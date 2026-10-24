"""Image decoding, tier sizing and WebP encoding (spec §2.2–§2.3).

Decoding sniffs the real format — 299 files carry a fake .webp extension
(spec §1.2) — and applies EXIF orientation before anything else, so the
pipeline always works on the visually upright pixels.
"""

from __future__ import annotations

import io
from dataclasses import dataclass
from typing import BinaryIO

from PIL import Image, ImageOps, UnidentifiedImageError

from . import params


@dataclass(frozen=True)
class Tier:
    """One encoded variant of one source."""

    width: int
    height: int
    mode: str  # params.MODE_LOSSLESS | params.MODE_Q85
    data: bytes


def load_image(path: str | io.BytesIO | BinaryIO) -> Image.Image:
    """Open by content sniff, apply EXIF orientation, normalise the mode."""
    img = Image.open(path)
    img.load()
    oriented = ImageOps.exif_transpose(img)
    return normalise_mode(oriented)


def sniff_format(path: str | io.BytesIO | BinaryIO) -> str:
    """The real container format of a file, ignoring its extension."""
    try:
        with Image.open(path) as img:
            return img.format or "UNKNOWN"
    except UnidentifiedImageError:
        return "UNKNOWN"


def normalise_mode(img: Image.Image) -> Image.Image:
    """Reduce to a mode WebP encodes faithfully (8-bit L/RGB/RGBA).

    Palette images expand losslessly (palette expansion is exact); anything
    exotic falls back to RGB, or RGBA when an alpha channel survives.
    """
    has_alpha = bool(img.info.get("transparency")) or img.mode in {
        "RGBA",
        "LA",
        "PA",
    }
    if img.mode in ("L", "RGB", "RGBA"):
        return img
    if img.mode == "P" and not has_alpha:
        return img.convert("RGB")
    return img.convert("RGBA" if has_alpha else "RGB")


def tier_height(source: Image.Image, width: int) -> int:
    """Height of a tier: the source aspect ratio at `width`, ±0 px here."""
    if width == source.width:
        return source.height
    return int(source.height * width / source.width + 0.5)


def resize_tier(source: Image.Image, width: int) -> Image.Image:
    """Lanczos-resize to a tier; the top tier at source width is not resampled."""
    if width == source.width:
        return source
    return source.resize((width, tier_height(source, width)), Image.Resampling.LANCZOS)


def encode_webp(img: Image.Image, mode: str) -> bytes:
    """Encode one image as WebP with the spec's parameters (§2.2).

    Lossless uses exact=True: without it libwebp converts RGB→YUV first and
    the decode is not pixel-identical — the cut-sheet acceptance (decode ==
    master, pixel for pixel) requires exact.
    """
    buf = io.BytesIO()
    if mode == params.MODE_LOSSLESS:
        img.save(
            buf,
            format=params.FORMAT,
            lossless=True,
            method=params.WEBP_METHOD,
            exact=True,
        )
    else:
        img.save(
            buf,
            format=params.FORMAT,
            quality=params.LOSSY_QUALITY,
            method=params.WEBP_METHOD,
        )
    return buf.getvalue()


def encode_tiers(
    source: Image.Image,
    role: str,
    sniffed: str,
) -> list[Tier]:
    """Encode every tier of one source (§2.2–§2.3).

    - role cut sheet: forced lossless on every tier (technical drawings).
    - PNG source: lossless and q85 are both encoded per tier and the smaller
      bytes win (ties go to lossless) — flat renders beat any lossy codec,
      textured ones do not, so it is decided per file, never by role.
    - any other source: q85 only (lossless-ising a JPEG only grows).
    """
    widths = params.tier_widths(source.width)
    force_lossless = role == params.ROLE_CUT_SHEET
    adaptive = sniffed == "PNG" and not force_lossless
    tiers: list[Tier] = []
    for width in widths:
        resized = resize_tier(source, width)
        if force_lossless:
            mode = params.MODE_LOSSLESS
        elif adaptive:
            lossless = encode_webp(resized, params.MODE_LOSSLESS)
            lossy = encode_webp(resized, params.MODE_Q85)
            if len(lossless) <= len(lossy):
                mode, data = params.MODE_LOSSLESS, lossless
            else:
                mode, data = params.MODE_Q85, lossy
            tiers.append(Tier(width, resized.height, mode, data))
            continue
        else:
            mode = params.MODE_Q85
        tiers.append(Tier(width, resized.height, mode, encode_webp(resized, mode)))
    return tiers


def pixels_equal(a: Image.Image, b: Image.Image) -> bool:
    """Exact pixel comparison (acceptance: lossless tiers decode back equal).

    Modes are normalised to RGBA first: WebP may decode an L-source tier as
    RGB, and that is a container difference, not a pixel difference.
    """
    if a.mode != b.mode:
        a = a.convert("RGBA")
        b = b.convert("RGBA")
    if a.size != b.size:
        return False
    return a.tobytes() == b.tobytes()
