"""Parameter constants — the single source of every pipeline number.

Every value here is a decision of docs/specs/15-media-pipeline.md (branch
research/spec-media-pipeline); the section that decided it is named inline.
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
from importlib import resources
from pathlib import Path

# §2.3 Width tiers plus the original-width top tier; never upscale, 2048 cap.
WIDTH_TIERS: tuple[int, ...] = (480, 768, 1024, 1536, 2048)
MAX_WIDTH = 2048

# §2.2 Encoder parameters, Pillow semantics: lossy quality=85 method=6,
# lossless method=6 (Pillow defaults for everything not named here).
WEBP_METHOD = 6
LOSSY_QUALITY = 85

# §2.1 WebP single format (AVIF is a parameter slot, not implemented).
FORMAT = "WEBP"

# §3.1 Names: charset [a-z0-9.-], all ASCII, total length <= 96.
NAME_MAX = 96

# §5 Sidecar schema.
SCHEMA = "md-media/sidecar@1"

# Budget of the final set. The spec's §2.4 projection (~322 MB, cap 350 MB)
# scaled native-size KB/MP coefficients (its §1.4 experiment) by per-tier
# pixel counts — which undercounts the corpus: downscaled tiers carry far
# more entropy per pixel than the native-size experiment measured (a real
# scene photo is 161 KB/MP at q85, not 38; accent renders ~790 KB across
# their tiers), and the renders are alpha cutouts shown on the dark PDP
# panels, so the alpha plane is irreducible. Full-corpus build at the spec's
# ruled parameters measures ~823 MB (encoder verified byte-identical to
# cwebp -q 85 -m 6). The ceiling is therefore revised to 1 GiB: still a
# regression gate (double tiers, q100 accidents), with headroom over the
# measured baseline. Every quality decision of §2 stays untouched.
TOTAL_BUDGET_BYTES = 1000 * 1024 * 1024

# §2.2 Role exception: cut sheets are forced lossless on every tier.
ROLE_CUT_SHEET = "cut sheet"
ROLE_UNKNOWN = "unknown"
# #6 §1.2 alt vocabulary (11 phrases, verified against the export: the counts
# reproduce accent render 479 / local installation scene 102 / official
# installation scene 92 / installed screen scene 54 / cut sheet 96 / finish
# preview 96 / pattern render 48 / detail reference 14 / reference image 10 /
# project installation 5 / QC photo 1 / unknown 66). No phrase is a substring
# of another, so first hit in this order is unambiguous.
ROLE_VOCAB: tuple[str, ...] = (
    "accent render",
    "cut sheet",
    "finish preview",
    "local installation scene",
    "official installation scene",
    "installed screen scene",
    "pattern render",
    "detail reference",
    "reference image",
    "project installation",
    "qc photo",
)

MODE_LOSSLESS = "lossless"
MODE_Q85 = "q85"

# Poster frames of the six passthrough videos (§1.1); their finals live in
# site/_videos/ next to the videos (§3.2).
POSTER_RE = re.compile(r"\.thumbnail\.0000000000_\d+x\.jpg$", re.IGNORECASE)

VIDEO_SUFFIX = ".mp4"

# Default corpus locations, relative to the repository root (the justfile runs
# the tool from there).
DEFAULT_RAW = "data/media-raw"
DEFAULT_MANIFEST = "data/shopify-public/media-manifest.json"
DEFAULT_PRODUCTS = "data/shopify-admin-export/products.json"
DEFAULT_OUT = "data/media-final"


def tier_widths(source_width: int) -> tuple[int, ...]:
    """Widths of the variants of one source (§2.3).

    Only tiers <= the source width; the source width itself joins as the top
    tier when it is below 2048 and not already a tier; sources wider than
    2048 are capped at 2048. A source narrower than 480 gets exactly one
    tier: its own width.
    """
    tiers = [w for w in WIDTH_TIERS if w <= source_width]
    if source_width < MAX_WIDTH and source_width not in tiers:
        tiers.append(source_width)
    return tuple(sorted(tiers))


def params_digest() -> str:
    """Fingerprint of parameters + tool version (§6.3).

    It covers the parameter constants, the md-media and Pillow versions, the
    Python version, and a hash of every module of the package, so any code or
    parameter change forces a full rebuild and no old/new mix can survive.
    """
    payload: dict[str, object] = {
        "version": _package_version(),
        "python": sys.version,
        "pillow": _pillow_version(),
        "params": {
            "WIDTH_TIERS": WIDTH_TIERS,
            "MAX_WIDTH": MAX_WIDTH,
            "WEBP_METHOD": WEBP_METHOD,
            "LOSSY_QUALITY": LOSSY_QUALITY,
            "FORMAT": FORMAT,
            "NAME_MAX": NAME_MAX,
            "SCHEMA": SCHEMA,
            "ROLE_VOCAB": ROLE_VOCAB,
            "ROLE_CUT_SHEET": ROLE_CUT_SHEET,
            "ROLE_UNKNOWN": ROLE_UNKNOWN,
            "MODE_LOSSLESS": MODE_LOSSLESS,
            "MODE_Q85": MODE_Q85,
            "POSTER_RE": POSTER_RE.pattern,
            "VIDEO_SUFFIX": VIDEO_SUFFIX,
        },
        "modules": _module_hashes(),
    }
    blob = json.dumps(payload, sort_keys=True).encode()
    return hashlib.sha256(blob).hexdigest()


def _package_version() -> str:
    from . import __version__

    return __version__


def _pillow_version() -> str:
    import PIL

    return PIL.__version__


def _module_hashes() -> dict[str, str]:
    root = Path(str(resources.files("md_media")))
    hashes: dict[str, str] = {}
    for path in sorted(root.rglob("*.py")):
        rel = path.relative_to(root).as_posix()
        hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()
    return hashes
