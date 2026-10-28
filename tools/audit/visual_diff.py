#!/usr/bin/env python3
"""Locate where the two screenshot batches differ, page by page.

Comparing page heights says something moved; it does not say what. This
compares the actual pixels so the report can name the regions that changed
and, just as important, the pages that did not.

Widths are identical between the batches, so a straight column comparison is
meaningful. Heights differ, so each image is compared over the overlap and
`below` reports how much content exists only in the taller capture.

    python3 tools/audit/visual_diff.py <before-dir> <after-dir>
"""

from __future__ import annotations

import os
import sys

from PIL import Image, ImageChops

# A row counts as changed when more than this share of its pixels moved. The
# threshold is not a pass/fail line; it keeps antialiasing and one-pixel
# reflow from painting the whole report red.
ROW_CHANGE_FLOOR = 0.02


def bands(diff: Image.Image, height: int) -> list[tuple[int, int, float]]:
    """Contiguous rows that changed, as (start, end, peak share)."""
    grey = diff.convert("L")
    width = grey.width
    runs: list[tuple[int, int, float]] = []
    start = None
    peak = 0.0
    for y in range(height):
        row = grey.crop((0, y, width, y + 1))
        values = row.tobytes()
        changed = sum(1 for v in values if v > 24) / width
        if changed >= ROW_CHANGE_FLOOR:
            if start is None:
                start = y
            peak = max(peak, changed)
        elif start is not None:
            runs.append((start, y - 1, peak))
            start, peak = None, 0.0
    if start is not None:
        runs.append((start, height - 1, peak))
    # Merge bands that touch: a table row and its rule are one change.
    merged: list[tuple[int, int, float]] = []
    for band in runs:
        if merged and band[0] - merged[-1][1] <= 4:
            merged[-1] = (merged[-1][0], band[1], max(merged[-1][2], band[2]))
        else:
            merged.append(band)
    return merged


def main() -> int:
    before_dir, after_dir = sys.argv[1], sys.argv[2]
    slugs = sorted(
        f[: -len("-1440.png")]
        for f in os.listdir(before_dir)
        if f.endswith("-1440.png") and not any(s in f for s in ("nav-", "search", "quote"))
    )
    print(f"{'page':<14}{'changed':>9}{'bands':>7}{'only-after':>12}{'only-before':>12}")
    for slug in slugs:
        b_path = os.path.join(before_dir, f"{slug}-1440.png")
        a_path = os.path.join(after_dir, f"{slug}-1440.png")
        if not os.path.exists(a_path):
            print(f"{slug:<14}{'MISSING':>9}")
            continue
        b = Image.open(b_path).convert("RGB")
        a = Image.open(a_path).convert("RGB")
        width = min(b.width, a.width)
        overlap = min(b.height, a.height)
        diff = ImageChops.difference(b.crop((0, 0, width, overlap)), a.crop((0, 0, width, overlap)))
        changed = sum(1 for v in diff.convert("L").tobytes() if v > 24) / (width * overlap)
        runs = bands(diff, overlap)
        only_after = max(0, a.height - b.height)
        only_before = max(0, b.height - a.height)
        print(
            f"{slug:<14}{changed:>8.2%}{len(runs):>7}{only_after:>12}{only_before:>12}"
        )
        for start, end, peak in runs[:6]:
            print(f"                 band y={start}-{end} (h={end - start + 1}, peak {peak:.0%})")
        if len(runs) > 6:
            print(f"                 … {len(runs) - 6} more bands")
    return 0


if __name__ == "__main__":
    sys.exit(main())
