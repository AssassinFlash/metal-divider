"""The steps' decisions, against fake targets: what runs, what refuses, what reports."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from md_deploy import plan, steps
from md_deploy.target import DeployError, Target


class _Site(Target):
    """A target whose answers are scripted per test; nothing runs for real."""

    plugins: dict[str, str]
    core: str
    http_answers: dict[str, tuple[int, str]]
    eval_answers: dict[str, str]
    wp_calls: list[tuple[str, ...]]

    def wp(self, *args: str, check: bool = True) -> str:  # noqa: D102 - scripted
        self.wp_calls.append(args)
        if args[:2] == ("plugin", "list"):
            rows = [
                {"name": name, "version": version, "status": "active"}
                for name, version in self.plugins.items()
            ]
            return json.dumps(rows)
        if args[:2] == ("core", "version"):
            return self.core
        if args[0] == "eval":
            return self.eval_answers.get(args[1], "")
        if args[:2] == ("post", "list"):
            return self.eval_answers.get("products", "")
        if args[0] == plan.WINDPRESS_ANCHORS[0]:
            return self.eval_answers.get("anchors", "")
        return ""

    def http(self, path: str, *, cache_buster: str | None = None) -> tuple[int, str]:
        return self.http_answers.get(path, (404, ""))


def _site(tmp_path: Path, **answers: Any) -> _Site:
    site = _Site("local:test", str(tmp_path), "http://127.0.0.1:9999", (), None, None)
    object.__setattr__(site, "plugins", answers.get("plugins", {}))
    object.__setattr__(site, "core", answers.get("core", ""))
    object.__setattr__(site, "http_answers", answers.get("http_answers", {}))
    object.__setattr__(site, "eval_answers", answers.get("eval_answers", {}))
    object.__setattr__(site, "wp_calls", [])
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


def test_the_windpress_check_reports_a_missing_cache_and_plugin(tmp_path: Path) -> None:
    """Both halves of the assertion say what is missing instead of passing quietly."""
    site = _site(tmp_path, eval_answers={"anchors": ""})

    result = steps.windpress_check(site)

    assert any("cache does not exist" in note for note in result.notes)
    assert any("anchor-class check needs md-site" in note for note in result.notes)


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
