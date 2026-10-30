#!/usr/bin/env python3
"""Find horizontal overflow at a given viewport, in the browser.

A screenshot cannot answer this. Content touching the right edge of a capture
is just as likely to be a deliberate full-bleed background as a real overflow,
and several pages here use full-bleed photography by design. The ground truth
is `documentElement.scrollWidth` against `innerWidth`, which is only available
from the DOM.

The site also has to be measured at a *real* viewport width. `--width` on a
screenshot reflows but does not reload images, and a window narrower than the
target renders the page at the window width. So this drives the three-step
opencli dance recorded in AGENTS.md §7: create a focused window at the target
size, activate the tab, focus the window, bind, then assert `innerWidth`
before trusting anything.

Usage:
    python3 tools/audit/overflow.py --width 320 --pages / /faqs/ /inquiry/
    python3 tools/audit/overflow.py --width 1440 --all
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time

OPENCLI = "opencli"

# The paths the goal's coverage matrix names, minus the 146 PDPs (one template;
# two representatives is what the matrix asks for).
DEFAULT_PAGES = [
    "/", "/pattern-category/", "/patterns/", "/faqs/", "/inquiry/", "/privacy/",
    "/about-us/", "/manufacturing-process/", "/project-gallery/", "/blog/",
    "/laser-cut-geometric-metal-screens/", "/glass-hybrid-screens/",
    "/outdoor-privacy-landscape-screens/", "/chinese-style-artistic-screens/",
    "/stainless-steel-screens/", "/laser-cut-panels/", "/perforated-panels/",
    "/expanded-metal-panels/", "/custom-metal-divider-screens/",
    "/pattern/agave-architectural-metal-screen-pattern/",
    "/pattern/apiary-architectural-metal-screen-pattern/",
    "/pattern-category/pattern-abstract/", "/pattern-category/pattern-geometric/",
]

# The narrowest window Chrome will create on this Mac. Measured: asking for
# 320 yields 578. Below this the DOM cannot be asked the question at all.
CHROME_MIN_WIDTH = 578

# Everything wider than the viewport by more than this is overflow, not
# subpixel rounding. One CSS pixel of slack is what a scrollbar-less layout
# can legitimately differ by.
OVERFLOW_SLACK = 1

# Page commands (`eval`, `open`, `screenshot`) require --session; the `chrome`
# subcommands reject it. Without this the width assertion reads `None` for every
# page and the whole run reports SKIP instead of measuring anything.
SESSION: dict[str, str] = {}


def _with_session(args: list[str]) -> list[str]:
    if SESSION and args and args[0] != "chrome" and not any(a == "--session" for a in args):
        return [*args, "--session", SESSION["name"]]
    return args


def run(args: list[str], timeout: int = 240) -> dict:
    try:
        completed = subprocess.run(
            [OPENCLI, "browser", *_with_session(args)],
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "timeout"}
    for line in reversed(completed.stdout.strip().splitlines()):
        try:
            return json.loads(line)
        except json.JSONDecodeError:
            continue
    return {"ok": False, "error": completed.stderr[-200:] or "no json"}


def capture_overflow(path: str) -> tuple[bool, str]:
    """Fallback for widths the browser will not open at.

    Chrome on this Mac will not make a window narrower than about 578px, and
    opencli exposes no device emulation, so a 320px viewport cannot be measured
    from the DOM. The capture can still answer the question, but not by asking
    whether content touches the right edge — full-bleed photography does that
    by design.

    The reliable signal is the *scrollbar*: when the page fits, the last column
    is one unchanging colour down the whole height because it is the scrollbar
    track. When the page overflows, that column carries content instead and the
    colour varies. Measured on 12 captures at 320: the six that fit all read
    flat 1.00 at #515151, the six with no scrollbar read 0.09-0.77.
    """
    try:
        from PIL import Image
    except ImportError:
        return True, "Pillow missing — cannot judge"
    image = Image.open(path).convert("RGB")
    width, height = image.size
    if width != 320:
        return True, f"capture is {width}px wide, not 320"
    column = [image.getpixel((width - 1, y)) for y in range(0, height, 3)]
    counts = {}
    for pixel in column:
        counts[pixel] = counts.get(pixel, 0) + 1
    modal, seen = max(counts.items(), key=lambda kv: kv[1])
    flat = seen / len(column)
    if flat >= 0.95:
        return False, f"fits (scrollbar track {modal})"
    return False, f"no scrollbar, edge varies (flat {flat:.2f}) — verify by eye"


def measure_js() -> str:
    """The overflow question plus the elements that answer it.

    Reporting the offenders matters: `scrollWidth > innerWidth` says a page
    overflows, and the element list says which subtree is responsible, which
    is the difference between a report someone can act on and a number.
    """
    return """(() => {
  const doc = document.documentElement;
  const iw = window.innerWidth;
  const overflow = doc.scrollWidth - iw;
  const offenders = [];
  if (overflow > 1) {
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      const over = Math.round(r.right - iw);
      if (over > 1) {
        const cs = getComputedStyle(el);
        // A fixed full-bleed background is not overflow the reader can see.
        if (cs.position === 'fixed') continue;
        offenders.push({
          over,
          w: Math.round(r.width),
          sel: (el.tagName.toLowerCase() + '.' + (el.className || '').toString().split(' ').filter(Boolean).slice(0,2).join('.')).slice(0, 60),
        });
      }
    }
  }
  offenders.sort((a, b) => b.over - a.over);
  return { iw, scrollWidth: doc.scrollWidth, overflow, offenders: offenders.slice(0, 8) };
})()"""


def report_from_captures(args: argparse.Namespace, pages: list[str]) -> int:
    """Judge the narrow widths from captures in --capture-dir."""
    import os

    directory = getattr(args, "capture_dir", None)
    if not directory or not os.path.isdir(directory):
        print(
            f"{args.width}px is below Chrome's minimum window width, and no "
            "--capture-dir was given, so there is nothing to judge.",
            file=sys.stderr,
        )
        return 2

    slugs = {path.rstrip("/").split("/")[-1] or "home": path for path in pages}
    results = []
    suspicious = 0
    for slug, path in slugs.items():
        capture = os.path.join(directory, f"{slug}-{args.width}.png")
        if not os.path.exists(capture):
            print(f"{path:52} no capture at {capture}")
            continue
        _, verdict = capture_overflow(capture)
        if "verify by eye" in verdict:
            suspicious += 1
        print(f"{path:52} {verdict}")
        results.append({"path": path, "verdict": verdict})
    print(f"\n{len(results)} judged, {suspicious} need a look")
    return 0 if suspicious == 0 else 1


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:9890")
    ap.add_argument("--width", type=int, default=320)
    ap.add_argument("--pages", nargs="*")
    ap.add_argument("--all", action="store_true", help="use the built-in page list")
    ap.add_argument("--json")
    ap.add_argument("--capture-dir")
    args = ap.parse_args()

    pages = args.pages or (DEFAULT_PAGES if args.all else DEFAULT_PAGES)

    # Chrome on this Mac refuses to make a window narrower than about 578px,
    # and opencli has no device emulation, so anything below that has to be
    # judged from a capture instead of the DOM.
    if args.width < CHROME_MIN_WIDTH:
        return report_from_captures(args, pages)

    # The window has to be wider than the page by the chrome: 56px is the
    # measured outerWidth-innerWidth difference on this Mac.
    window_width = args.width + 56
    session = f"mdov{int(time.time())}"
    SESSION["name"] = session

    created = run(
        [
            "chrome", "call", "windows.create",
            json.dumps([{"url": "about:blank", "width": window_width,
                         "height": 900, "left": 0, "top": 34, "focused": True}]),
        ]
    )
    if not created.get("ok"):
        print(f"could not create a window: {created}", file=sys.stderr)
        return 2
    window_id = created["data"]["id"]
    tab_id = (created["data"].get("tabs") or [{}])[0].get("id")
    if not tab_id:
        print("the new window has no tab", file=sys.stderr)
        return 2

    # bind attaches to Chrome's *active* tab, so both of these are required.
    run(["chrome", "call", "tabs.update", json.dumps([tab_id, {"active": True}])])
    run(["chrome", "call", "windows.update", json.dumps([window_id, {"focused": True}])])
    bound = run(["bind", "--session", session, "--window", "background"])
    if not bound.get("ok"):
        print(f"could not bind: {bound}", file=sys.stderr)
        return 2

    results = []
    failures = 0
    try:
        for path in pages:
            url = f"{args.base}{path}?mdaudit=ov{int(time.time())}"
            run(["open", url, "--window", "background"])
            check = run(["eval", "window.innerWidth"])
            actual = check.get("data")
            if actual != args.width:
                # A wrong width invalidates the measurement; do not silently
                # report it as a pass.
                print(f"{path:52} SKIP — viewport is {actual}, wanted {args.width}")
                results.append({"path": path, "error": f"viewport {actual}"})
                failures += 1
                continue
            measured = run(["eval", measure_js()])
            data = measured.get("data") or {}
            if not isinstance(data, dict):
                print(f"{path:52} SKIP — {measured}")
                failures += 1
                continue
            overflow = data.get("overflow", 0)
            ok = overflow <= OVERFLOW_SLACK
            if not ok:
                failures += 1
            print(f"{path:52} {'ok' if ok else 'OVERFLOW +' + str(overflow)}")
            for offender in data.get("offenders", []):
                print(f"      +{offender['over']:4d}px  {offender['w']:5d}px wide  {offender['sel']}")
            results.append({"path": path, **data})
    finally:
        run(["close", "--session", session])
        run(["chrome", "call", "windows.remove", json.dumps([window_id])])

    print(f"\n{len(results)} pages at {args.width}px, {failures} failed")
    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(results, handle, indent=2)
        print(f"wrote {args.json}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
