"""The URL scheme plane: what apply() writes, when it writes nothing, and its proof."""

from __future__ import annotations

import json

import pytest

from md_local import permalinks


class Wp:
    """A site's two permalink options, scripted the way WP-CLI answers.

    Records every call, so a test can assert a second apply() does not write,
    and can make a write fail to read back (the drift that must raise).
    """

    def __init__(self, structure: str = "", category_base: str = "") -> None:
        self.structure = structure
        self.category_base = category_base
        self.calls: list[tuple[str, ...]] = []

    def __call__(self, *args: str) -> str:
        self.calls.append(args)
        if args[0] == "eval":
            return json.dumps([self.structure, self.category_base])
        assert args[:2] == ("rewrite", "structure"), args
        assert len(args) == 4 and args[3].startswith("--category-base=")
        self.structure = args[2]
        self.category_base = args[3].removeprefix("--category-base=")
        return ""

    @property
    def writes(self) -> list[tuple[str, ...]]:
        return [call for call in self.calls if call[0] != "eval"]


def test_read_reports_both_options() -> None:
    site = Wp("/blog/%postname%/", "category")
    assert permalinks.read(site) == permalinks.SCHEME


def test_read_reports_unset_options_as_empty() -> None:
    """A fresh install (or an import from a differently configured site)."""
    assert permalinks.read(Wp()) == permalinks.Scheme("", "")


def test_apply_writes_a_drifted_site() -> None:
    site = Wp("/%postname%/", "")
    applied, wrote = permalinks.apply(site)

    assert applied == permalinks.SCHEME
    assert wrote is True
    assert site.writes == [
        (
            "rewrite",
            "structure",
            "/blog/%postname%/",
            "--category-base=category",
        )
    ]


def test_apply_is_idempotent_by_readback() -> None:
    """A site already on the scheme gets one read and no write: `up` stays a safe rerun."""
    site = Wp("/blog/%postname%/", "category")
    applied, wrote = permalinks.apply(site)

    assert applied == permalinks.SCHEME
    assert wrote is False
    assert site.writes == []


def test_apply_raises_when_the_write_does_not_read_back() -> None:
    """A site that looks configured but is not must not pass as a success."""

    def stuck(*args: str) -> str:
        if args[0] == "eval":
            return json.dumps(["/%postname%/", ""])
        return ""

    with pytest.raises(permalinks.PermalinkError, match="read back as"):
        permalinks.apply(stuck)


def test_apply_raises_on_a_readback_that_is_not_two_values() -> None:
    def odd(*args: str) -> str:
        return "Success: Rewrite structure set."

    with pytest.raises(permalinks.PermalinkError, match="no JSON line"):
        permalinks.apply(odd)


def test_read_takes_the_last_json_line_of_a_noisy_answer() -> None:
    """A plugin that prints on load must not turn a clean read into a false drift."""

    def noisy(*args: str) -> str:
        return (
            "Notice: something a plugin printed on load\n"
            + json.dumps(["/blog/%postname%/", "category"])
            + "\n"
        )

    assert permalinks.read(noisy) == permalinks.SCHEME


def test_the_readback_asks_for_unset_options_as_empty_strings() -> None:
    """`wp option get` exits 1 on an option the site does not have; the eval does not."""
    site = Wp()
    permalinks.read(site)
    (call,) = site.calls
    assert call[0] == "eval"
    assert "(string) get_option( 'permalink_structure' )" in call[1]
    assert "(string) get_option( 'category_base' )" in call[1]
