"""The mechanical transforms T1, T3, T4 and T7 of spec 19 §4.2.

Each transform is a pure string rewrite the pipeline applies to a
section's verbatim bytes. T2 (styles into volumes) lives in csslib.py and
volmap.py; the transforms here are the ones that only touch markup.

T1 — Shopify scaffolding stripped: generated JSON-LD, the platform's own
     system style blocks and tracking pixels become a repo comment.
T3 — every ``<script>`` leaves the template (the upgrade commits bring
     the whitelisted interactions back).
T4 — asset addresses: the old CDN's URLs become the media pipeline's
     root-relative final names.
T7 — comments and metadata: the old site's absolute addresses become
     root-relative.
"""

from __future__ import annotations

import re

# T1: Shopify's generated structured data (the new site's structured data
# is SEOPress's, not carried over — spec 19 T1).
SHOPIFY_LD = re.compile(
    r'[ \t]*<script type="application/ld\+json">.*?</script>\n?', re.DOTALL
)
# T1: the platform's system style blocks — buyer consent, shopify-*
# internals. A style block whose first rule addresses a shopify system
# selector is Shopify's, not the page's.
SHOPIFY_STYLE = re.compile(
    r"[ \t]*<style[^>]*>\s*(?:/[^\n]*\n\s*)?#shopify-[a-z-]+[^{]*\{.*?</style>\n?",
    re.DOTALL,
)
# T3: every script, inline or external.
SCRIPT = re.compile(r"[ \t]*<script\b[^>]*>.*?</script>\n?", re.DOTALL)
SCRIPT_EMPTY = re.compile(r"[ \t]*<script\b[^>]*/>\n?")
# Shopify's own app/config scripts that render as link preload or meta.
PRELOAD = re.compile(r'[ \t]*<link[^>]+rel="preload"[^>]*>\n?', re.IGNORECASE)
# Absolute addresses of the old site (T7).
OLD_HOST = re.compile(r'https://(?:www\.)?metalpartition\.com(/[^"\'\s)]*)')

REMOVED_MARK = "<!-- md-removed: {what} ({count}) -->"


def strip_scaffolding(html: str) -> tuple[str, dict[str, int]]:
    """T1: remove Shopify's generated payload, count what left."""
    counts: dict[str, int] = {}
    for name, pattern in (
        ("shopify-jsonld", SHOPIFY_LD),
        ("shopify-style", SHOPIFY_STYLE),
    ):
        html, n = pattern.subn("", html)
        if n:
            counts[name] = n
    return html, counts


def strip_scripts(html: str) -> tuple[str, int]:
    """T3: every script out of the template."""
    html, n = SCRIPT.subn("", html)
    html, n2 = SCRIPT_EMPTY.subn("", html)
    return html, n + n2


def root_relative(html: str) -> str:
    """T7: the old host's absolute addresses become root-relative."""
    return OLD_HOST.sub(r"\1", html)


# T4: the old CDN's URLs — protocol-relative and absolute, on both the
# shop domain (…/cdn/shop/…) and Shopify's own CDN (…/s/files/…). The
# width of the derived tier rides in the query (``?v=…&width=832``) or
# in the file-name suffix (``…_480x480.png``).
CDN_URL = re.compile(
    r"(?:https:)?//[a-z0-9.-]+/(?:(?P<shopcdn>cdn/shop)|(?P<sfcdn>s))/files/"
    r"(?P<path>[^\"'\s)]+?\.(?P<ext>png|jpe?g|webp|gif|svg|mp4|webm|avif))"
    r"(?P<q>\?[^\"'\s)]*)?",
    re.IGNORECASE,
)


def asset_name(url: str) -> str:
    """The media pipeline's final name for one old CDN address.

    Spec 15 §3.1: products ``{handle}--{seq}--{width}w.webp``, site
    ``{stem}--{width}w.webp`` — the stem is the source file name
    slugified, the width the address's derived tier when it carries one.
    The importer (#31) owns resolving the name against the built tiers;
    the map this produces is the contract between the two tickets.
    """
    match = CDN_URL.search(url)
    if match is None:
        return url
    name = match.group("path").rsplit("/", 1)[-1]
    stem = name.rsplit(".", 1)[0]
    # Shopify's derived-size suffix: files/foo_480x480.png → foo at 480.
    width: str | None = None
    size = re.search(r"_(\d+)x\d+$", stem)
    if size:
        width = size.group(1)
        stem = re.sub(r"_\d+x\d+$", "", stem)
    # The query's width parameter marks the derived tier as well.
    if match.group("q"):
        query = re.search(r"width=(\d+)", match.group("q"))
        if query:
            width = query.group(1)
    slug = re.sub(r"[^a-z0-9]+", "-", stem.lower()).strip("-")
    ext = match.group("ext").lower()
    if width:
        return f"{slug}--{width}w.{ext}"
    return f"{slug}.{ext}"


def map_assets(html: str, mapping: dict[str, str]) -> tuple[str, int]:
    """T4: CDN addresses become root-relative final names.

    ``mapping`` holds the media pipeline's answers (source address → final
    file); an address without an entry falls back to :func:`asset_name`'s
    derived stem, which the importer later resolves. The lookup keys are
    the address's scheme-relative form (``//…``) — the way the old site
    printed them — and the absolute form, so a map built from either
    resolves.
    """
    count = 0

    def replace(match: re.Match[str]) -> str:
        nonlocal count
        count += 1
        url = match.group(0)
        for form in (url, "https:" + url):
            if form in mapping:
                return "/wp-content/uploads/" + mapping[form]
        return "/wp-content/uploads/" + asset_name(url)

    return CDN_URL.sub(replace, html), count
