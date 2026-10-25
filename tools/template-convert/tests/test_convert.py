"""Tests for the volume plumbing and the selection/context fixes (#70)."""

from __future__ import annotations

from pathlib import Path

from md_template_convert.convert import BASE_VOLUME, Converter, _fold_inline
from md_template_convert.csslib import Minter


def _converter(tmp_path: Path) -> Converter:
    """A converter over an empty theme directory: no stylesheets, so the
    volume plumbing under test is the only thing exercised."""
    return Converter(tmp_path, tmp_path, tmp_path, {})


#: The old main.css baseline, abridged to the rules #70 names.
BASELINE_CSS = """\
html {
  font-size: 62.5%;
}
body {
  font-family: var(--font-body-family);
}
h1, h2, h3, h4, h5, h6,
.h1, .h2, .h3, .h4, .h5, .h6 {
  font-family: var(--font-heading-family);
  margin: 0;
}
.link, a {
  text-decoration: none;
}
img {
  max-width: 100%;
}
xo-container {
  display: block;
  max-width: var(--xo-container-width, 1400px);
}
#xo-root {
  overflow: hidden;
  display: flow-root;
}
.xo-headline {
  font-size: 42px;
}
.breadcrumb__link {
  color: rgba(var(--color-foreground));
}
"""

#: The old home page's rendered ``settings-adapter`` head block, abridged
#: (HTTP GET of https://metalpartition.com/, 2026-10-26).
REAL_HEAD_BLOCK = """\
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
      --font-heading-family: Playfair Display , san-serif;
      --font-body-scale: 1.0;
      --font-heading-scale: 1.4;
      --spacing-sections-desktop: 0px;
      --spacing-sections-mobile: 0px;
    }
</style>
"""


def _theme(tmp_path: Path, css: str = BASELINE_CSS) -> Path:
    theme = tmp_path / "theme"
    theme.mkdir(exist_ok=True)
    (theme / "main.css").write_text(css, encoding="utf-8")
    return theme


def _page(
    tmp_path: Path, body: str, head: str = REAL_HEAD_BLOCK, name: str = "page"
) -> Path:
    raw_dir = tmp_path / "raw"
    raw_dir.mkdir(exist_ok=True)
    raw = raw_dir / f"{name}.html"
    raw.write_text(
        "<!doctype html><html><head>"
        + head
        + "</head><body>"
        + body
        + "</body></html>",
        encoding="utf-8",
    )
    return raw


def _section(key: str, inner: str) -> str:
    return f'<div id="shopify-section-t__{key}" class="shopify-section">{inner}</div>'


# -- #70 C1/C5: the inline fold, both quote styles -------------------------


def test_a_single_quoted_inline_style_is_folded() -> None:
    """Real markup (old home, the nav label): a single-quoted class and a
    single-quoted style — the pair the old tokenizer never saw."""
    minter = Minter()
    tag = (
        "<h3\n  class='xo-menu-horizontal-v1__label'\n"
        "  style='; font-size: 13px\n  '\n>"
    )
    out = _fold_inline(tag, minter)
    minted = minter.mint("; font-size: 13px\n  ")
    assert out == ("<h3\n  class='xo-menu-horizontal-v1__label " + minted + "'\n>")
    assert "style=" not in out


def test_a_single_quoted_class_and_a_double_quoted_style_keep_one_class() -> None:
    """Real markup (old home, the sub-menu): the fold used to append a
    second ``class`` attribute, which HTML ignores."""
    minter = Minter()
    tag = (
        "<ul class='xo-menu-horizontal-v1__sub-menu list-unstyled'"
        ' style="background-color: #fff">'
    )
    out = _fold_inline(tag, minter)
    assert out.count("class=") == 1
    assert "list-unstyled md-t" in out
    assert "style=" not in out


def test_the_minted_class_joins_the_first_class_attribute() -> None:
    """Real markup (old home, the header wrapper): the theme itself
    repeats the attribute; HTML honours the first, so the minted class
    must not go to the second."""
    minter = Minter()
    tag = (
        '<div class="xo-header-v9__inner" '
        'class="xo-section color-background-1" style="--bg-color: #ffffff;">'
    )
    out = _fold_inline(tag, minter)
    assert out.count("class=") == 2  # the authored duplicate, no third
    assert out.startswith('<div class="xo-header-v9__inner md-t')


def test_a_tag_without_a_class_gets_one() -> None:
    minter = Minter()
    out = _fold_inline('<img src="/x.webp" style="max-width: 10px">', minter)
    minted = minter.mint("max-width: 10px")
    assert out == f'<img src="/x.webp" class="{minted}">'


def test_a_self_closing_tag_is_closed_after_the_new_class() -> None:
    minter = Minter()
    out = _fold_inline('<img src="/x.webp" style="width: 10px" />', minter)
    minted = minter.mint("width: 10px")
    assert out.endswith(f'class="{minted}"/>')


def test_a_binding_attribute_is_not_the_class_attribute() -> None:
    """Alpine's ``x-bind:class`` is not the element's class; the fold
    creates the plain class attribute it needs."""
    minter = Minter()
    out = _fold_inline(
        '<div x-bind:class="\'hidden\'" style="color: red">x</div>', minter
    )
    assert "x-bind:class=\"'hidden'\"" in out
    assert out.startswith('<div x-bind:class="\'hidden\'" class="md-t')
    assert "style=" not in out


def test_a_commented_out_tag_is_not_folded() -> None:
    minter = Minter()
    body = '<!-- <div style="color: red"> --><p>text</p>'
    assert _fold_inline(body, minter) == body


def test_an_attribute_value_may_hold_the_tag_closer() -> None:
    minter = Minter()
    out = _fold_inline('<div title="a > b" style="color: red">x</div>', minter)
    assert 'title="a > b"' in out
    assert "style=" not in out
    assert 'class="md-t' in out


def test_a_multi_line_style_value_survives_the_tag_scan() -> None:
    minter = Minter()
    out = _fold_inline(
        "<div\n  style='background-color: #fff;\n  color: #000'\n>x</div>", minter
    )
    assert "style=" not in out
    assert minter.mint("background-color: #fff;\n  color: #000") in out


def test_a_style_token_inside_another_attribute_is_not_an_attribute() -> None:
    """A handler body (``this.style='…'``) is text: the attribute walker
    skips quoted values, so only the element's own ``style`` attribute is
    folded."""
    minter = Minter()
    body = '<a href="#" onclick="this.style=\'color:red\'" style="color: blue">x</a>'
    out = _fold_inline(body, minter)
    assert "this.style='color:red'" in out
    assert 'style="color: blue"' not in out
    assert minter.mint("color: blue") in out


def test_an_unquoted_class_value_survives_as_a_quoted_one() -> None:
    minter = Minter()
    out = _fold_inline("<div class=foo style='color: red'>x</div>", minter)
    minted = minter.mint("color: red")
    assert f'class="foo {minted}"' in out


# -- #70 C2/C3: the base volume -------------------------------------------


def test_the_base_volume_carries_the_head_tokens_and_the_class_less_rules(
    tmp_path: Path,
) -> None:
    raw = _page(
        tmp_path=tmp_path,
        body=_section("one", '<div class="xo-headline">x</div>'),
    )
    converter = Converter(raw.parent, _theme(tmp_path), tmp_path / "plugin", {})
    converter.convert_page(raw, "home")
    converter.distribute()
    base = "\n".join(converter.volume_rules[BASE_VOLUME])
    assert "--font-heading-family: Playfair Display , san-serif;" in base
    assert "--font-heading-scale: 1.4;" in base
    assert "--color-foreground: 0,0,0;" in base
    assert "html { font-size: 62.5%; }" in base
    assert "a { text-decoration: none; }" in base
    assert "img {" in base
    assert "xo-container {" in base
    assert "#xo-root {" in base
    assert "h1, h2, h3, h4, h5, h6 {" in base


def test_the_class_distribution_is_unchanged_by_the_base_volume(
    tmp_path: Path,
) -> None:
    """The base pass adds a volume; a class still lands exactly where the
    page plan puts it (a breadcrumb section's class → the breadcrumb
    component volume, a page-only class → the page's template volume)."""
    raw = _page(
        tmp_path=tmp_path,
        body=(
            _section("breadcrumb-v2", '<a class="breadcrumb__link">x</a>')
            + _section("text-band", '<h1 class="xo-headline">x</h1>')
        ),
    )
    converter = Converter(raw.parent, _theme(tmp_path), tmp_path / "plugin", {})
    converter.convert_page(raw, "family-stainless-steel")
    converter.distribute()
    assert any(
        "breadcrumb__link" in rule for rule in converter.volume_rules["breadcrumb"]
    )
    assert any(
        "xo-headline" in rule
        for rule in converter.volume_rules["family-stainless-steel"]
    )
    assert not any(
        "breadcrumb__link" in rule or "xo-headline" in rule
        for rule in converter.volume_rules.get(BASE_VOLUME, [])
    )


def test_the_head_tokens_are_ingested_once_across_pages(tmp_path: Path) -> None:
    first = _page(tmp_path, _section("one", "<p>a</p>"), name="first")
    second = _page(tmp_path, _section("two", "<p>b</p>"), name="second")
    converter = Converter(first.parent, _theme(tmp_path), tmp_path / "plugin", {})
    converter.convert_page(first, "home")
    converter.convert_page(second, "family-stainless-steel")
    converter.distribute()
    base = "\n".join(converter.volume_rules[BASE_VOLUME])
    assert base.count("--font-heading-scale: 1.4;") == 1


def test_inline_styles_become_important_minted_rules_in_the_page_volume(
    tmp_path: Path,
) -> None:
    """End to end: the nav label's inline font-size leaves the markup and
    lands in the page's volume with the inline cascade position."""
    raw = _page(
        tmp_path,
        _section(
            "hero-1",
            "<h3\n  class='xo-menu-horizontal-v1__label'\n"
            "  style='; font-size: 13px\n  '\n>x</h3>",
        ),
    )
    converter = Converter(raw.parent, _theme(tmp_path), tmp_path / "plugin", {})
    report = converter.convert_page(raw, "home")
    converter.distribute()
    assert "style=" not in str(report["html"])
    rules = "\n".join(converter.volume_rules["home"])
    assert "font-size: 13px !important" in rules


def test_a_page_without_a_head_still_converts(tmp_path: Path) -> None:
    raw = _page(tmp_path, _section("one", "<p>a</p>"), head="")
    converter = Converter(raw.parent, _theme(tmp_path), tmp_path / "plugin", {})
    converter.convert_page(raw, "home")
    converter.distribute()
    base = "\n".join(converter.volume_rules[BASE_VOLUME])
    assert "html { font-size: 62.5%; }" in base
    assert "--font-heading-scale" not in base


# -- the volume plumbing (unchanged contract) -----------------------------


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
