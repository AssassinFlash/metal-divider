#!/usr/bin/env python3
"""Find text whose colour does not separate from what sits behind it.

The site is dark, and its text is mostly white or a translucent white — so a
"is this readable" question is really "is this readable *on that band*". Two
mistakes made an earlier hand-run of this measurement useless, and both are
designed out here:

  * **Only `backgroundColor` was read.** A band painted with
    `background: linear-gradient(...)` keeps `backgroundColor: rgba(0,0,0,0)`,
    so the walk skipped it and reached a white ancestor: white text on white
    read as 1:1. The first sweep reported 110 problems; 106 of them were this.
  * **Gradient text was counted as low contrast.** A headline using
    `background-clip: text` with `-webkit-text-fill-color: transparent` takes
    its colour from a gradient, not from `color`. Those render bright; the
    computed `color` is meaningless for them.

So the backdrop walk accepts a gradient's first stop as paint, and any element
with `background-clip: text` is skipped and *reported as skipped* rather than
silently dropped.

The threshold is WCAG AA: 4.5:1 for body text, 3:1 for large text (24px, or
18.66px bold).

Real Chrome through Playwright, because the measurement needs computed styles
at a real viewport. Profile leftovers are reclaimed the same way the other
audits do it.

Usage:
    python3 tools/audit/contrast.py --width 1440
    python3 tools/audit/contrast.py --self-check
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))

from narrow_viewport import PAGES, reclaim_profiles  # noqa: E402

PROBE = r"""() => {
  // Chrome reports Tailwind 4's `oklch()` colours verbatim: `getComputedStyle`
  // and `ctx.fillStyle` both hand back the source string, and a regex over
  // `rgba?()` returns null for it. A null backdrop is not "no colour" — the
  // walk skips that layer and lands on an ancestor, which turned a light-green
  // button with dark-green text into "dark green on near-black, 1.24:1"
  // (measured 2026-10-29). Painting one pixel and reading it back is the only
  // route that gives real numbers, because it is the renderer doing the
  // conversion.
  const pixelCtx = (() => {
    const cv = document.createElement('canvas');
    cv.width = 1;
    cv.height = 1;
    return cv.getContext('2d', { willReadFrequently: true });
  })();
  const parse = (c) => {
    const text = String(c || '');
    const m = text.match(/rgba?\(([^)]+)\)/);
    if (m) {
      const p = m[1].split(',').map(Number);
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    if (!pixelCtx || !/oklch|oklab|color\(|hsl/.test(text)) return null;
    pixelCtx.clearRect(0, 0, 1, 1);
    pixelCtx.fillStyle = text;
    pixelCtx.fillRect(0, 0, 1, 1);
    const d = pixelCtx.getImageData(0, 0, 1, 1).data;
    if (0 === d[3]) return null;
    return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
  };
  const lum = ({ r, g, b }) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const blend = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1,
  });
  const firstStop = (img) => {
    const m = String(img || '').match(/rgba?\([^)]+\)/);
    return m ? parse(m[0]) : null;
  };
  // Walk outward and composite each translucent layer over the next one.
  // Returning only a fully opaque layer skipped a gold button painted at
  // `rgba(212,175,55,0.9)` and continued to a black gradient behind it, so its
  // black label read as 1:1 when it is really about 9:1 (measured 2026-10-29).
  const backdrop = (el) => {
    const layers = [];
    let n = el;
    while (n && n !== document.documentElement) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') {
        const g = firstStop(cs.backgroundImage);
        if (g) {
          layers.push(g);
          if (g.a >= 0.99) break;
        }
      }
      const c = parse(cs.backgroundColor);
      if (c && c.a > 0) {
        layers.push(c);
        if (c.a >= 0.99) break;
      }
      n = n.parentElement;
    }
    let base = { r: 20, g: 20, b: 20, a: 1 };
    const body = parse(getComputedStyle(document.body).backgroundColor);
    if (body && body.a >= 0.99) base = body;
    // Composite from the deepest layer outward.
    for (let i = layers.length - 1; i >= 0; i--) {
      const layer = layers[i];
      base = {
        r: layer.r * layer.a + base.r * (1 - layer.a),
        g: layer.g * layer.a + base.g * (1 - layer.a),
        b: layer.b * layer.a + base.b * (1 - layer.a),
        a: 1,
      };
    }
    return base;
  };

  const findings = [];
  let skippedGradientText = 0;
  // Header and footer carry text on every page and were never measured before
  // 2026-10-29. They are swept alongside <main> rather than instead of it.
  const zones = [
    ['main', 'main'],
    ['header', 'header, #lc-header, .site-header'],
    ['footer', 'footer, .site-footer'],
  ];
  const roots = [];
  for (const [zone, selector] of zones) {
    for (const root of document.querySelectorAll(selector)) {
      const rr = root.getBoundingClientRect();
      if (rr.width > 200 && rr.height > 40) roots.push([zone, root]);
    }
  }
  // A container's `color` is not a claim about any glyph. Reading it reported a
  // nav <li> as white-on-white 140 times when the only rendered text in it is a
  // black <a>; `innerText` concatenates descendants, so "container" and "text"
  // had collapsed into one node (measured 2026-10-29). Only elements holding
  // their own text node are measured.
  const ownText = (el) => {
    let out = '';
    for (const node of el.childNodes) {
      if (node.nodeType === 3) out += node.nodeValue;
    }
    return out.replace(/\s+/g, ' ').trim();
  };
  const effOpacity = (el) => {
    let op = 1;
    let n = el;
    while (n && n !== document.documentElement) {
      op *= parseFloat(getComputedStyle(n).opacity || '1');
      n = n.parentElement;
    }
    return op;
  };
  for (const [zone, root] of roots) {
  for (const el of root.querySelectorAll('*')) {
    const text = ownText(el);
    if (text.length < 4) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 20 || r.height < 6) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    // A decorative layer is meant to be faint. The manufacturing page carries a
    // 50.7px wordmark at 4% opacity as `pointer-events-none absolute -z-0`;
    // reporting it as unreadable text is the criterion mistaking decoration for
    // content (measured 2026-10-29). Same rule `defects.js` applies.
    if ('none' === cs.pointerEvents && el.getAttribute('aria-hidden') === 'true') continue;
    if ('none' === cs.pointerEvents && 'absolute' === cs.position) continue;
    if (effOpacity(el) < 0.5) continue;
    // Gradient-clipped text paints from its gradient; `color` does not describe it.
    const clip = cs.webkitBackgroundClip || cs.backgroundClip;
    const fill = cs.webkitTextFillColor || '';
    if (clip === 'text' || /rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/.test(fill)) {
      skippedGradientText += 1;
      continue;
    }
    let fg = parse(cs.color);
    if (!fg) continue;
    const bg = backdrop(el);
    if (fg.a < 1) fg = blend(fg, bg);
    const l1 = lum(fg);
    const l2 = lum(bg);
    const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const size = parseFloat(cs.fontSize);
    const bold = (parseInt(cs.fontWeight, 10) || 400) >= 700;
    const need = size >= 24 || (size >= 18.66 && bold) ? 3.0 : 4.5;
    if (ratio >= need) continue;
    findings.push({
      zone,
      text: text.slice(0, 44),
      cls: (el.className || '').toString().slice(0, 52),
      color: cs.color,
      backdrop: `rgb(${Math.round(bg.r)}, ${Math.round(bg.g)}, ${Math.round(bg.b)})`,
      ratio: +ratio.toFixed(2),
      need,
      size: +size.toFixed(1),
    });
  }
  }
  return JSON.stringify({ findings, skippedGradientText });
}"""

# Two paragraphs on a dark surface: one legible, one legible only to its author.
# A rule that cannot separate them is not measuring contrast.
FIXTURE = """() => {
  const host = document.createElement('section');
  host.style.cssText = 'background:rgb(18,18,18);padding:20px;';
  const ok = document.createElement('p');
  ok.id = 'md-contrast-ok';
  ok.style.cssText = 'color:rgb(255,255,255);font-size:16px;margin:0 0 12px;';
  ok.textContent = 'White words on a dark band: must not be reported.';
  const bad = document.createElement('p');
  bad.id = 'md-contrast-bad';
  bad.style.cssText = 'color:rgb(0,0,0);font-size:16px;margin:0;';
  bad.textContent = 'Black words on a dark band: must be reported.';
  host.appendChild(ok);
  host.appendChild(bad);
  document.querySelector('main').appendChild(host);
  return true;
}"""


def self_check(page, base: str) -> int:
    """Prove the rule separates legible text from invisible text."""
    page.goto(f"{base}/pattern-category/", wait_until="load", timeout=60000)
    page.evaluate(FIXTURE)
    data = json.loads(page.evaluate(PROBE))
    texts = " ".join(f["text"] for f in data["findings"])
    cases = [
        ("black-on-black", "Black words on a dark band" in texts, True),
        ("white-on-black", "White words on a dark band" in texts, False),
    ]
    failed = 0
    for name, got, want in cases:
        ok = got == want
        failed += 0 if ok else 1
        print(f"  {'ok  ' if ok else 'FAIL'} {name:16} reported={str(got):5} want={str(want):5}")
    return failed


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--width", type=int, default=1440)
    parser.add_argument("--base", default="https://metal-divider.com")
    parser.add_argument("--pages", nargs="+", default=[p for _, p in PAGES])
    parser.add_argument("--out", default="/tmp/md-contrast")
    parser.add_argument("--self-check", action="store_true")
    args = parser.parse_args()

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    with sync_playwright() as play:
        browser = play.chromium.launch(channel="chrome", headless=False)
        context = browser.new_context(
            viewport={"width": args.width, "height": 920}, device_scale_factor=1
        )
        page = context.new_page()

        if args.self_check:
            print(f"contrast self-check at {args.base}")
            failed = self_check(page, args.base)
            context.close()
            browser.close()
            reclaim_profiles()
            print("self-check FAILED" if failed else "self-check passed")
            return 1 if failed else 0

        results = []
        for path in args.pages:
            url = f"{args.base}{path}?mdaudit={__import__('time').time_ns()}"
            entry: dict = {"path": path}
            try:
                page.goto(url, wait_until="load", timeout=60000)
                page.evaluate(
                    "() => new Promise(d => { let y = 0; const s = () => {"
                    " y += innerHeight; scrollTo(0, y);"
                    " if (y < document.documentElement.scrollHeight)"
                    " setTimeout(s, 40); else { scrollTo(0, 0);"
                    " setTimeout(d, 250); } }; s(); })"
                )
                # Record the width the page actually laid out at. Without it a
                # report cannot prove which viewport produced it, and a stale
                # file from another width looks perfectly valid (measured
                # 2026-10-29: the 320 report was byte-identical to the 390 one).
                entry["innerWidth"] = page.evaluate("() => innerWidth")
                data = json.loads(page.evaluate(PROBE))
                entry.update(data)
            except Exception as exc:  # noqa: BLE001 - a page that fails is data
                entry["error"] = str(exc)
            results.append(entry)
            n = len(entry.get("findings", []))
            mark = "" if n == 0 else f"  {n} low-contrast"
            print(f"{path:28} {entry.get('skippedGradientText', '?'):>3} skipped-gradient {mark}", flush=True)
        context.close()
        browser.close()

    reclaim_profiles()
    (out / "contrast.json").write_text(json.dumps(results, indent=2), encoding="utf-8")

    # A shared footer repeats on every page. Counting it once per page turned 4
    # real footer items into 128 "findings" (32 pages x 4) and buried the 45
    # that were actually in page bodies (measured 2026-10-29). Report distinct
    # problems, and say how many pages each one appears on.
    distinct: dict[tuple, dict] = {}
    for entry in results:
        for f in entry.get("findings", []):
            key = (f.get("zone"), f.get("color"), f.get("backdrop"), f.get("size"), f.get("cls"), f.get("text"))
            if key not in distinct:
                distinct[key] = dict(f, pages=[])
            distinct[key]["pages"].append(entry["path"])

    total = sum(len(e.get("findings", [])) for e in results)
    print(f"\n{total} run(s) across {len(results)} page(s); {len(distinct)} distinct problem(s)")
    for d in sorted(distinct.values(), key=lambda x: -len(x["pages"]))[:12]:
        where = f"{len(d['pages'])} page(s)" if len(d["pages"]) > 1 else d["pages"][0]
        print(f"  [{d.get('zone')}] {d['ratio']}/{d['need']} {d['size']}px {d['color'][:26]} {d.get('text','')[:26]}  {where}")
    print(f"wrote {out / 'contrast.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
