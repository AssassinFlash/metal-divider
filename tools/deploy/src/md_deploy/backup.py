"""The complete backup of a site, and the dump/restore scripts both targets share.

A complete, restorable copy of the site under the data root on this Mac
(`~/metal-divider-local/backups/<stamp>/`): the database, `wp-content/uploads`,
`wp-content/plugins`, `wp-content/mu-plugins`, `.htaccess`, an options export,
and a sha256 manifest of everything written. Backups live on this Mac only,
never on the host (AGENTS.md §5: 备份只存本机,不占服务器空间).

The dump is mysqldump, not `wp db export`. WP-CLI's export calls `exec()`
before it reaches mysqldump (wp-cli/db-command DB_Command.php:655) and the
shared host disables `exec` (disable_functions, read on the platform
2026-10-09), so the friendlier command cannot run there. The password stays
on the machine that runs the dump, in mysqldump's environment, and never
reaches this Mac as a command line.

Both scripts read and write the dump on the process's standard streams, so
one implementation covers a local instance (where the file happens to be
local) and the host (where it is piped through ssh): nothing in the script
names a path that only one of the two machines has.

Ported from the blueprint's proven implementation; the full backup is the
incremental `--link-dest` kind (unchanged files are hard-linked from the
newest complete backup, so a whole restorable tree costs only what changed).
"""

from __future__ import annotations

import gzip
import hashlib
import json
import shlex
import shutil
import subprocess
import tempfile
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from typing import IO

from md_deploy.target import (
    HTACCESS,
    MU_PLUGINS,
    PLUGINS,
    TRANSFER_ATTEMPTS,
    UPLOADS,
    DeployError,
    Target,
    _connection_dropped,
    _pause_after_drop,
)

# The data root on this Mac, shared with the local stack (tools/local): full
# backups (`backups/backup-*`), deploy snapshots (`snapshots/`) and the deploy
# workspace, all outside the repository.
DATA_HOME = Path.home() / "metal-divider-local"
BACKUPS = "backups"

MANIFEST = "manifest.json"
DUMP = "db.sql.gz"
OPTIONS = "options.json"


@dataclass(frozen=True)
class Backup:
    """A written backup, and what it holds."""

    directory: Path
    files: int
    dump_sha256: str


# The WordPress convention for DB_HOST is not mysqldump's: WP-CLI accepts
# `host`, `host:port` and `host:/path/to/socket`, and a local instance has the
# third form (`localhost:/tmp/mysql.sock`). mysqldump reads that whole string
# as a host name and fails with "Unknown MySQL server host". One function
# turns the WordPress form into the client's, so the dump is one script for
# both targets.
_DB_TARGET = """db_target() {
  local raw
  raw="$("${wp[@]}" config get DB_HOST)"
  DB_ARGS=()
  case "$raw" in
    *:/*) DB_ARGS+=(--host="${raw%%:*}" --socket="${raw#*:}") ;;
    *:*)  DB_ARGS+=(--host="${raw%%:*}" --port="${raw##*:}") ;;
    *)    DB_ARGS+=(--host="$raw") ;;
  esac
  DB_ARGS+=(--user="$("${wp[@]}" config get DB_USER)")
}"""

# Two flags keep the dump inside what the site's own database user can do.
# `--no-tablespaces` is for the dump end: reading the tablespaces needs the
# PROCESS privilege the site's user does not have (and a logical restore does
# not use them). The rest is for the import end, which is where it matters: a
# statement the import cannot run makes the backup unrestorable, and the
# rollback is the one thing that has to work. MySQL's mysqldump writes the
# server's GTID position (SET @@GLOBAL.GTID_PURGED) when the server has GTIDs;
# MariaDB's does not, and MariaDB's mysqldump does not know
# `--set-gtid-purged` at all. So the script asks mysqldump itself what it
# takes — its own help output is the authority — and passes the flag where it
# exists.
_DUMP_FLAGS = """dump_extra() {
  local help
  DUMP_EXTRA=()
  help="$(mysqldump --help 2>/dev/null || true)"
  case "$help" in
    *--set-gtid-purged*) DUMP_EXTRA=(--set-gtid-purged=OFF) ;;
  esac
}
"""


def _expansion(name: str) -> str:
    """A bash array expansion that is empty-array safe under `set -u`.

    bash 3.2 — macOS's /bin/bash — treats `"${a[@]}"` of an empty array as an
    unbound variable when `set -u` is on, and these scripts run on both
    machines. The `+` form expands to nothing instead, which is what an empty
    array means.
    """
    return "${" + name + '[@]+"${' + name + '[@]}"}'


def _wp_array(target: Target) -> str:
    """The target's WP-CLI command as a bash array literal, valid on the target.

    A string assignment plus unquoted `$wp` splits the remote prefix apart;
    the array keeps each element whole. `Target.wp_on_target` is the
    on-machine form.
    """
    return "(" + " ".join(shlex.quote(part) for part in target.wp_on_target()) + ")"


def dump_script(target: Target, *, skip: tuple[str, ...] = ()) -> str:
    """The shell script that writes the database, gzip-compressed, to standard output.

    `skip` names tables, without the WordPress prefix, to leave out; the
    prefix and database name are read on the machine the site runs on, so the
    same script serves both targets. A deploy snapshot skips nothing (spec 20
    §3.1: this site has no giant table to exclude).
    """
    wp = _wp_array(target)
    skipping = "".join(
        f'SKIP+=(--ignore-table="$name.$prefix"{shlex.quote(table)})\n'
        for table in skip
    )
    return f"""set -euo pipefail
cd {shlex.quote(target.root)}
wp={wp}
{_DB_TARGET}
{_DUMP_FLAGS}
db_target
dump_extra
name="$("${{wp[@]}}" config get DB_NAME)"
prefix="$("${{wp[@]}}" config get table_prefix)"
SKIP=()
{skipping}MYSQL_PWD="$("${{wp[@]}}" config get DB_PASSWORD)" mysqldump \\
  --single-transaction --quick --skip-dump-date --order-by-primary \\
  --default-character-set=utf8mb4 --no-tablespaces \\
  {_expansion("DUMP_EXTRA")} \\
  {_expansion("SKIP")} \\
  {_expansion("DB_ARGS")} \\
  "$name" | gzip
"""


def restore_script(target: Target) -> str:
    """The shell script that replaces the database with a dump read from standard input."""
    wp = _wp_array(target)
    return f"""set -euo pipefail
cd {shlex.quote(target.root)}
wp={wp}
{_DB_TARGET}
db_target
gunzip -c | MYSQL_PWD="$("${{wp[@]}}" config get DB_PASSWORD)" mysql \\
  --default-character-set=utf8mb4 \\
  {_expansion("DB_ARGS")} \\
  "$("${{wp[@]}}" config get DB_NAME)"
"""


def sha256(path: Path) -> str:
    """The SHA-256 of a file, read in chunks so a database dump fits in memory."""
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def directory_manifest(directory: Path) -> dict[str, dict[str, object]]:
    """Every file under `directory`, with its sha256 and size, keyed by relative path."""
    entries: dict[str, dict[str, object]] = {}
    for path in sorted(directory.rglob("*")):
        if path.is_file() and not path.is_symlink():
            entries[str(path.relative_to(directory))] = {
                "sha256": sha256(path),
                "bytes": path.stat().st_size,
            }
    return entries


def backups_home(home: Path) -> Path:
    """`~/metal-divider-local/backups/`, beside the local stack's own data."""
    return home / BACKUPS


def destination(home: Path, *, now: datetime | None = None) -> Path:
    """`~/metal-divider-local/backups/backup-<YYYYMMDD-HHMM>/`."""
    stamp = (now or datetime.now(UTC)).strftime("%Y%m%d-%H%M")
    return backups_home(home) / f"backup-{stamp}"


def _prove_the_tree_arrived(target: Target, relative: str, local: Path) -> None:
    """The pulled tree holds every file the site has — by name, case-folded.

    The chunk walk decides what travels, so a chunk rule that misses a kind
    of file makes a silently smaller backup — the blueprint measured 195 files
    absent from its first production backup this way. The site lists its own
    tree and the local tree must agree.

    The comparison folds case because this Mac's filesystem is
    case-insensitive: `includes/jobs.php` and `includes/Jobs.php` are two
    files on the Linux host and one slot here. Folding is the strongest claim
    this side can make, and it is the honest one.
    """
    listed = target.shell(
        f"cd {shlex.quote(target.path_of(relative))} && find . -type f"
    )
    on_site = {
        line.strip().removeprefix("./").lower()
        for line in listed.splitlines()
        if line.strip()
    }
    # `find -type f` lists regular files, so the local side ignores symlinks
    # the same way (the manifest agrees).
    arrived = {
        str(path.relative_to(local)).lower()
        for path in local.rglob("*")
        if path.is_file() and not path.is_symlink()
    }
    if arrived != on_site:
        missing_names = sorted(on_site - arrived)
        extra_names = sorted(arrived - on_site)
        missing = ", ".join(missing_names[:3])
        extra = ", ".join(extra_names[:3])
        raise DeployError(
            f"{relative}: the backup does not hold the site's file set "
            f"(missing {len(missing_names)}, extra {len(extra_names)}; "
            f"missing like [{missing}], extra like [{extra}])"
        )


def dump_to(target: Target, path: Path, *, skip: tuple[str, ...] = ()) -> None:
    """Stream the database into `path` and refuse a dump that does not read to its end.

    A failed stream deletes the file. A closed connection can still have
    written a gzip that reads to its end; leaving that file would make a
    failed dump look restorable.
    """
    written = False
    try:
        with path.open("wb") as handle:
            stream(target, dump_script(target, skip=skip), stdout=handle)
        if path.stat().st_size == 0 or not readable_gzip(path):
            raise DeployError(f"{path} is not a readable gzip — the dump is not usable")
        written = True
    finally:
        if not written:
            path.unlink(missing_ok=True)


def restore_database(target: Target, dump: Path) -> None:
    """Import a dump into the site's database (the tables in the dump are replaced)."""
    with dump.open("rb") as handle:
        stream(target, restore_script(target), stdin=handle)


def latest_complete(home: Path, *, exclude: Path | None = None) -> Path | None:
    """The newest `backup-*` backup under `home` that finished (has its manifest)."""
    candidates = sorted(
        (
            path
            for path in backups_home(home).glob("backup-2*")
            if path.is_dir() and (path / MANIFEST).is_file() and path != exclude
        ),
        key=lambda path: path.name,
    )
    return candidates[-1] if candidates else None


def create(target: Target, home: Path, *, now: datetime | None = None) -> Backup:
    """Take the backup: database, uploads, plugins, .htaccess, options, manifest.

    Files unchanged since the newest complete backup are hard-linked to it,
    not copied again: every backup is still a whole, restorable tree on its
    own, and only what changed travels.
    """
    directory = destination(home, now=now)
    previous = latest_complete(home, exclude=directory)
    # An empty directory is a run that died before it wrote anything. A
    # complete backup, or a readable dump with no manifest, is somebody's
    # data and must not be merged into. A failed run that left neither is
    # not a backup: the same minute can take a new one.
    if directory.exists() and any(directory.iterdir()):
        if not _replaceable_failure(directory):
            state = (
                "a complete backup"
                if (directory / MANIFEST).is_file()
                else "a readable database dump"
            )
            raise DeployError(
                f"{directory} already holds {state}; move it aside to take a new one"
            )
        shutil.rmtree(directory)
    directory.mkdir(parents=True, exist_ok=True)
    print(f"backup: {directory}")
    finished = False
    try:
        result = _fill(target, directory, previous)
        finished = True
        return result
    finally:
        if not finished:
            _discard_incomplete(directory)


def _fill(target: Target, directory: Path, previous: Path | None) -> Backup:
    """Write one backup. The caller deletes a readable dump if this raises."""
    print("backup: database")
    dump = directory / DUMP
    dump_to(target, dump)
    dump_sha = sha256(dump)
    print(f"backup: database {dump.stat().st_size} bytes, sha256 {dump_sha[:16]}…")

    if previous is not None:
        print(f"backup: unchanged files are hard-linked from {previous}")
    for relative in (UPLOADS, PLUGINS):
        print(f"backup: {relative}")
        target.copy_out(
            relative,
            directory / relative,
            link_dest=None if previous is None else previous / relative,
        )
        _prove_the_tree_arrived(target, relative, directory / relative)

    # The deploy writes the mu directory too (the recorder and the guards are
    # installed over the live files), so a rollback that could not put it back
    # is not a rollback. A site without that directory is a legal site with
    # nothing to put back, and the absence is recorded rather than fatal (the
    # same treatment `.htaccess` gets below). The completeness check stays
    # outside the try: a mismatch is a failed backup, not a site without a
    # directory.
    print(f"backup: {MU_PLUGINS}")
    present = True
    try:
        target.copy_out(
            MU_PLUGINS,
            directory / MU_PLUGINS,
            link_dest=None if previous is None else previous / MU_PLUGINS,
        )
    except DeployError as error:
        # A missing directory is a legal site. A dropped connection is not:
        # swallowing it would write a manifest over a tree that never arrived.
        if not _path_is_absent(error):
            raise
        present = False
        print(f"backup: no {MU_PLUGINS} ({error})")
    if present:
        _prove_the_tree_arrived(target, MU_PLUGINS, directory / MU_PLUGINS)

    htaccess = directory / HTACCESS
    try:
        target.copy_file_out(HTACCESS, htaccess)
    except DeployError as error:
        # A site without .htaccess is legal — the rewrite rules then live in
        # the server config. The absence is recorded, not fatal.
        print(f"backup: no {HTACCESS} ({error})")
        htaccess.write_text("", encoding="utf-8")

    print(f"backup: {OPTIONS}")
    (directory / OPTIONS).write_text(
        target.wp("option", "list", "--format=json") + "\n", encoding="utf-8"
    )

    manifest = directory_manifest(directory)
    (directory / MANIFEST).write_text(
        json.dumps(
            {
                "target": target.label,
                "taken_at": datetime.now(UTC).isoformat(),
                "files": manifest,
            },
            indent=1,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"backup: {len(manifest)} files, manifest written")
    return Backup(directory=directory, files=len(manifest), dump_sha256=dump_sha)


def restore(target: Target, directory: Path) -> None:
    """Put a backup back: the database, the file trees, then the caches."""
    directory = Path(directory)
    dump = directory / DUMP
    if not dump.is_file():
        raise DeployError(f"{dump} is missing — {directory} is not a usable backup")
    expected = manifest_of(directory).get(DUMP)
    if expected is not None and sha256(dump) != expected["sha256"]:
        raise DeployError(f"{dump} was modified since the backup was taken")

    print("rollback: database")
    restore_database(target, dump)
    print(f"rollback: {PLUGINS}")
    target.copy_in(directory / PLUGINS, PLUGINS)
    print(f"rollback: {UPLOADS}")
    target.copy_in(directory / UPLOADS, UPLOADS)
    # Only when the backup holds one: a site that had no mu directory has
    # nothing to restore, and creating an empty one would be a change of its
    # own (the tree stays out of the archive for the same reason).
    if (directory / MU_PLUGINS).is_dir():
        print(f"rollback: {MU_PLUGINS}")
        target.copy_in(directory / MU_PLUGINS, MU_PLUGINS)
    htaccess = directory / HTACCESS
    if htaccess.is_file() and htaccess.stat().st_size > 0:
        print(f"rollback: {HTACCESS}")
        # `.htaccess` is one file, not a tree, and it goes back the way it came
        # out: one mechanism for a file, whichever end it is on.
        target.copy_file_in(htaccess, HTACCESS)
    print("rollback: caches")
    target.wp("cache", "flush")
    target.wp("rewrite", "flush")


def manifest_of(directory: Path) -> dict[str, dict[str, object]]:
    """The `files` section of a backup's manifest, or an empty map without one."""
    path = Path(directory) / MANIFEST
    if not path.is_file():
        return {}
    data = json.loads(path.read_text(encoding="utf-8"))
    files = data.get("files", {})
    if not isinstance(files, dict):
        return {}
    return {str(key): dict(value) for key, value in files.items()}


def readable_gzip(path: Path) -> bool:
    """Whether the file is a gzip stream that decompresses to its end.

    Read to the end, not just the header: gzip's CRC and length live in the
    last eight bytes, so a dump truncated by a dropped connection decompresses
    happily for as far as it goes and only fails here. A backup that cannot be
    imported is not a backup, and this is the cheapest place to find out.
    """
    try:
        with gzip.open(path, "rb") as handle:
            for _ in iter(lambda: handle.read(1024 * 1024), b""):
                pass
    except (OSError, EOFError):
        return False
    return True


def _path_is_absent(error: DeployError) -> bool:
    text = str(error).lower()
    return "no such file" in text or "not a directory" in text


def _replaceable_failure(directory: Path) -> bool:
    """A same-minute directory this run may replace: no manifest, no readable dump."""
    if (directory / MANIFEST).is_file():
        return False
    dump = directory / DUMP
    return not (dump.is_file() and readable_gzip(dump))


def _discard_incomplete(directory: Path) -> None:
    """A failed run must not leave a dump or a manifest that looks finished."""
    (directory / DUMP).unlink(missing_ok=True)
    (directory / MANIFEST).unlink(missing_ok=True)


def _rewind(handle: IO[bytes] | None) -> None:
    if handle is None:
        return
    handle.seek(0)
    handle.truncate()


def stream(
    target: Target,
    script: str,
    *,
    stdin: IO[bytes] | None = None,
    stdout: IO[bytes] | None = None,
) -> None:
    """Run a script on the target with the given standard streams.

    Public because the deploy's database snapshot streams the same dump the
    same way. A dump (stdout, no stdin) retries a dropped connection and
    rewinds the local file first, so a retry does not append to the bytes
    the closed connection already wrote. Restore stays one shot: replaying
    a write onto a database that may have applied part of the dump is not
    a retry.
    """
    command = target.shell_command(script)
    limit = TRANSFER_ATTEMPTS if stdout is not None and stdin is None else 1
    message = ""
    for attempt in range(limit):
        if attempt:
            _rewind(stdout)
        with tempfile.TemporaryFile() as error:
            completed = subprocess.run(
                command, stdin=stdin, stdout=stdout, stderr=error, check=False
            )
            error.seek(0)
            message = error.read().decode(errors="replace").strip()
        if completed.returncode == 0:
            return
        if not _connection_dropped(message) or attempt + 1 >= limit:
            raise DeployError(f"{script.splitlines()[0]}: {message}")
        _pause_after_drop(attempt + 1)
    raise DeployError(f"{script.splitlines()[0]}: {message}")
