"""The blog fork: the de-linker journal faces carried onto this site
(spec 19 §2.2 R1, AGENTS.md 2026-10-24: the four blog LiveCanvas faces
and their WindPress volumes are the approved design source).

The fork is a mapping, not a redesign: the four templates and four
volumes move with their markup intact, and every change is one of the
mapping tables below — brand words, addresses, the audience sentence,
the token swap (de-linker's prototype tokens for this site's --md-*
tokens), the rem lengths restated in px at this site's root, the CSS
rewrites of CSS_RULES, and the structured-data blocks the new site's
SEOPress owns.
R1 is a redesigned face: no pixel gate, its design review is its own
ticket's.

The source site carries the shared journal rules in a fifth volume,
journal.css. This site's inventory is fixed at thirty-six volumes
(spec 19 §7) and does not have a slot for it, so the fork folds that
volume — plus the journal geometry the source keeps in tokens.css —
into the index face's volume. The fork output is the whole of each of
the four volumes: a rerun rewrites them, it does not append to them.
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

#: The shared journal rules' volume at the source, and the face volume this
#: site folds them into. The three discovery faces compile these rules from
#: the one block, so the fold does not add a thirty-seventh volume.
JOURNAL = "journal.css"
JOURNAL_TARGET = "blog-index.css"

#: The source's own token volume: the journal geometry (`--journal-*`) lives
#: there, and the fold carries it with the rules that consume it.
SOURCE_TOKENS = "tokens.css"

#: The journal rules' shared selector at the source, verbatim from journal.css.
#: The geometry block reuses it after the id transform, so "the three discovery
#: faces" has one definition.
JOURNAL_FACES_SELECTOR = (
    ":is(#tv-blog-index-v1,#tv-blog-category-v1,#tv-blog-search-v1)"
)

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

#: The literals the source site's tokens resolved to. They are the design
#: source's own values, rem included: every one was read in a browser whose
#: root is 16px, so `--proto-space-6: 2rem` is the 32px the design renders.
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

#: The design source's root. This site's base volume restores the old build's
#: 62.5% root (1rem = 10px, base.css) so the 856 carried old-site rem values
#: keep their meaning; a journal length carried as rem would compute 0.625x
#: there (a 2rem shell padding rendering 20px against the design's 32px).
PROTO_ROOT_PX = 16

#: CSS rewrites: the deliberate divergences from the design source, each one
#: pair of exact strings, applied to the fully transformed text (token swap,
#: id family and rem restatement done, so the strings read as the volume does).
#: They are scoped rewrites rather than token-map entries because the source's
#: one `--proto-font-display` token feeds both the journal headings (this
#: site's display face) and the single-post section headings (which keep the
#: sans face), so a token-level mapping cannot express the split.
CSS_RULES: list[tuple[str, str]] = [
    # The journal rules describe the source's fifth volume; this site folds it.
    (
        " * Shared navigation/search/feed live in journal.css; only this face has a\n"
        " * featured cover. Ratios and overlay follow the supplied reference; mobile\n"
        " * moves real text below its 16:9 image instead of hiding it inside the cover.\n",
        " * The shared navigation/search/feed rules are folded into this volume below\n"
        " * (the site's thirty-six-volume inventory has no separate journal volume);\n"
        " * only this face has a featured cover. Ratios and overlay follow the supplied\n"
        " * reference; mobile moves real text below its 16:9 image instead of hiding it\n"
        " * inside the cover.\n",
    ),
    # The journal h1 is the discovery faces' one display-face heading.
    (
        "font-family: var(--md-font-sans); font-size: clamp(24px,2.2vw,32px);",
        "font-family: var(--md-font-display); font-size: clamp(24px,2.2vw,32px);",
    ),
    # The source's two adjacent 640px-band comments become one that says what
    # the second band does (the first only repeated the band it sits in).
    (
        "/* Mobile reference: compact recommendations below the lead cover. */\n"
        "/* Existing 640px mobile band; the owner's compact mobile browsing reference. */\n",
        "/* 640px mobile band, second part: the compact recommendation list below the\n"
        " * lead cover (the owner's mobile browsing reference). */\n",
    ),
    # The two sibling discovery faces describe where the shared rules live; the
    # fold moves that answer into this site's index volume.
    (
        "/* The face's own rules. Shared content-platform composition is journal.css.\n"
        " * The current category comes from the native query, its navigation marker\n"
        " * from WordPress's category walker. No separate selection state is maintained.\n",
        "/* The face's own rules. Shared content-platform composition travels in the\n"
        " * journal block of blog-index.css (folded there to keep the site's fixed\n"
        " * thirty-six-volume inventory). The current category comes from the native\n"
        " * query, its navigation marker from WordPress's category walker. No separate\n"
        " * selection state is maintained.\n",
    ),
    (
        "/* The face's own rules. Search reuses journal.css and the native query loop.\n"
        " * The escaped query is allowed to wrap; no fixed heading width can clip it.\n",
        "/* The face's own rules. Search reuses the journal block in blog-index.css and\n"
        " * the native query loop. The escaped query is allowed to wrap; no fixed\n"
        " * heading width can clip it.\n",
    ),
]

#: Rules the fold drops, whole lines, by what their selector does. The
#: category-icon mappings key on the source site's own category slugs
#: (`/applications-blog/`, `/batteriespeicher/`, …); this site's categories do
#: not carry them, and the generic topic icon already covers every category, so
#: the mappings can never match here. The pattern requires both the slug match
#: (`href$=`) and the icon pseudo-element, so a future href-based rule that is
#: not an icon mapping survives.
CSS_DROPS: list[re.Pattern[str]] = [
    re.compile(
        r"^[^\n]*\.cat-item > a:is\(\[href\$=[^\n]*\)::before[^\n]*\n", re.MULTILINE
    ),
]

#: The journal geometry the source site keeps in its token volume. The fold
#: carries it onto the faces, which is where its consumers live; the selectors
#: are the three discovery faces, so the values follow the face ids.
JOURNAL_GEOMETRY_HEADER = (
    "/* The journal geometry de-linker kept in its tokens volume. They are face\n"
    " * variables, so they live with the faces rather than in the site tokens. */\n"
)

#: The fold's own note, above the shared rules it introduces.
JOURNAL_NOTE = (
    "/* The shared journal layout: topic rail, native search, featured cover and\n"
    " * the uninterrupted thumbnail feed (the de-linker journal volume, forked for\n"
    " * this site with the md-site tokens, spec 19 §2.2 R1). The three discovery\n"
    " * faces share these rules; the per-face volumes carry only their own deltas.\n"
    " *\n"
    " * Folded into this volume because the site's inventory is fixed at thirty-six\n"
    " * volumes (spec 19 §7 carries the four blog volumes, not de-linker's fifth\n"
    " * shared one) and every volume compiles into the one WindPress cache. The\n"
    " * de-linker category-icon mappings are dropped: this site's categories do not\n"
    " * carry de-linker's slugs, and the generic topic icon covers them all.\n"
    " */\n"
)

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


def restate_at_root(css: str) -> str:
    """The design source's rem lengths restated in px at this site's root.

    De-linker authored every length against a 16px root; the fork lands in a
    site whose base volume restores the old build's 10px root, where the same
    rem computes 0.625x. px is what the rest of the site's carried rules use
    (base.css's tokens, spec 17's literal scale), and it is the only form whose
    computed value does not depend on the root the volume lands under.
    """
    return re.sub(
        r"(?<![\w.])(\d*\.?\d+)rem\b",
        lambda rem: f"{float(rem.group(1)) * PROTO_ROOT_PX:g}px",
        css,
    )


def _transform(css: str) -> str:
    """One stylesheet through every mapping the fork applies.

    The rem restatement runs last, so CSS_RULES' exact strings read as the
    volume finally does (px, not the source's rem).
    """
    for old, new in {**CSS_TOKENS, **CSS_LITERALS}.items():
        css = css.replace(f"var({old})", new)
    css = re.sub(r"tv-blog-([a-z-]+?)-v1\b", r"md-blog-\1", css)
    css = css.replace("tv-blog-", "md-blog-")
    css = restate_at_root(css)
    for pattern in CSS_DROPS:
        css = pattern.sub("", css)
    for old, new in CSS_RULES:
        css = css.replace(old, new)
    return css


def journal_geometry(source: Path) -> str:
    """The `--journal-*` declarations, lifted from the source's token volume.

    The source keeps the journal geometry in tokens.css beside its colour and
    type scales; this site's tokens volume carries neither the journal nor a
    slot for a fifth blog volume, so the fold states them on the three
    discovery faces — the only consumers — where they scope exactly as the
    :root declarations did.
    """
    css = (source / "windpress" / SOURCE_TOKENS).read_text(encoding="utf-8")
    found = re.findall(r"^[ \t]*(--journal-[\w-]+:[^;]+;)[ \t]*$", css, re.MULTILINE)
    if not found:
        raise SystemExit(
            f"journal geometry: no --journal-* declarations in "
            f"{source / 'windpress' / SOURCE_TOKENS}"
        )
    # The selector is the journal rules' own shared selector, after the same
    # id transform: one definition of "the three discovery faces".
    selector = _transform(JOURNAL_FACES_SELECTOR)
    body = "".join(f"  {declaration}\n" for declaration in found)
    return f"{selector} {{\n{body}}}\n"


def fork_journal(source: Path) -> str:
    """The shared journal volume plus its geometry, transformed as one block.

    The source volume's own leading comment describes tokens.css's role and the
    design bands; JOURNAL_NOTE replaces it, since the fold has its own story
    (a fifth volume with no slot in this site's inventory) to tell.
    """
    css = (source / "windpress" / JOURNAL).read_text(encoding="utf-8")
    css = re.sub(r"\A(?:/\*.*?\*/\n)+", "", css, flags=re.DOTALL)
    block = (
        JOURNAL_NOTE
        + JOURNAL_GEOMETRY_HEADER
        + journal_geometry(source)
        + _transform(css)
    )
    return block.rstrip("\n")


def fork_volumes(source: Path, target: Path) -> list[Path]:
    """The four volume files, forked whole: token swap, rewrites, and (for the
    index face) the folded journal volume. A rerun replaces the files."""
    written: list[Path] = []
    for _name, volume in FACES.items():
        css = _transform((source / "windpress" / volume).read_text(encoding="utf-8"))
        if volume == JOURNAL_TARGET:
            css = css.rstrip("\n") + "\n\n" + fork_journal(source)
        out = target / "windpress" / volume
        out.write_text(HEADER.format(name=volume) + css + "\n", encoding="utf-8")
        written.append(out)
    return written
