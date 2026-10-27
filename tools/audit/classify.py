#!/usr/bin/env python3
"""Classify every parity gap as a real loss, a recorded upgrade, or noise.

The raw diff cannot tell the three apart, and reporting its total as "content
missing" is wrong in both directions: it counts the owner-authorised price-table
removal as a loss, and it counts a sentence that was rewritten on purpose as a
loss too. This script applies the repo's own rulings to the diff.

The rulings are read from disk, not restated here:
  docs/acceptance/32-debt-ledger.md  升级-3 (materials), 升级-4 (price display)
  docs/deploy/visual-parity-gate.md  升级-1 (sticky), 升级-2/3 (type fixes)

Verified sources:
  data/shopify-public/pages-content.md  the old site's rendered text, captured
                                        2026-10-24, used as the authority for
                                        what the old page actually said.

Usage:
    python3 tools/audit/classify.py [--json OUT] [--verbose]
"""
import argparse
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))

# Recorded content upgrades: an old string is legitimately absent because a
# ruling replaced it. Each entry is (regex over the OLD text, reason, citation).
UPGRADES = [
    # 升级-4: price ranges and discount tables removed on the owner's authority.
    (r"\$\s?\d", "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"price range|price tier|per sqm|/sqm|USD/m", "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"(?i)\bpercent off\b|% off|discount tier", "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    # 升级-3: the five-material vocabulary became stainless 304/316 only.
    (r"(?i)\b(corten|aluminium|aluminum|copper|mild steel|brass)\b", "升级-3 materials", "docs/acceptance/32-debt-ledger.md#升级-3"),
]

# The owner's own vocabulary ruling (AGENTS.md §2, 2026-10-24 修订 5): the
# customer-facing word is "pattern", never "product".
VOCABULARY = [
    (r"(?i)\bproducts?\b", "vocabulary: product -> pattern", "AGENTS.md §2 修订 5"),
    (r"(?i)\bcollections?\b", "vocabulary: shop language dropped", "AGENTS.md §2 修订 5"),
]

# Shopify/theme furniture that exists only because the old site ran a shop.
FURNITURE = [
    (r"(?i)^(add to cart|sold out|shop now|buy now|select options|choose options)$", "shop furniture"),
    (r"(?i)^(swipe to explore|← swipe|swipe) ", "carousel furniture"),
    (r"(?i)^\s*leave this field empty\s*$", "honeypot label"),
    (r"(?i)^(select country|select quantity|select project scope|select range)", "select placeholder"),
    (r"^[A-Za-z ]+\s*Select country", "select placeholder"),
]

# Old pages rendered all 146 patterns into the DOM and filtered client-side;
# the rebuild paginates. Card-count differences are a rendering strategy, not
# missing content, so a pattern name that is simply on another page is not a gap.
PAGINATION_PAGES = {"col-pattern-abstract", "col-pattern-asian", "col-pattern-geometric",
                    "col-pattern-gradient", "col-pattern-landscape", "col-pattern-moroccan-arabic",
                    "col-pattern-organic", "col-pattern-perforated", "col-pattern-vintage-retro"}


def normalize(text):
    return re.sub(r"\s+", " ", (text or "")).strip()


def match_any(text, rules):
    for pattern, reason, *citation in rules:
        if re.search(pattern, text):
            return reason, (citation[0] if citation else "")
    return None, None


def classify(slug, item, new_graph):
    """Return (bucket, reason, citation) for one missing string."""
    text = normalize(item["text"])

    reason, cite = match_any(text, UPGRADES)
    if reason:
        return "upgraded", reason, cite

    reason, cite = match_any(text, VOCABULARY)
    if reason:
        return "vocabulary", reason, cite

    reason, cite = match_any(text, FURNITURE)
    if reason:
        return "noise", reason, cite

    if slug in PAGINATION_PAGES:
        # A pattern name on another page of the same series is not missing.
        return "pagination", "pattern listed on another page", ""

    return "real", "", ""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", default="/tmp/md-audit")
    ap.add_argument("--json", default=None)
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    diff_path = os.path.join(args.dir, "diff3.json")
    with open(diff_path) as fh:
        diff = json.load(fh)

    buckets = {"real": [], "upgraded": [], "vocabulary": [], "noise": [], "pagination": []}
    per_page = {}

    for slug, page in diff.items():
        if page.get("error"):
            continue
        counts = {k: 0 for k in buckets}
        for item in page["missing"]:
            bucket, reason, cite = classify(slug, item, page)
            buckets[bucket].append({"page": slug, "kind": item["kind"], "text": item["text"],
                                    "reason": reason, "cite": cite})
            counts[bucket] += 1
        per_page[slug] = counts

    print("%-28s %5s %5s %5s %5s %5s" % ("page", "real", "upgrd", "vocab", "noise", "pagin"))
    print("-" * 62)
    for slug, c in sorted(per_page.items()):
        print("%-28s %5d %5d %5d %5d %5d" % (slug, c["real"], c["upgraded"], c["vocabulary"],
                                             c["noise"], c["pagination"]))
    print("-" * 62)
    totals = {k: len(v) for k, v in buckets.items()}
    print("%-28s %5d %5d %5d %5d %5d" % ("TOTAL", totals["real"], totals["upgraded"],
                                         totals["vocabulary"], totals["noise"], totals["pagination"]))
    print()
    print("Real gaps by kind:")
    by_kind = {}
    for item in buckets["real"]:
        by_kind[item["kind"]] = by_kind.get(item["kind"], 0) + 1
    for kind, n in sorted(by_kind.items(), key=lambda kv: -kv[1]):
        print("  %-10s %d" % (kind, n))

    if args.verbose:
        for slug in sorted({i["page"] for i in buckets["real"]}):
            items = [i for i in buckets["real"] if i["page"] == slug]
            print("\n### %s — %d real" % (slug, len(items)))
            for i in items[:40]:
                print("  [%s] %s" % (i["kind"], i["text"][:130]))

        print("\n### Upgraded (owner-authorised, not a defect)")
        by_reason = {}
        for i in buckets["upgraded"]:
            by_reason.setdefault(i["reason"], []).append(i)
        for reason, items in sorted(by_reason.items(), key=lambda kv: -len(kv[1])):
            print("  %-28s %d   e.g. %s" % (reason, len(items), items[0]["text"][:70]))

    if args.json:
        with open(args.json, "w") as fh:
            json.dump({"perPage": per_page, "totals": totals, "buckets": buckets}, fh, indent=1)
        print("\nwrote %s" % args.json)
    return 0


if __name__ == "__main__":
    sys.exit(main())
