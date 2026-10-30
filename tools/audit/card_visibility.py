#!/usr/bin/env python3
"""Prove the 146 pattern cards are visible on a live site.

The defect this guards against is not a broken URL and not a missing element:
every card had an <img> with a 200 response. The image was an RGBA line-art
mask whose motif lives in the alpha channel, so on a `#141414` card it
measured max luminance 1-11 and the grid looked half empty.

A markup check cannot see that, and a screenshot cannot prove it either
(`--width` reflows without reloading images). So this decodes the actual
pixels of each card thumbnail and measures luminance, which is the same
measurement the acceptance record quotes.

It reads the URLs from the served pages rather than from a list here, so it
keeps working as the catalogue grows — but that also means it can only check
the pages it is told about. The catalogue paginates; pass the page count.

Usage:
    python3 tools/audit/card_visibility.py
    python3 tools/audit/card_visibility.py --base https://metal-divider.com --pages 7
"""

from __future__ import annotations

import argparse
import concurrent.futures
import io
import re
import sys
import time
import urllib.request

try:
    from PIL import Image
except ImportError:  # pragma: no cover
    print("Pillow is required: python3 -m pip install pillow", file=sys.stderr)
    sys.exit(2)

UA = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36",
    "Cache-Control": "no-cache",
    "Pragma": "no-cache",
}

# A card whose thumbnail carries no pixel above this reads as an empty slot.
# The masks measured 1-11; the photographs that replaced them measure 49-235.
# 20 sits in the gap, so it separates the two populations rather than cutting
# into either.
DARK_MAX = 20.0


def get(url: str) -> bytes:
    request = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(request, timeout=60) as response:
        return response.read()


def card_images(html: str) -> list[tuple[str, str]]:
    """(pattern name, thumbnail URL) for each card on the page."""
    found: list[tuple[str, str]] = []
    for block in re.split(r'(?=<article[^>]*class="[^"]*pattern-card)', html)[1:]:
        name = ""
        for match in re.finditer(
            r'class="[^"]*pattern-card__value[^"]*"[^>]*>([^<]*)<', block
        ):
            candidate = " ".join(match.group(1).split())
            if candidate:
                name = candidate
                break
        media = re.search(
            r'class="[^"]*pattern-card__media[^"]*"[^>]*>(.*?)</div>', block, re.S
        )
        if not media:
            continue
        src = re.search(r'(?is)<img[^>]+src="([^"]+)"', media.group(1))
        if src:
            found.append((name or "?", src.group(1)))
    return found


def measure(url: str) -> dict:
    if url.startswith("//"):
        url = "https:" + url
    elif url.startswith("/"):
        raise ValueError("relative URL; resolve it against the page first")
    try:
        blob = get(url)
    except Exception as exc:  # noqa: BLE001 - a missing file is a finding, not a crash
        return {"error": f"{type(exc).__name__}: {exc}", "max": None, "mean": None}
    try:
        image = Image.open(io.BytesIO(blob))
    except Exception as exc:  # noqa: BLE001
        return {"error": f"decode: {exc}", "max": None, "mean": None}

    # Composite over the card background the site actually uses, because an
    # alpha image read on its own shows the line art at full brightness and
    # would look perfectly fine — the invisibility only exists after compositing.
    canvas = Image.new("RGB", image.size, (20, 20, 20))
    if image.mode in ("RGBA", "LA"):
        canvas.paste(image, (0, 0), image)
    else:
        canvas.paste(image.convert("RGB"), (0, 0))

    pixels = list(canvas.resize((32, 32)).getdata())
    luminance = sorted(0.2126 * r + 0.7152 * g + 0.0722 * b for r, g, b in pixels)
    return {
        "error": "",
        "mean": round(sum(luminance) / len(luminance), 1),
        "max": round(luminance[-1], 1),
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--pages", type=int, default=7)
    ap.add_argument("--cache-buster", default=None)
    args = ap.parse_args()
    base = args.base.rstrip("/")
    bust = args.cache_buster or f"cv{int(time.time())}"

    cards: list[tuple[str, str]] = []
    for page in range(1, args.pages + 1):
        url = f"{base}/patterns/?paged={page}&mdaudit={bust}"
        try:
            html = get(url).decode("utf-8", errors="replace")
        except Exception as exc:  # noqa: BLE001
            print(f"page {page}: {type(exc).__name__}: {exc}")
            continue
        on_page = card_images(html)
        print(f"page {page}: {len(on_page)} cards")
        cards.extend(on_page)

    if not cards:
        print("no cards found — wrong URL or the markup class moved")
        return 2

    dark: list[tuple[str, str, float]] = []
    broken: list[tuple[str, str]] = []

    def check(card: tuple[str, str]) -> tuple[str, str, dict]:
        name, url = card
        if url.startswith("/"):
            url = base + url
        return name, url, measure(url)

    # 16 at a time. Serially this took over ten minutes against production and
    # produced nothing usable; the images are independent, so the only thing
    # serialising them bought was a longer wait.
    with concurrent.futures.ThreadPoolExecutor(max_workers=16) as pool:
        for name, url, result in pool.map(check, cards):
            if result["error"]:
                broken.append((name, result["error"]))
            elif result["max"] is not None and result["max"] < DARK_MAX:
                dark.append((name, url.split("/")[-1], result["max"]))

    print(f"\n{len(cards)} cards measured")
    if broken:
        print(f"\n{len(broken)} could not be read:")
        for name, why in broken:
            print(f"  {name}: {why}")
    if dark:
        print(f"\n{len(dark)} read as an empty slot (max luminance < {DARK_MAX}):")
        for name, file, peak in dark:
            print(f"  {name:28} max={peak:5.1f}  {file}")
    if not dark and not broken:
        print(f"\nall {len(cards)} cards carry a visible thumbnail")

    return 1 if dark or broken else 0


if __name__ == "__main__":
    sys.exit(main())
