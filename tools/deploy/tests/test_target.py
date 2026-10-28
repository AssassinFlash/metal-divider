"""The target seam: what the three names resolve to, and the path guards."""

from __future__ import annotations

import os
import shlex
import subprocess
import time
from pathlib import Path

import pytest

from md_deploy import target
from md_deploy.target import PLUGINS, UPLOADS, DeployError, Target


def test_a_relative_path_outside_the_root_is_refused(tmp_path: Path) -> None:
    """`Path(root) / "/etc/passwd"` is `/etc/passwd`: the guard is not optional."""
    site = Target("fixture", str(tmp_path), "http://x", (), None, None)

    with pytest.raises(DeployError, match="not a path inside"):
        site.path_of("/etc/passwd")
    with pytest.raises(DeployError, match="not a path inside"):
        site.path_of("wp-content/../../etc")


def test_named_targets_accept_exactly_the_three_names_and_local_instances() -> None:
    """The three configured targets (spec 20 §3.1) plus any local instance."""
    with pytest.raises(DeployError, match="dev, local:<instance>, staging or prod"):
        target.named_target("production")

    staging = target.remote_target("staging")
    prod = target.remote_target("prod")
    assert staging.staging is True
    assert prod.staging is False
    assert staging.base_url == "https://staging.metal-divider.com"
    assert prod.base_url == "https://metal-divider.com"
    # The two sites are separate WordPress roots on one host account.
    assert staging.root != prod.root
    assert staging.rsync_remote is not None and prod.rsync_remote is not None
    # The alias carries no credential; it is a name in ~/.ssh/config (AGENTS §4).
    assert target.HOST in staging.rsync_remote


def test_the_backup_root_is_per_site_on_the_shared_account() -> None:
    """Two sites share one host account: one shared batch store would mix batches."""

    class _Asking(Target):
        answers: list[str]

        def shell(self, script: str) -> str:
            self.answers.append(script)
            # The real thing printf's "$HOME" plus the script's own suffix; the
            # stub replays exactly that, so the split — not the printf — is
            # what is under test.
            for namespace in (target.NAMESPACE, target.LEGACY_ROOT):
                if f"/{namespace}/" in script:
                    site = script.split(f"/{namespace}/", 1)[1].split('"', 1)[0]
                    return f"/home/u388076051/{namespace}/{site}"
            return ""

    staging = _Asking(
        "staging",
        "/home/u388076051/domains/staging.metal-divider.com/public_html",
        "https://staging.metal-divider.com",
        (),
        ("ssh", "host"),
        "host:root",
        staging=True,
    )
    prod = _Asking(
        "prod",
        "/home/u388076051/domains/metal-divider.com/public_html",
        "https://metal-divider.com",
        (),
        ("ssh", "host"),
        "host:root",
    )
    object.__setattr__(staging, "answers", [])
    object.__setattr__(prod, "answers", [])

    assert (
        target.backup_root(staging)
        == "/home/u388076051/deploy-prev-metal-divider/staging.metal-divider.com"
    )
    assert (
        target.backup_root(prod)
        == "/home/u388076051/deploy-prev-metal-divider/metal-divider.com"
    )


def test_the_batch_namespace_is_outside_de_linkers_prune() -> None:
    """de-linker's archive deletes every directory under its own flat root.

    Measured on the shared Hostinger account 2026-10-26: `~/deploy-prev/latest`
    carried a de-linker batch id while this project's pointer sat at
    `~/deploy-prev/metal-divider.com/latest`, and the pending batch
    `20261026T042025478901Z-1be1823` was gone from disk afterwards
    (linker_cutover/atomic.py: `for old in <root>/*; do ... rm -rf "$old"`).
    New batches must therefore not live under that root at all.
    """
    assert target.NAMESPACE != target.LEGACY_ROOT
    assert not target.NAMESPACE.startswith(target.LEGACY_ROOT + "/")
    # Siblings under $HOME, so neither is inside the other.
    assert "/" not in target.NAMESPACE
    assert "/" not in target.LEGACY_ROOT


def test_the_legacy_root_still_names_the_old_place() -> None:
    """Reads must keep working there: archived recovery points predate the split."""

    class _Asking(Target):
        def shell(self, script: str) -> str:
            site = script.split("/deploy-prev/", 1)[1].split('"', 1)[0]
            return f"/home/u388076051/deploy-prev/{site}"

    prod = _Asking(
        "prod",
        "/home/u388076051/domains/metal-divider.com/public_html",
        "https://metal-divider.com",
        (),
        ("ssh", "host"),
        "host:root",
    )

    assert (
        target.legacy_backup_root(prod)
        == "/home/u388076051/deploy-prev/metal-divider.com"
    )


def test_a_local_target_keeps_one_store_for_both_roots(tmp_path: Path) -> None:
    """No other project prunes a local instance's tree, so nothing needs moving."""
    local = Target(
        "local:dev", str(tmp_path / "wordpress"), "http://127.0.0.1:1", (), None, None
    )

    assert target.backup_root(local) == str(tmp_path / "deploy-prev")
    assert target.legacy_backup_root(local) == target.backup_root(local)


def test_local_entries_cover_every_kind_of_file(tmp_path: Path) -> None:
    """The four chunk kinds: root files, files inside first-level dirs, depth-2
    directories, and a first-level directory without subdirectories as a whole."""
    source = tmp_path / "tree"
    (source / "vendor" / "composer").mkdir(parents=True)
    (source / "vendor" / "autoload.php").write_text("a")
    (source / "vendor" / "composer" / "x.php").write_text("b")
    (source / "onefile").mkdir()
    (source / "onefile" / "only.php").write_text("c")
    (source / "root.php").write_text("d")

    entries = {str(piece.relative_to(source)) for piece in target.local_entries(source)}

    assert entries == {
        "root.php",
        "vendor/autoload.php",
        "vendor/composer",
        "onefile",
    }


def test_the_remote_chunk_listing_walks_the_same_four_kinds() -> None:
    listing = "\n".join(
        [
            "F ./root.php",
            "F ./vendor/autoload.php",
            "F ./vendor/composer/x.php",
            "D ./vendor/composer",
            "d ./vendor",
            "d ./onefile",
            "d ./windpress",
        ]
    )

    chunks = target._remote_chunks(listing)

    assert chunks == [
        ("root.php", False),
        ("vendor/autoload.php", False),
        ("vendor/composer/x.php", False),
        ("vendor/composer", True),
        ("onefile", True),
        ("windpress", True),
    ]


def _find_that_fails_once(tmp_path: Path) -> dict[str, str]:
    """A `find` whose first call exits 1 and whose later calls exit 0.

    The real `sort` stays on PATH, so a pipeline that ignores an early find
    failure still exits 0 — the masking this guards against.
    """
    bindir = tmp_path / "bin"
    bindir.mkdir(parents=True)
    count = tmp_path / "find-calls"
    (bindir / "find").write_text(
        "#!/bin/sh\n"
        f"n=$(cat {shlex.quote(str(count))} 2>/dev/null || echo 0)\n"
        f"echo $((n + 1)) > {shlex.quote(str(count))}\n"
        'test "$n" = 0 && exit 1\n'
        "exit 0\n",
        encoding="utf-8",
    )
    (bindir / "find").chmod(0o755)
    env = os.environ.copy()
    env["PATH"] = f"{bindir}{os.pathsep}{env.get('PATH', '')}"
    return env


def test_a_failed_find_is_not_hidden_by_sort_and_does_not_start_a_chunk_walk(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Four finds piped to sort: the first find dying must fail the listing.

    Without `pipefail`, and without the group failing on that find, `sort`
    exits 0 and copy_out treats the missing names as a complete tree. The
    directory exists, so a failing `cd` cannot fake this result. Uploads stay
    on the chunk walk, so this does not depend on the small-tree shortcut.
    """
    root = tmp_path / "site"
    (root / UPLOADS).mkdir(parents=True)
    pulls: list[list[str]] = []
    real_run = subprocess.run

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        if command and command[0] == "rsync":
            pulls.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            return real_run(
                ["bash", "-c", _listing_script_from(command)],
                capture_output=True,
                text=True,
                env=_find_that_fails_once(tmp_path / "walk"),
                check=False,
            )
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)
    site = Target(
        "prod",
        str(root),
        "https://metal-divider.com",
        (),
        ("ssh", "host", "bash -c"),
        f"hostinger-metal-divider:{root}",
    )

    with pytest.raises(DeployError, match="listing"):
        site.copy_out(UPLOADS, tmp_path / "uploads")

    assert pulls == []


def _listing_script_from(command: list[str]) -> str:
    payload = command[-1]
    if payload.startswith("bash -c "):
        return shlex.split(payload)[2]
    return payload


def _remote_site(root: Path) -> Target:
    return Target(
        "prod",
        str(root),
        "https://metal-divider.com",
        (),
        ("ssh", "-o", "BatchMode=yes", target.HOST, "bash -c"),
        f"{target.HOST}:{root}",
    )


def test_a_small_plugins_tree_is_one_recursive_pull(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A plugins tree under the small-tree cap is one rsync, not one SSH per directory.

    `rapls-ai-chatbot/assets` is inside that single recursive pull. The earlier
    backup is still a `--link-dest`, or unchanged plugin files would be copied
    again. A second ssh would be the chunk listing this path must not start.
    """
    root = tmp_path / "site"
    (root / PLUGINS / "rapls-ai-chatbot" / "assets" / "css").mkdir(parents=True)
    earlier = tmp_path / "earlier"
    earlier.mkdir()
    rsyncs: list[list[str]] = []
    ssh_calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal ssh_calls
        if command and command[0] == "rsync":
            rsyncs.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            ssh_calls += 1
            assert ssh_calls == 1
            return subprocess.CompletedProcess(
                command, 0, stdout="1048576\tplugins\n", stderr=""
            )
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)
    destination = tmp_path / "plugins"

    _remote_site(root).copy_out(PLUGINS, destination, link_dest=earlier)

    assert ssh_calls == 1
    assert len(rsyncs) == 1
    assert rsyncs[0][-2] == f"{target.HOST}:{root}/{PLUGINS}/"
    assert rsyncs[0][-1] == f"{destination}/"
    assert f"--link-dest={earlier.resolve()}" in rsyncs[0]


def test_uploads_stay_chunked_even_when_small(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Uploads keep the chunk walk. A small measured size must not collapse them."""
    root = tmp_path / "site"
    (root / UPLOADS).mkdir(parents=True)
    listing = "\n".join(["F ./loose.png", "D ./2024/10", "d ./2024", ""])
    rsyncs: list[list[str]] = []

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        if command and command[0] == "rsync":
            rsyncs.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            script = " ".join(command)
            if "du -sb" in script:
                return subprocess.CompletedProcess(
                    command, 0, stdout="100\tuploads\n", stderr=""
                )
            return subprocess.CompletedProcess(command, 0, stdout=listing, stderr="")
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    _remote_site(root).copy_out(UPLOADS, tmp_path / "uploads")

    sources = [command[-2] for command in rsyncs]
    assert sources
    assert f"{target.HOST}:{root}/{UPLOADS}/" not in sources
    assert f"{target.HOST}:{root}/{UPLOADS}/2024/10/" in sources
    assert f"{target.HOST}:{root}/{UPLOADS}/loose.png" in sources


def test_plugins_over_the_small_tree_cap_still_pull_assets_as_a_directory(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Above the cap, `rapls-ai-chatbot/assets` is one recursive directory chunk.

    A depth-3 css file is not its own chunk: the directory rsync carries it.
    The whole plugins root is not one leg — that is the transfer the host drops.
    """
    root = tmp_path / "site"
    (root / PLUGINS).mkdir(parents=True)
    listing = "\n".join(
        [
            "F ./rapls-ai-chatbot/rapls-ai-chatbot.php",
            "D ./rapls-ai-chatbot/assets",
            "d ./rapls-ai-chatbot",
            "",
        ]
    )
    rsyncs: list[list[str]] = []
    ssh_calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal ssh_calls
        if command and command[0] == "rsync":
            rsyncs.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            ssh_calls += 1
            if ssh_calls == 1:
                return subprocess.CompletedProcess(
                    command,
                    0,
                    stdout=f"{target.SMALL_UPLOAD_BYTES + 1}\tplugins\n",
                    stderr="",
                )
            return subprocess.CompletedProcess(command, 0, stdout=listing, stderr="")
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    _remote_site(root).copy_out(PLUGINS, tmp_path / "plugins")

    sources = [command[-2] for command in rsyncs]
    assert f"{target.HOST}:{root}/{PLUGINS}/rapls-ai-chatbot/assets/" in sources
    assert f"{target.HOST}:{root}/{PLUGINS}/" not in sources
    assert all("css" not in source for source in sources)


def _record_sleeps(monkeypatch: pytest.MonkeyPatch) -> list[float]:
    sleeps: list[float] = []
    monkeypatch.setattr(time, "sleep", lambda seconds: sleeps.append(seconds))
    return sleeps


def test_a_dropped_plugins_pull_backs_off_before_the_successful_retry(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Connection closed is not four immediate handshakes. The second wait is longer."""
    root = tmp_path / "site"
    (root / PLUGINS).mkdir(parents=True)
    sleeps = _record_sleeps(monkeypatch)
    rsyncs = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal rsyncs
        if command and command[0] == "ssh":
            return subprocess.CompletedProcess(
                command, 0, stdout="100\tplugins\n", stderr=""
            )
        if command and command[0] == "rsync":
            rsyncs += 1
            if rsyncs < 3:
                return subprocess.CompletedProcess(
                    command,
                    255,
                    stdout="",
                    stderr="Connection closed by 1.2.3.4 port 22\n",
                )
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    _remote_site(root).copy_out(PLUGINS, tmp_path / "plugins")

    assert rsyncs == 3
    assert sleeps[0] >= 1
    assert sleeps[1] > sleeps[0]


def test_four_dropped_pulls_stop_and_do_not_try_a_fifth(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    root = tmp_path / "site"
    (root / PLUGINS).mkdir(parents=True)
    sleeps = _record_sleeps(monkeypatch)
    rsyncs = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal rsyncs
        if command and command[0] == "ssh":
            return subprocess.CompletedProcess(
                command, 0, stdout="100\tplugins\n", stderr=""
            )
        if command and command[0] == "rsync":
            rsyncs += 1
            return subprocess.CompletedProcess(
                command, 255, stdout="", stderr="Connection reset by peer\n"
            )
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    with pytest.raises(DeployError, match="4 attempts"):
        _remote_site(root).copy_out(PLUGINS, tmp_path / "plugins")

    assert rsyncs == target.TRANSFER_ATTEMPTS
    assert len(sleeps) == target.TRANSFER_ATTEMPTS - 1
    assert sleeps[1] > sleeps[0]


def test_a_dropped_uploads_listing_backs_off_and_then_copies(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    root = tmp_path / "site"
    (root / UPLOADS).mkdir(parents=True)
    sleeps = _record_sleeps(monkeypatch)
    listing = "F ./loose.png\n"
    ssh_calls = 0
    rsyncs: list[list[str]] = []

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal ssh_calls
        if command and command[0] == "rsync":
            rsyncs.append(command)
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            ssh_calls += 1
            if ssh_calls == 1:
                return subprocess.CompletedProcess(
                    command,
                    255,
                    stdout="",
                    stderr="Connection closed by 1.2.3.4 port 22\n",
                )
            return subprocess.CompletedProcess(command, 0, stdout=listing, stderr="")
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    _remote_site(root).copy_out(UPLOADS, tmp_path / "uploads")

    assert ssh_calls == 2
    assert sleeps[0] >= 1
    assert rsyncs[0][-2] == f"{target.HOST}:{root}/{UPLOADS}/loose.png"


def test_a_listing_error_that_is_not_a_dropped_connection_is_not_retried(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    root = tmp_path / "site"
    (root / UPLOADS).mkdir(parents=True)
    sleeps = _record_sleeps(monkeypatch)
    ssh_calls = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal ssh_calls
        if command and command[0] == "ssh":
            ssh_calls += 1
            return subprocess.CompletedProcess(
                command, 1, stdout="", stderr="find: permission denied\n"
            )
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    with pytest.raises(DeployError, match="listing"):
        _remote_site(root).copy_out(UPLOADS, tmp_path / "uploads")

    assert ssh_calls == 1
    assert sleeps == []


def test_a_dropped_plugins_size_probe_backs_off_before_the_pull(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The size question is an SSH too. A closed connection there must wait, then pull."""
    root = tmp_path / "site"
    (root / PLUGINS).mkdir(parents=True)
    sleeps = _record_sleeps(monkeypatch)
    ssh_calls = 0
    rsyncs = 0

    def fake_run(
        command: list[str], **kwargs: object
    ) -> subprocess.CompletedProcess[str]:
        nonlocal ssh_calls, rsyncs
        if command and command[0] == "rsync":
            rsyncs += 1
            return subprocess.CompletedProcess(command, 0, stdout="", stderr="")
        if command and command[0] == "ssh":
            ssh_calls += 1
            if ssh_calls == 1:
                return subprocess.CompletedProcess(
                    command,
                    255,
                    stdout="",
                    stderr="Connection reset by peer\n",
                )
            return subprocess.CompletedProcess(
                command, 0, stdout="100\tplugins\n", stderr=""
            )
        raise AssertionError(command)

    monkeypatch.setattr(subprocess, "run", fake_run)

    _remote_site(root).copy_out(PLUGINS, tmp_path / "plugins")

    assert ssh_calls == 2
    assert sleeps[0] >= 1
    assert rsyncs == 1
