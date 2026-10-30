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


def test_normalize_alt_drops_the_superseded_material_words() -> None:
    """The alt is customer-visible: the old five-material names never reach
    a customer (owner ruling revision 6), while the pattern-name prefix, the
    role phrase the slot algorithm reads, and the separator stay intact."""
    normalize = migrate().normalize_alt
    assert (
        normalize("Aztec Corten pattern detail reference")
        == "Aztec pattern detail reference"
    )
    assert (
        normalize(
            "Indian Nights Laser Cut Corten Screens | Architectural Metal "
            "Screen Pattern official installation scene 2"
        )
        == "Indian Nights Laser Cut Screens | Architectural Metal Screen "
        "Pattern official installation scene 2"
    )
    # Case and word boundaries: only the material word goes, never part of
    # another ("Corten" inside a longer name is not a material claim here,
    # but a substring inside a word must not be cut).
    assert normalize("Geo Criss Cross CORTEN pattern render") == (
        "Geo Criss Cross pattern render"
    )
    assert normalize("Copperfield pattern render") == "Copperfield pattern render"
    assert normalize("Mild steel panel detail reference") == "panel detail reference"
    assert (
        normalize("Aluminium powder coat finish preview")
        == "powder coat finish preview"
    )


def test_normalize_alt_leaves_permitted_and_clean_text_identical() -> None:
    """Stainless Steel is the site's one material wording and stays exactly
    as the source wrote it; a string with no superseded word is unchanged,
    so a second plan run is byte-identical."""
    normalize = migrate().normalize_alt
    for alt in (
        "Aztec | Architectural Metal Screen Pattern official installation scene 2",
        "Stainless Steel panel finish preview",
        "Akira pattern installation scene visualization",
        "",
    ):
        assert normalize(alt) == alt


def test_drop_material_words_is_byte_identical_without_a_match() -> None:
    """The function is a no-op unless a superseded word is present — not
    "equal after tidying", byte-identical.

    This runs over body HTML and over more than a thousand alts, titles and
    descriptions, and the migration must not rewrite a document that has no
    material word to remove: newlines, indentation, doubled spaces, the
    spacing around a pipe, and a stray space before punctuation are the
    source's own and survive untouched. A tidy-everything implementation
    fails every case here (measured: before this rule it rewrote them all).
    """
    drop = migrate().drop_material_words
    for clean in (
        "line one\nline two",
        "<p>keeps\n  indentation</p>",
        "clean  double   spaces",
        "a|b|c",
        "  padded  ",
        "trailing   ",
        "counter with the majestic Humpback Whale . This decorative wall art",
        "part of our Abstract range . Once again, it's comprised",
        "",
        "Stainless Steel (304 / 316)",
    ):
        assert drop(clean) == clean, f"a clean string must not be rewritten: {clean!r}"

    # And the same contract holds through the two wrappers the plans use.
    assert migrate().normalize_alt("clean  alt | with  spacing") == (
        "clean  alt | with  spacing"
    )
    assert migrate().normalize_title("Clean  Title | With  Spacing") == (
        "Clean  Title | With  Spacing"
    )


def test_drop_material_words_cleans_only_what_the_removal_disturbed() -> None:
    """On a matched string the edit is confined to the word's own gaps: the
    spaces it vacated collapse, a separator it carried goes with it, and the
    newlines elsewhere in the text are still untouched."""
    drop = migrate().drop_material_words
    assert drop("Aztec  Corten  Screens") == "Aztec Screens"
    assert drop("Corten, brass, and copper") == "brass, and"
    assert drop("Mild steel; panel") == "panel"
    assert drop("The Aztec Corten Decorative Screen.") == "The Aztec Decorative Screen."
    # A body that carries the word keeps its line structure.
    body = "<p>The Corten screen.</p>\n<p>Second paragraph.</p>"
    assert drop(body) == "<p>The screen.</p>\n<p>Second paragraph.</p>"


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
    # Canonical pages are root-level (owner ruling): no 'pages' shell, no
    # parent, and the old /pages/<slug> URLs live in the redirects stage.
    assert plan["parent"] is None
    assert all(page["parent"] == 0 for page in plan["pages"])
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
    # 27 before the root-level page move, 40 after: every canonical page
    # also 301s its old /pages/<slug> URL to /<slug>/.
    assert len(entries) == 40
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
    # catalogue, and a page target resolves through the page move to the root
    # URL — no chain, and no /pages/ target survives.
    assert entries["collections/pattern-1"] == "/patterns/"
    assert entries["collections/frontpage"] == "/patterns/"
    assert entries["pages/railing-fence-infill-panels"] == "/glass-hybrid-screens/"
    assert entries["pages/custom-metal-divider"] == "/custom-metal-divider-screens/"
    assert (
        entries["pages/custom-metal-divider-screens-quote"]
        == "/custom-metal-divider-screens/"
    )
    # The two mapped rows the retirement table does not cover.
    assert entries["collections/all"] == "/patterns/"
    assert entries["policies/privacy-policy"] == "/privacy/"
    # The PDP base rule.
    assert plan["rule"] == {
        "source": "^/products/(.+)$",
        "target": "/pattern/$1",
        "regex": True,
        "basis": "PDP base /pattern/ (owner ruling revision 5)",
    }
    held = {row["source"] for row in plan["held"]}
    assert any(source.startswith("/zh") for source in held)


def test_redirects_move_every_content_page_to_its_root_url() -> None:
    """Canonical pages are root-level (owner ruling), so the URL the old
    site published for each one is a moved URL and owes a 301. Without this
    the thirteen content pages lose the routes their old links, sitemap
    entries and backlinks point at."""
    plan = migrate().build_redirects_plan(fixtures.export())
    entries = {entry["source"]: entry for entry in plan["entries"]}
    content = [
        page["handle"]
        for page in fixtures.export()["pages"]
        if page["published_at"] and page["handle"] not in migrate().RETIRED_PAGES
    ]
    assert len(content) == 13
    for handle in content:
        entry = entries[f"pages/{handle}"]
        assert entry["target"] == f"/{handle}/", f"{handle} lands at root level"
        assert "root level" in entry["basis"]
    # The retirement table still wins for the retired handles: those URLs
    # keep their spec-08 targets rather than pointing at a page.
    assert entries["pages/contact"]["target"] == "/inquiry/"
    assert entries["pages/gdpr"]["target"] == "/privacy/"


def test_redirects_never_target_a_pages_url() -> None:
    """A /pages/<slug> target is a URL no page answers on after the move:
    every one resolves to its root form instead, so an imported redirect
    cannot land on a 404 or on a second hop."""
    plan = migrate().build_redirects_plan(fixtures.export())
    stranded = [
        entry for entry in plan["entries"] if entry["target"].startswith("/pages/")
    ]
    assert stranded == []


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
