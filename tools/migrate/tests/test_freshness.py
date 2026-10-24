"""The plan-freshness fingerprint: an apply refuses a plan whose inputs
changed, the two-safety of spec 18 §3."""

from __future__ import annotations

from pathlib import Path

import pytest
from driver import migrate


def test_check_fresh_refuses_changed_inputs(tmp_path: Path) -> None:
    module = migrate()
    data = tmp_path / "data"
    (data / "shopify-admin-export").mkdir(parents=True)
    (data / "shopify-public").mkdir(parents=True)
    (data / "media-final" / "products").mkdir(parents=True)
    inputs = {
        "products": data / "shopify-admin-export" / "products.json",
        "collections": data / "shopify-admin-export" / "collections.json",
        "pages": data / "shopify-admin-export" / "pages.json",
        "redirects": data / "shopify-admin-export" / "redirects.json",
        "media-manifest": data / "shopify-public" / "media-manifest.json",
    }
    for path in inputs.values():
        path.write_text("first")
    recorded = module.plan_inputs(data, data / "media-final")
    module.check_fresh(tmp_path, {"inputs": recorded}, data, data / "media-final")
    inputs["products"].write_text("changed")
    with pytest.raises(module.MigrationError, match="sources changed"):
        module.check_fresh(tmp_path, {"inputs": recorded}, data, data / "media-final")


def test_ledger_counts_writes(tmp_path: Path) -> None:
    module = migrate()
    counts = module.save_ledger(
        tmp_path,
        "terms",
        [
            {"key": "a", "status": "created"},
            {"key": "b", "status": "updated"},
            {"key": "c", "status": "identical"},
            {"key": "d", "status": "error"},
        ],
        True,
    )
    assert counts["writes"] == 2
    assert counts == {
        "created": 1,
        "updated": 1,
        "identical": 1,
        "error": 1,
        "writes": 2,
    }
