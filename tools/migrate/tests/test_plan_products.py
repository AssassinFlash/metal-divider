"""The terms and products plan builders: the gradient re-homing, the meta
mapping of spec 06 §9 and the Woo shape every pattern gets."""

from __future__ import annotations

import fixtures
import pytest
from driver import migrate


def test_terms_covers_nine_series_with_gradient_restored() -> None:
    plan = migrate().build_terms_plan(fixtures.export())
    slugs = [term["slug"] for term in plan["terms"]]
    assert slugs == [
        "pattern-abstract",
        "pattern-asian",
        "pattern-geometric",
        "pattern-gradient",
        "pattern-landscape",
        "pattern-moroccan-arabic",
        "pattern-organic",
        "pattern-perforated",
        "pattern-vintage-retro",
    ]
    # pattern_materials is dropped (owner ruling revision 6), family becomes
    # the term name, difficulty becomes the ACF choice key.
    gradient = next(t for t in plan["terms"] if t["slug"] == "pattern-gradient")
    assert gradient["name"] == "Gradient"
    assert gradient["meta"]["difficulty_level"] == "complex"
    assert set(gradient["meta"]) == {
        "accent_color",
        "difficulty_level",
        "typical_applications",
        "bottom_content",
    }


def test_terms_re_homes_the_gradient_patterns() -> None:
    plan = migrate().build_terms_plan(fixtures.export())
    # La Pente carries the geometric tag in the export; the owner ruling
    # (issue #25) moves it to gradient.
    assert plan["expected_members"]["pattern-gradient"] == 1
    assert plan["expected_members"]["pattern-geometric"] == 0


def test_terms_rejects_an_unknown_series() -> None:
    export = fixtures.export()
    export["products"][0]["metafields"]["nodes"] = [
        node
        for node in export["products"][0]["metafields"]["nodes"]
        if node["key"] != "pattern_style"
    ] + [
        {
            "namespace": "custom",
            "key": "pattern_style",
            "value": "nouveau",
            "type": "single_line_text_field",
        }
    ]
    with pytest.raises(migrate().MigrationError, match="not a collection"):
        migrate().build_terms_plan(export)


def test_products_maps_the_spec06_table() -> None:
    plan = migrate().build_products_plan(fixtures.export())
    agave = next(p for p in plan["products"] if p["sku"] == "Agave")
    assert agave["slug"] == "agave-architectural-metal-screen-pattern"
    assert agave["series"] == "pattern-abstract"
    assert agave["content"] == "<p>Real body.</p>"
    # The summary lands twice: the ACF field is the data, the excerpt is the
    # PDP short slot.
    assert agave["excerpt"] == agave["acf"]["summary"] == "A summary."
    assert agave["acf"] == {
        "open_area": 33.0,
        "visual_density": "balanced",
        "repeat_behavior": "repeating",
        "motifs": ["abstract", "leaf"],
        "best_fit": "architectural-screening",
        "cad_status": "gated",
        "summary": "A summary.",
        "inspiration": "The inspiration.",
        # The old PDP's design-intent second paragraph: retained product
        # data (146/146 in the export), not one of the dropped metafields.
        "notable_effect": "Filtered light",
        "source_site": "parasoleil",
        "source_slug": "agave-slug",
        "source_manifest": '{"primary_source":"parasoleil"}',
        "openness_inferred_from": "yes-pattern",
        "openness_confidence": 0.35,
    }
    assert agave["seo"]["title"] == "Agave Metal Screen Pattern"
    # The Woo settings the stage writes (revision 5's /pattern/ base).
    assert plan["settings"]["product_base"] == "/pattern/"
    assert plan["settings"]["category_base"] == "pattern-category"


def test_products_empty_open_area_and_custom_density() -> None:
    plan = migrate().build_products_plan(fixtures.export())
    birdblind = next(p for p in plan["products"] if p["sku"] == "Birdblind C")
    # No openness in the export: null open_area (never 0), custom density,
    # best_for mapped to the decorative key, the provenance kept.
    assert birdblind["acf"]["open_area"] is None
    assert birdblind["acf"]["visual_density"] == "custom"
    assert birdblind["acf"]["best_fit"] == "decorative-screening"
    assert birdblind["acf"]["openness_inferred_from"] == "ref-pattern"
    assert birdblind["acf"]["openness_confidence"] == 0.05
    assert birdblind["excerpt"] == ""


def test_products_rejects_duplicate_sku() -> None:
    export = fixtures.export()
    export["products"][1]["metafields"]["nodes"] = [
        node if node["key"] != "pattern_code" else node | {"value": "Agave"}
        for node in export["products"][1]["metafields"]["nodes"]
    ]
    with pytest.raises(migrate().MigrationError, match="duplicate SKU"):
        migrate().build_products_plan(export)


def test_products_rejects_unknown_best_for() -> None:
    export = fixtures.export()
    export["products"][0]["metafields"]["nodes"] = [
        node | {"value": "Everything"} if node["key"] == "best_for" else node
        for node in export["products"][0]["metafields"]["nodes"]
    ]
    with pytest.raises(migrate().MigrationError, match="unknown best_for"):
        migrate().build_products_plan(export)


def test_products_drops_a_material_word_from_both_title_strings() -> None:
    """The display name and the SEOPress title are the same name in two
    places: the PDP h1, the breadcrumb, the catalog card's alt and the
    Product schema all read post_title, so a material word left in either
    string would still reach a customer. The SKU (pattern_code) and the slug
    (handle) are identity, not display, and stay verbatim."""
    export = fixtures.export()
    export["products"][0]["title"] = "Aztec Corten | Architectural Metal Screen Pattern"
    export["products"][0]["seo"]["title"] = "Aztec Corten Metal Screen Pattern"
    export["products"][0]["metafields"]["nodes"] = [
        node | {"value": "Aztec Corten"} if node["key"] == "pattern_code" else node
        for node in export["products"][0]["metafields"]["nodes"]
    ]

    plan = migrate().build_products_plan(export)
    aztec = next(p for p in plan["products"] if p["source_code"] == "Aztec Corten")

    # The SKU is the pattern's code and the PDP renders it as the h1
    # ([lc_wc_product get="sku"]), so it carries the corrected name too —
    # and it still equals pattern_code, normalized the same way.
    assert aztec["sku"] == "Aztec"
    assert aztec["title"] == "Aztec | Architectural Metal Screen Pattern"
    assert aztec["seo"]["title"] == "Aztec Metal Screen Pattern"
    assert aztec["source_code"] == "Aztec Corten", "the old code stays traceable"
    assert aztec["slug"] == "agave-architectural-metal-screen-pattern", (
        "the URL comes from the export handle and does not move"
    )


def test_products_leaves_clean_titles_byte_identical() -> None:
    """A title without a superseded word is untouched, so a second plan run
    over an already-clean corpus is a no-op."""
    plan = migrate().build_products_plan(fixtures.export())
    agave = next(p for p in plan["products"] if p["sku"] == "Agave")
    assert agave["title"] == "Agave | Architectural Metal Screen Pattern"
    assert agave["seo"]["title"] == "Agave Metal Screen Pattern"


def test_products_skips_the_material_word_in_every_visible_field() -> None:
    """The PDP renders more than the title: the body, the summary (excerpt
    and ACF), and the SEOPress description are customer-visible too. Assert
    the whole visible set at once, because a fix that covers one field and
    misses the others is the failure mode this guards."""
    export = fixtures.export()
    product = export["products"][0]
    product["title"] = "Aztec Corten | Architectural Metal Screen Pattern"
    product["bodyHtml"] = "<p>The Aztec Corten Decorative Screen reads well.</p>"
    product["seo"] = {
        "title": "Aztec Corten Metal Screen Pattern",
        "description": "Review the Aztec Corten screen for facades.",
    }
    replaced = {
        "pattern_code": "Aztec Corten",
        "pattern_summary": "The Aztec Corten Decorative Screen.",
    }
    product["metafields"]["nodes"] = [
        node | {"value": replaced[node["key"]]} if node["key"] in replaced else node
        for node in product["metafields"]["nodes"]
    ]

    plan = migrate().build_products_plan(export)
    aztec = next(p for p in plan["products"] if p["sku"] == "Aztec")

    assert aztec["content"] == "<p>The Aztec Decorative Screen reads well.</p>"
    assert aztec["excerpt"] == "The Aztec Decorative Screen."
    assert aztec["acf"]["summary"] == "The Aztec Decorative Screen."
    assert aztec["seo"]["description"] == "Review the Aztec screen for facades."


def test_products_sku_still_equals_the_normalized_pattern_code() -> None:
    """The reconcile joins the instance on _sku and proves SKU == pattern_code
    by construction; normalizing one side only would break that identity.
    The products and media plans build their sku through the same helper, so
    the two agree for every product."""
    module = migrate()
    plan = module.build_products_plan(fixtures.export())
    for product in plan["products"]:
        assert product["sku"] == module.drop_material_words(product["source_code"])


def test_notable_effect_is_retained_product_data_not_a_dropped_key() -> None:
    """The old PDP rendered `notable_effect` as the design-intent block's
    second paragraph, so it is product data the target must carry.

    It sat in DROPPED_KEYS, which cost 103 of 146 patterns their second
    paragraph: the plan wrote no ACF value and the reconcile asserted the
    key left no trace. Both halves are guarded here — the plan maps it, and
    the drop list cannot quietly take it back.
    """
    module = migrate()
    plan = module.build_products_plan(fixtures.export())

    assert "notable_effect" not in module.DROPPED_KEYS
    assert "notable_effect" in module.PRODUCT_ACF
    for product in plan["products"]:
        assert "notable_effect" in product["acf"], product["sku"]
        assert isinstance(product["acf"]["notable_effect"], str)


def test_notable_effect_is_verbatim_and_never_material_rewritten() -> None:
    """The measured values are "Filtered light" and "Decorative screening";
    neither names a material, so no rewrite applies. Keeping it verbatim is
    what makes the rendered paragraph byte-identical to the old PDP's."""
    plan = migrate().build_products_plan(fixtures.export())
    agave = next(p for p in plan["products"] if p["sku"] == "Agave")

    assert agave["acf"]["notable_effect"] == "Filtered light"


def test_notable_effect_is_empty_string_when_the_export_carries_none() -> None:
    """A pattern with no metafield still gets the key, as an empty string:
    the ACF field then reads empty, the template's guard leaves the
    paragraph out, and no fixture has to special-case a missing key."""
    export = fixtures.export()
    for product in export["products"]:
        nodes = product["metafields"]["nodes"]
        product["metafields"]["nodes"] = [
            node for node in nodes if node["key"] != "notable_effect"
        ]

    plan = migrate().build_products_plan(export)

    for product in plan["products"]:
        assert product["acf"]["notable_effect"] == ""
