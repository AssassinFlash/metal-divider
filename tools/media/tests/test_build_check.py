"""End to end over the synthetic corpus: build, resume, sidecars, check.

These are the acceptance assertions of spec §9 in miniature: full build with
zero errors, checkpoint idempotence (second run builds nothing and touches
no mtime), the §6.4 check suite green, and each tamper it must catch.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest
from corpus import Corpus, load_json

from md_media import build, check, params


def _run_build(corpus: Corpus) -> int:
    return build.run(
        corpus.raw, corpus.manifest_path, corpus.products_path, corpus.out, 2
    )


def _run_check(corpus: Corpus) -> int:
    return check.run(
        corpus.raw, corpus.manifest_path, corpus.products_path, corpus.out, 2
    )


def test_build_produces_the_tree_and_green_check(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0

    # the product tree: one manifest per product, files named by spec §3.1
    alpha = load_json(corpus.out / "products/alpha-test-pattern/manifest.json")
    assert alpha["schema"] == params.SCHEMA
    assert alpha["product"] == "alpha-test-pattern"
    assert [m["seq"] for m in alpha["media"]] == [1, 2, 3, 4]
    assert [m["featured"] for m in alpha["media"]] == [True, False, False, False]
    assert alpha["media"][0]["alt"] == "Alpha test pattern accent render"
    assert alpha["media"][0]["role"] == "accent render"
    top = alpha["media"][0]["tiers"][-1]
    assert top["file"] == "alpha-test-pattern--01--500w.webp"
    assert (corpus.out / "products/alpha-test-pattern" / top["file"]).exists()

    # the cut sheet is lossless on every tier
    cut = alpha["media"][2]
    assert cut["role"] == "cut sheet"
    assert cut["mode"] == "lossless"
    assert all(t["mode"] == "lossless" for t in cut["tiers"])

    # the byte-identical twin: beta's sidecar references alpha's products
    beta = load_json(corpus.out / "products/beta-test-pattern/manifest.json")
    twin = beta["media"][1]
    assert twin["source"]["sha256"] == corpus.shas["alpha-render"]
    assert twin["tiers"][-1]["file"] == (
        "products/alpha-test-pattern/alpha-test-pattern--01--500w.webp"
    )

    # the site manifest: refs grouped, shared sources point at products/,
    # videos passthrough with their posters
    site = load_json(corpus.out / "site-manifest.json")
    assert sorted(site["refs"]) == [
        "blog:design-guide",
        "collection:pattern-abstract",
        "home",
        "page:about-us",
    ]
    collection = site["refs"]["collection:pattern-abstract"][0]
    assert collection["source"]["sha256"] == corpus.shas["shared"]
    assert collection["tiers"][-1]["file"].startswith("products/alpha-")
    assert collection["alt"] == "Alpha pattern render"  # inherited, shared file
    assert collection["role"] == "pattern render"

    videos = [
        e for entries in site["refs"].values() for e in entries if e.get("passthrough")
    ]
    assert len(videos) == 2  # page:about-us + home both carry it
    video = videos[0]
    assert video["file"] == "site/_videos/abc123-hd-720p-3-0mbps-1.mp4"
    assert video["posters"], "the video lists its poster finals"
    assert video["posters"][0]["file"].startswith("site/_videos/")
    assert (corpus.out / video["file"]).exists()

    # posters dedup to one set under _videos
    posters = [p for p in (corpus.out / "site/_videos").glob("abc123-thumbnail*")]
    assert len({p.name for p in posters}) == len(params.tier_widths(800))

    # blog image narrower than 480: its own width is the single tier
    blog = site["refs"]["blog:design-guide"][0]
    assert [t["w"] for t in blog["tiers"]] == [300]

    assert _run_check(corpus) == 0


def test_second_build_is_a_no_op_touching_nothing(
    corpus: Corpus, capsys: pytest.CaptureFixture[str]
) -> None:
    assert _run_build(corpus) == 0
    before = {
        p: (p.stat().st_mtime_ns, p.stat().st_size)
        for p in corpus.out.rglob("*")
        if p.is_file()
    }
    assert _run_build(corpus) == 0
    assert "0 to build" in capsys.readouterr().out
    after = {
        p: (p.stat().st_mtime_ns, p.stat().st_size)
        for p in corpus.out.rglob("*")
        if p.is_file()
    }
    assert before == after


def test_a_touched_tier_is_rebuilt_not_trusted(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    tier = next((corpus.out / "site/home").glob("page-hero--*.webp"))
    keep = tier.read_bytes()
    tier.write_bytes(keep[:-1])  # size drift on disk
    assert _run_build(corpus) == 0  # rebuilt, not skipped
    assert tier.read_bytes() == keep  # deterministic encode restores the bytes
    assert _run_check(corpus) == 0


def test_check_catches_a_corrupted_tier(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    tier = next((corpus.out / "site/home").glob("page-hero--*.webp"))
    blob = bytearray(tier.read_bytes())
    blob[-1] ^= 0xFF
    tier.write_bytes(bytes(blob))
    assert _run_check(corpus) == 1


def test_check_catches_a_deleted_tier(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    next((corpus.out / "site/home").glob("page-hero--*.webp")).unlink()
    assert _run_check(corpus) == 1


def test_check_catches_a_drifted_source(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    (corpus.raw / "blog-img.png").write_bytes(
        (corpus.raw / "blog-img.png").read_bytes() + b"\x00"
    )
    assert _run_check(corpus) == 1


def test_check_catches_a_tampered_alt(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    path = corpus.out / "products/alpha-test-pattern/manifest.json"
    sc = load_json(path)
    sc["media"][0]["alt"] = "edited alt"
    path.write_text(json.dumps(sc), encoding="utf-8")
    assert _run_check(corpus) == 1


def test_check_fails_without_state(corpus: Corpus) -> None:
    assert _run_build(corpus) == 0
    (corpus.out / "_state.json").unlink()
    assert _run_check(corpus) == 1


def test_missing_source_is_recorded_as_a_skip(tmp_path: Path) -> None:
    corpus = Corpus(tmp_path / "corpus")
    (corpus.raw / "blog-img.png").unlink()
    assert _run_build(corpus) == 1
    st = load_json(corpus.out / "_state.json")
    assert any(s.get("local_name") == "blog-img.png" for s in st["skipped"])
    assert _run_check(corpus) == 1
