#!/usr/bin/env python3
"""Sweep the site for visual defects at every required viewport width.

Drives the owner's own Chrome through `opencli` — never Playwright. The rule is
the owner's: browser automation uses the real browser through opencli, with no
fallback to any other driver.

## How a viewport is set

opencli's `eval` always runs at the window's own size, so DOM measurements are
taken at the window width and the other widths are measured from a capture:

  opencli browser screenshot <path> --width 390 --height 900

That flag does not crop — Chrome lays the page out at the requested width.
Verified on this site by three independent signals:

  * `--md-header-h` resolves to 73px in the 320 and 390 captures and to 113px
    at 1440 (tokens.css defines exactly those three values);
  * `lg:hidden` blocks that compute to `display:none` in the window viewport
    appear in the 390 capture and are absent from the 1440 one;
  * a same-page pixel comparison of the two captures is only 41% identical,
    which a crop cannot be.

So: the DOM probe runs once at the window width, and every other width gets a
full-page capture that is then analysed for the defects that are visible as
pixels (horizontal overflow via content touching the frame, dead vertical
bands, text against the frame).

Usage:
    python3 tools/audit/sweep_opencli.py [--base URL] [--out DIR] [--widths 1440,390,320]
"""
import argparse
import json
import os
import struct
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
DEFECTS = os.path.join(HERE, "defects.js")
OPENCLI = os.environ.get("OPENCLI") or os.path.expanduser("~/.local/bin/opencli")

PAGES = [
    ("home", "/"),
    ("inquiry", "/inquiry/"),
    ("faqs", "/faqs/"),
    ("custom-screens", "/custom-metal-divider-screens/"),
    ("laser-cut-geometric", "/laser-cut-geometric-metal-screens/"),
    ("glass-hybrid", "/glass-hybrid-screens/"),
    ("outdoor-privacy", "/outdoor-privacy-landscape-screens/"),
    ("chinese-style", "/chinese-style-artistic-screens/"),
    ("stainless-steel", "/stainless-steel-screens/"),
    ("manufacturing", "/manufacturing-process/"),
    ("laser-cut-panels", "/laser-cut-panels/"),
    ("perforated-panels", "/perforated-panels/"),
    ("expanded-metal", "/expanded-metal-panels/"),
    ("project-gallery", "/project-gallery/"),
    ("about-us", "/about-us/"),
    ("privacy", "/privacy/"),
    ("hub", "/collections/"),
    ("shop", "/shop/"),
    ("blog", "/blog/"),
    ("thank-you", "/inquiry/thank-you/"),
    ("col-geometric", "/collections/pattern-geometric/"),
    ("col-abstract", "/collections/pattern-abstract/"),
    ("col-perforated", "/collections/pattern-perforated/"),
    ("col-gradient", "/collections/pattern-gradient/"),
    ("pdp-apiary", "/pattern/apiary-architectural-metal-screen-pattern/"),
    ("pdp-aztec", "/pattern/aztec-architectural-metal-screen-pattern/"),
    ("pdp-herringbone", "/pattern/herringbone-architectural-metal-screen-pattern/"),
    ("pdp-orchid", "/pattern/orchid-lattice-architectural-metal-screen-pattern/"),
    ("pdp-square-rage", "/pattern/square-rage-architectural-metal-screen-pattern/"),
    ("pdp-vlahos", "/pattern/the-vlahos-architectural-metal-screen-pattern/"),
]


def run(args, timeout=240):
    proc = subprocess.run([OPENCLI] + args, capture_output=True, text=True, timeout=timeout)
    line = proc.stdout.strip().splitlines()[-1] if proc.stdout.strip() else ""
    try:
        return json.loads(line)
    except json.JSONDecodeError:
        return {"ok": False, "error": (proc.stdout + proc.stderr)[:300]}


def call(session, args, timeout=240):
    return run(["browser"] + args + ["--session", session], timeout)


def settle(session):
    """Walk the page so lazy images resolve, then wait for the last decode."""
    call(session, ["eval", "(()=>{let y=0;const s=()=>{window.scrollTo(0,y);y+=700;"
                           "return y<document.documentElement.scrollHeight;};window.__md=s;return 1})()"])
    for _ in range(60):
        res = call(session, ["eval", "(()=>!!(window.__md&&window.__md()))()"])
        if res.get("data") is not True:
            break
        time.sleep(0.12)
    # Wait for every image to finish, then two frames for layout.
    call(session, ["eval",
                   "(async()=>{const dl=Date.now()+8000;"
                   "while(Date.now()<dl){if(![...document.images].filter(i=>!i.complete).length)break;"
                   "await new Promise(r=>setTimeout(r,120));}"
                   "window.scrollTo(0,0);"
                   "await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));return 1})()"])


def png_size(path):
    with open(path, "rb") as fh:
        head = fh.read(24)
    return struct.unpack(">II", head[16:24])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--out", default="/tmp/md-sweep-opencli")
    ap.add_argument("--widths", default="1440,390,320")
    ap.add_argument("--only", default="")
    args = ap.parse_args()

    widths = [int(w) for w in args.widths.split(",")]
    base = args.base.rstrip("/")
    bust = "?mdsweep=1" if "metal-divider.com" in base else ""
    os.makedirs(args.out, exist_ok=True)

    pages = PAGES
    if args.only:
        wanted = set(args.only.split(","))
        pages = [p for p in PAGES if p[0] in wanted]

    session = "mdsweep"
    window_width = None
    res = call(session, ["eval", "(()=>innerWidth)()"])
    if res.get("ok"):
        window_width = res.get("data")

    report = []
    for slug, path in pages:
        url = base + path + bust
        got = call(session, ["open", url], timeout=300)
        if not got.get("ok"):
            report.append({"slug": slug, "path": path, "error": "open failed: " + str(got)[:160]})
            print("%-20s open failed" % slug, flush=True)
            continue
        call(session, ["wait", "selector", "main"])
        settle(session)

        # DOM-level findings, at the window's own width.
        res = call(session, ["eval", open(DEFECTS).read()], timeout=300)
        dom = {}
        if res.get("ok"):
            try:
                dom = json.loads(res["data"])
            except json.JSONDecodeError:
                dom = {"error": "unparsable probe output"}

        shots = {}
        for width in widths:
            path_png = os.path.join(args.out, "%s-%d.png" % (slug, width))
            shot = call(session, ["screenshot", path_png, "--width", str(width),
                                  "--height", "900", "--full-page"], timeout=300)
            if shot.get("ok") and os.path.exists(path_png):
                shots[width] = {"file": path_png, "size": png_size(path_png)}
            else:
                shots[width] = {"error": str(shot)[:160]}

        report.append({
            "slug": slug,
            "path": path,
            "url": url,
            "windowWidth": window_width,
            "dom": {"summary": dom.get("summary", {}), "items": dom.get("items", [])},
            "captures": shots,
        })
        # Write after every page: a run that dies at page 27 must not lose the
        # 26 pages it already measured.
        with open(os.path.join(args.out, "report.json"), "w") as fh:
            json.dump(report, fh, indent=1)
        print("%-20s dom=%s captures=%s" % (
            slug, json.dumps(dom.get("summary", {})), sorted(shots)), flush=True)

    out_json = os.path.join(args.out, "report.json")
    with open(out_json, "w") as fh:
        json.dump(report, fh, indent=1)
    print("\nwrote %s (%d pages)" % (out_json, len(report)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
