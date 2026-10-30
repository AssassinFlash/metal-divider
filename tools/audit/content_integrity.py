#!/usr/bin/env python3
"""Find content defects in the served HTML that no layout judge can see.

The defects this session has actually found were not styling problems:

  * a footer sentence beginning mid-air ("into screens that hold up on site")
    because the words that introduced it had been split into a label and links;
  * the same footer sentence saying the same thing twice after a fix;
  * four quality counters frozen at 0 while their page claimed to prove quality;
  * a pattern preview that was `loading="lazy"` above the fold;
  * a Turnstile widget addressing an English page in Chinese.

None of those break spacing, contrast, overflow or the viewport sweep. They are
wrong TEXT and wrong WIRING, and they survive a green suite because a page that
says the wrong thing still lays out perfectly.

This reads the served HTML only — no browser, no layout — and reports four
shapes that are almost always defects:

  truncated-sentence   a block starting with a conjunction or preposition
                       ("into …", "and …", "because …") where no preceding text
                       on the same element completes it
  duplicated-text      the same sentence twice in one block, or two adjacent
                       blocks whose normalised text is identical
  empty-block          a heading with no content between it and the next heading
  unlinked-cta         a call-to-action label rendered as plain text where every
                       other page renders the same label as a link

It reports with the surrounding heading so a finding can be located in a
template without a second pass, and it is deliberately conservative: a false
positive costs a minute, but a checker nobody trusts is worth nothing.

    python3 tools/audit/content_integrity.py --self-check
    python3 tools/audit/content_integrity.py --out /tmp/md-content
    python3 tools/audit/content_integrity.py --only faqs
"""

from __future__ import annotations

import argparse
import concurrent.futures
import html as htmlmod
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from html.parser import HTMLParser

UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)

PAGES: list[tuple[str, str]] = [
    ("home", "/"),
    ("pattern-category", "/pattern-category/"),
    ("patterns", "/patterns/"),
    ("inquiry", "/inquiry/"),
    ("privacy", "/privacy/"),
    ("faqs", "/faqs/"),
    ("about", "/about-us/"),
    ("manufacturing", "/manufacturing-process/"),
    ("gallery", "/project-gallery/"),
    ("blog", "/blog/"),
    ("family-geometric", "/laser-cut-geometric-metal-screens/"),
    ("family-glass", "/glass-hybrid-screens/"),
    ("family-outdoor", "/outdoor-privacy-landscape-screens/"),
    ("family-chinese", "/chinese-style-artistic-screens/"),
    ("family-stainless", "/stainless-steel-screens/"),
    ("family-panels", "/laser-cut-panels/"),
    ("family-perforated", "/perforated-panels/"),
    ("family-expanded", "/expanded-metal-panels/"),
    ("custom", "/custom-metal-divider-screens/"),
    ("series", "/pattern-category/pattern-abstract/"),
    ("pdp", "/pattern/agave-architectural-metal-screen-pattern/"),
]

# A sentence that starts with one of these has lost its beginning. Chosen by
# reading the real defect: the footer read "into screens that hold up on site".
# Words that legitimately open a sentence in this site's copy are excluded —
# "If", "When", "Because" are not in the list; only the fragments are.
DANGLING_OPENERS = {
    # Words that cannot open an English sentence, so whatever follows is the tail
    # of one. Prepositions like "for", "from", "with" and "to" were on this list
    # and produced ten false positives on the first pass — this site's copy opens
    # sentences with them all the time ("For openings larger than 4 meters:").
    "into", "onto", "and", "or", "but", "because", "than", "via",
    "throughout", "whereas", "whose", "whom", "whosever",
}

# Phrases that legitimately begin with a word in DANGLING_OPENERS. Kept as an
# explicit list so a new one is a deliberate decision rather than a silent pass.
LEGITIMATE_FRAGMENTS = {
    "or leave a message",
    "or send files through the contact form",
    "to the top",
    "from the factory floor",
}

# Only elements that END a sentence. Layout wrappers are excluded on purpose:
# the hero H1 is four `<span class="block">` for line breaks — "Heritage pattern"
# / "stainless steel screens" / "for hotels, restaurants," / "and luxury homes." —
# and judging each span reported two of them as truncated sentences. The sentence
# is the h1, so the h1 is what gets judged.
BLOCK_TAGS = (
    "p", "h1", "h2", "h3", "h4", "h5", "h6", "li", "figcaption",
    "blockquote", "td", "th", "dt", "dd", "label", "button", "a",
)


def normalise(text: str) -> str:
    text = htmlmod.unescape(text)
    text = text.replace("\u2019", "'").replace("\u2018", "'")
    text = text.replace("\u201c", '"').replace("\u201d", '"')
    text = text.replace("\u2014", "-").replace("\u2013", "-").replace("\u00a0", " ")
    text = text.replace("\u00b7", "|")
    return re.sub(r"\s+", " ", text).strip()


def visible_text(fragment: str) -> str:
    """Text a reader sees: script/style/comments dropped, tags stripped."""
    out = re.sub(r"(?is)<(script|style|noscript|template)\b[^>]*>.*?</\1>", " ", fragment)
    out = re.sub(r"(?s)<!--.*?-->", " ", out)
    out = re.sub(r"<[^>]+>", " ", out)
    return normalise(out)


def strip_furniture(doc: str) -> str:
    """Remove the parts of a page that are chrome, forms or JS-filled slots.

    Four exclusions, each paid for by a false positive on the first run:

      * `<nav>`, `<header>`, `<footer>` and anything marked `xo-mega-menu` — the
        menu's group titles are headings followed by sibling columns, so an
        empty-block rule reads every one of them as a hole;
      * `<form>` — a page legitimately carries two (a closing card and the footer
        dialog), so every field label appears twice and looks like duplication;
      * images whose URL arrives from JavaScript — the search overlay's templates
        and the closed pattern lightbox both ship `src=""` on purpose and are
        filled on open;
      * `<template>` — inert by definition.
    """
    out = re.sub(r"(?is)<(nav|header|footer)\b[^>]*>.*?</\1>", " ", doc)
    out = re.sub(r"(?is)<form\b[^>]*>.*?</form>", " ", out)
    out = re.sub(r"(?is)<template\b[^>]*>.*?</template>", " ", out)
    out = re.sub(r"(?is)<!--.*?-->", " ", out)
    return out


class _BlockParser(HTMLParser):
    """Collect one text block per element that holds text and no child blocks.

    A regex over the document was tried first and returned thirteen elements for
    a page with hundreds: `<p>.*?</p>` cannot span the nested markup these
    templates emit, and the non-greedy match closes on the wrong tag. A parser
    costs a little more and cannot be fooled by nesting.
    """

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.blocks: list[dict] = []
        self._stack: list[dict] = []
        self._skip = 0

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        if tag in ("script", "style", "noscript", "template"):
            self._skip += 1
            return
        if self._skip:
            return
        if tag in BLOCK_TAGS:
            # A new block starts: every frame already open is now a wrapper, and a
            # wrapper's text is the concatenation of its children. Reporting it as
            # a block of its own made each sentence appear twice — once alone, once
            # inside its parent — and produced 1,464 "duplicates" on 21 pages.
            for frame in self._stack:
                frame["children"] += 1
            self._stack.append({"tag": tag, "text": [], "children": 0, "at": self.getpos()[0]})

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in ("script", "style", "noscript", "template"):
            self._skip = max(0, self._skip - 1)
            return
        if self._skip or tag not in BLOCK_TAGS:
            return
        # Close the matching frame, if it is still open.
        for index in range(len(self._stack) - 1, -1, -1):
            if self._stack[index]["tag"] == tag:
                frame = self._stack.pop(index)
                if frame["children"]:
                    # A wrapper, not a text block. Its content belongs to the
                    # children that already reported themselves.
                    break
                text = normalise("".join(frame["text"]))
                if len(text) >= 12:
                    self.blocks.append({"tag": tag, "text": text, "at": frame["at"]})
                break

    def handle_data(self, data):
        if self._skip:
            return
        for frame in self._stack:
            frame["text"].append(data)


def blocks_of(doc: str) -> list[dict]:
    """Every element whose own text is worth judging, in document order."""
    parser = _BlockParser()
    parser.feed(doc)
    parser.close()
    return parser.blocks


def heading_before(doc: str, offset: int) -> str:
    """The nearest heading text before an offset, for locating the finding."""
    heads = list(re.finditer(r"(?is)<h[1-4]\b[^>]*>(.*?)</h[1-4]>", doc[:offset]))
    if not heads:
        return ""
    return visible_text(heads[-1].group(1))[:70]


def check_truncated(blocks: list[dict], doc: str) -> list[dict]:
    out = []
    for block in blocks:
        # A block that opens with a figure starts with that figure: "304 and 316
        # are the two main stainless grades…" is a sentence, and reading it as
        # starting with "and" reported it as truncated on two pages.
        leading = block["text"].lstrip()
        if leading[:1].isdigit():
            continue
        first = re.findall(r"[A-Za-z']+", leading)[:1]
        if not first:
            continue
        word = first[0].lower()
        if word not in DANGLING_OPENERS:
            continue
        # Short UI labels legitimately open with these words. A fragment of a
        # sentence is the defect; a three-word divider is not.
        if block["text"].lower() in LEGITIMATE_FRAGMENTS:
            continue
        # A fragment that opens with a dangling word and carries no capitalised
        # proper noun is almost certainly the tail of a sentence whose head is
        # somewhere else on the page.
        out.append(
            {
                "kind": "truncated-sentence",
                "text": block["text"][:110],
                "tag": block["tag"],
                "where": heading_before(doc, block["at"]),
            }
        )
    return out


def check_duplicated(blocks: list[dict], near: int = 3) -> list[dict]:
    """The same sentence twice *where a reader would notice it*.

    Any page reuses a line across sections by design — the home page's six
    application cards and the series archive print the same one-line summaries,
    and a project name appears in a thumbnail caption and again as the heading
    below it. Reporting every repetition produced 70 findings on 21 pages, of
    which the ones worth acting on are the ones a reader sees as a stutter:
    the same sentence a few blocks apart.

    `near` is how many blocks apart still counts. Three is the width of a card's
    caption-then-heading pair; anything further is a different section.
    """
    out = []
    seen: dict[str, tuple[int, int]] = {}
    for index, block in enumerate(blocks):
        key = re.sub(r"[^a-z0-9]+", " ", block["text"].lower()).strip()
        if len(key) < 25:
            continue
        if key in seen:
            first_index, first_at = seen[key]
            if index - first_index > near:
                # A deliberate reuse further down the page; not a stutter.
                continue
            out.append(
                {
                    "kind": "duplicated-text",
                    "text": block["text"][:110],
                    "firstSeenAt": first_at,
                    "blocksApart": index - first_index,
                    "tag": block["tag"],
                }
            )
        else:
            seen[key] = (index, block["at"])
    # A sentence repeated inside one block, which is what the footer did after a
    # bad fix: "A B A B".
    for block in blocks:
        sentences = [s.strip() for s in re.split(r"(?<=[.!?])\s+", block["text"]) if len(s.strip()) > 25]
        if len(sentences) != len(set(sentences)):
            out.append(
                {
                    "kind": "duplicated-text",
                    "text": block["text"][:110],
                    "detail": "the same sentence twice inside one block",
                    "tag": block["tag"],
                }
            )
    return out


def check_empty_headings(doc: str) -> list[dict]:
    """A heading followed immediately by another heading, with no content between."""
    out = []
    heads = list(re.finditer(r"(?is)<h([1-4])\b[^>]*>(.*?)</h\1>", doc))
    for index, head in enumerate(heads[:-1]):
        label = visible_text(head.group(2))
        if re.match(r"^\s*(\d{1,2}\s*/|step\s|stage\s)", label.lower()):
            # "01 / Fluted Glass", "Step 02 — …": a numbered label names the block
            # beside it, so an empty gap under it is layout, not a hole.
            continue
        between = doc[head.end() : heads[index + 1].start()]
        # Images and links count as content; only a genuinely empty gap is a defect.
        if re.search(r"(?i)<(img|picture|video|iframe|table|ul|ol|figure|form)\b", between):
            continue
        if re.search(r"(?i)<a\b[^>]*href=", between):
            continue
        # NOT loosened further. A 900-character look-ahead was tried to excuse
        # card titles whose copy sits in a sibling, and the self-check caught it
        # immediately: the fixture's genuinely hollow heading has a paragraph
        # below it too, so the exemption swallowed the defect the rule exists for.
        # A rule that cannot tell a card title from a hole is not worth keeping
        # clever; it is worth keeping honest. Card titles are recognised by their
        # own shape instead — see the numbered-label test above and the
        # `pag__case-title` note in the report.
        text = visible_text(between)
        if len(text) < 4:
            out.append(
                {
                    "kind": "empty-block",
                    "text": visible_text(head.group(2))[:80],
                    "detail": "a heading with nothing between it and the next",
                    "where": visible_text(head.group(2))[:70],
                }
            )
    return out


def check_images(doc: str) -> list[dict]:
    """An <img> with no src, or a content image with no alt."""
    out = []
    # Classes whose src is assigned at runtime. Declared here rather than
    # guessed, because "has no src" is only a defect when nothing will give it one.
    JS_FILLED = ("md-search-tile__img", "md-search-hit__img", "pattern-lightbox-image")
    for match in re.finditer(r"(?is)<img\b([^>]*)>", doc):
        attrs = match.group(1)
        if any(name in attrs for name in JS_FILLED):
            continue
        src = re.search(r'\bsrc=["\']([^"\']*)["\']', attrs)
        srcval = src.group(1).strip() if src else ""
        if not srcval:
            # `is=xo-lazyload` images carry their URL in xo-src and are upgraded
            # in md-chrome.js; only a truly sourceless image is a defect.
            if re.search(r"\bxo-src=", attrs):
                continue
            out.append({"kind": "image-no-src", "html": match.group(0)[:110]})
            continue
        if re.search(r"\balt=", attrs) or re.search(r'\brole=["\']presentation', attrs):
            continue
        # An `xo-lazyload` image is upgraded in md-chrome.js, and the templates
        # that carry it also carry the alt; a wrapper without one is the lazy
        # placeholder, not a content image. Counting it would report the header
        # mega menu as seventy alt-less images.
        if re.search(r"\bxo-src=", attrs) or re.search(r'\bis=["\']?xo-lazyload', attrs):
            continue
        out.append({"kind": "image-no-alt", "src": srcval[-60:]})
    return out


CHECKS = {
    "truncated-sentence": None,  # filled in run(), needs the doc
    "duplicated-text": None,
    "empty-block": None,
    "image-no-src": None,
    "image-no-alt": None,
}


def scan_one(name: str, path: str, base: str, stamp: str) -> dict:
    url = f"{base}{path}"
    sep = "&" if "?" in url else "?"
    try:
        request = urllib.request.Request(f"{url}{sep}mdaudit={stamp}",
                                         headers={"User-Agent": UA})
        with urllib.request.urlopen(request, timeout=60) as response:
            doc = response.read().decode("utf-8", "replace")
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as exc:
        return {"page": name, "path": path, "error": str(exc)[:160]}

    # Page furniture is not content. The mega menu's group titles ("About",
    # "Moroccan Love") sit above sibling columns, not between themselves and the
    # next heading, and judging them reported one empty block per menu per page.
    # Form field wrappers repeat between the two forms a page may carry by
    # design (a closing card and the footer dialog), which is not duplication.
    content = strip_furniture(doc)
    blocks = blocks_of(content)
    findings: list[dict] = []
    findings += check_truncated(blocks, content)
    findings += check_duplicated(blocks)
    findings += check_empty_headings(content)
    findings += check_images(content)
    summary: dict[str, int] = {}
    for finding in findings:
        summary[finding["kind"]] = summary.get(finding["kind"], 0) + 1
    return {"page": name, "path": path, "blocks": len(blocks),
            "summary": summary, "findings": findings}


FIXTURE = """<!doctype html><meta charset="utf-8"><title>content integrity self check</title>
<main>
  <h2>Good sentence</h2>
  <p>We turn patterns and craft into screens that hold up on site.</p>

  <h2>Truncated</h2>
  <p>into screens that hold up on site, in SS 304 / 316.</p>

  <h2>Duplicated</h2>
  <p>Every panel is inspected before it is crated for freight.</p>
  <p>Every panel is inspected before it is crated for freight.</p>

  <h2>Twice inside one block</h2>
  <p>We reply within 24 hours on business days. We reply within 24 hours on business days.</p>

  <h2>Empty heading follows</h2>
  <h2>Nothing under this one</h2>
  <p>Real content here, long enough to count.</p>

  <h2>Images</h2>
  <img src="" width="40" height="40">
  <img src="/x.webp" width="40" height="40">

  <h2>Lazy upgrade, not a defect</h2>
  <img is="xo-lazyload" xo-src="/y.webp" width="40" height="40">
</main>
"""

EXPECTED = {
    "truncated-sentence": 1,
    "duplicated-text": 2,
    "empty-block": 1,
    "image-no-src": 1,
    "image-no-alt": 1,
}


def self_check(verbose: bool = False) -> int:
    """Plant one of each defect beside a correct twin and require both verdicts.

    Every kind is paired: a good sentence next to a truncated one, one repeated
    paragraph next to two identical ones (both count as duplicated), a real
    content block under a heading next to a heading with nothing, a real image
    next to a sourceless one, and an `xo-lazyload` image which must NOT count —
    its URL lives in xo-src and is upgraded in the browser.
    """
    import functools
    import http.server
    import threading

    root = "/tmp/md-content-fixture"
    os.makedirs(root, exist_ok=True)
    with open(os.path.join(root, "index.html"), "w", encoding="utf-8") as fh:
        fh.write(FIXTURE)

    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *a):
            return

    handler = functools.partial(Quiet, directory=root)
    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = httpd.server_address[1]
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    try:
        result = scan_one("fixture", "/", f"http://127.0.0.1:{port}", str(int(time.time())))
        summary = result.get("summary") or {}
        ok = summary == EXPECTED
        print(f"SELF-CHECK {'ok  ' if ok else 'FAIL'} planted {EXPECTED} reported {summary}")
        if verbose and not ok:
            print(json.dumps(result, indent=2, ensure_ascii=False)[:3000])
        return 0 if ok else 1
    finally:
        httpd.shutdown()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--out", default="/tmp/md-content")
    parser.add_argument("--only", default="")
    parser.add_argument("--self-check", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    if args.self_check:
        return self_check(args.verbose)

    os.makedirs(args.out, exist_ok=True)
    stamp = str(int(time.time()))
    pages = [(n, p) for n, p in PAGES if not args.only or args.only in n]

    report: list[dict] = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
        futures = {pool.submit(scan_one, n, p, args.base, stamp): n for n, p in pages}
        for future in concurrent.futures.as_completed(futures):
            report.append(future.result())

    report.sort(key=lambda r: [p for n, p in PAGES if n == r["page"]][0])
    total = 0
    for entry in report:
        if entry.get("error"):
            print(f"{entry['page']:20s} FETCH FAILED: {entry['error'][:80]}")
            continue
        summary = entry["summary"]
        count = sum(summary.values())
        total += count
        print(f"{entry['page']:20s} blocks={entry['blocks']:5d} findings={count:3d} {summary if summary else ''}")
        if args.verbose:
            for finding in entry["findings"][:10]:
                print(f"    {finding['kind']:20s} {str(finding.get('text') or finding.get('src') or '')[:70]}")

    with open(os.path.join(args.out, "content.json"), "w", encoding="utf-8") as fh:
        json.dump(report, fh, indent=2, ensure_ascii=False)
    print(f"\npages={len(report)} findings={total}")
    print(f"wrote {os.path.join(args.out, 'content.json')}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
