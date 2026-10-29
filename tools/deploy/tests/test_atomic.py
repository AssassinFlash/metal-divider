"""Whole-deployment filesystem behavior through the publication boundary."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from md_deploy import atomic
from md_deploy import target as target_module
from md_deploy.target import DeployError, Target


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


def namespaced(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> tuple[Target, Path, Path]:
    """A target whose two batch stores are distinct, real, local directories.

    The store split lives in `md_deploy.target.backup_root` (a function of the
    host's `$HOME`); `atomic` imports both names directly, so the split is
    installed here the way it resolves in production while the publication,
    archive and rollback machinery runs for real against a local filesystem.
    """
    new = tmp_path / "deploy-prev-metal-divider" / "metal-divider.com"
    legacy = tmp_path / "deploy-prev" / "metal-divider.com"
    new.mkdir(parents=True)
    legacy.mkdir(parents=True)
    monkeypatch.setattr(atomic, "backup_root", lambda _target: str(new))
    monkeypatch.setattr(atomic, "legacy_backup_root", lambda _target: str(legacy))
    return target_at(tmp_path / "site"), new, legacy


def manifest_at(directory: Path, deploy_id: str, state: str) -> None:
    """A batch directory with a pointer and a manifest, as publish leaves one."""
    batch = directory / deploy_id
    batch.mkdir(parents=True, exist_ok=True)
    (directory / "latest").write_text(deploy_id)
    (batch / "manifest.json").write_text(
        json.dumps({"deploy_id": deploy_id, "state": state, "backups": []})
    )


def test_a_new_batch_lands_in_the_namespace_not_the_legacy_store() -> None:
    """de-linker prunes every directory under its own root; ours must not be there."""

    # The real functions, asked the way a remote target asks: the stub answers
    # the `$HOME`-relative printf so the split itself is what is asserted.
    class Host(Target):
        def shell(self, script: str) -> str:  # noqa: D102 - scripted
            assert "printf" in script
            if "deploy-prev-metal-divider" in script:
                return "/home/u/deploy-prev-metal-divider/metal-divider.com"
            return "/home/u/deploy-prev/metal-divider.com"

    target = Host(
        "prod",
        "/home/u/domains/metal-divider.com/public_html",
        "https://metal-divider.com",
        (),
        ("ssh", "host"),
        "host:root",
    )

    new = target_module.backup_root(target)
    legacy = target_module.legacy_backup_root(target)

    assert new == "/home/u/deploy-prev-metal-divider/metal-divider.com"
    assert legacy == "/home/u/deploy-prev/metal-divider.com"
    assert not new.startswith(legacy + "/"), "the new store is inside de-linker's prune"


def test_an_archived_legacy_batch_is_still_readable(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Recovery points from before the split must keep working for rollback."""
    target, new, legacy = namespaced(tmp_path, monkeypatch)
    manifest_at(legacy, "20261026T044505580272Z-0f054c9", "archived")

    directory, manifest = atomic.latest_manifest(target)

    assert directory == str(legacy / "20261026T044505580272Z-0f054c9")
    assert manifest.state == "archived"


def test_the_namespace_pointer_wins_over_the_legacy_one(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Once this project has deployed into its own store, that is the record."""
    target, new, legacy = namespaced(tmp_path, monkeypatch)
    manifest_at(legacy, "20261026T044505580272Z-0f054c9", "archived")
    manifest_at(new, "20261026T060000000000Z-abcdef0", "archived")

    directory, manifest = atomic.latest_manifest(target)

    assert directory.startswith(str(new))
    assert manifest.deploy_id == "20261026T060000000000Z-abcdef0"


def test_a_pending_legacy_batch_is_refused_not_resumed(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """It lives inside de-linker's prune: resuming would trust files another project deletes."""
    target, _new, legacy = namespaced(tmp_path, monkeypatch)
    manifest_at(legacy, "20261026T042025478901Z-1be1823", "pending")

    with pytest.raises(DeployError, match="legacy store"):
        atomic.latest_manifest(target)


def test_a_pending_legacy_batch_also_blocks_a_new_publish(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A fresh deploy must not bury a batch that still holds live paths hostage."""
    target, _new, legacy = namespaced(tmp_path, monkeypatch)
    manifest_at(legacy, "20261026T042025478901Z-1be1823", "pending")
    live = tmp_path / "site" / "wp-content" / "mu-plugins"
    live.mkdir(parents=True)
    (live / "md-site-guards.php").write_text("old")
    source = tmp_path / "release"
    source.mkdir()
    (source / "md-site-guards.php").write_text("new")

    with pytest.raises(DeployError, match="legacy store"):
        atomic.publish(
            target,
            [
                atomic.Artifact(
                    source / "md-site-guards.php",
                    "wp-content/mu-plugins/md-site-guards.php",
                )
            ],
            deploy_id="20261026T070000000000Z-aaaaaaa",
        )


def test_nothing_is_reported_when_no_store_has_a_batch(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A first deploy on a clean host is not an error, it is simply no record."""
    target, _new, _legacy = namespaced(tmp_path, monkeypatch)

    with pytest.raises(DeployError, match="no previous deployment manifest"):
        atomic.latest_manifest(target)


def test_archiving_prunes_only_this_projects_store(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The reciprocal of the defect: our archive must not delete de-linker's batches.

    Every archive ends with a prune of its own root. Scoped to the namespace,
    that leaves `~/deploy-prev/` — another project's store — untouched; scoped
    to `$HOME/deploy-prev` (the old value) it would `rm -rf` their batches.
    """
    target, new, legacy = namespaced(tmp_path, monkeypatch)
    their_batch = legacy.parent / "20261026T050910Z-product-library-scope-89c20879"
    their_batch.mkdir()
    (their_batch / "manifest.json").write_text('{"theirs": true}')
    (legacy.parent / "latest").write_text(their_batch.name)
    old_batch = new / "20261026T050000000000Z-oldold0"
    old_batch.mkdir()
    live = tmp_path / "site" / "wp-content" / "mu-plugins"
    live.mkdir(parents=True)
    (live / "md-site-guards.php").write_text("old")
    source = tmp_path / "release"
    source.mkdir()
    (source / "md-site-guards.php").write_text("new")

    receipt = atomic.publish(
        target,
        [
            atomic.Artifact(
                source / "md-site-guards.php",
                "wp-content/mu-plugins/md-site-guards.php",
            )
        ],
        deploy_id="20261026T080000000000Z-bbbbbbb",
    )
    atomic.archive(target, receipt)

    assert (their_batch / "manifest.json").is_file(), "de-linker's batch was deleted"
    assert (legacy.parent / "latest").read_text() == their_batch.name
    assert not old_batch.exists(), "our own superseded batch is pruned as before"
    assert (new / receipt.deploy_id / "manifest.json").is_file()


def _pending_batch(target: Target, tmp_path: Path, *, live_first: bool) -> atomic.Receipt:
    """Publish one plugin batch onto the target, left in the pending state."""
    source = tmp_path / "release"
    source.mkdir(exist_ok=True)
    (source / "plugin.php").write_text("new")
    relative = "wp-content/plugins/first"
    if live_first:
        live = Path(target.path_of(relative))
        live.mkdir(parents=True)
        (live / "plugin.php").write_text("old")
    return atomic.publish(
        target,
        [atomic.Artifact(source, relative)],
        deploy_id="20261026T000000Z-leftover",
    )


def test_forget_archives_a_contentless_pending_batch(tmp_path: Path) -> None:
    """The 2026-10-26 shape: pending, nothing left to roll back, gate stuck.

    The batch's backup sibling is gone, so resume and rollback would both
    refuse on drift — forget archives the manifest, reads it back, and
    the publish gate opens again.
    """
    import shutil

    target = target_at(tmp_path / "site")
    receipt = _pending_batch(target, tmp_path, live_first=True)
    shutil.rmtree(Path(target.path_of(atomic._siblings("wp-content/plugins/first").previous)))

    deploy_id, directory, leftovers = atomic.forget(target)

    assert deploy_id == receipt.deploy_id
    assert directory == receipt.backup_directory
    assert leftovers == []
    _, readback = atomic.latest_manifest(target)
    assert readback.state == "archived", "the manifest must read back archived"
    # The gate is open again: a fresh publish is no longer blocked by name.
    source = tmp_path / "release2"
    source.mkdir()
    (source / "plugin.php").write_text("newer")
    atomic.publish(
        target,
        [atomic.Artifact(source, "wp-content/plugins/first")],
        deploy_id="20261026T000001Z-fresh",
    )


def test_forget_refuses_a_batch_that_still_holds_content(tmp_path: Path) -> None:
    """A `files/` directory means the batch can still be finished or undone."""
    target = target_at(tmp_path / "site")
    _pending_batch(target, tmp_path, live_first=True)
    (Path(atomic.latest_manifest(target)[0]) / "files").mkdir()

    with pytest.raises(RuntimeError, match="resume-deploy.*rollback"):
        atomic.forget(target)

    _, readback = atomic.latest_manifest(target)
    assert readback.state == "pending", "a refused forget changes nothing"


def test_forget_refuses_a_non_pending_batch(tmp_path: Path) -> None:
    """An archived or rolled-back batch has nothing to forget."""
    target = target_at(tmp_path / "site")
    receipt = _pending_batch(target, tmp_path, live_first=True)
    atomic.archive(target, receipt)

    with pytest.raises(RuntimeError, match="not pending"):
        atomic.forget(target)


def test_forget_reports_siblings_left_in_the_web_root(tmp_path: Path) -> None:
    """A pending batch with its backup intact is forgettable but reported.

    The gate opens (the incident's manual fix did exactly this), and the
    leftover sibling is named rather than silently stranded: it will
    block the next publish until an operator removes it.
    """
    target = target_at(tmp_path / "site")
    _pending_batch(target, tmp_path, live_first=True)

    _deploy_id, _directory, leftovers = atomic.forget(target)

    sibling = target.path_of(atomic._siblings("wp-content/plugins/first").previous)
    assert [Path(path).name for path in leftovers] == [Path(sibling).name]
    _, readback = atomic.latest_manifest(target)
    assert readback.state == "archived"
