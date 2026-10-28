#!/usr/bin/env python3
"""Drive the owner's real Chrome at a true narrow viewport and collect defects.

Why Playwright here and nowhere else: `opencli` cannot set device metrics
(`Emulation.setDeviceMetricsOverride` is not one of its browser-management
methods, measured 2026-10-29), and this Mac's Chrome refuses to open a window
narrower than ~578px, so `opencli browser screenshot --width 390` reflows a
578px layout into 390 rather than laying the page out at 390. GOAL.md §39
allows Playwright for exactly this gap and forbids its bundled Chromium:
`channel="chrome"` is the owner's own installation, and the first thing this
prints is the measured `innerWidth`, so a run that did not really reflow is
visible in the output rather than assumed.

One browser, one context, one window for the whole run, closed at the end —
the owner has twice asked not to be flooded with new browser windows.

Usage:
    /opt/homebrew/opt/python@3.12/bin/python3.12 tools/audit/narrow_viewport.py \
        --width 390 --out /tmp/md-390
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import time

from playwright.sync_api import sync_playwright

HERE = pathlib.Path(__file__).resolve().parent

# One page per type the coverage matrix names.
PAGES = [
    ("home", "/"),
    ("collections", "/collections/"),
    ("shop", "/shop/"),
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
    ("series-geometric", "/collections/pattern-geometric/"),
    ("pdp-agave", "/pattern/agave-architectural-metal-screen-pattern/"),
    ("pdp-alhambra", "/pattern/alhambra-architectural-metal-screen-pattern/"),
    ("pdp-white-sands", "/pattern/white-sands-architectural-metal-screen-pattern/"),
]

# A scroll pass, so `loading="lazy"` images enter the load margin and either
# load or fail visibly — a tall viewport would fake this and hide breakage.
SCROLL_ALL = """() => new Promise((done) => {
  let y = 0;
  const step = () => {
    y += innerHeight;
    scrollTo(0, y);
    if (y < document.documentElement.scrollHeight) setTimeout(step, 60);
    else { scrollTo(0, 0); setTimeout(done, 400); }
  };
  step();
})"""

IMAGE_AUDIT = """() => {
  const imgs = [...document.images];
  return {
    total: imgs.length,
    broken: imgs.filter((i) => i.complete && i.naturalWidth === 0).length,
    pending: imgs.filter((i) => !i.complete).length,
  };
}"""


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--width", type=int, default=390)
    parser.add_argument("--out", default="/tmp/md-narrow")
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--pages", default="", help="comma-separated page names")
    args = parser.parse_args()

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    probe = (HERE / "defects.js").read_text(encoding="utf-8")
    wanted = PAGES
    if args.pages:
        keep = args.pages.split(",")
        wanted = [p for p in PAGES if p[0] in keep]

    results = []
    with sync_playwright() as play:
        browser = play.chromium.launch(channel="chrome", headless=False)
        context = browser.new_context(
            viewport={"width": args.width, "height": 844},
            device_scale_factor=2,
        )
        page = context.new_page()

        for name, path in wanted:
            url = f"{args.base}{path}?mdaudit={int(time.time())}"
            entry: dict = {"name": name, "path": path, "url": url}
            errors: list[str] = []

            def on_console(message, sink=errors):
                if message.type == "error":
                    sink.append(message.text)

            page.on("console", on_console)
            try:
                page.goto(url, wait_until="load", timeout=60000)
                page.evaluate(SCROLL_ALL)
                entry["innerWidth"] = page.evaluate("() => innerWidth")
                # defects.js returns a JSON string, not an object: it is also
                # pasted into a DevTools console by hand, where a string is
                # readable and an object is not.
                entry["defects"] = json.loads(page.evaluate(probe))
                entry["images"] = page.evaluate(IMAGE_AUDIT)
                entry["errors"] = errors[:]
                page.screenshot(path=str(out / f"{name}.png"))
            except Exception as exc:  # noqa: BLE001 - a page that fails is data
                entry["error"] = str(exc)
            page.remove_listener("console", on_console)
            results.append(entry)
            count = len(entry.get("defects", {}).get("items", []))
            print(f"{name:20} {entry.get('innerWidth', '?'):>5} {count:>3} defects", flush=True)

        context.close()
        browser.close()

    (out / "report.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
    print(f"\nwrote {out / 'report.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
