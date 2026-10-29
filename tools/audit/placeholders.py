#!/usr/bin/env python3
"""Run the placeholder judge on every page type, after the page has been scrolled.

Why the scroll is not an optimisation but a correctness requirement: the quality
counters on /laser-cut-panels/ animate only when their card scrolls into view
(IntersectionObserver, threshold 0.3). Judged from the top of the page they read
"0" — correctly, because the animation has not been asked to start. The first run
of this runner reported all four as defects for exactly that reason, on the page
that had just been fixed.

A visitor scrolls. So does this: to the bottom, back to the top, then judge. A
value that is still a placeholder after that is not waiting for anything.

    python3 tools/audit/placeholders.py --self-check
    python3 tools/audit/placeholders.py --out /tmp/md-placeholders
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time

OPENCLI = "opencli"
SESSION = "md-placeholders"
HERE = os.path.dirname(os.path.abspath(__file__))

PAGES: list[tuple[str, str]] = [
    ("home", "/"),
    ("collections", "/collections/"),
    ("shop", "/shop/"),
    ("inquiry", "/inquiry/"),
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
    ("series", "/collections/pattern-abstract/"),
    ("pdp", "/pattern/agave-architectural-metal-screen-pattern/"),
]

# Scroll the whole document, let every observer fire, then go back to the top.
# A finite step count with a wait per step: the page heights here reach 34,000px
# and an IntersectionObserver needs a frame after each move.
SCROLL_JS = """(async () => {
  const step = Math.max(300, Math.round(innerHeight * 0.8));
  const height = () => Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
  let y = 0;
  let guard = 0;
  while (y < height() && guard < 120) {
    window.scrollTo(0, y);
    await new Promise((r) => requestAnimationFrame(() => setTimeout(r, 40)));
    y += step;
    guard += 1;
  }
  window.scrollTo(0, height());
  await new Promise((r) => setTimeout(r, 900));
  window.scrollTo(0, 0);
  await new Promise((r) => setTimeout(r, 250));
  return JSON.stringify({scrolledTo: y, height: height()});
})()"""


def call(args: list[str], timeout: int = 240) -> tuple[int, str, str]:
    p = subprocess.run([OPENCLI, "browser"] + args + ["--session", SESSION],
                       capture_output=True, text=True, timeout=timeout)
    return p.returncode, p.stdout, p.stderr


def run_js(js: str, timeout: int = 300) -> dict:
    """opencli wraps every answer as {"ok":true,"data":...}; unwrap it.

    The first version of a sibling runner read the envelope as the result and
    reported zero findings on a page with two planted defects.
    """
    try:
        code, out, err = call(["eval", js], timeout=timeout)
    except subprocess.TimeoutExpired:
        return {"error": "eval timed out"}
    text = (out or "").strip()
    envelope = None
    for candidate in (text, (err or "").strip()):
        if not candidate:
            continue
        try:
            envelope = json.loads(candidate)
        except json.JSONDecodeError:
            continue
        break
    if envelope is None:
        return {"error": f"rc={code}", "raw": (text or err or "")[:400]}
    if isinstance(envelope, dict) and "ok" in envelope:
        if not envelope.get("ok"):
            return {"error": json.dumps(envelope)[:400]}
        envelope = envelope.get("data")
    if isinstance(envelope, str):
        try:
            return json.loads(envelope)
        except json.JSONDecodeError:
            return {"error": "unparsed", "raw": envelope[:400]}
    return envelope if isinstance(envelope, dict) else {"error": "unexpected"}


def cleanup() -> None:
    subprocess.run([OPENCLI, "browser", "close", "--session", SESSION],
                   capture_output=True, text=True, timeout=60)


FIXTURE = """<!doctype html><meta charset="utf-8"><title>placeholder self check</title>
<style>.hidden{display:none}</style>
<main>
  <h2>Counters</h2>
  <div data-counter data-target="8"><span data-num>0</span></div>
  <div data-counter data-target="100"><span data-num>100</span></div>

  <h2>Images</h2>
  <img src="" width="40" height="40">
  <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" width="40" height="40">

  <h2>Ratio</h2>
  <span>1 / 1</span>
  <span>3 / 7</span>

  <h2>Meter</h2>
  <progress max="100"></progress>
  <progress max="100" value="42"></progress>

  <h2>Hidden by an ancestor</h2>
  <div style="opacity:0"><img src="" width="40" height="40"></div>
  <div inert><img src="" width="40" height="40"></div>
  <div style="display:none"><img src="" width="40" height="40"></div>
</main>
"""

EXPECTED = {
    "placeholder-value": 1,  # only the first counter is still 0
    "empty-image": 2,
    "default-ratio": 1,
    "empty-meter": 1,
}


def self_check(verbose: bool = False) -> int:
    """Plant one of each defect beside a correct twin, and require both verdicts.

    Every kind is paired: a stuck counter next to a filled one, an empty <img>
    next to a data: placeholder (both count) and a real one, a 1/1 default next
    to a 3/7, an empty <progress> next to a valued one. A judge that flags both
    halves is as useless as one that flags neither.
    """
    import functools
    import http.server
    import threading

    root = "/tmp/md-placeholder-fixture"
    os.makedirs(root, exist_ok=True)
    with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
        fh.write(FIXTURE)

    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *a):
            return

    handler = functools.partial(Quiet, directory=root)
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = httpd.server_address[1]
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    try:
        code, out, err = call(["open", f"http://127.0.0.1:{port}/"])
        if code != 0:
            print(f"SELF-CHECK FAIL cannot open fixture: {err[:200]}")
            return 1
        # The fixture has no observers, so the scroll is a no-op here; it still
        # runs so the harness is exercised end to end.
        run_js(SCROLL_JS)
        result = run_js(open(os.path.join(HERE, "placeholders.js"), encoding="utf-8").read())
        summary = result.get("summary") or {}
        ok = summary == EXPECTED
        print(f"SELF-CHECK {'ok  ' if ok else 'FAIL'} planted {EXPECTED} reported {summary}")
        if verbose and not ok:
            print(json.dumps(result, indent=2, ensure_ascii=False)[:2500])
        return 0 if ok else 1
    finally:
        httpd.shutdown()
        cleanup()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--out", default="/tmp/md-placeholders")
    ap.add_argument("--only", default="")
    ap.add_argument("--self-check", action="store_true")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()

    if args.self_check:
        return self_check(args.verbose)

    os.makedirs(args.out, exist_ok=True)
    judge = open(os.path.join(HERE, "placeholders.js"), encoding="utf-8").read()

    pages = [(n, p) for n, p in PAGES if not args.only or args.only in n]
    report = []
    try:
        for name, path in pages:
            url = f"{args.base}{path}?mdaudit={int(time.time())}"
            code, out, err = call(["open", url], timeout=180)
            if code != 0:
                report.append({"page": name, "url": url, "error": f"open failed: {err[:200]}"})
                print(f"{name:20s} OPEN FAILED")
                continue
            scroll = run_js(SCROLL_JS)
            result = run_js(judge)
            result["page"] = name
            result["scroll"] = scroll
            report.append(result)
            s = result.get("summary") or {}
            total = sum(s.values())
            print(f"{name:20s} findings={total:3d} {s if s else ''}")
            if args.verbose:
                for f in (result.get("findings") or [])[:10]:
                    print(f"    {f.get('kind'):20s} {str(f.get('text') or f.get('src') or '')[:20]:22s} {str(f.get('where') or '')[:44]}")
    finally:
        cleanup()

    with open(os.path.join(args.out, "placeholders.json"), "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, ensure_ascii=False)
    total = sum(sum((r.get("summary") or {}).values()) for r in report)
    print(f"\npages={len(report)} findings={total}")
    print(f"wrote {os.path.join(args.out, 'placeholders.json')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
