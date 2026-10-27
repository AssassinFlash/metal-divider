#!/usr/bin/env python3
"""Fail when a template references a media file the site does not serve.

Naming drift between the media pipeline and the templates is invisible in the
source: `Long_Island_Fence-480w.webp` and `long-island-fence-480w.webp` look
equally plausible, and only the deployed site knows which exists. That single
mismatch shipped a home-page pattern card with one dead image and was found
only because a viewport sweep happened to scroll past it.

This reads every `/wp-content/uploads/...` reference out of the templates and
asks the origin for each one. It is a network check by design — the local
media tree proves what the pipeline produced, not what the site serves.

Usage:
    python3 tools/audit/media_refs.py [--base URL] [--jobs N]

Exit code 1 when any reference 404s.
"""
import argparse
import concurrent.futures
import glob
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
TEMPLATES = os.path.join(ROOT, "plugins/md-site/content/livecanvas")

# The path stops at a quote, whitespace, a paren, a comma AND a pipe: a
# `<picture>` with several `<source>` elements writes them back to back inside
# one attribute, separated by `|`, and a regex that ignored the pipe glued three
# real URLs into one 404. That single mistake produced most of the first run's
# "not served" list.
REF = re.compile(r"/wp-content/uploads/[^\"'\s),|]+\.(?:webp|jpe?g|png|avif|svg|gif)")


def references():
    """Every uploads path in the templates, with the files that mention it."""
    found = {}
    patterns = [os.path.join(TEMPLATES, "*.html")]
    patterns += [os.path.join(TEMPLATES, "partials", "*.html")]
    for pattern in patterns:
        for path in glob.glob(pattern):
            name = os.path.relpath(path, TEMPLATES)
            with open(path) as fh:
                for ref in REF.findall(fh.read()):
                    found.setdefault(ref, set()).add(name)
    return found


GATEWAY_ERRORS = {502, 503, 504}


def status(base, ref, attempts=3):
    """The file's HTTP status, retrying gateway errors.

    A 504 means this host took too long under the checker's parallel load; it
    says nothing about whether the file exists. Seven of them appeared in one
    run and every one answered 200 when asked again on its own. Retrying, with
    the last attempt alone, separates "slow server" from "missing file" without
    pretending a timeout is a defect.
    """
    code = None
    for attempt in range(attempts):
        code = _status_once(base, ref)
        if code not in GATEWAY_ERRORS:
            return ref, code
        time.sleep(1.5 * (attempt + 1))
    return ref, code


def _status_once(base, ref):
    url = base.rstrip("/") + ref
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "Mozilla/5.0"})
    try:
        with urllib.request.urlopen(request, timeout=40) as response:
            return response.status
    except urllib.error.HTTPError as error:
        if error.code == 405:
            # Some servers refuse HEAD; ask for the first byte instead.
            try:
                request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0", "Range": "bytes=0-0"})
                with urllib.request.urlopen(request, timeout=40) as response:
                    return response.status
            except urllib.error.HTTPError as inner:
                return inner.code
        return error.code
    except Exception as error:  # noqa: BLE001 - reported, never swallowed
        return f"ERR {type(error).__name__}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--jobs", type=int, default=12)
    args = ap.parse_args()

    refs = references()
    print("checking %d references against %s" % (len(refs), args.base))

    bad = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs) as pool:
        futures = [pool.submit(status, args.base, ref) for ref in sorted(refs)]
        for future in concurrent.futures.as_completed(futures):
            ref, code = future.result()
            if code != 200:
                bad.append((code, ref, sorted(refs[ref])))

    bad.sort(key=lambda row: str(row[0]))
    for code, ref, where in bad:
        print("  %-8s %s\n           in %s" % (code, ref, ", ".join(where)))

    print("\n%d checked, %d not served" % (len(refs), len(bad)))
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
