"""Tests for csslib's carried-text repairs."""

from __future__ import annotations

from md_template_convert.csslib import collapse_duplicated_openers


def test_a_duplicated_opener_collapses_to_one() -> None:
    """The old site's custom-screens wart: the media opener written
    twice, balanced only by the stylesheet ending."""
    css = (
        "@media (max-width: 1023px) {\n"
        "@media (max-width: 1023px) {\n"
        ".app-lightbox {\npadding: 16px;\n}\n"
        "}\n"
    )
    assert collapse_duplicated_openers(css) == (
        "@media (max-width: 1023px) {\n.app-lightbox {\npadding: 16px;\n}\n}\n"
    )


def test_indentation_of_the_first_occurrence_is_kept() -> None:
    css = "  @media (x) {\n  @media (x) {\n  .a { b: c }\n  }"
    assert collapse_duplicated_openers(css).startswith("  @media (x) {\n  .a")


def test_openers_that_are_not_adjacent_are_untouched() -> None:
    css = "@media (x) {\n.a { b: c }\n}\n@media (x) {\n.d { e: f }\n}"
    assert collapse_duplicated_openers(css) == css


def test_a_blank_line_between_duplicates_blocks_the_collapse() -> None:
    """Strictly adjacent only: the deliberate nesting shape (with its
    own closers) must not be silently rewritten."""
    css = "@media (x) {\n\n@media (x) {\n.a { b: c }\n}\n}"
    assert collapse_duplicated_openers(css) == css


def test_only_block_open_lines_collapse() -> None:
    """A repeated declaration line is content, not an opener."""
    css = ".a {\ncolor: red;\ncolor: red;\n}"
    assert collapse_duplicated_openers(css) == css


def test_a_triple_opener_collapses_to_one() -> None:
    css = "@media (x) {\n@media (x) {\n@media (x) {\n.a { b: c }\n}"
    assert collapse_duplicated_openers(css).count("@media") == 1
