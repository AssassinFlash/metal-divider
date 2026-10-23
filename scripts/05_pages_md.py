#!/usr/bin/env python3
"""Extract main-content text of every EN page into one Markdown file for the repo.

Output: data/shopify-public/pages-content.md
"""
import json
import os
import re

RAW = "/tmp/md-r-work/raw"
OUT = "/tmp/md-r-content-export/data/shopify-public"


def text_of(html: str) -> str:
    t = re.sub(r"<script[^>]*>.*?</script>", "", html, flags=re.S)
    t = re.sub(r"<style[^>]*>.*?</style>", "", t, flags=re.S)
    t = re.sub(r"<[^>]+>", "\n", t)
    t = re.sub(r"\n{2,}", "\n", t)
    return "\n".join(ln.strip() for ln in t.splitlines() if ln.strip())


def main() -> None:
    lists = json.load(open(f"{RAW}/url_lists.json"))
    parts = ["# metalpartition.com 页面正文提取(EN main 内容)", "",
             "- 来源:2026-10-24 渲染 HTML 的 `<main>` 区块,去脚本样式后的纯文本行。",
             "- 用途:WordPress 重建的内容底稿;结构化字段见 pages.json。", ""]
    order = list(lists["sitemap_pages_1.xml"]) + ["https://metalpartition.com/"]
    for pu in order:
        t = pu.rsplit("/", 1)[-1] or "home"
        path = f"{RAW}/html/en/home.html" if pu.endswith("/") else f"{RAW}/html/en/page/{t}.html"
        h = open(path, encoding="utf-8", errors="replace").read()
        m = re.search(r"<main\b.*?>(.*)</main>", h, re.S)
        mc = m.group(1) if m else h
        parts.append(f"\n---\n\n<!-- {pu} -->\n\n# PAGE: {t}\n")
        parts.append(text_of(mc))
    with open(f"{OUT}/pages-content.md", "w") as f:
        f.write("\n".join(parts))
    print("wrote pages-content.md", os.path.getsize(f"{OUT}/pages-content.md"), "bytes")


if __name__ == "__main__":
    main()
