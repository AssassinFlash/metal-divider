#!/usr/bin/env python3
"""Run a page-side probe through opencli and print its JSON without truncation.

opencli wraps every result as {"ok":true,"data":"<string>"}, and the CLI truncates
long payloads in some paths. This runs the probe, saves the raw envelope, and
prints a compact table so a large finding set stays readable.

Usage:
    python3 tools/audit/probe.py <session> <js-file> [--raw OUT] [--brief]
"""
import argparse
import json
import subprocess
import sys


def opencli(args, timeout=300):
    proc = subprocess.run(["opencli"] + args, capture_output=True, text=True, timeout=timeout)
    return proc.stdout.strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("session")
    ap.add_argument("jsfile")
    ap.add_argument("--raw", default=None)
    ap.add_argument("--brief", action="store_true")
    args = ap.parse_args()

    with open(args.jsfile) as fh:
        js = fh.read()

    out = opencli(["browser", "eval", js, "--session", args.session])
    with open(args.raw, "w") if args.raw else open("/dev/null", "w") as dump:
        dump.write(out)

    try:
        envelope = json.loads(out)
    except json.JSONDecodeError:
        print("opencli returned something that is not JSON:")
        print(out[:2000])
        return 2

    if not envelope.get("ok"):
        print("probe failed:", json.dumps(envelope.get("error"))[:800])
        return 1

    data = envelope["data"]
    if isinstance(data, str):
        try:
            data = json.loads(data)
        except json.JSONDecodeError:
            print(data[:4000])
            return 0

    if not args.brief:
        print(json.dumps(data, indent=1)[:20000])
        return 0

    print("url      ", data.get("url"))
    print("controls ", data.get("controls"), " tested", data.get("tested"))
    print("summary  ", json.dumps(data.get("summary", {})))
    for f in data.get("findings", []):
        panel = ""
        if f.get("panelBefore") or f.get("panelAfter"):
            panel = " panel %s -> %s" % (
                json.dumps(f.get("panelBefore"), separators=(",", ":")),
                json.dumps(f.get("panelAfter"), separators=(",", ":")),
            )
        print("  %-22s %-40s %-44s%s dChar=%s" % (
            f.get("verdict"), (f.get("text") or "")[:40], (f.get("sel") or "")[:44], panel, f.get("deltaChars")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
