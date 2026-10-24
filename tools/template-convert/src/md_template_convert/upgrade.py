"""The upgrade transforms (spec 19 §4.3's second commit): the listed
upgrade items applied on top of the incorporated templates, each with
its DEBT ledger row.

T5 — every inquiry form (the old site's api.form-data.com posts, the
     platform the site is retiring) becomes the single-source
     md-inquiry-cta partial (spec 08 CHECK-8: the only form left is the
     inquiry plugin's own, on /inquiry/); the old contact-page links
     point at the inquiry page instead, keeping their source params
     (spec 07 D2's source chain).
"""

from __future__ import annotations

import re

#: The old form provider's endpoint: the marker of an inquiry form
#: (search and sort forms post elsewhere and stay — their rework is the
#: U1 upgrade item, a batch of its own).
FORM_DATA_FORM = re.compile(
    r'<form\b[^>]*action=(?:"https://api\.form-data\.com/[^"]*"|\'https://api\.form-data\.com/[^\']*\')[^>]*>.*?</form>',
    re.DOTALL,
)

CONTACT_LINK = re.compile(r'href="(/pages/contact)\?([^"]*)"')

CTA_PARTIAL = '[lc_get_partial slug="md-inquiry-cta"]'


def upgrade_forms(html: str) -> tuple[str, int]:
    """T5: inquiry forms become the md-inquiry-cta partial reference."""
    out, count = FORM_DATA_FORM.subn(CTA_PARTIAL, html)
    return out, count


def upgrade_contact_links(html: str) -> tuple[str, int]:
    """T5's link half: the retired contact page's addresses become the
    inquiry page's, source parameters kept (spec 07 D2)."""
    out, count = CONTACT_LINK.subn(r'href="/inquiry/?\2"', html)
    return out, count


def upgrade(html: str) -> tuple[str, dict[str, int]]:
    """All upgrade transforms for one template."""
    html, forms = upgrade_forms(html)
    html, links = upgrade_contact_links(html)
    return html, {"forms_replaced": forms, "contact_links": links}
