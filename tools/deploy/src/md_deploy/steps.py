"""The deploy steps of docs/specs/20-staging-deploy.md §3.3, one function each.

Every step is separately re-runnable: `deploy`, `verify`, `maintenance`,
`rollback-deploy`. None reads another's state; the only thing they share is
the `Target`, so an operator can interrupt a window, look at the site, and
run the failed step again.

The deploy order is spec 20 §3.3: snapshot the database, build and upload
every artifact, rename the whole batch in one shell, reset the opcode cache,
activate, present, then verify — and only a passing verify archives the
backups out of the web root.
"""

from __future__ import annotations

import hashlib
import shlex
import shutil
import subprocess
import time
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path

from md_local import versions as local_versions

from md_deploy import atomic, plan
from md_deploy import backup as backup_module
from md_deploy import snapshot as snapshot_module
from md_deploy.target import DeployError, Target


@dataclass(frozen=True)
class Result:
    """What a step did, in the order it did it — the evidence a run leaves."""

    step: str
    actions: list[str]
    notes: list[str]
    publication: atomic.Receipt | None = None

    def describe(self) -> str:
        lines = [f"{self.step}: {action}" for action in self.actions]
        lines += [f"{self.step}: note: {note}" for note in self.notes]
        return "\n".join(lines)


def maintenance(target: Target, *, on: bool) -> Result:
    """The maintenance window's core: the `.maintenance` file itself.

    `wp maintenance-mode activate` writes a literal timestamp, and core's
    `wp_maintenance()` stops honouring a file whose timestamp is over ten
    minutes old — shorter than a deploy window, and it cannot be re-armed
    mid-run (blueprint-measured on the same platform). The `time()` form
    re-evaluates on every request, so the window lasts exactly as long as the
    file exists, and the readback below is the proof, not the command's exit
    status.

    Turning it on when it is already on (or off when already off) is not a
    failure: the window is re-runnable step by step.
    """
    path = shlex.quote(target.path_of(".maintenance"))
    if on:
        target.shell(f"printf '%s\\n' '<?php $upgrading = time();' > {path}")
    else:
        target.shell(f"rm -f {path}")
    active = target.exists(".maintenance")
    if active != on:
        state = "still present" if active else "still absent"
        raise DeployError(
            f"the maintenance file is {state} after {'writing' if on else 'removing'} it"
        )
    action = "wrote .maintenance" if on else "removed .maintenance"
    note = "maintenance page: active" if on else "maintenance page: off"
    return Result("maintenance", [action], [note])


def stage_plugin(repository: Path, slug: str, staging: Path) -> Path:
    """Build the tree that ships, for one plugin.

    The checkout's `vendor/` carries the development dependencies; production
    carries none. So the deploy does not rsync the checkout: it rsyncs into a
    staging tree with the plugin's own ignores and installs the runtime
    dependencies there. `vendor/` and `build/` ship; `node_modules/`, `tests/`
    and the tool configurations do not.
    """
    source = Path(repository) / plan.REPO / slug
    if not (source / f"{slug}.php").is_file():
        raise DeployError(f"{source} does not look like the {slug} plugin")
    return stage_tree(source, staging, composer=True)


def stage_tree(source: Path, staging: Path, *, composer: bool) -> Path:
    """Stage one shippable tree: the source with the deploy excludes applied.

    `staging` is the workspace; the staged tree lands under it named after
    the source. Nothing is staged from the source directly: a file the
    excludes should keep out would then ship, and a plugin's vendor tree is
    rebuilt here rather than copied. The fingerprint cache keeps an
    unchanged tree from being rebuilt every deploy.
    """
    destination = Path(staging) / source.name
    stamp = Path(staging) / ".stage" / f"{source.name}.sha256"
    fingerprint = _stage_fingerprint(source)
    if (
        stamp.is_file()
        and stamp.read_text(encoding="utf-8").strip() == fingerprint
        and (
            not composer
            or not (destination / "composer.json").is_file()
            or (destination / "vendor").is_dir()
        )
    ):
        # Nothing changed since the last build: the staged tree already is the
        # source with its runtime vendor (a Composer build is minutes of every
        # deploy). The fingerprint covers every source file, dev files
        # included: a test edit rebuilds, which is merely slower.
        return destination
    destination.mkdir(parents=True, exist_ok=True)
    excludes: list[str] = []
    for pattern in plan.DEPLOY_EXCLUDES:
        excludes += ["--exclude", pattern]
    completed = subprocess.run(
        ["rsync", "-a", "--delete", *excludes, f"{source}/", f"{destination}/"],
        check=False,
        capture_output=True,
        text=True,
    )
    if completed.returncode != 0:
        raise DeployError(f"staging {source.name}: {completed.stderr.strip()}")
    if composer and (destination / "composer.json").is_file():
        _composer_install(destination)
    stamp.parent.mkdir(parents=True, exist_ok=True)
    stamp.write_text(fingerprint + "\n", encoding="utf-8")
    return destination


def _composer_install(destination: Path) -> None:
    """Install the runtime dependencies of the staged tree."""
    composer = shutil.which("composer")
    if composer is None:
        raise DeployError("composer is not installed (README.md)")
    installed = subprocess.run(
        [
            composer,
            "install",
            "--no-dev",
            "--no-interaction",
            "--no-progress",
            "--optimize-autoloader",
            "--quiet",
        ],
        cwd=destination,
        check=False,
        capture_output=True,
        text=True,
    )
    if installed.returncode != 0:
        raise DeployError(
            f"composer install --no-dev in {destination}: "
            + (installed.stderr or installed.stdout).strip()
        )


def _stage_fingerprint(source: Path) -> str:
    """sha256 over every source file's path and content: the staging cache key."""
    digest = hashlib.sha256()
    for path in sorted(item for item in source.rglob("*") if item.is_file()):
        digest.update(path.relative_to(source).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def landed_plugins(repository: Path) -> list[str]:
    """The planned plugins whose source trees are in this checkout."""
    return [
        slug
        for slug in plan.PLANNED_PLUGINS
        if (Path(repository) / plan.REPO / slug / f"{slug}.php").is_file()
    ]


def absent_artifacts(repository: Path) -> list[str]:
    """The deployment inventory's members that have not landed yet, by name.

    The plugin and theme tickets (#27, #32, #33) land after this tool; the
    deploy says which parts of the inventory it is not shipping instead of
    failing on a tree that is still growing.
    """
    absent = [
        f"{plan.REPO}/{slug}"
        for slug in plan.PLANNED_PLUGINS
        if slug not in landed_plugins(repository)
    ]
    if not (Path(repository) / plan.THEMES_REPO / plan.CHILD_THEME).is_dir():
        absent.append(f"{plan.THEMES_REPO}/{plan.CHILD_THEME}")
    return absent


def deployment_artifacts(repository: Path, target: Target) -> list[atomic.Artifact]:
    """The single owned-path inventory used by publication and rollback.

    Target-aware in exactly one way (spec 20 §3.2): the review site's own
    mu-plugin ships to the staging target alone; production and the local dev
    instance filter it out (the dev instance's isolation mu-plugin already
    does everything it does).

    The child theme ships as one tree, not file by file: on a dev instance
    the theme is a symlink into this checkout, and a file-by-file switch
    would write its backup siblings THROUGH the link, into the repository.
    A whole tree renames past the link (the link itself is moved aside),
    which is also exactly what production's real directory needs.
    """
    repository = Path(repository)
    mu = repository / plan.MU_PLUGINS_REPO
    plugins = sorted(mu.glob("*.php"))
    if not plugins:
        raise DeployError(f"{mu} carries no must-use plugin")
    if not target.staging:
        plugins = [path for path in plugins if path.name != plan.STAGING_MU]
    artifacts = [
        atomic.Artifact(path, f"{plan.MU_PLUGINS_DIR}/{path.name}") for path in plugins
    ]
    theme = repository / plan.THEMES_REPO / plan.CHILD_THEME
    if theme.is_dir():
        strays = [item.name for item in theme.iterdir() if item.name.startswith(".")]
        if strays:
            raise DeployError(
                f"{theme} carries dotfiles ({', '.join(strays)}): the theme ships "
                "as one tree and a stray sibling would ship with it"
            )
        artifacts.append(atomic.Artifact(theme, plan.CHILD_THEME_TARGET))
    for slug in landed_plugins(repository):
        source = repository / plan.REPO / slug
        artifacts.append(atomic.Artifact(source, f"{plan.PLUGINS_DIR}/{slug}"))
    return artifacts


def _prepared(
    artifact: atomic.Artifact, *, repository: Path, staging: Path
) -> atomic.Artifact:
    """The shippable form of one artifact: staged trees for the directories.

    The plugins stage with their Composer build; the child theme stages as
    the plain tree it is. A single file ships as itself.
    """
    if artifact.relative.startswith(plan.PLUGINS_DIR + "/"):
        return atomic.Artifact(
            stage_plugin(repository, artifact.source.name, staging), artifact.relative
        )
    if artifact.source.is_dir():
        return atomic.Artifact(
            stage_tree(artifact.source, staging, composer=False), artifact.relative
        )
    return artifact


def deployment_id(repository: Path) -> str:
    """UTC time plus the source commit: one id for one batch."""
    git = subprocess.run(
        ["git", "rev-parse", "--short", "HEAD"],
        cwd=repository,
        capture_output=True,
        text=True,
        check=False,
    )
    if git.returncode:
        raise DeployError("the deployment source must be a git checkout")
    return datetime.now(UTC).strftime("%Y%m%dT%H%M%S%fZ") + "-" + git.stdout.strip()


def full_backup_reminder(home: Path, *, now: datetime | None = None) -> str | None:
    """A line for the deploy log when the weekly full backup is due, else None."""
    latest = backup_module.latest_complete(home)
    if latest is None:
        return "reminder: there is no complete full backup; run `just deploy backup`"
    taken = datetime.strptime(latest.name, "backup-%Y%m%d-%H%M").replace(tzinfo=UTC)
    age = (now or datetime.now(UTC)) - taken
    if age.days < plan.FULL_BACKUP_DAYS:
        return None
    return (
        f"reminder: the newest full backup ({latest.name}) is {age.days} days old; "
        "run `just deploy backup` (weekly, and before every data write)"
    )


def opcache_reset_script(target: Target) -> str:
    """The after-switch step: a one-time token on the host, posted to the site.

    The token is made, written and posted on the machine the site runs on; it
    is read by `mu-plugins/md-deploy-reset.php` from the same private
    directory and deleted there. It travels in a header file, never on a
    command line other accounts on the shared host could list. The review
    site is password-protected (spec 20 §1.3), so its reset request carries
    the basic-auth pair — in the script's curl line, never in a file; an
    empty `-u` would break curl on every other target, so the flag exists
    only when the pair does.
    """
    url = shlex.quote(target.base_url.rstrip("/") + "/")
    marker = atomic.AFTER_SWITCH_MARKER
    auth = f"-u {shlex.quote(target.http_auth)} " if target.http_auth else ""
    return f"""(
umask 077
dir="$HOME/.config/metal-divider"
mkdir -p "$dir"
token="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \\n')"
printf '%s\\n' "$token" > "$dir/opcache-reset.token"
printf 'X-MD-Opcache-Reset: %s\\n' "$token" > "$dir/opcache-reset.header"
outcome=none
attempt=0
while [ "$attempt" -lt {plan.OPCACHE_RESET_ATTEMPTS} ]; do
  attempt=$((attempt + 1))
  answer="$(curl -sS -o /dev/null -D - --max-time 5 -X POST {auth}-H @"$dir/opcache-reset.header" {url} 2>/dev/null | tr -d '\\r' | sed -n 's/^[Xx]-[Mm][Dd]-[Oo]pcache-[Rr]eset: *//p')"
  if [ -n "$answer" ]; then outcome="$answer"; break; fi
  sleep {plan.OPCACHE_RESET_PAUSE}
done
rm -f "$dir/opcache-reset.token" "$dir/opcache-reset.header"
printf '{marker}opcache-reset=%s attempts=%s\\n' "$outcome" "$attempt"
)"""


# The version contract (spec 20 §2.2): the local pins are the host's contract
# (md_local.versions is the single source). A deploy onto a wrong plugin face
# is a deploy onto an unproven site, so the remote deploy checks this first.
CORE = "WordPress"
CONTRACT_PLUGINS: tuple[tuple[str, str], ...] = (
    ("woocommerce", local_versions.WOOCOMMERCE_VERSION),
    ("advanced-custom-fields", local_versions.ACF_VERSION),
    ("windpress", local_versions.WINDPRESS_VERSION),
    ("wp-seopress", local_versions.SEOPRESS_VERSION),
    ("wp-seopress-pro", local_versions.SEOPRESS_PRO_VERSION),
    ("livecanvas", local_versions.LIVECANVAS_VERSION),
    ("28-wp-markdown-editor", local_versions.YANSIR_MD_VERSION),
    ("litespeed-cache", local_versions.LITESPEED_CACHE_VERSION),
)


def contract(target: Target) -> Result:
    """Read the target's versions back and compare them with the local pins.

    Read-only. `wp core version` covers core; `wp plugin list` covers the
    plugins. A missing plugin is a mismatch of its own — the host ticket's
    install step (spec 20 §2.1 step 9) is what puts each of these there.
    """
    actions: list[str] = []
    notes: list[str] = []
    core = target.wp("core", "version").strip()
    actions.append(f"{CORE} {core}")
    if core != local_versions.WORDPRESS_VERSION:
        notes.append(f"{CORE} is {core}, the pin is {local_versions.WORDPRESS_VERSION}")
    listed = {
        str(entry["name"]): str(entry.get("version", ""))
        for entry in target.wp_json("plugin", "list", "--fields=name,version")
        if isinstance(entry, dict) and "name" in entry
    }
    for slug, pinned in CONTRACT_PLUGINS:
        found = listed.get(slug, "")
        actions.append(f"{slug} {found or 'missing'}")
        if found != pinned:
            notes.append(f"{slug} is {found or 'missing'}, the pin is {pinned}")
    return Result("contract", actions, notes)


def deploy(
    target: Target,
    *,
    repository: Path,
    staging: Path,
    deploy_id: str | None = None,
    on_switch: Callable[[], None] | None = None,
    snapshot_home: Path | None = None,
    now: datetime | None = None,
) -> Result:
    """Snapshot the database, build all trees, upload all artifacts, switch the batch.

    The snapshot comes first and a failed one stops the deploy before anything
    is uploaded: the database is the part of the site a code deploy can change
    that the atomic switch does not keep. Verification and private archiving
    follow in `finish_deploy`; keeping that phase separate lets the drill run
    the same code and leave the site out of maintenance before verify.
    """
    home = snapshot_home or backup_module.DATA_HOME
    remote = target.rsync_remote is not None
    if remote:
        # The version contract is part of the remote deploy (spec 20 §2.2:
        # 装完读回比对,不一致即失败); locally the stack builds from the same
        # pins, so the check would only repeat what md-local already proved.
        checked = contract(target)
        if checked.notes:
            raise DeployError(
                "the version contract does not hold on "
                + target.label
                + ": "
                + "; ".join(checked.notes)
            )
    # Each phase says what it is doing and how long it took: a slow phase
    # must not look like a hang.
    print("deploy: database snapshot", flush=True)
    started = time.monotonic()
    taken = snapshot_module.take(target, home)
    print(f"deploy: snapshot done in {time.monotonic() - started:.1f}s", flush=True)
    reminder = full_backup_reminder(home, now=now)
    artifacts = deployment_artifacts(repository, target)
    absent = absent_artifacts(repository)
    # Finish every local build before starting any remote upload.
    print("deploy: staging and Composer builds", flush=True)
    started = time.monotonic()
    prepared = [
        _prepared(item, repository=repository, staging=staging) for item in artifacts
    ]
    print(f"deploy: staging done in {time.monotonic() - started:.1f}s", flush=True)
    receipt = atomic.publish(
        target,
        prepared,
        deploy_id=deploy_id or deployment_id(repository),
        on_switch=on_switch,
        after_switch=opcache_reset_script(target),
    )
    assert receipt.switch_seconds is not None
    actions = [
        f"database snapshot {taken.path} ({taken.size} bytes, sha256 {taken.sha256[:16]}…); "
        f"restore with `just deploy restore-snapshot {taken.name}`"
    ]
    actions += [f"deployed {item.relative} by rename" for item in artifacts]
    if absent:
        actions.append(
            "not yet in the tree (skipped, their tickets land later): "
            + ", ".join(absent)
        )
    actions += [
        f"deploy id: {receipt.deploy_id}",
        f"total switch duration: {receipt.switch_seconds:.3f} s",
    ]
    actions += [f"after switch: {line}" for line in receipt.after_switch]
    print("deploy: activation", flush=True)
    started = time.monotonic()
    shipped = landed_plugins(repository)
    if shipped:
        target.wp("plugin", "activate", *shipped)
        actions.append("wp plugin activate " + " ".join(shipped))
    # Templates and the WindPress volume are data, not files: without present
    # a deploy keeps the previous volume, and a page whose fragment changed
    # renders unstyled. Idempotent; present keeps its own rollback copy.
    if "md-site" in shipped:
        print("deploy: present (templates and the WindPress volume)", flush=True)
        started_present = time.monotonic()
        target.wp(*plan.PRESENT, "--user=1")
        print(
            f"deploy: present done in {time.monotonic() - started_present:.1f}s",
            flush=True,
        )
        actions.append("wp " + " ".join(plan.PRESENT) + " --user=1")
        # The SEO configuration rides the same DB plane (spec 22 §9: the
        # runbook's config surface reaches staging and production through
        # the deploy, not by hand): templates, noindex faces, sitemap,
        # robots.txt, schema builder posts. Its report notes the pages
        # that are still pending; that is a report, not a failure.
        print("deploy: seo (SEOPress configuration)", flush=True)
        started_seo = time.monotonic()
        target.wp(*plan.SEO, "--user=1")
        print(
            f"deploy: seo done in {time.monotonic() - started_seo:.1f}s",
            flush=True,
        )
        actions.append("wp " + " ".join(plan.SEO) + " --user=1")
    else:
        actions.append(
            "present skipped: md-site has not landed yet (its ticket ships the "
            "templates and the volume)"
        )
    # The switch-time reset covers the rename itself; present and the plugin
    # activation run afterwards, and a request can still meet a tree the
    # workers compiled before them. A second reset at the end leaves every
    # worker compiling the final tree.
    print(f"deploy: activation done in {time.monotonic() - started:.1f}s", flush=True)
    refresh = target.shell(opcache_reset_script(target))
    line = refresh.replace(atomic.AFTER_SWITCH_MARKER, "").strip().splitlines()[-1:]
    actions.append("after present: " + (" ".join(line) or "opcache-reset=unknown"))
    # LiteSpeed's whole-cache purge is a WP-CLI command of the cache plugin
    # (spec 20 §3.3 step 6); on the dev instance the plugin is inert by design
    # (no LiteSpeed server module under php -S), so the purge is a note there.
    if remote:
        target.wp("litespeed-purge", "all")
        actions.append("wp litespeed-purge all")
    else:
        actions.append(
            "LiteSpeed purge skipped: the dev instance has no LiteSpeed server"
        )
    actions += windpress_instructions(target)
    actions += [f"manual: {item}" for item in plan.MANUAL]
    if reminder:
        # Last, so it is the line the log ends on and never shifts the others.
        actions.append(reminder)
    return Result("deploy", actions, [], receipt)


def windpress_instructions(target: Target) -> list[str]:
    """How to run the browser-side compile, and what to check afterwards.

    Tailwind v4 has no server-side build: the only compile that exists runs
    in the WindPress admin's worker. The dev instance has the automated path
    (`just deploy windpress-compile <instance>`); the remote sites are driven
    through their admin by a person (the review site is password-protected
    anyway).
    """
    if target.rsync_remote is None:
        instance = target.label.removeprefix("local:")
        compile_how = f"just deploy windpress-compile {instance}"
        check = f"just deploy windpress-check --target {target.label}"
    else:
        compile_how = (
            "log into the admin (the operator's credentials) → WindPress → "
            "Settings → Performance → Generate"
        )
        check = f"just deploy windpress-check --target {target.label}"
    return [
        f"windpress: run the browser compile on {target.label}: {compile_how}",
        f"windpress: then check with `{check}`",
    ]


def windpress_cache_mtime(target: Target) -> int:
    """The WindPress cache file's mtime on the target (0 when it does not exist)."""
    # wp_upload_dir()['basedir'] has no trailing slash; the relative path is
    # built with one, or the concatenation lands beside the uploads directory
    # and filemtime() answers false for a file that exists (ticket #55: the
    # check reported 0 while SSH showed the compiled cache).
    relative = plan.WINDPRESS_CACHE.removeprefix("wp-content/uploads/")
    php = f'echo (int) @filemtime( wp_upload_dir()["basedir"] . "/{relative}" );'
    answer = target.wp("eval", php, check=False).strip().splitlines()[-1:]
    if not answer or not answer[0].isdigit():
        return 0
    return int(answer[0])


def windpress_check(target: Target, *, before: int | None = None) -> Result:
    """The checks after a WindPress compile: the cache exists and is newer.

    The anchor-class half of the assertion (spec 17 §5.4) is the presentation
    plugin's own checker, reached through `wp md-site windpress-anchors`;
    until that plugin lands the check says so instead of inventing its
    answer.
    """
    actions: list[str] = []
    notes: list[str] = []
    mtime = windpress_cache_mtime(target)
    actions.append(f"windpress cache mtime: {mtime}")
    if mtime == 0:
        notes.append("the WindPress cache does not exist (present first, then compile)")
    if before is not None and mtime <= before:
        notes.append(
            f"the cache is not newer than {before}: the compile did not run or store"
        )
    anchors = target.wp(*plan.WINDPRESS_ANCHORS, check=False).strip()
    if anchors and anchors.startswith("["):
        if anchors != "[]":
            notes.append(f"the compiled cache misses anchor classes: {anchors}")
        else:
            actions.append("anchor classes present")
    else:
        notes.append(
            "the anchor-class check needs md-site's own checker "
            "(wp md-site windpress-anchors); the plugin has not landed yet"
        )
    return Result("windpress-check", actions, notes)


def verify(target: Target, *, cache_buster: str) -> Result:
    """The read-only regression subset (spec 20 §3.3 step 7).

    The home page always answers. The content entry points and the first
    pattern page are asserted once the content import has run (its primary
    artifact is a published product): before it they answer 404 by
    construction, and a machinery deploy must still be able to verify and
    archive — the rollout is deploy → import → review → production. On
    production, the page-cache contract is asserted on the response headers:
    two anonymous GETs of the home page, miss then hit (spec 20 §4.1;
    staging's page cache is off by ruling, and the dev instance has no
    LiteSpeed server at all).
    """
    actions: list[str] = []
    notes: list[str] = []
    status, _ = target.http(plan.HOME_PATH, cache_buster=cache_buster)
    actions.append(f"GET {plan.HOME_PATH} -> {status}")
    if status != 200:
        notes.append(f"{plan.HOME_PATH} answered {status}")
    slug = _first_pattern_slug(target)
    if slug is None:
        actions.append(
            "content not imported yet: the entry-point checks wait for it "
            "(the import's first published product turns them on)"
        )
    else:
        for path in plan.CONTENT_PATHS:
            status, _ = target.http(path, cache_buster=cache_buster)
            actions.append(f"GET {path} -> {status}")
            if status != 200:
                notes.append(f"{path} answered {status}")
        path = f"{plan.PATTERN_BASE}{slug}/"
        status, body = target.http(path, cache_buster=cache_buster)
        actions.append(f"GET {path} -> {status}")
        if status != 200:
            notes.append(f"{path} answered {status}")
        if slug not in body:
            notes.append(f"{path} does not carry its pattern's slug")
    if target.label == "prod":
        cache_actions, cache_notes = _page_cache_check(target)
        actions += cache_actions
        notes += cache_notes
    return Result("verify", actions, notes)


def _first_pattern_slug(target: Target) -> str | None:
    """The first published product's slug, or None while no content has landed."""
    listed = target.wp(
        "post",
        "list",
        "--post_type=product",
        "--post_status=publish",
        "--orderby=ID",
        "--order=asc",
        "--field=post_name",
        "--posts_per_page=1",
        check=False,
    ).strip()
    return listed.splitlines()[0] if listed else None


def _page_cache_check(target: Target) -> tuple[list[str], list[str]]:
    """Two anonymous GETs without a cache buster: the first misses, the second hits.

    The header is LiteSpeed Cache's own control header (`x-litespeed-cache`).
    No cache buster here — a busted URL is a fresh cache key by definition and
    can never hit.
    """
    actions: list[str] = []
    notes: list[str] = []
    answers: list[str] = []
    for attempt in (1, 2):
        status, headers, _ = target.fetch("/")
        value = (headers.get("x-litespeed-cache") or "").strip().lower()
        answers.append(value)
        actions.append(f"page cache: GET / (attempt {attempt}) -> {status} {value}")
        if status != 200:
            notes.append(f"page cache: / answered {status}")
    if answers[0] != "miss":
        notes.append(
            f"page cache: the first GET was {answers[0]!r}, expected miss "
            "(the deploy purged the cache)"
        )
    if answers[1] != "hit":
        notes.append(
            f"page cache: the second GET was {answers[1]!r}, expected hit — "
            "the page cache is not serving"
        )
    return actions, notes


def finish_deploy(
    target: Target, receipt: atomic.Receipt, *, cache_buster: str
) -> Result:
    """Only a passing verify allows backups to leave the web root."""
    checked = verify(target, cache_buster=cache_buster)
    if checked.notes:
        return Result("deploy-verify", checked.actions, checked.notes)
    atomic.archive(target, receipt)
    return Result(
        "deploy-verify",
        checked.actions
        + [
            f"archived backups by rename: {receipt.backup_directory}",
            "retained only the most recent deploy backup set",
        ],
        [],
    )


def resume_deploy(target: Target, *, repository: Path, cache_buster: str) -> Result:
    """Verify/archive a pending batch after an interrupted reply or failed verify."""
    relatives = _manifest_relatives(target)
    receipt = atomic.pending_receipt(target, relatives)
    checked = finish_deploy(target, receipt, cache_buster=cache_buster)
    return Result(
        "resume-deploy",
        [f"deploy id: {receipt.deploy_id}", *checked.actions],
        checked.notes,
    )


def _manifest_relatives(target: Target) -> list[str]:
    """The last batch's own inventory, from its manifest.

    The rollback and the resume restore the batch that was actually deployed.
    The blueprint recomputed the inventory from the tree; this site's
    inventory legitimately grows while the plugin and theme tickets land, so
    the manifest — not today's checkout — is the authority for what a
    rollback must put back.
    """
    _, manifest = atomic.latest_manifest(target)
    return [entry.relative for entry in manifest.backups]


def rollback_deploy(target: Target, *, repository: Path) -> Result:
    """Restore a consistent manifest, then prove live identities and HTTP."""
    del repository  # the manifest names the batch; the tree is not the authority
    relatives = _manifest_relatives(target)
    receipt = atomic.rollback(target, relatives)
    actions = [f"deploy id: {receipt.deploy_id}", *receipt.readbacks]
    listed = target.wp_json("plugin", "list", "--fields=name,status")
    statuses = {
        row["name"]: row.get("status", "missing")
        for row in listed
        if isinstance(row, dict) and "name" in row
    }
    notes: list[str] = []
    for slug in plan.PLANNED_PLUGINS:
        status = statuses.get(slug, "missing")
        actions.append(f"plugin {slug}: {status}")
        if status not in ("active", "missing"):
            notes.append(f"plugin {slug} is {status} after rollback")
    checked = verify(target, cache_buster=receipt.deploy_id + "-rollback")
    return Result("rollback-deploy", actions + checked.actions, notes + checked.notes)


def plugin_face(target: Target) -> list[str]:
    """The target's plugin list, one `name<TAB>status` row each (drill evidence)."""
    listed = target.wp_json("plugin", "list", "--fields=name,status")
    return sorted(
        f"{row['name']}\t{row.get('status', 'missing')}"
        for row in listed
        if isinstance(row, dict) and "name" in row
    )
