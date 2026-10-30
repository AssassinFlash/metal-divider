#!/usr/bin/env python3
"""Side-by-side full-page captures of the old and new site, lazily resolved.

The owner judged the new site visually inferior to the old one, so every
claimed divergence has to be judged from renders that actually resolved their
lazy images — an opencli full-page shot of an xo-lazyload page is a black
rectangle (measured 2026-10-30: 34,720px of near-black).

One real-Chrome instance (channel="chrome"), one page at a time: scroll to the
bottom in viewport steps so `is=xo-lazyload` swaps in the real sources, wait
for the network to settle, scroll back, then take the full-page shot.

Usage:
    python3 tools/audit/sbs_capture.py --out /tmp/md-sbs2 \
        --pairs "/:/,/pages/laser-cut-panels:/laser-cut-panels/"
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

OLD = "https://metalpartition.com"
NEW = "https://metal-divider.com"


def capture_pair(page, old_path: str, new_path: str, out: Path, width: int) -> None:
    for side, base, path in (("old", OLD, old_path), ("new", NEW, new_path)):
        page.goto(f"{base}{path}", wait_until="load", timeout=90000)
        page.wait_for_timeout(2500)
        # Step-scroll so lazy sources swap in; a single jump skips them.
        height = page.evaluate("document.body.scrollHeight")
        y = 0
        while y < height:
            page.evaluate(f"window.scrollTo(0, {y})")
            page.wait_for_timeout(700)
            y += 800
            height = page.evaluate("document.body.scrollHeight")
        page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
        page.wait_for_timeout(2000)
        page.evaluate("window.scrollTo(0, 0)")
        page.wait_for_timeout(1500)
        page.screenshot(path=str(out / f"{width}-{side}-{old_path.strip('/').replace('/', '_') or 'home'}.png"), full_page=True)
        print(f"{side}: {base}{path} -> captured")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="/tmp/md-sbs2")
    ap.add_argument("--width", type=int, default=1440)
    ap.add_argument(
        "--pairs",
        nargs="+",
        default=["/:/", "/pages/laser-cut-panels:/laser-cut-panels/"],
        help="old_path:new_path per item",
    )
    args = ap.parse_args()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    from narrow_viewport import reclaim_profiles

    reclaim_profiles()
    pairs = [tuple(p.split(":", 1)) for p in args.pairs]
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="chrome", headless=False)
        page = browser.new_page(viewport={"width": args.width, "height": 900})
        for old_path, new_path in pairs:
            capture_pair(page, old_path, new_path, out, args.width)
        browser.close()
    return 0


if __name__ == "__main__":
    sys_exit = main()
    raise SystemExit(sys_exit)
