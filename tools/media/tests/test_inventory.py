"""Inventory tests: roles, slugs, ownership, dedup, naming (§3.1–§3.2)."""

from __future__ import annotations

from corpus import Corpus, make_png

from md_media import inventory, params


def test_role_vocabulary_and_casing() -> None:
    assert inventory.parse_role("Apiary | Pattern finish preview") == "finish preview"
    assert inventory.parse_role("Cellular Flow fabricated panel QC photo") == "qc photo"
    assert (
        inventory.parse_role("Floral Geo gallery supplement 2") == params.ROLE_UNKNOWN
    )
    assert inventory.parse_role("Allure pattern installation scene visualization") == (
        params.ROLE_UNKNOWN
    )


def test_slugify_folds_to_ascii_dashes() -> None:
    assert inventory.slugify("Alhambra_Star.webp") == "alhambra-star-webp"
    assert inventory.slugify("Ünïcodé -- Name!!") == "unicode-name"
    assert inventory.slugify("...") == ""


def test_ref_dirs_follow_the_spec_tree() -> None:
    assert inventory.ref_dir("home") == "site/home"
    assert inventory.ref_dir("page:about-us") == "site/page/about-us"
    assert inventory.ref_dir("blog:design-guide") == "site/blog/design-guide"


def test_plan_ownership_and_dedup(corpus: Corpus) -> None:
    manifest = inventory.load_manifest(corpus.manifest_path)
    by_master = {e.master_src: e for e in manifest if e.master_src}
    products = inventory.load_products(corpus.products_path, by_master)
    plan = inventory.plan(manifest, products, corpus.raw)

    units = plan.unit_by_sha
    # one unit per unique sha: 12 entries -> 9 shas (the byte-identical twin,
    # the shared product/collection file and the poster twins deduplicate)
    assert len(plan.units) == 9
    assert not plan.errors

    # the byte-identical twin is owned by the first product: one set only
    twin = units[corpus.shas["alpha-render"]]
    assert twin.out_dir == "products/alpha-test-pattern"
    assert twin.stem == "alpha-test-pattern--01"
    assert units[corpus.shas["beta-twin"]] is twin

    # the shared product/collection file lives in the product tree
    shared = units[corpus.shas["shared"]]
    assert shared.out_dir == "products/alpha-test-pattern"

    # posters and their video live in site/_videos
    assert units[corpus.shas["poster"]].out_dir == "site/_videos"
    assert units[corpus.shas["poster2"]] is units[corpus.shas["poster"]]
    video = units[corpus.shas["video"]]
    assert video.kind == "video"
    assert video.video_name == "abc123-hd-720p-3-0mbps-1.mp4"

    # the multi-ref page image sits under its first (sorted) ref: home
    assert units[corpus.shas["page-hero"]].out_dir == "site/home"
    # cut sheets carry the force flag
    assert units[corpus.shas["alpha-cut"]].force_lossless
    # a plain product render does not
    assert not units[corpus.shas["beta-render"]].force_lossless


def test_plan_records_site_refs_for_every_non_product_ref(corpus: Corpus) -> None:
    manifest = inventory.load_manifest(corpus.manifest_path)
    by_master = {e.master_src: e for e in manifest if e.master_src}
    products = inventory.load_products(corpus.products_path, by_master)
    plan = inventory.plan(manifest, products, corpus.raw)

    assert sorted(plan.site_refs) == [
        "blog:design-guide",
        "collection:pattern-abstract",
        "home",
        "page:about-us",
    ]
    collection = plan.site_refs["collection:pattern-abstract"]
    assert [e.local_name for e in collection] == ["shared-gallery.png"]
    assert collection[0].master_src is None  # site entries carry no URL


def test_long_site_names_get_a_hash_suffix_within_96() -> None:
    sha = "ab" * 32
    long_stem = "paralocal__continental-flower-cfr-gallery-image-desktop_2x" + "x" * 120
    fitted = inventory._fit_stem(inventory.slugify(long_stem), sha)
    assert len(fitted) + len("--2048w.webp") <= params.NAME_MAX
    assert fitted.endswith(f"-{sha[:8]}")


def test_slug_collisions_in_one_dir_get_a_hash_suffix(corpus: Corpus) -> None:
    first = corpus.raw / "Hero Shot.png"
    second = corpus.raw / "hero-shot.png"
    sha1 = make_png(first, 600, 400)
    sha2 = make_png(second, 700, 400, kind="noise")
    manifest = inventory.load_manifest(corpus.manifest_path)
    for name, sha in ((first.name, sha1), (second.name, sha2)):
        manifest.append(
            inventory.ManifestEntry(
                local_name=name,
                filename=name,
                cdn_path=name,
                master_src=None,
                width=None,
                height=None,
                refs=("page:about-us",),
                bytes=100,
                sha256=sha,
            )
        )
    by_master = {e.master_src: e for e in manifest if e.master_src}
    products = inventory.load_products(corpus.products_path, by_master)
    plan = inventory.plan(manifest, products, corpus.raw)
    stems = sorted(u.stem for u in plan.units if u.out_dir == "site/page/about-us")
    assert len(stems) == 2
    assert "hero-shot" in stems
    suffixed = next(s for s in stems if s != "hero-shot")
    assert suffixed in (f"hero-shot-{sha1[:8]}", f"hero-shot-{sha2[:8]}")
