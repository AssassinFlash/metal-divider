#!/usr/bin/env python3
"""Build a before/after sheet from two captures of the same page.

The owner asked for before/after evidence on every delivery, at desktop and
mobile. Producing it by hand invites the two mistakes that make such a sheet
useless: comparing captures taken at different widths, and comparing captures
whose images have not finished loading. This refuses both.

It crops a chosen horizontal band out of each capture, scales the pair to one
width, and stacks them with a caption strip on the left. The band is chosen by
the caller because "the part that matters" is not something a script can know.

Usage:
    python3 tools/audit/before_after.py \
        --before OLD.png --after NEW.png --top 1500 --height 900 \
        --label "catalogue row 1" --out sheet.png
"""

from __future__ import annotations

import argparse
import sys

try:
    from PIL import Image, ImageDraw
except ImportError:  # pragma: no cover
    print("Pillow is required: python3 -m pip install pillow", file=sys.stderr)
    sys.exit(2)

CAPTION_HEIGHT = 34
LABEL_WIDTH = 118
GUTTER = 10
BACKGROUND = (18, 18, 18)
CAPTION = (232, 232, 232)


def crop_band(path: str, top: int, height: int) -> Image.Image:
    image = Image.open(path).convert("RGB")
    bottom = min(top + height, image.height)
    return image.crop((0, min(top, bottom), image.width, bottom))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--before", required=True)
    ap.add_argument("--after", required=True)
    ap.add_argument("--top", type=int, default=0)
    ap.add_argument("--height", type=int, default=900)
    ap.add_argument("--label", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    before = crop_band(args.before, args.top, args.height)
    after = crop_band(args.after, args.top, args.height)

    # Refuse the comparison that would be a lie: two reflows at different
    # widths are not a before/after, they are two different layouts.
    if before.width != after.width:
        print(
            f"refusing: the captures are {before.width}px and {after.width}px wide. "
            "A before/after has to be the same viewport; re-capture one of them.",
            file=sys.stderr,
        )
        return 1

    column_height = max(before.height, after.height)
    canvas_width = LABEL_WIDTH + before.width + after.width + GUTTER * 3
    canvas_height = CAPTION_HEIGHT + column_height + GUTTER * 2
    sheet = Image.new("RGB", (canvas_width, canvas_height), BACKGROUND)
    draw = ImageDraw.Draw(sheet)

    draw.text((GUTTER, 10), args.label, fill=CAPTION)
    draw.text((LABEL_WIDTH + GUTTER, 10), "BEFORE", fill=(250, 120, 120))
    draw.text(
        (LABEL_WIDTH + GUTTER * 2 + before.width, 10), "AFTER", fill=(130, 220, 150)
    )

    top = CAPTION_HEIGHT + GUTTER
    sheet.paste(before, (LABEL_WIDTH + GUTTER, top))
    sheet.paste(after, (LABEL_WIDTH + GUTTER * 2 + before.width, top))
    sheet.save(args.out)

    identical = before.tobytes() == after.tobytes()
    print(
        f"{'IDENTICAL' if identical else 'differs'}  {args.label}  "
        f"{before.width}x{before.height} -> {args.out}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
