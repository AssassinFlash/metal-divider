"""Remote mode (--wp): media and page entries bind through the dev
attachment map, and an entry the map cannot resolve is a plan-time error,
never a broken attach path on the target host."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import fixtures
import pytest
from driver import migrate

CALIPER_URL = "https://cdn.shopify.com/s/files/1/0771/7470/2333/files/caliper.webp?v=1"
VIDEO_URL = "https://cdn.shopify.com/s/files/1/0771/7470/2333/videos/clip.mp4?v=1"


def product_map(staging: Path) -> dict[str, dict[str, Any]]:
    """A dev map for the fixture corpus: every master_src bound to its file
    and the variant ladder the dev import would have recorded."""
    local = migrate().build_media_plan(fixtures.export(), staging)
    amap: dict[str, dict[str, Any]] = {}
    for product in local["products"]:
        for entry in product["media"]:
            amap[entry["master_src"]] = {
                "file": f"2026/10/{entry['attach'].rsplit('/', 1)[1]}",
                "variants": [
                    {"w": tier["w"], "f": tier["file"].rsplit("/", 1)[-1]}
                    for tier in entry["variants"]
                ],
            }
    return amap


def test_media_plan_binds_attach_uploads_in_remote_mode(tmp_path: object) -> None:
    module = migrate()
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    amap = product_map(staging)
    plan = module.build_media_plan(fixtures.export(), staging, amap)
    assert plan["remote"] is True
    entries = [e for p in plan["products"] for e in p["media"]]
    # Every entry carries the map's uploads-relative file and the variant
    # names the dev import recorded, keyed by its own source URL.
    assert all(
        entry["attach_uploads"] == amap[entry["master_src"]]["file"]
        for entry in entries
    )
    assert all(
        entry["attach_uploads_variants"] == amap[entry["master_src"]]["variants"]
        for entry in entries
    )
    assert all(not entry["attach_uploads"].startswith("/") for entry in entries)


def test_media_plan_refuses_unmapped_entries_in_remote_mode(tmp_path: object) -> None:
    module = migrate()
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    amap = product_map(staging)
    dropped = amap.pop(next(iter(amap)))
    assert dropped
    with pytest.raises(
        module.MigrationError, match="have no row in dev-attachment-map.json"
    ):
        module.build_media_plan(fixtures.export(), staging, amap)


def test_local_media_plan_stays_free_of_attach_uploads(tmp_path: object) -> None:
    module = migrate()
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    plan = module.build_media_plan(fixtures.export(), staging)
    assert plan["remote"] is False
    assert all(
        "attach_uploads" not in entry
        for product in plan["products"]
        for entry in product["media"]
    )


def test_pages_plan_binds_mapped_media_and_allows_the_reuse_path(
    tmp_path: object,
) -> None:
    module = migrate()
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    amap = {
        CALIPER_URL: {
            "file": "2026/10/caliper--480w.webp",
            "variants": [{"w": 480, "f": "caliper--480w.webp"}],
        },
        VIDEO_URL: {"file": "2026/10/clip.mp4", "variants": []},
    }
    plan = module.build_pages_plan(fixtures.export(), staging, set(), amap)
    media = {entry["source_url"]: entry for entry in plan["media"]}
    assert media[CALIPER_URL]["attach_uploads"] == "2026/10/caliper--480w.webp"
    assert media[VIDEO_URL]["attach_uploads"] == "2026/10/clip.mp4"
    assert media[VIDEO_URL]["attach_uploads_variants"] == []
    # An unmapped entry whose sha256 a product already owns is the reuse
    # path (media/write-page-media): lawful without attach_uploads.
    plan = module.build_pages_plan(
        fixtures.export(), staging, {"sha-faqs-1"}, {VIDEO_URL: amap[VIDEO_URL]}
    )
    media = {entry["source_url"]: entry for entry in plan["media"]}
    assert "attach_uploads" not in media[CALIPER_URL]
    assert media[VIDEO_URL]["attach_uploads"] == "2026/10/clip.mp4"


def test_pages_plan_refuses_unmapped_and_unshared_media(tmp_path: object) -> None:
    module = migrate()
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    # The video neither maps nor shares bytes with anything: no remote
    # binding exists for it.
    with pytest.raises(
        module.MigrationError, match="neither through dev-attachment-map.json"
    ):
        module.build_pages_plan(
            fixtures.export(),
            staging,
            {"sha-faqs-1"},
            {
                CALIPER_URL: {
                    "file": "2026/10/caliper--480w.webp",
                    "variants": [],
                }
            },
        )


def test_load_attachment_map_reads_the_vendored_file(
    tmp_path: object, monkeypatch: object
) -> None:
    module = migrate()
    good = tmp_path / "map.json"  # type: ignore[operator]
    good.write_text(
        json.dumps(
            {
                "schema": "md-migrate/attachment-map@1",
                "map": {
                    "https://cdn.example/a.webp": {
                        "file": "2026/10/a.webp",
                        "variants": [{"w": 480, "f": "a-480w.webp"}],
                    }
                },
            }
        )
    )
    monkeypatch.setattr(module, "ATTACHMENT_MAP", good)  # type: ignore[attr-defined]
    assert module.load_attachment_map() == {
        "https://cdn.example/a.webp": {
            "file": "2026/10/a.webp",
            "variants": [{"w": 480, "f": "a-480w.webp"}],
        }
    }
    good.write_text(json.dumps({"schema": "something-else", "map": {}}))
    with pytest.raises(module.MigrationError, match="schema"):
        module.load_attachment_map()
    good.write_text(
        json.dumps(
            {"schema": "md-migrate/attachment-map@1", "map": {"s": "bare-string"}}
        )
    )
    with pytest.raises(module.MigrationError, match="not a"):
        module.load_attachment_map()


def test_the_vendored_map_covers_the_real_corpus(tmp_path: object) -> None:
    """The committed map is not a fixture: every real media entry resolves,
    with the variant ladder dev recorded. Skipped wherever the corpus is
    absent (CI: the corpus lives on this Mac only, spec 18 §1)."""
    module = migrate()
    data = module.Path.home() / "Documents" / "metal-divider" / "data"
    staging = data / "media-final"
    if not (data / "shopify-admin-export").is_dir():
        pytest.skip("no corpus on this machine (spec 18 §1)")
    export = {
        "products": module.read_json(data / "shopify-admin-export" / "products.json")[
            "products"
        ],
    }
    amap = module.load_attachment_map()
    plan = module.build_media_plan(export, staging, amap)
    total = sum(len(p["media"]) for p in plan["products"])
    assert total == module.EXPECTED_COUNTS["product_media"]
    assert all("attach_uploads" in e for p in plan["products"] for e in p["media"])
    assert all(
        isinstance(e["attach_uploads_variants"], list)
        for p in plan["products"]
        for e in p["media"]
    )
