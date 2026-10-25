"""The slot passes of the three data-driven faces (spec 19 §2.2/§6).

The PDP is one template over 146 products: the pipeline converts the
representative sample's markup, then this pass replaces the sample's
per-product values with the template's data slots — LiveCanvas native
tags first (AGENTS.md §5), the ``md_pdp_*`` shortcodes only where the
native layer has no tag for the value, each with its exhaustion proof
in the shortcode's own docblock.

The series archive and the shop share the pattern-catalog grid: the
repeated product cards collapse into one ``[lc_loop]`` body (the main
query is the product loop on those page types), the card's own values
become loop tags. The hub's series cards collapse into the
``[md_series_grid]`` data assembly (a term loop LiveCanvas has no tag
for — its ``lc_get_terms`` renders badge lists, not markup).
"""

from __future__ import annotations

import re


def pdp_sample(html: str) -> dict[str, str]:
    """The representative sample's own values, extracted from its markup.

    Every slot replacement anchors on the exact bytes the sample
    rendered, so the sample's values come out of the same section the
    template is cut from — no second source of truth.
    """

    def one(pattern: str, default: str = "") -> str:
        match = re.search(pattern, html, re.DOTALL)
        return match.group(1) if match else default

    sample = {
        # The breadcrumb's current link carries the sample's own address
        # and its full title — the two values the breadcrumb slots need.
        # (href precedes aria-current in the old markup.)
        "handle_url": one(r"<a[^>]+href='(/products/[^']+)'[^>]*aria-current='page'"),
        "title": one(
            r"<a[^>]+href='/products/[^']+'[^>]*aria-current='page'\s*>\s*([A-Za-z][^<]+?)\s*</a>"
        ),
        "code": one(r'<h1>\s*([A-Za-z][^<\n]+?)\s*<span class="pdp-h1-sub">'),
        "eyebrow": one(r'<span class="pdp-eyebrow">([^<]+)</span>'),
        "style": one(r"<div><dt>Style</dt><dd>([^<]+)</dd>"),
        "open_area": one(r"<div><dt>Open area</dt><dd>([^<]+)</dd>"),
        "material": one(r"<div><dt>Material route</dt><dd>([^<]+)</dd>"),
        "best_fit": one(
            r'<div class="pdp-spec-wide"><dt>Best fit</dt><dd>([^<]+)</dd>'
        ),
        "hero_desc": one(r'<p class="pdp-hero-desc">([^<]+)</p>'),
        "identity_lead": one(
            r'Design intent</span>\s*<h2>[^<]+</h2>\s*<p class="pdp-body">([^<]+)</p>'
        ),
        "identity_supplemental": one(
            r'Design intent</span>.*?<p class="pdp-body">[^<]+</p>\s*<p class="pdp-body">([^<]+)</p>'
        ),
        "hero_bg_url": one(r'<div class="pdp-hero-bg"[^>]*>\s*<img\s+src="([^"]+)"'),
        "plate_url": one(r'<div class="pdp-hero-plate[^"]*"[^>]*><img\s+src="([^"]+)"'),
        "square_url": one(r'<figure class="pdp-square[^"]*"[^>]*><img\s+src="([^"]+)"'),
    }
    # A slot rule anchors on the sample's exact bytes; an empty anchor
    # would splice the slot into every position. Every value must be
    # non-empty or the run refuses — never a partial slot pass.
    missing = sorted(name for name, value in sample.items() if not value)
    if missing:
        raise ValueError(f"PDP sample extraction missed: {', '.join(missing)}")
    return sample


def slot_pdp(html: str, sample: dict[str, str]) -> str:
    """The PDP template from the representative sample's markup.

    ``sample`` carries the sample's own values (its handle, code, style,
    open area, best fit, image URLs) so every replacement anchors on the
    exact bytes the sample rendered — the verbatim-move discipline kept
    into the slot pass: nothing is re-serialised, only the sample's
    value strings become template slots.
    """
    rules: list[tuple[str, str]] = [
        # The breadcrumb's current link: the sample's own address and
        # full title become the loop-context permalink and title.
        (sample["handle_url"], "[lc_the_permalink]"),
        # Alt texts carry the full title (… | Architectural Metal Screen
        # Pattern); the title tag renders it for every product.
        (sample["title"], "[lc_the_title]"),
        # The spec strip's Pattern code cell is the SKU (spec 06 §2:
        # pattern_code → Woo SKU); the h1's leading word is the same
        # value between the h1 open and its sub-span, matched loosely
        # because the old markup indents the word.
        ("<dd>" + sample["code"] + "</dd>", '<dd>[lc_wc_product get="sku"]</dd>'),
        # The eyebrow and the spec strip's Style cell: the series word
        # from product_cat (spec 06 §3).
        (sample["eyebrow"], '[lc_wp_func func="md_pdp_style"] pattern'),
        (
            "<dd>" + sample["style"] + "</dd>",
            '<dd>[lc_wp_func func="md_pdp_style"]</dd>',
        ),
        # The spec strip's Open area and the applications figure: the
        # ACF number with the 16-gap "On request" rule.
        ("<dd>" + sample["open_area"] + "</dd>", "<dd>[md_pdp_open_area]</dd>"),
        (
            '<span class="pdp-open-value">' + sample["open_area"] + "</span>",
            '<span class="pdp-open-value">[md_pdp_open_area]</span>',
        ),
        # Material route: the site-wide constant (owner ruling, AGENTS.md
        # 2026-10-24 revision 6).
        ("<dd>" + sample["material"] + "</dd>", "<dd>[md_pdp_material]</dd>"),
        # Best fit: the ACF key rendered as its display word.
        ("<dd>" + sample["best_fit"] + "</dd>", "<dd>[md_pdp_best_fit]</dd>"),
        # The hero description and the identity block's two paragraphs:
        # the design-intent assembly, which resolves the prose from the
        # product's own Woo/ACF fields (no copy file in the checkout is a
        # source). The identity paragraphs carry [lc_if] guards because a
        # pattern whose prose nobody has written yet must leave no empty
        # .pdp-body in the DOM; the hero sentence is always present (it is
        # the site-wide wording), so it needs none.
        (
            '<p class="pdp-hero-desc">' + sample["hero_desc"] + "</p>",
            '<p class="pdp-hero-desc">[md_pdp_design_intent slot="hero"]</p>',
        ),
        (
            '<p class="pdp-body">' + sample["identity_lead"] + "</p>",
            '[lc_if condition="md_pdp_has_design_intent" param="lead"]<p class="pdp-body">[md_pdp_design_intent slot="lead"]</p>[/lc_if]',
        ),
        (
            '<p class="pdp-body">' + sample["identity_supplemental"] + "</p>",
            '[lc_if condition="md_pdp_has_design_intent" param="supplemental"]<p class="pdp-body">[md_pdp_design_intent slot="supplemental"]</p>[/lc_if]',
        ),
        # The three per-product images: the Woo gallery through the
        # assembly shortcode (LiveCanvas has no per-gallery-image tag).
        (sample["hero_bg_url"], '[md_pdp_image slot="hero-bg"]'),
        (sample["plate_url"], '[md_pdp_image slot="plate"]'),
        (sample["square_url"], '[md_pdp_image slot="square"]'),
    ]
    for old, new in rules:
        html = html.replace(old, new)
    html = re.sub(
        r"(<h1>\s*)" + re.escape(sample["code"]) + r"(\s*<span)",
        r'\1[lc_wc_product get="sku"]\2',
        html,
        count=1,
    )
    html = _slot_pdp_partials(html)
    html = _slot_pdp_related(html)
    return html


def pdp_slot_check(template_html: str) -> list[str]:
    """The slots a converted PDP template must carry (the acceptance
    list; a missing one means the anchor drifted)."""
    required = (
        "lc_wc_product",
        "md_pdp_material",
        "md_pdp_design_intent",
        "md_pdp_open_area",
        "md_pdp_best_fit",
        "md_pdp_image",
        "md_pdp_related",
        "lc_get_partial",
    )
    return [tag for tag in required if tag not in template_html]


def _slot_pdp_partials(html: str) -> str:
    """The single-source content components: the where-works rows and the
    technical FAQ, both 146-identical payloads (spec 08 §3.1) replaced by
    their partials (spec 19 §5.2)."""
    rows = re.search(
        r'(<div class="pdp-apps-rows[^"]*"[^>]*>)(.*?)(</div>)', html, re.DOTALL
    )
    if rows:
        html = (
            html[: rows.start(2)]
            + '[lc_get_partial slug="md-where-works"]'
            + html[rows.end(2) :]
        )
    faq = re.search(
        r'(<div class="pdp-faq-list[^"]*"[^>]*>)(.*?)(</div>)', html, re.DOTALL
    )
    if faq:
        html = (
            html[: faq.start(2)]
            + '[lc_get_partial slug="md-pdp-faq"]'
            + html[faq.end(2) :]
        )
    return html


def _slot_pdp_related(html: str) -> str:
    """The adjacent-patterns cards: the Woo related products through the
    data assembly (LiveCanvas's lc_wc_related renders the WooCommerce
    card template, not this markup; Woo's own shortcode does the same —
    the card recipe is the replica's, so the assembly is the only
    layer that can emit it)."""
    start = html.find('class="pdp-related-grid"')
    if start < 0:
        return html
    content = html.index(">", start) + 1
    # The grid closes before the FAQ section opens; the cards between
    # are the sample's own related products.
    end = html.find('id="faq"', content)
    if end < 0:
        return html
    close = html.rfind("</div>", content, end)
    if close < 0:
        return html
    return html[:content] + "[md_pdp_related]" + html[close:]


def slot_grid(html: str) -> str:
    """The pattern-catalog grid (series archive and shop): repeated
    product cards collapse into one ``[lc_loop]`` body.

    The main query on these page types is the product loop, so
    ``[lc_loop]`` — LiveCanvas's native main-loop tag — walks it. The
    first card's markup becomes the loop body with its own values as
    loop tags: the link, the image, the title and the card's spec rows
    (the rows render from the pattern fields the loop post carries).
    """
    # The grid's repeated cards: class="pattern-card" (with __link on
    # the wrapping anchor). Find the container of a run of at least
    # three cards, keep the first as the loop body, drop the rest.
    card = re.compile(
        r'<a [^>]*class="pattern-card__link[^"]*"[^>]*>.*?</a>', re.DOTALL
    )
    cards = list(card.finditer(html))
    if len(cards) < 3:
        return html
    first = cards[0]
    body = first.group(0)
    # The card's own values (sample = the first card's product) become
    # loop tags: link, image, title, code, style, open area.
    body = _slot_card(body)
    loop = (
        "<!-- md-loop: the product loop (LiveCanvas native lc_loop) -->\n"
        "[lc_loop]\n" + body + "\n[/lc_loop]"
    )
    # Replace the whole run of cards with the loop.
    html = html[: cards[0].start()] + loop + html[cards[-1].end() :]
    return html


def _slot_card(body: str) -> str:
    """One product card's values become loop tags."""
    link = re.search(r'href="(/products/[^"]+)"', body)
    if link:
        body = body.replace(link.group(1), "[lc_the_permalink]")
    img = re.search(r'src="[^"]*/files/([^"?]+)\?[^"]*"', body)
    if img:
        body = re.sub(r'src="[^"]*"', 'src="[lc_the_thumbnail_url]"', body, count=1)
    title = re.search(r'class="pattern-card__value--title">([^<]+)<', body)
    if title:
        body = body.replace(">" + title.group(1) + "<", ">[lc_the_title]<")
    return body


def slot_hub(html: str) -> str:
    """The hub's series cards collapse into the term-loop assembly.

    LiveCanvas's term tag renders badge lists (``lc_get_terms``); a
    series card grid — image, title, count, link — has no native tag,
    and Woo's product shortcodes list products, not series. The
    ``[md_series_grid]`` assembly (md-site) walks the product_cat terms
    and emits the card markup the replica carries.
    """
    # The hub page's series links: /collections/<series-slug> anchors
    # in a run; collapse the run's container content.
    series_card = re.compile(
        r'<a [^>]*href="/collections/pattern-[a-z-]+"[^>]*>.*?</a>', re.DOTALL
    )
    cards = list(series_card.finditer(html))
    if len(cards) < 4:
        return html
    html = (
        html[: cards[0].start()]
        + "<!-- md-loop: the series loop (md_series_grid assembly; no LC/Woo native tag renders a term-card grid) -->\n"
        + "[md_series_grid]"
        + html[cards[-1].end() :]
    )
    return html
