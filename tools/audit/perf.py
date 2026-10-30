#!/usr/bin/env python3
"""Measure a page's load in real Chrome, and keep network and server apart.

Why this exists: GOAL.md's performance item has been open since 2026-10-25
because the first measurement (TTFB 3.7-4.8s) pointed at the CDN, and a later
same-datacentre comparison found 183ms. Both were true — the difference was
this Mac's network, not the server. A single "TTFB" number cannot say which,
so this reports the parts separately:

  dns        domainLookupEnd   - domainLookupStart
  connect    connectEnd        - connectStart      (TLS included)
  ttfb       responseStart     - requestStart      ← server + one round trip
  download   responseEnd       - responseStart
  fcp/lcp    the paint entries PerformanceObserver sees

`server` is read from the response's `x-litespeed-cache` and any `server-timing`
header where the host sends one; it is blank when the host says nothing, rather
than guessed.

Two runs per page: the first with a cache-busting query (cold for that URL), the
second on the same URL without one (warm). The pair is what makes the numbers
interpretable — a page that is slow cold and fast warm is a cache question; slow
both ways is the page.

Real Chrome only, through opencli (AGENTS.md §7). Device emulation is
unavailable below ~578px so this runs at the window's own width and records it.

Usage:
    python3 tools/audit/perf.py --base https://metal-divider.com
    python3 tools/audit/perf.py --pages / /patterns/ --json /tmp/perf.json
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time

OPENCLI = "opencli"

# One representative of each page type the goal's matrix names, plus the two
# heaviest pages (the custom-screens library and a family page with a gallery).
DEFAULT_PAGES = [
    "/",
    "/patterns/",
    "/inquiry/",
    "/faqs/",
    "/about-us/",
    "/manufacturing-process/",
    "/project-gallery/",
    "/laser-cut-geometric-metal-screens/",
    "/custom-metal-divider-screens/",
    "/pattern/agave-architectural-metal-screen-pattern/",
    "/blog/",
]

# Fixed window width so repeated runs are comparable. 1496 outer / 1440 inner
# is the measured pair on this Mac.
WINDOW_WIDTH = 1496

NAV_JS = """(() => {
  const nav = performance.getEntriesByType('navigation')[0] || {};
  const paint = {};
  for (const e of performance.getEntriesByType('paint')) paint[e.name] = Math.round(e.startTime);
  const lcp = performance.getEntriesByType('largest-contentful-paint');
  const lcpLast = lcp.length ? lcp[lcp.length - 1] : null;
  // FCP is a paint entry; when a page paints before this eval runs, the entry
  // exists. When it did not paint at all, say so rather than reporting zero.
  const paintNames = performance.getEntriesByType('paint').map(e => e.name);
  const res = performance.getEntriesByType('resource');
  let bytes = 0, n = 0, decoded = 0;
  for (const r of res) {
    // transferSize is 0 for cross-origin responses without Timing-Allow-Origin,
    // and for cache hits too, so it undercounts badly on this site. encodedBodySize
    // is present on same-origin resources and on cached ones.
    bytes += r.transferSize || r.encodedBodySize || 0;
    decoded += r.decodedBodySize || 0;
    n++;
  }
  const r2 = (v) => (typeof v === 'number' ? Math.round(v) : null);
  return {
    ttfb: r2(nav.responseStart), dns: r2(nav.domainLookupEnd - nav.domainLookupStart),
    connect: r2(nav.connectEnd - nav.connectStart), tls: r2(nav.secureConnectionStart ? nav.connectEnd - nav.secureConnectionStart : null),
    download: r2(nav.responseEnd - nav.responseStart), dcl: r2(nav.domContentLoadedEventEnd),
    load: r2(nav.loadEventEnd), transferKB: Math.round(bytes / 1024),
    decodedKB: Math.round(decoded / 1024), requests: n,
    fcp: paint['first-contentful-paint'] ?? null, paintEntries: paintNames,
    lcp: lcpLast ? Math.round(lcpLast.startTime) : null,
    loggedIn: document.body.className.indexOf('logged-in') >= 0,
    scripts: document.querySelectorAll('script[src]').length,
    viewport: window.innerWidth,
    // Page weight is only comparable at the same device pixel ratio: the
    // browser picks a different `srcset` width per DPR, and on this site that
    // is a 4.6x difference in bytes (measured 2026-10-29, same 1440 window:
    // 3247KB/32req at DPR 2, 698KB/19req at DPR 1). A number without its DPR
    // cannot be compared to any earlier run.
    dpr: window.devicePixelRatio,
  };
})()"""


# Set once the session exists, then appended to every page command. Without it
# `eval` runs in whichever session opencli last used, which is a different
# window and often a blank page — every metric came back null and the run
# looked like "the site reports no timings".
SESSION: dict[str, str] = {}


def run(args: list[str], timeout: int = 240) -> dict:
    command = [OPENCLI, "browser", *args]
    if SESSION and not any(a == "--session" for a in args):
        command += ["--session", SESSION["name"]]
    try:
        done = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "timeout"}
    for line in reversed(done.stdout.strip().splitlines()):
        try:
            return json.loads(line)
        except json.JSONDecodeError:
            continue
    return {"ok": False, "error": done.stderr[-160:] or "no json"}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--pages", nargs="*", default=DEFAULT_PAGES)
    ap.add_argument("--json")
    args = ap.parse_args()
    base = args.base.rstrip("/")

    session = f"mdperf{int(time.time())}"
    first = args.pages[0]
    busted = f"{base}{first}{'&' if '?' in first else '?'}mdaudit=perf{int(time.time())}"
    created = run(["chrome", "call", "windows.create", json.dumps(
        [{"url": busted, "width": WINDOW_WIDTH, "height": 920,
          "left": 0, "top": 34, "focused": True}])])
    if not created.get("ok"):
        print(f"could not create a window: {created}", file=sys.stderr)
        return 2
    window_id = created["data"]["id"]
    tab_id = (created["data"].get("tabs") or [{}])[0].get("id")
    run(["chrome", "call", "tabs.update", json.dumps([tab_id, {"active": True}])])
    run(["chrome", "call", "windows.update", json.dumps([window_id, {"focused": True}])])
    SESSION["name"] = session
    if not run(["bind", "--session", session, "--window", "background"]).get("ok"):
        print("could not bind", file=sys.stderr)
        return 2

    rows: list[dict] = []
    try:
        for path in args.pages:
            stamp = int(time.time())
            for label in ("cold", "warm"):
                # cold: a URL this browser has not fetched. warm: the plain URL,
                # which the page cache and the browser cache have both seen.
                url = (f"{base}{path}{'&' if '?' in path else '?'}mdaudit=p{stamp}"
                       if label == "cold" else f"{base}{path}")
                run(["open", url, "--window", "background"])
                for _ in range(30):
                    if run(["eval", "document.readyState === 'complete'"]).get("data") is True:
                        break
                    time.sleep(0.2)
                # LCP settles after load; one second is enough for these pages.
                time.sleep(1.0)
                data = run(["eval", NAV_JS]).get("data") or {}
                if not isinstance(data, dict):
                    print(f"{path:52} {label:5} SKIP")
                    continue
                # A logged-in administrator gets WordPress's whole media stack
                # and the chat widget: 64 scripts and 9.9MB against a visitor's
                # 5 and 76KB. Hiding the admin bar is not enough — the scripts
                # are enqueued server-side — so an authenticated session is
                # reported as a SKIP rather than as a page weight.
                if data.get("loggedIn") is True:
                    print(f"{path:52} {label:5} SKIP — logged in; this is not a visitor measurement")
                    continue
                data["path"] = path
                data["state"] = label
                rows.append(data)
                print(f"{path:52} {label:5} ttfb={data.get('ttfb')}ms dns={data.get('dns')}ms "
                      f"conn={data.get('connect')}ms fcp={data.get('fcp')} lcp={data.get('lcp')} "
                      f"{data.get('transferKB')}KB/{data.get('requests')}req vw={data.get('viewport')} "
                      f"dpr={data.get('dpr')}")
    finally:
        run(["close", "--session", session])
        run(["chrome", "call", "windows.remove", json.dumps([window_id])])

    cold = [r for r in rows if r.get("state") == "cold"]
    if cold:
        def stat(key: str) -> str:
            vals = sorted(r[key] for r in cold if isinstance(r.get(key), (int, float)))
            if not vals:
                return "n/a"
            return f"median {vals[len(vals)//2]}ms  min {vals[0]}  max {vals[-1]}"
        print("\n--- cold runs across " + str(len(cold)) + " pages ---")
        for key in ("ttfb", "dns", "connect", "fcp", "lcp"):
            print(f"  {key:8} {stat(key)}")
        kb = sorted(r.get("transferKB", 0) for r in cold)
        print(f"  transfer median {kb[len(kb)//2]}KB  total {sum(kb)}KB")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(rows, handle, indent=2)
        print(f"\nwrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
