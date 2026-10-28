#!/usr/bin/env python3
"""Measure the three things a screenshot complains about but cannot quantify.

The owner's acceptance notes named "字体贴边、间距贴死、可视范围过窄" as a global
problem class, and a pixel diff cannot separate them from a deliberate design:
a heading that starts 2px from the edge and a full-bleed photograph both "reach
the edge" in the image. So this reads the DOM instead and reports three
numbers per page, each of which a person can act on:

  edge-touch   body text whose box sits closer than MIN_GUTTER to the viewport
               edge. Text is what needs air; a full-bleed background does not,
               which is why only text-bearing elements are measured.
  tight-leading  text blocks of two or more lines whose computed line-height is
               below MIN_LEADING x font-size. One line cannot look cramped, so
               the rule counts what is actually laid out.
  narrow-column  the widest text run on the page as a fraction of the viewport.
               A low number is not automatically wrong — a 72-character measure
               is a typographic rule — so this is reported, never flagged.

The point is the comparison. Run it against the old site and the new one at the
same width and the gap between the two reports is the replication gap, in
pixels, on the axis the owner was describing.

Real Chrome, Playwright, because the widths that matter here are below the
window minimum (~578px on this Mac). Profile leftovers are reclaimed the same
way narrow_viewport.py does it.

Usage:
    python3 tools/audit/spacing.py --width 390 --base https://metal-divider.com \
        --pages / /shop/ --out /tmp/md-space
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))

from narrow_viewport import reclaim_profiles  # noqa: E402

# 8px: below this a gutter reads as a layout mistake rather than a choice. The
# value is a judgement, so it is printed with every finding and the caller can
# re-run with --min-gutter to see what a different line would report.
MIN_GUTTER = 8.0
# 1.25: the smallest line-height that still separates two lines of body text
# without descenders colliding. Tailwind's `leading-tight` is 1.25, so this
# flags only what is tighter than the framework's own tightest named step.
MIN_LEADING = 1.25
# 2px of clear background between two lines of ink. Judged from the rendered
# pixels on four wrapping labels at 320 (2026-10-29): the tightest held 4px, the
# loosest 7px, and all four read as normal in the screenshot. A line-height
# ratio cannot express that — 1.2 there still left 7px, because a sans at this
# size does not fill its em box — so the ratio is reported for context and the
# gap decides.
MIN_INK_GAP = 2.0
# Above this size tight leading is typography, not crowding. Display headings on
# both sites sit at 1.06-1.20 (measured 2026-10-29: the old site's h1/h2 run
# 1.06-1.10 and the new site reproduces them), and calling that cramped would
# flag the original design on every page. Body text is what needs air.
DISPLAY_SIZE_PX = 20.0

PROBE = r"""() => {
  const MIN_GUTTER = %GUT%;
  const MIN_LEADING = %LEAD%;
  const DISPLAY_SIZE = %DISP%;
  const MIN_INK_GAP = %GAP%;
  const vw = innerWidth;
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const TEXT = 'p,h1,h2,h3,h4,h5,h6,li,dt,dd,figcaption,blockquote,td,th,a,button,span,label';
  const shown = (el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    if (parseFloat(cs.opacity) < 0.5) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const ownText = (el) => {
    let t = '';
    for (const n of el.childNodes) if (n.nodeType === 3) t += n.nodeValue;
    return norm(t);
  };

  // Line count from the text's own line boxes, not from the element's height.
  // Height includes padding, so a one-word badge with 10px of padding read as
  // "3 lines" and its `line-height: 1` looked cramped when there was only ever
  // one line (measured 2026-10-29: eight `/shop/` badges, all `display: flex`).
  //
  // Counting distinct tops rather than rects: one line can produce several
  // rects when a character is laid out separately (`Step 02 / Drawing & quote`
  // yielded 2 rects on 1 line, measured 2026-10-29).
  const lineTops = (el) => {
    const range = document.createRange();
    range.selectNodeContents(el);
    const rects = [...range.getClientRects()].filter((r) => r.width > 0 && r.height > 0);
    return [...new Set(rects.map((r) => Math.round(r.top)))].sort((a, b) => a - b);
  };

  // The gap between two lines of ink, in CSS pixels — the quantity the eye
  // actually judges. Line-height ratio is a proxy: on a site set in a sans
  // whose capitals and lowercase do not fill the em box, 1.2 can leave 7px of
  // clear background (measured 2026-10-29 on four `/shop/` labels, confirmed by
  // inspecting the rendered pixels: 4-7 CSS px of pure background between the
  // lines, the tightest being `Organic Patterns`, where `g`'s descender hangs
  // 4px above the next line's `t` without touching).
  //
  // Range rects cannot give this: they are the LINE BOX, and consecutive line
  // boxes abut, so the difference is zero for every normally-set paragraph
  // (measured 2026-10-29 — the first version of this function reported 25
  // `/shop/` findings for exactly that reason). The glyph extent has to come
  // from the font, and canvas `measureText` reports it as
  // `actualBoundingBoxAscent/Descent` for the actual characters. On a 16px/21.6
  // body paragraph that yields 6.12px, matching the pixels.
  const canvas = document.createElement('canvas').getContext('2d');
  const inkGap = (el, cs, tops) => {
    if (tops.length < 2) return null;
    const lh = parseFloat(cs.lineHeight);
    if (!lh) return null;
    canvas.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    // Measure the glyphs that are actually rendered. A `uppercase` eyebrow set
    // in lowercase source has no descenders on screen, but `measureText` on the
    // raw string still measures `g`'s tail and reports a gap 3px tighter than
    // the page shows (measured 2026-10-29: five `Step 0N / …` labels read 0.4px
    // here, while the rendered pixels leave ~3 CSS px).
    const transform = cs.textTransform || 'none';
    let text = el.textContent || '';
    if (transform === 'uppercase') text = text.toUpperCase();
    else if (transform === 'lowercase') text = text.toLowerCase();
    const m = canvas.measureText(text);
    const ascent = m.actualBoundingBoxAscent || 0;
    const descent = m.actualBoundingBoxDescent || 0;
    if (!ascent) return null;
    return +(lh - ascent - descent).toFixed(2);
  };

  const edge = [], tight = [];
  let widest = 0, widestText = '';

  // Candidates are collected first and confirmed afterwards, because the only
  // honest visibility test for a box that a *clip ancestor* hides is to scroll
  // to it and ask which element is on top there. This page carries collapsed
  // FAQ answer panels laid out 40px wide at x=1 — display and opacity both say
  // "visible", and they are not (measured 2026-10-29: ten such paragraphs were
  // reported as body text touching the left edge with a 1px gutter).
  const edgeCandidates = [];

  for (const el of document.querySelectorAll(TEXT)) {
    if (!shown(el)) continue;
    const t = ownText(el);
    if (!t) continue;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const left = r.left, right = vw - r.right;
    // A box that runs past the viewport edge is overflow (overflow.py's job) or
    // a carousel item parked half off-screen, not a gutter. Only fully visible
    // text has a gutter worth measuring.
    if (r.width > vw + 1 || r.left < -1 || r.right > vw + 1) continue;
    // Text inside a moving strip has no stable gutter: its distance to the edge
    // is a function of scroll position or animation time, not of layout. Two
    // mechanisms carry that here — a scroll container, and a transform-driven
    // marquee (`mfg-projects-marquee-track`, `translateX(-442px)` at rest,
    // measured 2026-10-29). Judging either produces a finding that moves when
    // the element moves, and this probe's own `scrollIntoView` moves it.
    const inMovingStrip = (() => {
      let p = el.parentElement;
      for (let i = 0; i < 10 && p; i++) {
        const pcs = getComputedStyle(p);
        if (/auto|scroll/.test(pcs.overflowX) && p.scrollWidth > p.clientWidth + 1) {
          return true;
        }
        const m = /matrix\(([^)]+)\)/.exec(pcs.transform || '');
        if (m) {
          const parts = m[1].split(',').map(Number);
          if (Math.abs(parts[4] || 0) > 1) return true;
        }
        if (pcs.animationName && pcs.animationName !== 'none') return true;
        p = p.parentElement;
      }
      return false;
    })();
    // A 40px-wide paragraph is not a line of body text. Anything this narrow is
    // a clipped clone, and no gutter number from it means anything.
    if (r.width >= 80 && !inMovingStrip) {
      const gutter = Math.min(left, right);
      if (gutter < MIN_GUTTER) {
        edgeCandidates.push({ el, gutter, left, right, text: t });
      }
    }
    const fs = parseFloat(cs.fontSize) || 0;
    const lh = parseFloat(cs.lineHeight);
    if (fs > 0 && fs < DISPLAY_SIZE && lh > 0 && r.width >= 80) {
      const tops = lineTops(el);
      if (tops.length >= 2) {
        // Report the whole family — the ink gap, the ratio, and the count —
        // because the gap alone cannot say whether two boxes that sit close
        // actually collide. A reader sees a number they can check against the
        // screenshot instead of a verdict they have to trust.
        const gap = inkGap(el, cs, tops);
        if (gap !== null && gap < MIN_INK_GAP) {
          tight.push({
            tag: el.tagName.toLowerCase(),
            inkGap: gap,
            ratio: +(lh / fs).toFixed(2), fs: +fs.toFixed(1), lh: +lh.toFixed(1),
            lines: tops.length, text: t.slice(0, 120),
          });
        }
      }
    }
    if (r.width > widest) { widest = r.width; widestText = t.slice(0, 120); }
  }

  const originalY = scrollY;
  for (const c of edgeCandidates) {
    c.el.scrollIntoView({ block: 'center' });
    const box = c.el.getBoundingClientRect();
    // Re-measure after scrolling. The coordinates that made this a candidate
    // were read before `scrollIntoView`, and that call can move the element
    // horizontally as well (a carousel item loses the -266px gutter it had
    // while parked off-screen). Reporting the stale numbers would accuse a
    // control that sits fine once it is the active slide — measured
    // 2026-10-29, all six surviving home findings were exactly that.
    if (box.width < 80 || box.right <= 0 || box.left >= vw) continue;
    const gutter = Math.min(box.left, vw - box.right);
    if (gutter >= MIN_GUTTER) continue;
    const hit = document.elementFromPoint(
      Math.min(Math.max(box.left + box.width / 2, 1), vw - 1),
      Math.min(Math.max(box.top + box.height / 2, 1), innerHeight - 1)
    );
    const visible = hit && (hit === c.el || c.el.contains(hit) || hit.contains(c.el));
    if (!visible) continue;
    edge.push({
      tag: c.el.tagName.toLowerCase(),
      cls: (c.el.className || '').toString().slice(0, 70),
      left: Math.round(box.left), right: Math.round(vw - box.right),
      gutter: +gutter.toFixed(1),
      text: c.text.slice(0, 120),
    });
  }
  scrollTo(0, originalY);

  return JSON.stringify({
    viewport: vw,
    docWidth: document.documentElement.scrollWidth,
    widestTextWidth: Math.round(widest),
    widestFraction: +(widest / vw).toFixed(3),
    widestText,
    edgeTouch: edge,
    tightLeading: tight,
  });
}"""


SELF_CHECK_FIXTURES = """() => {
  // Two boxes that a correct rule must flag, appended to <main>:
  //   edge  — a body paragraph whose text starts 1px from the left edge;
  //   tight — a paragraph whose line boxes overlap (line-height 0.7);
  //   ok    — ordinary 1.6 leading, which must stay out of the report.
  // Without these the sweep can only ever report zero, and a criterion that
  // cannot fail is worth nothing (AGENTS.md §8).
  const host = document.createElement('section');
  host.style.cssText = 'width:100%;background:#141414;padding:0;margin:0';
  const edge = document.createElement('p');
  edge.id = 'md-space-fixture-edge';
  edge.style.cssText =
    'margin:0;padding:0 0 0 1px;width:auto;font-size:16px;line-height:1.6;color:#fff';
  edge.textContent =
    'This paragraph is deliberately one pixel from the left edge so the gutter rule has something to catch.';
  const tight = document.createElement('p');
  tight.id = 'md-space-fixture-tight';
  tight.style.cssText =
    'margin:24px 20px 0;font-size:16px;line-height:0.7;color:#fff;max-width:300px';
  tight.textContent =
    'These lines are set so tight that their boxes overlap, which the ink-gap rule has to catch.';
  // The control: ordinary body text at a comfortable leading. It must stay out
  // of the report, or the rule is just firing on every paragraph.
  const ok = document.createElement('p');
  ok.id = 'md-space-fixture-control';
  ok.style.cssText =
    'margin:24px 20px 0;font-size:16px;line-height:1.6;color:#fff;max-width:300px';
  ok.textContent =
    'This paragraph is set at an ordinary leading and wraps, and it must not be reported.';
  host.appendChild(edge);
  host.appendChild(tight);
  host.appendChild(ok);
  document.querySelector('main').appendChild(host);
  return true;
}"""


def self_check(page, probe: str, base: str) -> int:
    """Prove the gutter and ink-gap rules still discriminate."""
    page.goto(f"{base}/?mdaudit={int(time.time())}", wait_until="load", timeout=60000)
    page.evaluate(SELF_CHECK_FIXTURES)
    data = json.loads(page.evaluate(probe))
    edge_texts = " ".join(x["text"] for x in data.get("edgeTouch", []))
    tight_texts = " ".join(x["text"] for x in data.get("tightLeading", []))
    cases = [
        (
            "gutter",
            "deliberately one pixel from the left edge" in edge_texts,
            True,
            "a paragraph 1px from the edge",
        ),
        (
            "ink-gap",
            "their boxes overlap" in tight_texts,
            True,
            "two lines whose boxes overlap",
        ),
        (
            "control",
            "ordinary leading and wraps" in tight_texts,
            False,
            "ordinary 1.6 leading must stay out",
        ),
    ]
    failed = 0
    for name, got, want, why in cases:
        ok = got == want
        failed += 0 if ok else 1
        print(f"  {'ok  ' if ok else 'FAIL'} {name:8} caught={str(got):5} want={str(want):5}  {why}")
    return failed


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--width", type=int, default=390)
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--pages", nargs="+", default=[])
    parser.add_argument("--out", default="/tmp/md-space")
    parser.add_argument("--min-gutter", type=float, default=MIN_GUTTER)
    parser.add_argument("--min-leading", type=float, default=MIN_LEADING)
    parser.add_argument("--min-ink-gap", type=float, default=MIN_INK_GAP)
    parser.add_argument(
        "--self-check",
        action="store_true",
        help="plant a 1px-gutter and a cramped paragraph, then exit",
    )
    args = parser.parse_args()

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    probe = (
        PROBE.replace("%GUT%", repr(args.min_gutter))
        .replace("%LEAD%", repr(args.min_leading))
        .replace("%DISP%", repr(DISPLAY_SIZE_PX))
        .replace("%GAP%", repr(args.min_ink_gap))
    )

    results = []
    with sync_playwright() as play:
        browser = play.chromium.launch(channel="chrome", headless=False)
        context = browser.new_context(
            viewport={"width": args.width, "height": 844},
            device_scale_factor=2,
        )
        page = context.new_page()
        if args.self_check:
            print(f"spacing self-check at {args.width}px")
            failed = self_check(page, probe, args.base)
            context.close()
            browser.close()
            reclaim_profiles()
            print("self-check FAILED" if failed else "self-check passed")
            return 1 if failed else 0

        for path in args.pages:
            url = f"{args.base}{path}?mdaudit={int(time.time())}"
            entry: dict = {"path": path}
            try:
                page.goto(url, wait_until="load", timeout=60000)
                page.evaluate(
                    "() => new Promise(d => { let y = 0; const s = () => {"
                    " y += innerHeight; scrollTo(0, y);"
                    " if (y < document.documentElement.scrollHeight)"
                    " setTimeout(s, 50); else { scrollTo(0, 0);"
                    " setTimeout(d, 250); } }; s(); })"
                )
                entry["innerWidth"] = page.evaluate("() => innerWidth")
                entry.update(json.loads(page.evaluate(probe)))
            except Exception as exc:  # noqa: BLE001 - a page that fails is data
                entry["error"] = str(exc)
            results.append(entry)
            print(
                f"{path:46} iw={entry.get('innerWidth', '?'):>4} "
                f"edge={len(entry.get('edgeTouch', [])):>3} "
                f"tight={len(entry.get('tightLeading', [])):>3} "
                f"widest={entry.get('widestFraction', '?')}",
                flush=True,
            )
        context.close()
        browser.close()

    reclaim_profiles()
    (out / "spacing.json").write_text(json.dumps(results, indent=2), encoding="utf-8")
    print(f"\nwrote {out / 'spacing.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
