#!/usr/bin/env python3
"""Measure how much of each page is actually carrying information.

The DOM probe answers "does this container have a child"; the owner's
complaint is "this screen is not filled". Those are different questions, and
every earlier detector was answering the first one. This one reads the
rendered pixels instead: for each block of rows it asks how much of the width
is something other than the page's own background.

Two findings it produces:

  dead-well   a wide flat region sitting beside content — the photo that fills
              60% of its card, the accordion panel with one small child.
  low-detail  a run of rows whose ink coverage never rises above a floor while
              its height is large enough to have held a paragraph or a picture.

Deliberately NOT flagged: full-bleed photographs (they are the design), dark
sections whose "background" is a photograph, gradient scrims, and section
rhythm padding. The thresholds below were each raised until the page's own
measured design stopped tripping them — see the notes on each constant.

Usage:
    python3 tools/audit/density.py CAPTURE.png [...]
    python3 tools/audit/density.py --dir /tmp/md-verify --json /tmp/density.json
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter
from dataclasses import dataclass, asdict

try:
    from PIL import Image
except ImportError:  # pragma: no cover
    print("Pillow is required: python3 -m pip install pillow", file=sys.stderr)
    sys.exit(2)

# Two pixels closer than this are the same colour. The site's dark palette
# sits within 12 of itself (`#1a1a1a` / `#141414` / `#0a0a0a` are all meant to
# read as "background"), so a tight tolerance would call its own bands content.
TOLERANCE = 12

# Rows are sampled every this many pixels vertically, columns every SCAN_STEP.
SCAN_STEP = 4
ROW_STEP = 4

# A band has to be this tall before its emptiness means anything. Below it we
# are looking at the gap between a heading and its first paragraph.
MIN_BAND_PX = 200

# A band counts as a "well" when at most this share of its width carries ink.
# Measured on the pages the owner marked up: the project card's empty cell is
# 40% of its card, the accordion panel's empty field is ~28% of the row. A
# deliberately asymmetric editorial layout (image 60% / text 40%) lands at 40%
# and must NOT trip, so the floor is 25%.
MIN_INK_SHARE = 0.25

# Ink coverage below which a row is "carrying nothing".
EMPTY_ROW_SHARE = 0.06


@dataclass
class Finding:
    kind: str
    top: int
    height: int
    ink_share: float
    left_blank: int
    right_blank: int
    note: str


def palette(im: Image.Image) -> list[tuple[int, int, int]]:
    """The colours that make up this page's background. Not one colour: the
    site uses a dark ladder plus white bands, and a single sampled colour
    calls the other half of the page 'content'."""
    small = im.resize((min(im.width, 400), min(im.height, 4000)))
    counts = Counter(small.getdata())
    return [colour for colour, _ in counts.most_common(8)]


def is_bg(pixel: tuple[int, int, int], pal: list[tuple[int, int, int]]) -> bool:
    for colour in pal:
        if (abs(pixel[0] - colour[0]) <= TOLERANCE
                and abs(pixel[1] - colour[1]) <= TOLERANCE
                and abs(pixel[2] - colour[2]) <= TOLERANCE):
            return True
    return False


def row_profile(im: Image.Image, pal: list[tuple[int, int, int]]) -> list[tuple[int, float, int, int]]:
    """Per-band ink share and the blank margins either side of the ink."""
    width = im.width
    columns = list(range(0, width, SCAN_STEP))
    profile = []
    for top in range(0, im.height, ROW_STEP):
        band_rows = range(top, min(top + ROW_STEP, im.height))
        inked = 0
        samples = 0
        first_ink = None
        last_ink = None
        for x in columns:
            column_has_ink = False
            for y in band_rows:
                samples += 1
                if not is_bg(im.getpixel((x, y)), pal):
                    column_has_ink = True
            if column_has_ink:
                inked += 1
                if first_ink is None:
                    first_ink = x
                last_ink = x
        share = inked / len(columns) if columns else 0.0
        left = first_ink if first_ink is not None else width
        right = (width - last_ink) if last_ink is not None else width
        profile.append((top, share, left, right))
    return profile


def analyse(path: str) -> dict:
    im = Image.open(path).convert("RGB")
    pal = palette(im)
    profile = row_profile(im, pal)

    findings: list[Finding] = []
    run: list[tuple[int, float, int, int]] = []

    def flush() -> None:
        nonlocal run
        if not run:
            return
        top = run[0][0]
        bottom = run[-1][0] + ROW_STEP
        height = bottom - top
        if height >= MIN_BAND_PX:
            peak = max(t[1] for t in run)
            mean = sum(t[1] for t in run) / len(run)
            # The band's own margins, averaged, so a band that is blank on
            # both sides is distinguished from one blank on one side only.
            left = sum(t[2] for t in run) / len(run)
            right = sum(t[3] for t in run) / len(run)
            if peak <= EMPTY_ROW_SHARE:
                findings.append(Finding(
                    kind="blank-band", top=top, height=height,
                    ink_share=round(mean, 4),
                    left_blank=int(left), right_blank=int(right),
                    note="no ink anywhere in the width",
                ))
            elif mean <= MIN_INK_SHARE and height >= MIN_BAND_PX * 2:
                side = "right" if left < right else "left"
                findings.append(Finding(
                    kind="dead-well", top=top, height=height,
                    ink_share=round(mean, 4),
                    left_blank=int(left), right_blank=int(right),
                    note=f"content pools on the {side}, the rest is flat background",
                ))
        run = []

    for row in profile:
        if row[1] <= EMPTY_ROW_SHARE:
            run.append(row)
        else:
            flush()

    flush()

    return {
        "file": os.path.basename(path),
        "width": im.width,
        "height": im.height,
        "palette": [f"#{r:02x}{g:02x}{b:02x}" for r, g, b in pal],
        "findings": [asdict(f) for f in findings],
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("images", nargs="*")
    ap.add_argument("--dir")
    ap.add_argument("--json")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    paths = list(args.images)
    if args.dir:
        for name in sorted(os.listdir(args.dir)):
            if name.lower().endswith((".png", ".jpg", ".jpeg")):
                paths.append(os.path.join(args.dir, name))
    if not paths:
        ap.error("give at least one image or --dir")

    results = []
    total = 0
    for path in paths:
        result = analyse(path)
        results.append(result)
        total += len(result["findings"])
        if not args.quiet:
            head = f"{result['file']}  {result['width']}x{result['height']}"
            if not result["findings"]:
                print(f"{head}  clean")
            else:
                print(f"{head}  {len(result['findings'])} finding(s)")
            for finding in result["findings"]:
                print(f"   {finding['kind']:12} y={finding['top']:6d}"
                      f"  h={finding['height']:5d}"
                      f"  ink={finding['ink_share']:.3f}"
                      f"  blank L{finding['left_blank']}/R{finding['right_blank']}"
                      f"  {finding['note']}")

    print(f"\n{len(paths)} capture(s), {total} finding(s)")
    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(results, handle, indent=2)
        print(f"wrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
