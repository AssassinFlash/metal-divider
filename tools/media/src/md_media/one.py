"""`media-one` — the owner's single-file path (spec §8.3).

`just media-one ~/Desktop/foo.jpg` produces the final file next to the
input, with the corpus pipeline's exact parameters: content-adaptive
lossless/q85, width capped at 2048, no tier ladder (a blog body image is
constrained by the content width and needs no srcset family). The command
prints the path to upload; the upload itself is a plain WP media-library
add — the server never transforms a byte.
"""

from __future__ import annotations

from pathlib import Path

from . import images, inventory, params


def run(path: Path) -> int:
    if not path.is_file():
        print(f"FAIL: {path} is not a file")
        return 1
    sniffed = images.sniff_format(str(path))
    if sniffed in ("MP4", "UNKNOWN"):
        print(f"FAIL: {path.name} sniffs as {sniffed}; media-one handles images")
        return 1
    img = images.load_image(str(path))
    width = min(img.width, params.MAX_WIDTH)
    resized = images.resize_tier(img, width)
    adaptive = sniffed == "PNG"
    if adaptive:
        lossless = images.encode_webp(resized, params.MODE_LOSSLESS)
        lossy = images.encode_webp(resized, params.MODE_Q85)
        mode, data = (
            (params.MODE_LOSSLESS, lossless)
            if len(lossless) <= len(lossy)
            else (params.MODE_Q85, lossy)
        )
    else:
        mode = params.MODE_Q85
        data = images.encode_webp(resized, mode)
    out = path.with_name(f"{inventory.slugify(path.stem)}--{width}w.webp")
    out.write_bytes(data)
    print(f"{out}  ({width}w, {mode}, {len(data):,} bytes)")
    print("上传此文件(upload this file)")
    return 0
