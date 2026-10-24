"""Whole-deployment filesystem behavior through the publication boundary."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from md_deploy import atomic
from md_deploy.target import Target


def target_at(root: Path) -> Target:
    """The production shell/rsync path, without SSH or WordPress."""
    return Target("fixture", str(root), "http://127.0.0.1", (), None, None)


def test_all_artifacts_are_uploaded_before_one_switch(tmp_path: Path) -> None:
    """An upload of a later dependency must still observe every live version as old."""
    root = tmp_path / "site with spaces"
    relatives = ("wp-content/plugins/first", "wp-content/plugins/second")
    for relative in relatives:
        live = root / relative
        live.mkdir(parents=True)
        (live / "plugin.php").write_text("old")
    uploads_seen: list[str] = []
    switches: list[str] = []

    class ObservedTarget(Target):
        def copy_in(self, source: Path, relative: str) -> None:
            assert all(
                (root / path / "plugin.php").read_text() == "old" for path in relatives
            )
            super().copy_in(source, relative)
            uploads_seen.append(relative)

        def shell(self, script: str) -> str:
            if "TIMEFORMAT=" in script:
                assert len(uploads_seen) == len(relatives)
                switches.append(script)
            return super().shell(script)

    target = ObservedTarget("fixture", str(root), "http://127.0.0.1", (), None, None)
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("new")
    artifacts = [atomic.Artifact(source, relative) for relative in relatives]

    receipt = atomic.publish(target, artifacts, deploy_id="20261024T000000Z-abc123")

    assert all((root / path / "plugin.php").read_text() == "new" for path in relatives)
    assert len(switches) == 1
    assert receipt.deploy_id == "20261024T000000Z-abc123"
    assert receipt.switch_seconds is not None
    assert receipt.switch_seconds >= 0


def test_rollback_rejects_mixed_deploy_ids_before_changing_live(tmp_path: Path) -> None:
    """A saved path from another deployment must be named, not silently mixed in."""
    target = target_at(tmp_path / "site")
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("new")
    relatives = ("wp-content/plugins/first", "wp-content/plugins/second")
    for relative in relatives:
        live = Path(target.path_of(relative))
        live.mkdir(parents=True)
        (live / "plugin.php").write_text("old")
    receipt = atomic.publish(
        target,
        [atomic.Artifact(source, path) for path in relatives],
        deploy_id="20261024T000000Z-abc123",
    )
    manifest_path = Path(receipt.backup_directory) / "manifest.json"
    manifest = json.loads(manifest_path.read_text())
    manifest["backups"][0]["deploy_id"] = "20261023T000000Z-older"
    manifest_path.write_text(json.dumps(manifest))

    with pytest.raises(RuntimeError, match="first.*deploy id"):
        atomic.rollback(target, list(relatives))

    assert all(
        (Path(target.root) / path / "plugin.php").read_text() == "new"
        for path in relatives
    )


def test_rollback_restores_whole_batch_and_reports_actual_identities(
    tmp_path: Path,
) -> None:
    """Trees, owned files and first-time additions share one reversible batch."""
    target = target_at(tmp_path / "site")
    old_tree = Path(target.path_of("wp-content/plugins/first"))
    old_tree.mkdir(parents=True)
    (old_tree / "plugin.php").write_text("old plugin")
    old_inode = old_tree.stat().st_ino
    theme = Path(target.path_of("wp-content/themes/child/functions.php"))
    theme.parent.mkdir(parents=True)
    theme.write_text("old theme")
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("new plugin")
    new_file = tmp_path / "new.php"
    new_file.write_text("new file")
    artifacts = [
        atomic.Artifact(source, "wp-content/plugins/first"),
        atomic.Artifact(new_file, "wp-content/themes/child/functions.php"),
        atomic.Artifact(new_file, "wp-content/mu-plugins/added.php"),
    ]
    atomic.publish(target, artifacts, deploy_id="20261024T000000Z-abc123")

    restored = atomic.rollback(target, [entry.relative for entry in artifacts])

    assert (old_tree / "plugin.php").read_text() == "old plugin"
    assert old_tree.stat().st_ino == old_inode
    assert theme.read_text() == "old theme"
    assert not Path(target.path_of("wp-content/mu-plugins/added.php")).exists()
    assert any(
        "wp-content/plugins/first" in line and str(old_inode) in line
        for line in restored.readbacks
    )
    assert any("added.php: absent" in line for line in restored.readbacks)


def test_verified_backup_moves_outside_web_root_and_only_latest_is_kept(
    tmp_path: Path,
) -> None:
    """Archiving and rollback both rename the original tree on the same device."""
    target = target_at(tmp_path / "site")
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("one")
    artifacts = [atomic.Artifact(source, "wp-content/plugins/first")]
    first_receipt = atomic.publish(target, artifacts, deploy_id="20261024T000001Z-one")
    atomic.archive(target, first_receipt)

    (source / "plugin.php").write_text("two")
    second_receipt = atomic.publish(target, artifacts, deploy_id="20261024T000002Z-two")
    atomic.archive(target, second_receipt)

    root = Path(first_receipt.backup_directory).parent
    # The current batch stays (it is the next rollback's source); the
    # superseded batch does not, and the web root holds no sibling at all.
    assert [path.name for path in sorted(root.iterdir())] == [
        second_receipt.deploy_id,
        "latest",
    ]
    assert (Path(root) / "latest").read_text().strip() == second_receipt.deploy_id
    assert not (root / first_receipt.deploy_id).exists()
    # The web root holds no deployment sibling of any kind.
    for relative in (
        "wp-content/plugins/.first.next",
        "wp-content/plugins/.first.prev",
    ):
        assert not Path(target.path_of(relative)).exists()
    assert (
        Path(target.path_of("wp-content/plugins/first")) / "plugin.php"
    ).read_text() == "two"


def test_a_pending_deployment_blocks_the_next_one_by_name(tmp_path: Path) -> None:
    """A batch that was never verified or rolled back is the only one allowed."""
    target = target_at(tmp_path / "site")
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("one")
    artifacts = [atomic.Artifact(source, "wp-content/plugins/first")]
    atomic.publish(target, artifacts, deploy_id="20261024T000001Z-one")

    with pytest.raises(RuntimeError, match="still needs verify/archive or rollback"):
        atomic.publish(target, artifacts, deploy_id="20261024T000002Z-two")

    # The receipt the window lost is recoverable without republishing.
    recovered = atomic.pending_receipt(target, [item.relative for item in artifacts])
    assert recovered.deploy_id == "20261024T000001Z-one"
    assert recovered.switch_seconds is None


def test_an_unresolved_sibling_refuses_before_anything_is_uploaded(
    tmp_path: Path,
) -> None:
    """A leftover .prev/.next from a killed run blocks the next deploy by name."""
    target = target_at(tmp_path / "site")
    live = Path(target.path_of("wp-content/plugins/first"))
    live.mkdir(parents=True)
    (live / "plugin.php").write_text("old")
    Path(target.path_of("wp-content/plugins/.first.prev")).mkdir()
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("new")

    with pytest.raises(RuntimeError, match="unresolved previous deployment"):
        atomic.publish(
            target,
            [atomic.Artifact(source, "wp-content/plugins/first")],
            deploy_id="20261024T000000Z-abc123",
        )


def test_a_duplicated_or_empty_inventory_is_refused(tmp_path: Path) -> None:
    """The manifest is one path per entry; a duplicate would make rollback ambiguous."""
    target = target_at(tmp_path / "site")
    source = tmp_path / "release"
    source.mkdir()
    (source / "plugin.php").write_text("new")
    twice = [atomic.Artifact(source, "wp-content/plugins/first")] * 2

    with pytest.raises(RuntimeError, match="empty or duplicated"):
        atomic.publish(target, twice, deploy_id="20261024T000000Z-abc123")
