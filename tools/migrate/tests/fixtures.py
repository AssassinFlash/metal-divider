"""The fixtures of the migration tests: a small synthetic corpus shaped
exactly like the real exports (a handful of patterns covering every mapping
branch, the nine series, the 31 pages, the 16 old-site redirects), so the
plan builders run against data small enough to assert on while keeping the
real structures."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

HERE = Path(__file__).resolve().parent


def load(name: str) -> Any:
    return json.loads((HERE / "fixtures" / f"{name}.json").read_text(encoding="utf-8"))


def export() -> dict[str, Any]:
    """The synthetic export of the admin API, as build_*_plan consumes it."""
    return {
        "products": load("products"),
        "collections": load("collections"),
        "pages": load("pages"),
        "redirects": load("redirects"),
    }


def make_staging(root: Path) -> Path:
    """A synthetic media-final tree: one sidecar per product with two tiers
    per image, and the site sidecar with one page ref and one passthrough
    video entry."""
    products = load("products")
    staging = root / "media-final"
    for product in products:
        directory = staging / "products" / product["handle"]
        directory.mkdir(parents=True)
        media: list[dict[str, Any]] = []
        for node in product["media"]["nodes"]:
            filename = node["image"]["url"].rsplit("/", 1)[1]
            media.append(
                {
                    "seq": len(media) + 1,
                    "featured": len(media) == 0,
                    "role": "finish preview" if len(media) == 0 else "accent render",
                    "alt": node["alt"],
                    "source": {
                        "file": filename,
                        "sha256": f"sha-{product['handle']}-{len(media)}",
                        "width": node["image"]["width"],
                        "height": node["image"]["height"],
                        "master_src": node["image"]["url"],
                    },
                    "mode": "lossless",
                    "tiers": [
                        {
                            "w": 480,
                            "file": f"{product['handle']}--{len(media) + 1:02d}--480w.webp",
                            "bytes": 1000,
                            "sha256": f"tier-{len(media)}-480",
                        },
                        {
                            "w": 1024,
                            "file": f"{product['handle']}--{len(media) + 1:02d}--1024w.webp",
                            "bytes": 2000,
                            "sha256": f"tier-{len(media)}-1024",
                        },
                    ],
                }
            )
            for tier in media[-1]["tiers"]:
                (directory / tier["file"]).write_bytes(b"final")
        (directory / "manifest.json").write_text(
            json.dumps(
                {
                    "schema": "md-media/sidecar@1",
                    "product": product["handle"],
                    "media": media,
                }
            ),
            encoding="utf-8",
        )
    site = staging / "site"
    (site / "page" / "faqs").mkdir(parents=True)
    page_image = "site/page/faqs/caliper--480w.webp"
    (staging / page_image).write_bytes(b"final")
    (staging / "site" / "_videos").mkdir(parents=True)
    video = "site/_videos/clip-hd-720p.mp4"
    (staging / video).write_bytes(b"video")
    (staging / "site-manifest.json").write_text(
        json.dumps(
            {
                "schema": "md-media/sidecar@1",
                "refs": {
                    "page:faqs": [
                        {
                            "ref": "page:faqs",
                            "role": "unknown",
                            "alt": None,
                            "source": {
                                "file": "caliper.webp",
                                "sha256": "sha-faqs-1",
                                "width": 480,
                                "height": 320,
                                "master_src": None,
                                "cdn_path": "files/caliper.webp?v=1",
                            },
                            "mode": "q85",
                            "tiers": [
                                {
                                    "w": 480,
                                    "file": "site/page/faqs/caliper--480w.webp",
                                    "bytes": 800,
                                    "sha256": "tier-faqs-480",
                                }
                            ],
                        },
                        {
                            "ref": "page:faqs",
                            "passthrough": True,
                            "source": {
                                "file": "clip.mp4",
                                "sha256": "sha-video",
                                "cdn_path": "videos/clip.mp4?v=1",
                            },
                            "file": "site/_videos/clip-hd-720p.mp4",
                            "bytes": 6,
                            "sha256": "sha-video",
                        },
                    ]
                },
            }
        ),
        encoding="utf-8",
    )
    return staging


def metafields_of(product: dict[str, Any]) -> dict[str, str]:
    return {node["key"]: node["value"] for node in product["metafields"]["nodes"]}
