"""The chat plane: Live Helper Chat, Rapls, and our code, as one deploy unit.

The chat surface is a second plane next to the WordPress file plane. It has
three layers with different sources of truth:

- the golden host tree: LHC 4.97v and Rapls are third-party and not in the
  repository; staging's `/chat/` tree is the golden copy, relayed to the
  target on the same physical host (staging and production share it);
- the repository: `lhc-extensions/mdvisitor`, `lhc-extensions/mdmobile`
  and the chat mu-plugins are ours and ship from the checkout;
- the config seed: the behaviour-defining database rows, exported from the
  golden host data-only (the target's own LHC installer creates the schema)
  with every secret stripped; secrets are regenerated on the target host at
  deploy time and never transit this Mac.

md-deploy chat-seed    export the sanitized config seed from golden staging
md-deploy chat-push    relay the tree, ship our code, apply seed + secrets
md-deploy chat-verify  read-only HTTP probes of the chat surface
"""

from __future__ import annotations

import base64
import json
import re
import shlex
import shutil
import tarfile
import tempfile
import time
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse
from uuid import uuid4

from md_deploy import backup as backup_module
from md_deploy import snapshot as snapshot_module
from md_deploy import steps
from md_deploy.target import (
    HOST,
    MU_PLUGINS,
    PLUGINS,
    DeployError,
    Target,
    named_target,
)

# The chat root and the plugin roots, relative to a target's WordPress root.
CHAT_ROOT = "chat"
LHC_EXTENSIONS = ("mdvisitor", "mdmobile")
CHAT_MU_PLUGINS = (
    "md-chat-launcher.php",
    "md-chat-delete.php",
    "md-chat-email.php",
    "md-kb-endpoint.php",
    "md-wecom-notify.php",
)
RAPLS_PLUGIN_DIR = "rapls-ai-chatbot"

# Paths inside the golden /chat/ tree that never relay: credentials, caches,
# per-host settings, runtime storage and the settings backups all belong to
# the target itself. The patterns are anchored to the transfer root (the
# leading slash) so a nested directory with the same name still relays.
CHAT_RELAY_EXCLUDES = (
    "/settings/",
    "/cache/",
    "/var/",
    "/sites/",
    "/storage/",
)

# LHC tables whose DATA defines the chat surface (2026-10-28 audit of all
# 228 tables in the golden database). The target's own LHC installer creates
# every schema and its default rows first; the seed then REPLACE-imports
# exactly these. Everything else is runtime, an empty catalog the installer
# already created, or a secret that is regenerated per target.
LHC_SEED_TABLES = (
    # department and the bot chain
    "lh_departament",
    "lh_generic_bot_bot",
    "lh_generic_bot_group",
    "lh_generic_bot_trigger",
    "lh_webhook",
    # operators, roles, canned replies
    "lh_users",  # exported with the password hash blanked
    "lh_groupuser",
    "lh_userdep",
    "lh_canned_msg",
    "lh_canned_msg_dep",
    "lh_abstract_email_template",
    # site configuration and installer catalogs
    "lh_chat_config",  # exported with secret fields blanked
    "lh_group",
    "lh_role",
    "lh_grouprole",
    "lh_rolefunction",
    "lh_users_setting",
    "lh_users_setting_option",
    "lh_speech_language",
    "lh_speech_language_dialect",
)

# Tables exported separately because their rows carry credentials or
# target-specific URLs and are rewritten host-side at export time:
#   lh_generic_bot_rest_api  auth_bearer per row + the site's base URL
#   lh_abstract_rest_api_key the REST key equals md_wecom_notify.lhc_api_key
LHC_SEED_SECRET_TABLES = (
    "lh_generic_bot_rest_api",
    "lh_abstract_rest_api_key",
)

# Fields inside lh_chat_config values that are per-install secrets; the
# exporter blanks them and the deploy regenerates them on the target.
CHAT_CONFIG_SECRET_FIELDS = (
    "export_hash",
    "autologin_data",  # .secret_hash inside
    "mobile_options",  # .fcm_key inside
)

# Non-secret lh_chat_config fields whose stock values must not reach the
# target; the deploy writes the target's own values.
CHAT_CONFIG_REWRITE_FIELDS = (
    "customer_site_url",
    "customer_company_name",
)

# WordPress options of the chat surface (2026-10-28 audit). The two option
# rows carry every API credential; they are exported sanitized and written
# on the target host at deploy time, never through the seed file.
CHAT_OPTION_SPECS = (
    # name, safe keys (empty tuple = every non-secret key), secret keys
    (
        "md_wecom_notify",
        (),
        (
            "bearer_token",
            "lhc_api_key",
            "wecom_callback_token",
            "wecom_callback_aes_key",
            "wecom_secret",
        ),
    ),
    ("md_kb_endpoint", (), ("bearer_token", "deepseek_api_key")),
)

# Option rows that are runtime state, never seed.
CHAT_OPTION_EXCLUDES = (
    "md_wecom_notify_chats",
    "md_wecom_notify_sends",
    "raplsaich_unanswered_log",
)

# raplsaich_settings does not ship at all: the audit found stock plugin
# defaults with empty provider keys, and shipping it would enable the stock
# Rapls badge instead of the LHC launcher. md_chat_launcher_hosts is not a
# seed row either: the deploy writes the target's own host list.

# Rapls knowledge is curated config and ships; its table carries the
# WordPress prefix, so the export rewrites golden prefix -> target prefix at
# import time. The 146-row index is derived and rebuilt per target.
RAPLS_SEED_TABLES = ("raplsaich_knowledge",)

GOLDEN_TARGET = "staging"

SEED_FILES = {
    "tables": "lhc-config-data.sql",
    # transformer output: identifiers that name credentials are blanked and
    # every hex run is struck, so desk preferences ship without secrets
    "users_setting": "lhc-users-setting-sanitized.sql",
    "users": "lhc-users-sanitized.sql",  # transformer output (password blanked)
    "chat_config": "lhc-chat-config-sanitized.sql",  # transformer output
    "secret_tables": "lhc-secret-tables.sql",
    "knowledge": "rapls-knowledge.sql",
    "schema": "lhc-schema-local-only.sql",  # local validation only
    "options": "chat-options.json",
}

# Tar members that must never unpack, even from an old tarball: the retired
# local-only hash restore carried live password hashes and no longer exists.
SEED_LOCAL_ONLY_NAMES = ("staging-passwords-local-only.sql",)

# Target-side marker that the credential bring-up seed (users, users_setting,
# secret_tables) has been imported once. Re-running chat-push after the
# runbook has set real secrets must not blank them again, so those files
# import only until this marker exists.
BRINGUP_OPTION = "md_chat_push_bringup"

# Seed files the golden host must never import: each carries sanitized
# credentials (blanked password, blanked chat-config secret fields, struck
# hex tokens, desk preferences with credential-looking identifiers
# blanked), so importing them on the golden host would blank the live
# secrets the staging bot/endpoint/connector chains depend on right now.
GOLDEN_SKIP_KEYS = ("users", "chat_config", "secret_tables", "users_setting")

# One-line reason per skipped file, shown in the push actions.
GOLDEN_SKIP_REASONS = {
    "users": "the operator row is already final on the golden host (the seed copy is blanked)",
    "chat_config": "the live secret fields are already final on the golden host (the seed copy has them blanked)",
    "secret_tables": "the live bearer and API-key rows are already final on the golden host (the seed copy has every credential struck)",
    "users_setting": "the operator desk preferences are already live on the golden host (the seed copy is credential-stripped)",
}


def seed_tables() -> list[str]:
    """Every table the seed export touches, data-only."""
    return [*LHC_SEED_TABLES, *LHC_SEED_SECRET_TABLES, *RAPLS_SEED_TABLES]


def rewrite_sql_for_target(
    sql: str,
    *,
    golden_prefix: str,
    target_prefix: str,
    golden_host: str,
    target_host: str,
) -> str:
    """Make golden-source SQL importable on the target.

    Two rewrites: the Rapls table prefix, and every mention of the golden
    hostname. The hostname rule is deliberately bare - `staging.metal-divider.com`
    -> `metal-divider.com` - because the seed stores it in several wrapped
    forms (plain URL, JSON-escaped `https:\\/\\/` inside SQL strings, and
    SMTP e-mail addresses), while the hostname bytes themselves are never
    escaped. Seed data contains no other legitimate use of the golden host
    (verified: knowledge rows reference no hosts), so the bare rule is
    provably complete.
    """
    if golden_prefix != target_prefix:
        sql = sql.replace(f"`{golden_prefix}raplsaich_", f"`{target_prefix}raplsaich_")
    if golden_host != target_host:
        sql = sql.replace(golden_host, target_host)
    return sql


@dataclass(frozen=True)
class SeedReport:
    out_dir: Path
    tables: tuple[str, ...]
    options: tuple[str, ...]
    rows: dict[str, int]


def chat_push_plan() -> list[str]:
    """The steps chat-push runs, in execution order, for the dry run.

    Every line names an operation the code executes — the sequence below is
    the sequence chat_push performs (snapshot and maintenance window first,
    mutation, the LHC cache-dir reset, and verification inside the window,
    then the page-cache purge after the window closes — the LiteSpeed purge notify 503s against a live
    maintenance page). The three things a deploy must never do unattended (fresh
    secrets, the launcher host gate, the operator password) are called out
    as manual runbook steps.
    """
    return [
        "snapshot the target database first (rollback point, kept on this Mac)",
        "open the maintenance window around the mutation",
        "relay the golden /chat/ tree (excludes: "
        + ", ".join(CHAT_RELAY_EXCLUDES)
        + ")",
        f"relay the {RAPLS_PLUGIN_DIR} plugin from the golden host",
        "ship lhc-extensions from the repository: " + ", ".join(LHC_EXTENSIONS),
        "ship chat mu-plugins from the repository: " + ", ".join(CHAT_MU_PLUGINS),
        "import the sanitized config seed (REPLACE INTO, data only; the"
        " golden host and completed bring-ups skip the sanitized credential"
        " files)",
        "rewrite seed URLs and the Rapls table prefix for the target",
        "merge the chat option rows on the target host (secrets never leave it)",
        "hash-verify the shipped lhc-extensions and mu-plugins on both sides",
        "reset the LHC compiled-template cache dirs inside the window",
        "close the maintenance window",
        "purge the WordPress page cache after the maintenance window closed",
        "defer to the runbook for the manual items: fresh secrets host-side,"
        " launcher host gate, operator password",
    ]


def chat_verify_probes(base_url: str) -> list[tuple[str, str, str]]:
    """The read-only HTTP probes: (name, url, expected marker in the body).

    Every probe names something the visitor's browser needs: the widget
    document, our extension assets, the REST surface and the page that
    carries the launcher.
    """
    base = base_url.rstrip("/")
    return [
        (
            "widget-document",
            f"{base}/chat/index.php/chat/chatwidget/(department)/1",
            "md-card",
        ),
        (
            "launcher-script",
            f"{base}/chat/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-launcher.js",
            "mdvl-launcher",
        ),
        (
            "widget-css",
            f"{base}/chat/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css",
            "mdv-closed-actions",
        ),
        # The REST namespace index is the one GET-able witness that the
        # POST-only chat routes are registered; PHP json_encode escapes
        # every slash, so the marker is the escaped route name.
        ("rest-route-exists", f"{base}/wp-json/md/v1", "kb\\/answer"),
        ("home-carries-launcher", f"{base}/", "mdvisitor-launcher.js"),
    ]


def seed_directory(home: Path) -> Path:
    """The sanitized seed's local directory, beside the backups and snapshots."""
    return home / "chat-seed"


def seed_import_files() -> tuple[str, ...]:
    """The seed files chat-push imports, in order (data only).

    `schema` is local-only and never reaches a target; `options` is JSON and
    goes through the host-side merge, not the SQL import.
    """
    return tuple(
        SEED_FILES[key]
        for key in (
            "tables",
            "users_setting",
            "users",
            "chat_config",
            "secret_tables",
            "knowledge",
        )
    )


def is_golden_target(target: Target, golden: Target) -> bool:
    """True when the push target is the golden host itself (staging).

    The staging-to-staging rehearsal must not import the sanitized seed
    files: their blanked credentials (operator password, chat-config
    secret fields, struck hex tokens) would replace the live rows and
    break the running bot/endpoint/connector chains. The golden host
    already owns those rows in final form, so every GOLDEN_SKIP_KEYS file
    is skipped there and only there.
    """
    return target.label == golden.label or target.base_url.rstrip(
        "/"
    ) == golden.base_url.rstrip("/")


def chat_seed_plan(home: Path) -> list[str]:
    """The steps chat-seed runs, for the dry run (the golden host is staging)."""
    return [
        "scp tools/deploy/seed/export-chat-seed.php to the golden host (/tmp)",
        "scp tools/deploy/seed/dump-chat-seed.sh to the golden host (/tmp)",
        "run export-chat-seed.php on the golden host",
        "run dump-chat-seed.sh on the golden host",
        f"pull /tmp/md-seed.tar.gz and unpack it into {seed_directory(home)}",
        "remove the /tmp seed artifacts from the golden host",
    ]


def chat_seed(*, repository: Path, home: Path) -> steps.Result:
    """Export the sanitized config seed from the golden host into the seed dir.

    The two seed scripts ship from the checkout, run on the golden host (they
    hardcode the staging root and write `/tmp/md-seed`), and the tarball is
    pulled back and unpacked here. The scripts print counts and names only —
    never a credential — and their output is recorded verbatim as actions.
    """
    golden = named_target(GOLDEN_TARGET)
    seed = repository / "tools" / "deploy" / "seed"
    php = seed / "export-chat-seed.php"
    dump = seed / "dump-chat-seed.sh"
    for script in (php, dump):
        if not script.is_file():
            raise DeployError(f"{script} is missing from the checkout")

    out_dir = seed_directory(home)
    out_dir.mkdir(parents=True, exist_ok=True)
    actions: list[str] = []
    notes: list[str] = []

    for script in (php, dump):
        golden.rsync(str(script), f"{HOST}:/tmp/{script.name}")
        actions.append(f"scp {script.name} to the golden host (/tmp)")

    # The same PHP resolution the dump script uses (command -v, then the
    # pinned /usr/local/bin/php the host's WP-CLI shares).
    php_bin = "$(command -v php || echo /usr/local/bin/php)"
    for line in golden.shell(f"{php_bin} /tmp/{php.name}").splitlines():
        if line.strip():
            actions.append(line.strip())
    for line in golden.shell(f"bash /tmp/{dump.name}").splitlines():
        if line.strip():
            actions.append(line.strip())

    tarball = out_dir / "md-seed.tar.gz"
    golden.rsync(f"{HOST}:/tmp/md-seed.tar.gz", str(tarball))
    actions.append(f"pull /tmp/md-seed.tar.gz to {tarball}")
    # Stale files from an earlier pull must never mask a failed
    # extraction: the tarball just pulled is this run's only source.
    stale = [
        name
        for name in (*SEED_FILES.values(), *SEED_LOCAL_ONLY_NAMES)
        if (out_dir / name).exists()
    ]
    for name in stale:
        (out_dir / name).unlink()
    if stale:
        actions.append(f"clear {len(stale)} stale seed file(s) from a previous pull")
    extracted = _extract_seed(tarball, out_dir)
    actions.append(f"unpack {extracted} seed files into {out_dir}")

    if not (out_dir / SEED_FILES["schema"]).is_file():
        notes.append(
            f"{SEED_FILES['schema']} is missing — the golden export did not complete"
        )

    # The seed tarball, the unpacked /tmp/md-seed and the two scripts are
    # transient host artifacts; the pull has verified the tarball, so they
    # are removed from the shared host's /tmp (best effort — a failure is a
    # note, not a failed export).
    try:
        golden.shell(
            "rm -rf /tmp/md-seed /tmp/md-seed.tar.gz"
            " /tmp/export-chat-seed.php /tmp/dump-chat-seed.sh"
        )
        actions.append("remove the /tmp seed artifacts from the golden host")
    except DeployError as error:
        notes.append(f"the golden host's /tmp seed artifacts stayed: {error}")
    return steps.Result("chat-seed", actions, notes)


def chat_push(
    target: Target,
    *,
    repository: Path,
    home: Path,
) -> steps.Result:
    """Deploy the chat plane: relay the golden trees, ship our code, apply the seed.

    The mutation runs inside a safety envelope: a database snapshot is taken
    to this Mac first (a failure aborts before anything changes) and the
    maintenance window opens around every mutation, closing in a finally
    even when a step raises. Each operation is then read back: relay the
    golden /chat/ tree (per-host settings and caches excluded; a relay whose
    source and destination resolve to the same path is skipped, never run —
    rsync --delete against itself is a wipe pattern); relay the golden Rapls
    plugin; ship the two LHC extensions and the five chat mu-plugins from
    the repository (their source of truth); import the sanitized seed SQL,
    rewritten for the target's table prefix and host. The golden host skips
    the sanitized credential files (users, users_setting, chat_config, secret_tables);
    every other target imports them exactly once — the bring-up marker
    (md_chat_push_bringup) makes a re-push after the runbook set real
    secrets a no-op for those files. The chat option rows merge on the
    target host, so the live secret values never leave it. The shipped files
    hash-verify, the LHC cache dirs reset inside the window (on the failure
    path too), and the page cache purges after the window closes.
    """
    golden = named_target(GOLDEN_TARGET)
    out_dir = seed_directory(home)
    actions: list[str] = []
    notes: list[str] = []

    snapshot = snapshot_module.take(target, home)
    actions.append(f"snapshot the database first ({snapshot.name})")

    golden_self = is_golden_target(target, golden)
    failure: DeployError | None = None
    try:
        steps.maintenance(target, on=True)
        actions.append("open the maintenance window")

        if _relay(golden, target, CHAT_ROOT, excludes=CHAT_RELAY_EXCLUDES):
            actions.append(
                "relay the golden /chat/ tree (excludes: "
                + ", ".join(CHAT_RELAY_EXCLUDES)
                + ")"
            )
        else:
            actions.append(
                "skip the /chat/ relay: source and destination are the same"
                " path (the golden host itself)"
            )
        if _relay(golden, target, f"{PLUGINS}/{RAPLS_PLUGIN_DIR}", excludes=()):
            actions.append(f"relay the {RAPLS_PLUGIN_DIR} plugin from the golden host")
        else:
            actions.append(
                f"skip the {RAPLS_PLUGIN_DIR} relay: source and destination"
                " are the same path (the golden host itself)"
            )

        for name in LHC_EXTENSIONS:
            source = repository / "lhc-extensions" / name
            if not source.is_dir():
                raise DeployError(f"{source} is missing from the checkout")
            target.copy_in(source, f"{CHAT_ROOT}/extension/{name}")
            actions.append(f"ship lhc-extension {name} from the repository")
        for filename in CHAT_MU_PLUGINS:
            source = repository / "mu-plugins" / filename
            if not source.is_file():
                raise DeployError(f"{source} is missing from the checkout")
            target.copy_file_in(source, f"{MU_PLUGINS}/{filename}")
            actions.append(f"ship mu-plugin {filename} from the repository")

        golden_prefix = golden.wp("config", "get", "table_prefix")
        target_prefix = target.wp("config", "get", "table_prefix")
        golden_host = urlparse(golden.base_url).hostname or ""
        target_host = urlparse(target.base_url).hostname or ""
        credential_done = golden_self or _bringup_complete(target)
        golden_skip = {SEED_FILES[key]: key for key in GOLDEN_SKIP_KEYS}
        for filename in seed_import_files():
            if filename in golden_skip and credential_done:
                reason = GOLDEN_SKIP_REASONS[golden_skip[filename]]
                if golden_self:
                    actions.append(f"skip {filename}: {reason}")
                else:
                    actions.append(
                        f"skip {filename}: the bring-up marker"
                        f" ({BRINGUP_OPTION}) is set — the runbook's live"
                        " secrets stay untouched"
                    )
                continue
            path = out_dir / filename
            if not path.is_file():
                raise DeployError(f"{path} is missing — run chat-seed first")
            sql = rewrite_sql_for_target(
                path.read_text(encoding="utf-8"),
                golden_prefix=golden_prefix,
                target_prefix=target_prefix,
                golden_host=golden_host,
                target_host=target_host,
            )
            _import_sql(target, sql)
            actions.append(f"import {filename} (rewritten for {target.label})")
        for name in _write_options(
            target,
            out_dir,
            golden_host=golden_host,
            target_host=target_host,
            notes=notes,
        ):
            actions.append(f"merge option {name} on the target host")
        if not golden_self and not credential_done:
            target.wp("option", "update", BRINGUP_OPTION, "1")
            if not _bringup_complete(target):
                raise DeployError(
                    f"the bring-up marker ({BRINGUP_OPTION}) did not read"
                    " back as set after the option merge"
                )
            actions.append(
                f"set the bring-up marker ({BRINGUP_OPTION}) after the"
                " verified option merge: the sanitized credential seed"
                " will not import again on this target"
            )

        for name in LHC_EXTENSIONS:
            _verify_tree(
                target,
                repository / "lhc-extensions" / name,
                f"{CHAT_ROOT}/extension/{name}",
                notes,
            )
        for filename in CHAT_MU_PLUGINS:
            _verify_file(
                target,
                repository / "mu-plugins" / filename,
                f"{MU_PLUGINS}/{filename}",
                notes,
            )
    except DeployError as exc:
        failure = exc
    finally:
        # Reset the LHC cache dirs while the window still shields the site:
        # the reset is a shell command (safe inside the window), and a live
        # request in a rm-before-mkdir gap fatal-errors through LHC's
        # "Site Maintenance" template. Runs on the failure path too, so a
        # half-applied tree never reopens with stale compiled templates.
        try:
            _reset_lhc_caches(target, notes)
            actions.append(
                "reset the LHC compiled-template cache dirs inside the window"
            )
        except DeployError as cache_error:
            notes.append(
                f"lhc cache reset failed before the window closed: {cache_error}"
            )
        steps.maintenance(target, on=False)
        actions.append("close the maintenance window")

    # The page-cache purge runs only after the window closes: the LiteSpeed
    # purge notify is an HTTP self-request, and a live maintenance window
    # answers it with a 503, which the plugin reports as "Could not notify
    # the web server to purge" (reproduced deterministically on staging 5/5
    # pushes; every isolated attempt passed because the probe had written a
    # plain-text .maintenance whose $upgrading stays unset, so core skips it).
    if _purge_page_cache(target, notes):
        actions.append("purge the WordPress page cache after the window closed")
    if failure is not None:
        raise failure
    return steps.Result("chat-push", actions, notes)


def chat_verify(target: Target, *, cache_buster: str | None = None) -> steps.Result:
    """The read-only HTTP probes, each one PASS/FAIL with its URL.

    A note per failed probe; the CLI returns non-zero when any note is set.
    """
    base = target.base_url.rstrip("/")
    actions: list[str] = []
    notes: list[str] = []
    for name, url, marker in chat_verify_probes(base):
        path = url[len(base) :] or "/"
        status, body = target.http(path, cache_buster=cache_buster)
        passed = status == 200 and marker in body
        actions.append(f"{name} {url} -> {status} {'PASS' if passed else 'FAIL'}")
        if not passed:
            notes.append(f"{name} failed: {url} answered {status} without {marker!r}")
    return steps.Result("chat-verify", actions, notes)


def _relay(
    golden: Target, target: Target, relative: str, *, excludes: tuple[str, ...]
) -> bool:
    """Mirror a golden-host directory onto the target, skipping the excludes.

    Returns False — without touching anything — when the golden source and
    the target destination resolve to the same path: rsync --delete against
    its own source is a wipe pattern, and the golden host mirroring itself
    has nothing to gain. The golden host is staging (remote by
    construction). When the target is on the same physical host, one
    on-host rsync between the two roots keeps the tree from streaming
    through this Mac twice; a local target pulls it down.
    """
    if golden.rsync_remote is None:
        raise DeployError("the golden host must be remote (staging)")
    if golden.path_of(relative) == target.path_of(relative):
        return False
    exclude_flags = " ".join(shlex.quote(f"--exclude={name}") for name in excludes)
    if target.rsync_remote is not None:
        golden.shell(
            f"rsync -az --delete {exclude_flags} "
            f"{shlex.quote(golden.path_of(relative) + '/')} "
            f"{shlex.quote(target.path_of(relative) + '/')}"
        )
    else:
        target.rsync(
            f"{golden.rsync_remote}/{relative}/",
            f"{target.path_of(relative)}/",
            extra=[arg for name in excludes for arg in ("--exclude", name)],
        )
    return True


def _import_sql(target: Target, sql: str) -> None:
    """Pipe one SQL payload into the target's WordPress database.

    The seed tables live in the site's own database — the golden export reads
    `lh_users` and the WordPress options through one connection — so `wp db
    import -` reaches them. Unlike `wp db export`, the import path runs
    `mysql` through proc_open, not `exec()`, so it is safe on the host.
    """
    wp = " ".join(shlex.quote(part) for part in target.wp_on_target())
    script = f"""set -euo pipefail
cd {shlex.quote(target.root)}
{wp} db import -"""
    # subprocess needs a real file descriptor; BytesIO has none, so the
    # SQL payload rides a TemporaryFile that unlinks itself on close.
    with tempfile.TemporaryFile() as payload:
        payload.write(sql.encode("utf-8"))
        payload.seek(0)
        backup_module.stream(target, script, stdin=payload)


def _write_options(
    target: Target,
    out_dir: Path,
    *,
    golden_host: str,
    target_host: str,
    notes: list[str],
) -> list[str]:
    """Merge each sanitized option row into the live value on the target host.

    The live row never leaves the host: the merge runs there through
    seed/merge-chat-option.php under wp eval-file, and this side ships only
    the sanitized seed payload plus the script itself. A remote target
    receives the script through an SSH pipe into a unique /tmp path (mode
    600) removed afterwards; Target.path_of rejects absolute paths, so
    copy_file_in can never place it there. The eval-file acknowledgement
    must match the exact OK line, and the PHP script proves the write by
    reading the option back.
    """
    path = out_dir / SEED_FILES["options"]
    if not path.is_file():
        raise DeployError(f"{path} is missing — run chat-seed first")
    if not golden_host or not target_host:
        raise DeployError(
            "the golden and target base URLs must both carry a hostname"
            " before the option values can be rewritten"
        )
    payload = json.loads(path.read_text(encoding="utf-8"))
    script = Path(__file__).resolve().parents[2] / "seed" / "merge-chat-option.php"
    if not script.is_file():
        raise DeployError(f"{script} is missing from the checkout")
    remote_script: str | None = None
    if target.rsync_remote is not None:
        remote_script = f"/tmp/md-merge-chat-option-{uuid4().hex[:8]}.php"
        encoded = base64.b64encode(script.read_bytes()).decode("ascii")
        target.shell(
            f"printf '%s' {encoded} | base64 -d > {remote_script}"
            f" && chmod 600 {remote_script}"
        )
    written: list[str] = []
    try:
        for name, _safe, secret_keys in CHAT_OPTION_SPECS:
            value = payload.get(name)
            if value is None:
                raise DeployError(f"{name} is missing from {SEED_FILES['options']}")
            leak = _find_secret_keys(value, secret_keys)
            if leak:
                raise DeployError(
                    f"{name} still carries secret keys ({', '.join(leak)});"
                    " re-export the seed with a sanitizer that strips them"
                )
            value = _rewrite_option_values(value, golden_host, target_host)
            ack = target.wp(
                "eval-file",
                remote_script if remote_script is not None else str(script),
                name,
                json.dumps(value),
            )
            if not re.fullmatch(
                rf"OK {re.escape(name)} merged=\d+ total=\d+", ack.strip()
            ):
                raise DeployError(
                    f"the option merge for {name} did not acknowledge with"
                    f" its OK line (got: {ack.strip()!r})"
                )
            written.append(name)
    finally:
        if remote_script is not None:
            try:
                target.shell(f"rm -f {remote_script}")
            except DeployError as error:
                notes.append(
                    f"the merge script stayed behind at {remote_script}: {error}"
                )
    return written


def _find_secret_keys(value: object, secret_keys: tuple[str, ...]) -> list[str]:
    """Dotted paths of every secret key in the payload, at any depth."""
    found: list[str] = []

    def walk(node: object, prefix: str) -> None:
        if isinstance(node, dict):
            for key, item in node.items():
                here = f"{prefix}.{key}" if prefix else str(key)
                if key in secret_keys:
                    found.append(here)
                walk(item, here)
        elif isinstance(node, list):
            for index, item in enumerate(node):
                walk(item, f"{prefix}[{index}]")

    walk(value, "")
    return found


def _rewrite_option_values(value: object, golden_host: str, target_host: str) -> object:
    """Replace the golden host's bare hostname inside every string value.

    Same rule as rewrite_sql_for_target: the hostname bytes never appear
    escaped inside a JSON string, so a plain replace is provably complete —
    and it must run before the payload reaches the target, because the
    option rows carry the base URLs the mu-plugins dial (a prod target
    pointing lhc_base_url back at staging would send its WeCom cards to the
    rehearsal desk).
    """
    if isinstance(value, str):
        return value.replace(golden_host, target_host)
    if isinstance(value, list):
        return [
            _rewrite_option_values(item, golden_host, target_host) for item in value
        ]
    if isinstance(value, dict):
        return {
            key: _rewrite_option_values(item, golden_host, target_host)
            for key, item in value.items()
        }
    return value


def _bringup_complete(target: Target) -> bool:
    """Has this target finished the one-time credential bring-up?

    Fail-closed: only a literal "1" read back through wp eval counts. Any
    error (SSH, WP-CLI, missing option) reports False, which makes the next
    push import the credential files again rather than silently skipping
    them.
    """
    raw = target.wp(
        "eval",
        f"var_export(get_option({BRINGUP_OPTION!r}) === '1');",
        check=False,
    ).strip()
    return raw == "true"


def _verify_tree(target: Target, source: Path, relative: str, notes: list[str]) -> None:
    """md5-readback: the repo's tree and the target's copy hold the same bytes."""
    expected = {
        str(path.relative_to(source)): backup_module.sha256(path)
        for path in sorted(source.rglob("*"))
        if path.is_file() and not path.is_symlink()
    }
    remote: dict[str, str] = {}
    for line in target.tree(relative):
        # shasum/sha256sum emit "<64 hex><whitespace>./<path>"; parse on the
        # hash body so a spacing change in either tool cannot corrupt the
        # comparison (an unparseable line simply never lands in `remote`).
        matched = re.match(r"^([0-9a-f]{64})\s+\./(.+)$", line)
        if matched:
            remote[matched.group(2)] = matched.group(1)
    if set(expected) != set(remote):
        missing = ", ".join(sorted(set(expected) - set(remote))[:3])
        extra = ", ".join(sorted(set(remote) - set(expected))[:3])
        notes.append(
            f"{relative}: the file set does not match "
            f"(missing [{missing}], extra [{extra}])"
        )
        return
    for path in sorted(expected):
        if remote[path] != expected[path]:
            notes.append(f"{relative}/{path}: the bytes do not match on the target")


def _reset_lhc_caches(target: Target, notes: list[str]) -> None:
    """Delete and immediately recreate the LHC compiled-template cache dirs.

    LHC compiles its templates and module PHP into `cache/compiledtemplates`
    and maps them in `cache/cacheconfig`; a push that changed either source
    would be silently ignored until both are dropped (blueprint-measured).
    Both directories must exist again before the first request: LHC does
    not recreate a missing cache directory, it fatal-errors through a
    template titled "Site Maintenance" (staging-measured). rm alone also
    leaves that gap live, so the recreate is part of the same shell command
    and the whole reset runs inside the maintenance window.
    """
    for name in ("compiledtemplates", "cacheconfig"):
        cache_dir = shlex.quote(target.path_of(f"{CHAT_ROOT}/cache/{name}"))
        target.shell(f"rm -rf {cache_dir} && mkdir -p {cache_dir}")


def _purge_page_cache(target: Target, notes: list[str]) -> bool:
    """Purge the WordPress page cache after the maintenance window closes.

    The launcher tag and the pushed assets ship inside full-page HTML, so
    the page cache must drop too. The purge notify is an HTTP self-request
    that 503s against a live maintenance window, which is why this runs
    only after the window closes. One retry (the shared-host notify can
    flake right after a push burst, measured both ways on staging), then a
    loud note that fails the CLI run. Returns True when the purge landed.
    """
    try:
        target.wp("litespeed-purge", "all")
    except DeployError:
        notes.append("litespeed-purge failed once; retrying after a 5s pause")
        time.sleep(5)
        try:
            target.wp("litespeed-purge", "all")
        except DeployError:
            notes.append(
                "litespeed-purge failed twice; the page cache may serve"
                " stale HTML - run wp litespeed-purge all on the target"
                " before accepting traffic"
            )
            return False
    return True


def _hash_file(target: Target, relative: str) -> str:
    """SHA-256 one target file.

    The deploy host (Linux) has sha256sum but not shasum; local dev
    targets (macOS) the reverse — so each form falls back to the other.
    """
    path = shlex.quote(target.path_of(relative))
    listing = target.shell(f"(shasum -a 256 {path} || sha256sum {path}) 2>/dev/null")
    return listing.split()[0] if listing.split() else ""


def _verify_file(target: Target, source: Path, relative: str, notes: list[str]) -> None:
    """Hash-readback for one repo-shipped file."""
    expected = backup_module.sha256(source)
    remote = _hash_file(target, relative)
    if remote != expected:
        notes.append(f"{relative}: the bytes do not match on the target")


def _extract_seed(tarball: Path, destination: Path) -> int:
    """Unpack the seed tarball (flat member names) into `destination`.

    dump-chat-seed.sh tars an explicit flat member list (`-C /tmp/md-seed
    <members>`), so every member is a bare file name. Directory members,
    nested paths and anything carrying '..' never unpack; members named in
    SEED_LOCAL_ONLY_NAMES never unpack even from a tarball an older export
    produced (the retired hash-restore file carried live password hashes).

    Returns the number of files written. A tarball that unpacks to nothing
    is an error, not a silent no-op: stale files from an earlier pull must
    never mask a failed export (chat_seed clears them first for the same
    reason).
    """
    written = 0
    with tarfile.open(tarball, "r:gz") as archive:
        for member in archive.getmembers():
            name = member.name.removeprefix("./")
            parts = Path(name).parts
            if not parts or ".." in parts or len(parts) != 1:
                continue
            if name in SEED_LOCAL_ONLY_NAMES:
                continue
            if not member.isfile():
                continue
            source = archive.extractfile(member)
            if source is None:
                continue
            target_path = destination / name
            with source, target_path.open("wb") as handle:
                shutil.copyfileobj(source, handle)
            written += 1
    if written == 0:
        raise DeployError(
            f"{tarball} unpacked to nothing - the golden export did not"
            " complete or this is not the seed tarball"
        )
    return written
