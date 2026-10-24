"""The page plan: which raw page becomes which template, and where a
section's styles belong (spec 19 §2.2, §5, §7).

The plan is data, not logic: the converter reads it and every mapping
here traces to a spec row. Three buckets:

* chrome — the four header sections and the footer the standard pages
  share; they become the two LiveCanvas partials (§5.1) and their
  styles land in chrome.css. custom-screens' landing chrome is the one
  page-level exception the old site carried (spec 19 §2.2 L17 row);
  its sections stay page content and the deviation is a DEBT ledger
  row, not a second global chrome.
* components — the nine reusable pieces of §5.3; a section whose name
  matches a component pattern carries its styles in that component's
  volume, so the piece is single-sourced across the templates that use
  it. The reusable thing is the recipe and the classes, not the copy
  (§5.3's closing rule), so the section's markup still moves verbatim
  into each template.
* the page's own template volume — everything else.
"""

from __future__ import annotations

import re

CHROME_SECTIONS = frozenset(
    {
        "header_v1_main",
        "search",
        "mega_menu_v2_HEAtbw",
        "mega_menu_v2_x7pRC4",
        "footer",
    }
)

#: A component volume per §5.3, with the section-name patterns the old
#: site's sections carry. Matched against the section's own name (the
#: part after ``__``), lowercased; the first match wins. The marquee
#: precedes the pattern-grid: the marquee section is one band whose
#: cards ride inside it, so the whole section is the marquee piece
#: (§5.3 gives the pattern-grid the catalog grids, not the band).
COMPONENTS: list[tuple[str, re.Pattern[str]]] = [
    ("breadcrumb", re.compile(r"^breadcrumb")),
    ("hero", re.compile(r"hero")),
    ("cta-band", re.compile(r"(_mid_cta$|^mid_cta|_contact$|^contact|cta$)")),
    ("faq", re.compile(r"faq")),
    (
        "spec-table",
        re.compile(
            r"(_specs$|-specs$|specification|comparison-table|manufacturing-envelope|spec-guide|_envelope$)"
        ),
    ),
    ("stats-band", re.compile(r"(manufacturing_stats|_stats$)")),
    (
        "gallery",
        re.compile(
            r"(gallery|showcase|featured_projects|_projects$|project-stories|project_selector|proof_of_work)"
        ),
    ),
    ("marquee", re.compile(r"marquee")),
    (
        "pattern-grid",
        re.compile(r"(pattern_library|pattern-catalog|stainless-project-selector)"),
    ),
]

#: The replica pages: raw input → template (and template volume) name.
#: PAGES with a slot function become one template from several inputs;
#: the rest are one input each. The blog, inquiry and privacy faces are
#: new writing (R1–R3) and are not in this table.
PAGES: list[dict[str, str]] = [
    {"raw": "home/index.html", "template": "home", "title": "Home"},
    {
        "raw": "pdp/*.html",
        "template": "pdp",
        "title": "Pattern (single)",
        "slot": "pdp",
    },
    {
        "raw": "collections/pattern-organic.html",
        "template": "series-archive",
        "title": "Pattern series archive",
        "slot": "grid",
        "check": "collections/*.html",
    },
    {
        "raw": "hub/index.html",
        "template": "hub",
        "title": "Pattern series hub",
        "slot": "hub",
    },
    {
        "raw": "shop/index.html",
        "template": "shop",
        "title": "Shop (all patterns)",
        "slot": "grid",
    },
    {
        "raw": "pages/laser-cut-geometric.html",
        "template": "family-laser-cut-geometric",
        "title": "Laser-cut geometric screens",
    },
    {
        "raw": "pages/glass-hybrid.html",
        "template": "family-glass-hybrid",
        "title": "Glass hybrid screens",
    },
    {
        "raw": "pages/outdoor-privacy.html",
        "template": "family-outdoor-privacy",
        "title": "Outdoor privacy screens",
    },
    {
        "raw": "pages/chinese-style.html",
        "template": "family-chinese-style",
        "title": "Chinese style screens",
    },
    {
        "raw": "pages/stainless-steel.html",
        "template": "family-stainless-steel",
        "title": "Stainless steel screens",
    },
    {
        "raw": "pages/laser-cut-panels.html",
        "template": "family-laser-cut-panels",
        "title": "Laser-cut panels",
    },
    {
        "raw": "pages/perforated-panels.html",
        "template": "family-perforated-panels",
        "title": "Perforated panels",
    },
    {
        "raw": "pages/expanded-metal.html",
        "template": "family-expanded-metal",
        "title": "Expanded metal panels",
    },
    {
        "raw": "pages/manufacturing.html",
        "template": "manufacturing",
        "title": "Manufacturing process",
    },
    {
        "raw": "pages/project-gallery.html",
        "template": "project-gallery",
        "title": "Project gallery",
    },
    {"raw": "pages/about.html", "template": "about", "title": "About us"},
    {"raw": "pages/faqs.html", "template": "faqs", "title": "FAQs"},
    {
        "raw": "pages/custom-screens.html",
        "template": "custom-screens",
        "title": "Custom metal divider screens",
    },
]


def volume_for(section_name: str, template: str) -> str:
    """The volume a section's styles belong to."""
    name = section_name.lower()
    for volume, pattern in COMPONENTS:
        if pattern.search(name):
            return volume
    return template


#: The component volume names, for the distribution pass's cross-page
#: decision (a class several volumes want goes to the component).
COMPONENT_VOLUMES = frozenset(volume for volume, _pattern in COMPONENTS)


def is_chrome(section_name: str) -> bool:
    return section_name in CHROME_SECTIONS
