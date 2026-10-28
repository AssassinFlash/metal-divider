#!/usr/bin/env python3
"""Read the markers this round removed back from the live stylesheet.

Written because a fix can be present in the repository, pass every unit test,
and still not be on the site: `present` re-renders templates and WindPress
recompiles the stylesheet, and either step can be skipped. These are the exact
declarations that were removed, so finding one means the change did not land.

Each marker names a **selector**, not a bare property. The first version of
this file searched for `object-fit: contain` and `gap: 14px` and reported four
failures on a stylesheet where those values legitimately appear on the blog
cover images and the header rail. A bare value is a proxy for the defect; the
selector is the defect.

    python3 tools/audit/marker_readback.py
"""

from __future__ import annotations

import re
import sys
import time
import urllib.request

BASE = "https://metal-divider.com"
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36"

# (label, selector-scoped pattern, must_be_absent, what it meant when present)
MARKERS: list[tuple[str, str, bool, str]] = [
    (
        "40em flatten",
        r"max-width:\s*40em",
        True,
        "replaced every template cap instead of deferring to the narrower one",
    ),
    (
        "search 100dvh",
        r'xo-modal\[xo-name=["\']?search["\']?\][^{]*\{[^}]*min-height:\s*100dvh',
        True,
        "search panel held a full viewport of background around one input",
    ),
    (
        "dropdown contain",
        r"mmv2__img img[^{]*\{[^}]*object-fit:\s*contain",
        True,
        "square menu images shrank into a grey box",
    ),
    (
        "catalog 280px",
        r"grid-template-columns:\s*280px\s+minmax",
        True,
        "sidebar left the pattern column at 72.8% of a 1440 viewport",
    ),
    (
        "card gap 14px",
        r"pattern-card__body[^{]*\{[^}]*gap:\s*14px",
        True,
        "catalog card internals packed tighter than the 16px rhythm",
    ),
    (
        "uncapped measure",
        r'main p:not\(\[class\*=["\']?max-w-["\']?\]\)\s*\{[^{}]*max-width:\s*\d+px',
        False,
        "the replacement cap for copy that carries no width of its own",
    ),
    (
        "cover, not contain",
        r"mmv2__img img[^{]*\{[^}]*object-fit:\s*cover",
        False,
        "menu images now fill their card",
    ),
    (
        "card gap 16px",
        r"pattern-card__body[^{]*\{[^}]*gap:\s*16px",
        False,
        "catalog card internals back on the 16px rhythm",
    ),
]


def get(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read().decode("utf-8", "replace")


def strip_comments(css: str) -> str:
    """Remove CSS comments before matching.

    The fixed volumes explain in comments which rule was removed and why, so a
    bare `max-width: 40em` search finds the explanation and reports the defect
    as still present. Matching the stylesheet means matching what it applies.
    """
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)


def self_check() -> int:
    """Prove every marker tells the before and after states apart.

    Run with `--self-check` and a saved copy of the pre-fix stylesheet. A
    marker that fires in both states measures nothing, and this is how the
    first draft of this file went wrong: `object-fit: contain` and `gap: 14px`
    are legitimate elsewhere, so they reported defects that were already fixed.
    """
    before_path = sys.argv[2]
    before = strip_comments(open(before_path, encoding="utf-8").read())
    after = strip_comments(
        "".join(
            open(f"plugins/md-site/content/windpress/{name}", encoding="utf-8").read()
            for name in ("chrome.css", "pattern-grid.css", "base.css")
        )
    )
    bad = 0
    print(f"{'marker':<20}{'want':>9}{'before':>8}{'after':>7}  verdict")
    for label, pattern, must_be_absent, _ in MARKERS:
        b = len(re.findall(pattern, before))
        a = len(re.findall(pattern, after))
        ok_before = (b == 0) if must_be_absent else (b > 0)
        ok_after = (a == 0) if must_be_absent else (a > 0)
        discriminates = (not ok_before) and ok_after
        if not discriminates:
            bad += 1
        want = "absent" if must_be_absent else "present"
        print(
            f"{label:<20}{want:>9}{b:>8}{a:>7}  "
            f"{'discriminates' if discriminates else 'NO DISCRIMINATION'}"
        )
    print()
    print("every marker tells the two states apart" if bad == 0 else f"{bad} marker(s) measure nothing")
    return 0 if bad == 0 else 1


def main() -> int:
    if "--self-check" in sys.argv:
        return self_check()

    stamp = int(time.time() * 1000)
    home = get(f"{BASE}/?mdaudit={stamp}")
    match = re.search(
        r"https://metal-divider\.com/wp-content/uploads/windpress/cache/tailwind\.css\?ver=\d+",
        home,
    )
    if not match:
        print("FAIL: the home page links no compiled stylesheet")
        return 1
    css_url = match.group(0)
    css = strip_comments(get(css_url))
    print(f"stylesheet: {css_url}\n            {len(css):,} bytes (comments stripped)\n")

    failures = 0
    for label, pattern, must_be_absent, meaning in MARKERS:
        found = len(re.findall(pattern, css))
        ok = found == 0 if must_be_absent else found > 0
        want = "absent" if must_be_absent else "present"
        print(f"{'ok  ' if ok else 'FAIL'} {label:<18} found={found:<3} want {want:<8} {meaning}")
        if not ok:
            failures += 1

    # The number a reader feels: the measure actually applied to copy that has
    # no cap of its own. Stated as a fraction of a 1440 viewport so it can be
    # compared against the 44% the `!important` rule produced at 16px.
    #
    # Read by locating the rule that carries the selector, not by matching a
    # whole declaration: the old stylesheet merged three selectors into one
    # comma list, so a pattern anchored on `)` followed by `{` could only ever
    # match the fixed form, and reported "not found" while the marker table
    # above reported the replacement present.
    print()
    cap = None
    for at in (m.start() for m in re.finditer(r"main p:not\(", css)):
        open_brace = css.find("{", at)
        close_brace = css.find("}", open_brace)
        if open_brace == -1 or close_brace == -1:
            continue
        selector = css[at:open_brace]
        body = css[open_brace:close_brace]
        if "max-w-" not in selector:
            continue
        found = re.search(r"max-width:\s*(\d+)px", body)
        if found:
            cap = int(found.group(1))
            break
    if cap is not None:
        print(
            f"uncapped body copy: {cap}px  — {cap / 1440:.1%} of a 1440 viewport "
            f"(was 40em = 640px = 44.4% at 16px)"
        )
    else:
        print("FAIL: no uncapped body measure found")
        failures += 1

    print()
    print("PASS" if failures == 0 else f"FAIL: {failures} marker(s) wrong")
    return 0 if failures == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
