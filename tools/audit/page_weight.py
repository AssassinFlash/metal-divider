#!/usr/bin/env python3
"""Break a page's weight down per resource, at a real 1440 viewport.

`perf.py` answers "how heavy is this page". This answers "what is the weight
made of, and is any of it wasted". The distinction matters: the home page grew
from 690 KB to ~3.4 MB, and the useful question is not the total but which
images are delivered larger than the space they occupy.

An image is reported as over-delivered when its intrinsic width exceeds twice
its rendered width. Twice, because a 2x display needs two device pixels per CSS
pixel; beyond that the extra bytes buy nothing a viewer can see on this site's
densities. The criterion is the *rendered* width, not the `sizes` attribute —
a `sizes` value that misstates the slot produces exactly this symptom, and
reading the attribute back would only confirm its own claim.

Drives the owner's Chrome through opencli, never Playwright (AGENTS §7).

    python3 tools/audit/page_weight.py /            # one page
    python3 tools/audit/page_weight.py / /patterns/     # several
"""

from __future__ import annotations

import json
import subprocess
import sys
import time

OPENCLI = "opencli"
WINDOW_WIDTH = 1496  # outer; measured pair 1496 -> 1440 inner on this Mac

# Page commands (eval, open, screenshot) require --session; the chrome subcommands
# reject it. Keeping the name here lets `run` append it exactly where perf.py does.
SESSION: dict[str, str] = {}

PROBE = """(() => {
  const res = performance.getEntriesByType('resource');
  const images = [...document.querySelectorAll('img')];
  const slotOf = (src) => {
    const hit = images.find((i) => i.currentSrc && i.currentSrc === src);
    if (!hit) return null;
    const r = hit.getBoundingClientRect();
    return { w: Math.round(r.width), h: Math.round(r.height),
             nw: hit.naturalWidth, nh: hit.naturalHeight,
             loading: hit.loading, sizes: hit.getAttribute('sizes') || '' };
  };
  const rows = res.map((e) => {
    const bytes = e.transferSize || e.encodedBodySize || 0;
    const row = { name: e.name.split('/').pop().split('?')[0],
                  kb: Math.round(bytes / 1024), type: e.initiatorType,
                  decoded: Math.round((e.decodedBodySize || 0) / 1024) };
    if (e.initiatorType === 'img') Object.assign(row, { slot: slotOf(e.name) });
    return row;
  });
  rows.sort((a, b) => b.kb - a.kb);
  const total = rows.reduce((a, r) => a + r.kb, 0);
  return JSON.stringify({
    viewport: window.innerWidth, dpr: devicePixelRatio,
    requests: rows.length, totalKB: total,
    images: rows.filter((r) => r.type === 'img').length,
    rows,
  });
})()"""


def run(args: list[str], timeout: int = 180) -> dict:
    command = [OPENCLI, "browser", *args]
    # `chrome` manages windows/tabs and rejects --session; page commands require it.
    if SESSION and args and args[0] != "chrome" and not any(a == "--session" for a in args):
        command += ["--session", SESSION["name"]]
    proc = subprocess.run(command, capture_output=True, text=True, timeout=timeout)
    for line in reversed(proc.stdout.strip().splitlines()):
        try:
            parsed = json.loads(line)
        except json.JSONDecodeError:
            continue
        return parsed if isinstance(parsed, dict) else {"ok": False, "raw": line[:300]}
    return {"ok": False, "raw": (proc.stdout + proc.stderr)[:300]}


def measure(page: str, session: str) -> dict | None:
    """Open one page in the bound window and read its resource table."""
    url = f"https://metal-divider.com{page}?mdaudit=w{int(time.time() * 1000)}"
    run(["open", url, "--window", "background"])
    for _ in range(40):
        if run(["eval", "document.readyState === 'complete'"]).get("data") is True:
            break
        time.sleep(0.2)
    # Lazy images settle after load; one second matched perf.py's own wait.
    time.sleep(1.2)
    data = run(["eval", PROBE]).get("data")
    if not isinstance(data, str):
        # `open` can land the page in another window when the profile already
        # holds several tabs, leaving this session pointed at a blank target.
        # Rebinding and reading again is the reliable recovery; a second miss
        # is a real failure rather than something to retry indefinitely.
        run(["bind", "--session", session, "--window", "background"])
        data = run(["eval", PROBE]).get("data")
    if not isinstance(data, str):
        print(f"  {page}: no probe result ({data})", file=sys.stderr)
        return None
    parsed = json.loads(data)
    if parsed.get("viewport") != 1440:
        # A narrow window silently changes which srcset tier the browser
        # picks, so a measurement taken there is not the one being reported.
        print(f"  {page}: ABORT innerWidth={parsed.get('viewport')} (want 1440)", file=sys.stderr)
        return None
    return parsed


def main() -> int:
    pages = sys.argv[1:] or ["/"]
    session = f"mdweight{int(time.time())}"
    SESSION["name"] = session
    first = f"https://metal-divider.com{pages[0]}?mdaudit=w{int(time.time() * 1000)}"
    created = run(
        [
            "chrome",
            "call",
            "windows.create",
            json.dumps(
                [{"url": first, "width": WINDOW_WIDTH, "height": 920, "left": 0, "top": 34, "focused": True}]
            ),
        ]
    )
    if not created.get("ok"):
        print(f"could not create a window: {created}", file=sys.stderr)
        return 2
    window_id = created["data"]["id"]
    tab_id = (created["data"].get("tabs") or [{}])[0].get("id")
    run(["chrome", "call", "tabs.update", json.dumps([tab_id, {"active": True}])])
    run(["chrome", "call", "windows.update", json.dumps([window_id, {"focused": True}])])
    if not run(["bind", "--session", session, "--window", "background"]).get("ok"):
        print("could not bind", file=sys.stderr)
        return 2

    exit_code = 0
    try:
        for page in pages:
            data = measure(page, session)
            if data is None:
                exit_code = 1
                continue
            print(
                f"\n== {page}  {data['totalKB']} KB / {data['requests']} requests "
                f"/ {data['images']} images  (dpr {data['dpr']})"
            )
            over = []
            for row in data["rows"]:
                slot = row.get("slot")
                if not slot or not slot["w"]:
                    continue
                # An oversized delivery is one where the file carries more
                # pixels than twice the slot can show.
                if slot["nw"] > slot["w"] * 2:
                    over.append((row, slot))
            if over:
                print("   over-delivered (intrinsic width > 2x rendered width):")
                for row, slot in sorted(over, key=lambda t: -t[0]["kb"]):
                    ratio = slot["nw"] / slot["w"]
                    print(
                        f"     {row['kb']:>5} KB  {row['name'][:52]:<52} "
                        f"{slot['nw']}px in {slot['w']}px slot ({ratio:.1f}x)"
                    )
            else:
                print("   no over-delivered images")
            print("   heaviest:")
            for row in data["rows"][:8]:
                print(f"     {row['kb']:>5} KB  {row['type']:<7} {row['name'][:58]}")
    finally:
        run(["close", "--session", session])
        run(["chrome", "call", "windows.remove", json.dumps([window_id])])
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
