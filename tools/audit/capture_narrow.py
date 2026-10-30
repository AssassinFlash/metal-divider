#!/usr/bin/env python3
"""Capture every page at a narrow viewport with a viewport taller than the page.

Why a tall viewport: at 320 and 390 the page is 8,000-35,000px tall, so a
normal `--full-page` capture returns to the top before every `loading="lazy"`
image has entered the load margin, and those images never start. A viewport
taller than the whole document puts them all inside the margin at once, which
does not depend on `visibilityState` the way scrolling does.

Chrome caps the capture height, so the resulting file is cropped to the height
this script measures from the document, not to the height requested.

Usage:
    python3 tools/audit/capture_narrow.py --width 320 --out /tmp/md320
    python3 tools/audit/capture_narrow.py --width 390 --out /tmp/md390 --pages / /patterns/
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time

from PIL import Image

OPENCLI = "opencli"

# One page per type the goal's coverage matrix names.
DEFAULT_PAGES = [
    ("home", "/"),
    ("pattern-category", "/pattern-category/"),
    ("patterns", "/patterns/"),
    ("inquiry", "/inquiry/"),
    ("thank-you", "/inquiry/thank-you/"),
    ("privacy", "/privacy/"),
    ("faqs", "/faqs/"),
    ("about", "/about-us/"),
    ("manufacturing", "/manufacturing-process/"),
    ("gallery", "/project-gallery/"),
    ("blog", "/blog/"),
    ("family-geometric", "/laser-cut-geometric-metal-screens/"),
    ("family-glass", "/glass-hybrid-screens/"),
    ("family-outdoor", "/outdoor-privacy-landscape-screens/"),
    ("family-chinese", "/chinese-style-artistic-screens/"),
    ("family-stainless", "/stainless-steel-screens/"),
    ("family-panels", "/laser-cut-panels/"),
    ("family-perforated", "/perforated-panels/"),
    ("family-expanded", "/expanded-metal-panels/"),
    ("custom", "/custom-metal-divider-screens/"),
    ("series-abstract", "/pattern-category/pattern-abstract/"),
    ("series-geometric", "/pattern-category/pattern-geometric/"),
    ("pdp-agave", "/pattern/agave-architectural-metal-screen-pattern/"),
    ("pdp-apiary", "/pattern/apiary-architectural-metal-screen-pattern/"),
]

# Taller than any page in the list; Chrome clamps it and the crop below trims
# the padding that leaves behind.
CAPTURE_HEIGHT = 60000


def page_end(path: str) -> int:
    """Where real content stops, for a report — not for cropping.

    The tall viewport pads the file with the page background, and at 320 the
    padding is not cleanly separable (the capture is a reflow of a 578px layout
    squeezed into 320, so rows carry compression noise). Rather than guess a
    crop, report both numbers and let the density analyser say whether any band
    is a container with content missing (ink on neither side) or padding (ink
    on neither side *and* the full width is one colour).
    """
    image = Image.open(path).convert("RGB")
    width, height = image.size
    step = max(1, width // 40)
    usable = max(1, width - 20)
    for y in range(height - 80, 0, -20):
        row = [image.getpixel((x, y)) for x in range(0, usable, step)]
        low = min(sum(p) // 3 for p in row)
        high = max(sum(p) // 3 for p in row)
        if high - low > 3:
            return min(height, y + 40)
    return height


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--width", type=int, default=320)
    ap.add_argument("--out", required=True)
    ap.add_argument("--pages", nargs="*", help="paths only; defaults to the matrix")
    args = ap.parse_args()
    base = args.base.rstrip("/")
    os.makedirs(args.out, exist_ok=True)

    if args.pages:
        pages = [(p.strip("/").replace("/", "-") or "home", p) for p in args.pages]
    else:
        pages = DEFAULT_PAGES

    session = f"mdn{int(time.time())}"

    def run(cmd: list[str], timeout: int = 600) -> dict:
        full = [OPENCLI, "browser", *cmd]
        if cmd and cmd[0] != "chrome":
            full += ["--session", session]
        try:
            done = subprocess.run(full, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            return {"ok": False, "error": "timeout"}
        for line in reversed(done.stdout.strip().splitlines()):
            try:
                return json.loads(line)
            except json.JSONDecodeError:
                continue
        return {"ok": False, "error": done.stderr[-160:] or "no json"}

    first = pages[0][1]
    created = run(["chrome", "call", "windows.create", json.dumps(
        [{"url": f"{base}{first}?mdaudit=n{int(time.time())}", "width": 1496,
          "height": 920, "left": 0, "top": 34, "focused": True}])])
    if not created.get("ok"):
        print(f"could not create a window: {created}", file=sys.stderr)
        return 2
    window_id = created["data"]["id"]
    tab_id = (created["data"].get("tabs") or [{}])[0].get("id")
    run(["chrome", "call", "tabs.update", json.dumps([tab_id, {"active": True}])])
    run(["chrome", "call", "windows.update", json.dumps([window_id, {"focused": True}])])
    if not run(["bind", "--window", "background"]).get("ok"):
        print("could not bind", file=sys.stderr)
        return 2

    written = 0
    try:
        for index, (slug, path) in enumerate(pages):
            if index > 0:
                run(["open", f"{base}{path}?mdaudit=n{int(time.time())}", "--window", "background"])
            time.sleep(0.8)
            state = run(["eval", "JSON.stringify({logged:document.body.className.indexOf('logged-in')>=0,h:document.documentElement.scrollHeight})"]).get("data")
            try:
                info = json.loads(state) if isinstance(state, str) else {}
            except json.JSONDecodeError:
                info = {}
            if info.get("logged"):
                print(f"{slug:18} SKIP — logged in")
                continue
            target = os.path.join(args.out, f"{slug}-{args.width}.png")
            shot = run(["screenshot", target, "--width", str(args.width),
                        "--height", str(CAPTURE_HEIGHT)], timeout=600)
            if not shot.get("ok"):
                print(f"{slug:18} FAIL {shot.get('error', '')}")
                continue
            final = Image.open(target)
            content_end = page_end(target)
            print(f"{slug:18} {final.width}x{final.height}  content≈{content_end}", flush=True)
            written += 1
    finally:
        run(["close"])
        run(["chrome", "call", "windows.remove", json.dumps([window_id])])

    print(f"\n{written}/{len(pages)} captured at {args.width}px into {args.out}")
    return 0 if written == len(pages) else 1


if __name__ == "__main__":
    sys.exit(main())
