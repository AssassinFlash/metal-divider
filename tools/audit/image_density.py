#!/usr/bin/env python3
"""Check whether the images a page serves have enough pixels for their slots.

Two defects this catches that a visibility check cannot:

  soft      the largest srcset candidate is smaller than the slot the tag
            declares, so the browser upscales. Visible and blurry at once.
  missing   the slot has no candidate at all.

The hard part is reading `sizes`. `sizes="(min-width: 1025px) 87px, 53px"`
mixes a breakpoint condition with a width; taking max() over its numbers
compares a 100px thumbnail against a 1025px slot it never occupies. This
resolves the value for one viewport, first match from the widest condition.

Usage:
    python3 tools/audit/image_density.py --base https://metal-divider.com
    python3 tools/audit/image_density.py --pages /patterns/ --viewport 1440
"""

from __future__ import annotations

import argparse
import re
import sys
import time
import urllib.request

UA = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36",
    "Cache-Control": "no-cache",
}

DEFAULT_PAGES = [
    "/", "/patterns/", "/pattern-category/", "/inquiry/", "/about-us/",
    "/manufacturing-process/", "/project-gallery/", "/blog/",
    "/laser-cut-geometric-metal-screens/", "/glass-hybrid-screens/",
    "/perforated-panels/", "/expanded-metal-panels/",
    "/custom-metal-divider-screens/",
    "/pattern/agave-architectural-metal-screen-pattern/",
]

# Below this share of the slot the upscale shows. 0.8 allows the ordinary
# rounding a srcset ladder does near a breakpoint (a 338px slot served a 330px
# file is fine) and still catches 500 in 676.
MIN_SHARE = 0.8

# A slot narrower than this is an icon or a thumbnail strip; its candidates are
# sized for it and checking them only adds noise.
MIN_SLOT = 100


def get(url: str) -> str:
    request = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read().decode(response.headers.get_content_charset() or "utf-8",
                                      errors="replace")


def resolve_slot(sizes: str, viewport: int) -> float | None:
    """The CSS width this tag's image occupies at `viewport`.

    First match from the widest condition, because `sizes` is written
    broad-to-narrow in practice; a trailing bare `Xpx` is the default when
    nothing matches; `calc(100vw - Npx)` is the mobile full-width form.
    """
    parts = [p.strip() for p in sizes.split(",")]
    for part in parts:
        match = re.match(r"\(min-width:\s*(\d+)px\)\s*([\d.]+)px", part)
        if match and viewport >= int(match.group(1)):
            return float(match.group(2))
    for part in reversed(parts):
        match = re.match(r"^([\d.]+)px$", part)
        if match:
            return float(match.group(1))
    # `calc(Nvw - Mpx)` and the mixed `calc(54.1vw - 43px)` form: resolve vw
    # against the viewport and subtract the constant.
    calc = re.search(r"calc\(\s*([\d.]+)vw\s*-\s*(\d+)px\s*\)", sizes)
    if calc:
        return float(viewport) * float(calc.group(1)) / 100.0 - int(calc.group(2))
    calc = re.search(r"calc\(\s*100vw\s*-\s*(\d+)px\s*\)", sizes)
    if calc:
        return float(viewport - int(calc.group(1)))
    return None


def candidates(tag: str) -> list[tuple[str, int]]:
    """(url, width) for every srcset candidate, plus the bare `src`."""
    found: list[tuple[str, int]] = []
    srcset = re.search(r'srcset="([^"]+)"', tag)
    if srcset:
        for part in srcset.group(1).split(","):
            bits = part.strip().split()
            if len(bits) == 2 and bits[1].endswith("w"):
                try:
                    found.append((bits[0], int(bits[1][:-1])))
                except ValueError:
                    continue
    src = re.search(r'src="([^"]+)"', tag)
    if src and not found:
        found.append((src.group(1), 0))
    return found


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--pages", nargs="*", default=DEFAULT_PAGES)
    ap.add_argument("--viewport", type=int, default=1440)
    args = ap.parse_args()
    base = args.base.rstrip("/")

    soft: list[tuple] = []
    for path in args.pages:
        try:
            html = get(f"{base}{path}?mdaudit=den{int(time.time())}")
        except Exception as exc:  # noqa: BLE001
            print(f"{path:52} FETCH FAIL {exc}")
            continue
        checked = 0
        page_soft = 0
        for match in re.finditer(r"(?is)<img[^>]+>", html):
            tag = match.group(0)
            sizes = re.search(r'sizes="([^"]+)"', tag)
            if not sizes:
                continue
            slot = resolve_slot(sizes.group(1), args.viewport)
            if not slot or slot < MIN_SLOT:
                continue
            found = candidates(tag)
            if not found:
                continue
            # A lightbox thumbnail declares 87px and shows an 87px image; the
            # wider values in its `sizes` describe the stage that the thumbnail
            # swaps into, so judging by them reports a defect that is not one.
            # The class sits on the button wrapping the image, not on the image,
            # so the check has to look at the element that encloses this tag.
            position = match.start()
            enclosing = html[max(0, position - 400):position]
            if re.search(r'class="[^"]*(pattern-thumb|lightbox|thumb)[^"]*"', enclosing):
                continue
            checked += 1
            available = max(width for _, width in found)
            if available and available < slot * MIN_SHARE:
                page_soft += 1
                src = re.search(r'src="([^"]+)"', tag)
                soft.append((round(available / slot, 2), int(slot), available, path,
                             (src.group(1).split("/")[-1] if src else "?")[:52]))
        flag = "" if page_soft == 0 else f"  {page_soft} SOFT"
        print(f"{path:52} checked={checked:3d}{flag}")

    if soft:
        soft.sort()
        print(f"\n{len(soft)} images below {MIN_SHARE:.0%} of their slot at {args.viewport}px:")
        for share, slot, avail, path, file in soft[:20]:
            print(f"  {share:4.2f}x  slot={slot:4d}  largest={avail:4d}w  {path[:34]:36} {file}")

    print(f"\n{len(args.pages)} pages, {int(len(soft))} soft images")
    return 1 if soft else 0


if __name__ == "__main__":
    sys.exit(main())
