"""The steps' decisions, against fake targets: what runs, what refuses, what reports."""

from __future__ import annotations

import json
import re
import subprocess
import time
from http.client import HTTPMessage
from pathlib import Path
from typing import Any

import pytest
from md_local import permalinks

from md_deploy import atomic, plan, snapshot, steps
from md_deploy.target import DeployError, Target


class _Site(Target):
    """A target whose answers are scripted per test; nothing runs for real.

    The URL scheme is stateful, because it is a write a readback then checks:
    the site starts on `scheme` (the scheme itself unless a test drifts it) and
    a `rewrite structure` call moves it — unless `stuck` is set, which models a
    write that runs and changes nothing (a conflicting filter, say).
    """

    plugins: dict[str, str]
    core: str
    http_answers: dict[str, tuple[int, str]]
    eval_answers: dict[str, str]
    wp_calls: list[tuple[str, ...]]
    scheme: list[str]
    stuck: bool
    wp_failures: dict[tuple[str, ...], str]
    # The page-cache script (path -> [(status, cache header), ...]) and how many
    # answers each path has consumed, so a test can prove the poll stopped at
    # the hit instead of running the whole deadline.
    fetch_answers: dict[str, list[tuple[int, str | None]]]
    fetch_calls: dict[str, list[int]]

    def wp(self, *args: str, check: bool = True) -> str:  # noqa: D102 - scripted
        self.wp_calls.append(args)
        # A command the test declared failing raises the way Target.wp() does on
        # a non-zero exit, so a step that swallows a WP-CLI failure shows up.
        failure = self.wp_failures.get(args[:2])
        if failure is not None and check:
            raise DeployError(f"wp {' '.join(args[:2])}: {failure}")
        if args[:2] == ("plugin", "list"):
            rows = [
                {"name": name, "version": version, "status": "active"}
                for name, version in self.plugins.items()
            ]
            return json.dumps(rows)
        if args[:2] == ("core", "version"):
            return self.core
        if args[:2] == ("rewrite", "structure"):
            if not self.stuck:
                self.scheme = [args[2], args[3].removeprefix("--category-base=")]
            return ""
        if args[0] == "eval" and args[1] == permalinks.READBACK:
            return json.dumps(self.scheme)
        if args[0] == "eval":
            return self.eval_answers.get(args[1], "")
        if args[:2] == ("post", "list"):
            return self.eval_answers.get("products", "")
        if args[0] == plan.WINDPRESS_ANCHORS[0]:
            return self.eval_answers.get("anchors", "")
        return ""

    def http(self, path: str, *, cache_buster: str | None = None) -> tuple[int, str]:
        return self.http_answers.get(path, (404, ""))

    def fetch(
        self, path: str, *, cache_buster: str | None = None
    ) -> tuple[int, HTTPMessage, str]:
        """The page-cache script: one scripted answer per call, then the last one.

        `fetch_answers["/"]` is a list of (status, cache header) the test
        scripted in order; an exhausted list repeats its last entry, so a test
        that wants "always missing" writes one and a test that wants
        "miss, miss, hit" writes three.
        """
        del cache_buster
        scripted = self.fetch_answers.get(path)
        if scripted is None:
            status, body = self.http_answers.get(path, (404, ""))
            return status, _headers(None), body
        index = min(len(self.fetch_calls.get(path, [])), len(scripted) - 1)
        self.fetch_calls.setdefault(path, []).append(index)
        status, cache = scripted[index]
        return status, _headers(cache), ""


def _headers(cache_value: str | None) -> HTTPMessage:
    """A real `HTTPMessage` carrying the one header the cache check reads."""
    message = HTTPMessage()
    if cache_value is not None:
        message["x-litespeed-cache"] = cache_value
    return message


def _site(tmp_path: Path, **answers: Any) -> _Site:
    site = _Site("local:test", str(tmp_path), "http://127.0.0.1:9999", (), None, None)
    object.__setattr__(site, "plugins", answers.get("plugins", {}))
    object.__setattr__(site, "core", answers.get("core", ""))
    object.__setattr__(site, "http_answers", answers.get("http_answers", {}))
    object.__setattr__(site, "eval_answers", answers.get("eval_answers", {}))
    object.__setattr__(site, "wp_calls", [])
    object.__setattr__(site, "scheme", answers.get("scheme", list(permalinks.SCHEME)))
    object.__setattr__(site, "stuck", answers.get("stuck", False))
    object.__setattr__(site, "wp_failures", answers.get("wp_failures", {}))
    object.__setattr__(site, "fetch_answers", answers.get("fetch_answers", {}))
    object.__setattr__(site, "fetch_calls", {})
    return site


def test_the_contract_names_every_mismatch(tmp_path: Path) -> None:
    """A wrong plugin face is a wrong site; the check lists every way it differs."""
    from md_local import versions

    site = _site(
        tmp_path,
        core=versions.WORDPRESS_VERSION,
        plugins={"woocommerce": versions.WOOCOMMERCE_VERSION},
    )

    result = steps.contract(site)

    assert not any("WordPress" in note for note in result.notes)
    assert any(note.startswith("litespeed-cache is missing") for note in result.notes)
    assert not any(
        note.startswith(f"woocommerce is {versions.WOOCOMMERCE_VERSION}")
        for note in result.notes
    )


def test_the_contract_passes_on_the_pinned_face(tmp_path: Path) -> None:
    from md_local import versions

    plugins = {slug: pinned for slug, pinned in steps.CONTRACT_PLUGINS}
    site = _site(tmp_path, core=versions.WORDPRESS_VERSION, plugins=plugins)

    result = steps.contract(site)

    assert result.notes == []


def test_the_data_plane_runs_every_step_in_order(tmp_path: Path) -> None:
    """present → seed-series → seed-tiles → seo, and nothing skipped.

    The seeds are data, not files: the atomic code switch cannot carry them,
    so a deploy that omitted either would leave a switched target rendering
    the new templates against unseeded WooCommerce data. seed-tiles in
    particular is the custom-screens tiles' only source of `menu_order` on a
    clean target.
    """
    site = _site(tmp_path)
    actions: list[str] = []

    steps.apply_data_plane(site, actions)

    assert [call[:2] for call in site.wp_calls] == [
        ("md-site", "present"),
        ("md-site", "seed-series"),
        ("md-site", "seed-tiles"),
        ("md-site", "seo"),
    ]
    assert [a for a in actions if "seed-tiles" in a], "the run is reported"
    assert all(a.endswith("--user=1") for a in actions), (
        "the data plane runs as an administrator"
    )


def test_the_data_plane_names_the_seeded_steps_it_ran(tmp_path: Path) -> None:
    """Silent data writes are unauditable: every step lands in the action log."""
    site = _site(tmp_path)
    actions: list[str] = []

    steps.apply_data_plane(site, actions)

    assert actions == [
        "wp md-site present --user=1",
        "wp md-site seed-series --user=1",
        "wp md-site seed-tiles --user=1",
        "wp md-site seo --user=1",
    ]


def test_a_failing_seed_fails_the_deploy_instead_of_being_skipped(
    tmp_path: Path,
) -> None:
    """A seed that exits non-zero stops the run; later steps never see a pass.

    This is the guard that made production's failure loud rather than silent:
    ``seed-tiles`` exited non-zero on the three Corten codes, and the run has to
    abort there — a deploy that continued to ``seo`` would switch a target whose
    custom-screens panels are empty and report success.

    The three codes that broke it are now resolved by ``TileOrderCommand``
    itself (an un-migrated target carries the old SKU), so this test holds the
    *deploy's* half of that contract: whatever makes a step fail, the failure
    propagates. It is asserted here rather than in the PHP suite because the
    swallowing would happen in Python.
    """
    site = _site(
        tmp_path,
        wp_failures={
            ("md-site", "seed-tiles"): "seed-tiles: missing pattern-geometric: Aztec"
        },
    )
    actions: list[str] = []

    with pytest.raises(DeployError, match="seed-tiles"):
        steps.apply_data_plane(site, actions)

    assert [call[:2] for call in site.wp_calls] == [
        ("md-site", "present"),
        ("md-site", "seed-series"),
        ("md-site", "seed-tiles"),
    ], "the failing step is the last one attempted; seo never ran"
    assert not any("seo" in action for action in actions), (
        "a failed seed is not reported as a completed step"
    )


def test_the_windpress_mtime_reads_the_cache_inside_uploads(tmp_path: Path) -> None:
    """basedir has no trailing slash: the PHP path must carry it (ticket #55)."""
    site = _site(tmp_path)
    site.eval_answers[
        'echo (int) @filemtime( wp_upload_dir()["basedir"] . '
        '"/windpress/cache/tailwind.css" );'
    ] = "1700000000"

    assert steps.windpress_cache_mtime(site) == 1700000000
    php = site.wp_calls[-1][1]
    assert '"/windpress/cache/tailwind.css"' in php


def test_the_windpress_check_reports_a_missing_cache_and_a_silent_checker(
    tmp_path: Path,
) -> None:
    """Both halves say what is missing instead of one passing quietly.

    The second half is the checker printing nothing at all — the cache is
    absent, or md-site is inactive — which must be a note, never a pass.
    """
    site = _site(tmp_path, eval_answers={"anchors": ""})

    result = steps.windpress_check(site)

    assert any("cache does not exist" in note for note in result.notes)
    assert any("anchor-class check needs md-site" in note for note in result.notes)
    assert not any("anchor classes present" in action for action in result.actions)


def test_the_windpress_check_asserts_newer_and_anchors(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(steps, "windpress_cache_mtime", lambda target: 1700000001)
    site = _site(tmp_path, eval_answers={"anchors": "[]"})

    result = steps.windpress_check(site, before=1700000000)

    assert result.notes == []
    assert any("anchor classes present" in action for action in result.actions)


def test_the_windpress_check_refuses_a_stale_cache(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(steps, "windpress_cache_mtime", lambda target: 1700000000)
    site = _site(tmp_path, eval_answers={"anchors": "[]"})

    result = steps.windpress_check(site, before=1700000000)

    assert any("not newer" in note for note in result.notes)


def test_the_windpress_check_names_missing_anchor_classes(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setattr(steps, "windpress_cache_mtime", lambda target: 1700000001)
    site = _site(tmp_path, eval_answers={"anchors": '["md-header"]'})

    result = steps.windpress_check(site, before=1700000000)

    assert any("md-header" in note for note in result.notes)


def test_verify_before_the_import_checks_the_machinery_and_says_so(
    tmp_path: Path,
) -> None:
    """A machinery deploy (before the import) must be able to verify and archive."""
    site = _site(
        tmp_path,
        http_answers={"/": (200, "home"), "/collections/": (500, "")},
        eval_answers={"products": ""},
    )

    result = steps.verify(site, cache_buster="t")

    assert result.notes == []
    assert any("content not imported yet" in action for action in result.actions)
    assert not any("GET /collections/" in action for action in result.actions), (
        "an entry point its content does not exist for yet is not fetched"
    )


def test_verify_after_the_import_checks_every_entry_point(tmp_path: Path) -> None:
    site = _site(
        tmp_path,
        http_answers={
            path: (200, "page")
            for path in ("/", "/collections/", "/inquiry/", "/blog/")
        }
        | {"/pattern/a-slug/": (500, "")},
        eval_answers={"products": "a-slug"},
    )

    result = steps.verify(site, cache_buster="t")

    assert any("/pattern/a-slug/ answered 500" for note in result.notes)


def test_verify_checks_the_first_pattern_page_when_content_exists(
    tmp_path: Path,
) -> None:
    slug = "link-circle-mesh"
    site = _site(
        tmp_path,
        http_answers={
            path: (200, "page")
            for path in ("/", "/collections/", "/inquiry/", "/blog/")
        }
        | {f"/pattern/{slug}/": (200, f"the {slug}")},
        eval_answers={"products": slug},
    )

    result = steps.verify(site, cache_buster="t")

    assert result.notes == []
    assert any(f"GET /pattern/{slug}/ -> 200" in action for action in result.actions)


def test_verify_flags_a_pattern_page_that_lost_its_slug(tmp_path: Path) -> None:
    site = _site(
        tmp_path,
        http_answers={
            path: (200, "page")
            for path in ("/", "/collections/", "/inquiry/", "/blog/")
        }
        | {"/pattern/some-slug/": (200, "not it")},
        eval_answers={"products": "some-slug"},
    )

    result = steps.verify(site, cache_buster="t")

    assert any("does not carry its pattern's slug" for note in result.notes)


def test_deployment_artifacts_refuse_a_tree_without_mu_plugins(tmp_path: Path) -> None:
    """The must-use layer is the deploy's own floor; an empty one is a broken checkout."""
    target = Target("dev", "/site", "http://site", (), None, None)
    with pytest.raises(DeployError, match="carries no must-use plugin"):
        steps.deployment_artifacts(tmp_path, target)


def test_url_scheme_writes_a_drifted_site_and_reads_it_back(tmp_path: Path) -> None:
    """The scheme is options, so a drifted site needs the write; the readback is the proof."""
    site = _site(tmp_path, scheme=["/%postname%/", ""])

    result = steps.url_scheme(site)

    assert result.notes == []
    assert any(
        "/blog/%postname%/ / categories category written and read back" in action
        for action in result.actions
    ), result.actions
    written = [call for call in site.wp_calls if call[:2] == ("rewrite", "structure")]
    assert written == [
        ("rewrite", "structure", "/blog/%postname%/", "--category-base=category")
    ]


def test_url_scheme_writes_nothing_on_a_site_already_on_the_scheme(
    tmp_path: Path,
) -> None:
    """Idempotent: the deploy runs on every target and must not flush rewrites each time."""
    site = _site(tmp_path)

    result = steps.url_scheme(site)

    assert result.notes == []
    assert any("already applied" in action for action in result.actions)
    assert not [call for call in site.wp_calls if call[0] == "rewrite"]


def test_url_scheme_notes_a_write_that_does_not_read_back(tmp_path: Path) -> None:
    """A site that looks configured but is not must not pass as a successful deploy."""
    site = _site(tmp_path, scheme=["/%postname%/", ""], stuck=True)

    result = steps.url_scheme(site)

    assert any("read back as" in note for note in result.notes), result.notes


def test_url_scheme_reads_without_writing_when_asked(tmp_path: Path) -> None:
    """verify's half: the readback only, so a window's check never changes the site."""
    site = _site(tmp_path, scheme=["/%postname%/", ""])

    result = steps.url_scheme(site, write=False)

    assert not [call for call in site.wp_calls if call[0] == "rewrite"]
    assert any("read back" in action for action in result.actions)


def test_verify_notes_a_drifted_url_scheme(tmp_path: Path) -> None:
    """Drift is its own note, not a scatter of 404s from the entry points."""
    site = _site(
        tmp_path,
        http_answers={"/": (200, "home")},
        scheme=["", ""],
    )

    result = steps.verify(site, cache_buster="t")

    assert any("the url scheme is" in note for note in result.notes), result.notes


def test_verify_passes_a_site_on_the_scheme(tmp_path: Path) -> None:
    site = _site(tmp_path, http_answers={"/": (200, "home")})

    result = steps.verify(site, cache_buster="t")

    assert result.notes == []
    assert any("url scheme /blog/%postname%/" in action for action in result.actions)


def test_finish_deploy_keeps_the_backups_when_a_phase_note_fails(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A switch-time note is a failed check: no archiving, so rollback still has its copies."""
    site = _site(tmp_path, http_answers={"/": (200, "home")})
    archived: list[object] = []
    monkeypatch.setattr(atomic, "archive", lambda *a: archived.append(a))
    receipt = atomic_receipt(tmp_path)

    result = steps.finish_deploy(
        site, receipt, cache_buster="t", phase_notes=["the scheme did not hold"]
    )

    assert result.notes == ["the scheme did not hold"]
    assert archived == [], "a failed phase must leave the rollback copies in place"


def test_finish_deploy_archives_when_every_half_passes(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    site = _site(tmp_path, http_answers={"/": (200, "home")})
    archived: list[object] = []
    monkeypatch.setattr(atomic, "archive", lambda *a: archived.append(a))
    receipt = atomic_receipt(tmp_path)

    result = steps.finish_deploy(site, receipt, cache_buster="t", phase_notes=[])

    assert result.notes == []
    assert len(archived) == 1
    assert any("archived backups by rename" in action for action in result.actions)


def atomic_receipt(tmp_path: Path) -> atomic.Receipt:
    """A pending deploy's receipt, as `atomic.publish` leaves one.

    The batch directory exists: `publish` makes it, and `finish_deploy` writes
    the phase notes beside the manifest before verify (`atomic.write_phase_notes`).
    """
    directory = tmp_path / "backups"
    directory.mkdir(exist_ok=True)
    return atomic.Receipt(
        deploy_id="d-test",
        switch_seconds=0.1,
        backup_directory=str(directory),
        paths=(),
    )


def checkout(tmp_path: Path, files: dict[str, str] | None = None) -> Path:
    """A git checkout with one commit, so a test can dirty it deliberately."""
    root = tmp_path / "checkout"
    root.mkdir()
    (root / "plugins" / "md-site").mkdir(parents=True)
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php\n")
    (root / "mu-plugins").mkdir()
    (root / "mu-plugins" / "md-site-guards.php").write_text("<?php\n")
    for relative, content in (files or {}).items():
        path = root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
    for command in (
        ("git", "init", "-q", "."),
        ("git", "add", "-A"),
        ("git", "-c", "user.email=a@b", "-c", "user.name=a", "commit", "-qm", "init"),
    ):
        subprocess.run(command, cwd=root, check=True, capture_output=True)
    return root


def test_a_clean_checkout_reports_no_dirty_paths(tmp_path: Path) -> None:
    root = checkout(tmp_path)

    state = steps.source_state(root)

    assert state.dirty == ()
    assert state.digest == ""
    assert state.commit != ""


def test_an_edited_shipped_file_is_dirty(tmp_path: Path) -> None:
    """The bytes that ship differ from the commit: exactly the defect this guards."""
    root = checkout(tmp_path)
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php // edited\n")

    state = steps.source_state(root)

    assert state.dirty == ("plugins/md-site/md-site.php",)
    assert state.digest != ""


def test_an_edited_excluded_file_is_not_dirty(tmp_path: Path) -> None:
    """A test file, vendor tree or phpcs config changes no deployed byte."""
    root = checkout(
        tmp_path,
        {
            "plugins/md-site/tests/UnitTest.php": "<?php\n",
            "plugins/md-site/phpcs.xml.dist": "<ruleset/>\n",
            "plugins/md-site/vendor/lib/x.php": "<?php\n",
        },
    )
    (root / "plugins/md-site" / "tests" / "UnitTest.php").write_text(
        "<?php // edited\n"
    )
    (root / "plugins/md-site" / "phpcs.xml.dist").write_text("<ruleset/>\n<!-- x -->")
    (root / "plugins/md-site" / "vendor" / "lib" / "x.php").write_text(
        "<?php // edited\n"
    )

    assert steps.source_state(root).dirty == ()


def test_an_untracked_shipped_file_is_dirty(tmp_path: Path) -> None:
    """rsync copies untracked files, so they ship and the commit does not have them."""
    root = checkout(tmp_path)
    (root / "plugins" / "md-site" / "extra.php").write_text("<?php\n")

    assert steps.source_state(root).dirty == ("plugins/md-site/extra.php",)


def test_a_renamed_shipped_file_reports_the_destination(tmp_path: Path) -> None:
    """The origin is a bare NUL field: reading it as a path would invent a dirty file."""
    root = checkout(tmp_path, {"plugins/md-site/old.php": "<?php\n"})
    subprocess.run(
        ["git", "mv", "plugins/md-site/old.php", "plugins/md-site/new.php"],
        cwd=root,
        check=True,
        capture_output=True,
    )

    assert steps.source_state(root).dirty == ("plugins/md-site/new.php",)


def test_a_dirty_tree_outside_the_shipped_roots_is_not_dirty(tmp_path: Path) -> None:
    """Only the inventory's own trees count; the rest of the checkout is not deployed."""
    root = checkout(tmp_path)
    (root / "docs").mkdir()
    (root / "docs" / "notes.md").write_text("not shipped\n")
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php // shipped\n")

    assert steps.source_state(root).dirty == ("plugins/md-site/md-site.php",)


def test_the_dirty_digest_separates_two_states(tmp_path: Path) -> None:
    """Two different dirty trees must never share a deploy id."""
    root = checkout(tmp_path)
    target = root / "plugins" / "md-site" / "md-site.php"
    target.write_text("<?php // one\n")
    first = steps.source_state(root)
    target.write_text("<?php // two\n")
    second = steps.source_state(root)

    assert first.digest != second.digest


def test_a_clean_tree_names_the_commit_in_its_id(tmp_path: Path) -> None:
    root = checkout(tmp_path)

    state = steps.source_state(root)

    assert steps.deployment_id(root, state).endswith("-" + state.commit)
    assert "dirty" not in steps.deployment_id(root, state)


def test_a_dirty_tree_names_the_commit_and_its_content(tmp_path: Path) -> None:
    """The commit alone is not the story of this batch's bytes; the digest is."""
    root = checkout(tmp_path)
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php // edited\n")
    state = steps.source_state(root)

    identifier = steps.deployment_id(root, state)

    assert identifier.endswith(f"-{state.commit}-dirty-{state.digest}")
    # The id has to survive the manifest's own validation.
    assert re.fullmatch(r"[A-Za-z0-9-]+", identifier)


def test_a_remote_deploy_refuses_a_dirty_shipped_tree(tmp_path: Path) -> None:
    """The architecture rule: a remote batch ships a commit, never working-tree bytes."""
    root = checkout(tmp_path)
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php // edited\n")
    remote = _Site(
        "prod", str(tmp_path), "https://example.test", (), None, "host:/root"
    )

    with pytest.raises(DeployError, match="must deploy a commit"):
        steps.deploy(remote, repository=root, staging=tmp_path / "staging")


def test_the_local_deploy_keeps_working_on_a_dirty_tree(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """`just deploy dev --write` is where those bytes are being written: it proceeds.

    The refusal is the remote target's; the local path must still reach its
    first real step (here: the snapshot, faked to prove it was asked).
    """
    root = checkout(tmp_path)
    (root / "plugins" / "md-site" / "md-site.php").write_text("<?php // edited\n")
    local = _site(tmp_path)
    asked: list[str] = []

    def stop(*args: object, **kwargs: object) -> None:
        asked.append("snapshot")
        raise DeployError("stop here: the preflight let this deploy through")

    monkeypatch.setattr(snapshot, "take", stop)

    with pytest.raises(DeployError, match="stop here"):
        steps.deploy(local, repository=root, staging=tmp_path / "staging")

    assert asked == ["snapshot"], "the local deploy got past the source check"


def local_target(root: Path) -> Target:
    """A real local target: `shell` runs here, on the test's own directory.

    `shell_prefix is None` makes Target run the script directly, so a test can
    exercise a shell round trip for real instead of scripting its result.
    """
    root.mkdir(parents=True, exist_ok=True)
    return Target("local:test", str(root), "http://127.0.0.1:9999", ("wp",), None, None)


def test_phase_notes_round_trip_through_the_batch_directory(tmp_path: Path) -> None:
    """The switch-time notes survive the process that made them.

    `deploy()` and `resume-deploy` are separate invocations: the second one
    never ran the switch phases, so the only way it can know a phase left a
    note is a record beside the batch. This is that record's contract — write
    once, read back exactly, absent means none.
    """
    site = local_target(tmp_path / "site")
    batch = tmp_path / "batch"
    batch.mkdir()

    atomic.write_phase_notes(site, str(batch), ["the url scheme did not hold"])

    assert atomic.read_phase_notes(site, str(batch)) == ["the url scheme did not hold"]


def test_phase_notes_are_absent_and_empty_when_none_were_recorded(
    tmp_path: Path,
) -> None:
    """A clean batch records nothing: the resumed run's result must not differ."""
    site = local_target(tmp_path / "site")
    batch = tmp_path / "batch"
    batch.mkdir()

    assert atomic.read_phase_notes(site, str(batch)) == []
    atomic.write_phase_notes(site, str(batch), [])
    assert atomic.read_phase_notes(site, str(batch)) == []


def test_resume_deploy_reports_the_notes_the_switch_recorded(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The defect: `resume_deploy` finished the batch with no phase notes.

    A batch whose switch already reported a problem would then archive its
    backups — the state `finish_deploy`'s contract forbids — and the note
    would vanish with the process that made it. `resume_deploy` must read the
    batch's own record and hand it to `finish_deploy`, so the failure is
    reported and the rollback copies stay put.
    """
    site = local_target(tmp_path / "site")
    directory = tmp_path / "backups"
    directory.mkdir()
    receipt = atomic.Receipt("d-test", None, str(directory), ())
    atomic.write_phase_notes(site, str(directory), ["present reported drift"])

    monkeypatch.setattr(steps, "_manifest_relatives", lambda target: [])
    monkeypatch.setattr(atomic, "pending_receipt", lambda target, relatives: receipt)
    monkeypatch.setattr(
        steps,
        "verify",
        lambda target, cache_buster: steps.Result("verify", ["verified"], []),
    )
    archived: list[object] = []
    monkeypatch.setattr(atomic, "archive", lambda *a: archived.append(a))

    result = steps.resume_deploy(site, repository=tmp_path, cache_buster="t")

    assert result.notes == ["present reported drift"]
    assert archived == [], (
        "a batch with a switch-time note must keep its rollback copies"
    )
    assert any("switch-time notes recovered: 1" in action for action in result.actions)


def test_resume_deploy_archives_a_clean_batch(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """No recorded notes and a passing verify: the batch archives as before."""
    site = local_target(tmp_path / "site")
    directory = tmp_path / "backups"
    directory.mkdir()
    receipt = atomic.Receipt("d-test", None, str(directory), ())

    monkeypatch.setattr(steps, "_manifest_relatives", lambda target: [])
    monkeypatch.setattr(atomic, "pending_receipt", lambda target, relatives: receipt)
    monkeypatch.setattr(
        steps,
        "verify",
        lambda target, cache_buster: steps.Result("verify", ["verified"], []),
    )
    archived: list[object] = []
    monkeypatch.setattr(atomic, "archive", lambda *a: archived.append(a))

    result = steps.resume_deploy(site, repository=tmp_path, cache_buster="t")

    assert result.notes == []
    assert len(archived) == 1
    assert not any("switch-time notes recovered" in action for action in result.actions)


def test_the_reset_token_lives_beside_the_root_not_under_home(tmp_path: Path) -> None:
    """The production web SAPI has no HOME (measured 2026-10-26).

    The script used to write `$HOME/.config/metal-divider/opcache-reset.token`;
    the web-side reader asked `getenv('HOME')`, found nothing, and returned
    before it ever read the token — every deploy logged `opcache-reset=none`.
    The path is now computed from the root, which both sides know.
    """
    target = Target(
        "prod",
        "/home/u/domains/metal-divider.com/public_html",
        "https://metal-divider.com",
        (),
        None,
        "host:/home/u/domains/metal-divider.com/public_html",
    )

    script = steps.opcache_reset_script(target)

    expected = "/home/u/domains/metal-divider.com/.md-deploy"
    assert f"dir={expected}" in script
    assert f"{expected}/opcache-reset.token" in script
    assert f"{expected}/opcache-reset.header" in script
    assert "$HOME" not in script, "the token must not depend on an environment variable"


def test_the_reset_script_is_per_site(tmp_path: Path) -> None:
    """A review-site token must never be presentable to production."""

    def script(root: str) -> str:
        return steps.opcache_reset_script(
            Target("t", root, "https://x.test", (), None, f"host:{root}")
        )

    prod = script("/home/u/domains/metal-divider.com/public_html")
    staging = script("/home/u/domains/staging.metal-divider.com/public_html")

    assert "dir=/home/u/domains/metal-divider.com/.md-deploy" in prod
    assert "dir=/home/u/domains/metal-divider.com/.md-deploy" not in staging
    assert "dir=/home/u/domains/staging.metal-divider.com/.md-deploy" in staging


def test_the_reset_script_still_asks_with_a_header_file_and_cleans_up(
    tmp_path: Path,
) -> None:
    """The contract the plugin answers to, unchanged by the path move."""
    target = Target(
        "prod",
        "/root/public_html",
        "https://metal-divider.com",
        (),
        None,
        "host:/root/public_html",
    )

    script = steps.opcache_reset_script(target)

    # The token travels in a file, never on a command line other accounts could list.
    assert "-H @/root/.md-deploy/opcache-reset.header" in script
    assert "--max-time 5" in script
    assert "-X POST" in script
    # Both files the run created are removed, whatever the outcome.
    assert (
        "rm -f /root/.md-deploy/opcache-reset.token /root/.md-deploy/opcache-reset.header"
        in script
    )
    # The answer is what the deploy reads back, and 'none' means no attempt
    # reached the plugin — the shape the production log showed.
    assert "opcache-reset=%s attempts=%s" in script


def test_the_reset_header_name_is_the_plugins() -> None:
    """The deploy sends X-MD-Opcache-Reset; the plugin reads HTTP_X_MD_OPCACHE_RESET."""
    plugin = (
        Path(__file__).resolve().parents[3] / "mu-plugins" / "md-deploy-reset.php"
    ).read_text()
    assert "HTTP_X_MD_OPCACHE_RESET" in plugin
    assert "X-MD-Opcache-Reset" in steps.opcache_reset_script(
        Target("t", "/root/public_html", "https://x.test", (), None, "host:/x")
    )


def test_the_two_sides_agree_on_the_token_directory() -> None:
    """`steps.RESET_DIRECTORY` and the plugin's `DeployReset::DIRECTORY` are one path.

    They are two languages in two projects, so nothing but a test keeps them
    equal — and a silent mismatch is exactly the failure that produced
    `opcache-reset=none`.
    """
    plugin = (
        Path(__file__).resolve().parents[3] / "mu-plugins" / "md-deploy-reset.php"
    ).read_text()

    assert f"DIRECTORY = '/{steps.RESET_DIRECTORY}'" in plugin


def test_the_page_cache_passes_on_an_immediate_hit(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A warm cache is a pass: a resumed deploy must not be failed for being early.

    The first GET already hitting means LiteSpeed is serving; nothing about
    the cache is broken because this deploy did not see it miss.
    """
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(tmp_path, fetch_answers={"/": [(200, "hit")]})

    actions, notes = steps._page_cache_check(site)

    assert notes == []
    assert len(actions) == 1, "an immediate hit ends the check at once"
    assert "200 hit" in actions[0]


def test_the_page_cache_polls_through_misses_to_a_hit(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The reported production shape: miss, miss, then hit — generation is async."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(
        tmp_path,
        fetch_answers={"/": [(200, "miss"), (200, "miss"), (200, "hit")]},
    )

    actions, notes = steps._page_cache_check(site)

    assert notes == []
    assert len(actions) == 3
    assert "miss" in actions[0] and "hit" in actions[2]


def test_the_page_cache_stops_at_the_first_hit(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The poll is bounded by the hit, not by the deadline: no wasted window time."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(
        tmp_path,
        fetch_answers={"/": [(200, "miss"), (200, "hit"), (200, "hit")]},
    )

    actions, _ = steps._page_cache_check(site)

    assert len(actions) == 2
    assert len(site.fetch_calls["/"]) == 2


def test_the_page_cache_never_passes_a_sustained_miss(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A cache that never serves is the failure this check exists for."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(tmp_path, fetch_answers={"/": [(200, "miss")]})

    actions, notes = steps._page_cache_check(site)

    assert len(actions) == plan.PAGE_CACHE_ATTEMPTS, "it used the whole deadline"
    assert len(notes) == 1
    assert "never answered hit" in notes[0]
    assert "miss" in notes[0], "the report says what was actually seen"


def test_the_page_cache_never_passes_a_non_200(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """A page that does not serve is not a cache result; fail fast, do not poll."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(tmp_path, fetch_answers={"/": [(503, "miss"), (200, "hit")]})

    actions, notes = steps._page_cache_check(site)

    assert len(actions) == 1, "no polling after a bad status"
    assert any("answered 503" in note for note in notes)
    assert not any("never answered hit" in note for note in notes)


def test_a_missing_header_is_not_a_hit(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """No header is 'no evidence', never a pass and never a silent crash."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(tmp_path, fetch_answers={"/": [(200, None)]})

    actions, notes = steps._page_cache_check(site)

    assert "no header" in actions[0]
    assert any("never answered hit" in note for note in notes)


def test_verify_passes_a_warm_page_cache(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """The production verify path: an immediate hit is a green verify, not a note."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(
        tmp_path,
        http_answers={"/": (200, "home")},
        fetch_answers={"/": [(200, "hit")]},
    )
    object.__setattr__(site, "label", "prod")

    result = steps.verify(site, cache_buster="t")

    assert result.notes == []
    assert any("page cache: GET / (attempt 1) -> 200 hit" in a for a in result.actions)


def test_verify_fails_a_page_cache_that_never_serves(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Sustained misses must keep a production verify red."""
    monkeypatch.setattr(time, "sleep", _no_sleep)
    site = _site(
        tmp_path,
        http_answers={"/": (200, "home")},
        fetch_answers={"/": [(200, "miss")]},
    )
    object.__setattr__(site, "label", "prod")

    result = steps.verify(site, cache_buster="t")

    assert any("never answered hit" in note for note in result.notes)


def _no_sleep(seconds: float) -> None:
    """The poll's pause, without the pause: the deadline is the code's, not the test's."""
    del seconds
