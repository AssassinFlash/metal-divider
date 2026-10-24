"""The diff logic: what makes an item identical, applied or drifted, and
the create sets that carry a whole item in one write."""

from __future__ import annotations

from typing import Any

import fixtures
from driver import migrate


def current_product(item: dict[str, Any], **changes: Any) -> dict[str, Any]:
    """A product exactly as the WordPress side would read the plan's result
    back, with the named fields changed."""
    current = {
        "id": 7,
        **{
            name: item[name]
            for name in ("title", "slug", "content", "excerpt", "series")
        },
        **migrate().PRODUCT_CONSTANTS,
        "acf": dict(item["acf"]),
        "seo": dict(item["seo"]),
    }
    for name, value in changes.items():
        if name.startswith("acf_"):
            current["acf"][name[4:]] = value
        elif name.startswith("seo_"):
            current["seo"][name[4:]] = value
        else:
            current[name] = value
    return current


def test_diff_product_identical() -> None:
    module = migrate()
    item = module.build_products_plan(fixtures.export())["products"][0]
    assert module.diff_product(item, current_product(item)) == (False, {})


def test_diff_product_create() -> None:
    module = migrate()
    item = module.build_products_plan(fixtures.export())["products"][0]
    set_map = module.create_product_set(item)
    assert set_map["create"] is True
    assert set_map["acf_set"] == item["acf"]
    # The empty-price rule rides along: an empty string, never a number.
    assert set_map["regular_price"] == ""


def test_diff_product_only_the_drifted_fields() -> None:
    module = migrate()
    item = module.build_products_plan(fixtures.export())["products"][0]
    differs, set_map = module.diff_product(
        item, current_product(item, title="Old title", acf_visual_density="open")
    )
    assert differs
    assert set_map == {
        "title": item["title"],
        "acf_set": {"visual_density": "balanced"},
    }


def test_diff_product_motifs_ignore_order() -> None:
    module = migrate()
    item = module.build_products_plan(fixtures.export())["products"][0]
    same_order = current_product(item, acf_motifs=list(reversed(item["acf"]["motifs"])))
    assert module.diff_product(item, same_order) == (False, {})


def test_diff_product_none_open_area_is_a_delete() -> None:
    module = migrate()
    plan = module.build_products_plan(fixtures.export())
    item = next(p for p in plan["products"] if p["acf"]["open_area"] is None)
    drifted = current_product(item, acf_open_area=0)
    differs, set_map = module.diff_product(item, drifted)
    assert differs and set_map["acf_delete"] == ["open_area"]
    # Already empty in every honest shape: no write.
    for empty in (None, "", False):
        assert (
            module.diff_product(item, current_product(item, acf_open_area=empty))[0]
            is False
        )


def test_diff_product_seopress_rows() -> None:
    module = migrate()
    item = module.build_products_plan(fixtures.export())["products"][0]
    differs, set_map = module.diff_product(item, current_product(item, seo_title="Old"))
    assert set_map["seo_title"] == item["seo"]["title"]


def test_diff_term_and_redirect() -> None:
    module = migrate()
    term = module.build_terms_plan(fixtures.export())["terms"][0]
    same = {"name": term["name"], "meta": dict(term["meta"])}
    assert module.diff_term(term, same) == (False, {})
    differs, set_map = module.diff_term(term, {"name": "X", "meta": dict(term["meta"])})
    assert set_map == {"name": term["name"]}
    redirect = {"source": "/pages/contact", "target": "/inquiry/"}
    current = {
        "enabled": "yes",
        "type": "301",
        "value": "/inquiry/",
        "logged_status": "both",
    }
    assert module.diff_redirect(redirect, current) == (False, {})
    differs, set_map = module.diff_redirect(redirect, {**current, "type": "302"})
    assert (differs, set_map) == (True, {"type": "301"})
    rule = {"source": "^/products/(.+)$", "target": "/pattern/$1", "regex": True}
    assert module.diff_redirect(rule, current)[1] == {
        "value": "/pattern/$1",
        "regex": "yes",
    }
    assert module.create_redirect_set(rule) == {
        "enabled": "yes",
        "type": "301",
        "value": "/pattern/$1",
        "logged_status": "both",
        "regex": "yes",
    }


def test_media_diff_imports_binds_and_fills() -> None:
    module = migrate()
    product = {
        "sku": "Agave",
        "handle": "agave-architectural-metal-screen-pattern",
        "media": [
            {
                "master_src": "https://cdn/a",
                "role": "finish preview",
                "alt": "A",
                "sha256": "sha-a",
                "seq": 1,
                "variants": [
                    {"w": 480, "file": "a-480.webp"},
                    {"w": 1024, "file": "a-1024.webp"},
                ],
            },
            {
                "master_src": "https://cdn/b",
                "role": "accent render",
                "alt": "B",
                "sha256": "sha-b",
                "seq": 2,
                "variants": [{"w": 480, "file": "b-480.webp"}],
            },
        ],
    }
    # Nothing there: both import, the featured and the gallery bind.
    change = module.media_product_diff(product, None)
    assert change["import"] == product["media"]
    assert change["bind"] == {
        "thumbnail": "https://cdn/a",
        "gallery": ["https://cdn/b"],
    }
    # All present and bound: identical.
    present = {
        "attachments": {
            "https://cdn/a": {
                "id": 11,
                "alt": "A",
                "role": "finish preview",
                "sha256": "sha-a",
                "variants": [{"w": 480}],
            },
            "https://cdn/b": {
                "id": 12,
                "alt": "B",
                "role": "accent render",
                "sha256": "sha-b",
                "variants": [{"w": 480}],
            },
        },
        "thumbnail": "https://cdn/a",
        "gallery": ["https://cdn/b"],
    }
    change = module.media_product_diff(product, present)
    assert not change["import"] and not change["fill"] and not change["bind"]
    # A present attachment with missing meta gets only the fill, never a
    # second import; a swapped gallery order is a binding fix.
    half = {
        "attachments": {
            "https://cdn/a": {
                "id": 11,
                "alt": "A",
                "role": "",
                "sha256": "sha-a",
                "variants": [],
            },
        },
        "thumbnail": "https://cdn/a",
        "gallery": [],
    }
    change = module.media_product_diff(product, half)
    assert change["import"] == [product["media"][1]]
    assert change["fill"] == [
        {
            "master_src": "https://cdn/a",
            "role": "finish preview",
            "variants": [
                {"w": 480, "f": "a-480.webp"},
                {"w": 1024, "f": "a-1024.webp"},
            ],
        }
    ]
    assert change["bind"] == {"gallery": ["https://cdn/b"]}


def test_media_diff_refuses_a_replaced_file() -> None:
    module = migrate()
    product = {
        "sku": "Agave",
        "handle": "h",
        "media": [
            {
                "master_src": "https://cdn/a",
                "role": "r",
                "alt": "A",
                "sha256": "sha-a",
                "seq": 1,
                "variants": [],
            }
        ],
    }
    replaced = {
        "attachments": {
            "https://cdn/a": {
                "id": 11,
                "alt": "A",
                "role": "r",
                "sha256": "different",
                "variants": [],
            }
        },
        "thumbnail": "https://cdn/a",
        "gallery": [],
    }
    try:
        module.media_product_diff(product, replaced)
    except module.MigrationError as error:
        assert "sha256" in str(error)
    else:
        raise AssertionError("a replaced file must refuse")
