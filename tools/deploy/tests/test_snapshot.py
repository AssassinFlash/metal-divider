"""The deploy's database snapshot: taken here, verified, never left half-written."""

from __future__ import annotations

import gzip
from datetime import UTC, datetime
from pathlib import Path
from typing import IO

import pytest

from md_deploy import backup, snapshot
from md_deploy.target import DeployError, Target

AT = datetime(2026, 10, 24, 2, 0, 0, tzinfo=UTC)


class _Site(Target):
    """A target whose WP-CLI calls are recorded instead of run."""

    calls: list[tuple[str, ...]]

    def wp(self, *args: str, check: bool = True) -> str:  # noqa: D102 - recorded
        del check
        self.calls.append(args)
        return ""


def _site(root: Path) -> _Site:
    site = _Site(
        label="local:test",
        root=str(root),
        base_url="http://127.0.0.1:9999",
        prefix=("php", "wp", f"--path={root}"),
        shell_prefix=None,
        rsync_remote=None,
    )
    object.__setattr__(site, "calls", [])
    return site


def _dump(monkeypatch: pytest.MonkeyPatch, payload: bytes) -> list[str]:
    """Stand in for the transport: the dump arrives as `payload`; scripts are recorded."""
    scripts: list[str] = []

    def fake(
        target: Target,
        script: str,
        *,
        stdin: IO[bytes] | None = None,
        stdout: IO[bytes] | None = None,
    ) -> None:
        del target
        scripts.append(script)
        if stdout is not None:
            stdout.write(payload)
        if stdin is not None:
            stdin.read()

    monkeypatch.setattr(backup, "stream", fake)
    return scripts


def test_a_snapshot_is_written_here_and_verified_and_skips_nothing(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The whole database travels: this site has no table big enough to exclude."""
    scripts = _dump(monkeypatch, gzip.compress(b"select 1;\n"))

    taken = snapshot.take(_site(tmp_path / "site"), tmp_path, now=AT)

    assert taken.name == "db-20261024T020000Z.sql.gz"
    assert taken.path == tmp_path / "snapshots" / taken.name
    assert taken.path.is_file() and taken.size == taken.path.stat().st_size
    assert len(taken.sha256) == 64
    # Nothing is written on the host: the dump's only output is the pipe.
    assert "--ignore-table" not in scripts[0]
    assert "> " not in scripts[0].split("mysqldump", 1)[1].split("| gzip", 1)[0]
    assert list((tmp_path / "snapshots").iterdir()) == [taken.path]


def test_a_dump_that_does_not_read_to_the_end_is_not_a_snapshot(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A truncated gzip decompresses happily until its CRC; the check reads to it."""
    whole = gzip.compress(b"select 1;\n" * 1000)
    _dump(monkeypatch, whole[: len(whole) // 2])

    with pytest.raises(DeployError, match="not a readable gzip"):
        snapshot.take(_site(tmp_path / "site"), tmp_path, now=AT)

    assert (
        not (tmp_path / "snapshots").exists()
        or list((tmp_path / "snapshots").iterdir()) == []
    )


def test_the_newest_twenty_stay_and_the_just_taken_one_is_never_pruned(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Spec 20 §3.3: 留 20 份 — and the newest name is protected from clock skew."""
    _dump(monkeypatch, gzip.compress(b"select 1;\n"))
    site = _site(tmp_path / "site")
    for index in range(22):
        moment = datetime(2026, 10, 1, 0, index, 0, tzinfo=UTC)
        snapshot.take(site, tmp_path, now=moment)

    names = snapshot.available(tmp_path)

    assert len(names) == snapshot.KEEP
    # The 20 newest of the 22 (minutes 2..21), newest first.
    assert names[0] == "db-20261001T002100Z.sql.gz"
    assert names[-1] == "db-20261001T000200Z.sql.gz"
