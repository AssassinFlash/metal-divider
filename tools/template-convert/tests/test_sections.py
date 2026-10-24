"""Tests for the section scanner."""

from __future__ import annotations

from md_template_convert.sections import section_html, sections


def test_splits_the_wrappers_in_document_order() -> None:
    html = (
        '<div id="shopify-section-a__one" class="shopify-section">first</div>'
        '<div id="shopify-section-a__two">second</div>'
    )
    found = sections(html)
    assert [s.name for s in found] == ["one", "two"]
    assert section_html(html, found[0]) == "first"


def test_balances_nested_divs_of_the_wrapper() -> None:
    html = '<div id="shopify-section-x__outer"><div><div>deep</div></div></div><p>after</p>'
    found = sections(html)
    assert len(found) == 1
    assert section_html(html, found[0]) == "<div><div>deep</div></div>"


def test_balances_a_section_wrapper_with_nested_sections() -> None:
    # The product template's shape: a section wrapper around markup that
    # itself carries section elements.
    html = (
        '<section id="shopify-section-t__pdp">'
        '<section id="cinematic-pdp"><div>body</div></section>'
        "</section>"
    )
    found = sections(html)
    assert len(found) == 1
    assert found[0].name == "pdp"
    assert "<section id=" in section_html(html, found[0])


def test_refuses_an_unbalanced_wrapper() -> None:
    import pytest

    with pytest.raises(ValueError, match="unbalanced"):
        sections('<div id="shopify-section-x__broken"><div>never closed')
