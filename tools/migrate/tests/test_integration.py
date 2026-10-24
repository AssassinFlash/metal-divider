"""The gated integration read: when MD_MIGRATE_TEST_WP names a WP-CLI
command (the local dev instance by default), the preflight's environment
read must answer. Skipped everywhere else, CI included: the corpus and the
instances live on this Mac only (spec 18 §1)."""

from __future__ import annotations

import os

import pytest
from driver import migrate


@pytest.mark.skipif(
    not os.environ.get("MD_MIGRATE_TEST_WP"), reason="no MD_MIGRATE_TEST_WP"
)
def test_preflight_read_answers() -> None:
    module = migrate()
    wp = module.Wp("dev", os.environ["MD_MIGRATE_TEST_WP"])
    answer = wp.eval_file(
        {
            "stage": "preflight",
            "action": "read",
            "product_fields": list(module.PRODUCT_ACF),
            "term_fields": list(module.TERM_ACF),
        }
    )
    assert answer["plugins"]["woocommerce"]
    assert answer["plugins"]["advanced-custom-fields"]
    assert answer["plugins"]["wp-seopress"]
    assert answer["plugins"]["wp-seopress-pro"]
    assert answer["plugins"]["md-site"]
    assert answer["seopress_404"]
    assert all(answer["acf_fields"].values()), answer["acf_fields"]
