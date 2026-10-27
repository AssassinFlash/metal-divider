#!/usr/bin/env python3
"""Capture a content graph for every page of the old and new site.

Read-only. Drives the owner's real Chrome through opencli, scrolls each page so
lazy images resolve, then evaluates tools/audit/page-graph.js and stores the
result. The graphs are what the parity diff consumes.

Usage:
    python3 tools/audit/capture.py old|new|both [--out DIR] [--sessions N]
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
GRAPH_JS = os.path.join(HERE, "page-graph.js")
OPENCLI = shutil.which("opencli") or os.path.expanduser("~/.local/bin/opencli")

OLD = "https://metalpartition.com"
NEW = "https://metal-divider.com"

# (slug, old path, new path). The rebuild renamed /pages/<x> to /<x>/ and turned
# Shopify /pages/contact into /inquiry/. The old shop's home is "/", not
# /pages/metal-divider — that handle 301s to the custom-screens page, which is
# why capturing it as home silently produced the same graph twice. The eight
# Consentmo compliance pages collapse into one new privacy page.
PAGES = [
    ("home", "/", "/"),
    ("contact", "/pages/contact", "/inquiry/"),
    ("faqs", "/pages/faqs", "/faqs/"),
    ("custom-screens", "/pages/custom-metal-divider-screens", "/custom-metal-divider-screens/"),
    ("laser-cut-geometric", "/pages/laser-cut-geometric-metal-screens", "/laser-cut-geometric-metal-screens/"),
    ("glass-hybrid", "/pages/glass-hybrid-screens", "/glass-hybrid-screens/"),
    ("outdoor-privacy", "/pages/outdoor-privacy-landscape-screens", "/outdoor-privacy-landscape-screens/"),
    ("chinese-style", "/pages/chinese-style-artistic-screens", "/chinese-style-artistic-screens/"),
    ("stainless-steel", "/pages/stainless-steel-screens", "/stainless-steel-screens/"),
    ("manufacturing", "/pages/manufacturing-process", "/manufacturing-process/"),
    ("laser-cut-panels", "/pages/laser-cut-panels", "/laser-cut-panels/"),
    ("perforated-panels", "/pages/perforated-panels", "/perforated-panels/"),
    ("expanded-metal", "/pages/expanded-metal-panels", "/expanded-metal-panels/"),
    ("project-gallery", "/pages/project-gallery", "/project-gallery/"),
    ("about-us", "/pages/about-us", "/about-us/"),
]

COLLECTIONS = [
    "pattern-abstract", "pattern-asian", "pattern-geometric", "pattern-gradient",
    "pattern-landscape", "pattern-moroccan-arabic", "pattern-organic",
    "pattern-perforated", "pattern-vintage-retro",
]


def run(args, timeout=180):
    return subprocess.run([OPENCLI] + args, capture_output=True, text=True, timeout=timeout)


def session_call(session, args, timeout=180):
    proc = run(["browser"] + args + ["--session", session], timeout=timeout)
    try:
        payload = json.loads(proc.stdout.strip().splitlines()[-1])
    except Exception:
        return {"ok": False, "error": (proc.stdout + proc.stderr)[:400]}
    return payload


def settle(session):
    """Scroll the whole page so lazy images resolve, then return to the top."""
    session_call(session, ["eval", "(()=>{let y=0;const h=()=>document.documentElement.scrollHeight;"
                                     "const step=()=>{window.scrollTo(0,y);y+=600;return y<h();};"
                                     "window.__mdStep=step;return h();})()"])
    for _ in range(40):
        res = session_call(session, ["eval", "(()=>{const more=window.__mdStep&&window.__mdStep();return !!more;})()"])
        data = res.get("data")
        if data is not True:
            break
        time.sleep(0.25)
    session_call(session, ["eval", "window.scrollTo(0,0)"])


def capture_one(job):
    session, slug, url, out_dir = job
    # A busting parameter: this site sends `cache-control: public, max-age=604800`
    # on HTML, so an un-purged page cache serves a week-old document to a real
    # browser while curl without it gets a fresh render. An audit that does not
    # bust the cache measures the cache, not the site.
    sep = "&" if "?" in url else "?"
    url = url + sep + "mdaudit=1"
    got = session_call(session, ["open", url], timeout=240)
    if not got.get("ok"):
        return slug, {"error": "open failed", "detail": str(got)[:300]}
    session_call(session, ["wait", "selector", "body"], timeout=60)
    settle(session)
    with open(GRAPH_JS) as fh:
        js = fh.read()
    res = session_call(session, ["eval", js], timeout=180)
    if not res.get("ok"):
        return slug, {"error": "eval failed", "detail": str(res)[:300]}
    try:
        graph = json.loads(res["data"])
    except Exception:
        return slug, {"error": "bad json", "detail": str(res["data"])[:300]}
    graph["slug"] = slug
    graph["source"] = url
    with open(os.path.join(out_dir, slug + ".json"), "w") as fh:
        json.dump(graph, fh, indent=1)
    return slug, {"ok": True, "counts": graph["counts"], "doc_h": graph["doc"]["h"]}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("which", choices=["old", "new", "both"])
    ap.add_argument("--out", default="/tmp/md-audit")
    # One session by default. Several opencli sessions share one Chrome
    # window, so parallel tabs steal focus from each other: a run with four
    # sessions captured the Asian collection page into the FAQ slot and the
    # custom-screens page into the home slot, and the diff that followed
    # reported 732 "missing" strings that were really mislabelled captures.
    ap.add_argument("--sessions", type=int, default=1)
    args = ap.parse_args()

    jobs = []
    sessions = ["mdg%d" % i for i in range(args.sessions)]
    used = 0

    def enqueue(base, slug, path, side):
        nonlocal used
        out_dir = os.path.join(args.out, side)
        os.makedirs(out_dir, exist_ok=True)
        jobs.append((sessions[used % len(sessions)], slug, base + path, out_dir))
        used += 1

    if args.which in ("old", "both"):
        for slug, old_path, _ in PAGES:
            enqueue(OLD, slug, old_path, "old")
        for col in COLLECTIONS:
            enqueue(OLD, "col-" + col, "/collections/" + col, "old")
    if args.which in ("new", "both"):
        for slug, _, new_path in PAGES:
            enqueue(NEW, slug, new_path, "new")
        for col in COLLECTIONS:
            enqueue(NEW, "col-" + col, "/collections/" + col + "/", "new")

    # One session per parallel worker: opencli serialises work inside a session,
    # so jobs are grouped by session rather than interleaved.
    by_session = {}
    for job in jobs:
        by_session.setdefault(job[0], []).append(job)

    results = {}

    def drain(session_jobs):
        for job in session_jobs:
            slug, res = capture_one(job)
            results[job[3].split("/")[-1] + "/" + slug] = res
            print("%s/%s %s" % (job[3].split("/")[-1], slug, json.dumps(res)[:180]), flush=True)

    with ThreadPoolExecutor(max_workers=len(by_session)) as pool:
        list(pool.map(drain, by_session.values()))

    bad = {k: v for k, v in results.items() if not v.get("ok")}
    print("\ncaptured %d/%d" % (len(results) - len(bad), len(results)))
    for k, v in bad.items():
        print("FAILED", k, v)
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
