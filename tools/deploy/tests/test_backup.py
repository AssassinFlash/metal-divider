"""Complete-backup failures: a dropped dump, a short tree, and a retry."""

from __future__ import annotations

import gzip
import subprocess
import time
from datetime import UTC, datetime
from pathlib import Path
from typing import IO

import pytest

from md_deploy import backup
from md_deploy.target import DeployError, Target

AT = datetime(2026, 10, 28, 12, 0, tzinfo=UTC)


def _site(root: Path) -> Target:
    return Target(
        "local:test",
        str(root),
        "http://127.0.0.1:9",
        ("php", "wp", f"--path={root}"),
        None,
        None,
    )


def _sleeps(monkeypatch: pytest.MonkeyPatch) -> list[float]:
    slept: list[float] = []
    monkeypatch.setattr(time, "sleep", lambda seconds: slept.append(seconds))
    return slept


def test_a_dropped_dump_is_retried_and_replaces_the_partial_bytes(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The second attempt must not append to the bytes the closed connection left."""
    good = gzip.compress(b"select 1;\n")
    slept = _sleeps(monkeypatch)
    calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal calls
        calls += 1
        stdout = kwargs.get("stdout")
        stderr = kwargs.get("stderr")
        if calls == 1:
            if hasattr(stdout, "write"):
                stdout.write(b"partial" + good + b"tail-that-must-not-survive")
            if hasattr(stderr, "write"):
                stderr.write(b"Connection closed by 1.2.3.4 port 22\n")
            return subprocess.CompletedProcess(command, 255)
        if hasattr(stdout, "write"):
            stdout.write(good)
        return subprocess.CompletedProcess(command, 0)

    monkeypatch.setattr(subprocess, "run", fake_run)
    path = tmp_path / "db.sql.gz"

    backup.dump_to(_site(tmp_path / "site"), path)

    assert calls == 2
    assert slept[0] >= 1
    assert path.read_bytes() == good


def test_a_dump_that_keeps_dropping_leaves_no_readable_dump(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Four closed connections, each after a whole gzip, must not look restorable."""
    good = gzip.compress(b"select 1;\n")
    slept = _sleeps(monkeypatch)
    calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal calls
        calls += 1
        stdout = kwargs.get("stdout")
        stderr = kwargs.get("stderr")
        if hasattr(stdout, "write"):
            stdout.write(good)
        if hasattr(stderr, "write"):
            stderr.write(b"Connection closed by 1.2.3.4 port 22\n")
        return subprocess.CompletedProcess(command, 255)

    monkeypatch.setattr(subprocess, "run", fake_run)
    path = tmp_path / "db.sql.gz"

    with pytest.raises(DeployError, match="Connection closed"):
        backup.dump_to(_site(tmp_path / "site"), path)

    assert calls == 4
    assert len(slept) == 3
    assert slept[1] > slept[0]
    assert not path.exists()


def test_a_dump_error_is_not_retried_and_leaves_no_readable_dump(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal calls
        calls += 1
        stdout = kwargs.get("stdout")
        stderr = kwargs.get("stderr")
        if hasattr(stdout, "write"):
            stdout.write(gzip.compress(b"select 1;\n"))
        if hasattr(stderr, "write"):
            stderr.write(b"mysqldump: Got error: 1045: Access denied\n")
        return subprocess.CompletedProcess(command, 2)

    monkeypatch.setattr(subprocess, "run", fake_run)
    path = tmp_path / "db.sql.gz"

    with pytest.raises(DeployError, match="1045"):
        backup.dump_to(_site(tmp_path / "site"), path)

    assert calls == 1
    assert not path.exists()


class _Site(Target):
    """copy_out writes a short tree; shell reports whatever the test set."""

    remote_files: str
    mu_error: str | None = None

    def copy_out(
        self, relative: str, destination: Path, *, link_dest: Path | None = None
    ) -> None:
        del link_dest
        if relative == "wp-content/mu-plugins" and self.mu_error:
            raise DeployError(self.mu_error)
        destination.mkdir(parents=True, exist_ok=True)
        (destination / "kept.php").write_text("ok", encoding="utf-8")

    def copy_file_out(self, relative: str, destination: Path) -> None:
        del relative
        destination.write_text("", encoding="utf-8")

    def shell(self, script: str) -> str:
        if "find . -type f" in script:
            return self.remote_files
        raise AssertionError(script)

    def wp(self, *args: str, check: bool = True) -> str:
        del args, check
        return "{}"


def _arriving_dump(monkeypatch: pytest.MonkeyPatch) -> None:
    def fake_stream(
        target: Target,
        script: str,
        *,
        stdin: IO[bytes] | None = None,
        stdout: IO[bytes] | None = None,
    ) -> None:
        del target, script, stdin
        if stdout is not None:
            stdout.write(gzip.compress(b"select 1;\n"))

    monkeypatch.setattr(backup, "stream", fake_stream)


def test_a_short_tree_names_the_missing_total_and_leaves_no_backup(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Four files absent, three examples: the count is what the message used to hide."""
    _arriving_dump(monkeypatch)
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(
        site,
        "remote_files",
        "./kept.php\n./a.css\n./b.css\n./c.css\n./d.css\n",
    )

    with pytest.raises(DeployError, match=r"missing 4, extra 0"):
        backup.create(site, tmp_path, now=AT)

    directory = backup.destination(tmp_path, now=AT)
    assert not (directory / backup.MANIFEST).exists()
    dump = directory / backup.DUMP
    assert not dump.exists() or not backup.readable_gzip(dump)
    assert backup.latest_complete(tmp_path) is None


def test_a_failed_backup_can_be_taken_again_in_the_same_minute(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _arriving_dump(monkeypatch)
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(site, "remote_files", "./kept.php\n./missing.css\n")

    with pytest.raises(DeployError, match=r"missing 1, extra 0"):
        backup.create(site, tmp_path, now=AT)

    object.__setattr__(site, "remote_files", "./kept.php\n")
    taken = backup.create(site, tmp_path, now=AT)

    assert taken.directory == backup.destination(tmp_path, now=AT)
    assert (taken.directory / backup.MANIFEST).is_file()
    assert backup.readable_gzip(taken.directory / backup.DUMP)
    assert backup.latest_complete(tmp_path) == taken.directory


def test_a_finished_backup_is_not_replaced_in_the_same_minute(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _arriving_dump(monkeypatch)
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(site, "remote_files", "./kept.php\n")

    taken = backup.create(site, tmp_path, now=AT)

    with pytest.raises(DeployError, match="complete backup"):
        backup.create(site, tmp_path, now=AT)

    assert (taken.directory / backup.MANIFEST).is_file()
    assert backup.readable_gzip(taken.directory / backup.DUMP)


def test_a_readable_dump_without_a_manifest_is_not_replaced(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _arriving_dump(monkeypatch)
    directory = backup.destination(tmp_path, now=AT)
    directory.mkdir(parents=True)
    (directory / backup.DUMP).write_bytes(gzip.compress(b"select 1;\n"))
    (directory / "kept.php").write_text("residue", encoding="utf-8")
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(site, "remote_files", "./kept.php\n")

    with pytest.raises(DeployError, match="readable database dump"):
        backup.create(site, tmp_path, now=AT)

    assert backup.readable_gzip(directory / backup.DUMP)
    assert (directory / "kept.php").is_file()


def test_a_dropped_mu_plugins_copy_does_not_finish_the_backup(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A closed connection is not a site without mu-plugins."""
    _arriving_dump(monkeypatch)
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(site, "remote_files", "./kept.php\n")
    object.__setattr__(site, "mu_error", "Connection closed by 1.2.3.4 port 22")

    with pytest.raises(DeployError, match="Connection closed"):
        backup.create(site, tmp_path, now=AT)

    directory = backup.destination(tmp_path, now=AT)
    assert not (directory / backup.MANIFEST).exists()
    dump = directory / backup.DUMP
    assert not dump.exists() or not backup.readable_gzip(dump)
    assert backup.latest_complete(tmp_path) is None


def test_a_site_without_mu_plugins_still_finishes(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _arriving_dump(monkeypatch)
    site = _Site("local:test", str(tmp_path / "site"), "http://x", (), None, None)
    object.__setattr__(site, "remote_files", "./kept.php\n")
    object.__setattr__(
        site, "mu_error", "wp-content/mu-plugins is not a directory on local:test"
    )

    taken = backup.create(site, tmp_path, now=AT)

    assert (taken.directory / backup.MANIFEST).is_file()
    assert not (taken.directory / "wp-content/mu-plugins").exists()
