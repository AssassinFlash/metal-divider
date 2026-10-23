#!/usr/bin/env python3
"""Parse raw fetches into structured exports under data/shopify-public/.

Reads /tmp/md-r-work/raw/, writes into repo data/shopify-public/:
  products.json      per-product: public JSON fields + spec strip parsed from EN HTML
                     + zh state + media list
  collections.json   handle, title from HTML, membership from collection JSON,
                     metafield-rendered-content check
  pages.json         per-page: title, headings, text length, image count, zh parity
  blogs.json         blogs + articles with content
  media-manifest.json every CDN file referenced: filename, url, dims, sha256 later
"""
import hashlib
import json
import os
import re
from html.parser import HTMLParser

RAW = "/tmp/md-r-work/raw"
OUT = "/tmp/md-r-content-export/data/shopify-public"
os.makedirs(OUT, exist_ok=True)


def rd(p):
    with open(p, encoding="utf-8", errors="replace") as f:
        return f.read()


class TextExtract(HTMLParser):
    """Collect text with script/style skipped."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []
        self.skip = 0

    def handle_starttag(self, tag, attrs):
        if tag in ("script", "style", "noscript"):
            self.skip += 1

    def handle_endtag(self, tag):
        if tag in ("script", "style", "noscript") and self.skip:
            self.skip -= 1

    def handle_data(self, d):
        if not self.skip:
            self.parts.append(d)


def text_of(html: str) -> str:
    p = TextExtract()
    p.feed(html)
    return re.sub(r"\s+", " ", "".join(p.parts)).strip()


def main_content(html: str) -> str:
    """Cut to <main ...> ... </main> if present."""
    m = re.search(r"<main\b.*?>(.*)</main>", html, re.S)
    return m.group(1) if m else html


def parse_spec_strip(html: str) -> dict:
    m = re.search(r'<dl class="pdp-spec-strip[^"]*">(.*?)</dl>', html, re.S)
    if not m:
        return {}
    out = {}
    for dt, dd in re.findall(r"<dt>(.*?)</dt><dd>(.*?)</dd>", m.group(1), re.S):
        out[text_of(dt)] = text_of(dd)
    return out


def parse_design_intent(html: str) -> dict:
    m = re.search(
        r'<span class="pdp-eyebrow">Design intent</span>\s*<h2>(.*?)</h2>(.*?)</div>',
        html, re.S)
    if not m:
        return {}
    bodies = [text_of(x) for x in re.findall(r'<p class="pdp-body">(.*?)</p>', m.group(2), re.S)]
    return {"heading": text_of(m.group(1)), "body": [b for b in bodies if b]}


def parse_app_rows(html: str) -> list:
    rows = []
    for m in re.finditer(r'<article class="pdp-app-row[^"]*">\s*<h3>(.*?)</h3>\s*<p>(.*?)</p>', html, re.S):
        rows.append({"title": text_of(m.group(1)), "text": text_of(m.group(2))})
    return rows


def parse_faq(html: str) -> list:
    out = []
    m = re.search(r'<h2[^>]*>\s*Technical FAQ\s*</h2>(.*?)</section>', html, re.S)
    if not m:
        return out
    seg = m.group(1)
    for q, a in re.findall(
            r'<button class="pdp-faq-q"[^>]*>(.*?)<span[^>]*>.*?</button>\s*'
            r'<div class="pdp-faq-a"[^>]*>\s*<p>(.*?)</p>', seg, re.S):
        out.append({"q": text_of(q), "a": text_of(a)})
    return out


def cdn_files(html: str) -> set:
    out = set()
    for m in re.finditer(r"(?:https:)?//metalpartition\.com/cdn/shop/([^\"'\s&]+)", html):
        out.add(m.group(1))
    return out


def main() -> None:
    lists = json.load(open(f"{RAW}/url_lists.json"))
    handles = [u.rsplit("/", 1)[-1] for u in lists["sitemap_products_1.xml"] if "/products/" in u]

    # ---------- products ----------
    products = []
    media = {}  # filename -> record
    spec_missing, faq_all = [], []
    for h in handles:
        pj = json.load(open(f"{RAW}/products_json/{h}.json"))["product"]
        en_html = rd(f"{RAW}/html/en/product/{h}.html")
        zh_html = rd(f"{RAW}/html/zh/product/{h}.html")

        spec = parse_spec_strip(en_html)
        if not spec:
            spec_missing.append(h)
        di = parse_design_intent(en_html)
        apps = parse_app_rows(en_html)
        faq = parse_faq(en_html)
        faq_all.append((h, json.dumps(faq, ensure_ascii=False)))

        raw_tags = pj.get("tags") or []
        if isinstance(raw_tags, str):
            tags = [t.strip() for t in raw_tags.split(",") if t.strip()]
        else:
            tags = [t.strip() for t in raw_tags if t.strip()]
        tag_map = {}
        for t in tags:
            if ":" in t:
                k, v = t.split(":", 1)
                tag_map[k] = v

        imgs = []
        for im in pj.get("images", []):
            src = im["src"]
            fname = src.split("/files/")[-1].split("?")[0]
            imgs.append({
                "position": im["position"], "filename": fname,
                "src": src, "width": im.get("width"), "height": im.get("height"),
                "alt": im.get("alt"),
            })
            rec = media.setdefault(fname, {"filename": fname, "master_src": src,
                                           "width": im.get("width"), "height": im.get("height"),
                                           "refs": []})
            rec["refs"].append(f"product:{h}")

        zh_spec = parse_spec_strip(zh_html)
        zh_txt = text_of(main_content(zh_html))
        en_txt = text_of(main_content(en_html))
        zh_en_same = zh_txt == en_txt
        zh_cjk_n = len(re.findall(r"[一-鿿]", zh_txt))
        en_cjk_n = len(re.findall(r"[一-鿿]", en_txt))

        products.append({
            "handle": h, "id": pj["id"], "title": pj["title"],
            "published_at": pj.get("published_at"), "updated_at": pj.get("updated_at"),
            "vendor": pj.get("vendor"), "product_type": pj.get("product_type"),
            "status_hint": pj.get("status"),
            "tags": tags, "tag_map": tag_map,
            "body_html_len": len(pj.get("body_html") or ""),
            "body_html": pj.get("body_html") or "",
            "options": [{"name": o["name"], "values": o["values"]} for o in pj.get("options", [])],
            "variant": {k: pj["variants"][0].get(k) for k in ("price", "sku", "barcode", "grams", "weight_unit", "inventory_management")} if pj.get("variants") else None,
            "spec_strip": spec,
            "design_intent": di,
            "application_rows": apps,
            "tech_faq": faq,
            "images": imgs,
            "zh": {"spec_same_as_en": zh_spec == spec, "content_same_as_en": zh_en_same,
                   "cjk_chars_in_main": zh_cjk_n, "cjk_chars_in_en_main": en_cjk_n,
                   "verdict": "en-only" if zh_cjk_n - en_cjk_n < 5 else "partial-or-full-zh"},
        })

    with open(f"{OUT}/products.json", "w") as f:
        json.dump({"_meta": {"count": len(products), "source": "metalpartition.com public JSON + rendered HTML 2026-10-24"},
                   "products": products}, f, ensure_ascii=False, indent=1)

    # ---------- collections ----------
    collections = []
    for cu in lists["sitemap_collections_1.xml"]:
        t = cu.rsplit("/", 1)[-1]
        cj = json.load(open(f"{RAW}/products_json/_coll_{t}.json"))
        members = [p["handle"] for p in cj.get("products", [])]
        en_html = rd(f"{RAW}/html/en/collection/{t}.html")
        title = re.search(r"<title>(.*?)</title>", en_html)
        meta_rendered = {k: (k in en_html) for k in
                         ("Pattern Family", "Suitable Materials", "Typical Applications",
                          "Difficulty Level")}
        zh_html = rd(f"{RAW}/html/zh/collection/{t}.html")
        collections.append({
            "handle": t, "title": text_of(title.group(1)) if title else None,
            "product_count": len(members), "members": members,
            "collection_metafields_rendered": meta_rendered,
            "zh_content_same_as_en": text_of(main_content(zh_html)) == text_of(main_content(en_html)),
        })
    with open(f"{OUT}/collections.json", "w") as f:
        json.dump({"_meta": {"count": len(collections)}, "collections": collections}, f, ensure_ascii=False, indent=1)

    # ---------- pages ----------
    pages = []
    for pu in lists["sitemap_pages_1.xml"]:
        t = pu.rsplit("/", 1)[-1]
        en_html = rd(f"{RAW}/html/en/page/{t}.html")
        mc = main_content(en_html)
        txt = text_of(mc)
        zh_html = rd(f"{RAW}/html/zh/page/{t}.html")
        zh_txt = text_of(main_content(zh_html))
        title = re.search(r"<title>(.*?)</title>", en_html)
        pages.append({
            "handle": t, "title": text_of(title.group(1)) if title else None,
            "text_len": len(txt),
            "headings": [text_of(x) for lv, x in re.findall(r"<h([1-3])[^>]*>(.*?)</h\1>", mc, re.S)][:40],
            "cdn_files": sorted(cdn_files(mc)),
            "zh": {"content_same_as_en": zh_txt == txt,
                   "cjk_chars_in_main": len(re.findall(r"[一-鿿]", zh_txt)),
                   "cjk_chars_in_en_main": len(re.findall(r"[一-鿿]", txt))},
        })
    # home
    for lang, url_label in (("en", "home"), ("zh", "home-zh")):
        pass
    en_home = rd(f"{RAW}/html/en/home.html")
    zh_home = rd(f"{RAW}/html/zh/home.html")
    home_txt = text_of(main_content(en_home))
    pages.append({
        "handle": "/", "title": "Home",
        "text_len": len(home_txt),
        "headings": [text_of(x) for lv, x in re.findall(r"<h([1-3])[^>]*>(.*?)</h\1>", main_content(en_home), re.S)][:40],
        "cdn_files": sorted(cdn_files(main_content(en_home))),
        "zh": {"content_same_as_en": text_of(main_content(zh_home)) == home_txt,
               "cjk_chars_in_main": len(re.findall(r"[一-鿿]", text_of(main_content(zh_home)))),''
               "cjk_chars_in_en_main": len(re.findall(r"[一-鿿]", home_txt))},
    })
    with open(f"{OUT}/pages.json", "w") as f:
        json.dump({"_meta": {"count": len(pages)}, "pages": pages}, f, ensure_ascii=False, indent=1)

    # ---------- blogs ----------
    blogs = []
    for bu in lists["sitemap_blogs_1.xml"]:
        bt = bu.rsplit("/", 1)[-1]
        en_html = rd(f"{RAW}/html/en/blog/{bt}.html")
        arts = [u for u in lists["sitemap_articles_1.xml"] if f"/blogs/{bt}/" in u]
        art_recs = []
        for au in arts:
            at = au.rsplit("/", 1)[-1]
            ah = rd(f"{RAW}/html/en/article/{bt}__{at}.html")
            zh_ah = rd(f"{RAW}/html/zh/article/{bt}__{at}.html")
            am = re.search(r"<title>(.*?)</title>", ah)
            body = re.search(r"<article\b.*?>(.*?)</article>", ah, re.S)
            art_recs.append({
                "handle": at, "title": text_of(am.group(1)) if am else None,
                "body_text_len": len(text_of(body.group(1))) if body else 0,
                "zh_content_same_as_en": text_of(main_content(zh_ah)) == text_of(main_content(ah)),
            })
        tm = re.search(r"<title>(.*?)</title>", en_html)
        blogs.append({"handle": bt, "title": text_of(tm.group(1)) if tm else None,
                      "article_count": len(art_recs), "articles": art_recs})
    with open(f"{OUT}/blogs.json", "w") as f:
        json.dump({"_meta": {"count": len(blogs)}, "blogs": blogs}, f, ensure_ascii=False, indent=1)

    # ---------- media from pages + collections + homes too ----------
    for pu in lists["sitemap_pages_1.xml"]:
        t = pu.rsplit("/", 1)[-1]
        for fn in cdn_files(main_content(rd(f"{RAW}/html/en/page/{t}.html"))):
            media.setdefault(fn, {"filename": fn, "master_src": None, "width": None,
                                  "height": None, "refs": []})["refs"].append(f"page:{t}")
    for cu in lists["sitemap_collections_1.xml"]:
        t = cu.rsplit("/", 1)[-1]
        for fn in cdn_files(main_content(rd(f"{RAW}/html/en/collection/{t}.html"))):
            media.setdefault(fn, {"filename": fn, "master_src": None, "width": None,
                                  "height": None, "refs": []})["refs"].append(f"collection:{t}")
    for fn in cdn_files(main_content(en_home)):
        media.setdefault(fn, {"filename": fn, "master_src": None, "width": None,
                              "height": None, "refs": []})["refs"].append("home")
    for bu in lists["sitemap_blogs_1.xml"]:
        bt = bu.rsplit("/", 1)[-1]
        for fn in cdn_files(main_content(rd(f"{RAW}/html/en/blog/{bt}.html"))):
            media.setdefault(fn, {"filename": fn, "master_src": None, "width": None,
                                  "height": None, "refs": []})["refs"].append(f"blog:{bt}")
    for au in lists["sitemap_articles_1.xml"]:
        at = au.rsplit("/", 1)[-1]
        bt = au.rsplit("/", 2)[-2]
        p = f"{RAW}/html/en/article/{bt}__{at}.html"
        for fn in cdn_files(main_content(rd(p))):
            media.setdefault(fn, {"filename": fn, "master_src": None, "width": None,
                                  "height": None, "refs": []})["refs"].append(f"article:{at}")

    with open(f"{OUT}/media-manifest.json", "w") as f:
        json.dump({"_meta": {"count": len(media)}, "files": sorted(media.values(), key=lambda x: x["filename"])},
                  f, ensure_ascii=False, indent=1)

    print(f"products={len(products)} spec_missing={spec_missing}")
    print(f"collections={len(collections)} pages={len(pages)} blogs={len(blogs)}")
    print(f"media files={len(media)}")
    # FAQ uniformity
    uniq = {}
    for h, s in faq_all:
        uniq.setdefault(s, []).append(h)
    print(f"distinct FAQ payloads: {len(uniq)}")
    for s, hs in list(uniq.items())[:5]:
        print("  ", len(hs), "products:", hs[0], "...", "empty" if s == "[]" else s[:80])


if __name__ == "__main__":
    main()
