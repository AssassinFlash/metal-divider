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

from md_local import permalinks
from md_local import versions as local_versions

from md_deploy import atomic, plan
from md_deploy import backup as backup_module
from md_deploy import snapshot as snapshot_module
from md_deploy.target import DeployError, Target

# The directory the opcache reset's token lives in, beside the WordPress root
# and outside the web root. `mu-plugins/md-deploy-reset.php` computes the same
# path from ABSPATH (DeployReset::DIRECTORY); a test pins the two together.
RESET_DIRECTORY = ".md-deploy"


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


def deployment_id(repository: Path, state: SourceState | None = None) -> str:
    """UTC time plus what the bytes actually are: the commit, or the commit and its dirty content.

    A clean shipped tree is the commit, and the commit is the whole story of
    where the bytes came from. A dirty one is not: naming only the commit
    would attribute a batch to source that does not contain it, and a
    manifest or a rollback audit would then be wrong about the one thing it
    is for. The dirty form names the commit the tree was built on AND a
    digest of the files that differ from it, so two different states never
    share an id — while staying inside the id alphabet `atomic._check_id`
    accepts.
    """
    state = state or source_state(repository)
    stamp = datetime.now(UTC).strftime("%Y%m%dT%H%M%S%fZ")
    if not state.dirty:
        return f"{stamp}-{state.commit}"
    return f"{stamp}-{state.commit}-dirty-{state.digest}"


@dataclass(frozen=True)
class SourceState:
    """What a deploy's bytes are: the commit, and the shipped files that differ from it."""

    commit: str
    # Repo-relative paths that would ship and are not the commit's version.
    dirty: tuple[str, ...]
    # A digest over those files' content; '' when nothing differs.
    digest: str


def source_state(repository: Path) -> SourceState:
    """The checkout's commit, and the shipped paths whose bytes the commit does not have.

    Only the paths that actually ship count. A dirty test file, a `vendor/`
    tree or a PHPCS config changes no deployed byte (`DEPLOY_EXCLUDES` drops
    them from the staged tree), so counting them would refuse a deploy over a
    file that never leaves this Mac — and would make the local workflow
    depend on a clean repository for no reason.
    """
    commit = subprocess.run(
        ["git", "rev-parse", "--short", "HEAD"],
        cwd=repository,
        capture_output=True,
        text=True,
        check=False,
    )
    if commit.returncode:
        raise DeployError("the deployment source must be a git checkout")
    dirty = _dirty_shipped_paths(repository)
    return SourceState(
        commit=commit.stdout.strip(),
        dirty=tuple(dirty),
        digest=_dirty_digest(repository, dirty) if dirty else "",
    )


def _dirty_shipped_paths(repository: Path) -> list[str]:
    """The repo-relative paths under a shipped tree that differ from HEAD, sorted.

    Type changes and renames are included: a rename leaves bytes at a path
    the commit does not have. Untracked files count too — rsync copies them,
    so they ship.
    """
    roots = _shipped_roots(repository)
    if not roots:
        return []
    # -z: paths come through NUL-separated, so a space or a newline in a name
    # cannot be mistaken for the status columns.
    status = subprocess.run(
        [
            "git",
            "status",
            "--porcelain=v1",
            "-z",
            "--untracked-files=all",
            "--",
            *roots,
        ],
        cwd=repository,
        capture_output=True,
        check=False,
    )
    if status.returncode:
        raise DeployError(
            "cannot read the deployment source's status: "
            + status.stderr.decode("utf-8", "replace").strip()
        )
    entries = status.stdout.decode("utf-8", "surrogateescape").split("\0")
    dirty: list[str] = []
    index = 0
    while index < len(entries):
        entry = entries[index]
        index += 1
        if len(entry) < 4:
            continue
        path = entry[3:]
        if entry[0] in ("R", "C"):
            # A rename/copy reports the destination at this index and the
            # origin in the next NUL field; the destination is what ships.
            index += 1
        relative = _artifact_relative(path, roots)
        if relative is None or not _ships(relative):
            continue
        dirty.append(path)
    return sorted(dirty)


def _shipped_roots(repository: Path) -> list[str]:
    """The repository paths the deploy's inventory is built from.

    Read from the plan's own inventory rather than spelled out here, so a
    tree added to the deploy cannot silently escape the dirty check.
    """
    roots = [f"{plan.MU_PLUGINS_REPO}/*.php", f"{plan.THEMES_REPO}/{plan.CHILD_THEME}"]
    roots += [
        f"{plan.REPO}/{slug}"
        for slug in plan.PLANNED_PLUGINS
        if _plugin_landed(repository, slug)
    ]
    return roots


def _plugin_landed(repository: Path, slug: str) -> bool:
    """Whether the plugin's source tree is in this checkout (`landed_plugins`, one slug)."""
    return (Path(repository) / plan.REPO / slug / f"{slug}.php").is_file()


def _artifact_relative(path: str, roots: list[str]) -> str | None:
    """The path as it appears inside a staged tree (the form DEPLOY_EXCLUDES matches)."""
    for root in roots:
        if root.endswith("/*.php"):
            # `mu-plugins/*.php` is that directory's top-level files only:
            # `deployment_artifacts` globs exactly those, and a nested file
            # under it does not ship.
            directory = root.removesuffix("/*.php")
            if Path(path).parent.as_posix() == directory and path.endswith(".php"):
                return path
            continue
        if path == root:
            return ""
        if path.startswith(root + "/"):
            return path.removeprefix(root + "/")
    return None


def _ships(relative: str) -> bool:
    """Whether a staged tree keeps this path — the inverse of `plan.DEPLOY_EXCLUDES`.

    rsync's exclude patterns match a name at any depth (`tests/` keeps out
    every `tests` directory), so the test is per path component, not on the
    whole relative path.
    """
    parts = relative.split("/")
    for pattern in plan.DEPLOY_EXCLUDES:
        if pattern.rstrip("/") in parts:
            return False
    return True


def _dirty_digest(repository: Path, dirty: list[str]) -> str:
    """A short digest over the dirty files' content: two states, two ids."""
    digest = hashlib.sha256()
    for path in dirty:
        absolute = Path(repository) / path
        digest.update(path.encode("utf-8"))
        digest.update(b"\0")
        # A deleted path is its own state, and must not read as an empty file.
        digest.update(
            hashlib.sha256(absolute.read_bytes()).digest()
            if absolute.is_file()
            else b"-"
        )
    return digest.hexdigest()[:8]


def _refuse_dirty_remote(state: SourceState, target: Target) -> None:
    """Stop a deploy that would ship working-tree bytes under a commit's name.

    A remote deploy must ship what a commit contains: the batch's id names
    that commit, and the manifest and the rollback are read later to answer
    "which source is live?". Bytes from somebody's working tree answer it
    wrongly, and nothing downstream can tell. The local instance is exempt
    because it is where those bytes are being developed — and there the id
    carries the dirty content's digest (`deployment_id`), so it stays true.
    """
    if target.rsync_remote is None or not state.dirty:
        return
    listed = ", ".join(state.dirty[:8])
    if len(state.dirty) > 8:
        listed += f" and {len(state.dirty) - 8} more"
    raise DeployError(
        f"{target.label} must deploy a commit, and {len(state.dirty)} shipped "
        f"path(s) differ from {state.commit}: {listed}. Commit or stash them "
        "(the local instance deploys them as they are: `just deploy dev --write`)."
    )


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
    is read by `mu-plugins/md-deploy-reset.php` from the directory beside the
    web root and deleted there. That directory is computed from the root this
    target deploys into, which is the same `dirname( ABSPATH )` the plugin
    computes — the two sides share a filesystem, never an environment: the
    production web SAPI runs with no `HOME` (measured 2026-10-26), so a
    `$HOME`-relative path written here was never found there, and every deploy
    logged `opcache-reset=none`. It is per site, so a staging token cannot be
    presented to production.

    It travels in a header file, never on a command line other accounts on the
    shared host could list. The review site is password-protected (spec 20
    §1.3), so its reset request carries the basic-auth pair — in the script's
    curl line, never in a file; an empty `-u` would break curl on every other
    target, so the flag exists only when the pair does.
    """
    url = shlex.quote(target.base_url.rstrip("/") + "/")
    directory = shlex.quote(str(Path(target.root).parent / RESET_DIRECTORY))
    token = shlex.quote(
        str(Path(target.root).parent / RESET_DIRECTORY / "opcache-reset.token")
    )
    header = shlex.quote(
        str(Path(target.root).parent / RESET_DIRECTORY / "opcache-reset.header")
    )
    marker = atomic.AFTER_SWITCH_MARKER
    auth = f"-u {shlex.quote(target.http_auth)} " if target.http_auth else ""
    return f"""(
umask 077
dir={directory}
mkdir -p "$dir"
token="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \\n')"
printf '%s\\n' "$token" > {token}
printf 'X-MD-Opcache-Reset: %s\\n' "$token" > {header}
outcome=none
attempt=0
while [ "$attempt" -lt {plan.OPCACHE_RESET_ATTEMPTS} ]; do
  attempt=$((attempt + 1))
  answer="$(curl -sS -o /dev/null -D - --max-time 5 -X POST {auth}-H @{header} {url} 2>/dev/null | tr -d '\\r' | sed -n 's/^[Xx]-[Mm][Dd]-[Oo]pcache-[Rr]eset: *//p')"
  if [ -n "$answer" ]; then outcome="$answer"; break; fi
  sleep {plan.OPCACHE_RESET_PAUSE}
done
rm -f {token} {header}
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


def apply_data_plane(target: Target, actions: list[str]) -> None:
    """Install md-site's data plane: templates, seeds, SEO configuration.

    These steps are data, not files, so the atomic code switch cannot carry
    them; without them a switched target keeps the previous WindPress volume
    and the previous seed state while running the new templates.

    The order matters and is asserted by the tests:

    * ``present`` first — the templates and the volume the pages render from.
    * ``seed-series`` — the hub's card images and copy, which a clean target
      has as empty WooCommerce term fields.
    * ``seed-tiles`` — the custom-screens panels' order, which is WooCommerce's
      ``menu_order``; a clean target has every product at 0, so the page would
      render no tiles at all.
    * ``seo`` last — it reads the terms the two seeds have just filled.

    Both seeds mark what they handled and never touch a maintained record
    again, so deploying onto a site someone has been editing is a no-op.
    """
    for step in (plan.PRESENT, plan.SEED_SERIES, plan.SEED_TILES, plan.SEO):
        print(f"deploy: {step[1]}", flush=True)
        started = time.monotonic()
        target.wp(*step, "--user=1")
        print(
            f"deploy: {step[1]} done in {time.monotonic() - started:.1f}s", flush=True
        )
        actions.append("wp " + " ".join(step) + " --user=1")


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

    The source is checked before the snapshot: a remote deploy must ship a
    commit (`_refuse_dirty_remote`), because the batch's id names that commit
    and the manifest and the rollback are read later to answer "which source
    is live?". The local instance deploys its working tree as it stands — that
    is where those bytes are being written — and there the id carries the
    dirty content's digest, so it stays a true statement about the bytes.
    """
    home = snapshot_home or backup_module.DATA_HOME
    remote = target.rsync_remote is not None
    source = source_state(Path(repository))
    _refuse_dirty_remote(source, target)
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
        deploy_id=deploy_id or deployment_id(repository, source),
        on_switch=on_switch,
        after_switch=opcache_reset_script(target),
    )
    assert receipt.switch_seconds is not None
    actions = [
        f"database snapshot {taken.path} ({taken.size} bytes, sha256 {taken.sha256[:16]}…); "
        f"restore with `just deploy restore-snapshot {taken.name}`"
    ]
    # The switch has already happened, so a note here is a report, not a
    # refusal: the post-switch steps that find the site wrong record it, and
    # the CLI turns any note into a non-zero exit (a window must not read a
    # switched-but-wrong site as a pass).
    notes: list[str] = []
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
        apply_data_plane(target, actions)
    else:
        actions.append(
            "present skipped: md-site has not landed yet (its ticket ships the "
            "templates and the volume)"
        )
    # The URL scheme is options, not files, so the switch cannot carry it: a
    # database imported from a site configured otherwise answers at URLs no
    # link, template or sitemap entry names. It sits outside the md-site
    # branch because it belongs to no plugin — every target gets it, present
    # or not. Idempotent by readback, so a second deploy writes nothing.
    print("deploy: url-scheme (the permalink options)", flush=True)
    scheme = url_scheme(target)
    actions += scheme.actions
    notes += scheme.notes
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
    return Result("deploy", actions, notes, receipt)


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


def url_scheme(target: Target, *, write: bool = True) -> Result:
    """The site's URL scheme options: apply them (`write`), or only read them back.

    The scheme is `md_local.permalinks` — the local stack's own definition, so
    the deploy cannot hold a second copy of a URL scheme the dev instance was
    built with. It is two `wp_options` rows, and files cannot carry it: a
    database imported from a site configured otherwise answers at URLs no
    link, template or sitemap entry names.

    Idempotent by readback, and the readback is the proof in both directions:
    `wp rewrite structure` exits 0 whether or not the options it wrote are the
    ones that came back, and a note is what stops the deploy from archiving its
    backups (`finish_deploy`). No `--hard`: the write is the two options and
    the `rewrite_rules` refresh, never `.htaccess` (host setup, spec 20 §2.1).
    """
    actions: list[str] = []
    notes: list[str] = []
    try:
        if write:
            applied, wrote = permalinks.apply(target.wp)
            outcome = "written and read back" if wrote else "already applied"
        else:
            applied, outcome = permalinks.read(target.wp), "read back"
    except permalinks.PermalinkError as error:
        return Result("url-scheme", actions, [str(error)])
    scheme = f"{applied.structure} / categories {applied.category_base}"
    actions.append(f"url scheme {scheme} {outcome}")
    if not write and applied != permalinks.SCHEME:
        notes.append(
            f"the url scheme is {scheme}, the site's should be "
            f"{permalinks.SCHEME.structure} / categories {permalinks.SCHEME.category_base}"
        )
    return Result("url-scheme", actions, notes)


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
    plugin's own checker, reached through `wp md-site windpress-anchors
    --format=json`: it prints the anchors the compiled cache does not carry,
    `[]` when it carries them all. A stream that is not a JSON array means the
    checker did not answer at all — the cache is missing, or the plugin is not
    active — and that is said as a note rather than read as a pass.
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
            "(wp md-site windpress-anchors) and it printed no answer: "
            "the compiled cache is missing or the plugin is inactive"
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
    the home page must be answered from LiteSpeed's cache — a first GET that
    already hits, or a hit within a bounded poll after a miss
    (`_page_cache_check`; staging's page cache is off by ruling, and the dev
    instance has no LiteSpeed server at all). The URL scheme options are read
    back here too: they are what the entry-point paths resolve through, so a
    drifted scheme becomes its own note instead of a scatter of 404s.
    """
    actions: list[str] = []
    notes: list[str] = []
    scheme = url_scheme(target, write=False)
    actions += scheme.actions
    notes += scheme.notes
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
    """One anonymous GET must be answered from LiteSpeed's page cache.

    The header is LiteSpeed Cache's own control header (`x-litespeed-cache`).
    No cache buster here — a busted URL is a fresh cache key by definition and
    can never hit.

    What is asserted is that the cache *serves*, not which request first saw
    it. Two shapes pass:

    - the first GET is already a hit (a warm cache: a resumed deploy, or a
      window whose purge ran earlier than verify);
    - a miss, followed by a hit within `plan.PAGE_CACHE_ATTEMPTS` — LiteSpeed
      generates the entry for a miss and may finish after that response has
      gone out, so demanding a hit on the immediate second request measured
      the generator's scheduling rather than the cache (production, 2026-10-26:
      two back-to-back misses, then every following curl a hit).

    What never passes: a non-200 (a page that does not serve is not a cache
    result), and a run of misses through the whole deadline — that is the
    cache genuinely not serving, reported with what was actually seen.
    """
    actions: list[str] = []
    notes: list[str] = []
    seen: list[str] = []
    for attempt in range(1, plan.PAGE_CACHE_ATTEMPTS + 1):
        status, headers, _ = target.fetch("/")
        value = (headers.get("x-litespeed-cache") or "").strip().lower()
        seen.append(value or "no-header")
        actions.append(
            f"page cache: GET / (attempt {attempt}) -> {status} {value or 'no header'}"
        )
        if status != 200:
            # Polling would not change this verdict, and the window should see
            # the failure at once rather than after the deadline.
            notes.append(f"page cache: / answered {status} on attempt {attempt}")
            return actions, notes
        if value == "hit":
            break
        if attempt < plan.PAGE_CACHE_ATTEMPTS:
            time.sleep(plan.PAGE_CACHE_PAUSE)
    if "hit" not in seen:
        notes.append(
            f"page cache: / never answered hit in {len(seen)} attempt(s) "
            f"over {plan.PAGE_CACHE_ATTEMPTS * plan.PAGE_CACHE_PAUSE:.1f} s "
            f"(saw {', '.join(seen)}) — the page cache is not serving"
        )
    return actions, notes


def finish_deploy(
    target: Target,
    receipt: atomic.Receipt,
    *,
    cache_buster: str,
    phase_notes: list[str] | None = None,
) -> Result:
    """Only a passing verify allows backups to leave the web root.

    `phase_notes` carries what the switch-time steps reported (`deploy()`'s
    own notes — the URL scheme's readback, say): those ran before verify, so
    they are part of "did this deploy land correctly", and a batch that left
    one must keep its rollback copies where `rollback-deploy` finds them.
    Verify still runs and is still reported; the notes are combined.

    The notes are also written beside the manifest before verify runs, so a
    batch that fails verify — or whose reply never arrives — can still be
    finished by `resume-deploy`, which runs in a separate process and would
    otherwise have no way to learn what the switch phases reported.
    """
    atomic.write_phase_notes(target, receipt.backup_directory, list(phase_notes or []))
    checked = verify(target, cache_buster=cache_buster)
    notes = [*(phase_notes or []), *checked.notes]
    if notes:
        return Result("deploy-verify", checked.actions, notes)
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
    """Verify/archive a pending batch after an interrupted reply or failed verify.

    The switch-time notes are read back from the batch's own record rather
    than defaulted to none: this process never ran those phases, and losing
    the notes here would archive a batch whose switch reported a problem —
    exactly the state `finish_deploy`'s own contract forbids.
    """
    relatives = _manifest_relatives(target)
    receipt = atomic.pending_receipt(target, relatives)
    phase_notes = atomic.read_phase_notes(target, receipt.backup_directory)
    checked = finish_deploy(
        target, receipt, cache_buster=cache_buster, phase_notes=phase_notes
    )
    actions = [f"deploy id: {receipt.deploy_id}", *checked.actions]
    if phase_notes:
        actions.append(f"switch-time notes recovered: {len(phase_notes)}")
    return Result("resume-deploy", actions, checked.notes)


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
