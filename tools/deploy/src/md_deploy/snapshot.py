"""The database snapshot every deploy takes first (docs/specs/20-staging-deploy.md §3.3).

A code deploy cannot touch `wp-content/uploads`, and the atomic switch keeps
the code it replaces (`atomic.py`; `rollback-deploy` puts it back). What a
deploy can still change is the database: plugin activation, options, the
present step's template rows. So the backup a deploy needs is the database
alone, and it needs it before anything is uploaded.

The dump streams to this Mac — nothing is written on the host (AGENTS.md §5:
备份只存本机) — into `~/metal-divider-local/snapshots/`, where the newest 20
stay (spec 20 §3.3 step 1). No table is left out: this site has no retired
giant table the blueprint's snapshot had to skip.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path

from md_deploy import backup
from md_deploy.target import DeployError, Target

DIRECTORY = "snapshots"

# The newest 20 stay; older ones are deleted after a new one is verified
# (spec 20 §3.3: 留 20 份, the blueprint's own retention, whose measured
# rationale this keeps).
KEEP = 20

NAME = re.compile(r"db-\d{8}T\d{6}Z\.sql\.gz")


@dataclass(frozen=True)
class Snapshot:
    """A snapshot written and verified on this Mac."""

    name: str
    path: Path
    size: int
    sha256: str


def directory(home: Path) -> Path:
    """`~/metal-divider-local/snapshots/`, beside the full backups."""
    return home / DIRECTORY


def name_at(now: datetime | None = None) -> str:
    """The snapshot's file name for a moment, in UTC."""
    return (now or datetime.now(UTC)).strftime("db-%Y%m%dT%H%M%SZ.sql.gz")


def existing(home: Path, name: str) -> Path:
    """The path of a snapshot by name, refusing anything that is not one."""
    if not NAME.fullmatch(name):
        raise DeployError(
            f"{name!r} is not a snapshot name (db-YYYYMMDDTHHMMSSZ.sql.gz)"
        )
    path = directory(home) / name
    if not path.is_file():
        raise DeployError(f"{path} does not exist")
    return path


def take(
    target: Target,
    home: Path,
    *,
    now: datetime | None = None,
    protect: frozenset[str] = frozenset(),
) -> Snapshot:
    """Stream the database here, verify it, keep the newest KEEP.

    The dump is written to a hidden partial file and renamed only after it
    reads to the end as gzip, so the directory never holds a snapshot that
    would not restore. A failed dump leaves nothing behind. Pruning never
    removes the snapshot just taken (even when a clock skew put a newer name
    on an older file) nor any name in `protect` (the one a restore is about
    to read).
    """
    folder = directory(home)
    folder.mkdir(parents=True, exist_ok=True)
    name = name_at(now)
    final = folder / name
    if final.exists():
        raise DeployError(f"{final} already exists; take the snapshot again")
    partial = folder / f".{name}.partial"
    try:
        backup.dump_to(target, partial)
        partial.rename(final)
    finally:
        partial.unlink(missing_ok=True)
    others = [old for old in available(home) if old != name and old not in protect]
    for old in others[KEEP - 1 - len(protect) :]:
        (folder / old).unlink(missing_ok=True)
    return Snapshot(
        name=name, path=final, size=final.stat().st_size, sha256=backup.sha256(final)
    )


def restore(
    target: Target, home: Path, name: str, *, now: datetime | None = None
) -> list[str]:
    """Put a snapshot back, after snapshotting the database it replaces.

    The import is a data write like any other (AGENTS.md §5): the state being
    overwritten is kept first, so a restore of the wrong snapshot is itself
    one command away from undone. Only the tables in the snapshot are
    replaced; a table created after it (a new plugin's) keeps its rows, and
    the code is put back separately with `rollback-deploy`.
    """
    path = existing(home, name)
    if not backup.readable_gzip(path):
        raise DeployError(f"{path} is not a readable gzip")
    before = take(target, home, now=now, protect=frozenset({path.name}))
    backup.restore_database(target, path)
    target.wp("cache", "flush")
    return [
        f"kept the replaced database as {before.name}",
        f"restored {path}",
        "wp cache flush",
    ]


def available(home: Path) -> list[str]:
    """The snapshot names on this Mac, newest first."""
    folder = directory(home)
    if not folder.is_dir():
        return []
    return sorted(
        (path.name for path in folder.iterdir() if NAME.fullmatch(path.name)),
        reverse=True,
    )
