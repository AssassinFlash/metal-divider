"""Tests for the mechanical transforms."""

from __future__ import annotations

from md_template_convert.transform import (
    asset_name,
    map_assets,
    root_relative,
    strip_scaffolding,
    strip_scripts,
)


def test_asset_name_keeps_the_width_from_the_query() -> None:
    url = "//metalpartition.com/cdn/shop/files/hotel-lobby.webp?v=1781&width=2400"
    assert asset_name(url) == "hotel-lobby--2400w.webp"


def test_asset_name_keeps_the_width_from_the_suffix() -> None:
    url = "https://cdn.shopify.com/s/files/1/0000/foo_480x480.png?v=1"
    assert asset_name(url) == "foo--480w.png"


def test_asset_name_slugifies_the_stem() -> None:
    url = "//cdn.shopify.com/s/files/1/0000/Alhambra_Star.PNG"
    assert asset_name(url) == "alhambra-star.png"


def test_map_assets_uses_the_pipeline_answer_when_it_has_one() -> None:
    html = '<img src="//cdn.shopify.com/s/files/1/0000/a.png?v=1">'
    mapping = {"https://cdn.shopify.com/s/files/1/0000/a.png?v=1": "a--2048w.webp"}
    out, count = map_assets(html, mapping)
    assert count == 1
    assert out == '<img src="/wp-content/uploads/a--2048w.webp">'


def test_map_assets_falls_back_to_the_derived_stem() -> None:
    html = '<img src="//metalpartition.com/cdn/shop/files/b.jpg?width=800">'
    out, _ = map_assets(html, {})
    assert out == '<img src="/wp-content/uploads/b--800w.jpg">'


def test_root_relative_rewrites_the_old_host() -> None:
    assert root_relative('<a href="https://metalpartition.com/pages/x">x</a>') == (
        '<a href="/pages/x">x</a>'
    )


def test_strip_scripts_removes_every_script() -> None:
    out, count = strip_scripts(
        "<p>a</p><script>x()</script><p>b</p><script src='c'></script>"
    )
    assert count == 2
    assert "script" not in out
    assert "<p>a</p>" in out


def test_strip_scaffolding_removes_shopify_jsonld() -> None:
    html = '<script type="application/ld+json">{"@type":"Product"}</script><p>kept</p>'
    out, counts = strip_scaffolding(html)
    assert counts == {"shopify-jsonld": 1}
    assert "<p>kept</p>" in out
    assert "ld+json" not in out
