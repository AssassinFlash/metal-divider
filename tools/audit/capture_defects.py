#!/usr/bin/env python3
"""Read a full-page capture and report the layout defects pixels can show.

The DOM probe runs at the browser window's own width, so the 390 and 320
findings have to come from the captures taken with
`screenshot --width … --full-page`. What a capture can prove that the DOM
cannot:

  * the capture is a different width than asked for (a crop, not a reflow);
  * a dead vertical band — a run of rows with no content at all, which is the
    "莫名的大块空白间隙" the owner reported;
  * content that bleeds past the frame, or text pinned into the first columns.

## Why the background has to be measured first

This site is dark: `#0a0a0a` page, `#0f0f0f` bands, gold rules. A test that
compares every pixel against "the colour at the top-right corner" finds that
almost the whole page differs from it and calls 2,304 of 2,304 rows full-bleed.
The page's own background is a palette, not one colour, so the analyser takes
the five most common colours as the background and treats everything else as
content.

Usage:
    python3 tools/audit/capture_defects.py <capture.png> [--width 390] [--json]
"""
import argparse
import json
import sys
from collections import Counter

try:
    from PIL import Image
except ImportError:  # pragma: no cover - the dev Mac has Pillow
    print("Pillow is required: python3 -m pip install pillow", file=sys.stderr)
    sys.exit(2)

TOLERANCE = 14


def background_palette(im):
    """The handful of colours that make up the page's own background."""
    sample = im.resize((min(im.size[0], 300), min(im.size[1], 3000)))
    counts = Counter(sample.getdata())
    return [colour for colour, _ in counts.most_common(6)]


def is_background(pixel, palette):
    for colour in palette:
        if all(abs(a - b) <= TOLERANCE for a, b in zip(pixel, palette_colour(colour))):
            return True
    return False


def palette_colour(colour):
    return colour if isinstance(colour, tuple) else colour


def content_columns(im, y, palette):
    w, _ = im.size
    cols = []
    for x in range(w):
        if not is_background(im.getpixel((x, y)), palette):
            cols.append(x)
    return cols


def analyse(path, width):
    im = Image.open(path).convert("RGB")
    w, h = im.size
    palette = background_palette(im)

    guttered = 0        # rows where content starts well inside the frame
    bleeding = []       # rows whose content reaches both frame edges
    left_pinned = 0     # rows whose content starts in the first two columns
    dead_bands = []
    blank_run = 0

    step = 5
    for y in range(0, h, step):
        cols = content_columns(im, y, palette)
        if not cols:
            blank_run += step
            continue
        if blank_run >= 200:
            dead_bands.append({"startY": y - blank_run, "height": blank_run})
        blank_run = 0
        first, last = cols[0], cols[-1]
        if first <= 1 and last >= w - 2:
            bleeding.append(y)
        if first <= 1:
            left_pinned += 1
        if first >= 6 and last <= w - 7:
            guttered += 1

    total_rows = max(1, h // step)
    return {
        "file": path,
        "capturedWidth": w,
        "requestedWidth": width or w,
        "widthMismatch": bool(width) and w != width,
        "height": h,
        "backgroundPalette": [list(c) for c in palette],
        "rowsSampled": total_rows,
        "rowsWithSideGutter": guttered,
        "rowsBleedingToBothEdges": len(bleeding),
        "firstBleedingRow": bleeding[0] if bleeding else None,
        "rowsPinnedToLeftEdge": left_pinned,
        "deadBands": dead_bands[:8],
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("path")
    ap.add_argument("--width", type=int, default=0)
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()

    result = analyse(args.path, args.width)
    if args.json:
        print(json.dumps(result, indent=1))
    else:
        for key, value in result.items():
            print("%-28s %s" % (key, value))
    return 0


if __name__ == "__main__":
    sys.exit(main())
