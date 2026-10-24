"""`media-one` tests: the owner's single-file path (spec §8.3)."""

from __future__ import annotations

from pathlib import Path

import pytest
from PIL import Image

from md_media import one


def _write_png(path: Path, width: int, height: int) -> None:
    Image.new("RGB", (width, height), (5, 120, 5)).save(path, format="PNG")


def _write_jpeg(path: Path, width: int, height: int) -> None:
    Image.effect_noise((width, height), 80).convert("RGB").save(
        path, format="JPEG", quality=92
    )


def test_one_png_gets_adaptive_mode_next_to_the_input(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    src = tmp_path / "Desk Photo.png"
    _write_png(src, 3000, 2000)
    assert one.run(src) == 0
    out = tmp_path / "desk-photo--2048w.webp"
    assert out.exists()
    with Image.open(out) as img:
        assert img.format == "WEBP"
        assert img.width == 2048  # capped, never upscaled
    assert "上传此文件" in capsys.readouterr().out


def test_one_jpeg_is_q85(tmp_path: Path) -> None:
    src = tmp_path / "hero.jpg"
    _write_jpeg(src, 1000, 600)
    assert one.run(src) == 0
    out = tmp_path / "hero--1000w.webp"
    assert out.exists()  # width below the cap: the original width is the final
    with Image.open(out) as img:
        assert img.width == 1000


def test_one_refuses_videos_and_missing_files(tmp_path: Path) -> None:
    fake = tmp_path / "clip.mp4"
    fake.write_bytes(b"\x00\x00\x00\x18ftypisom")
    assert one.run(fake) == 1
    assert one.run(tmp_path / "nope.jpg") == 1
