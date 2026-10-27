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
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
TEMPLATES = os.path.join(ROOT, "plugins/md-site/content/livecanvas")

REF = re.compile(r"/wp-content/uploads/[^\"'\s),]+\.(?:webp|jpe?g|png|avif|svg|gif)")


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


def status(base, ref):
    url = base.rstrip("/") + ref
    request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "Mozilla/5.0"})
    try:
        with urllib.request.urlopen(request, timeout=25) as response:
            return ref, response.status
    except urllib.error.HTTPError as error:
        if error.code == 405:
            # Some servers refuse HEAD; ask for the first byte instead.
            try:
                request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0", "Range": "bytes=0-0"})
                with urllib.request.urlopen(request, timeout=25) as response:
                    return ref, response.status
            except urllib.error.HTTPError as inner:
                return ref, inner.code
        return ref, error.code
    except Exception as error:  # noqa: BLE001 - reported, never swallowed
        return ref, f"ERR {type(error).__name__}"


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
