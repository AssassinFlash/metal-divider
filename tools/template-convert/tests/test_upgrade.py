"""Tests for the upgrade transforms (spec 19 §4.3's second commit)."""

from __future__ import annotations

from md_template_convert.upgrade import upgrade


def test_inquiry_form_becomes_the_cta_partial() -> None:
    html = (
        '<form method="post" action="https://api.form-data.com/f/xyz" novalidate>'
        '<input name="name"></form>'
    )
    out, ledger = upgrade(html)
    assert out == '[lc_get_partial slug="md-inquiry-cta"]'
    assert ledger == {"forms_replaced": 1, "contact_links": 0}


def test_search_and_sort_forms_stay() -> None:
    html = '<form class="pattern-catalog__search" action="/search" method="get"><input name="s"></form>'
    out, ledger = upgrade(html)
    assert "pattern-catalog__search" in out
    assert ledger["forms_replaced"] == 0


def test_contact_links_move_to_inquiry_keeping_source_params() -> None:
    html = (
        '<a href="/pages/contact?source_page=pattern-pdp&source_module=hero">Send</a>'
    )
    out, ledger = upgrade(html)
    assert (
        out == '<a href="/inquiry/?source_page=pattern-pdp&source_module=hero">Send</a>'
    )
    assert ledger["contact_links"] == 1
