#!/usr/bin/env python3
"""Capture one page at one viewport, deterministically, through opencli.

This is the opencli replacement for the Playwright capture library the pixel
gate was built on. The gate compares screenshots byte for byte, so every step
that made the old captures reproducible is carried over — dropping one turns
the comparison into noise:

  * animations paused and transitions off (KILL_CSS), or a hovering element
    lands in a different frame on each run;
  * fonts settled (`document.fonts.ready`), or text renders in a fallback face;
  * the cookie banner accepted, or it covers the footer;
  * a lazy-load scroll pass, or every below-fold photograph is missing;
  * every `<img>` complete — `networkidle` is NOT used because this site's
    analytics hold sockets open forever, so it would never fire;
  * WindPress's client-side Tailwind compile finished (the injected `<style>`
    bytes exceed a threshold), or the capture is an unstyled page. That wait is
    the one that matters most here: Tailwind v4 has no server-side build, so
    styles arrive after DOM load.

Two thresholds are this site's own, measured rather than inherited. The style
floor is 20,000 bytes: the home page's 26 inline style blocks total 32,097 when
compiled, and the 50,000 the gateway blueprint used would never be reached
here. And "every image complete" excludes `loading="lazy"` images that are off
screen after the scroll pass returns to the top — the browser has no reason to
fetch them, and waiting for them stalls the capture for the full timeout.

## Viewport

`eval` runs at the browser window's own width, and the capture width is set on
the screenshot call. Verified on this site to reflow rather than crop: the
header-height token resolves to 73px in the 320 and 390 captures and 113px at
1440, and blocks carrying `lg:hidden` appear at 390 and are absent at 1440.

Usage (library):
    from capture_opencli import capture
    result = capture("https://metal-divider.com/", "mdcap", 1440, "/tmp/out/home-1440.png")
"""
import hashlib
import json
import os
import struct
import subprocess
import time
from typing import Any

OPENCLI = os.environ.get("OPENCLI") or os.path.expanduser("~/.local/bin/opencli")

# Paused animations and dead transitions: the gate needs the same frame twice.
KILL_CSS = """*, *::before, *::after {
  animation-play-state: paused !important;
  transition: none !important;
  scroll-behavior: auto !important;
}"""

COOKIE_ACCEPT_SELECTORS = [
    "#cookie_action_close_header",
    ".cky-btn-accept",
    "#cky-btn-accept",
    "button.cky-banner-element.cky-btn-accept",
    ".cookieyes-btn-accept",
    "#onetrust-accept-btn-handler",
    "#cookiescript_accept",
]

BANNER_DETECTORS = [
    "#cky-consent",
    ".cky-consent",
    ".cky-consent-bar",
    "#cookie-law-info-bar",
    ".cookieyes-consent-container",
    "#cookiescript_injected",
    "#onetrust-consent-sdk",
]


def call(session: str, args: list[str], timeout: int = 300) -> dict[str, Any]:
    proc = subprocess.run(
        [OPENCLI, "browser"] + args + ["--session", session],
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    line = proc.stdout.strip().splitlines()[-1] if proc.stdout.strip() else ""
    try:
        parsed = json.loads(line)
    except json.JSONDecodeError:
        return {"ok": False, "raw": (proc.stdout + proc.stderr)[:400]}
    if not isinstance(parsed, dict):
        return {"ok": False, "raw": line[:400]}
    return parsed


def evaluate(session: str, js: str) -> Any:
    res = call(session, ["eval", js])
    return res.get("data") if res.get("ok") else None


def sha256(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def png_size(path: str) -> tuple[int, int]:
    with open(path, "rb") as fh:
        head = fh.read(24)
    width, height = struct.unpack(">II", head[16:24])
    return width, height


def dismiss_banner(session: str) -> bool:
    for selector in COOKIE_ACCEPT_SELECTORS:
        res = evaluate(
            session,
            "(() => {const el = document.querySelector(%s);"
            "if (!el) return false; el.click(); return true;})()" % json.dumps(selector),
        )
        if res is True:
            return True
    return False


def residual_banner(session: str) -> list[str]:
    found: list[str] = []
    for selector in BANNER_DETECTORS:
        res = evaluate(
            session,
            "(() => {const el = document.querySelector(%s);"
            "if (!el) return false; const r = el.getBoundingClientRect();"
            "const cs = getComputedStyle(el);"
            "return cs.display !== 'none' && cs.visibility !== 'hidden' && r.height > 20;})()"
            % json.dumps(selector),
        )
        if res is True:
            found.append(selector)
    return found


def settle(session: str, style_bytes_floor: int = 20000, timeout_s: float = 90.0) -> dict[str, Any]:
    """Bring the page to a state worth photographing, and say what it reached."""
    # Animations off, before anything is measured.
    evaluate(
        session,
        "(() => {const s = document.createElement('style'); s.textContent = %s;"
        "document.head.appendChild(s); return 1;})()" % json.dumps(KILL_CSS),
    )
    evaluate(session, "(() => {document.documentElement.style.scrollBehavior='auto';return 1;})()")
    evaluate(session, "(() => {if (document.fonts && document.fonts.ready) {document.fonts.ready;}"
                      "return document.fonts ? document.fonts.status : 'none';})()")

    banner = dismiss_banner(session)

    # Lazy-load pass: walk down, then back to the top.
    evaluate(
        session,
        "(() => {let y = 0; const step = Math.max(400, Math.round(innerHeight * 0.8));"
        "const walk = () => {window.scrollTo(0, y); y += step;"
        "return y < document.documentElement.scrollHeight;}; window.__mdwalk = walk;"
        "return document.documentElement.scrollHeight;})()",
    )
    for _ in range(80):
        if evaluate(session, "(() => !!(window.__mdwalk && window.__mdwalk()))()") is not True:
            break
        time.sleep(0.12)
    evaluate(session, "window.scrollTo(0, 0)")

    # Every image finished, then the WindPress style bytes arrive.
    deadline = time.time() + timeout_s
    images_done = False
    style_bytes = 0
    lazy_pending = 0
    while time.time() < deadline:
        # Only images the browser has been asked for count: an off-screen
        # lazy image is not pending in any meaningful sense.
        probe = (
            "(() => {let total = 0;"
            "document.querySelectorAll('style').forEach((s) => {total += (s.textContent || '').length;});"
            "const imgs = [...document.images];"
            "const pending = imgs.filter((i) => !i.complete && i.getAttribute('loading') !== 'lazy').length;"
            "const lazyPending = imgs.filter((i) => !i.complete && i.getAttribute('loading') === 'lazy').length;"
            "return JSON.stringify({pending: pending, lazyPending: lazyPending, styleBytes: total});})()"
        )
        state = evaluate(session, probe)

        if isinstance(state, str):
            parsed = json.loads(state)
            style_bytes = int(parsed.get("styleBytes", 0))
            images_done = int(parsed.get("pending", 0)) == 0
            lazy_pending = int(parsed.get("lazyPending", 0))
            if images_done and style_bytes >= style_bytes_floor:
                break
        time.sleep(0.5)

    # A backstop for sites with no WindPress JIT at all.
    time.sleep(1.5)
    return {
        "bannerClicked": banner,
        "imagesDone": images_done,
        "lazyPending": lazy_pending,
        "styleBytes": style_bytes,
        "residualBanner": residual_banner(session),
    }


def capture(url: str, session: str, width: int, out: str, height: int = 900) -> dict[str, Any]:
    """One page at one width. Returns the record the manifest stores."""
    started = time.time()
    opened = call(session, ["open", url], timeout=300)
    if not opened.get("ok"):
        return {"ok": False, "error": "open failed: " + str(opened)[:200]}

    call(session, ["wait", "selector", "main"], timeout=120)
    state = settle(session)

    os.makedirs(os.path.dirname(out), exist_ok=True)
    shot = call(
        session,
        ["screenshot", out, "--width", str(width), "--height", str(height), "--full-page"],
        timeout=300,
    )
    if not shot.get("ok") or not os.path.exists(out):
        return {"ok": False, "error": "capture failed: " + str(shot)[:200]}

    actual_w, actual_h = png_size(out)
    return {
        "ok": True,
        "file": os.path.basename(out),
        "bytes": os.path.getsize(out),
        "sha256": sha256(out),
        "capturedWidth": actual_w,
        "capturedHeight": actual_h,
        "widthMismatch": actual_w != width,
        "finalUrl": evaluate(session, "location.href"),
        "ms": int((time.time() - started) * 1000),
        **state,
    }
