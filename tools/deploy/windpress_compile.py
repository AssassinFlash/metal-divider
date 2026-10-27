#!/usr/bin/env python3
"""Run the WindPress browser-side compile through opencli.

Tailwind v4 has no server-side build: the CSS the site serves is produced by
the WindPress admin's own worker, in a real browser. This drives that browser
through opencli against the owner's Chrome — never Playwright (AGENTS §7).

The steps and the reasons behind them are carried over from the Playwright
driver this replaces:

  * The dashboard mounts into `#windpress-app`; every selector is scoped there
    so WordPress's own admin menu can never match "Settings".
  * WindPress parks a review-request prompt in a fixed bottom-right viewport
    that swallows pointer events over the Generate button. Dismiss it by its
    own button first; if it survives, drop the notification region from the
    layer.
  * Hostinger Agent's greeting is a second fixed overlay on production and
    intercepts the same click.
  * The gate is the cache-store response, not the toast: a compile that
    answers with `last_generated` newer than the previous one is the proof.

Usage:
    python3 tools/deploy/windpress_compile.py <one-time-login-url> [before-mtime]
    MD_WP_SITE=… MD_WP_USER=… MD_WP_PASS=… python3 tools/deploy/windpress_compile.py
"""

import json
import os
import re
import subprocess
import sys
import time
from typing import Any

OPENCLI = os.environ.get("OPENCLI") or os.path.expanduser("~/.local/bin/opencli")
SESSION = os.environ.get("WINDPRESS_SESSION", "mdwp")
WAIT_MS = int(os.environ.get("WINDPRESS_COMPILE_WAIT_MS", "300000"))

CLICK_JS = """(() => {{
  const app = document.querySelector({container});
  if (!app) return JSON.stringify({{error: 'no app'}});
  const wanted = {text};
  const nodes = [...app.querySelectorAll({selector})].filter(
    (n) => (n.textContent || '').trim() === wanted
  );
  const node = nodes[0];
  if (!node) return JSON.stringify({{error: 'not found', wanted}});
  if (!node.id) node.id = '__md_wp_' + Math.random().toString(36).slice(2);
  return JSON.stringify({{selector: '#' + node.id, count: nodes.length}});
}})()"""

VISIBLE_JS = """(() => {{
  const app = document.querySelector({container});
  if (!app) return false;
  const nodes = [...app.querySelectorAll({selector})].filter(
    (n) => (n.textContent || '').trim() === {text}
  );
  return nodes.some((n) => {{
    const r = n.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  }});
}})()"""

CLEAR_OVERLAYS_JS = """(() => {{
  const region = document.querySelector('[role="region"][aria-label*="Notification"]');
  if (region) region.remove();
  document.querySelectorAll('#vue-app .first-greeting').forEach((e) => e.remove());
  return 1;
}})()"""


def call(args: list[str], timeout: int = 240) -> dict[str, Any]:
    proc = subprocess.run(
        [OPENCLI, "browser"] + args + ["--session", SESSION],
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


def ev(js: str) -> dict[str, Any]:
    return call(["eval", js])


def click_text(
    selector_js: str, text: str, container: str = "#windpress-app"
) -> dict[str, Any]:
    """Click the first element matching a role/name pair, through DOM order.

    opencli's click takes a selector, not an accessibility name, so the lookup
    runs in the page: it returns a stable id, then a real click lands on it.
    Falling back to `.click()` from the same expression keeps behaviour
    identical to dispatching on the located node.
    """
    js = CLICK_JS.format(
        container=json.dumps(container),
        text=json.dumps(text),
        selector=json.dumps(selector_js),
    )
    res = ev(js)
    data = res.get("data")
    if not isinstance(data, str):
        return {"ok": False, "error": res}
    parsed = json.loads(data)
    if not isinstance(parsed, dict):
        return {"ok": False, "error": "unexpected click probe output"}
    if "error" in parsed:
        return {"ok": False, "error": parsed["error"]}
    clicked = call(["click", str(parsed["selector"])])
    return {
        "selector": parsed["selector"],
        "count": parsed["count"],
        "clicked": bool(clicked.get("ok", False)),
    }


def wait_for(
    selector_js: str,
    text: str,
    timeout_ms: int = 90000,
    container: str = "#windpress-app",
) -> bool:
    """Poll for an element to exist and be visible."""
    deadline = time.time() + timeout_ms / 1000
    while time.time() < deadline:
        js = VISIBLE_JS.format(
            container=json.dumps(container),
            selector=json.dumps(selector_js),
            text=json.dumps(text),
        )
        res = ev(js)
        if res.get("data") is True:
            return True
        time.sleep(1.5)
    return False


def main() -> int:
    login_url = sys.argv[1] if len(sys.argv) > 1 else ""
    before = int(sys.argv[2]) if len(sys.argv) > 2 else 0
    started = time.time()

    if not login_url and not os.environ.get("MD_WP_SITE"):
        print(
            "usage: windpress_compile.py <one-time-login-url> [before-mtime]",
            file=sys.stderr,
        )
        return 2

    if login_url:
        call(["open", login_url], timeout=300)
        url = call(["get", "url"]).get("data") or ""
        if "/wp-admin/" not in url:
            print(
                "the login link did not reach wp-admin (at " + url + ")",
                file=sys.stderr,
            )
            return 1
    else:
        site = os.environ["MD_WP_SITE"].rstrip("/")
        call(["open", site + "/wp-login.php"], timeout=300)
        call(["fill", "#user_login", os.environ["MD_WP_USER"]])
        call(["fill", "#user_pass", os.environ["MD_WP_PASS"]])
        call(["click", "#wp-submit"], timeout=300)
        deadline = time.time() + 40
        url = ""
        while time.time() < deadline:
            url = call(["get", "url"]).get("data") or ""
            if "/wp-admin/" in url:
                break
            time.sleep(1)
        if "/wp-admin/" not in url:
            print("login did not reach wp-admin (at " + url + ")", file=sys.stderr)
            return 1

    origin_match = re.match(r"^(https?://[^/]+)", url)
    if not origin_match:
        print("could not read the origin from " + url, file=sys.stderr)
        return 1
    origin = origin_match.group(1)
    call(["open", origin + "/wp-admin/admin.php?page=windpress"], timeout=300)

    if not wait_for("a", "Settings"):
        print("the WindPress dashboard never showed Settings", file=sys.stderr)
        return 1
    click_text("a", "Settings")
    if not wait_for("a", "Performance", 30000):
        print("the Settings page never showed Performance", file=sys.stderr)
        return 1
    click_text("a", "Performance")

    if not wait_for("button", "Generate", 30000):
        print("the Performance page never showed Generate", file=sys.stderr)
        return 1

    # Dismiss the review prompt, then clear any surviving overlay.
    for label in ("Never", "Later", "OK, Let's do it!"):
        click_text("button", label)
        time.sleep(0.5)
    ev(CLEAR_OVERLAYS_JS)

    # Click Generate, then read the cache-store answer out of the network log.
    click_text("button", "Generate")
    deadline = time.time() + WAIT_MS / 1000
    stored_key = None
    while time.time() < deadline:
        since = f"{int(time.time() - started + 5):d}s"
        entries = call(["network", "--since", since, "--all"])
        data = entries.get("data") or {}
        for entry in data.get("entries", []):
            url = entry.get("url", "")
            if "settings/cache/store" in url and entry.get("method") == "POST":
                stored_key = entry.get("key")
                break
        if stored_key:
            break
        time.sleep(4)

    if not stored_key:
        print(
            f"no cache store within {int(WAIT_MS / 1000):d}s of Generate — the compile never finished",
            file=sys.stderr,
        )
        return 1

    # `network` reports a shape preview by default; the body needs --detail.
    detail = call(["network", "--detail", stored_key, "--all"])
    stored = json.dumps(detail.get("data")) if detail.get("data") is not None else ""
    if not stored:
        print("the cache-store entry had no readable body", file=sys.stderr)
        return 1

    # The stored cache's timestamp is the gate, not the toast.
    last_generated = None
    match = re.search(r'"last_generated"\s*:\s*(\d+)', stored)
    if match:
        last_generated = int(match.group(1))
    if last_generated is None:
        print(
            "the cache-store body carries no last_generated: " + stored[:300],
            file=sys.stderr,
        )
        return 1
    if last_generated <= before:
        print(
            f"the stored cache is not newer than the previous one (before {before:d}, stored {last_generated:d})",
            file=sys.stderr,
        )
        return 1

    size = None
    size_match = re.search(r'"file_size"\s*:\s*(\d+)', stored)
    if size_match:
        size = int(size_match.group(1))

    print(
        json.dumps(
            {
                "compiled": True,
                "last_generated": last_generated,
                "file_size": size,
                "ms": int((time.time() - started) * 1000),
            }
        )
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
