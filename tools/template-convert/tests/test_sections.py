"""Tests for the section scanner and the page-head reader."""

from __future__ import annotations

from md_template_convert.sections import head_style_blocks, section_html, sections


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


#: The old home page's head, abridged to the three shapes it carries
#: (HTTP GET of https://metalpartition.com/, 2026-10-26): the checkout
#: widget's block, the rendered ``settings-adapter`` block, an empty one.
REAL_HEAD = """\
<!doctype html><html><head>
<style id="shopify-accelerated-checkout-cart">.x{color:red}</style>
<style data-shopify>
      :root,
      .color-background-1 {
        --color-background: 255,255,255;
        --gradient-background: #ffffff;
      --color-accent: 212,175,55;
      --color-foreground: 0,0,0;
    }

    :root {
      --heading-hero: 56px;
      --font-body-family: Inter , san-serif;
      --font-heading-scale: 1.4;
    }

    @media (max-width: 768px) {
      :root {
        --heading-hero: 42px;
      }
    }
</style>
<style></style>
</head><body></body></html>
"""


def test_head_style_blocks_reads_only_the_design_token_block() -> None:
    """#70 C3: the ``settings-adapter`` block lives in the head, outside
    every Shopify section; the platform's own blocks are not carried."""
    blocks = head_style_blocks(REAL_HEAD)
    assert len(blocks) == 1
    assert blocks[0].lstrip().startswith(":root,")
    assert "--heading-hero: 56px;" in blocks[0]
    assert "shopify-accelerated" not in blocks[0]


def test_head_style_blocks_are_empty_without_a_head() -> None:
    assert head_style_blocks('<div id="shopify-section-a__one"></div>') == []


def test_a_head_block_without_tokens_is_not_a_token_block() -> None:
    html = "<html><head><style>body { color: red; }</style></head></html>"
    assert head_style_blocks(html) == []
