"""Encoding tests: adaptive modes, forced lossless, pixel identity (§2.2)."""

from __future__ import annotations

import io
from pathlib import Path

from PIL import Image

from md_media import images, params


def _decode(blob: bytes) -> Image.Image:
    img = Image.open(io.BytesIO(blob))
    img.load()
    return img


def _png(width: int, height: int, kind: str = "flat") -> Image.Image:
    if kind == "flat":
        return Image.new("RGB", (width, height), (10, 200, 30))
    return Image.effect_noise((width, height), 90).convert("RGB")


def _jpeg(width: int, height: int) -> Image.Image:
    img = Image.effect_noise((width, height), 80).convert("RGB")
    buf = io.BytesIO()
    img.save(buf, format="JPEG", quality=92)
    return Image.open(buf)


def test_flat_png_chooses_lossless_and_decodes_identical() -> None:
    source = _png(1000, 700)
    tiers = images.encode_tiers(source, "accent render", "PNG")
    assert [t.width for t in tiers] == [480, 768, 1000]
    for tier in tiers:
        assert tier.mode == params.MODE_LOSSLESS
        assert images.pixels_equal(
            _decode(tier.data), images.resize_tier(source, tier.width)
        )


def test_textured_png_may_choose_q85_per_tier() -> None:
    source = _png(1000, 700, kind="noise")
    tiers = images.encode_tiers(source, "accent render", "PNG")
    # Whatever each tier picked, the smaller of the two encodings won.
    for tier in tiers:
        resized = images.resize_tier(source, tier.width)
        lossless = images.encode_webp(resized, params.MODE_LOSSLESS)
        lossy = images.encode_webp(resized, params.MODE_Q85)
        expected = (
            params.MODE_LOSSLESS if len(lossless) <= len(lossy) else params.MODE_Q85
        )
        assert tier.mode == expected
        if tier.mode == params.MODE_LOSSLESS:
            assert images.pixels_equal(_decode(tier.data), resized)


def test_cut_sheet_is_forced_lossless_even_when_q85_is_smaller() -> None:
    source = _png(1000, 700, kind="noise")
    tiers = images.encode_tiers(source, params.ROLE_CUT_SHEET, "PNG")
    assert tiers
    top = tiers[-1]
    lossy = images.encode_webp(images.resize_tier(source, top.width), params.MODE_Q85)
    # the premise of the test: without the force, q85 would have won
    assert len(lossy) < len(top.data)
    for tier in tiers:
        assert tier.mode == params.MODE_LOSSLESS
        assert images.pixels_equal(
            _decode(tier.data), images.resize_tier(source, tier.width)
        )


def test_jpeg_source_is_q85_only() -> None:
    source = _jpeg(1200, 900)
    tiers = images.encode_tiers(source, "local installation scene", "JPEG")
    assert [t.width for t in tiers] == [480, 768, 1024, 1200]
    assert all(t.mode == params.MODE_Q85 for t in tiers)


def test_palette_png_normalises_and_survives_lossless() -> None:
    rgb = _png(600, 400)
    pal = rgb.convert("P")
    normalised = images.normalise_mode(pal)
    assert normalised.mode == "RGB"
    tiers = images.encode_tiers(normalised, "pattern render", "PNG")
    top = tiers[-1]
    assert images.pixels_equal(_decode(top.data), normalised)


def test_rgba_alpha_survives_the_roundtrip() -> None:
    rgba = Image.new("RGBA", (500, 400), (1, 2, 3, 128))
    tiers = images.encode_tiers(rgba, "finish preview", "PNG")
    top = tiers[-1]
    decoded = _decode(top.data)
    assert decoded.mode == "RGBA"
    assert images.pixels_equal(decoded, rgba)


def test_lossless_tiers_of_real_world_content_decode_identical() -> None:
    # Flat colors survive even a lossy-ish lossless encode; real renders do
    # not. This is the regression test for exact=True: without it, libwebp
    # converts RGBA through YUV and the decode drifts (found on the corpus:
    # a 1500x850 accent render failed pixel equality at exact=False).
    source = Image.effect_noise((640, 480), 120).convert("RGB").convert("RGBA")
    source.putalpha(Image.effect_noise((640, 480), 100))
    tiers = images.encode_tiers(source, "accent render", "PNG")
    for tier in tiers:
        if tier.mode != params.MODE_LOSSLESS:
            continue
        decoded = _decode(tier.data)
        assert images.pixels_equal(decoded, images.resize_tier(source, tier.width))


def test_tier_height_rounds_half_up() -> None:
    source = Image.new("RGB", (3, 2))
    assert images.tier_height(source, 3) == 2
    assert images.tier_height(source, 2) == 1  # 2 * 2/3 = 1.33 -> 1
    wide = Image.new("RGB", (1000, 501))
    assert images.tier_height(wide, 480) == 240  # 501 * 0.48 = 240.48 -> 240
    assert images.tier_height(wide, 768) == 385  # 501 * 0.768 = 384.77 -> 385


def test_sniff_format_ignores_the_extension(tmp_path: Path) -> None:
    fake = tmp_path / "liar.webp"
    fake.write_bytes(_png_bytes())
    assert images.sniff_format(str(fake)) == "PNG"


def _png_bytes() -> bytes:
    buf = io.BytesIO()
    _png(10, 10).save(buf, format="PNG")
    return buf.getvalue()
