#!/usr/bin/env python3
"""Find text that sits flush against the inner edge of a panel.

The defect the owner photographed on 2026-10-30: "FAQs & Download" printed
against the left edge of its dropdown panel, while every other row carried a
15px gutter. The existing sweeps could not see it: `spacing.py` measures text
against the *viewport* edge, and this panel hangs in the middle of the page.
The class is real and repeatable — any card, dropdown, modal or dock can hold
text with no air between the text and its container's edge.

A panel here is a visible box that *looks* like a container: border-radius or a
box-shadow, at least 150px wide. The finding is a text-bearing element whose
box starts (or ends) less than MIN_INSET from that panel's edge, counted only
when the panel itself supplies no padding on that side — a padded panel already
gives its rows air, and the screenshot defect was precisely a panel whose rows
lost their padding.

Deliberately not flagged:
  - elements with their own background (chips, badges, buttons): they define
    their own air, and their edge is their own, not the panel's;
  - anything inside a horizontally scrollable strip (marquees, chip rails):
    their gutters move, `spacing.py` already excludes them for the same reason;
  - full-bleed media boxes: no direct text, so not text that "needs air".

Self-check: a fixture panel with a deliberately flush row must be reported, a
padded row on the same panel must not be — a criterion that cannot fail is
worth nothing (AGENTS.md §0.1).

Usage:
    python3 tools/audit/panel_flush.py --width 1440 --pages / /faqs/
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from pathlib import Path

from playwright.sync_api import sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))

from narrow_viewport import reclaim_profiles

# Below 2px the text is touching the panel edge — the photographed defect was
# 0px where the sibling rows sat at 15px. Above it, whatever air exists is a
# choice, and this sweep has no opinion about choices.
MIN_INSET = 2.0

# The force-open styles are applied inline so closed panels (dropdowns waiting
# for hover, the search overlay waiting for a click) can be measured without
# scripting each trigger. Geometry is what this sweep reads; the interaction
# itself is judged by interactions.js elsewhere.
FORCE_OPEN = """() => {
  let opened = 0;
  const reveal = (el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    el.style.opacity = '1';
    el.style.visibility = 'visible';
    el.style.pointerEvents = 'auto';
    el.style.transform = 'none';
    opened += 1;
    return true;
  };
  document
    .querySelectorAll('.xo-menu-horizontal-v1__sub-menu')
    .forEach(reveal);
  document
    .querySelectorAll(
      '[class*=predictive-search] dialog, dialog[class*=search], .xo-modal[xo-name="search"]'
    )
    .forEach((el) => {
      el.style.display = 'block';
      reveal(el);
    });
  return opened;
}"""

PROBE = r"""() => {
  const MIN_INSET = %INSET%;
  const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
  const shown = (el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') return false;
    if (parseFloat(cs.opacity) < 0.05) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const ownBackground = (el) => {
    const cs = getComputedStyle(el);
    const bg = cs.backgroundColor;
    const hasColor = bg && !/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/.test(bg);
    const hasImage = cs.backgroundImage && cs.backgroundImage !== 'none';
    return hasColor || hasImage;
  };
  const inScrollStrip = (el) => {
    for (let p = el.parentElement; p; p = p.parentElement) {
      const cs = getComputedStyle(p);
      if (/auto|scroll/.test(cs.overflowX) && p.scrollWidth > p.clientWidth + 1) {
        return true;
      }
    }
    return false;
  };
  const directText = (el) =>
    norm([...el.childNodes]
      .filter((n) => n.nodeType === 3)
      .map((n) => n.textContent)
      .join(' ')).length > 2;
  // Measure the *text line boxes*, not the element box: a block anchor with
  // 15px padding still has its border box flush with the panel, but its text
  // has air. The photographed defect was text at 0px; padding the element
  // fixes it, so padding must move the measurement.
  const textRect = (el) => {
    const range = document.createRange();
    range.selectNodeContents(el);
    const rects = [...range.getClientRects()].filter((r) => r.width > 0 && r.height > 0);
    if (!rects.length) return null;
    return {
      left: Math.min(...rects.map((r) => r.left)),
      right: Math.max(...rects.map((r) => r.right)),
    };
  };

  const panels = [...document.querySelectorAll('body *')].filter((el) => {
    if (!shown(el)) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 150 || r.height < 40) return false;
    const cs = getComputedStyle(el);
    const radius = parseFloat(cs.borderTopLeftRadius) || 0;
    const shadow = cs.boxShadow && cs.boxShadow !== 'none';
    return radius > 0 || shadow;
  });

  const findings = [];
  for (const panel of panels) {
    const pr = panel.getBoundingClientRect();
    const pcs = getComputedStyle(panel);
    const padL = parseFloat(pcs.paddingLeft) || 0;
    const padR = parseFloat(pcs.paddingRight) || 0;
    for (const el of panel.querySelectorAll('*')) {
      if (!shown(el) || !directText(el) || ownBackground(el)) continue;
      if (inScrollStrip(el)) continue;
      const tr = textRect(el);
      if (!tr) continue;
      // Text entirely outside the panel's own box (e.g. the honeypot row,
      // parked off-screen at left:-10000px by design) is not panel content.
      if (tr.right <= pr.left || tr.left >= pr.right) continue;
      const label = {
        text: norm(el.textContent).slice(0, 60),
        panel: norm(panel.className).slice(0, 60),
      };
      const insetL = tr.left - pr.left;
      if (insetL < MIN_INSET && padL < MIN_INSET) {
        findings.push({ type: 'panel-flush-left', inset: Math.round(insetL * 10) / 10, ...label });
      }
      const insetR = pr.right - tr.right;
      if (insetR < MIN_INSET && padR < MIN_INSET) {
        findings.push({ type: 'panel-flush-right', inset: Math.round(insetR * 10) / 10, ...label });
      }
    }
  }
  // Nested panels report the same text twice (row panel + outer panel); keep
  // one finding per (side, text).
  const seen = new Set();
  return findings.filter((f) => {
    const key = f.type + '|' + f.text;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
}"""

SELF_CHECK_FIXTURES = """() => {
  // flush  — a radius panel whose first row has no padding: must be reported;
  // padded — the same panel's second row with 15px: must stay out;
  // chip   — a flush row with its own background: must stay out (own air).
  const host = document.createElement('div');
  host.style.cssText =
    'position:fixed;top:0;left:0;width:300px;background:#fff;border-radius:8px;box-shadow:0 5px 15px rgba(0,0,0,.07);padding:0;z-index:99999';
  const flush = document.createElement('a');
  flush.id = 'md-pf-fixture-flush';
  flush.style.cssText = 'display:block;padding:10px 0;font-size:15px;color:#000';
  flush.textContent = 'Fixture row flush against the panel edge for the audit to catch.';
  const padded = document.createElement('a');
  padded.id = 'md-pf-fixture-padded';
  padded.style.cssText = 'display:block;padding:10px 15px;font-size:15px;color:#000';
  padded.textContent = 'Fixture row with its own padding must not be reported.';
  const chip = document.createElement('a');
  chip.id = 'md-pf-fixture-chip';
  chip.style.cssText =
    'display:block;padding:10px 0;font-size:15px;color:#fff;background:#141414';
  chip.textContent = 'Fixture row on its own background must not be reported.';
  host.append(flush, padded, chip);
  document.body.appendChild(host);
  return true;
}"""


def self_check(page, probe: str, base: str) -> int:
    """Prove the flush rule discriminates: fixture flush row in, padded and
    own-background rows out."""
    page.goto(f"{base}/?mdaudit={int(time.time())}", wait_until="load", timeout=60000)
    page.evaluate(FORCE_OPEN)
    page.evaluate(SELF_CHECK_FIXTURES)
    findings = page.evaluate(probe)
    texts = " ".join(f["text"] for f in findings)

    cases = [
        (
            "flush-row",
            "flush against the panel edge" in texts,
            True,
            "a row 0px from its panel edge",
        ),
        (
            "padded-row",
            "own padding must not be reported" in texts,
            False,
            "a 15px-padded sibling row",
        ),
        (
            "own-background",
            "own background must not be reported" in texts,
            False,
            "a flush row sitting on its own background",
        ),
    ]
    failed = 0
    for name, ok, expected, why in cases:
        if ok != expected:
            failed += 1
            print(
                f"SELF-CHECK FAIL [{name}]: expected {'hit' if expected else 'miss'}, got {'hit' if ok else 'miss'} — {why}"
            )
        else:
            print(f"self-check ok [{name}]")
    return failed


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="https://metal-divider.com")
    ap.add_argument("--width", type=int, default=1440)
    ap.add_argument(
        "--pages",
        nargs="+",
        default=["/", "/patterns/", "/faqs/", "/inquiry/", "/pattern-category/"],
    )
    ap.add_argument("--self-check", action="store_true")
    args = ap.parse_args()

    reclaim_profiles()
    failures = 0
    with sync_playwright() as p:
        browser = p.chromium.launch(channel="chrome", headless=False)
        page = browser.new_page(viewport={"width": args.width, "height": 900})

        if args.self_check:
            failures += self_check(
                page, PROBE.replace("%INSET%", str(MIN_INSET)), args.base
            )
            browser.close()
            return 1 if failures else 0

        report = {}
        for path in args.pages:
            url = f"{args.base}{path}"
            try:
                page.goto(
                    f"{url}?mdaudit={int(time.time())}",
                    wait_until="load",
                    timeout=60000,
                )
                page.wait_for_timeout(1500)
                page.evaluate(FORCE_OPEN)
                page.wait_for_timeout(300)
                findings = page.evaluate(PROBE.replace("%INSET%", str(MIN_INSET)))
                report[path] = findings
                if findings:
                    failures += len(findings)
                    for f in findings:
                        print(
                            f"[{path}] {f['type']} inset={f['inset']}px panel={f['panel']!r} text={f['text']!r}"
                        )
                else:
                    print(f"[{path}] clean")
            except Exception as exc:  # noqa: BLE001 — one page failing must not stop the sweep
                print(f"[{path}] ERROR {exc}")
                failures += 1

        browser.close()

    out = Path(f"/tmp/md-panel-flush-{args.width}.json")
    out.write_text(json.dumps(report, indent=2))
    print(f"report: {out}  findings: {failures}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
