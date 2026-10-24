#!/usr/bin/env python3
"""The Shopify -> WordPress migration of metal-divider.com (ticket #31).

Seven stages, plan -> apply, driven through WP-CLI `eval-file` (spec 18,
branch research/spec-migrator; the data mapping is spec 06 §9, branch
research/spec-data-model):

  plan       read the exports, build the five stage plans, run the preflight
  terms      9 product_cat series terms — the 8 series plus gradient restored
             with its 3 real patterns (owner ruling 2026-10-24, issue #25,
             which overturns spec 06's "gradient term is not built") — with
             the ACF series meta (the five-material list is dropped as bad
             data, owner ruling revision 6)
  products   146 patterns in one Woo CRUD shape each: SKU = pattern_code,
             one series term, empty price (native unpurchasable), ACF groups
             A/B/C, SEOPress title/description; plus the Woo settings
             (product base /pattern/, category base /collections/, no
             checkout surface)
  media      the 1063 pattern images of the media-final sidecars: one
             attachment per source (the top tier), the other tiers as
             sibling files, alt and the _md_* postmeta, featured = old
             media[0], gallery = old media[1:] in order
  pages      the 13 content pages under /pages/ (the 11 other published
             pages are retired to redirects per spec 08 §2.2; the 7 drafts
             are dropped) with their source bodies, their parent 'pages'
             shell and their media
  redirects  28 redirects into SEOPress PRO redirections: the 16 old-site
             redirects resolved to final targets (15 imported, /pages/about-us
             superseded by the live page), the 11 retired URLs, two mapped
             rows, and the /products/* -> /pattern/* regex rule
  reconcile  the 13-item read-only audit; any FAIL exits non-zero

Idempotency is natural keys + diff updates, never skip-on-exist: products by
_sku, attachments by _md_source_url, terms by slug, pages by post_name,
redirects by source path. The provable definition: a second `apply --write`
of the same plan writes nothing (every ledger row identical) and reconcile
stays green.

    uv run --project tools/migrate 18-migrate.py plan
    uv run --project tools/migrate 18-migrate.py apply --stage products
    uv run --project tools/migrate 18-migrate.py apply --stage products --write
    uv run --project tools/migrate 18-migrate.py reconcile

The instance is the local `dev` of tools/local (read from
~/metal-divider-local/instances.toml); `--wp "<command>"` runs the same
driver against any other WP-CLI (a staging host over SSH). Plans, ledgers
and reports live under ~/metal-divider-local/migrate/, outside the public
repository. The WordPress side is 18-wordpress.php, which `wp eval-file -`
evaluates with the request (JSON, base64) in place of its payload
placeholder.

The corpus (data/shopify-admin-export, data/media-final, …) lives on this
Mac and is never in the repository; only the redirect map is vendored
(tools/migrate/data/redirect-map.csv, the closed ticket #4 authority), it is
public URL data with no secrets.
"""

from __future__ import annotations

import argparse
import base64
import csv
import hashlib
import json
import os
import shutil
import subprocess
import sys
import time
from html import unescape
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent
PHP = HERE / "18-wordpress.php"
REDIRECT_MAP = HERE / "data" / "redirect-map.csv"
PHP_PAYLOAD = "'md-migrate-payload'"
MARKER = "@@md-migrate@@"
LOCAL_HOME = Path(
    os.environ.get("MD_LOCAL_HOME", str(Path.home() / "metal-divider-local"))
)
RUN_DIR = LOCAL_HOME / "migrate"
OLD_CDN_BASE = "https://cdn.shopify.com/s/files/1/0771/7470/2333"

STAGES = ("terms", "products", "media", "pages", "redirects")

# The three real gradient patterns the owner re-homed onto the gradient
# series (issue #25, 2026-10-24; their export tags still say geometric).
GRADIENT_HANDLES = (
    "la-pente-gradient-architectural-metal-screen-pattern",
    "nara-gradient-architectural-metal-screen-pattern",
    "skyfall-gradient-architectural-metal-screen-pattern",
)

# The 11 retired page URLs (spec 08 §2.2 X1–X4) and where they 301. The
# other 13 of the 24 published pages are the content pages the pages stage
# builds; the 7 drafts are dropped entirely (spec 08 F3).
RETIRED_PAGES = {
    "contact": "/inquiry/",
    "custom-metal-divider-screens-thank-you": "/inquiry/thank-you/",
    "metal-divider": "/",
    "gdpr": "/privacy/",
    "lgpd": "/privacy/",
    "popia": "/privacy/",
    "pdpa": "/privacy/",
    "appi": "/privacy/",
    "apa-nzpa": "/privacy/",
    "canadian-laws-compliance": "/privacy/",
    "us-laws-compliance": "/privacy/",
}

# Old display value -> ACF choice key (spec 06 §2.2 groups A/B).
BEST_FIT = {
    "Architectural screening": "architectural-screening",
    "Decorative screening": "decorative-screening",
}
DENSITY = {
    "open": "open",
    "balanced": "balanced",
    "dense": "dense",
    "unknown": "custom",
}
DIFFICULTY = {"Simple": "simple", "Moderate": "moderate", "Complex": "complex"}

# The 18 discarded product metafield keys (spec 06 §2.3) — reconcile item 4
# proves they left no trace in wp_postmeta. pattern_materials is the
# series-level key the same revision 6 ruling discards; it is scanned with
# them so a bad terms write cannot smuggle it back either.
DROPPED_KEYS = (
    "notable_effect",
    "fabrication_note",
    "compliance_notes",
    "seo_primary_phrase",
    "seo_supporting_phrases",
    "material_options",
    "asset_count",
    "biomimicry_score",
    "cad_note",
    "customization_options",
    "finish_options_note",
    "mesh_score",
    "privacy_score",
    "recommended_spaces",
    "reference_pdf",
    "shade_score",
    "small_opening_score",
    "structure_score",
    "pattern_materials",
)

# The 17 ACF field names of plugins/md-site (groups A/B/C on the product,
# D on product_cat); the preflight proves each resolves on the instance.
PRODUCT_ACF = (
    "open_area",
    "visual_density",
    "repeat_behavior",
    "motifs",
    "best_fit",
    "cad_status",
    "summary",
    "inspiration",
    "source_site",
    "source_slug",
    "source_manifest",
    "openness_inferred_from",
    "openness_confidence",
)
TERM_ACF = (
    "accent_color",
    "difficulty_level",
    "typical_applications",
    "bottom_content",
)

# The Woo shape every product gets (spec 06 §7): the empty price is what
# makes is_purchasable() false natively, so nothing may set a price anywhere.
PRODUCT_CONSTANTS = {
    "status": "publish",
    "virtual": True,
    "stock_status": "instock",
    "manage_stock": False,
    "regular_price": "",
    "catalog_visibility": "visible",
    "tax_status": "none",
    "reviews_allowed": False,
}

# Expected export shapes (spec 18 §2 stage 0; the measured counts of #10,
# #2 and #4).
EXPECTED_COUNTS = {
    "products": 146,
    "collections": 9,
    "published_pages": 24,
    "draft_pages": 7,
    "redirects": 16,
    "manifest_entries": 1420,
    "product_media": 1063,
}


class MigrationError(RuntimeError):
    """A migration failure with a human explanation."""


# ---------------------------------------------------------------- utilities


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path) -> Any:
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=1, sort_keys=True)
        handle.write("\n")


def stamp() -> str:
    return time.strftime("%Y%m%d-%H%M%S")


def metafields(product: dict[str, Any]) -> dict[str, str]:
    """The product's metafields by key, the last one winning duplicates."""
    return {node["key"]: node["value"] for node in product["metafields"]["nodes"]}


def number_or_none(value: Any) -> float | None:
    if value is None or value == "" or value is False:
        return None
    return float(value)


def series_of(product: dict[str, Any], fields: dict[str, str]) -> str:
    """The product's series term: pattern-<style>, except the three gradient
    patterns the owner re-homed (issue #25)."""
    if product["handle"] in GRADIENT_HANDLES:
        return "pattern-gradient"
    return f"pattern-{fields['pattern_style']}"


# ---------------------------------------------------------- plan building


def build_terms_plan(export: dict[str, Any]) -> dict[str, Any]:
    """Nine series terms: name = family display name, slug = series slug,
    ACF series meta with pattern_materials dropped (revision 6)."""
    terms: list[dict[str, Any]] = []
    members: dict[str, int] = {}
    for collection in export["collections"]:
        values = {
            node["key"]: node["value"] for node in collection["metafields"]["nodes"]
        }
        difficulty = DIFFICULTY.get(values.get("difficulty_level", ""))
        if difficulty is None:
            raise MigrationError(
                f"collection {collection['handle']}: unknown difficulty_level "
                f"{values.get('difficulty_level')!r}"
            )
        terms.append(
            {
                "slug": collection["handle"],
                "name": values["family"],
                "meta": {
                    "accent_color": values["accent_color"],
                    "difficulty_level": difficulty,
                    "typical_applications": values["typical_applications"],
                    "bottom_content": values["bottom_content"],
                },
            }
        )
        members[collection["handle"]] = 0
    for product in export["products"]:
        series = series_of(product, metafields(product))
        if series not in members:
            raise MigrationError(
                f"{product['handle']}: series {series} is not a collection"
            )
        members[series] += 1
    if sum(members.values()) != len(export["products"]):
        raise MigrationError("every product must belong to exactly one series")
    return {
        "schema": "md-migrate/terms@1",
        "terms": terms,
        # gradient = the 3 re-homed patterns, geometric = 15 - 3 (issue
        # #25); every other count is the export's own.
        "expected_members": members,
        "remove_default_term": "uncategorized",
        "note": (
            "9 terms: the 8 series plus gradient, restored with its 3 real "
            "patterns (issue #25 overturns spec 06 §3's empty-gradient rule); "
            "pattern_materials is dropped (owner ruling revision 6)"
        ),
    }


def build_products_plan(export: dict[str, Any]) -> dict[str, Any]:
    """146 patterns with the Woo native fields, ACF groups A/B/C and the
    SEOPress title/description, per the spec 06 §9 mapping table."""
    skus: set[str] = set()
    products: list[dict[str, Any]] = []
    for product in export["products"]:
        fields = metafields(product)
        sku = fields["pattern_code"]
        if not sku or sku in skus:
            raise MigrationError(f"{product['handle']}: empty or duplicate SKU {sku!r}")
        skus.add(sku)
        seo = product.get("seo") or {}
        title = seo.get("title") or fields.get("title_tag") or ""
        description = seo.get("description") or fields.get("description_tag") or ""
        if not title or not description:
            raise MigrationError(f"{product['handle']}: no SEO title/description")
        best_fit = BEST_FIT.get(fields.get("best_for", ""))
        if best_fit is None:
            raise MigrationError(
                f"{product['handle']}: unknown best_for {fields.get('best_for')!r}"
            )
        density = DENSITY.get(fields.get("visual_density", ""))
        if density is None:
            raise MigrationError(
                f"{product['handle']}: unknown visual_density "
                f"{fields.get('visual_density')!r}"
            )
        summary = fields.get("pattern_summary") or ""
        products.append(
            {
                "sku": sku,
                "title": product["title"],
                "slug": product["handle"],
                "content": product["bodyHtml"] or "",
                # The summary lands twice by design (spec 06 §9): the ACF
                # field is the data, the excerpt is the PDP short slot.
                "excerpt": summary,
                "series": series_of(product, fields),
                "acf": {
                    "open_area": number_or_none(fields.get("openness_percent")),
                    "visual_density": density,
                    "repeat_behavior": fields["repeat_behavior"],
                    "motifs": json.loads(fields.get("motif_tags") or "[]"),
                    "best_fit": best_fit,
                    "cad_status": fields["cad_status"],
                    "summary": summary,
                    "inspiration": fields.get("pattern_inspiration") or "",
                    "source_site": fields["source_site"],
                    "source_slug": fields["source_slug"],
                    "source_manifest": fields.get("source_manifest") or "",
                    "openness_inferred_from": fields.get("inferred_from_pattern") or "",
                    "openness_confidence": number_or_none(
                        fields.get("inference_confidence")
                    ),
                },
                "seo": {"title": title, "description": description},
            }
        )
    return {
        "schema": "md-migrate/products@1",
        "products": products,
        # Written with the first products apply; the diff keeps later applies
        # at zero writes (spec 08 F5 + owner ruling revision 5: /pattern/).
        "settings": {
            "product_base": "/pattern/",
            "category_base": "collections",
            "remove_page_slugs": ["cart", "checkout", "my-account"],
        },
    }


def resolve_tier(path: str, manifest_dir: Path, staging_dir: Path) -> Path:
    """A sidecar tier reference: a bare name is a sibling of its manifest,
    a path is relative to the media-final root (tools/media README)."""
    if "/" in path:
        return staging_dir / path
    return manifest_dir / path


def media_entry(
    entry: dict[str, Any], manifest_dir: Path, staging_dir: Path
) -> dict[str, Any]:
    """One sidecar media entry as the media plan holds it: the attachment is
    the top (widest) tier, every tier rides along for the srcset."""
    tiers = sorted(entry["tiers"], key=lambda tier: tier["w"])
    top = tiers[-1]
    return {
        "seq": entry.get("seq"),
        "master_src": entry["source"]["master_src"],
        "role": entry.get("role") or "unknown",
        "alt": entry.get("alt") or "",
        "sha256": entry["source"]["sha256"],
        "attach": str(resolve_tier(top["file"], manifest_dir, staging_dir)),
        "variants": [
            {
                "w": tier["w"],
                "file": tier["file"],
                "path": str(resolve_tier(tier["file"], manifest_dir, staging_dir)),
            }
            for tier in tiers
        ],
    }


def build_media_plan(export: dict[str, Any], staging_dir: Path) -> dict[str, Any]:
    """One attachment per pattern image, keyed by _md_source_url = the
    sidecar's master_src."""
    if not staging_dir.is_dir():
        raise MigrationError(f"staging dir {staging_dir} does not exist")
    products: list[dict[str, Any]] = []
    seen_sources: set[str] = set()
    for product in export["products"]:
        manifest_path = staging_dir / "products" / product["handle"] / "manifest.json"
        if not manifest_path.is_file():
            raise MigrationError(f"no sidecar at {manifest_path}")
        sidecar = read_json(manifest_path)
        if sidecar.get("schema") != "md-media/sidecar@1":
            raise MigrationError(f"{manifest_path}: schema {sidecar.get('schema')!r}")
        manifest_dir = manifest_path.parent
        media: list[dict[str, Any]] = []
        for entry in sidecar["media"]:
            master_src = entry["source"]["master_src"]
            if master_src in seen_sources:
                raise MigrationError(f"duplicate master_src {master_src}")
            seen_sources.add(master_src)
            media.append(media_entry(entry, manifest_dir, staging_dir))
        if not media or sum(1 for e in media if e["seq"] == 1) != 1:
            raise MigrationError(
                f"{product['handle']}: sidecar must have exactly one seq-1 media"
            )
        products.append(
            {
                "sku": metafields(product)["pattern_code"],
                "handle": product["handle"],
                "media": media,
            }
        )
    return {
        "schema": "md-migrate/media@1",
        "staging_dir": str(staging_dir),
        "products": products,
    }


def build_pages_plan(
    export: dict[str, Any], staging_dir: Path, product_sha256: set[str]
) -> dict[str, Any]:
    """The 13 content pages under /pages/ (a parent 'pages' shell carries the
    URL prefix), their source bodies, and their media from the site sidecar.
    A page image whose bytes a product already owns (the site sidecar cites
    it into the products/ tree) reuses that attachment instead of importing a
    second copy (spec 18 §2: one import per sha256)."""
    published = {
        page["handle"]: page for page in export["pages"] if page["published_at"]
    }
    content_slugs = sorted(
        handle for handle in published if handle not in RETIRED_PAGES
    )
    missing_retired = sorted(set(RETIRED_PAGES) - set(published))
    if missing_retired:
        raise MigrationError(
            f"retired pages missing from the export: {missing_retired}"
        )
    site_manifest_path = staging_dir / "site-manifest.json"
    if not site_manifest_path.is_file():
        raise MigrationError(f"no site sidecar at {site_manifest_path}")
    refs = read_json(site_manifest_path)["refs"]

    pages: list[dict[str, Any]] = []
    media: list[dict[str, Any]] = []
    seen_media: set[str] = set()
    shared_with_products = 0
    for slug in content_slugs:
        page = published[slug]
        pages.append(
            {
                "slug": slug,
                "title": page["title"],
                "status": "publish",
                "content": page["body_html"] or "",
                "source_body": page["body_html"] or "",
                "parent": "pages",
            }
        )
        for entry in refs.get(f"page:{slug}", []):
            source_url = f"{OLD_CDN_BASE}/{entry['source']['cdn_path']}"
            if source_url in seen_media:
                continue  # one attachment per source, however many pages cite it
            seen_media.add(source_url)
            if entry.get("passthrough"):
                media.append(
                    {
                        "page": slug,
                        "source_url": source_url,
                        "sha256": entry["sha256"],
                        "role": entry.get("role") or "unknown",
                        "alt": entry.get("alt") or "",
                        "attach": str(staging_dir / entry["file"]),
                        "variants": [],
                    }
                )
                continue
            item = media_entry(entry, staging_dir, staging_dir)
            item.pop("seq")
            item.pop("master_src")
            item["page"] = slug
            item["source_url"] = source_url
            media.append(item)
    shared_with_products = sum(
        1 for entry in media if entry["variants"] and entry["sha256"] in product_sha256
    )
    return {
        "schema": "md-migrate/pages@1",
        "staging_dir": str(staging_dir),
        "parent": {
            "slug": "pages",
            "title": "Pages",
            "status": "publish",
            "content": "",
        },
        "pages": pages,
        "media": media,
        "retired": {handle: RETIRED_PAGES[handle] for handle in sorted(RETIRED_PAGES)},
        "media_shared_with_products": shared_with_products,
        "note": (
            "13 content pages of the 24 published (spec 08 §2.2): 11 retire to "
            "redirects (see the redirects plan), 7 drafts are dropped; the "
            "parent 'pages' shell is what makes the URLs /pages/<slug>"
        ),
    }


def build_redirects_plan(export: dict[str, Any]) -> dict[str, Any]:
    """Every redirect the WordPress side owns. The domain-only rows of the
    redirect map stay at the DNS layer; the path-changed rows resolve to
    their final targets (ticket #4's no-chain rule), the retired URLs follow
    spec 08, and the PENDING and /zh rows are held with their reasons."""
    series = {collection["handle"] for collection in export["collections"]}
    content_pages = {
        page["handle"]
        for page in export["pages"]
        if page["published_at"] and page["handle"] not in RETIRED_PAGES
    }
    entries: dict[str, dict[str, Any]] = {
        f"/pages/{handle}": {
            "source": f"/pages/{handle}",
            "target": target,
            "basis": "spec 08 X1–X4 retirement",
        }
        for handle, target in RETIRED_PAGES.items()
    }
    held: list[dict[str, str]] = []

    # The 16 old-site redirects, each resolved to its final destination.
    # The retired table is already in place above: /pages/contact is
    # domain-only in the redirect map (its path does not change there), so
    # its 301 cannot come from a CSV row — spec 08 is its only source.
    for redirect in export["redirects"]:
        source, target = redirect["path"], redirect["target"]
        handle = source[len("/pages/") :] if source.startswith("/pages/") else None
        if handle in RETIRED_PAGES:
            if entries[source]["target"] != RETIRED_PAGES[handle]:
                raise MigrationError(f"{source}: retired target changed")
        elif target.startswith("/collections/"):
            name = target[len("/collections/") :]
            # Deleted test collections (404 on the old site, verified
            # 2026-10-24): the series hub absorbs their equity, the same
            # destination the empty-gradient decision used.
            entries[source] = {
                "source": source,
                "target": "/shop/" if name not in series and name != "all" else target,
                "basis": "old-site redirect; deleted collection target -> /shop/"
                if name not in series
                else "old-site redirect, target kept",
            }
        elif target == "/pages/about":
            # /pages/about 404s on the old site while /pages/about-us is
            # the live page here: importing the redirect would hijack it.
            held.append(
                {
                    "source": source,
                    "reason": "superseded: live page at the same path, the old "
                    "target /pages/about 404s on the old site",
                }
            )
        elif target.startswith("/pages/"):
            name = target[len("/pages/") :]
            if name in RETIRED_PAGES:
                entries[source] = {
                    "source": source,
                    "target": RETIRED_PAGES[name],
                    "basis": "old-site redirect resolved through the retirement table",
                }
            elif name not in content_pages:
                raise MigrationError(
                    f"old-site redirect {source} -> {target}: unknown page"
                )
            else:
                entries[source] = {
                    "source": source,
                    "target": target,
                    "basis": "old-site redirect, target kept (no chain)",
                }
        else:
            raise MigrationError(
                f"old-site redirect {source} -> {target}: unknown shape"
            )

    # The redirect map's path-changed rows: every one must be consumed by a
    # rule here or the plan refuses to build (no silent drops).
    with REDIRECT_MAP.open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    old_host = "https://metalpartition.com"

    def path_of(url: str) -> str:
        return "/" + url.split("/", 3)[3]

    for row in rows:
        source_url, target_url = row["source_url"], row["target_url"]
        source = path_of(source_url)
        if source_url.startswith(f"{old_host}/zh"):
            held.append(
                {
                    "source": source,
                    "reason": "held: ZH is phase 2 (AGENTS §2, multilingual deferred)",
                }
            )
            continue
        if target_url == "PENDING":
            if source not in entries:
                note = row["note"]
                reason = note.split(";", 1)[1].strip() if ";" in note else "PENDING"
                held.append({"source": source, "reason": f"held: {reason}"})
            continue
        if target_url == "(no redirect)":
            continue  # 404 on the old site by design
        target = path_of(target_url)
        if source == target:
            continue  # domain-only: the DNS layer carries it
        if source in entries:
            if entries[source]["target"] != target:
                raise MigrationError(
                    f"{source}: redirect map says {target}, resolution says "
                    f"{entries[source]['target']}"
                )
            continue
        if source == "/collections/all":
            entries[source] = {
                "source": source,
                "target": target,
                "basis": "redirect map: collection index -> Woo shop archive",
            }
        elif source == "/policies/privacy-policy":
            entries[source] = {
                "source": source,
                "target": target,
                "basis": "redirect map: Shopify policy page -> /privacy/",
            }
        else:
            raise MigrationError(
                f"redirect map row {source} -> {target} is path-changed but no "
                "rule consumes it"
            )

    # The PDP base move (owner ruling revision 5): one regex rule for all
    # 146 product URLs, metalpartition.com and metal-divider.com alike.
    # Exact-match origins are stored the way SEOPress matches them: its
    # template_redirect handler compares post_title against the request
    # path with the leading slash stripped, so /pages/contact must live as
    # "pages/contact" (the regex rule is different: it matches the URI with
    # its slash, which is why ^/products/ works as written).
    for entry in entries.values():
        entry["source"] = entry["source"].lstrip("/")
    return {
        "schema": "md-migrate/redirects@1",
        "rule": {
            "source": "^/products/(.+)$",
            "target": "/pattern/$1",
            "regex": True,
            "basis": "PDP base /pattern/ (owner ruling revision 5)",
        },
        "entries": [entries[key] for key in sorted(entries)],
        "held": held,
        "note": (
            "/collections/pattern-gradient keeps its live path: the gradient "
            "series exists again (issue #25), the spec 08 D8 redirect is "
            "cancelled"
        ),
    }


def build_all_plans(data_dir: Path, staging_dir: Path) -> dict[str, dict[str, Any]]:
    """Loads the exports, builds the five stage plans and checks the
    measured counts of spec 18 §2 stage 0."""
    export_dir = data_dir / "shopify-admin-export"
    export = {
        "products": read_json(export_dir / "products.json")["products"],
        "collections": read_json(export_dir / "collections.json")["collections"],
        "pages": read_json(export_dir / "pages.json")["pages"],
        "redirects": read_json(export_dir / "redirects.json")["redirects"],
    }
    manifest = read_json(data_dir / "shopify-public" / "media-manifest.json")
    published = [page for page in export["pages"] if page["published_at"]]
    counts = {
        "products": len(export["products"]),
        "collections": len(export["collections"]),
        "published_pages": len(published),
        "draft_pages": len(export["pages"]) - len(published),
        "redirects": len(export["redirects"]),
        "manifest_entries": len(manifest["files"]),
    }
    product_media = sum(
        len(
            read_json(staging_dir / "products" / product["handle"] / "manifest.json")[
                "media"
            ]
        )
        for product in export["products"]
    )
    counts["product_media"] = product_media
    for name, expected in EXPECTED_COUNTS.items():
        if counts[name] != expected:
            raise MigrationError(
                f"export count {name} = {counts[name]}, expected {expected}"
            )
    handles = {product["handle"] for product in export["products"]}
    for handle in GRADIENT_HANDLES:
        if handle not in handles:
            raise MigrationError(f"gradient handle {handle} missing from the export")

    plans = {
        "terms": build_terms_plan(export),
        "products": build_products_plan(export),
        "media": build_media_plan(export, staging_dir),
        "redirects": build_redirects_plan(export),
    }
    plans["pages"] = build_pages_plan(
        export,
        staging_dir,
        {
            entry["sha256"]
            for product in plans["media"]["products"]
            for entry in product["media"]
        },
    )
    total_media = sum(len(product["media"]) for product in plans["media"]["products"])
    if total_media != EXPECTED_COUNTS["product_media"]:
        raise MigrationError(
            f"media plan has {total_media} images, expected "
            f"{EXPECTED_COUNTS['product_media']}"
        )
    return plans


def plan_inputs(data_dir: Path, staging_dir: Path) -> dict[str, str]:
    """The sha256 fingerprint of every input file, for apply-time freshness."""
    inputs: dict[str, str] = {
        name: sha256_file(data_dir / "shopify-admin-export" / f"{name}.json")
        for name in ("products", "collections", "pages", "redirects")
    }
    inputs["media-manifest.json"] = sha256_file(
        data_dir / "shopify-public" / "media-manifest.json"
    )
    inputs["redirect-map.csv"] = sha256_file(REDIRECT_MAP)
    for manifest_path in sorted((staging_dir / "products").glob("*/manifest.json")):
        inputs[f"sidecars/{manifest_path.parent.name}/manifest.json"] = sha256_file(
            manifest_path
        )
    site_sidecar = staging_dir / "site-manifest.json"
    if site_sidecar.is_file():
        inputs["sidecars/site-manifest.json"] = sha256_file(site_sidecar)
    return inputs


# ------------------------------------------------------------- WP bridge


class Wp:
    """WP-CLI of the target instance: the local one of tools/local by
    default (read from instances.toml), or any command via --wp."""

    def __init__(self, instance: str, command: str | None) -> None:
        if command:
            self.argv = command.split()
            return
        php = "/opt/homebrew/opt/php@8.3/bin/php"
        php = php if Path(php).is_file() else (shutil.which("php") or "php")
        wp_phar = shutil.which("wp")
        if wp_phar is None:
            raise MigrationError("WP-CLI is not installed (brew install wp-cli)")
        registry = LOCAL_HOME / "instances.toml"
        if not registry.is_file():
            raise MigrationError(f"no instance registry at {registry}")
        port: str | None = None
        inside = False
        for line in registry.read_text().splitlines():
            name = line.strip()
            if name.startswith("[") and name.endswith("]"):
                inside = name == f"[{instance}]"
                continue
            if inside and name.startswith("port"):
                port = name.split("=", 1)[1].strip()
                break
        if port is None:
            raise MigrationError(f"no instance {instance!r} in {registry}")
        instance_dir = LOCAL_HOME / instance
        self.argv = [
            php,
            "-c",
            str(instance_dir / "php.ini"),
            wp_phar,
            f"--path={instance_dir / 'wordpress'}",
            f"--url=http://127.0.0.1:{port}",
        ]

    def eval_file(self, request: dict[str, Any]) -> dict[str, Any]:
        """Runs 18-wordpress.php inside WordPress with the request; returns
        its answer. A per-item failure arrives as a result row, a fatal one
        as a non-zero exit."""
        php_source = PHP.read_text()
        if php_source.count(PHP_PAYLOAD) != 1:
            raise MigrationError(f"{PHP.name} must contain {PHP_PAYLOAD} exactly once")
        payload = {**request, "marker": MARKER}
        encoded = base64.b64encode(
            json.dumps(payload, ensure_ascii=False).encode()
        ).decode()
        result = subprocess.run(
            [*self.argv, "eval-file", "-"],
            input=php_source.replace(PHP_PAYLOAD, f"'{encoded}'"),
            capture_output=True,
            text=True,
            check=False,
            timeout=1800,
        )
        answer = next(
            (
                line[len(MARKER) :]
                for line in result.stdout.splitlines()
                if line.startswith(MARKER)
            ),
            None,
        )
        if result.returncode != 0 or answer is None:
            raise MigrationError(
                f"wp eval-file failed ({result.returncode}):\n"
                f"{result.stdout[-2000:]}\n{result.stderr[-2000:]}"
            )
        loaded = json.loads(answer)
        if not isinstance(loaded, dict):
            raise MigrationError("the WordPress side answered no object")
        return loaded


# ------------------------------------------------------------ diff logic


def diff_product(
    plan: dict[str, Any], current: dict[str, Any] | None
) -> tuple[bool, dict[str, Any]]:
    """What must change for one product: True + the set map when it differs
    from the plan (or does not exist), False + {} when identical."""
    if current is None:
        return True, {"create": True}
    current_acf = current.get("acf") or {}
    acf_set: dict[str, Any] = {}
    acf_delete: list[str] = []
    for name, value in plan["acf"].items():
        if name == "motifs":
            if sorted(current_acf.get(name) or []) != sorted(value or []):
                acf_set[name] = value
        elif name in ("open_area", "openness_confidence"):
            if number_or_none(current_acf.get(name)) != value:
                if value is None:
                    acf_delete.append(name)
                else:
                    acf_set[name] = value
        elif (current_acf.get(name) or "") != value:
            acf_set[name] = value
    set_map: dict[str, Any] = {
        name: plan[name]
        for name in ("title", "slug", "content", "excerpt", "series")
        if plan[name] != current.get(name)
    }
    set_map.update(
        {
            name: value
            for name, value in PRODUCT_CONSTANTS.items()
            if current.get(name) != value
        }
    )
    if acf_set:
        set_map["acf_set"] = acf_set
    if acf_delete:
        set_map["acf_delete"] = acf_delete
    for field, key in (("seo_title", "title"), ("seo_description", "description")):
        if plan["seo"][key] != (current.get("seo") or {}).get(key):
            set_map[field] = plan["seo"][key]
    return bool(set_map), set_map


def create_product_set(item: dict[str, Any]) -> dict[str, Any]:
    """The full write for a product the instance does not hold yet: every
    plan field plus the constants of spec 06 §7."""
    acf_delete = [
        name
        for name in ("open_area", "openness_confidence")
        if item["acf"][name] is None
    ]
    return {
        "create": True,
        "title": item["title"],
        "slug": item["slug"],
        "status": PRODUCT_CONSTANTS["status"],
        "content": item["content"],
        "excerpt": item["excerpt"],
        "series": item["series"],
        **PRODUCT_CONSTANTS,
        "acf_set": {
            name: value for name, value in item["acf"].items() if name not in acf_delete
        },
        "acf_delete": acf_delete,
        "seo_title": item["seo"]["title"],
        "seo_description": item["seo"]["description"],
    }


def create_page_set(item: dict[str, Any]) -> dict[str, Any]:
    return {
        "create": True,
        "title": item["title"],
        "status": item["status"],
        "content": item["content"],
        "source_body": item["source_body"],
    }


def create_redirect_set(item: dict[str, Any]) -> dict[str, Any]:
    full = {
        "enabled": "yes",
        "type": "301",
        "value": item["target"],
        "logged_status": "both",
    }
    if item.get("regex"):
        full["regex"] = "yes"
    return full


def diff_term(
    plan: dict[str, Any], current: dict[str, Any] | None
) -> tuple[bool, dict[str, Any]]:
    if current is None:
        return True, {"create": True}
    set_map: dict[str, Any] = {}
    # WordPress stores a term name's ampersands HTML-encoded ("Moroccan &
    # Arabic" lives as "Moroccan &amp; Arabic"): that is its canonical form,
    # not drift, so the comparison unescapes both sides.
    if unescape(plan["name"]) != unescape(current.get("name") or ""):
        set_map["name"] = plan["name"]
    meta_set = {
        key: value
        for key, value in plan["meta"].items()
        if (current.get("meta") or {}).get(key) != value
    }
    if meta_set:
        set_map["meta_set"] = meta_set
    return bool(set_map), set_map


def diff_page(
    plan: dict[str, Any], current: dict[str, Any] | None
) -> tuple[bool, dict[str, Any]]:
    if current is None:
        return True, {"create": True}
    set_map = {
        name: plan[name]
        for name in ("title", "status", "content", "source_body")
        if plan[name] != current.get(name)
    }
    return bool(set_map), set_map


def diff_redirect(
    plan: dict[str, Any], current: dict[str, Any] | None
) -> tuple[bool, dict[str, Any]]:
    if current is None:
        return True, {"create": True}
    want = {
        "enabled": "yes",
        "type": "301",
        "value": plan["target"],
        "logged_status": "both",
    }
    if plan.get("regex"):
        want["regex"] = "yes"
    differing = {key: value for key, value in want.items() if current.get(key) != value}
    return bool(differing), differing


def media_product_diff(
    plan_product: dict[str, Any], current: dict[str, Any] | None
) -> dict[str, Any]:
    """The per-product media write: the attachments still to import (those
    the instance lacks by _md_source_url), the missing-meta fills and the
    binding fixes; what already stands is left untouched (spec 18 §3: an
    existing attachment never gets a second sideload)."""
    if current is None:
        current = {"attachments": {}, "thumbnail": None, "gallery": []}
    known = current.get("attachments") or {}
    imports = [
        entry for entry in plan_product["media"] if entry["master_src"] not in known
    ]
    fills: list[dict[str, Any]] = []
    for entry in plan_product["media"]:
        there = known.get(entry["master_src"])
        if not there:
            continue
        if there.get("sha256") not in (None, "", entry["sha256"]):
            raise MigrationError(
                f"{entry['master_src']}: the instance holds sha256 "
                f"{there.get('sha256')}, the plan says {entry['sha256']}"
            )
        to_fill = {
            key: value
            for key, value in (("role", entry["role"]), ("sha256", entry["sha256"]))
            if there.get(key) in (None, "")
        }
        if not there.get("variants"):
            to_fill["variants"] = [
                {"w": tier["w"], "f": tier["file"]} for tier in entry["variants"]
            ]
        if to_fill:
            fills.append({"master_src": entry["master_src"], **to_fill})
    srcs = [entry["master_src"] for entry in plan_product["media"]]
    bind: dict[str, Any] = {}
    if current.get("thumbnail") != srcs[0]:
        bind["thumbnail"] = srcs[0]
    if list(current.get("gallery") or []) != srcs[1:]:
        bind["gallery"] = srcs[1:]
    return {
        "sku": plan_product["sku"],
        "handle": plan_product["handle"],
        "media": plan_product["media"],
        "import": imports,
        "fill": fills,
        "bind": bind,
    }


# ----------------------------------------------------------- apply stages


def read_plan(run_dir: Path, stage: str) -> dict[str, Any]:
    path = run_dir / "plans" / f"18-{stage}.json"
    if not path.is_file():
        raise MigrationError(
            f"no plan for {stage} at {path}; run the plan command first"
        )
    loaded: dict[str, Any] = read_json(path)
    return loaded


def check_fresh(
    run_dir: Path, plan: dict[str, Any], data_dir: Path, staging_dir: Path
) -> None:
    """Refuses to apply when any input changed since the plan was built."""
    recorded = plan.get("inputs", {})
    current = plan_inputs(data_dir, staging_dir)
    if recorded != current:
        changed = sorted(
            set(recorded) ^ set(current)
            | {
                name
                for name in set(recorded) & set(current)
                if recorded[name] != current[name]
            }
        )
        raise MigrationError(
            f"sources changed since the plan was made: {changed}; re-run plan"
        )


def save_ledger(
    run_dir: Path, stage: str, results: list[dict[str, Any]], wrote: bool
) -> dict[str, int]:
    counts = {"created": 0, "updated": 0, "identical": 0, "error": 0, "writes": 0}
    for row in results:
        counts[row["status"]] = counts.get(row["status"], 0) + 1
        if row["status"] in ("created", "updated"):
            counts["writes"] += 1
    write_json(
        run_dir / "runs" / f"{stamp()}-{stage}.json",
        {
            "stage": stage,
            "write": wrote,
            "stamp": stamp(),
            "counts": counts,
            "items": results,
        },
    )
    return counts


def merge_status(
    results: list[dict[str, Any]],
    updates: dict[str, Any],
    errors: dict[str, Any] | None = None,
) -> list[dict[str, Any]]:
    """Folds the WordPress side's per-key statuses and errors back in."""
    merged = []
    for row in results:
        if row["key"] in updates:
            row = row | {"status": updates[row["key"]]}
        if errors and row["key"] in errors:
            row = row | {"status": "error", "error": errors[row["key"]]}
        merged.append(row)
    return merged


def apply_stage(
    wp: Wp,
    run_dir: Path,
    stage: str,
    data_dir: Path,
    staging_dir: Path,
    write: bool,
    only: str | None,
) -> int:
    plan = read_plan(run_dir, stage)
    check_fresh(run_dir, plan, data_dir, staging_dir)
    if stage == "terms":
        return apply_terms(wp, run_dir, plan, write, only)
    if stage == "products":
        return apply_products(wp, run_dir, plan, write, only)
    if stage == "media":
        return apply_media(wp, run_dir, plan, write, only)
    if stage == "pages":
        return apply_pages(wp, run_dir, plan, write, only)
    return apply_redirects(wp, run_dir, plan, write, only)


def apply_terms(
    wp: Wp, run_dir: Path, plan: dict[str, Any], write: bool, only: str | None
) -> int:
    items = plan["terms"]
    if only:
        items = [item for item in items if item["slug"] == only]
        if not items:
            raise MigrationError(f"--only {only!r} matched no term")
    answer = wp.eval_file(
        {
            "stage": "terms",
            "action": "read",
            "slugs": [item["slug"] for item in items],
        }
    )
    results: list[dict[str, Any]] = []
    writes: list[dict[str, Any]] = []
    for item in items:
        current = answer["terms"].get(item["slug"])
        differs, set_map = diff_term(item, current)
        results.append(
            {"key": item["slug"], "status": "apply" if differs else "identical"}
        )
        if differs:
            # A created term needs its whole meta; an updated one only the
            # rows that drifted.
            meta = item["meta"] if current is None else set_map.get("meta_set", {})
            writes.append(
                {
                    "slug": item["slug"],
                    "name": item["name"],
                    "create": current is None,
                    "name_changed": "name" in set_map,
                    "meta_set": meta,
                }
            )
    print(
        f"terms{' (dry run)' if not write else ''}: "
        f"{sum(1 for r in results if r['status'] == 'apply')} to apply, "
        f"{sum(1 for r in results if r['status'] == 'identical')} identical"
    )
    if answer.get("default_term") is not None:
        print(
            "  default term 'uncategorized' present "
            f"(count {answer['default_term']['count']}), removal pending"
        )
    if not write:
        return 0
    if writes or answer.get("default_term") is not None:
        write_answer = wp.eval_file(
            {
                "stage": "terms",
                "action": "write",
                "items": writes,
                "remove_default_term": answer.get("default_term") is not None,
            }
        )
        results = merge_status(
            results, write_answer["results"], write_answer.get("errors")
        )
        if write_answer.get("default_term_removed"):
            results.append({"key": "uncategorized", "status": "updated"})
    counts = save_ledger(run_dir, "terms", results, True)
    print(
        f"terms: {counts['created']} created, {counts['updated']} updated, "
        f"{counts['identical']} identical, {counts['error']} errors"
    )
    return 1 if counts["error"] else 0


def settings_differs(settings: dict[str, Any], current: dict[str, Any]) -> bool:
    if current.get("permalinks") != {
        "product_base": settings["product_base"],
        "category_base": settings["category_base"],
    }:
        return True
    return bool(current.get("remove"))


def apply_products(
    wp: Wp, run_dir: Path, plan: dict[str, Any], write: bool, only: str | None
) -> int:
    items = plan["products"]
    if only:
        items = [item for item in items if item["sku"] == only]
        if not items:
            raise MigrationError(f"--only {only!r} matched no product")
    answer = wp.eval_file(
        {
            "stage": "products",
            "action": "read",
            "skus": [item["sku"] for item in items],
            "slugs": [item["slug"] for item in items],
        }
    )
    results: list[dict[str, Any]] = []
    writes: list[dict[str, Any]] = []
    for item in items:
        current = answer["products"].get(item["sku"])
        owner = answer["by_slug"].get(item["slug"])
        if current is None and owner is not None:
            results.append(
                {
                    "key": item["sku"],
                    "status": "error",
                    "error": f"slug {item['slug']} is taken by post {owner}",
                }
            )
            continue
        if current is not None and owner is not None and owner != current["id"]:
            results.append(
                {
                    "key": item["sku"],
                    "status": "error",
                    "error": f"slug {item['slug']} is taken by post {owner}",
                }
            )
            continue
        if current is None:
            set_map = create_product_set(item)
        else:
            _, set_map = diff_product(item, current)
            set_map.pop("create", None)
        differs = bool(set_map)
        results.append(
            {"key": item["sku"], "status": "apply" if differs else "identical"}
        )
        if differs:
            writes.append({"sku": item["sku"], "item": item, "set": set_map})

    settings_answer = wp.eval_file(
        {
            "stage": "products",
            "action": "read-settings",
            "settings": plan["settings"],
        }
    )
    settings_diff = settings_differs(plan["settings"], settings_answer)
    print(
        f"woo settings: {'need write' if settings_diff else 'identical'} "
        f"({settings_answer.get('detail', '')})"
    )
    print(
        f"products{' (dry run)' if not write else ''}: "
        f"{sum(1 for r in results if r['status'] == 'apply')} to apply, "
        f"{sum(1 for r in results if r['status'] == 'identical')} identical, "
        f"{sum(1 for r in results if r['status'] == 'error')} errors"
    )
    if not write:
        return 1 if any(r["status"] == "error" for r in results) else 0

    if settings_diff:
        wp.eval_file(
            {
                "stage": "products",
                "action": "write-settings",
                "settings": plan["settings"],
                "remove": settings_answer["remove"],
            }
        )
        # Its own request: WooCommerce must re-register product_cat with the
        # new bases before the rules are rebuilt, or the flush writes the
        # old ones back.
        wp.eval_file({"stage": "products", "action": "flush"})
        results.append({"key": "woo-settings", "status": "updated"})
    for start in range(0, len(writes), 25):
        write_answer = wp.eval_file(
            {
                "stage": "products",
                "action": "write",
                "items": writes[start : start + 25],
            }
        )
        results = merge_status(
            results, write_answer["results"], write_answer.get("errors")
        )
    counts = save_ledger(run_dir, "products", results, True)
    print(
        f"products: {counts['created']} created, {counts['updated']} updated, "
        f"{counts['identical']} identical, {counts['error']} errors"
    )
    return 1 if counts["error"] else 0


def apply_media(
    wp: Wp, run_dir: Path, plan: dict[str, Any], write: bool, only: str | None
) -> int:
    items = plan["products"]
    if only:
        items = [item for item in items if item["handle"] == only]
        if not items:
            raise MigrationError(f"--only {only!r} matched no product")
    results: list[dict[str, Any]] = []
    writes: list[dict[str, Any]] = []
    batch = 10
    for start in range(0, len(items), batch):
        chunk = items[start : start + batch]
        answer = wp.eval_file(
            {
                "stage": "media",
                "action": "read",
                "products": [
                    {
                        "sku": product["sku"],
                        "handle": product["handle"],
                        "master_srcs": [
                            entry["master_src"] for entry in product["media"]
                        ],
                    }
                    for product in chunk
                ],
            }
        )
        for product in chunk:
            change = media_product_diff(
                product, answer["products"].get(product["handle"])
            )
            differs = bool(change["import"] or change["fill"] or change["bind"])
            results.append(
                {
                    "key": product["handle"],
                    "status": "apply" if differs else "identical",
                }
            )
            if differs:
                writes.append(change)
        print(f"  read {min(start + batch, len(items))}/{len(items)}", file=sys.stderr)
    print(
        f"media{' (dry run)' if not write else ''}: "
        f"{sum(1 for r in results if r['status'] == 'apply')} to apply, "
        f"{sum(1 for r in results if r['status'] == 'identical')} identical"
    )
    if not write:
        return 0

    for change in writes:
        write_answer = wp.eval_file(
            {"stage": "media", "action": "write", "product": change}
        )
        results = merge_status(
            results,
            {change["handle"]: write_answer["status"]},
            {change["handle"]: write_answer.get("error")}
            if write_answer.get("error")
            else None,
        )
        print(
            f"  {change['handle']}: {write_answer['status']} "
            f"({write_answer['imported']} imported)"
            + (f" — {write_answer['error']}" if write_answer.get("error") else ""),
            file=sys.stderr,
        )
    counts = save_ledger(run_dir, "media", results, True)
    print(
        f"media: {counts['created']} created, {counts['updated']} updated, "
        f"{counts['identical']} identical, {counts['error']} errors"
    )
    return 1 if counts["error"] else 0


def apply_pages(
    wp: Wp, run_dir: Path, plan: dict[str, Any], write: bool, only: str | None
) -> int:
    items = plan["pages"]
    if only:
        items = [item for item in items if item["slug"] == only]
        if not items:
            raise MigrationError(f"--only {only!r} matched no page")
    answer = wp.eval_file(
        {
            "stage": "pages",
            "action": "read",
            "slugs": [item["slug"] for item in items],
            "parent": plan["parent"]["slug"],
            "media": [
                {"source_url": entry["source_url"], "sha256": entry["sha256"]}
                for entry in plan["media"]
            ],
        }
    )
    results: list[dict[str, Any]] = []
    writes: list[dict[str, Any]] = []
    for item in items:
        current = answer["pages"].get(item["slug"])
        if current is None:
            set_map = create_page_set(item)
        else:
            _, set_map = diff_page(item, current)
            set_map.pop("create", None)
        differs = bool(set_map)
        results.append(
            {"key": item["slug"], "status": "apply" if differs else "identical"}
        )
        if differs:
            writes.append({"item": item, "set": set_map})
    media_missing = [
        entry
        for entry in plan["media"]
        if entry["source_url"] not in (answer.get("attachments") or {})
    ]
    print(
        f"pages{' (dry run)' if not write else ''}: "
        f"{sum(1 for r in results if r['status'] == 'apply')} to apply, "
        f"{sum(1 for r in results if r['status'] == 'identical')} identical; "
        f"parent {'missing' if answer.get('parent') is None else 'present'}; "
        f"media {len(media_missing)}/{len(plan['media'])} to import"
    )
    if not write:
        return 0

    if writes or answer.get("parent") is None:
        write_answer = wp.eval_file(
            {
                "stage": "pages",
                "action": "write",
                "parent": plan["parent"] if answer.get("parent") is None else None,
                "items": writes,
            }
        )
        results = merge_status(
            results, write_answer["results"], write_answer.get("errors")
        )
        if answer.get("parent") is None:
            results.append({"key": "pages-parent", "status": "created"})
    media_status: dict[str, str] = {}
    for start in range(0, len(media_missing), 20):
        chunk = media_missing[start : start + 20]
        write_answer = wp.eval_file(
            {"stage": "media", "action": "write-page-media", "items": chunk}
        )
        media_status.update(write_answer["results"])
        print(
            f"  page media {min(start + 20, len(media_missing))}/{len(media_missing)}",
            file=sys.stderr,
        )
    for source_url, status in sorted(media_status.items()):
        if status != "present":
            results.append({"key": f"media:{source_url}", "status": status})
    counts = save_ledger(run_dir, "pages", results, True)
    print(
        f"pages: {counts['created']} created, {counts['updated']} updated, "
        f"{counts['identical']} identical, {counts['error']} errors"
    )
    return 1 if counts["error"] else 0


def apply_redirects(
    wp: Wp, run_dir: Path, plan: dict[str, Any], write: bool, only: str | None
) -> int:
    items = plan["entries"]
    if only:
        items = [item for item in items if item["source"] == only]
        if not items:
            raise MigrationError(f"--only {only!r} matched no redirect")
    rule = plan["rule"]
    sources = [item["source"] for item in items] + [rule["source"]]
    answer = wp.eval_file({"stage": "redirects", "action": "read", "sources": sources})
    results: list[dict[str, Any]] = []
    writes: list[dict[str, Any]] = []
    for item in items:
        current = answer["redirects"].get(item["source"])
        if current is None:
            set_map = create_redirect_set(item)
            differs = True
        else:
            differs, set_map = diff_redirect(item, current)
            set_map.pop("create", None)
        results.append(
            {"key": item["source"], "status": "apply" if differs else "identical"}
        )
        if differs:
            writes.append(
                {
                    "source": item["source"],
                    "target": item["target"],
                    "create": current is None,
                    "set": set_map,
                }
            )
    rule_current = answer["redirects"].get(rule["source"])
    if rule_current is None:
        rule_differs, rule_set = True, create_redirect_set(rule)
    else:
        rule_differs, rule_set = diff_redirect(rule, rule_current)
        rule_set.pop("create", None)
    results.append(
        {"key": rule["source"], "status": "apply" if rule_differs else "identical"}
    )
    if rule_differs:
        writes.append(
            {
                "source": rule["source"],
                "target": rule["target"],
                "create": rule_current is None,
                "set": rule_set,
            }
        )
    print(
        f"redirects{' (dry run)' if not write else ''}: "
        f"{sum(1 for r in results if r['status'] == 'apply')} to apply "
        f"(of {len(results)}), held {len(plan['held'])}"
    )
    for row in plan["held"]:
        print(f"  held: {row['source']} — {row['reason']}")
    if not write:
        return 0

    if writes:
        write_answer = wp.eval_file(
            {"stage": "redirects", "action": "write", "items": writes}
        )
        results = merge_status(
            results, write_answer["results"], write_answer.get("errors")
        )
    counts = save_ledger(run_dir, "redirects", results, True)
    print(
        f"redirects: {counts['created']} created, {counts['updated']} updated, "
        f"{counts['identical']} identical, {counts['error']} errors"
    )
    return 1 if counts["error"] else 0


# ------------------------------------------------------------- preflight


def preflight(wp: Wp, plans: dict[str, Any]) -> list[str]:
    """The stage-0 checks that need the instance; every FAIL stops before
    any write (the plan command itself writes nothing anywhere)."""
    problems: list[str] = []
    answer = wp.eval_file(
        {
            "stage": "preflight",
            "action": "read",
            "product_fields": list(PRODUCT_ACF),
            "term_fields": list(TERM_ACF),
        }
    )
    for name, ok in answer["acf_fields"].items():
        if not ok:
            problems.append(f"ACF field {name} does not resolve on the instance")
    for plugin in (
        "woocommerce",
        "advanced-custom-fields",
        "wp-seopress",
        "wp-seopress-pro",
        "md-site",
    ):
        if not answer["plugins"].get(plugin):
            problems.append(f"plugin {plugin} is not active")
    if not answer["seopress_404"]:
        problems.append("SEOPress PRO redirections (seopress_404) are unavailable")
    for product in plans["media"]["products"]:
        for entry in product["media"]:
            path = Path(entry["attach"])
            if not path.is_file():
                problems.append(f"missing final: {path}")
            elif path.stat().st_size == 0:
                problems.append(f"empty final: {path}")
    for entry in plans["pages"]["media"]:
        path = Path(entry["attach"])
        if not path.is_file():
            problems.append(f"missing page final: {path}")
    return problems


# ------------------------------------------------------------- reconcile


def reconcile(wp: Wp, run_dir: Path, data_dir: Path, staging_dir: Path) -> int:
    """The 13-item audit of spec 18 §4, updated for the rulings the tickets
    carried after that spec (9 series terms, /pattern/ base, 13 pages).
    Read-only; any FAIL exits non-zero."""
    plans = {stage: read_plan(run_dir, stage) for stage in STAGES}
    products_plan = plans["products"]
    media_plan = plans["media"]
    checks: list[dict[str, Any]] = []

    def check(name: str, ok: bool, detail: str) -> None:
        checks.append(
            {"check": name, "result": "PASS" if ok else "FAIL", "detail": detail}
        )

    products_answer = wp.eval_file(
        {
            "stage": "products",
            "action": "read",
            "skus": [item["sku"] for item in products_plan["products"]],
            "slugs": [item["slug"] for item in products_plan["products"]],
        }
    )
    found = {
        sku: value
        for sku, value in products_answer["products"].items()
        if value is not None
    }
    total = len(products_plan["products"])
    published = sum(1 for value in found.values() if value["status"] == "publish")
    check(
        "1 products",
        len(found) == total and published == total,
        f"{len(found)}/{total} products, {published} publish",
    )

    terms_answer = wp.eval_file(
        {
            "stage": "terms",
            "action": "read",
            "slugs": [item["slug"] for item in plans["terms"]["terms"]],
        }
    )
    series = {item["slug"] for item in plans["terms"]["terms"]}
    extra_terms = set(terms_answer["all_slugs"]) - series - {"uncategorized"}
    expected_members = plans["terms"]["expected_members"]
    actual_members = terms_answer["member_counts"]
    per_product = [value["series"] for value in found.values()]
    check(
        "2 series",
        all(slug in series for slug in per_product)
        and all(
            actual_members.get(slug, 0) == count
            for slug, count in expected_members.items()
        )
        and not extra_terms
        and terms_answer.get("default_term") is None,
        f"9 series terms, members {actual_members}, extras "
        f"{sorted(extra_terms) or 'none'}, "
        f"gradient={actual_members.get('pattern-gradient', 0)} (ruling #25)",
    )
    check(
        "3 SKUs",
        len(found) == total,
        f"{len(found)} of {total} products found by _sku (the key itself): the "
        f"values equal pattern_code by construction of the lookup",
    )
    if any(entry["result"] == "FAIL" for entry in checks):
        return finish_report(run_dir, checks, plans, products_answer, found)

    drifted = [
        item["sku"]
        for item in products_plan["products"]
        if diff_product(item, products_answer["products"].get(item["sku"]))[0]
    ]
    residue_answer = wp.eval_file(
        {"stage": "reconcile", "action": "dropped-residue", "keys": list(DROPPED_KEYS)}
    )
    check(
        "4 35-key mapping",
        not drifted and not residue_answer["hits"],
        f"the 17 migrated keys compared over all {total}: {len(drifted)} drifted; "
        f"dropped-key residue rows: {residue_answer['hits']}",
    )

    plan_nulls = [
        item for item in products_plan["products"] if item["acf"]["open_area"] is None
    ]
    instance_values = {
        item["sku"]: (found.get(item["sku"]) or {}).get("acf", {})
        for item in plan_nulls
    }
    custom_ok = all(
        values.get("visual_density") == "custom" for values in instance_values.values()
    )
    provenance_ok = all(
        values.get("openness_inferred_from")
        and number_or_none(values.get("openness_confidence")) is not None
        for values in instance_values.values()
    )
    instance_nulls = sum(
        1 for value in found.values() if value["acf"]["open_area"] in (None, "")
    )
    check(
        "5 empty values",
        len(plan_nulls) == 16 and custom_ok and provenance_ok and instance_nulls == 16,
        f"open_area empty for {len(plan_nulls)} patterns (instance holds "
        f"{instance_nulls}), visual_density=custom and provenance present for all",
    )

    media_answer = wp.eval_file(
        {
            "stage": "media",
            "action": "read",
            "products": [
                {
                    "sku": product["sku"],
                    "handle": product["handle"],
                    "master_srcs": [entry["master_src"] for entry in product["media"]],
                }
                for product in media_plan["products"]
            ],
        }
    )
    missing_attachments: list[str] = []
    bad_bindings: list[str] = []
    alt_drift: list[str] = []
    for product in media_plan["products"]:
        current = media_answer["products"].get(product["handle"]) or {}
        known = current.get("attachments") or {}
        srcs = [entry["master_src"] for entry in product["media"]]
        for entry in product["media"]:
            there = known.get(entry["master_src"])
            if not there:
                missing_attachments.append(entry["master_src"])
            elif there.get("alt", "") != entry["alt"]:
                alt_drift.append(entry["master_src"])
        if (
            current.get("thumbnail") != srcs[0]
            or list(current.get("gallery") or []) != srcs[1:]
        ):
            bad_bindings.append(product["handle"])
    gallery_slots = sum(len(p["media"]) - 1 for p in media_plan["products"])
    check(
        "6 image bindings",
        not missing_attachments and not bad_bindings and not alt_drift,
        f"1063 attachments present, featured+gallery order correct for all "
        f"{len(media_plan['products'])} ({gallery_slots} gallery slots), alt "
        f"verbatim; missing={len(missing_attachments)}, binding drift="
        f"{len(bad_bindings)}, alt drift={len(alt_drift)}",
    )

    by_count = sorted(
        (len(product["media"]), product["handle"]) for product in media_plan["products"]
    )
    sample_handles = list(
        dict.fromkeys(
            [
                by_count[0][1],
                by_count[-1][1],
                by_count[len(by_count) // 2][1],
                media_plan["products"][0]["handle"],
                media_plan["products"][-1]["handle"],
            ]
        )
    )[:5]
    sample_problems: list[str] = []
    for handle in sample_handles:
        product = next(p for p in media_plan["products"] if p["handle"] == handle)
        current = media_answer["products"].get(handle) or {}
        known = current.get("attachments") or {}
        srcs = [entry["master_src"] for entry in product["media"]]
        if current.get("thumbnail") != srcs[0]:
            sample_problems.append(f"{handle}: featured")
        if list(current.get("gallery") or []) != srcs[1:]:
            sample_problems.append(f"{handle}: gallery order")
        for entry in product["media"]:
            if (known.get(entry["master_src"]) or {}).get("alt", "") != entry["alt"]:
                sample_problems.append(f"{handle}: alt {entry['master_src']}")
    check(
        "7 image samples",
        not sample_problems,
        f"5 samples ({', '.join(h[:24] for h in sample_handles)}): featured = old "
        f"media[0], gallery order = old media order, alt verbatim; problems: "
        f"{sample_problems or 'none'}",
    )

    seo_missing = [
        item["sku"]
        for item in products_plan["products"]
        if ((found.get(item["sku"]) or {}).get("seo") or {}).get("title") in (None, "")
        or ((found.get(item["sku"]) or {}).get("seo") or {}).get("description")
        in (None, "")
    ]
    seo_drift = [
        item["sku"]
        for item in products_plan["products"][:10]
        if (found.get(item["sku"]) or {}).get("seo") != item["seo"]
    ]
    check(
        "8 SEOPress",
        not seo_missing and not seo_drift,
        f"{total - len(seo_missing)}/{total} title+description non-empty; the "
        f"first 10 compared verbatim ({len(seo_drift)} drifted)",
    )

    purchase_answer = wp.eval_file(
        {
            "stage": "reconcile",
            "action": "purchasable",
            "skus": [item["sku"] for item in products_plan["products"][:10]],
        }
    )
    purchasable = [sku for sku, ok in purchase_answer["results"].items() if ok]
    check(
        "9 unpurchasable",
        not purchasable,
        f"10 sampled: empty _regular_price and is_purchasable() false; "
        f"purchasable anyway: {purchasable or 'none'}",
    )

    pages_answer = wp.eval_file(
        {
            "stage": "pages",
            "action": "read",
            "slugs": [item["slug"] for item in plans["pages"]["pages"]],
            "parent": plans["pages"]["parent"]["slug"],
            "media": [],
        }
    )
    pages_missing = [
        item["slug"]
        for item in plans["pages"]["pages"]
        if (pages_answer["pages"].get(item["slug"]) or {}).get("source_body") is None
    ]
    check(
        "10 pages",
        not pages_missing,
        f"{len(plans['pages']['pages']) - len(pages_missing)}/"
        f"{len(plans['pages']['pages'])} content pages under /pages/ with "
        f"_md_source_body; the 11 retired live as redirects",
    )

    cdn_answer = wp.eval_file(
        {
            "stage": "reconcile",
            "action": "cdn-residue",
            "needle": "cdn.shopify.com",
        }
    )
    check(
        "11 URL residue",
        not cdn_answer["posts"] and not cdn_answer["postmeta"],
        f"cdn.shopify.com in content: {cdn_answer['posts']} posts, "
        f"{cdn_answer['postmeta']} postmeta rows (the _md_source_url provenance "
        f"key is exempt by design)",
    )

    redirects_plan = plans["redirects"]
    redirect_sources = [item["source"] for item in redirects_plan["entries"]] + [
        redirects_plan["rule"]["source"]
    ]
    redirects_answer = wp.eval_file(
        {"stage": "redirects", "action": "read", "sources": redirect_sources}
    )
    imported = sum(
        1
        for source in redirect_sources
        if (redirects_answer["redirects"].get(source) or {}).get("enabled") == "yes"
    )
    check(
        "12 redirects",
        imported == len(redirect_sources),
        f"{imported}/{len(redirect_sources)} in SEOPress redirections "
        f"({len(redirects_plan['entries'])} mapped + the /products/* rule); "
        f"{len(redirects_plan['held'])} held",
    )

    pending: list[str] = []
    for item in products_plan["products"]:
        if diff_product(item, products_answer["products"].get(item["sku"]))[0]:
            pending.append(item["sku"])
    for item in plans["terms"]["terms"]:
        if diff_term(item, terms_answer["terms"].get(item["slug"]))[0]:
            pending.append(item["slug"])
    for item in plans["pages"]["pages"]:
        if diff_page(item, pages_answer["pages"].get(item["slug"]))[0]:
            pending.append(item["slug"])
    for item in redirects_plan["entries"]:
        if diff_redirect(item, redirects_answer["redirects"].get(item["source"]))[0]:
            pending.append(item["source"])
    if diff_redirect(
        redirects_plan["rule"],
        redirects_answer["redirects"].get(redirects_plan["rule"]["source"]),
    )[0]:
        pending.append(redirects_plan["rule"]["source"])
    for product in media_plan["products"]:
        change = media_product_diff(
            product, media_answer["products"].get(product["handle"])
        )
        if change["import"] or change["fill"] or change["bind"]:
            pending.append(product["handle"])
    check(
        "13 idempotency",
        not pending,
        f"a dry run of every stage over the current instance: {len(pending)} "
        f"pending writes ({pending[:5]})",
    )
    return finish_report(run_dir, checks, plans, products_answer, found)


def finish_report(
    run_dir: Path,
    checks: list[dict[str, Any]],
    plans: dict[str, Any],
    products_answer: dict[str, Any],
    found: dict[str, Any],
) -> int:
    failed = [entry for entry in checks if entry["result"] == "FAIL"]
    siteurl = products_answer.get("siteurl", "")
    lines = [
        f"# Reconcile {stamp()} — {siteurl}",
        "",
        "| check | result | detail |",
        "|---|---|---|",
    ]
    lines += [
        f"| {entry['check']} | {entry['result']} | {entry['detail']} |"
        for entry in checks
    ]
    lines += [
        "",
        f"**{len(checks) - len(failed)}/{len(checks)} PASS**"
        + (f", {len(failed)} FAIL" if failed else ""),
        "",
        "Held redirects (not failures, spec 18 §5):",
    ]
    lines += [
        f"- {row['source']}: {row['reason']}" for row in plans["redirects"]["held"]
    ]
    report = "\n".join(lines) + "\n"
    report_path = run_dir / "reports" / f"{stamp()}-reconcile.md"
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text(report, encoding="utf-8")
    write_json(
        report_path.with_suffix(".json"),
        {
            "siteurl": siteurl,
            "checks": checks,
            "held": plans["redirects"]["held"],
            "products": len(found),
        },
    )
    for entry in checks:
        print(f"{entry['result']:4}  {entry['check']:20} {entry['detail']}")
    print(f"\n{len(checks) - len(failed)}/{len(checks)} PASS — report: {report_path}")
    return 1 if failed else 0


# ------------------------------------------------------------------- main


def describe_plan(stage: str, plan: dict[str, Any]) -> str:
    if stage == "terms":
        return f"{len(plan['terms'])} terms, members {plan['expected_members']}"
    if stage == "products":
        return f"{len(plan['products'])} products, settings {plan['settings']}"
    if stage == "media":
        return (
            f"{len(plan['products'])} products, "
            f"{sum(len(p['media']) for p in plan['products'])} images"
        )
    if stage == "pages":
        return (
            f"{len(plan['pages'])} pages + parent, {len(plan['media'])} page media, "
            f"{len(plan['retired'])} retired"
        )
    return (
        f"{len(plan['entries'])} entries + /products/* rule, {len(plan['held'])} held"
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=(__doc__ or "").split("\n\n")[0])
    parser.add_argument("command", choices=("plan", "apply", "reconcile"))
    parser.add_argument("--stage", choices=STAGES, help="the stage to apply")
    parser.add_argument(
        "--write", action="store_true", help="apply: write (default: dry run)"
    )
    parser.add_argument("--only", help="one key of the stage (sku, slug, source…)")
    parser.add_argument(
        "--instance", default="dev", help="the local instance (default dev)"
    )
    parser.add_argument("--wp", help="a full WP-CLI command to run against instead")
    parser.add_argument(
        "--data-dir",
        type=Path,
        default=Path.home() / "Documents" / "metal-divider" / "data",
        help="the corpus root (default ~/Documents/metal-divider/data)",
    )
    parser.add_argument(
        "--staging-dir",
        type=Path,
        default=None,
        help="the media-final root (default <data-dir>/media-final)",
    )
    parser.add_argument(
        "--run-dir",
        type=Path,
        default=RUN_DIR,
        help=f"plans, ledgers, reports (default {RUN_DIR})",
    )
    args = parser.parse_args(argv)
    staging_dir = args.staging_dir or (args.data_dir / "media-final")

    if args.command == "plan":
        wp = Wp(args.instance, args.wp)
        plans = build_all_plans(args.data_dir, staging_dir)
        inputs = plan_inputs(args.data_dir, staging_dir)
        problems = preflight(wp, plans)
        for stage, plan in plans.items():
            write_json(
                args.run_dir / "plans" / f"18-{stage}.json", plan | {"inputs": inputs}
            )
            print(f"plan {stage}: {describe_plan(stage, plan)}")
        if problems:
            for problem in problems:
                print(f"FAIL  {problem}", file=sys.stderr)
            return 1
        print(
            f"preflight: PASS ({len(plans)} plans written to {args.run_dir / 'plans'})"
        )
        return 0

    if args.command == "apply":
        if not args.stage:
            parser.error("apply needs --stage (one of: " + ", ".join(STAGES) + ")")
        wp = Wp(args.instance, args.wp)
        return apply_stage(
            wp,
            args.run_dir,
            args.stage,
            args.data_dir,
            staging_dir,
            args.write,
            args.only,
        )

    wp = Wp(args.instance, args.wp)
    return reconcile(wp, args.run_dir, args.data_dir, staging_dir)


if __name__ == "__main__":
    sys.exit(main())
