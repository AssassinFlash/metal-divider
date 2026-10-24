"""The media, pages and redirects plan builders."""

from __future__ import annotations

import json

import fixtures
import pytest
from driver import migrate


def test_media_takes_the_top_tier_as_the_attachment(tmp_path: object) -> None:
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    plan = migrate().build_media_plan(fixtures.export(), staging)
    agave = next(p for p in plan["products"] if p["handle"].startswith("agave"))
    first = agave["media"][0]
    # seq 1 is the featured slot, the widest tier is the uploaded file,
    # every tier rides along for the srcset ladder.
    assert first["seq"] == 1
    assert first["attach"].endswith("--01--1024w.webp")
    assert [tier["w"] for tier in first["variants"]] == [480, 1024]
    assert first["master_src"].startswith("https://cdn.shopify.com/")
    assert first["alt"] == "Agave | Architectural Metal Screen Pattern finish preview"
    assert len(agave["media"]) == 2


def test_media_rejects_a_duplicate_source(tmp_path: object) -> None:
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    manifest = (
        staging
        / "products"
        / "agave-architectural-metal-screen-pattern"
        / "manifest.json"
    )
    sidecar = json.loads(manifest.read_text())
    sidecar["media"][1]["source"]["master_src"] = sidecar["media"][0]["source"][
        "master_src"
    ]
    manifest.write_text(json.dumps(sidecar))
    with pytest.raises(migrate().MigrationError, match="duplicate master_src"):
        migrate().build_media_plan(fixtures.export(), staging)


def test_pages_builds_the_thirteen_content_pages(tmp_path: object) -> None:
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    plan = migrate().build_pages_plan(fixtures.export(), staging, set())
    assert [page["slug"] for page in plan["pages"]] == [
        "about-us",
        "chinese-style-artistic-screens",
        "custom-metal-divider-screens",
        "expanded-metal-panels",
        "faqs",
        "glass-hybrid-screens",
        "laser-cut-geometric-metal-screens",
        "laser-cut-panels",
        "manufacturing-process",
        "outdoor-privacy-landscape-screens",
        "perforated-panels",
        "project-gallery",
        "stainless-steel-screens",
    ]
    assert plan["parent"]["slug"] == "pages"
    assert len(plan["retired"]) == 11
    assert plan["retired"]["contact"] == "/inquiry/"
    # The page media: one image entry with its tier ladder and one
    # passthrough video entry without one.
    media = {entry["source_url"]: entry for entry in plan["media"]}
    assert media[
        "https://cdn.shopify.com/s/files/1/0771/7470/2333/files/caliper.webp?v=1"
    ]["variants"] == [
        {
            "w": 480,
            "file": "site/page/faqs/caliper--480w.webp",
            "path": str(staging / "site/page/faqs/caliper--480w.webp"),
        }
    ]
    assert media[
        "https://cdn.shopify.com/s/files/1/0771/7470/2333/videos/clip.mp4?v=1"
    ] == {
        "page": "faqs",
        "source_url": "https://cdn.shopify.com/s/files/1/0771/7470/2333/videos/clip.mp4?v=1",
        "sha256": "sha-video",
        "role": "unknown",
        "alt": "",
        "attach": str(staging / "site/_videos/clip-hd-720p.mp4"),
        "variants": [],
    }


def test_pages_marks_media_shared_with_products(tmp_path: object) -> None:
    staging = fixtures.make_staging(tmp_path)  # type: ignore[arg-type]
    shared = {"sha-faqs-1"}
    plan = migrate().build_pages_plan(fixtures.export(), staging, shared)
    assert plan["media_shared_with_products"] == 1


def test_redirects_consumes_every_path_changed_row() -> None:
    plan = migrate().build_redirects_plan(fixtures.export())
    entries = {entry["source"]: entry["target"] for entry in plan["entries"]}
    assert len(entries) == 27
    # Exact-match origins are stored the way SEOPress matches them: the
    # request path with its leading slash stripped.
    # The eleven retired URLs (spec 08 X1–X4), /pages/contact included: its
    # redirect-map row is domain-only, so the retirement table is the only
    # source of its 301.
    assert entries["pages/contact"] == "/inquiry/"
    assert (
        entries["pages/custom-metal-divider-screens-thank-you"] == "/inquiry/thank-you/"
    )
    assert entries["pages/metal-divider"] == "/"
    for slug in (
        "gdpr",
        "lgpd",
        "popia",
        "pdpa",
        "appi",
        "apa-nzpa",
        "canadian-laws-compliance",
        "us-laws-compliance",
    ):
        assert entries[f"pages/{slug}"] == "/privacy/"
    # The old-site redirects resolved: deleted test collections land on the
    # series hub, live page targets are kept, /pages/about-us is superseded.
    assert entries["collections/pattern-1"] == "/shop/"
    assert entries["collections/frontpage"] == "/shop/"
    assert entries["pages/railing-fence-infill-panels"] == "/pages/glass-hybrid-screens"
    assert (
        entries["pages/custom-metal-divider"] == "/pages/custom-metal-divider-screens"
    )
    assert (
        entries["pages/custom-metal-divider-screens-quote"]
        == "/pages/custom-metal-divider-screens"
    )
    # The two mapped rows the retirement table does not cover.
    assert entries["collections/all"] == "/shop/"
    assert entries["policies/privacy-policy"] == "/privacy/"
    # The PDP base rule.
    assert plan["rule"] == {
        "source": "^/products/(.+)$",
        "target": "/pattern/$1",
        "regex": True,
        "basis": "PDP base /pattern/ (owner ruling revision 5)",
    }
    held = {row["source"] for row in plan["held"]}
    assert "/pages/about-us" in held
    assert any(source.startswith("/zh") for source in held)


def test_redirects_rejects_an_unconsumed_row(
    tmp_path: object, monkeypatch: object
) -> None:
    module = migrate()
    csv_content = (
        "source_url,target_url,note\n"
        "https://metalpartition.com/pages/somewhere,"
        "https://metal-divider.com/pages/elsewhere,HTTP 200; unmapped\n"
    )
    map_file = tmp_path / "map.csv"  # type: ignore[operator]
    map_file.write_text(csv_content)
    monkeypatch.setattr(module, "REDIRECT_MAP", map_file)  # type: ignore[attr-defined]
    with pytest.raises(module.MigrationError, match="no rule consumes it"):
        module.build_redirects_plan(fixtures.export())
