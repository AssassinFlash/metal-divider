#!/usr/bin/env python3
"""List content blocks the frozen Shopify site has and the rebuild does not.

Every existing audit here measures how the things that *are* on the page look:
spacing, contrast, overflow, narrow layout, media reachability. A block that was
never carried over occupies no space, hugs no edge, and overflows nothing, so
none of those judges can see it. That is why "content is missing" survived a
hundred green runs.

This judge takes the other direction: pull the text of every block from both
sites, then ask, for each old block, whether anything on the new page says the
same thing. Blocks that answer no are the carry-over gap, in a countable list.

Matching is fuzzy on purpose. The two sites are built from different frameworks
and will never share punctuation, casing, entity escaping or wrapping; a strict
string comparison would report every paragraph as missing and be ignored.

    python3 tools/audit/block_parity.py --self-check
    python3 tools/audit/block_parity.py --json /tmp/block-parity.json
    python3 tools/audit/block_parity.py --pair /pages/faqs /faqs/ --verbose
"""

from __future__ import annotations

import argparse
import concurrent.futures
import difflib
import html
import json
import re
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass, field, asdict
from html.parser import HTMLParser

OLD = "https://metalpartition.com"
NEW = "https://metal-divider.com"
UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)

# Below this, two blocks are considered to say different things.
#
# 0.75 was the first guess and the self-check killed it: "lead time is fifteen
# business days for stock patterns" scored 0.849 against "... thirty business
# days for custom patterns". Same sentence shape, different promise. Raising the
# threshold alone cannot fix that class — a single swapped number moves the ratio
# by about 0.01 — so numbers are checked separately (see figure_tokens) and this
# threshold only has to separate real rewording from real restatement.
MATCH_THRESHOLD = 0.88

NUMBER_WORDS = {
    "zero": "0", "one": "1", "two": "2", "three": "3", "four": "4", "five": "5",
    "six": "6", "seven": "7", "eight": "8", "nine": "9", "ten": "10",
    "eleven": "11", "twelve": "12", "fifteen": "15", "twenty": "20",
    "thirty": "30", "forty": "40", "fifty": "50", "sixty": "60",
    "seventy": "70", "eighty": "80", "ninety": "90", "hundred": "100",
    "thousand": "1000",
}


def figure_tokens(key: str) -> frozenset[str]:
    """Every quantity a block asserts, as comparable strings.

    Two blocks that differ in any figure are not the same block, whatever their
    edit distance says. "500-1000 hrs" against "4,000 hr" must never match.
    """
    out = set()
    for tok in re.findall(r"\d+(?:[.,]\d+)*", key):
        out.add(tok.replace(",", "").rstrip(".,"))
    for w in re.findall(r"[a-z]+", key):
        if w in NUMBER_WORDS:
            out.add(NUMBER_WORDS[w])
    return frozenset(out)

# A block shorter than this carries no claim worth comparing. Single words are
# everywhere on both sides ("Gold", "SS304") and match each other by accident.
MIN_CHARS = 12

SKIP_TAGS = {"script", "style", "noscript", "svg", "template", "iframe", "head"}
# These hold page furniture. Both sites have a header and a footer and they are
# compared elsewhere (pixel gate), so including them here only adds noise.
CHROME_TAGS = {"header", "footer", "nav"}

# Tags that end a text run. Everything else flows into its parent block, which is
# how a paragraph split only by <span> stays one block.
BLOCK_TAGS = {
    "p", "li", "dt", "dd", "td", "th", "blockquote", "figcaption", "caption",
    "h1", "h2", "h3", "h4", "h5", "h6", "button", "label", "summary", "legend",
    "option", "address", "pre", "div", "section", "article", "aside", "table",
    "ul", "ol", "dl", "figure", "form", "details",
}
VOID_TAGS = {"br", "hr", "img", "input", "meta", "link", "source", "track", "area", "col", "embed"}


def norm(text: str) -> str:
    """Fold away everything that legitimately differs between the two sites."""
    text = html.unescape(text or "")
    text = text.replace("\u2019", "'").replace("\u2018", "'")
    text = text.replace("\u201c", '"').replace("\u201d", '"')
    text = text.replace("\u2014", "-").replace("\u2013", "-").replace("\u2212", "-")
    text = text.replace("\u00a0", " ")
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


@dataclass
class Block:
    text: str
    key: str
    tag: str
    imgs: int = 0
    alts: list[str] = field(default_factory=list)
    in_form: bool = False


class BlockParser(HTMLParser):
    """Collect one block per run of text, keeping <img> counts and alt text."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.blocks: list[Block] = []
        self._buf: list[str] = []
        self._imgs = 0
        self._alts: list[str] = []
        self._cur_tag = ""
        self._skip_depth = 0
        self._chrome_depth = 0
        self._stack: list[str] = []
        self._form_depth = 0

    def _flush(self) -> None:
        text = "".join(self._buf)
        self._buf = []
        imgs, alts = self._imgs, self._alts
        self._imgs, self._alts = 0, []
        key = norm(text)
        if len(key) >= MIN_CHARS:
            self.blocks.append(Block(text=re.sub(r"\s+", " ", text).strip(), key=key,
                                     tag=self._cur_tag, imgs=imgs, alts=alts,
                                     in_form=self._form_depth > 0))
        elif imgs and self._cur_tag:
            # An image-only block still carries content (a figure or a card
            # thumb). Keep it so a missing image strip can be counted.
            self.blocks.append(Block(text="", key="", tag=self._cur_tag, imgs=imgs, alts=alts,
                                     in_form=self._form_depth > 0))

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        a = dict(attrs)
        if tag in SKIP_TAGS:
            self._skip_depth += 1
            return
        if tag in CHROME_TAGS:
            self._chrome_depth += 1
        if tag == "form" and not self._skip_depth:
            self._form_depth += 1
        if tag in VOID_TAGS:
            if tag == "img" and not self._skip_depth and not self._chrome_depth:
                self._imgs += 1
                alt = (a.get("alt") or "").strip()
                if alt:
                    self._alts.append(alt)
            return
        if tag in BLOCK_TAGS:
            self._flush()
            self._cur_tag = tag
            self._stack.append(tag)

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in SKIP_TAGS:
            self._skip_depth = max(0, self._skip_depth - 1)
            return
        if tag in CHROME_TAGS:
            self._chrome_depth = max(0, self._chrome_depth - 1)
            return
        if tag == "form":
            self._form_depth = max(0, self._form_depth - 1)
            self._flush()
            return
        if tag in BLOCK_TAGS:
            if tag in self._stack:
                while self._stack:
                    t = self._stack.pop()
                    if t == tag:
                        break
            self._flush()

    def handle_data(self, data):
        if self._skip_depth or self._chrome_depth:
            return
        self._buf.append(data)

    def close(self):
        super().close()
        self._flush()


def blocks_of(raw: str) -> list[Block]:
    p = BlockParser()
    p.feed(raw)
    p.close()
    return p.blocks


def fetch(url: str, timeout: int = 45) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "text/html,*/*"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8", "replace")


def best_match(old_key: str, new_keys: list[str]) -> tuple[float, str]:
    """Highest similarity of one old block against any new block.

    A candidate is discarded outright when its figures differ from the old
    block's, so a rewritten promise can never be mistaken for a carried-over one.
    """
    want = figure_tokens(old_key)
    sm = difflib.SequenceMatcher(autojunk=False)
    sm.set_seq2(old_key)
    best, what = 0.0, ""
    for nk in new_keys:
        if figure_tokens(nk) != want:
            continue
        sm.set_seq1(nk)
        if sm.real_quick_ratio() <= best or sm.quick_ratio() <= best:
            continue
        r = sm.ratio()
        if r > best:
            best, what = r, nk
    return best, what


@dataclass
class PairResult:
    old_path: str
    new_path: str
    old_blocks: int
    new_blocks: int
    missing: list[dict]
    missing_chars: int
    old_imgs: int
    new_imgs: int
    form_skipped: int = 0


def compare_pair(old_path: str, new_path: str, verbose: bool = False) -> PairResult:
    old_raw = fetch(OLD + old_path)
    new_raw = fetch(NEW + new_path)
    old_blocks = blocks_of(old_raw)
    new_blocks = blocks_of(new_raw)
    new_keys = [b.key for b in new_blocks if b.key]
    missing = []
    form_skipped = 0
    for b in old_blocks:
        if not b.key:
            continue
        # Form field microcopy is a rewritten, already-ruled surface (spec 07 /
        # spec 19): "Get in Touch", "Message (optional)", the country list, the
        # quantity bands. Comparing them reports a dozen gaps every run and none
        # of them is a carry-over defect. Counted separately so the number is
        # visible rather than silently dropped.
        if b.in_form:
            form_skipped += 1
            continue
        score, _ = best_match(b.key, new_keys)
        if score < MATCH_THRESHOLD:
            missing.append({"text": b.text, "tag": b.tag, "score": round(score, 3)})
            if verbose:
                print(f"  MISSING [{b.tag}] {score:.2f}  {b.text[:120]}")
    return PairResult(
        old_path=old_path,
        new_path=new_path,
        old_blocks=len([b for b in old_blocks if b.key]),
        new_blocks=len([b for b in new_blocks if b.key]),
        missing=missing,
        missing_chars=sum(len(m["text"]) for m in missing),
        old_imgs=sum(b.imgs for b in old_blocks),
        new_imgs=sum(b.imgs for b in new_blocks),
        form_skipped=form_skipped,
    )


# Old path -> new path. Compliance pages (gdpr/popia/pdpa/appi/...) are excluded
# by owner ruling; they are Shopify Consentmo residue. `/pages/metal-divider` and
# `/pages/custom-metal-divider-screens` serve one page on the old site (same
# title, same size to within 250 bytes) and map to one page here.
PAGE_PAIRS: list[tuple[str, str]] = [
    ("/", "/"),
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
    ("/pages/custom-metal-divider-screens", "/custom-metal-divider-screens/"),
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


FIXTURES = {
    # Two blocks that say the same thing through different punctuation, casing
    # and entity escaping. If these do not match, the judge reports every
    # paragraph on the site as missing and its output is worthless.
    "rewording": (
        '<div><p>We ship <strong>worldwide</strong> &amp; crated.</p></div>',
        '<section><p>we ship worldwide & crated</p></section>',
    ),
    # Same topic, different claim. Must NOT match, or a page that silently
    # swapped one promise for another would pass.
    "different_claim": (
        '<p>Lead time is fifteen business days for stock patterns.</p>',
        '<p>Lead time is thirty business days for custom patterns.</p>',
    ),
    # Same words, same shape, only a test figure differs. The ratio here is
    # above 0.95, so only the figure guard can catch it.
    "different_figure": (
        '<p>Passed ASTM B117 salt spray for 4,000 hours.</p>',
        '<p>Passed ASTM B117 salt spray for 500-1000 hours.</p>',
    ),
    # A block present only on the old side is the defect this tool exists for.
    "dropped": (
        '<p>Every panel is hand-polished before crating.</p>',
        '<p>Something else entirely is written here instead.</p>',
    ),
}


def self_check() -> int:
    failures = 0
    for name, (old_raw, new_raw) in FIXTURES.items():
        old_blocks = [b for b in blocks_of(old_raw) if b.key]
        new_keys = [b.key for b in blocks_of(new_raw) if b.key]
        if not old_blocks:
            print(f"SELF-CHECK FAIL {name}: fixture produced no old blocks")
            failures += 1
            continue
        score = max((best_match(b.key, new_keys)[0] for b in old_blocks), default=0.0)
        if name in {"rewording"}:
            ok = score >= MATCH_THRESHOLD
        else:
            ok = score < MATCH_THRESHOLD
        print(f"SELF-CHECK {'ok  ' if ok else 'FAIL'} {name}: best score {score:.3f} "
              f"(threshold {MATCH_THRESHOLD})")
        if not ok:
            failures += 1

    # The judge must also be able to fail: a page compared against itself has
    # zero missing blocks, and against a gutted clone it has some.
    full = ('<h2>Finishes we run</h2><p>Brushed, mirror, and PVD in three colours.</p>'
            '<p>All panels are crated in plywood for sea freight.</p>')
    gutted = '<h2>Finishes we run</h2><p>Brushed, mirror, and PVD in three colours.</p>'
    a = compare_strings(full, full)
    b = compare_strings(full, gutted)
    ok = a == 0 and b == 1
    print(f"SELF-CHECK {'ok  ' if ok else 'FAIL'} can_fail: identical={a} gutted={b}")
    if not ok:
        failures += 1
    return failures


def compare_strings(old_raw: str, new_raw: str) -> int:
    old_blocks = [b for b in blocks_of(old_raw) if b.key]
    new_keys = [b.key for b in blocks_of(new_raw) if b.key]
    return sum(1 for b in old_blocks if best_match(b.key, new_keys)[0] < MATCH_THRESHOLD)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", default="")
    ap.add_argument("--pair", nargs=2, metavar=("OLD", "NEW"))
    ap.add_argument("--verbose", action="store_true")
    ap.add_argument("--self-check", action="store_true")
    ap.add_argument("--workers", type=int, default=6)
    args = ap.parse_args()

    if args.self_check:
        n = self_check()
        print(f"self-check failures: {n}")
        return 1 if n else 0

    if args.pair:
        r = compare_pair(args.pair[0], args.pair[1], verbose=True)
        print(json.dumps(asdict(r), ensure_ascii=False, indent=2)[:4000])
        return 0

    results: list[PairResult] = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as ex:
        futs = {ex.submit(compare_pair, o, n): (o, n) for o, n in PAGE_PAIRS}
        for fut in concurrent.futures.as_completed(futs):
            o, n = futs[fut]
            try:
                results.append(fut.result())
            except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError) as exc:
                print(f"FETCH FAILED {o} -> {n}: {exc}", file=sys.stderr)

    results.sort(key=lambda r: -len(r.missing))
    print(f"{'page':44s} {'old':>5s} {'new':>5s} {'form':>5s} {'miss':>5s} {'chars':>6s} {'imgs':>9s}")
    total_missing = 0
    for r in results:
        total_missing += len(r.missing)
        print(f"{r.new_path:44s} {r.old_blocks:5d} {r.new_blocks:5d} {r.form_skipped:5d} "
              f"{len(r.missing):5d} {r.missing_chars:6d} {r.old_imgs:4d}->{r.new_imgs:<4d}")
    print(f"\npages={len(results)} missing_blocks={total_missing}")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump([asdict(r) for r in results], fh, ensure_ascii=False, indent=2)
        print(f"wrote {args.json}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
