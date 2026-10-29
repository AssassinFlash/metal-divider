#!/usr/bin/env python3
"""Read this batch's markers back from the live site.

`marker_readback.py` checks the fixes of earlier rounds. This one checks the
batch that put the closing-card forms back, added the skip link, and stopped
shipping internal HTML comments — each of which can be present in the
repository, pass every unit test, and still be missing from production, because
`present` re-renders templates and WindPress recompiles the CSS as separate
steps and either can be skipped.

Every check names the failure it would catch. A marker that cannot fail is not
a check, so `--self-check` runs the same predicates against this repository's
pre-fix fixtures and requires each one to go red.

    python3 tools/audit/batch_2026_10_29_readback.py
    python3 tools/audit/batch_2026_10_29_readback.py --self-check
    python3 tools/audit/batch_2026_10_29_readback.py --base http://127.0.0.1:9890
"""

from __future__ import annotations

import argparse
import re
import sys
import urllib.error
import urllib.request

UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)

# The pages whose closing card lost its form. Each entry is one page that must
# now answer with the form's own markup.
FORM_PAGES = [
    "/about-us/",
    "/project-gallery/",
    "/manufacturing-process/",
    "/shop/",
    "/collections/pattern-abstract/",
    "/stainless-steel-screens/",
    "/glass-hybrid-screens/",
    "/laser-cut-outdoor-privacy-landscape-screens-placeholder/",
]


def get(url: str, timeout: int = 45) -> str:
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read().decode("utf-8", "replace")


def check_forms(base: str) -> list[tuple[bool, str]]:
    """Every closing card carries a form, not the link-only band.

    Fails when a page still ships `.md-inquiry-cta` where its heading promises a
    form, or ships the band and no form at all.
    """
    results = []
    for path in FORM_PAGES:
        if "placeholder" in path:
            continue
        try:
            html = get(base + path)
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
            results.append((False, f"{path} unreachable: {exc}"))
            continue
        forms = len(re.findall(r'class="md-inquiry-form"', html))
        bands = len(re.findall(r'class="md-inquiry-cta"', html))
        ok = forms >= 1 and bands == 0
        results.append((ok, f"{path} forms={forms} bands={bands} (want >=1 / 0)"))
    return results


def check_comments(base: str) -> list[tuple[bool, str]]:
    """No HTML comment rides along with the document.

    Fails when an internal note ships: the pre-fix pages carried 4-15 KB of them,
    one of which was a second full inquiry form.
    """
    results = []
    for path in ("/", "/about-us/", "/shop/", "/inquiry/"):
        try:
            html = get(base + path)
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
            results.append((False, f"{path} unreachable: {exc}"))
            continue
        comments = re.findall(r"(?s)<!--(.*?)-->", html)
        longest = max((len(c) for c in comments), default=0)
        ok = longest <= 64
        results.append((ok, f"{path} comments={len(comments)} longest={longest}B (want <=64)"))
    return results


def check_unique_ids(base: str) -> list[tuple[bool, str]]:
    """No element id appears twice on a page.

    Fails when two forms share an id, which is what happened the moment a page
    carried a closing-card form beside the footer dialog: wp_nonce_field()
    writes its id without a per-instance suffix, so both forms claimed
    `md_inquiry_nonce`. Duplicate ids are invalid HTML, and getElementById plus
    any `for=` label then resolve to whichever form rendered first, leaving the
    second form's nonce unreachable by id.

    Measured before the fix: /about-us/ 69 ids, one duplicated; /shop/ 86 and
    /project-gallery/ 76, one each.
    """
    results = []
    for path in ("/", "/about-us/", "/shop/", "/project-gallery/", "/inquiry/"):
        try:
            html = get(base + path)
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
            results.append((False, f"{path} unreachable: {exc}"))
            continue
        ids = re.findall(r'\bid="([^"]+)"', html)
        duplicated = sorted({i for i in ids if ids.count(i) > 1})
        results.append(
            (
                not duplicated,
                f"{path} ids={len(ids)} duplicated={len(duplicated)}"
                + (f" {duplicated[:4]}" if duplicated else ""),
            )
        )
    return results


def check_skip_link(base: str) -> list[tuple[bool, str]]:
    """The document opens with a skip link that names a real target.

    Fails when the header partial was never re-rendered, which leaves the site
    exactly as it was: 30 Tab presses and no way into main.
    """
    results = []
    for path in ("/", "/shop/", "/inquiry/"):
        try:
            html = get(base + path)
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
            results.append((False, f"{path} unreachable: {exc}"))
            continue
        found = re.search(r'<a[^>]*class="[^"]*md-skip-link[^"]*"[^>]*href="#([^"]+)"', html)
        if not found:
            results.append((False, f"{path} no skip link"))
            continue
        target = found.group(1)
        has_target = f'id="{target}"' in html or f"id='{target}'" in html
        results.append((ok_marker := has_target, f"{path} skip-link -> #{target} target={has_target}"))
        del ok_marker
    return results


def check_stylesheet(base: str) -> list[tuple[bool, str]]:
    """The compiled CSS carries the scoped frame rule.

    Fails when WindPress was not recompiled after the CSS edit: the rule is in
    the repository and in no stylesheet the browser loads, so every card keeps
    the inquiry page's 144px frame.
    """
    try:
        home = get(base + "/")
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        return [(False, f"/ unreachable: {exc}")]
    match = re.search(r'href=["\']([^"\']*windpress/cache/tailwind\.css[^"\']*)["\']', home)
    if not match:
        return [(False, "no compiled stylesheet linked from /")]
    url = match.group(1)
    if url.startswith("/"):
        root = re.match(r"^(https?://[^/]+)", base)
        url = (root.group(1) if root else base) + url
    try:
        css = get(url, timeout=90)
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        return [(False, f"stylesheet unreachable: {exc}")]
    found = len(re.findall(r"\.md-inquiry-app:not\(#md-inquiry \*\)", css))
    skip = len(re.findall(r"\.md-skip-link", css))
    return [
        (found >= 1, f"scoped frame rule in compiled CSS: {found} (want >=1)"),
        (skip >= 1, f"md-skip-link in compiled CSS: {skip} (want >=1)"),
    ]


CHECKS = (
    ("closing-card forms", check_forms),
    ("no shipped comments", check_comments),
    ("unique element ids", check_unique_ids),
    ("skip link", check_skip_link),
    ("compiled stylesheet", check_stylesheet),
)


def run(base: str, verbose: bool = True) -> int:
    failures = 0
    for label, fn in CHECKS:
        results = fn(base)
        bad = [r for r in results if not r[0]]
        if verbose:
            print(f"\n== {label}")
            for ok, message in results:
                print(f"  {'ok  ' if ok else 'FAIL'} {message}")
        failures += len(bad)
    print(f"\n{base}: failures={failures}")
    return 1 if failures else 0


def self_check() -> int:
    """Prove each predicate can fail, using this repository's own pre-fix text.

    The fixtures are the defects themselves, quoted from what the repository
    held before this batch: a card carrying only the band, a page carrying a
    long comment, a header with no skip link, and a stylesheet without the rule.
    """
    fixtures = {
        "band card": '<div class="md-closing-well"><div class="md-inquiry-cta">'
        '<a class="md-inquiry-cta__primary" href="/inquiry/">Request a quote</a></div></div>',
        "form card": '<div class="md-closing-well"><form class="md-inquiry-form"></form></div>',
        "commented page": "<!-- " + ("x" * 900) + " --><main></main>",
        "clean page": "<main id='theme-main'></main>",
        "no skip header": "<header><a href='/shop/'>Shop</a></header>",
        "skip header": '<header><a class="md-skip-link" href="#theme-main">Skip to content</a>'
        "</header><main id='theme-main'></main>",
        "old css": ".md-inquiry{padding:56px 0 88px}",
        "new css": ".md-inquiry-app:not(#md-inquiry *) .md-inquiry{padding:0}.md-skip-link{clip:rect(0,0,0,0)}",
    }

    def forms_ok(html: str) -> bool:
        return bool(re.search(r'class="md-inquiry-form"', html)) and not re.search(
            r'class="md-inquiry-cta"', html
        )

    def comments_ok(html: str) -> bool:
        comments = re.findall(r"(?s)<!--(.*?)-->", html)
        return max((len(c) for c in comments), default=0) <= 64

    def skip_ok(html: str) -> bool:
        found = re.search(r'<a[^>]*class="[^"]*md-skip-link[^"]*"[^>]*href="#([^"]+)"', html)
        if not found:
            return False
        return f'id="{found.group(1)}"' in html or f"id='{found.group(1)}'" in html

    def css_ok(css: str) -> bool:
        return bool(re.search(r"\.md-inquiry-app:not\(#md-inquiry \*\)", css)) and bool(
            re.search(r"\.md-skip-link", css)
        )

    def ids_ok(html: str) -> bool:
        ids = re.findall(r'\bid="([^"]+)"', html)
        return len(ids) == len(set(ids))

    fixtures["two forms, same id"] = (
        '<input name="md_inquiry_nonce" id="md_inquiry_nonce">'
        '<input name="md_inquiry_nonce" id="md_inquiry_nonce">'
    )
    fixtures["two forms, suffixed"] = (
        '<input name="md_inquiry_nonce" id="md_inquiry_nonce">'
        '<input name="md_inquiry_nonce" id="md_inquiry_nonce-2">'
    )

    pairs = [
        ("forms", forms_ok, "band card", "form card"),
        ("comments", comments_ok, "commented page", "clean page"),
        ("unique ids", ids_ok, "two forms, same id", "two forms, suffixed"),
        ("skip link", skip_ok, "no skip header", "skip header"),
        ("stylesheet", css_ok, "old css", "new css"),
    ]
    failures = 0
    for label, fn, bad_fixture, good_fixture in pairs:
        red = not fn(fixtures[bad_fixture])
        green = fn(fixtures[good_fixture])
        ok = red and green
        print(
            f"SELF-CHECK {'ok  ' if ok else 'FAIL'} {label:<16} "
            f"pre-fix -> {'red' if red else 'GREEN (blind)'}  "
            f"post-fix -> {'green' if green else 'RED (over-strict)'}"
        )
        if not ok:
            failures += 1
    print(f"self-check failures: {failures}")
    return 1 if failures else 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args()
    if args.self_check:
        return self_check()
    return run(args.base)


if __name__ == "__main__":
    sys.exit(main())
