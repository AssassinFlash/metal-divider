"""The media plane's decisions: the chunk rule, the backup gate, the readback."""

from __future__ import annotations

from datetime import UTC, datetime, timedelta
from pathlib import Path

import pytest

from md_deploy import backup, media, plan
from md_deploy.target import DeployError, Target

NOW = datetime(2026, 10, 24, 12, 0, 0, tzinfo=UTC)


def _uploads(tmp_path: Path) -> Path:
    root = tmp_path / "uploads"
    for month in ("2024/03", "2024/11", "2025/01"):
        (root / month).mkdir(parents=True)
        (root / month / "image.webp").write_bytes(b"final")
    (root / "2024" / "loose.png").write_bytes(b"old layout file")
    (root / "root-level.jpg").write_bytes(b"loose root file")
    (root / "windpress" / "data").mkdir(parents=True)
    (root / "windpress" / "data" / "main.css").write_text("/* a volume */")
    (root / "unexpected").mkdir()
    return root


def test_the_chunks_are_the_year_months_plus_loose_files(tmp_path: Path) -> None:
    """One connection per month; loose files travel alone; nothing else is touched."""
    root = _uploads(tmp_path)

    chunks, ignored = media.chunks(root)

    assert sorted(str(piece.relative_to(root)) for piece in chunks) == [
        "2024/03",
        "2024/11",
        "2024/loose.png",
        "2025/01",
        "root-level.jpg",
    ]
    assert sorted(ignored) == ["unexpected", "windpress"], (
        "an unnamed directory under uploads must be a decision, not an accident"
    )


def test_the_chunks_refuse_something_that_is_not_uploads(tmp_path: Path) -> None:
    with pytest.raises(DeployError, match="not an uploads directory"):
        media.chunks(tmp_path / "nothing-here")


def _with_backup(tmp_path: Path, *, days_old: int) -> Path:
    home = tmp_path / "home"
    taken = NOW - timedelta(days=days_old)
    directory = backup.destination(home, now=taken)
    directory.mkdir(parents=True)
    (directory / backup.MANIFEST).write_text("{}\n", encoding="utf-8")
    return home


class _Pushed(Target):
    """A target that records what the push tried to copy and answers counts."""

    copied: list[str]
    count_answer: str

    def copy_in(self, source: Path, relative: str) -> None:
        self.copied.append(f"dir:{relative}")

    def copy_file_in(self, source: Path, relative: str) -> None:
        self.copied.append(f"file:{relative}")

    def shell(self, script: str) -> str:
        if "wc -l" in script:
            return self.count_answer
        return ""


def _target(tmp_path: Path, *, count: str) -> _Pushed:
    target = _Pushed("local:test", str(tmp_path), "http://x", (), None, None)
    object.__setattr__(target, "copied", [])
    object.__setattr__(target, "count_answer", count)
    return target


def test_a_push_without_a_backup_refuses(tmp_path: Path) -> None:
    target = _target(tmp_path, count="5\n")

    with pytest.raises(DeployError, match="no complete backup exists"):
        media.push(target, _uploads(tmp_path), home=tmp_path / "empty-home", now=NOW)
    assert target.copied == [], "the gate runs before anything is pushed"


def test_an_old_backup_refuses_the_push(tmp_path: Path) -> None:
    home = _with_backup(tmp_path, days_old=plan.FULL_BACKUP_DAYS)

    with pytest.raises(DeployError, match="days old"):
        media.push(
            _target(tmp_path, count="5\n"), _uploads(tmp_path), home=home, now=NOW
        )


def test_a_push_with_a_fresh_backup_pushes_every_chunk_and_reads_back(
    tmp_path: Path,
) -> None:
    home = _with_backup(tmp_path, days_old=0)
    target = _target(tmp_path, count="5\n")

    actions = media.push(target, _uploads(tmp_path), home=home, now=NOW)

    assert sorted(target.copied) == [
        "dir:uploads/2024/03",
        "dir:uploads/2024/11",
        "dir:uploads/2025/01",
        "file:uploads/2024/loose.png",
        "file:uploads/root-level.jpg",
    ]
    assert any("readback: 5" in action for action in actions)


def test_the_readback_of_a_wrong_count_fails(tmp_path: Path) -> None:
    home = _with_backup(tmp_path, days_old=0)

    with pytest.raises(DeployError, match="readback is wrong"):
        media.push(
            _target(tmp_path, count="4\n"), _uploads(tmp_path), home=home, now=NOW
        )
