#!/usr/bin/env python3
"""Find elements that lie about being clickable, and those that lie about not.

GOAL.md names this as a defect class: "视觉误导:看起来能点的东西必须能点;能点的
东西要有点的样子". A `cursor: pointer` on a `div` with no handler is a promise the
page cannot keep; an `<a>` that renders like body text is a control nobody
finds.

The check has to run in the browser because both halves are computed style,
and it has to run at the *real* target width because a filter row that is a
real control at 1440 can be a hidden clone at 390.

Three findings, each of which a reviewer can act on:

  dead-affordance   `cursor: pointer` with no link, no button role, and no
                    event listener — clicking does nothing.
  flat-control      a real link or button whose computed cursor is NOT pointer
                    (outside a disabled/aria-disabled context).
  tiny-target       an interactive element smaller than 44x44 CSS px, which is
                    the project's own hit-area floor.

What it deliberately does not flag: `<label>` (pointer cursor is correct and
the browser forwards the click), `aria-disabled` / `disabled` controls (flat
cursor is the intended signal), and anything inside a `[hidden]` or
zero-size ancestor.

Usage:
    python3 tools/audit/affordance.py --width 1440 --pages / /
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time

OPENCLI = "opencli"
CHROME_MIN_WIDTH = 578

DEFAULT_PAGES = [
    "/", "/collections/", "/shop/", "/faqs/", "/inquiry/", "/about-us/",
    "/manufacturing-process/", "/project-gallery/", "/blog/",
    "/laser-cut-geometric-metal-screens/", "/perforated-panels/",
    "/custom-metal-divider-screens/",
    "/pattern/agave-architectural-metal-screen-pattern/",
    "/collections/pattern-abstract/",
]

# The project's own floor, from GOAL.md's 320px audit: a tap target is 44px.
MIN_TARGET = 44


# Set once the session exists, then appended to every page command. Without it
# `eval` has no session to run in and answers `None`, which the caller reads as
# "wrong viewport" and skips every page — a silent total failure.
SESSION: dict[str, str] = {}


def run(args: list[str], timeout: int = 240) -> dict:
    command = [OPENCLI, "browser", *args]
    if SESSION and not any(a == "--session" for a in args):
        command += ["--session", SESSION["name"]]
    try:
        completed = subprocess.run(
            command, capture_output=True, text=True, timeout=timeout
        )
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "timeout"}
    for line in reversed(completed.stdout.strip().splitlines()):
        try:
            return json.loads(line)
        except json.JSONDecodeError:
            continue
    return {"ok": False, "error": completed.stderr[-200:] or "no json"}


def audit_js(min_target: int) -> str:
    """The three questions, answered from computed style plus the DOM.

    The listener check is the only weak part: `getEventListeners` is a DevTools
    API that page script cannot reach, so "has a handler" is inferred from the
    markup the site uses for interactivity (role, data-* hooks, href, onclick).
    That is stated in the output so a reader does not over-trust it.
    """
    return """(() => {
  const MIN = %d;
  const out = { iw: window.innerWidth, dead: [], flat: [], tiny: [],
                scanned: 0, notes: [] };
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return null;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || cs.opacity === '0') return null;
    // A closed dialog is present in the DOM with real boxes but is not
    // reachable: its own `aria-hidden="true"` / `inert` says so. Measuring the
    // buttons inside reported the lightbox arrows at 42px — they are 44px when
    // the dialog is actually open, and when it is closed nobody can press them.
    if (el.closest('[aria-hidden="true"], [inert]')) return null;
    return r;
  };
  const name = (el) => {
    const cls = (el.className || '').toString().split(' ').filter(Boolean).slice(0, 2).join('.');
    const txt = (el.textContent || '').trim().slice(0, 28).replace(/\\s+/g, ' ');
    return (el.tagName.toLowerCase() + (cls ? '.' + cls : '') + (txt ? ' "' + txt + '"' : '')).slice(0, 78);
  };
  const disabledish = (el) => {
    if (el.disabled) return true;
    if (el.getAttribute('aria-disabled') === 'true') return true;
    if (el.closest('[aria-disabled="true"], [disabled], fieldset[disabled]')) return true;
    return false;
  };

  // The WordPress admin bar is chrome for the logged-in editor, not the site.
  // On a logged-in dev instance it produced 195 of 195 "tiny targets" and
  // many "dead" ones, burying the real findings.
  const inAdminBar = (el) => !!el.closest('#wpadminbar, .ab-top-menu');

  // A background tab freezes CSS transitions at currentTime 0, so anything
  // whose size comes from an opening animation measures its start state. Force
  // every animation to its end before reading boxes; without this, a lightbox
  // dialog measured 864 of its eventual 900 and its buttons 42 of 44.
  try { document.getAnimations().forEach(a => { try { a.finish(); } catch (e) {} }); } catch (e) {}

  const all = document.querySelectorAll('body *');
  for (const el of all) {
    if (inAdminBar(el)) continue;
    const r = visible(el);
    if (!r) continue;
    out.scanned++;
    const tag = el.tagName.toLowerCase();
    const cs = getComputedStyle(el);
    const pointer = cs.cursor === 'pointer';
    const isLink = tag === 'a' && el.hasAttribute('href');
    const isControl = isLink || tag === 'button' || tag === 'summary' ||
                      el.getAttribute('role') === 'button' ||
                      el.getAttribute('role') === 'link' ||
                      el.getAttribute('role') === 'tab' ||
                      tag === 'input' || tag === 'select' || tag === 'textarea';
    if (tag === 'label') continue;
    if (disabledish(el)) continue;

    const hooks = ['data-pattern-preview','data-pattern-close','data-pattern-prev','data-pattern-next',
                   'data-sps-trigger','data-preview','data-lightbox','onclick','@click','x-on:click'];
    const hasHook = hooks.some(h => el.hasAttribute(h) || el.getAttribute(h) != null);

    // A child of a link or button inherits `cursor: pointer` and clicking it
    // does navigate — the browser targets the ancestor. Reporting those found
    // 60 "dead" affordances on three pages, almost all of them an <img> or
    // <h3> inside a route card. Only an element that is neither a control nor
    // inside one, and has no hook of its own, is actually dead.
    // "Is this element part of an interactive region" has a third form the
    // first version missed: a wrapper div carries `cursor: pointer` and the
    // click handler, and every child inherits the cursor. The FAQ accordion
    // and the pattern-family accordion both work that way, and they produced
    // 66 false "dead" findings. An ancestor with pointer AND a hook, or with
    // pointer beyond the body, makes the element part of that region.
    const controlSelector = 'a[href], button, summary, [role="button"], [role="link"], [role="tab"]';
    const insideControl = !!el.closest(controlSelector);
    let pointerAncestor = false;
    if (!insideControl && el !== document.body) {
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
        if (getComputedStyle(p).cursor === 'pointer') { pointerAncestor = true; break; }
      }
    }
    if (pointer && !isControl && !hasHook && !insideControl && !pointerAncestor) {
      // Candidate. Confirm by clicking it: the project cards respond through a
      // delegated listener, so the markup heuristic alone called nine of them
      // dead when clicking one does open the lightbox.
      // Signatures have to be taken from a closed state, or the second card on
      // a page reads "no change" simply because the first card already opened
      // the lightbox. Measured on the gallery: card 0 went hidden -> shown,
      // cards 1-4 showed shown -> shown and were reported dead.
      const close = () => {
        for (const btn of document.querySelectorAll('[class*=close],[data-close]')) {
          const box = btn.closest('[class*=lightbox],[class*=modal]');
          if (box) { try { btn.click(); } catch (e) { /* ignore */ } }
        }
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      };
      const signature = () => {
        const boxes = [...document.querySelectorAll('[class*=lightbox],[class*=modal]')];
        const shown = boxes.filter(b => {
          const s = getComputedStyle(b);
          return s.display !== 'none' && s.visibility !== 'hidden' && s.opacity !== '0';
        }).length;
        return [shown, document.body.scrollHeight, location.hash].join(':');
      };
      close();
      const before = signature();
      try { el.click(); } catch (e) { /* a throwing handler is still a handler */ }
      const after = signature();
      close();
      if (before === after) {
        out.dead.push({ el: name(el), w: Math.round(r.width), h: Math.round(r.height),
                        tabindex: el.getAttribute('tabindex'), role: el.getAttribute('role') });
      } else {
        out.responsive = (out.responsive || 0) + 1;
      }
    }
    // Text fields show an I-beam on purpose — that IS the affordance for a
    // field you type into. Flagging them as "flat" was reporting the design.
    const textField = tag === 'input' || tag === 'textarea' || tag === 'select';
    if (isControl && !pointer && !textField && r.width > 20 && r.height > 20) {
      out.flat.push({ el: name(el), cursor: cs.cursor, w: Math.round(r.width), h: Math.round(r.height) });
    }
    if (isControl && (r.width < MIN || r.height < MIN)) {
      out.tiny.push({ el: name(el), w: Math.round(r.width), h: Math.round(r.height) });
    }
  }
  out.dead = out.dead.slice(0, 20);
  out.flat = out.flat.slice(0, 20);
  out.tiny = out.tiny.slice(0, 20);
  out.notes.push('handler inference is markup-based; getEventListeners is unavailable to page script');
  return out;
})()""" % min_target


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:9890")
    ap.add_argument("--width", type=int, default=1440)
    ap.add_argument("--pages", nargs="*", default=DEFAULT_PAGES)
    ap.add_argument("--json")
    args = ap.parse_args()

    if args.width < CHROME_MIN_WIDTH:
        print(f"{args.width}px is below Chrome's window minimum; use a capture instead.", file=sys.stderr)
        return 2

    session = f"mdaf{int(time.time())}"
    # Create the window with the first page already open. `bind` attaches to
    # Chrome's active tab, and a session bound to about:blank then navigated by
    # `open` ends up in whichever window Chrome considers active — measured:
    # `innerWidth` came back 701 or None instead of 1440. Binding to a window
    # that was created at the target size keeps it there.
    # The first page needs the buster too. Created without it, the window loaded
    # a cached document — one that referenced an older tailwind.css?ver — and the
    # audit reported 14 flat controls on the home page where a busted load finds
    # none. Later pages already carried one, which is why only page one lied.
    first = args.pages[0] if args.pages else "/"
    first_url = f"{args.base}{first}" + ("&" if "?" in first else "?") + f"mdaudit=af{int(time.time())}"
    created = run(["chrome", "call", "windows.create", json.dumps(
        [{"url": first_url, "width": args.width + 56, "height": 920,
          "left": 0, "top": 34, "focused": True}])])
    if not created.get("ok"):
        print(f"could not create a window: {created}", file=sys.stderr)
        return 2
    window_id = created["data"]["id"]
    tab_id = (created["data"].get("tabs") or [{}])[0].get("id")
    run(["chrome", "call", "tabs.update", json.dumps([tab_id, {"active": True}])])
    run(["chrome", "call", "windows.update", json.dumps([window_id, {"focused": True}])])
    SESSION["name"] = session
    if not run(["bind", "--session", session, "--window", "background"]).get("ok"):
        print("could not bind", file=sys.stderr)
        return 2

    results = []
    totals = {"dead": 0, "flat": 0, "tiny": 0}
    try:
        for path in args.pages:
            # The first page is already loaded; later ones navigate through the
            # bound session, which keeps the window.
            if path != first:
                run(["open", f"{args.base}{path}?mdaudit=af{int(time.time())}",
                     "--window", "background"])
            width_now = None
            for _ in range(40):
                width_now = run(["eval", "window.innerWidth"]).get("data")
                if isinstance(width_now, int):
                    break
                time.sleep(0.25)
            if width_now != args.width:
                print(f"{path:52} SKIP — viewport {width_now}")
                continue
            # Wait until the stylesheet has actually applied. Without this the
            # audit ran against a page whose layout was computed but whose
            # cursor values were still the user-agent defaults, and reported 14
            # flat controls on the home page where a settled read finds one
            # (a carousel arrow that is legitimately disabled).
            for _ in range(40):
                ready = run(["eval",
                             "document.readyState === 'complete' && document.styleSheets.length > 0"
                             " && getComputedStyle(document.body).backgroundColor !== 'rgba(0, 0, 0, 0)'"
                             ]).get("data")
                if ready is True:
                    break
                time.sleep(0.25)
            data = run(["eval", audit_js(MIN_TARGET)]).get("data") or {}
            dead = data.get("dead") or []
            flat = data.get("flat") or []
            tiny = data.get("tiny") or []
            totals["dead"] += len(dead)
            totals["flat"] += len(flat)
            totals["tiny"] += len(tiny)
            print(f"\n{path}   scanned={data.get('scanned')}  "
                  f"dead={len(dead)} flat={len(flat)} tiny={len(tiny)}")
            for item in dead:
                print(f"   DEAD  {item['w']}x{item['h']}  {item['el']}")
            for item in flat:
                print(f"   FLAT  cursor={item['cursor']:9} {item['w']}x{item['h']}  {item['el']}")
            for item in tiny:
                print(f"   TINY  {item['w']}x{item['h']}  {item['el']}")
            results.append({"path": path, **data})
    finally:
        run(["close", "--session", session])
        run(["chrome", "call", "windows.remove", json.dumps([window_id])])

    print(f"\n{totals['dead']} dead, {totals['flat']} flat, {totals['tiny']} tiny across {len(results)} pages")
    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump(results, handle, indent=2)
        print(f"wrote {args.json}")
    return 1 if any(totals.values()) else 0


if __name__ == "__main__":
    sys.exit(main())
