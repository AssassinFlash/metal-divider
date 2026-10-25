"""Tests for csslib's carried-text repairs and its selection/context model.

The excerpts marked *real* are copied from the old site's own files —
``~/Documents/shopify-builder/theme/assets/main.css``,
``tailwind-production.css``, the rendered head of
``https://metalpartition.com/`` (HTTP GET, single quotes preserved), and
the converted header/breadcrumb markup under
``plugins/md-site/content/livecanvas/`` (which is the old markup verbatim).
"""

from __future__ import annotations

from md_template_convert.csslib import (
    IMPORTANT,
    Minter,
    Stylesheet,
    collapse_duplicated_openers,
    collect_classes,
)

# -- #70 C1: the class tokenizer reads both quote styles --------------------

#: Real markup shapes: the shipped header partial (from the old
#: sections/header-v1.liquid + the menu snippets) and the breadcrumb
#: partial's source (family-*.html). The attributes that carry Liquid
#: leftovers are kept byte-for-byte.
REAL_HEADER_MARKUP = """\
<ul class='xo-menu-horizontal-v1 list-unstyled xo-menu-horizontal-v1--hover'>
<div class='xo-menu-hamburger-style1'>
<div
  class='xo-predictive-search'
  data-locales='
    { pages: "Pages", collections: "Collections" }
  '
>
<div
  class='xo-modal-content'
>
<ul class='xo-menu-horizontal-v1__sub-menu list-unstyled' style="background-color: #fff">
<h3
  class='xo-menu-horizontal-v1__label'
  style='; font-size: 13px
  '
>
"""

REAL_BREADCRUMB_MARKUP = """\
<nav class='breadcrumb' --display-mobile:block' role='navigation' aria-label='breadcrumb'>
<ol class='breadcrumb__list' --justify:flex-start'>
<li class='breadcrumb__item'>
<a class='breadcrumb__link' href='/'>Home</a>
<span class='breadcrumb__separator'></span>
"""


def test_collect_classes_reads_single_and_double_quoted_attributes() -> None:
    assert collect_classes(REAL_HEADER_MARKUP) >= {
        "xo-menu-horizontal-v1",
        "xo-menu-hamburger-style1",
        "xo-predictive-search",
        "xo-modal-content",
    }
    assert collect_classes("<div class=\"double\"><b class='single'>") == {
        "double",
        "single",
    }


def test_collect_classes_ignores_class_bindings_and_prefixed_names() -> None:
    """``x-bind:class``/``data-class`` are not the element's own class
    attribute; the old header carries ``x-bind:class`` on the menu."""
    html = (
        '<div x-bind:class="\'{ hidden: open }\'" data-class="tracking"'
        ' class="real"></div>'
    )
    assert collect_classes(html) == {"real"}


def test_the_whole_header_and_breadcrumb_families_tokenize() -> None:
    """#70 C1's acceptance: the five families the old markup renders with
    single-quoted attributes — four header families and the breadcrumb —
    are present after the fix (the previous tokenizer found none of the
    breadcrumb's and 62 of the header's 109 classes were missing)."""
    families = (
        "xo-menu-horizontal-v1",
        "xo-menu-hamburger-style1",
        "xo-predictive-search",
        "xo-modal-content",
    )
    header = collect_classes(REAL_HEADER_MARKUP)
    for family in families:
        assert family in header, family
    breadcrumb = collect_classes(REAL_BREADCRUMB_MARKUP)
    assert {
        "breadcrumb",
        "breadcrumb__list",
        "breadcrumb__item",
        "breadcrumb__link",
        "breadcrumb__separator",
    } <= breadcrumb


#: One real rule per family, copied from main.css (line numbers there):
#: __label 5685, hamburger 5188, predictive-search 7600, modal 5804,
#: breadcrumb 2163/2191.
REAL_FAMILY_RULES = """\
.xo-menu-horizontal-v1__label {
  font-size: calc(var(--font-heading-scale) * var(--font-body-scale) * 1.3rem);
  margin-bottom: 0;
  color: rgb(var(--color-foreground));
  font-weight: 500;
  text-transform: uppercase;
}
.xo-menu-hamburger-style1 {
  display: flex;
  align-items: center;
}
.xo-predictive-search {
  padding-top: 2rem;
  padding-bottom: 2rem;
  position: relative;
}
.xo-modal-content {
  --gap: 1.5rem;
  display: flex;
  flex-direction: column;
}
.breadcrumb__link {
  color: rgba(var(--color-foreground));
  font-size: calc(var(--font-body-scale) * 1.3rem);
}
"""


def test_each_family_selects_its_old_rules_through_the_tokenizer() -> None:
    """The selection path end to end: the real markup's tokens (both quote
    styles) are what drives ``rules_for_classes``."""
    sheet = Stylesheet("main.css", REAL_FAMILY_RULES)
    classes = collect_classes(REAL_HEADER_MARKUP + REAL_BREADCRUMB_MARKUP)
    for family in (
        "xo-menu-horizontal-v1__label",
        "xo-menu-hamburger-style1",
        "xo-predictive-search",
        "xo-modal-content",
        "breadcrumb__link",
    ):
        assert family in classes
        rules = sheet.rules_for_classes({family})
        assert len(rules) == 1, family
        assert family in rules[0]


def test_a_class_rule_is_not_indexed_under_its_parent_selector() -> None:
    """``.xo-menu-horizontal-v1__label`` is not a rule for class
    ``xo-menu-horizontal-v1`` — the old regex read the token up to the
    first ``__`` correctly, but the point of the token contract is that
    only the rule's own class names index it."""
    sheet = Stylesheet("x", ".xo-menu-horizontal-v1__label { color: red; }")
    assert sheet.rules_for_classes({"xo-menu-horizontal-v1"}) == []
    assert sheet.rules_for_classes({"xo-menu-horizontal-v1__label"}) != []


# -- #70 C2: class-less rules reach the model ------------------------------


def test_a_class_less_rule_lands_in_the_bare_list() -> None:
    sheet = Stylesheet("main.css", "html {\n  font-size: 62.5%;\n}")
    assert sheet.bare_rules() == ["html { font-size: 62.5%; }"]


def test_a_mixed_selector_keeps_its_class_arm_and_contributes_its_bare_arm() -> None:
    """Real main.css:361-363 ``.link, a { text-decoration: none }`` — the
    ``a`` arm is the global no-underline baseline and used to be lost
    because the rule's class (``link``) made it a class rule."""
    sheet = Stylesheet("main.css", ".link, a {\n  text-decoration: none;\n}")
    assert sheet.rules_for_classes({"link"}) == [".link, a { text-decoration: none; }"]
    assert sheet.bare_rules() == ["a { text-decoration: none; }"]


def test_the_heading_reset_keeps_its_element_arms() -> None:
    """Real main.css:1294-1305: ``h1…h6, .h1…h6 { margin: 0 }`` — the
    element arms are the heading baseline the replica lost."""
    css = (
        "h1, h2, h3, h4, h5, h6,\n.h1, .h2, .h3, .h4, .h5, .h6 {\n"
        "  font-family: var(--font-heading-family);\n  margin: 0;\n}\n"
    )
    sheet = Stylesheet("main.css", css)
    bare = sheet.bare_rules()
    assert len(bare) == 1
    assert bare[0].startswith(
        "h1, h2, h3, h4, h5, h6 { font-family: var(--font-heading-family);"
    )
    assert "margin: 0;" in bare[0]
    assert ".h1" not in bare[0]


def test_a_class_less_rule_inside_a_media_query_keeps_its_wrapper() -> None:
    css = (
        "@media (min-width: 768px) {\n  body {\n"
        "    font-size: var(--font-body-desktop-size, 1.6rem);\n  }\n}"
    )
    sheet = Stylesheet("main.css", css)
    assert sheet.bare_rules() == [
        "@media (min-width: 768px) {\n"
        "body { font-size: var(--font-body-desktop-size, 1.6rem); }\n}"
    ]


def test_the_global_baseline_the_replica_needs_is_all_bare() -> None:
    """The seven rules #70 names: html, body, headings, a, img,
    xo-container, #xo-root — real main.css text, one rule each."""
    css = """\
html {
  font-size: 62.5%;
}
body {
  font-family: var(--font-body-family);
  background: rgb(var(--color-background));
}
img {
  max-width: 100%;
  height: auto;
}
xo-container {
  display: block;
  max-width: var(--xo-container-width, 1400px);
}
#xo-root {
  overflow: hidden;
  display: flow-root;
}
.link, a {
  text-decoration: none;
}
"""
    bare = Stylesheet("main.css", css).bare_rules()
    joined = "\n".join(bare)
    for needle in (
        "html {",
        "body {",
        "img {",
        "xo-container {",
        "#xo-root {",
        "a { text-decoration: none; }",
    ):
        assert needle in joined, needle


def test_a_class_less_rule_that_repeats_appears_once() -> None:
    """main.css carries a duplicated normalize block; the base volume
    must not repeat it."""
    css = "body { margin: 0; }\nbody { margin: 0; }\n"
    assert Stylesheet("main.css", css).bare_rules() == ["body { margin: 0; }"]


def test_keyframes_a_bare_rule_references_travel_with_it() -> None:
    css = (
        "@keyframes spin { to { transform: rotate(1turn); } }\n"
        "body { animation: spin 1s linear; }\n"
    )
    bare = Stylesheet("main.css", css).bare_rules()
    assert len(bare) == 2
    assert bare[1].startswith("@keyframes spin")


# -- #70 C4: escaped utility selectors ------------------------------------

#: Real tailwind-production.css text: the old custom px scale, compiled
#: into escaped responsive selectors inside their media wrappers.
REAL_OLD_UTILITIES = (
    "@media (min-width:768px){"
    ".md\\:text-2xl{font-size:24px}"
    ".md\\:text-5xl{font-size:42px}"
    ".md\\:text-6xl{font-size:48px}"
    ".md\\:text-7xl{font-size:56px}"
    "}"
    "@media (min-width:1024px){.lg\\:text-7xl{font-size:56px}}"
)


def test_an_escaped_utility_selector_indexes_by_the_utility_name() -> None:
    sheet = Stylesheet("tailwind-production.css", REAL_OLD_UTILITIES)
    assert "md:text-5xl" in sheet.classes()
    assert "md" not in sheet.classes()
    assert "lg" not in sheet.classes()


def test_the_old_utility_scale_is_carried_for_the_markup_classes() -> None:
    sheet = Stylesheet("tailwind-production.css", REAL_OLD_UTILITIES)
    markup = (
        '<h1 class="lg:text-7xl">t</h1>'
        '<h2 class="md:text-5xl">s</h2>'
        '<p class="md:text-6xl">x</p>'
    )
    rules = "\n".join(sheet.rules_for_classes(collect_classes(markup)))
    assert ".md\\:text-5xl { font-size:42px }" in rules
    assert ".md\\:text-6xl { font-size:48px }" in rules
    assert ".lg\\:text-7xl { font-size:56px }" in rules
    assert "@media (min-width:768px) {" in rules
    assert "@media (min-width:1024px) {" in rules


def test_an_arbitrary_escaped_utility_keeps_its_brackets() -> None:
    """``lg:text-[56px]`` is the family heroes' arbitrary value — it must
    index as that full class, not as ``lg``."""
    sheet = Stylesheet(
        "tailwind-production.css", ".lg\\:text-\\[56px\\]{font-size:56px}"
    )
    assert sheet.classes() == {"lg:text-[56px]"}
    assert sheet.rules_for_classes({"lg:text-[56px]"}) != []


def test_a_class_name_inside_a_functional_pseudo_is_still_indexed() -> None:
    """``.a:not(.b)`` matches on both classes; indexing it under ``b``
    only is not enough for selection."""
    sheet = Stylesheet("x", ".a:not(.b) { color: red; }")
    assert sheet.classes() == {"a", "b"}


# -- #70 C5: the inline fold's cascade policy -----------------------------


def test_minted_declarations_keep_the_inline_cascade_position() -> None:
    minter = Minter()
    name = minter.mint("font-size: 13px")
    assert minter.css() == f".{name} {{ font-size: 13px {IMPORTANT} }}"


def test_the_nav_label_case_stays_above_an_id_rule() -> None:
    """The old nav label's inline ``font-size: 13px`` (real markup) must
    keep the precedence inline had: as a class rule it needs the
    important flag to outrank an ID rule such as the deployed overlay's
    ``#HeaderV9… .xo-menu-horizontal-v1__label { font-size: 1.4rem }``."""
    minter = Minter()
    minter.mint("; font-size: 13px\n")
    assert minter.css().endswith("font-size: 13px !important }")


def test_an_already_important_declaration_is_not_doubled() -> None:
    minter = Minter()
    minter.mint("color: red !important")
    assert minter.css().count("!important") == 1


def test_a_custom_property_keeps_its_importance() -> None:
    minter = Minter()
    minter.mint("--color: #fff; color: var(--color)")
    css = minter.css()
    assert "--color: #fff !important" in css
    assert "color: var(--color) !important" in css


def test_empty_liquid_values_do_not_mint_an_empty_rule() -> None:
    minter = Minter()
    minter.mint("background:;color:")
    assert minter.css() == ""


# -- the carried-text repairs ---------------------------------------------


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
