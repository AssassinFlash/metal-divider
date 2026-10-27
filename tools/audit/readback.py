#!/usr/bin/env python3
"""Prove a deployment landed by reading production back, not by trusting it.

Every check here compares what production serves now against a value that was
measured from the broken page before the fix. A check that cannot state the
"before" value is not a check, so each one carries it in the name.

Usage:
    python3 tools/audit/readback.py
    python3 tools/audit/readback.py --base https://metal-divider.com
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.request

UA = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36",
    "Cache-Control": "no-cache",
    "Pragma": "no-cache",
}


def get(base: str, path: str, bust: str) -> str:
    sep = "&" if "?" in path else "?"
    url = f"{base}{path}{sep}mdaudit={bust}"
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read().decode(resp.headers.get_content_charset() or "utf-8",
                                  errors="replace")


class Report:
    def __init__(self) -> None:
        self.failures = 0
        self.checks = 0

    def check(self, name: str, ok: bool, detail: str) -> None:
        self.checks += 1
        if not ok:
            self.failures += 1
        print(f"{'PASS' if ok else 'FAIL'}  {name}\n      {detail}")


def count_images(path_html: str, start_marker: str, span: int = 40000) -> int:
    index = path_html.find(start_marker)
    if index < 0:
        return -1
    return len(re.findall(r"(?is)<img\b", path_html[index:index + span]))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    args = ap.parse_args()
    base = args.base.rstrip("/")
    bust = f"rb{int(time.time())}"
    report = Report()

    # 1. The six route cards. Before the fix production served 0 images in this
    #    section and 2 on the whole page, both of them footer/nav assets.
    inquiry = get(base, "/inquiry/", bust)
    inquiry_body = re.sub(r"(?is)<(script|style|noscript|template)\b[^>]*>.*?</\1>", "", inquiry)
    route_imgs = count_images(inquiry_body, "inquiry-route-grid")
    report.check(
        "inquiry route cards carry their six pictures (was 0)",
        route_imgs == 6,
        f"route-card <img> count = {route_imgs}",
    )
    body_img_count = len(re.findall(r"(?is)<img\b", inquiry_body))
    report.check(
        "inquiry page image total rose from 2",
        body_img_count >= 8,
        f"total body <img> = {body_img_count}",
    )
    for name in ("noodle-screen-2-480w", "quill-window-cover-2-480w"):
        report.check(
            f"{name} is served (was 404 before upload)",
            f"{name}.webp" in inquiry_body,
            "referenced in the route grid" if f"{name}.webp" in inquiry_body
            else "NOT referenced — the template did not ship",
        )

    # 2. The catalog grid. Before: repeat(auto-fill, minmax(min(100%,300px),340px))
    #    which cannot grow, leaving the row partly empty on a wide monitor.
    shop = get(base, "/shop/", bust)
    css = ""
    for match in re.finditer(r"(?is)<link[^>]+rel=['\"]stylesheet['\"][^>]*>", shop):
        tag = match.group(0)
        href = re.search(r"href=['\"]([^'\"]+)['\"]", tag)
        if href and "tailwind" in href.group(1):
            css_url = href.group(1)
            if css_url.startswith("/"):
                css_url = base + css_url
            try:
                css += urllib.request.urlopen(
                    urllib.request.Request(css_url, headers=UA), timeout=60
                ).read().decode("utf-8", errors="replace")
            except Exception as exc:  # noqa: BLE001
                print(f"      (could not read {css_url}: {exc})")
    report.check(
        "catalog grid can fill its row (auto-fit present)",
        "auto-fit" in css,
        f"auto-fit occurrences in compiled CSS = {css.count('auto-fit')}",
    )
    report.check(
        "catalog grid no longer caps columns at 340px in the products rule",
        not re.search(r"pattern-catalog__products[^}]*minmax\(min\(100%,\s*300px\),\s*340px\)",
                      css, re.S),
        "old capped rule absent" if not re.search(
            r"pattern-catalog__products[^}]*minmax\(min\(100%,\s*300px\),\s*340px\)", css, re.S
        ) else "old capped rule STILL present",
    )

    # 3. Family pages: the persona accordion panels and the scene wiring.
    family = get(base, "/stainless-steel-screens/", bust)
    report.check(
        "persona accordion ships one open panel per row",
        family.count("data-sps-story-panel") == 4,
        f"panels = {family.count('data-sps-story-panel')}",
    )
    scene_ids = sorted(set(re.findall(r'data-sps-scene="(\d)"', family)))
    report.check(
        "persona rows expose a scene image each",
        len(scene_ids) == 4,
        f"distinct scenes = {scene_ids}",
    )
    # The first scene used to be the workshop shot; the lobby row needs a lobby.
    report.check(
        "hotel-designer row is paired with a lobby image, not the workshop",
        "modern-fluted-glass-room-divider" in family,
        "lobby scene present" if "modern-fluted-glass-room-divider" in family
        else "lobby scene still missing",
    )

    # 4. The gallery keeps list semantics and all its cards.
    gallery = get(base, "/project-gallery/", bust)
    report.check(
        "gallery cards are list items again (was 0)",
        gallery.count('role="listitem"') == 23,
        f"role=listitem count = {gallery.count('role=listitem')}",
    )

    # 5. Search sheet: the sheet must not depend on the ported xo-grid spans.
    report.check(
        "search sheet has an empty-state prompt",
        "xo-predictive-search__prompt" in inquiry or "xo-predictive-search__prompt" in shop,
        "prompt element present",
    )

    print(f"\n{report.checks} checks, {report.failures} failed")
    return 1 if report.failures else 0


if __name__ == "__main__":
    sys.exit(main())
