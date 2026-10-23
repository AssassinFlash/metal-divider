#!/usr/bin/env python3
"""Download all files in media-manifest.json to data/media-raw/ (not committed).

5 concurrent workers, resumable (skips files already on disk), then writes
size + sha256 back into media-manifest.json.
"""
import hashlib
import json
import os
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor

REPO = "/tmp/md-r-content-export"
MANIFEST = f"{REPO}/data/shopify-public/media-manifest.json"
DEST = f"{REPO}/data/media-raw"
os.makedirs(DEST, exist_ok=True)
UA = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) research-audit/1.0"}


def download(rec: dict) -> str:
    fn = rec["filename"]
    if "/" in fn:
        fn = fn.replace("/", "__")
    dest = os.path.join(DEST, fn)
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return "skip"
    if rec.get("master_src"):
        url = rec["master_src"]
    else:
        path = rec["cdn_path"]
        url = f"https://metalpartition.com/cdn/shop/{path}"
    tmp = dest + ".part"
    last = None
    for _ in range(4):
        try:
            req = urllib.request.Request(url, headers=UA)
            with urllib.request.urlopen(req, timeout=60) as r:
                body = r.read()
            with open(tmp, "wb") as f:
                f.write(body)
            os.replace(tmp, dest)
            return f"{len(body)}"
        except Exception as e:  # noqa: BLE001
            last = e
    print(f"FAILED {url}: {last}", flush=True)
    return "fail"


def main() -> None:
    man = json.load(open(MANIFEST))
    files = man["files"]
    # attach cdn_path for HTML-sourced entries (filename field holds path w/ query)
    for rec in files:
        if not rec.get("master_src"):
            rec.setdefault("cdn_path", rec["filename"])
    print(f"{len(files)} files")
    n = 0
    fails = []
    with ThreadPoolExecutor(max_workers=5) as ex:
        for rec, res in zip(files, ex.map(download, files)):
            n += 1
            if res == "fail":
                fails.append(rec["filename"])
            if n % 100 == 0:
                print(f"  {n}/{len(files)}", flush=True)
    print(f"done, {len(fails)} failures")
    for f in fails[:20]:
        print("  fail:", f)

    # hash everything
    def sha(p):
        h = hashlib.sha256()
        with open(p, "rb") as fh:
            for chunk in iter(lambda: fh.read(1 << 20), b""):
                h.update(chunk)
        return h.hexdigest()

    for rec in files:
        fn = rec["filename"].replace("/", "__")
        p = os.path.join(DEST, fn)
        if os.path.exists(p) and os.path.getsize(p) > 0:
            rec["bytes"] = os.path.getsize(p)
            rec["sha256"] = sha(p)
        else:
            rec["bytes"] = None
            rec["sha256"] = None
    man["_meta"]["hashed_at"] = "2026-10-24"
    man["_meta"]["failed"] = fails
    with open(MANIFEST, "w") as f:
        json.dump(man, f, ensure_ascii=False, indent=1)
    print("manifest updated")


if __name__ == "__main__":
    sys.exit(main())
