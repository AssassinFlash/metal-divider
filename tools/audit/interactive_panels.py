#!/usr/bin/env python3
"""Run the interaction audit against every page type on the live site.

Why this exists: three of the owner's screenshots show panels that are OPEN with
nothing in them. A static first-load scan cannot see that class of defect at all
— the empty panel only exists after a click — which is how the site passed a
hundred checks while shipping open-but-empty accordions.

The judge itself is `interactions.js`, which clicks every control that presents
itself as a state switch and records whether anything changed. This file is only
the runner: it drives the owner's real Chrome through `opencli`, one page at a
time, on one session, and cleans up after itself.

The browser is a single shared resource — every opencli session drives the same
Chrome window — so pages run strictly serially here. Never run two of these at
once.

    python3 tools/audit/interactive_panels.py --self-check
    python3 tools/audit/interactive_panels.py --out /tmp/md-interactive
    python3 tools/audit/interactive_panels.py --only stainless --verbose
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time

OPENCLI = "opencli"
SESSION = "md-interactive"
HERE = os.path.dirname(os.path.abspath(__file__))

# One page per distinct page type that carries a state switch. The collection
# archive and the PDP are included because both have panels (series panel, PDP
# tabs) rather than only links.
PAGES: list[tuple[str, str]] = [
    ("home", "/"),
    ("shop", "/shop/"),
    ("series", "/collections/pattern-abstract/"),
    ("pdp", "/pattern/agave-architectural-metal-screen-pattern/"),
    ("faqs", "/faqs/"),
    ("inquiry", "/inquiry/"),
    ("stainless", "/stainless-steel-screens/"),
    ("laser-geometric", "/laser-cut-geometric-metal-screens/"),
    ("glass-hybrid", "/glass-hybrid-screens/"),
    ("perforated", "/perforated-panels/"),
    ("expanded", "/expanded-metal-panels/"),
    ("outdoor", "/outdoor-privacy-landscape-screens/"),
    ("chinese-style", "/chinese-style-artistic-screens/"),
    ("laser-panels", "/laser-cut-panels/"),
    ("manufacturing", "/manufacturing-process/"),
    ("gallery", "/project-gallery/"),
    ("about", "/about-us/"),
    ("custom", "/custom-metal-divider-screens/"),
    ("blog", "/blog/"),
]

# Live verdicts that mean the control or its panel is broken. `dead-control`
# covers a control that changes nothing; the two `empty-panel` verdicts cover a
# panel that opens to zero height — with words in it (`empty-panel`, i.e. the
# content exists but is not laid out) or with none at all
# (`empty-panel-blank`, i.e. nothing was ever put there). The distinction
# matters: the first is a CSS defect, the second a content gap.
BROKEN = {"dead-control", "empty-panel", "empty-panel-blank", "content-disappeared", "click-threw"}


def call(args: list[str], timeout: int = 240) -> tuple[int, str, str]:
    cmd = [OPENCLI, "browser"] + args + ["--session", SESSION]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    return p.returncode, p.stdout, p.stderr


def run_js(js: str, timeout: int = 300) -> dict:
    # opencli takes the script as an argv element, not a file, so it must fit
    # one argument. ARG_MAX on macOS allows far more than this file.
    try:
        code, out, err = call(["eval", js], timeout=timeout)
    except subprocess.TimeoutExpired:
        return {"error": "eval timed out", "raw": ""}
    text = (out or "").strip()
    # opencli wraps every answer as {"ok":true,"data":<value>}. The first version
    # of this parser read the wrapper as the result and reported "0 findings" on
    # a page with two planted defects — the audit looked clean because it was
    # reading the envelope, not the page.
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
        return {"error": f"rc={code}", "raw": (text or err or "")[:2000]}
    if isinstance(envelope, dict) and "ok" in envelope:
        if not envelope.get("ok"):
            return {"error": "opencli reported failure", "raw": json.dumps(envelope, ensure_ascii=False)[:2000]}
        envelope = envelope.get("data")
    if isinstance(envelope, str):
        try:
            return json.loads(envelope)
        except json.JSONDecodeError:
            return {"error": "unparsed string result", "raw": envelope[:2000]}
    if isinstance(envelope, dict):
        return envelope
    return {"error": f"unexpected result type {type(envelope).__name__}", "raw": str(envelope)[:2000]}


def cleanup() -> None:
    """Close the session and remove any window this run created.

    The owner's rule from AGENTS.md: a measuring run that leaves windows behind
    slows every later run down (one day of it produced 69 windows). One attempt
    each, no retry loop — a window held by the daemon is the daemon's business.
    """
    subprocess.run([OPENCLI, "browser", "close", "--session", SESSION],
                   capture_output=True, text=True, timeout=60)


FIXTURE_PAGE = """<!doctype html><meta charset="utf-8"><title>interactive-panel self check</title>
<style>
  .collapsed > * { display: none; }
</style>
<main>
  <button id="real" aria-expanded="false" aria-controls="panel-real">Real</button>
  <div id="panel-real" hidden><p>panel body text that should appear when the control is clicked</p></div>
  <button id="dead" aria-expanded="false" aria-controls="panel-dead">Dead</button>
  <div id="panel-dead" hidden><p>never shown</p></div>
  <button id="hollow" aria-expanded="true" aria-controls="panel-hollow">Hollow</button>
  <div id="panel-hollow" class="collapsed"><p>lorem ipsum dolor sit amet consectetur adipiscing elit sed do</p></div>
</main>
<script>
document.getElementById('real').addEventListener('click', function () {
  var p = document.getElementById('panel-real');
  p.hidden = !p.hidden;
  this.setAttribute('aria-expanded', String(!p.hidden));
});
// #hollow flips its own aria-expanded and does nothing to the panel: this is
// the owner's screenshot — a panel that is open, contains copy, and renders at
// zero height. It must be reported as empty-panel, not ok.
document.getElementById('hollow').addEventListener('click', function () {
  this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
});
</script>
"""


def self_check(verbose: bool = False) -> int:
    """Prove the judge can return both verdicts on a page built to produce them.

    The fixture is served over http from a throwaway server because opencli
    refuses non-http schemes (`about:blank` is rejected with "Blocked URL
    scheme"), and a file:// page would not exercise the same path.
    """
    import functools
    import http.server
    import threading

    handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory="/tmp/md-interactive-fixture")
    os.makedirs("/tmp/md-interactive-fixture", exist_ok=True)
    with open("/tmp/md-interactive-fixture/index.html", "w", encoding="utf-8") as fh:
        fh.write(FIXTURE_PAGE)

    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *a):  # noqa: D102 - silence the request log
            return

    handler = functools.partial(Quiet, directory="/tmp/md-interactive-fixture")
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = httpd.server_address[1]
    thread = threading.Thread(target=httpd.serve_forever, daemon=True)
    thread.start()
    try:
        code, out, err = call(["open", f"http://127.0.0.1:{port}/"], timeout=120)
        if code != 0:
            print(f"SELF-CHECK FAIL cannot open the fixture: rc={code} {err[:400]}")
            return 1
        # Order matters: the click judge flips every state switch it touches, so
        # the static judge must read the page as it loaded. Running them the
        # other way round made the static judge report nothing, because the
        # click judge had already collapsed the one expanded panel in the
        # fixture — a false pass with a perfectly plausible explanation.
        static = run_js(open(os.path.join(HERE, "open_panels.js"), encoding="utf-8").read())
        sf = static.get("findings") or []
        hollow = [f for f in sf if f.get("verdict") == "open-but-hollow"]
        blank = [f for f in sf if f.get("verdict") == "open-but-blank"]
        # The static judge's half: the open-but-hollow panel (#panel-hollow is
        # expanded on load in the fixture) must be found, and the live panel
        # (#panel-real, collapsed on load) must not be.
        hollow_ids = {f.get("panel") for f in hollow}
        static_ok = "panel-hollow" in hollow_ids and "panel-real" not in hollow_ids

        result = run_js(open(os.path.join(HERE, "interactions.js"), encoding="utf-8").read())
        findings = result.get("findings") or []
        by_id = {str(f.get("sel", "")): f.get("verdict") for f in findings}
        # The click judge's half: a working control must not be noise, and a
        # control that changes nothing must be called dead.
        clicks_ok = by_id.get("button#real") is None and by_id.get("button#dead") == "dead-control"

        ok = clicks_ok and static_ok
        print(f"SELF-CHECK {'ok  ' if ok else 'FAIL'} "
              f"click judge: real={by_id.get('button#real')} dead={by_id.get('button#dead')} "
              f"(want None / dead-control) | static judge: hollow={sorted(hollow_ids)} "
              f"blank={len(blank)} (want panel-hollow present, panel-real absent)")
        if verbose:
            print(json.dumps({"click": findings, "static": sf}, indent=2, ensure_ascii=False)[:3000])
        return 0 if ok else 1
    finally:
        httpd.shutdown()
        cleanup()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default="/tmp/md-interactive")
    ap.add_argument("--only", default="")
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--self-check", action="store_true")
    ap.add_argument("--verbose", action="store_true")
    # The click judge clicks up to 90 controls per page with a 420ms settle
    # after each, so a page with many switches takes minutes and can wedge on a
    # control that opens a modal. The static judge needs no clicks at all: it
    # reads which panels are ALREADY open and empty, which is the defect the
    # owner photographed. --static-only runs the cheap half alone.
    ap.add_argument("--static-only", action="store_true")
    args = ap.parse_args()

    if args.self_check:
        return self_check(args.verbose)

    os.makedirs(args.out, exist_ok=True)
    click_js = open(os.path.join(HERE, "interactions.js"), encoding="utf-8").read()
    static_js = open(os.path.join(HERE, "open_panels.js"), encoding="utf-8").read()

    pages = [(n, p) for n, p in PAGES if not args.only or args.only in n]
    report = []
    try:
        for name, path in pages:
            url = args.base + path
            code, out, err = call(["open", url], timeout=180)
            if code != 0:
                report.append({"page": name, "url": url, "error": f"open failed rc={code} {err[:300]}"})
                print(f"{name:16s} OPEN FAILED {err[:120]}")
                continue
            started = time.time()

            # Static judge first. The click judge flips every state switch it
            # touches, so anything read after it is no longer the page as the
            # visitor received it — and the open-but-hollow panels this run
            # exists to find would all be collapsed by then.
            static = run_js(static_js)
            result = run_js(click_js) if not args.static_only else {}
            result["page"] = name
            result["url"] = url
            result["seconds"] = round(time.time() - started, 1)
            result["static"] = static
            report.append(result)

            s = result.get("summary") or {}
            broken = sum(v for k, v in s.items() if k in BROKEN)
            ss = static.get("summary") or {}
            static_broken = sum(ss.values())
            print(f"{name:16s} w={static.get('width')} controls={result.get('controls')} "
                  f"tested={result.get('tested')} dead={broken} static={static_broken} "
                  f"{result.get('error') or static.get('error') or ''}")
            for f in (static.get("findings") or [])[:10]:
                print(f"    STATIC {f.get('verdict'):18s} h={f.get('height'):>5} "
                      f"w={f.get('words'):>3} [{str(f.get('heading',''))[:34]}] {str(f.get('panel',''))[:40]}")
            for f in (result.get("findings") or [])[:10]:
                print(f"    CLICK  {f.get('verdict'):18s} {f.get('sel','')[:44]:44s} {str(f.get('text',''))[:36]}")
            if args.verbose:
                print(json.dumps({"static": static, "click": result}, indent=2, ensure_ascii=False)[:6000])
    finally:
        cleanup()

    with open(os.path.join(args.out, "interactive.json"), "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, ensure_ascii=False)
    total_dead = sum(sum(v for k, v in (r.get("summary") or {}).items() if k in BROKEN) for r in report)
    total_static = sum(sum((r.get("static") or {}).get("summary", {}).values()) for r in report)
    print(f"\npages={len(report)} dead_controls={total_dead} static_findings={total_static}")
    print(f"wrote {os.path.join(args.out, 'interactive.json')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
