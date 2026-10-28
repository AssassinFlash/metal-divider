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
import shutil
import subprocess
import sys
import tempfile
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
    # All nine series archives. One was swept and eight were not (measured
    # 2026-10-29: the page sitemap has 19 pages and all were covered, but the
    # product_cat sitemap has nine archives and only `pattern-geometric` was on
    # this list). They are the same template rendering different term data, so
    # they can break independently — a series with no thumbnail or no products
    # renders an empty grid that no page-level sweep would see.
    ("series-abstract", "/collections/pattern-abstract/"),
    ("series-asian", "/collections/pattern-asian/"),
    ("series-geometric", "/collections/pattern-geometric/"),
    ("series-gradient", "/collections/pattern-gradient/"),
    ("series-landscape", "/collections/pattern-landscape/"),
    ("series-moroccan-arabic", "/collections/pattern-moroccan-arabic/"),
    ("series-organic", "/collections/pattern-organic/"),
    ("series-perforated", "/collections/pattern-perforated/"),
    ("series-vintage-retro", "/collections/pattern-vintage-retro/"),
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


def reclaim_profiles() -> int:
    """Delete Playwright's leftover temp directories that nothing holds open.

    Covered: the browser profile each `launch` creates, plus the artifacts and
    transform caches it writes alongside. Scoping the sweep to the profile glob
    alone left three of these behind (measured 2026-10-29), which is why the
    patterns are listed explicitly rather than as `playwright-*`: that broader
    glob could reach a directory another tool owns.
    """
    scrap = (
        "playwright_chromiumdev_profile-*",
        "playwright-artifacts-*",
        "playwright-transform-cache-*",
    )
    swept = 0
    temp = pathlib.Path(tempfile.gettempdir())
    for pattern in scrap:
        for leftover in temp.glob(pattern):
            held = subprocess.run(
                ["lsof", "+D", str(leftover)],
                capture_output=True,
                text=True,
                check=False,
            )
            if held.returncode == 0 and held.stdout.strip():
                continue
            shutil.rmtree(leftover, ignore_errors=True)
            swept += 1
    if swept:
        print(f"reclaimed {swept} stale playwright leftover(s)")
    return swept


FIXTURES = """() => {
  // Three boxes appended to <main>, so the probe runs against them exactly as
  // it runs against the site. Each pins one arm of the empty-block rule:
  //   empty    — nothing paints it, so it must be reported;
  //   dot      — a 10x10 dot buried two levels down must NOT excuse the void;
  //   painted  — a full-cover gradient two levels down must NOT be reported.
  // The third is the shape that produced a false positive at 320 on
  // /perforated-panels/ (measured 2026-10-29), which is why the rule walks all
  // descendants yet still requires half the box to be covered.
  const make = (id, css) => {
    const el = document.createElement('div');
    el.id = id;
    el.style.cssText = 'width:300px;height:260px;' + css;
    document.querySelector('main').appendChild(el);
    return el;
  };
  make('md-fixture-empty', '');
  const dot = make('md-fixture-dot', 'position:relative');
  const dotWrap = document.createElement('div');
  const dotInk = document.createElement('div');
  dotInk.style.cssText = 'position:absolute;left:0;top:0;width:10px;height:10px;background:#c9a962';
  dotWrap.appendChild(dotInk);
  dot.appendChild(dotWrap);
  const full = make('md-fixture-painted', 'position:relative');
  const fullWrap = document.createElement('div');
  const fullInk = document.createElement('div');
  fullInk.style.cssText = 'position:absolute;inset:0;background-image:radial-gradient(circle, rgba(201,169,98,.4) 1.5px, transparent 1.5px)';
  fullWrap.appendChild(fullInk);
  full.appendChild(fullWrap);
  //   behind   — an unpainted absolute layer at z-index -2, the shape four
  //              breadcrumb bands present; it sits behind painted content, so it
  //              is not a hole. The layer is the box itself, which is how the
  //              live one is built (`div.xo-section-bg-image` carries the
  //              position and the z-index, it does not wrap a layer).
  //   in-front — the same box at z-index 1, which DOES sit over the content.
  //              That one is still a real hole, and must stay reported.
  const stacked = (id, z) => {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:300px;height:260px;';
    const layer = document.createElement('div');
    layer.id = id;
    layer.style.cssText =
      'position:absolute;inset:0;z-index:' + z + ';';
    wrap.appendChild(layer);
    document.querySelector('main').appendChild(wrap);
    return layer;
  };
  stacked('md-fixture-behind', -2);
  stacked('md-fixture-infront', 1);
  return true;
}"""

SELF_CHECK_URL = "/perforated-panels/"


def self_check(page, probe: str, base: str) -> int:
    """Prove the empty-block rule still tells a void from a painted wrapper.

    A criterion that only ever passes is worth nothing: this project has been
    burned five times by detectors that were green in both states (AGENTS.md
    §8). So the rule is re-measured here on every run, against three fixtures
    whose answers are known, and the run fails loudly if any of them flips.
    """
    page.goto(f"{base}{SELF_CHECK_URL}?mdaudit={int(time.time())}", wait_until="load", timeout=60000)
    page.evaluate(FIXTURES)
    items = json.loads(page.evaluate(probe))["items"]
    flagged = {i["sel"] for i in items if i["kind"] == "empty-block"}
    cases = [
        ("div#md-fixture-empty", True, "a box nothing paints"),
        ("div#md-fixture-dot", True, "a 10x10 dot cannot excuse a 300x260 void"),
        ("div#md-fixture-painted", False, "a descendant that covers the box is paint"),
        ("div#md-fixture-behind", False, "an unpainted layer behind content is not a hole"),
        ("div#md-fixture-infront", True, "the same layer in front of content is a hole"),
    ]
    failed = 0
    for sel, want, why in cases:
        got = sel in flagged
        ok = got == want
        failed += 0 if ok else 1
        print(f"  {'ok  ' if ok else 'FAIL'} {sel:26} flagged={str(got):5} want={str(want):5}  {why}")
    return failed


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--width", type=int, default=390)
    parser.add_argument("--out", default="/tmp/md-narrow")
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--pages", default="", help="comma-separated page names")
    parser.add_argument(
        "--self-check",
        action="store_true",
        help="run the empty-block fixtures and exit; sweeps nothing",
    )
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

        if args.self_check:
            print(f"empty-block self-check at {args.width}px")
            failed = self_check(page, probe, args.base)
            context.close()
            browser.close()
            reclaim_profiles()
            print("self-check FAILED" if failed else "self-check passed")
            return 1 if failed else 0

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
            print(
                f"{name:20} {entry.get('innerWidth', '?'):>5} {count:>3} defects",
                flush=True,
            )

        context.close()
        browser.close()

    # Every launch leaves a `playwright_chromiumdev_profile-*` in $TMPDIR, and
    # a run that dies mid-page leaves it behind even though context.close()
    # ran. The owner asked on 2026-10-29 for instances to be reclaimed after
    # use: four such profiles had accumulated here. Sweep only the ones no
    # process holds.
    reclaim_profiles()

    (out / "report.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
    print(f"\nwrote {out / 'report.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
