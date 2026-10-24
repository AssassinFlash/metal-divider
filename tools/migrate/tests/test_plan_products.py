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
        "source_site": "parasoleil",
        "source_slug": "agave-slug",
        "source_manifest": '{"primary_source":"parasoleil"}',
        "openness_inferred_from": "yes-pattern",
        "openness_confidence": 0.35,
    }
    assert agave["seo"]["title"] == "Agave Metal Screen Pattern"
    # The Woo settings the stage writes (revision 5's /pattern/ base).
    assert plan["settings"]["product_base"] == "/pattern/"
    assert plan["settings"]["category_base"] == "collections"


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
