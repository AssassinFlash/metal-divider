#!/usr/bin/env python3
"""Diff an old-site content graph against its rebuilt counterpart.

Reads the JSON that tools/audit/capture.py wrote and reports, per page, which
headings / paragraphs / list items / table rows / button labels / form fields
exist on the old site but not the new one, and which images have no counterpart.

The comparison is deliberately fuzzy about whitespace and case, and exact about
wording: a rebuild may re-case a heading, but a dropped sentence is a gap.

Usage:
    python3 tools/audit/diff.py [--dir /tmp/md-audit] [--json OUT]
"""
import argparse
import json
import os
import re
import sys

PUNCT = re.compile(r"[^\w\s]")
SPACE = re.compile(r"\s+")


def key(value):
    """Reduce a string to the words that carry meaning: no punctuation, no
    case, no collapsed whitespace, no Shopify theme furniture."""
    s = (value or "").replace("\u2019", "'").replace("\u2014", "-").replace("\u2013", "-")
    s = PUNCT.sub(" ", s.lower())
    return SPACE.sub(" ", s).strip()


STOP = {
    "add to cart", "sold out", "shop now", "view all", "learn more", "read more",
    "menu", "close", "search", "skip to content", "select options",
}


def usable(text, minimum=14):
    k = key(text)
    if len(k) < minimum:
        return False
    if k in STOP:
        return False
    return True


def collect(graph):
    """Every meaningful string the page shows, tagged with where it lives."""
    items = []
    for h in graph.get("headings", []):
        if usable(h["x"]):
            items.append(("heading", h["t"], h["x"]))
    for p in graph.get("paras", []):
        if usable(p):
            items.append(("para", "", p))
    for li in graph.get("lis", []):
        if usable(li):
            items.append(("li", "", li))
    for t in graph.get("looseText", []):
        if usable(t):
            items.append(("text", "", t))
    for tb in graph.get("tables", []):
        for cell in tb.get("head", []):
            if usable(cell, 4):
                items.append(("th", "", cell))
    for b in graph.get("buttons", []):
        if usable(b, 4):
            items.append(("button", "", b))
    for f in graph.get("fields", []):
        if usable(f.get("label", ""), 3):
            items.append(("field", "", f["label"]))
    return items


def image_stems(graph):
    stems = set()
    for img in graph.get("imgs", []):
        src = img.get("src") or ""
        base = src.rsplit("/", 1)[-1]
        base = re.sub(r"[-_]\d+w$", "", base)
        base = re.sub(r"\.(webp|jpe?g|png|avif|gif)$", "", base, flags=re.I)
        if base:
            stems.add(base)
    return stems


def compare(old, new):
    new_keys = set()
    for kind, _, text in collect(new):
        new_keys.add(key(text))
    # A rebuilt page often splits one old paragraph into several, or joins
    # several into one. Treat an old line as covered when its words appear as a
    # contiguous run inside any new line.
    new_blob = " " + " ".join(sorted(new_keys)) + " "

    missing = []
    for kind, tag, text in collect(old):
        k = key(text)
        if not k:
            continue
        if k in new_keys:
            continue
        if k in new_blob:
            continue
        missing.append({"kind": kind, "tag": tag, "text": text, "key": k})

    old_imgs = image_stems(old)
    new_imgs = image_stems(new)
    # Compare on a loose stem so a renamed file whose name still names the
    # pattern counts as present.
    missing_imgs = sorted(s for s in old_imgs if s not in new_imgs and not any(s in n or n in s for n in new_imgs))

    return {
        "old_counts": old.get("counts", {}),
        "new_counts": new.get("counts", {}),
        "old_doc_h": old.get("doc", {}).get("h"),
        "new_doc_h": new.get("doc", {}).get("h"),
        "missing": missing,
        "missing_images": missing_imgs,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", default="/tmp/md-audit")
    ap.add_argument("--json", default=None)
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    old_dir = os.path.join(args.dir, "old")
    new_dir = os.path.join(args.dir, "new")
    if not os.path.isdir(old_dir) or not os.path.isdir(new_dir):
        print("need both %s and %s" % (old_dir, new_dir))
        return 2

    report = {}
    total_missing = 0
    for name in sorted(os.listdir(old_dir)):
        if not name.endswith(".json"):
            continue
        slug = name[:-5]
        new_path = os.path.join(new_dir, name)
        if not os.path.exists(new_path):
            report[slug] = {"error": "not captured on the new site"}
            continue
        with open(os.path.join(old_dir, name)) as fh:
            old = json.load(fh)
        with open(new_path) as fh:
            new = json.load(fh)
        result = compare(old, new)
        report[slug] = result
        total_missing += len(result["missing"])

    print("%-24s %6s %6s %7s %7s" % ("page", "oldH", "newH", "missing", "imgGap"))
    print("-" * 58)
    for slug, r in report.items():
        if r.get("error"):
            print("%-24s %s" % (slug, r["error"]))
            continue
        print("%-24s %6d %6d %7d %7d" % (
            slug,
            r["old_counts"].get("headings", 0),
            r["new_counts"].get("headings", 0),
            len(r["missing"]),
            len(r["missing_images"]),
        ))
    print("-" * 58)
    print("total missing strings: %d" % total_missing)

    if args.verbose:
        for slug, r in report.items():
            if r.get("error") or not r["missing"]:
                continue
            print("\n### %s — %d missing" % (slug, len(r["missing"])))
            for m in r["missing"][:60]:
                print("  [%s] %s" % (m["kind"], m["text"][:150]))
            if r["missing_images"]:
                print("  images: %s" % ", ".join(r["missing_images"][:20]))

    if args.json:
        with open(args.json, "w") as fh:
            json.dump(report, fh, indent=1)
        print("\nwrote %s" % args.json)
    return 0


if __name__ == "__main__":
    sys.exit(main())
