"""Tests for the slot passes."""

from __future__ import annotations

import pytest

from md_template_convert.slots import pdp_sample, slot_grid, slot_pdp

# A distilled PDP body, shaped like the old site's: breadcrumb link,
# hero with the three images, the spec strip, the identity block, the
# where-works rows and the related cards.
PDP_BODY = """
<a class='breadcrumb__link' href='/products/akira-architectural-metal-screen-pattern'
   aria-current='page'>Akira | Architectural Metal Screen Pattern</a>
<div class="pdp-hero-bg" aria-hidden="true"><img src="//cdn.shopify.com/s/files/1/0/hero.jpg?v=1&width=2400"></div>
<span class="pdp-eyebrow">asian pattern</span>
<h1>Akira <span class="pdp-h1-sub">Architectural Metal Screen Pattern</span></h1>
<p class="pdp-hero-desc">Built for light behavior, privacy, and surface impact in Decorative screening.</p>
<div class="pdp-hero-plate gs-reveal"><img src="//cdn.shopify.com/s/files/1/0/plate.png?width=1000" alt="Akira | Architectural Metal Screen Pattern finish preview"></div>
<dl class="pdp-spec-strip gs-reveal">
  <div><dt>Pattern code</dt><dd>Akira</dd></div>
  <div><dt>Style</dt><dd>asian</dd></div>
  <div><dt>Open area</dt><dd>41%</dd></div>
  <div><dt>Material route</dt><dd>304 / 316 stainless</dd></div>
  <div class="pdp-spec-wide"><dt>Best fit</dt><dd>Decorative screening</dd></div>
</dl>
<span class="pdp-eyebrow">Design intent</span>
<h2>Visual rhythm and light behavior</h2>
<p class="pdp-body">Asian geometry gives this screen its project read.</p>
<p class="pdp-body">Filtered light and pattern shadow</p>
<figure class="pdp-square gs-reveal"><img src="//cdn.shopify.com/s/files/1/0/square.png?width=1200"></figure>
<div class="pdp-apps-rows">
  <article class="pdp-app-row gs-reveal"><h3>Exterior facades</h3><p>Reads at building scale.</p></article>
  <article class="pdp-app-row gs-reveal"><h3>Interior dividers</h3><p>Separates zones.</p></article>
</div>
<div class="pdp-related-grid">
  <a href="/products/tiger-lily-architectural-metal-screen-pattern" class="pdp-related-card gs-reveal">
    <figure><img src="//cdn.shopify.com/s/files/1/0/tiger.jpg?width=800" alt="Tiger Lily"></figure>
    <h3>Tiger Lily Decorative Screening Panel</h3><span>Compare density and rhythm</span>
  </a>
  <a href="/products/other-architectural-metal-screen-pattern" class="pdp-related-card gs-reveal">
    <figure><img src="//cdn.shopify.com/s/files/1/0/other.jpg?width=800" alt="Other"></figure>
    <h3>Other</h3><span>Compare density and rhythm</span>
  </a>
</div>
<section id="faq">
  <div class="pdp-faq-list gs-reveal"><div class="pdp-faq-item"><p>Q1</p></div></div>
</section>
"""


def test_pdp_sample_extracts_every_anchor() -> None:
    sample = pdp_sample(PDP_BODY)
    assert sample["handle_url"] == "/products/akira-architectural-metal-screen-pattern"
    assert sample["title"] == "Akira | Architectural Metal Screen Pattern"
    assert sample["code"] == "Akira"
    assert sample["style"] == "asian"
    assert sample["open_area"] == "41%"
    assert sample["best_fit"] == "Decorative screening"
    assert "hero.jpg" in sample["hero_bg_url"]
    assert "plate.png" in sample["plate_url"]
    assert "square.png" in sample["square_url"]


def test_pdp_sample_refuses_a_missed_anchor() -> None:
    with pytest.raises(ValueError, match="missed"):
        pdp_sample("<p>no anchors here</p>")


def test_slot_pdp_replaces_every_sample_value() -> None:
    out = slot_pdp(PDP_BODY, pdp_sample(PDP_BODY))
    assert "Akira" not in out.replace("Architectural Metal Screen Pattern", "")
    assert "[lc_the_permalink]" in out
    assert "[lc_wc_product" in out
    assert '[md_pdp_design_intent slot="hero"]' in out
    assert '[md_pdp_design_intent slot="lead"]' in out
    assert '[md_pdp_design_intent slot="supplemental"]' in out
    assert "[md_pdp_open_area]" in out
    assert "[md_pdp_material]" in out
    assert "[md_pdp_best_fit]" in out
    assert '[md_pdp_image slot="hero-bg"]' in out
    assert '[md_pdp_image slot="plate"]' in out
    assert '[md_pdp_image slot="square"]' in out
    assert '[lc_get_partial slug="md-where-works"]' in out
    assert '[lc_get_partial slug="md-pdp-faq"]' in out
    assert "[md_pdp_related]" in out
    assert "Tiger Lily" not in out, (
        "the sample's related cards collapse into the assembly"
    )


def test_slot_grid_collapses_repeated_cards_into_the_loop() -> None:
    html = (
        '<div class="grid">'
        + "".join(
            f'<a href="/products/p{i}" class="pattern-card__link"><figure>'
            f'<img src="//cdn.shopify.com/s/files/1/0/p{i}.png?width=600" alt="P{i}"></figure>'
            f'<div class="pattern-card__value--title">P{i}</div></a>'
            for i in range(4)
        )
        + "</div>"
    )
    out = slot_grid(html)
    assert out.count("[lc_loop]") == 1
    assert "/products/p3" not in out
    assert "[lc_the_permalink]" in out
    assert "[lc_the_thumbnail_url]" in out
    assert "[lc_the_title]" in out
