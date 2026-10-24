"""Shared fixtures: a synthetic mini corpus shaped like the real one.

Real-data facts mirrored here (spec §1.1): product manifest entries carry
master_src and dimensions, site entries carry null for both; a shared
product/collection file appears as two entries with the same local_name;
byte-identical twins share one sha256 under different names; posters match
their video by the hex prefix before the first dot.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

from PIL import Image


def make_png(path: Path, width: int, height: int, kind: str = "flat") -> str:
    if kind == "flat":
        img = Image.new("RGB", (width, height), (200, 30, 40))
    else:
        img = Image.effect_noise((width, height), 90).convert("RGB")
    path.parent.mkdir(parents=True, exist_ok=True)
    img.save(path, format="PNG")
    return hashlib.sha256(path.read_bytes()).hexdigest()


def make_jpeg(path: Path, width: int, height: int) -> str:
    img = Image.effect_noise((width, height), 80).convert("RGB")
    path.parent.mkdir(parents=True, exist_ok=True)
    img.save(path, format="JPEG", quality=92)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def make_mp4(path: Path) -> str:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"\x00\x00\x00\x18ftypisom" + b"\x00" * 32)
    return hashlib.sha256(path.read_bytes()).hexdigest()


def entry(
    local_name: str,
    sha256: str,
    refs: list[str],
    *,
    master_src: str | None = None,
    width: int | None = None,
    height: int | None = None,
    cdn_path: str | None = None,
    size: int = 1000,
) -> dict[str, object]:
    return {
        "filename": local_name,
        "local_name": local_name,
        "cdn_path": cdn_path or local_name,
        "master_src": master_src,
        "width": width,
        "height": height,
        "refs": refs,
        "bytes": size,
        "sha256": sha256,
    }


class Corpus:
    """The synthetic corpus: raw files, manifest, export, and paths."""

    def __init__(self, root: Path) -> None:
        self.root = root
        self.raw = root / "media-raw"
        self.out = root / "media-final"
        self.manifest_path = root / "media-manifest.json"
        self.products_path = root / "products.json"

        raw = self.raw
        shas: dict[str, str] = {}
        shas["alpha-render"] = make_png(raw / "alpha-render.png", 500, 400)
        shas["alpha-scene"] = make_jpeg(raw / "alpha-scene.jpg", 1200, 900)
        shas["alpha-cut"] = make_png(raw / "alpha-cut.png", 900, 600, kind="noise")
        shas["beta-render"] = make_png(raw / "beta-render.png", 1000, 700)
        # byte-identical twin of alpha-render under a different name/url
        shas["beta-twin"] = shas["alpha-render"]
        (raw / "beta-twin.png").write_bytes((raw / "alpha-render.png").read_bytes())
        shas["page-hero"] = make_jpeg(raw / "page-hero.jpg", 1600, 1000)
        # shared product/collection file: same bytes under one name
        shas["shared"] = make_png(raw / "shared-gallery.png", 800, 500)
        # poster twins (byte-identical) + their video
        shas["poster"] = make_jpeg(
            raw / "abc123.thumbnail.0000000000_1100x.jpg", 800, 600
        )
        shas["poster2"] = shas["poster"]
        (raw / "abc123.thumbnail.0000000000_1200x.jpg").write_bytes(
            (raw / "abc123.thumbnail.0000000000_1100x.jpg").read_bytes()
        )
        shas["video"] = make_mp4(raw / "abc123.HD-720p-3.0Mbps-1.mp4")
        shas["blog-img"] = make_png(raw / "blog-img.png", 300, 200)

        sizes = {p.name: p.stat().st_size for p in raw.iterdir() if p.is_file()}
        self.entries = [
            entry(
                "alpha-render.png",
                shas["alpha-render"],
                ["product:alpha-test-pattern"],
                master_src="https://cdn.example/files/alpha-render.png?v=1",
                width=500,
                height=400,
                size=sizes["alpha-render.png"],
            ),
            entry(
                "alpha-scene.jpg",
                shas["alpha-scene"],
                ["product:alpha-test-pattern"],
                master_src="https://cdn.example/files/alpha-scene.jpg?v=2",
                width=1200,
                height=900,
                size=sizes["alpha-scene.jpg"],
            ),
            entry(
                "alpha-cut.png",
                shas["alpha-cut"],
                ["product:alpha-test-pattern"],
                master_src="https://cdn.example/files/alpha-cut.png?v=3",
                width=900,
                height=600,
                size=sizes["alpha-cut.png"],
            ),
            entry(
                "beta-render.png",
                shas["beta-render"],
                ["product:beta-test-pattern"],
                master_src="https://cdn.example/files/beta-render.png?v=4",
                width=1000,
                height=700,
                size=sizes["beta-render.png"],
            ),
            entry(
                "beta-twin.png",
                shas["beta-twin"],
                ["product:beta-test-pattern"],
                master_src="https://cdn.example/files/beta-twin.png?v=5",
                width=500,
                height=400,
                size=sizes["beta-twin.png"],
            ),
            entry(
                "page-hero.jpg",
                shas["page-hero"],
                ["page:about-us", "home"],
                cdn_path="pages/page-hero.jpg?v=6",
                size=sizes["page-hero.jpg"],
            ),
            entry(
                "shared-gallery.png",
                shas["shared"],
                ["product:alpha-test-pattern"],
                master_src="https://cdn.example/files/shared-gallery.png?v=7",
                width=800,
                height=500,
                size=sizes["shared-gallery.png"],
            ),
            entry(
                "shared-gallery.png",
                shas["shared"],
                ["collection:pattern-abstract"],
                cdn_path="collections/shared-gallery.jpg",
                size=sizes["shared-gallery.png"],
            ),
            entry(
                "abc123.thumbnail.0000000000_1100x.jpg",
                shas["poster"],
                ["page:about-us"],
                size=sizes["abc123.thumbnail.0000000000_1100x.jpg"],
            ),
            entry(
                "abc123.thumbnail.0000000000_1200x.jpg",
                shas["poster2"],
                ["home"],
                size=sizes["abc123.thumbnail.0000000000_1200x.jpg"],
            ),
            entry(
                "abc123.HD-720p-3.0Mbps-1.mp4",
                shas["video"],
                ["page:about-us", "home"],
                size=sizes["abc123.HD-720p-3.0Mbps-1.mp4"],
            ),
            entry(
                "blog-img.png",
                shas["blog-img"],
                ["blog:design-guide"],
                size=sizes["blog-img.png"],
            ),
        ]
        self.manifest_path.write_text(
            json.dumps({"_meta": {"count": len(self.entries)}, "files": self.entries}),
            encoding="utf-8",
        )

        self.products_path.write_text(
            json.dumps(
                {
                    "products": [
                        {
                            "handle": "alpha-test-pattern",
                            "media": {
                                "nodes": [
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/alpha-render.png?v=1"
                                        },
                                        "alt": "Alpha test pattern accent render",
                                    },
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/alpha-scene.jpg?v=2"
                                        },
                                        "alt": "Alpha local installation scene",
                                    },
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/alpha-cut.png?v=3"
                                        },
                                        "alt": "Alpha cut sheet preview",
                                    },
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/shared-gallery.png?v=7"
                                        },
                                        "alt": "Alpha pattern render",
                                    },
                                ]
                            },
                        },
                        {
                            "handle": "beta-test-pattern",
                            "media": {
                                "nodes": [
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/beta-render.png?v=4"
                                        },
                                        "alt": "Beta finish preview",
                                    },
                                    {
                                        "image": {
                                            "url": "https://cdn.example/files/beta-twin.png?v=5"
                                        },
                                        "alt": "Beta accent render",
                                    },
                                ]
                            },
                        },
                    ]
                }
            ),
            encoding="utf-8",
        )
        self.shas = shas


def load_json(path: Path) -> dict[str, Any]:
    loaded: dict[str, Any] = json.loads(path.read_text(encoding="utf-8"))
    return loaded
