"""The media plane: the finalized uploads, pushed in chunks (spec 20 §3.2 #7).

A separate pipeline on purpose — a code deploy never touches
`wp-content/uploads` (AGENTS.md §5's layering), and the media are data: the
compressed final images the local pipeline produces (ticket #29), written to
the server as finished files, the server doing no runtime conversion of its
own (AGENTS.md §2).

The host drops a single long transfer (the blueprint measured 2.6 GB cut at
~8 minutes on the same platform), so the push is chunked by the uploads
year/month tree — one independent connection per chunk, retried — which is
also what lets an interrupted push resume where it stopped. rsync per chunk
(not a tar stream) because rsync resumes a chunk file by file.

A data write needs a complete backup first (AGENTS.md §5): the push refuses
to start without one newer than `plan.FULL_BACKUP_DAYS` days.
"""

from __future__ import annotations

import re
import shlex
from datetime import UTC, datetime
from pathlib import Path

from md_deploy import backup as backup_module
from md_deploy import plan
from md_deploy.target import DeployError, Target

# The chunk rule: one year/month directory of the uploads tree. WordPress
# files every upload under uploads/<YYYY>/<MM>/, so the months are the
# natural, bounded chunks.
YEAR = re.compile(r"\d{4}")

# What the media plane never touches, with the reason each is someone else's.
# WindPress's data (the volumes present installs) lives under uploads/, and
# the attachment rows are a database write the import pipeline owns.
EXCLUDED_UPLOAD_ENTRIES = ("windpress",)


def chunks(source: Path) -> tuple[list[Path], list[str]]:
    """The push's chunks: every year/month directory, plus loose root files.

    Returns (chunks, ignored): the ignored names are reported, never silently
    skipped — a new top-level directory under uploads that is neither a year
    nor on the exclusion list must be a decision, not an accident.
    """
    if not source.is_dir():
        raise DeployError(f"{source} is not an uploads directory")
    months: list[Path] = []
    files: list[Path] = []
    ignored: list[str] = []
    for entry in sorted(source.iterdir()):
        if entry.is_file():
            files.append(entry)
        elif entry.is_dir() and YEAR.fullmatch(entry.name):
            for month in sorted(entry.iterdir()):
                if month.is_dir():
                    months.append(month)
                elif month.is_file():
                    # A loose file directly under a year (a pre-3.5 WordPress
                    # layout) is a chunk of its own.
                    files.append(month)
        else:
            # windpress's data (present installs it) and anything unnamed:
            # not this pipeline's to touch, and named in the report.
            ignored.append(entry.name)
    return months + files, ignored


def _count_on_target(target: Target, relatives: list[str]) -> int:
    """How many regular files the pushed set holds on the target."""
    if not relatives:
        return 0
    listing = " ".join(shlex.quote(target.path_of(relative)) for relative in relatives)
    found = target.shell(f"find {listing} -type f | wc -l").strip()
    return int(found) if found.isdigit() else -1


def push(
    target: Target,
    source: Path,
    *,
    home: Path | None = None,
    now: datetime | None = None,
) -> list[str]:
    """Push the finalized uploads to the target, one connection per month.

    Every chunk mirrors its directory (`--delete`): the local tree is the
    finished set, and a file the server holds that the set does not is a file
    that should not be served. The readback counts the pushed set itself, on
    both sides — a chunk rule that missed a kind of file makes a silently
    smaller push, and the count is the cheapest proof that it did not.
    """
    root = home or backup_module.DATA_HOME
    latest = backup_module.latest_complete(root)
    if latest is None:
        raise DeployError(
            "no complete backup exists: a media push is a data write — run "
            "`just deploy backup` first (AGENTS.md §5)"
        )
    taken = datetime.strptime(latest.name, "backup-%Y%m%d-%H%M").replace(tzinfo=UTC)
    age = (now or datetime.now(UTC)) - taken
    if age.days >= plan.FULL_BACKUP_DAYS:
        raise DeployError(
            f"the newest complete backup ({latest.name}) is {age.days} days old: "
            "run `just deploy backup` before this data write"
        )
    pieces, ignored = chunks(source)
    actions = [
        f"backup gate: {latest.name} is {age.days} days old (fresh enough)",
        f"{len(pieces)} chunk(s) under {source}"
        + (
            f", ignoring {', '.join(ignored)} (not this pipeline's to touch)"
            if ignored
            else ""
        ),
    ]
    pushed: list[str] = []
    local_count = 0
    for piece in pieces:
        relative = f"wp-content/uploads/{piece.relative_to(source)}"
        print(f"media: {relative}", flush=True)
        if piece.is_dir():
            target.copy_in(piece, relative)
            local_count += sum(
                1 for _ in piece.rglob("*") if _.is_file() and not _.is_symlink()
            )
        else:
            target.copy_file_in(piece, relative)
            local_count += 1
        pushed.append(relative)
        actions.append(f"pushed {relative}")
    remote_count = _count_on_target(target, pushed)
    actions.append(
        f"readback: {remote_count} files of the pushed set on the target, "
        f"{local_count} local"
    )
    if remote_count != local_count:
        raise DeployError(
            f"the push's readback is wrong: {remote_count} files of the pushed "
            f"set on the target, {local_count} in the source"
        )
    return actions
