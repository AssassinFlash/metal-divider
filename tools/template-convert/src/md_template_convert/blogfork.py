"""The blog fork: the de-linker journal faces carried onto this site
(spec 19 §2.2 R1, AGENTS.md 2026-10-24: the four blog LiveCanvas faces
and their WindPress volumes are the approved design source).

The fork is a mapping, not a redesign: the four templates and four
volumes move with their markup intact, and every change is one of the
mapping tables below — brand words, addresses, the audience sentence,
the token swap (de-linker's prototype tokens for this site's --md-*
tokens), and the structured-data blocks the new site's SEOPress owns.
R1 is a redesigned face: no pixel gate, its design review is its own
ticket's.
"""

from __future__ import annotations

import re
from pathlib import Path

#: The four faces: template file → volume file.
FACES = {
    "blog_index.html": "blog-index.css",
    "blog_single.html": "blog.css",
    "category_archive.html": "blog-category.css",
    "search.html": "blog-search.css",
}

#: The output names (spec 19 §3: the page-type keys' file names).
OUTPUT = {
    "blog_index.html": "blog-index.html",
    "blog_single.html": "blog-single.html",
    "category_archive.html": "blog-category.html",
    "search.html": "blog-search.html",
}

#: Markup rewrites: the brand, the site's own addresses, the audience.
HTML_RULES: list[tuple[str, str]] = [
    # The live-refresh wrapper is the source site's own mechanism.
    (r'<tangible class="live-refresh">\n?', ""),
    (r"</tangible>\n?", ""),
    # Brand.
    ("Linker Journal", "Metal Divider Journal"),
    (
        r'<span class="journal-mark" aria-hidden="true">L</span>',
        '<span class="journal-mark" aria-hidden="true">M</span>',
    ),
    ("Linker", "Metal Divider"),
    # Addresses: this site's own faces.
    ('href="/faq-download/"', 'href="/faqs/"'),
    ("FAQ &amp; documents", "FAQs"),
    ("FAQ & documents", "FAQs"),
    ('href="/products/"', 'href="/shop/"'),
    (">Products<", ">All patterns<"),
    ('href="/solution-configurator/"', 'href="/inquiry/"'),
    ("Open the Solution Configurator", "Start an inquiry"),
    ("From reading to a system plan.", "From reading to a project quote."),
    # The audience sentence (the source site's is installer/EPC wording).
    (
        "Technical guidance for installers and EPCs.",
        "Technical guidance for architects, contractors, and dealers.",
    ),
    # The source site's short-title field is its own plugin's; this
    # site carries plain titles.
    ('name="journal_title"', 'name="title"'),
    # DOM ids and classes: the source site's tv- prefix becomes this
    # site's md- (the -v1 family suffix goes with it — the version-family
    # naming ban of CHECK-7 reads on files, and ids follow the same
    # spirit on a forked face).
    (r"tv-blog-([a-z-]+?)-v1\b", r"md-blog-\1"),
    ("tv-blog-", "md-blog-"),
    # The source site's category-links shortcode is its own plugin's;
    # this site renders the same links with the Tangible term loop the
    # faces already use.
    (
        "[linker_blog_post_categories]",
        '<loop type="taxonomy_term" taxonomy="category" post="current"><a href="{Field url}"><field name="title"></field></a></loop>',
    ),
]

#: The token swap: de-linker's prototype tokens for this site's own.
#: Color tokens map onto --md-* where one exists; the neutrals, spaces
#: and radii carry their values (this site's tokens.css does not name
#: them — spec 17: the type scale and spacing stay literal).
CSS_TOKENS: dict[str, str] = {
    "--proto-font-display": "var(--md-font-sans)",
    "--proto-font-sans": "var(--md-font-sans)",
    "--proto-font-editorial": "var(--md-font-display)",
    "--proto-font-mono": "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace",
    "--proto-ink-deep": "var(--md-bg-deep)",
    "--proto-ink-muted-deep": "#5a6363",
    "--proto-teal-50": "var(--md-cream)",
    "--proto-teal-100": "var(--md-gold-light)",
    "--proto-teal-200": "var(--md-gold-light)",
    "--proto-teal-300": "var(--md-gold)",
    "--proto-teal-400": "var(--md-gold)",
    "--proto-teal-500": "var(--md-gold)",
    "--proto-teal-600": "var(--md-gold-dark)",
    "--proto-teal-700": "var(--md-gold-dark)",
    "--proto-teal-800": "var(--md-gold)",
    "--proto-teal-900": "var(--md-gold-dark)",
    "--proto-focus-ring": "2px solid var(--md-gold)",
    "--lk-header-h": "var(--md-header-h, 80px)",
}

CSS_LITERALS: dict[str, str] = {
    "--proto-neutral-0": "#ffffff",
    "--proto-neutral-50": "#f7f8f8",
    "--proto-neutral-100": "#eef0f0",
    "--proto-neutral-200": "#e2e5e5",
    "--proto-neutral-300": "#c8cdcd",
    "--proto-neutral-400": "#9aa3a3",
    "--proto-neutral-500": "#5a6363",
    "--proto-neutral-600": "#4a5151",
    "--proto-neutral-700": "#2f3535",
    "--proto-neutral-800": "#1a1e1e",
    "--proto-neutral-900": "#0c0f0f",
    "--proto-radius-sm": "4px",
    "--proto-radius-md": "8px",
    "--proto-radius-lg": "12px",
    "--proto-radius-xl": "12px",
    "--proto-radius-photo": "12px",
    "--proto-radius-pill": "12px",
    "--proto-radius-card": "18px",
    "--proto-radius-panel": "22px",
    "--proto-radius-round": "999px",
    "--proto-control-height": "2.75rem",
    "--proto-control-pad-x": "1.25rem",
    "--proto-control-pad-y": "0.75rem",
    "--proto-control-weight": "600",
    "--proto-control-size": "0.9375rem",
    "--proto-focus-offset": "2px",
    "--proto-space-1": "0.25rem",
    "--proto-space-2": "0.5rem",
    "--proto-space-3": "0.75rem",
    "--proto-space-4": "1rem",
    "--proto-space-5": "1.5rem",
    "--proto-space-6": "2rem",
    "--proto-space-7": "3rem",
    "--proto-space-8": "4rem",
    "--proto-space-9": "6rem",
    "--proto-space-10": "8rem",
    "--proto-space-section": "clamp(4rem, 7vw, 6rem)",
    "--proto-space-gutter": "1.5rem",
    "--proto-space-gutter-lg": "2rem",
}

JSON_LD = re.compile(
    r"[ \t]*<script type=\"application/ld\+json\">.*?</script>\n?", re.DOTALL
)

HEADER = (
    "/* md-site volume: {name} — the journal face forked from the\n"
    " * approved design source (AGENTS.md 2026-10-24; spec 19 §2.2 R1),\n"
    " * with this site's tokens (the mapping lives in the fork command\n"
    " * of tools/template-convert, ticket #32).\n"
    " */\n"
)


def fork_templates(source: Path, target: Path) -> list[Path]:
    """The four template files, forked and written."""
    written: list[Path] = []
    for name, out_name in OUTPUT.items():
        html = (source / "livecanvas" / name).read_text(encoding="utf-8")
        html = JSON_LD.sub("", html)
        for pattern, replacement in HTML_RULES:
            html = (
                re.sub(pattern, replacement, html)
                if isinstance(pattern, str)
                and any(c in pattern for c in r"\^$.[]|()*+?{}")
                else html.replace(pattern, replacement)
            )
        out = target / "livecanvas" / out_name
        out.write_text(
            "<!-- Forked from the approved journal design (spec 19 §2.2 R1):\n"
            "     markup intact; brand, addresses and tokens mapped (see\n"
            "     tools/template-convert blog_fork, ticket #32). -->\n" + html,
            encoding="utf-8",
        )
        written.append(out)
    return written


def fork_volumes(source: Path, target: Path) -> list[Path]:
    """The four volume files, forked with the token swap."""
    written: list[Path] = []
    for _name, volume in FACES.items():
        css = (source / "windpress" / volume).read_text(encoding="utf-8")
        for old, new in {**CSS_TOKENS, **CSS_LITERALS}.items():
            css = css.replace(f"var({old})", new)
        css = re.sub(r"tv-blog-([a-z-]+?)-v1\b", r"md-blog-\1", css)
        css = css.replace("tv-blog-", "md-blog-")
        out = target / "windpress" / volume
        out.write_text(HEADER.format(name=volume) + css + "\n", encoding="utf-8")
        written.append(out)
    return written
