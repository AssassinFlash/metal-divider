"""Tests for the volume plumbing of the converter."""

from __future__ import annotations

from pathlib import Path

from md_template_convert.convert import Converter


def _converter(tmp_path: Path) -> Converter:
    """A converter over an empty theme directory: no stylesheets, so the
    volume plumbing under test is the only thing exercised."""
    return Converter(tmp_path, tmp_path, tmp_path, {})


def test_multiline_rules_keep_their_inner_lines() -> None:
    """Two rules that share declaration lines and closing braces keep
    every line: the dedup unit is the rule, not the line.

    This is #49's regression: the per-line dedup dropped the second
    rule's ``}`` and its ``display: flex;`` (both seen in the first
    rule), which unbalanced the volume and starved the second rule.
    """
    converter = _converter(Path("/tmp"))
    converter.add_rules(
        "v",
        ".a {\ndisplay: flex;\ncolor: red;\n}\n.b {\ndisplay: flex;\ncolor: blue;\n}",
    )
    assert converter.volume_rules["v"] == [
        ".a {\ndisplay: flex;\ncolor: red;\n}",
        ".b {\ndisplay: flex;\ncolor: blue;\n}",
    ]


def test_a_repeated_rule_deduplicates_as_one_unit() -> None:
    """The same rule added twice (two pages carrying the same style
    block) drops whole the second time — the dedup still works."""
    converter = _converter(Path("/tmp"))
    rule = ".a {\ndisplay: flex;\n}"
    converter.add_rules("v", rule)
    converter.add_rules("v", rule + "\n.b { color: red; }")
    assert converter.volume_rules["v"] == [rule, ".b { color: red; }"]


def test_a_media_wrapper_is_one_unit() -> None:
    """A multi-rule media block stays one unit, so it can only drop as
    a whole — never leaving an inner rule's brace behind."""
    converter = _converter(Path("/tmp"))
    block = "@media (max-width: 1399px) {\n.a { width: 100%; }\n.b {\nmargin: 0;\n}\n}"
    converter.add_rules("v", block)
    converter.add_rules("v", block)
    assert converter.volume_rules["v"] == [block]


def test_indentation_and_blank_lines_are_stripped_per_line() -> None:
    """The emitted shape is unchanged by the fix: stripped lines joined
    by newlines, exactly what the per-line form produced."""
    converter = _converter(Path("/tmp"))
    converter.add_rules("v", "  .a {\n    color: red;\n  }\n\n  .b { x: 1 }\n")
    assert converter.volume_rules["v"] == [".a {\ncolor: red;\n}", ".b { x: 1 }"]


def test_a_variant_of_a_carried_rule_keeps_its_differing_lines() -> None:
    """Two pages carrying the same section with one value changed: the
    variant rule is a different unit and survives whole — the per-line
    form kept only the lines the first copy had not shown."""
    converter = _converter(Path("/tmp"))
    converter.add_rules("v", "#s .a {\nbackground: #faf9f7;\npadding: 15px;\n}")
    converter.add_rules("v", "#s .a {\nbackground: #ffffff;\npadding: 15px;\n}")
    assert len(converter.volume_rules["v"]) == 2
    assert "#ffffff" in converter.volume_rules["v"][1]


def test_empty_css_adds_nothing(tmp_path: Path) -> None:
    converter = _converter(tmp_path)
    converter.add_rules("v", "\n\n  \n")
    assert converter.volume_rules == {"v": []}
