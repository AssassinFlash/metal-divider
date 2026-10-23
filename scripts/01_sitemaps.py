#!/usr/bin/env python3
"""Fetch every sitemap under metalpartition.com/sitemap.xml and extract URL lists.

Output:
  /tmp/md-r-work/raw/sitemaps/*.xml        raw sitemap files
  /tmp/md-r-work/raw/url_lists.json        {sitemap_name: [urls]}
"""
import json
import os
import re
import urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = "https://metalpartition.com"
OUT = "/tmp/md-r-work/raw"
SITEMAP_DIR = os.path.join(OUT, "sitemaps")
os.makedirs(SITEMAP_DIR, exist_ok=True)

UA = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) research-audit/1.0"}


def fetch(url: str) -> bytes | None:
    req = urllib.request.Request(url, headers=UA)
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read()
        except Exception as e:  # noqa: BLE001
            print(f"  retry {attempt + 1} {url}: {e}")
    print(f"  FAILED {url}")
    return None


def sitemap_name(url: str) -> str:
    name = url.split("/", 3)[-1].split("?")[0]
    name = name.replace("/", "_")
    if "zh" in url.split("?")[0]:
        name = "zh_" + name
    return name


def main() -> None:
    raw = fetch(f"{BASE}/sitemap.xml")
    assert raw, "main sitemap fetch failed"
    with open(os.path.join(SITEMAP_DIR, "sitemap.xml"), "wb") as f:
        f.write(raw)
    subs = re.findall(rb"<loc>(.*?)</loc>", raw)
    subs = [s.decode().replace("&amp;", "&") for s in subs]
    print(f"{len(subs)} sub-sitemaps")

    def job(url: str):
        data = fetch(url)
        if data:
            with open(os.path.join(SITEMAP_DIR, sitemap_name(url)), "wb") as f:
                f.write(data)
        return url, data

    url_lists = {}
    with ThreadPoolExecutor(max_workers=5) as ex:
        for url, data in ex.map(job, subs):
            if not data:
                continue
            locs = [
                loc.decode().replace("&amp;", "&")
                for loc in re.findall(rb"<loc>(.*?)</loc>", data)
            ]
            url_lists[sitemap_name(url)] = locs
            print(f"  {sitemap_name(url)}: {len(locs)} urls")

    with open(os.path.join(OUT, "url_lists.json"), "w") as f:
        json.dump(url_lists, f, indent=1)
    print("wrote url_lists.json")


if __name__ == "__main__":
    main()
