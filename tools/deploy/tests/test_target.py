"""The target seam: what the three names resolve to, and the path guards."""

from __future__ import annotations

from pathlib import Path

import pytest

from md_deploy import target
from md_deploy.target import DeployError, Target


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
