#!/usr/bin/env bash
#
# verify-chat.sh - verify the chat plane (Live Helper Chat visitor widget).
#
# Always runs five read-only curl probes, each against a unique cache-busted
# URL (LiteSpeed page cache can serve stale HTML). With --chrome it also emits
# (and, when opencli is on PATH, executes) a single-session real-Chrome launcher
# chain: open -> assert launcher -> click -> assert card -> screenshot ->
# Escape -> assert closed -> screenshot.
#
# Exits 0 only when every probe that ran passed. No secrets; all URLs are built
# from --base-url.

set -euo pipefail

BASE_URL="https://staging.metal-divider.com"
CHROME=0
OUT_DIR=".scratch/verify-chat"

CURL_PASS=0
CURL_FAIL=0
CHROME_PASS=0
CHROME_FAIL=0
CHROME_OC=0

usage() {
  echo "Usage: $(basename "$0") [--base-url URL] [--chrome] [--out-dir DIR]"
  echo ""
  echo "Verify the chat plane (Live Helper Chat visitor widget)."
  echo ""
  echo "Options:"
  echo "  --base-url URL   Site base URL (default https://staging.metal-divider.com)"
  echo "  --chrome         Run the opencli real-Chrome launcher chain"
  echo "  --out-dir DIR    Screenshot output dir (default .scratch/verify-chat)"
  echo "  -h, --help       Show this help"
}

buster() {
  echo "verify=$(date +%s)$RANDOM"
}

http_get() {
  # $1 = url, $2 = output file; prints HTTP status code (000 on transport error).
  # curl writes the -w status (000) even when the transfer fails, so the
  # failure branch must not append a second 000.
  curl -sS --max-time 30 -o "$2" -w '%{http_code}' "$1" 2>/dev/null || true
}

excerpt() {
  # one-line excerpt of a body file, for failure messages
  head -c 240 "$1" 2>/dev/null | awk '{printf "%s ", $0}' || true
}

say_pass() { echo "PASS  $1"; CURL_PASS=$((CURL_PASS + 1)); }
say_fail() { echo "FAIL  $1"; CURL_FAIL=$((CURL_FAIL + 1)); }

curl_chain() {
  local tmp body code url idx icode
  tmp="$(mktemp -d)"
  body="$tmp/body"
  idx="$tmp/index"

  echo ""
  echo "=== Curl chain (read-only) ==="
  echo "base: $BASE_URL"

  # 1. Widget start document carries the md-card marker class
  url="$BASE_URL/chat/index.php/chat/chatwidget/(department)/1?$(buster)"
  code="$(http_get "$url" "$body")"
  if [[ "$code" == "200" ]] && grep -qF 'md-card' "$body"; then
    say_pass "widget start document carries md-card"
  else
    say_fail "widget start document carries md-card"
    echo "      url:      $url"
    echo "      expected: HTTP 200 and body contains 'md-card'"
    echo "      found:    HTTP $code; excerpt: $(excerpt "$body")"
  fi

  # 2. Launcher script is served
  url="$BASE_URL/chat/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-launcher.js?$(buster)"
  code="$(http_get "$url" "$body")"
  if [[ "$code" == "200" ]] && grep -qF 'mdvl-launcher' "$body"; then
    say_pass "launcher script served (contains mdvl-launcher)"
  else
    say_fail "launcher script served (contains mdvl-launcher)"
    echo "      url:      $url"
    echo "      expected: HTTP 200 and body contains 'mdvl-launcher'"
    echo "      found:    HTTP $code; excerpt: $(excerpt "$body")"
  fi

  # 3. Widget CSS is served and carries .mdv-closed-actions
  url="$BASE_URL/chat/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css?$(buster)"
  code="$(http_get "$url" "$body")"
  if [[ "$code" == "200" ]] && grep -qF 'mdv-closed-actions' "$body"; then
    say_pass "widget css served (contains mdv-closed-actions)"
  else
    say_fail "widget css served (contains mdv-closed-actions)"
    echo "      url:      $url"
    echo "      expected: HTTP 200 and body contains 'mdv-closed-actions'"
    echo "      found:    HTTP $code; excerpt: $(excerpt "$body")"
  fi

  # 4. REST route /wp-json/md/v1/kb/answer exists (registered POST-only).
  # Evidence is the namespace index: the route is POST-only, so a direct GET is
  # 404 rest_no_route, and any other GET status proves nothing about the route.
  # The index JSON escapes forward slashes, so the route-specific marker is
  # checked in both the escaped form kb\/answer and the plain form kb/answer;
  # an unrelated "answer" route satisfies neither.
  url="$BASE_URL/wp-json/md/v1/kb/answer?$(buster)"
  code="$(http_get "$url" "$body")"
  icode="$(http_get "$BASE_URL/wp-json/md/v1?$(buster)" "$idx")"
  if [[ "$icode" == "200" ]] && grep -qF -e 'kb\/answer' -e 'kb/answer' "$idx"; then
    say_pass "rest route /wp-json/md/v1/kb/answer exists (namespace index lists it; direct GET HTTP $code)"
  else
    say_fail "rest route /wp-json/md/v1/kb/answer exists"
    echo "      url:      $url"
    echo "      expected: /wp-json/md/v1 index HTTP 200 listing kb/answer"
    echo "      found:    direct GET HTTP $code; namespace index GET HTTP $icode"
  fi

  # 5. Fresh home render carries the launcher script tag
  url="$BASE_URL/?$(buster)"
  code="$(http_get "$url" "$body")"
  if [[ "$code" == "200" ]] && grep -qF 'mdvisitor-launcher.js' "$body"; then
    say_pass "home render carries launcher script tag"
  else
    say_fail "home render carries launcher script tag"
    echo "      url:      $url"
    echo "      expected: HTTP 200 and <script ... mdvisitor-launcher.js>"
    echo "      found:    HTTP $code; excerpt: $(excerpt "$body")"
  fi

  echo ""
  echo "curl chain: $CURL_PASS passed, $CURL_FAIL failed"

  rm -rf "$tmp"
}

chrome_chain() {
  local probe_dir sess win b url
  probe_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/probes" && pwd)"
  sess="mdv-verify-$$"
  win="background"
  b="verify=$(date +%s)$RANDOM"
  url="$BASE_URL/?$b"

  echo ""
  echo "=== Chrome chain (opencli) ==="
  echo "session: $sess  window: $win"

  if command -v opencli >/dev/null 2>&1; then
    CHROME_OC=1
  else
    # Fail closed: --chrome was requested, so an unusable opencli is a failure,
    # not a skip. The manual sequence below is printed for the operator.
    echo "FAIL  opencli not on PATH; the real-Chrome chain cannot run"
    CHROME_FAIL=$((CHROME_FAIL + 1))
  fi

  mkdir -p "$OUT_DIR"

  oc() {
    echo "  opencli $*"
    if [[ $CHROME_OC -eq 1 ]]; then
      if ! opencli "$@"; then
        echo "  FAIL  opencli $* (non-zero exit)"
        CHROME_FAIL=$((CHROME_FAIL + 1))
      fi
    fi
  }

  oc_assert() {
    local label="$1" file="$2" marker="$3" js out
    js="$(cat "$file")"
    echo "  [assert] $label"
    printf '  opencli browser eval "$(cat %s)" --session %s --window %s' "$file" "$sess" "$win"
    echo ""
    if [[ $CHROME_OC -eq 1 ]]; then
      out="$(opencli browser eval "$js" --session "$sess" --window "$win" 2>&1 || true)"
      if grep -qF "$marker" <<<"$out"; then
        echo "  PASS  $label"
        CHROME_PASS=$((CHROME_PASS + 1))
      else
        echo "  FAIL  $label (expected $marker; got: $(echo "$out" | head -c 300))"
        CHROME_FAIL=$((CHROME_FAIL + 1))
      fi
    fi
  }

  oc browser open "$url" --session "$sess" --window "$win"
  oc browser wait selector ".mdvl-launcher" --session "$sess" --window "$win" --timeout 15000
  oc_assert "launcher button .mdvl-launcher present" "$probe_dir/assert-launcher-exists.js" "MDV_LAUNCHER_OK"
  oc browser click ".mdvl-launcher" --session "$sess" --window "$win"
  oc browser wait time 2 --session "$sess" --window "$win"
  oc_assert "widget card .mdvl-card visible" "$probe_dir/assert-card-visible.js" "MDV_CARD_VISIBLE"
  oc browser screenshot "$OUT_DIR/01-launcher-open.png" --session "$sess" --window "$win"
  oc browser keys Escape --session "$sess" --window "$win"
  oc_assert "widget card .mdvl-card closed" "$probe_dir/assert-card-closed.js" "MDV_CARD_CLOSED"
  oc browser screenshot "$OUT_DIR/02-launcher-closed.png" --session "$sess" --window "$win"
  oc browser close --session "$sess"

  echo ""
  if [[ $CHROME_OC -eq 1 ]]; then
    echo "chrome chain: $CHROME_PASS passed, $CHROME_FAIL failed"
  else
    echo "chrome chain: FAILED (opencli not installed; --chrome was requested)"
  fi
}

main() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --base-url)
        [[ $# -ge 2 ]] || { echo "error: --base-url requires a value" >&2; exit 2; }
        BASE_URL="$2"; shift 2 ;;
      --base-url=*)
        BASE_URL="$(printf '%s' "$1" | cut -d= -f2-)"; shift ;;
      --chrome)
        CHROME=1; shift ;;
      --out-dir)
        [[ $# -ge 2 ]] || { echo "error: --out-dir requires a value" >&2; exit 2; }
        OUT_DIR="$2"; shift 2 ;;
      --out-dir=*)
        OUT_DIR="$(printf '%s' "$1" | cut -d= -f2-)"; shift ;;
      -h|--help)
        usage; exit 0 ;;
      *)
        echo "error: unknown option: $1" >&2
        usage >&2
        exit 2 ;;
    esac
  done

  BASE_URL="$(printf '%s' "$BASE_URL" | sed 's:/$::')"

  curl_chain

  if [[ $CHROME -eq 1 ]]; then
    chrome_chain
  fi

  overall_fail=$CURL_FAIL
  if [[ $CHROME -eq 1 && $CHROME_OC -eq 1 ]]; then
    overall_fail=$((overall_fail + CHROME_FAIL))
  fi

  echo ""
  echo "SUMMARY: curl $CURL_PASS passed, $CURL_FAIL failed"
  if [[ $CHROME -eq 1 ]]; then
    if [[ $CHROME_OC -eq 1 ]]; then
      echo "         chrome $CHROME_PASS passed, $CHROME_FAIL failed"
    else
      echo "         chrome: FAILED (opencli not installed)"
    fi
  fi

  if [[ $overall_fail -eq 0 ]]; then
    echo "RESULT: chat plane verification PASSED"
    exit 0
  else
    echo "RESULT: chat plane verification FAILED"
    exit 1
  fi
}

main "$@"
