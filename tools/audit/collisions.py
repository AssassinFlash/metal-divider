#!/usr/bin/env python3
"""Find control families whose attribute names collide inside one template.

The story accordion on the stainless page shares `data-sps-panel` with the tab
strip above it, one starting at 0 and one at 1. One generic binder then merges
both groups, decides visibility by string equality, and hides the panel that
belongs to the open row. The page renders an accordion that opens onto nothing.

This script catches that class of defect before it ships: for every attribute a
binder selects on, it records, per template, how many elements carry it, how
many distinct value bases there are, and whether the value sets of separate
regions overlap.

Usage:
    python3 tools/audit/collisions.py [--verbose]
"""
import argparse
import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TEMPLATES = os.path.join(ROOT, "plugins/md-site/content/livecanvas")
JS = os.path.join(ROOT, "plugins/md-site/assets/js/md-chrome.js")

ATTR = re.compile(r"data-[a-z0-9-]+")


def bound_attributes():
    """Every attribute a binder selects on, read from the real call sites."""
    with open(JS) as fh:
        src = fh.read()
    attrs = set()
    for match in re.finditer(r"querySelectorAll\(\s*'\[(data-[a-z0-9-]+)\]'", src):
        attrs.add(match.group(1))
    for match in re.finditer(r"'((?:data)-[a-z0-9-]+)'", src):
        attrs.add(match.group(1))
    return attrs


def occurrences(html, attr):
    """Every element carrying `attr`, with its value and the enclosing <section id>."""
    found = []
    for m in re.finditer(r"<([a-zA-Z][a-zA-Z0-9-]*)([^>]*?)" + re.escape(attr) + r"=\"([^\"]*)\"", html):
        tag = m.group(1).lower()
        value = m.group(3)
        prefix = html[: m.start()]
        sections = re.findall(r'<section[^>]*id="([^"]+)"', prefix)
        found.append({"tag": tag, "value": value, "section": sections[-1] if sections else ""})
    return found


def regions(found):
    """Group the occurrences into the blocks that must not share a namespace."""
    groups = {}
    for item in found:
        groups.setdefault(item["section"], []).append(item["value"])
    return groups


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--verbose", "-v", action="store_true")
    args = ap.parse_args()

    attrs = sorted(bound_attributes())
    templates = sorted(glob.glob(os.path.join(TEMPLATES, "*.html")))
    problems = 0

    print("%-26s %-26s %5s %s" % ("attribute", "template", "n", "regions"))
    print("-" * 96)
    for path in templates:
        name = os.path.basename(path)[:-5]
        with open(path) as fh:
            html = fh.read()
        for attr in attrs:
            found = occurrences(html, attr)
            if not found:
                continue
            groups = regions(found)
            nonempty = {k: v for k, v in groups.items() if k}
            if len(nonempty) < 2:
                if args.verbose:
                    print("%-26s %-26s %5d  one region" % (attr, name, len(found)))
                continue
            sets = {k: sorted(set(v), key=str) for k, v in nonempty.items()}
            keys = list(sets)
            clash = []
            for i in range(len(keys)):
                for j in range(i + 1, len(keys)):
                    shared = set(sets[keys[i]]) & set(sets[keys[j]])
                    if shared:
                        clash.append((keys[i], keys[j], sorted(shared)))
            flag = "CLASH" if clash else "ok"
            print("%-26s %-26s %5d  %s  %s" % (attr, name, len(found), flag,
                                               "; ".join("%s~%s shared %s" % c for c in clash) or ""))
            if clash:
                problems += 1

    print("-" * 96)
    print("%d attribute/template pairs share a value namespace across regions" % problems)
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
