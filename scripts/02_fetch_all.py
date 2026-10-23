#!/usr/bin/env python3
"""Fetch all public product JSON and rendered HTML (EN + zh) into a raw cache.

Resumable: skips files that already exist and are non-empty.

Output layout under /tmp/md-r-work/raw/:
  products_json/<handle>.json          GET /products/<handle>.json
  html/en/product/<handle>.html        rendered product page
  html/zh/product/<handle>.html        rendered zh product page
  html/en/page/<handle>.html
  html/zh/page/<handle>.html
  html/en/collection/<handle>.html
  html/zh/collection/<handle>.html
  html/en/blog/<blog_handle>.html
  html/zh/blog/<blog_handle>.html
  html/en/article/<blog>__<article>.html
  html/zh/article/<blog>__<article>.html
  html/en/home.html  html/zh/home.html
"""
import json
import os
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urlparse

RAW = "/tmp/md-r-work/raw"
UA = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) research-audit/1.0"}
LOCK = __import__("threading").Lock()


def fetch_to(url: str, dest: str) -> str:
    if os.path.exists(dest) and os.path.getsize(dest) > 0:
        return "skip"
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    tmp = dest + ".part"
    req = urllib.request.Request(url, headers=UA)
    last = None
    for _ in range(4):
        try:
            with urllib.request.urlopen(req, timeout=45) as r:
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
    jobs: list[tuple[str, str]] = []
    with open(os.path.join(RAW, "url_lists.json")) as f:
        lists = json.load(f)

    prod_urls = [u for u in lists["sitemap_products_1.xml"] if "/products/" in u]
    handles = [urlparse(u).path.rsplit("/", 1)[-1] for u in prod_urls]
    for h in handles:
        jobs.append((f"https://metalpartition.com/products/{h}.json",
                     f"{RAW}/products_json/{h}.json"))
    jobs.append(("https://metalpartition.com/products.json?limit=250",
                 f"{RAW}/products_json/_all_limit250.json"))

    groups = [
        ("sitemap_products_1.xml", "/products/", "product"),
        ("sitemap_pages_1.xml", "/pages/", "page"),
        ("sitemap_collections_1.xml", "/collections/", "collection"),
    ]
    for lang in ("en", "zh"):
        prefix = "" if lang == "en" else "/zh"
        for sm, marker, kind in groups:
            for u in lists[sm]:
                if marker not in u:
                    continue
                tail = urlparse(u).path.rstrip("/").rsplit("/", 1)[-1]
                if kind == "collection" and tail == "collections":
                    continue
                jobs.append((f"https://metalpartition.com{prefix}{urlparse(u).path}",
                             f"{RAW}/html/{lang}/{kind}/{tail}.html"))

        for blog_url in lists["sitemap_blogs_1.xml"]:
            b = urlparse(blog_url).path.rstrip("/")
            bt = b.rsplit("/", 1)[-1]
            jobs.append((f"https://metalpartition.com{prefix}{b}",
                         f"{RAW}/html/{lang}/blog/{bt}.html"))
        for art_url in lists["sitemap_articles_1.xml"]:
            a = urlparse(art_url).path.rstrip("/")
            _, _, blog_t, art_t = a.rsplit("/", 3)
            jobs.append((f"https://metalpartition.com{prefix}{a}",
                         f"{RAW}/html/{lang}/article/{blog_t}__{art_t}.html"))
        jobs.append((f"https://metalpartition.com{prefix}/",
                     f"{RAW}/html/{lang}/home.html"))

    # collection product listings (public JSON) for membership check
    for coll in lists["sitemap_collections_1.xml"]:
        t = urlparse(coll).path.rstrip("/").rsplit("/", 1)[-1]
        jobs.append((f"https://metalpartition.com/collections/{t}/products.json?limit=250",
                     f"{RAW}/products_json/_coll_{t}.json"))

    print(f"{len(jobs)} jobs")
    done = 0
    with ThreadPoolExecutor(max_workers=5) as ex:
        for res in ex.map(lambda j: fetch_to(*j), jobs):
            done += 1
            if done % 50 == 0:
                print(f"  {done}/{len(jobs)}", flush=True)
    print("done")


if __name__ == "__main__":
    sys.exit(main())
