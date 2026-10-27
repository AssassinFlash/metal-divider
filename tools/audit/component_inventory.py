#!/usr/bin/env python3
"""Inventory the components a page renders, old site against new.

Heading comparison misses everything without an h2: galleries, spec tables,
FAQ accordions, stat bands, logo strips, video blocks. Those are exactly the
blocks a visitor notices, and exactly what "内容少了很多" would mean if a
port dropped them.

So this counts structures instead of words: how many tables, list groups,
disclosure widgets, figures, embedded videos and buttons each page has. A
delta is a candidate gap, not proof of one — the old theme wrapped things in
extra divs, so the numbers are compared as a ratio and the raw counts are
printed for a human to judge.

Run:
    python3 tools/audit/component_inventory.py
    python3 tools/audit/component_inventory.py --json /tmp/components.json
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.request
from collections import Counter
from dataclasses import dataclass, asdict

OLD = "https://metalpartition.com"
NEW = "https://metal-divider.com"

UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36"
)

PAGE_PAIRS = [
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
    ("/", "/"),
]

# Structures whose absence a reader would feel. `div` is deliberately absent:
# both themes nest it differently and the count says nothing.
STRUCTURES = [
    "section", "article", "table", "thead", "details", "summary",
    "figure", "figcaption", "blockquote", "picture", "img", "video",
    "iframe", "ul", "ol", "li", "form", "input", "select", "textarea",
    "button", "a", "svg", "dl", "dt", "dd", "hr",
]

STRIP = re.compile(r"(?is)<(script|style|noscript|template)\b[^>]*>.*?</\1>")
# Both storefronts share the same chrome markup (`<nav class="xo-header-nav
# desktop-only">`), so the same strip applies to both. Stripping <header>
# instead was asymmetric: the old theme has no <header> wrapper, so its whole
# mega-menu survived and every link count read 3x the new site's.
CHROME = re.compile(r"(?is)<(footer|nav)\b[^>]*>.*?</\1>")


def fetch(url: str, bust: bool) -> tuple[int, str]:
    sep = "&" if "?" in url else "?"
    target = f"{url}{sep}mdaudit=inv" if bust else url
    req = urllib.request.Request(
        target,
        headers={"User-Agent": UA, "Cache-Control": "no-cache", "Pragma": "no-cache"},
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.status, resp.read().decode(
            resp.headers.get_content_charset() or "utf-8", errors="replace"
        )


@dataclass
class Inventory:
    url: str
    status: int
    counts: dict[str, int]
    cta_labels: list[str]
    alts: list[str]
    error: str = ""


def inventory(url: str, bust: bool) -> Inventory:
    try:
        status, markup = fetch(url, bust)
    except Exception as exc:  # noqa: BLE001 - keep the sweep going
        return Inventory(url=url, status=0, counts={}, cta_labels=[], alts=[],
                         error=f"{type(exc).__name__}: {exc}")

    body = STRIP.sub("", markup)
    body = CHROME.sub("", body)

    counts = {}
    for tag in STRUCTURES:
        counts[tag] = len(re.findall(rf"(?i)<{tag}\b", body))

    cta_labels = []
    for match in re.finditer(r"(?is)<(button|a)\b[^>]*>(.*?)</\1>", body):
        text = re.sub(r"<[^>]+>", " ", match.group(2))
        text = " ".join(text.split())
        if 2 <= len(text) <= 48:
            cta_labels.append(text)

    alts = [
        " ".join(m.group(1).split())
        for m in re.finditer(r'(?is)<img\b[^>]*\balt\s*=\s*["\']([^"\']*)["\']', body)
        if m.group(1).strip()
    ]

    return Inventory(url=url, status=status, counts=counts,
                     cta_labels=cta_labels, alts=alts)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json")
    ap.add_argument("--only")
    args = ap.parse_args()

    rows = []
    for old_path, new_path in PAGE_PAIRS:
        if args.only and args.only not in old_path and args.only not in new_path:
            continue
        old = inventory(OLD + old_path, bust=False)
        new = inventory(NEW + new_path, bust=True)
        if old.error or new.error:
            print(f"SKIP {new_path}  old={old.error}  new={new.error}")
            continue

        # Which component kinds does the old page have that the new one lacks
        # entirely? A drop to zero is the unambiguous case; a smaller number is
        # reported as a ratio for a human to judge, because the two themes do
        # not nest the same way.
        vanished = [k for k in STRUCTURES
                    if old.counts.get(k, 0) >= 3 and new.counts.get(k, 0) == 0]
        dropped = [
            (k, old.counts[k], new.counts[k])
            for k in STRUCTURES
            if old.counts.get(k, 0) >= 5
            and new.counts.get(k, 0) > 0
            and new.counts[k] < old.counts[k] * 0.5
        ]
        old_cta = Counter(old.cta_labels)
        new_cta = Counter(new.cta_labels)
        lost_cta = [label for label in old_cta if old_cta[label] >= 2 and label not in new_cta]

        rows.append({
            "old_path": old_path, "new_path": new_path,
            "old": asdict(old), "new": asdict(new),
            "vanished": vanished, "dropped": dropped, "lost_cta": lost_cta[:12],
        })

        flags = []
        if vanished:
            flags.append("VANISHED " + ",".join(vanished))
        if dropped:
            flags.append("DROPPED " + ",".join(f"{k} {o}->{n}" for k, o, n in dropped))
        print(f"{new_path:44} img {old.counts['img']:3d}->{new.counts['img']:3d}"
              f"  li {old.counts['li']:3d}->{new.counts['li']:3d}"
              f"  table {old.counts['table']:2d}->{new.counts['table']:2d}"
              f"  details {old.counts['details']:2d}->{new.counts['details']:2d}"
              f"  {'; '.join(flags) if flags else 'ok'}")
        if lost_cta:
            print(f"      CTA only on old: {', '.join(lost_cta[:6])}")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(rows, handle, indent=2, ensure_ascii=False)
        print(f"\nwrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
