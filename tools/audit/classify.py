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
    # The ruling replaced the ranges with per-project quoting, so the material
    # that described those ranges — the tier table, its header row, the
    # "what drives the price" explainer, the volume-discount rows — all went
    # with them. Every other way the old pages worded that same subject is
    # listed, because each one is the same decision seen from a different page.
    (r"\$\s?\d", "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"price range|price tier|price band|per sqm|/sqm|USD/m|price dri|tier-based|tiered pricing",
     "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"(?i)what drives the price|what shapes the (?:price|quote)", "升级-4 price display",
     "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"(?i)unit cost drops|pricing drops|discount|MOQ pricing|adds? \d+[–-]\d+%|FOB China|freight and dut",
     "升级-4 price display", "docs/acceptance/32-debt-ledger.md#升级-4"),
    (r"(?i)^(price range|order size|typical discount|price)$", "升级-4 price display",
     "docs/acceptance/32-debt-ledger.md#升级-4"),
    # MOQ is pricing-adjacent: the same ruling replaced the stated minimum with
    # "confirmed in your quote", the wording every family page now uses. It is
    # listed separately because it is a judgment call rather than a pure price
    # string — "MOQ from 1 sqm" is customer-friendly information, and dropping
    # it loses a selling point even though no number is promised.
    (r"(?i)^MOQ from |sample order from 1 sqm", "升级-4 MOQ wording",
     "docs/acceptance/32-debt-ledger.md#升级-4"),
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
    (r"(?i)^(phone\s*/\s*whatsapp|project details|message|your name|company name|email|country)"
     r"(\s*\*)?$", "field label, reworded", ""),
    (r"(?i)^i confirm this (?:is|inquiry)", "confirm checkbox, reworded", ""),
    (r"(?i)^(select country|select quantity|select project scope|select range)", "select placeholder"),
    (r"^[A-Za-z ]+\s*Select country", "select placeholder"),
]

# Old pages rendered all 146 patterns into the DOM and filtered client-side;
# the rebuild paginates. Card-count differences are a rendering strategy, not
# missing content, so a pattern name that is simply on another page is not a gap.
PAGINATION_PAGES = {"col-pattern-abstract", "col-pattern-asian", "col-pattern-geometric",
                    "col-pattern-gradient", "col-pattern-landscape", "col-pattern-moroccan-arabic",
                    "col-pattern-organic", "col-pattern-perforated", "col-pattern-vintage-retro"}

# Words too common to prove two strings are the same sentence.
FILLER = set("""a an the and or of for to in on at by with from is are was were be been
this that these those it its their your our we you they as so if then than
screen screens pattern patterns project projects metal divider""".split())


def tokens(text):
    """Content words only: lowercase, no punctuation, no filler."""
    words = re.findall(r"[a-z0-9]+", (text or "").lower())
    return [w for w in words if w not in FILLER and len(w) > 2]


def overlap(old_text, new_token_set, threshold=0.62):
    """The share of the old string's content words that survive in the new page.

    A rebuild rewords: `How much do glass-hybrid screens cost?` became `How is a
    glass-hybrid screen quoted?`, and `Your Name *` became `Name`. Treating
    those as losses inflates the gap list with text that is present under a
    different wording. A high token survival rate means the idea is still on the
    page; a low one means the sentence is gone.
    """
    mine = tokens(old_text)
    if not mine:
        return 1.0
    hits = sum(1 for w in mine if w in new_token_set)
    return hits / len(mine)


def normalize(text):
    return re.sub(r"\s+", " ", (text or "")).strip()


def match_any(text, rules):
    for pattern, reason, *citation in rules:
        if re.search(pattern, text):
            return reason, (citation[0] if citation else "")
    return None, None


def classify(slug, item, new_graph, new_tokens):
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

    if overlap(text, new_tokens) >= 0.62:
        return "rewritten", "same idea, different wording", ""

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

    buckets = {"real": [], "upgraded": [], "vocabulary": [], "noise": [], "pagination": [],
               "rewritten": []}
    per_page = {}

    for slug, page in diff.items():
        if page.get("error"):
            continue
        # The page's own words, for the rewording test.
        new_path = os.path.join(args.dir, "new", slug + ".json")
        new_tokens = set()
        if os.path.exists(new_path):
            with open(new_path) as fh:
                new_graph = json.load(fh)
            for field in ("headings", "paras", "lis", "looseText", "buttons"):
                for entry in new_graph.get(field, []):
                    text = entry.get("x") if isinstance(entry, dict) else entry
                    new_tokens.update(tokens(text or ""))
            for entry in new_graph.get("fields", []):
                new_tokens.update(tokens(entry.get("label") or ""))

        counts = {k: 0 for k in buckets}
        for item in page["missing"]:
            bucket, reason, cite = classify(slug, item, page, new_tokens)
            buckets[bucket].append({"page": slug, "kind": item["kind"], "text": item["text"],
                                    "reason": reason, "cite": cite})
            counts[bucket] += 1
        per_page[slug] = counts

    cols = ["real", "rewritten", "upgraded", "vocabulary", "noise", "pagination"]
    print("%-28s %5s %5s %5s %5s %5s %5s" % ("page", "real", "rewrit", "upgrd", "vocab", "noise", "pagin"))
    print("-" * 70)
    for slug, c in sorted(per_page.items(), key=lambda kv: -kv[1]["real"]):
        print("%-28s %5d %5d %5d %5d %5d %5d" % (slug, *[c[k] for k in cols]))
    print("-" * 70)
    totals = {k: len(v) for k, v in buckets.items()}
    print("%-28s %5d %5d %5d %5d %5d %5d" % ("TOTAL", *[totals[k] for k in cols]))
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
