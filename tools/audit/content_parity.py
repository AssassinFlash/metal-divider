#!/usr/bin/env python3
"""Compare body content volume between the frozen Shopify site and the WordPress rebuild.

The owner's complaint is "content is missing", not "pages are missing": every
commercial page has a live counterpart. This measures what a reader actually
gets per page — words, headings, images — by stripping chrome (script, style,
nav, header, footer) from both sides and counting the remainder.

Run:
    python3 tools/audit/content_parity.py --json /tmp/content-parity.json
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
import urllib.request
from dataclasses import dataclass, asdict
from html.parser import HTMLParser

OLD = "https://metalpartition.com"
NEW = "https://metal-divider.com"

# Old path -> new path. Compliance pages (gdpr/popia/pdpa/appi/...) are excluded
# by owner ruling: they are Shopify Consentmo residue and are replaced by one
# proper privacy policy.
PAGE_PAIRS: list[tuple[str, str]] = [
    ("/pages/contact", "/inquiry/"),
    ("/pages/faqs", "/faqs/"),
    ("/pages/laser-cut-geometric-metal-screens", "/laser-cut-geometric-metal-screens/"),
    ("/pages/glass-hybrid-screens", "/glass-hybrid-screens/"),
    ("/pages/outdoor-privacy-landscape-screens", "/outdoor-privacy-landscape-screens/"),
    ("/pages/chinese-style-artistic-screens", "/chinese-style-artistic-screens/"),
    ("/pages/stainless-steel-screens", "/stainless-steel-screens/"),
    ("/pages/manufacturing-process", "/manufacturing-process/"),
    ("/pages/laser-cut-panels", "/laser-cut-panels/"),
    ("/pages/perforated-panels", "/perforated-panels/"),
    ("/pages/expanded-metal-panels", "/expanded-metal-panels/"),
    ("/pages/project-gallery", "/project-gallery/"),
    ("/pages/about-us", "/about-us/"),
    ("/pages/metal-divider", "/custom-metal-divider-screens/"),
    ("/pages/custom-metal-divider-screens", "/custom-metal-divider-screens/"),
    ("/", "/"),
    ("/collections/pattern-abstract", "/collections/pattern-abstract/"),
    ("/collections/pattern-asian", "/collections/pattern-asian/"),
    ("/collections/pattern-geometric", "/collections/pattern-geometric/"),
    ("/collections/pattern-gradient", "/collections/pattern-gradient/"),
    ("/collections/pattern-landscape", "/collections/pattern-landscape/"),
    ("/collections/pattern-moroccan-arabic", "/collections/pattern-moroccan-arabic/"),
    ("/collections/pattern-organic", "/collections/pattern-organic/"),
    ("/collections/pattern-perforated", "/collections/pattern-perforated/"),
    ("/collections/pattern-vintage-retro", "/collections/pattern-vintage-retro/"),
]

STRIP_TAGS = {"script", "style", "noscript", "svg", "template"}
CHROME_TAGS = {"header", "footer", "nav"}
BLOCK_TAGS = {
    "p", "div", "section", "article", "li", "h1", "h2", "h3", "h4", "h5", "h6",
    "br", "tr", "td", "th", "blockquote", "figcaption", "dt", "dd",
}
HEADINGS = {"h1", "h2", "h3", "h4", "h5", "h6"}


@dataclass
class Metrics:
    url: str
    status: int
    words: int
    chars: int
    links: int
    images: int
    h1: int
    h2: int
    h3: int
    sections: int
    headings: list[str]
    error: str = ""


class BodyText(HTMLParser):
    """Collect visible body text plus structural counts, skipping page chrome."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.skip_depth = 0
        self.chrome_depth = 0
        self.parts: list[str] = []
        self.links = 0
        self.images = 0
        self.sections = 0
        self.headings: list[tuple[str, str]] = []
        self._heading: str | None = None
        self._heading_text: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if self.skip_depth or self.chrome_depth:
            if tag in STRIP_TAGS:
                self.skip_depth += 1
            elif tag in CHROME_TAGS:
                self.chrome_depth += 1
            elif tag == "img" and not self.skip_depth:
                self.images += 1
            return
        if tag in STRIP_TAGS:
            self.skip_depth += 1
            return
        if tag in CHROME_TAGS:
            self.chrome_depth += 1
            return
        if tag == "img":
            self.images += 1
        elif tag == "a":
            self.links += 1
        elif tag == "section":
            self.sections += 1
        elif tag in HEADINGS:
            self._heading = tag
            self._heading_text = []
        if tag in BLOCK_TAGS:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        if tag in STRIP_TAGS and self.skip_depth:
            self.skip_depth -= 1
            return
        if tag in CHROME_TAGS and self.chrome_depth:
            self.chrome_depth -= 1
            return
        if self.skip_depth or self.chrome_depth:
            return
        if tag in HEADINGS and self._heading == tag:
            text = " ".join("".join(self._heading_text).split())
            if text:
                self.headings.append((tag, text))
            self._heading = None
            self._heading_text = []
        if tag in BLOCK_TAGS:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if self.skip_depth or self.chrome_depth:
            return
        self.parts.append(data)
        if self._heading:
            self._heading_text.append(data)

    def text(self) -> str:
        raw = "".join(self.parts)
        raw = html.unescape(raw)
        lines = [" ".join(line.split()) for line in raw.splitlines()]
        return "\n".join(line for line in lines if line)


def fetch(url: str, *, bust_cache: bool) -> tuple[int, str]:
    sep = "&" if "?" in url else "?"
    target = f"{url}{sep}mdaudit=parity" if bust_cache else url
    req = urllib.request.Request(
        target,
        headers={
            "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
            "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml",
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
        },
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        body = resp.read()
        charset = resp.headers.get_content_charset() or "utf-8"
        return resp.status, body.decode(charset, errors="replace")


def measure(url: str, *, bust_cache: bool) -> Metrics:
    blank = Metrics(url=url, status=0, words=0, chars=0, links=0, images=0,
                    h1=0, h2=0, h3=0, sections=0, headings=[])
    try:
        status, markup = fetch(url, bust_cache=bust_cache)
    except Exception as exc:  # noqa: BLE001 - report and continue the sweep
        blank.error = f"{type(exc).__name__}: {exc}"
        return blank

    parser = BodyText()
    parser.feed(markup)
    text = parser.text()
    words = len(re.findall(r"[A-Za-z0-9][A-Za-z0-9'’-]*", text))
    counts = {tag: 0 for tag in ("h1", "h2", "h3")}
    for tag, _ in parser.headings:
        if tag in counts:
            counts[tag] += 1
    return Metrics(
        url=url,
        status=status,
        words=words,
        chars=len(text),
        links=parser.links,
        images=parser.images,
        h1=counts["h1"],
        h2=counts["h2"],
        h3=counts["h3"],
        sections=parser.sections,
        headings=[f"{t}: {v}" for t, v in parser.headings],
    )


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", help="write the full comparison here")
    ap.add_argument("--only", help="substring filter on the page path")
    ap.add_argument("--show-headings", action="store_true",
                    help="print the new site's heading outline for each page")
    args = ap.parse_args()

    rows: list[dict] = []
    for old_path, new_path in PAGE_PAIRS:
        if args.only and args.only not in old_path and args.only not in new_path:
            continue
        old = measure(OLD + old_path, bust_cache=False)
        new = measure(NEW + new_path, bust_cache=True)
        ratio = (new.words / old.words) if old.words else 0.0
        rows.append({"old_path": old_path, "new_path": new_path,
                     "old": asdict(old), "new": asdict(new),
                     "word_ratio": round(ratio, 3)})
        flag = "SHORT" if ratio < 0.75 else ("long" if ratio > 1.25 else "ok")
        print(f"{flag:5} {old.words:6d} -> {new.words:6d} ({ratio:5.0%})  "
              f"h2 {old.h2:2d}->{new.h2:2d}  img {old.images:3d}->{new.images:3d}  {new_path}")
        if args.show_headings:
            for heading in new.headings:
                print(f"         {heading}")
        if new.error:
            print(f"         ERROR {new.error}")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(rows, handle, indent=2, ensure_ascii=False)
        print(f"\nwrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
