"""The WindPress volumes' syntax gate (ticket #49).

The volume files are the one artifact the WindPress compiler takes whole:
it is fatal on the first unbalanced brace, and a fatal volume takes the
site's styles down with it — which is how #44's extraction debt sat
unnoticed until #48's acceptance (24 of the 36 volumes unbalanced, the
compiler's output empty on every page). This module is the guard that
makes that class of corruption a check-time failure instead: every
``.css`` under the plugin's ``content/windpress`` must be brace-balanced
(strings and comments respected) and parse without tinycss2 error nodes.

The balance scan is a small state machine rather than a parser: it walks
the text once, tracking comment, string and escape state, and counts
braces only where they are structural. tinycss2's own parse is lenient by
design (an unclosed rule is not an error to it), so it is the second net,
not the first.
"""

from __future__ import annotations

import sys
from pathlib import Path

import tinycss2


def check_volume(css_text: str) -> list[str]:
    """One volume's problems, as human-readable lines (empty = pass).

    Two passes, both string- and comment-aware: the brace walk (depth
    must return to zero and never dip below it) and tinycss2's parse
    (stray closers and other malformed shapes its error nodes name).
    """
    problems: list[str] = []
    depth = 0
    line = 1
    i = 0
    n = len(css_text)
    in_comment = False
    in_string: str | None = None
    while i < n:
        ch = css_text[i]
        if ch == "\n":
            line += 1
            i += 1
            continue
        if in_comment:
            if ch == "*" and i + 1 < n and css_text[i + 1] == "/":
                in_comment = False
                i += 2
                continue
            i += 1
            continue
        if in_string is not None:
            if ch == "\\":
                i += 2
                continue
            if ch == in_string:
                in_string = None
            i += 1
            continue
        if ch == "/" and i + 1 < n and css_text[i + 1] == "*":
            in_comment = True
            i += 2
            continue
        if ch in "\"'":
            in_string = ch
            i += 1
            continue
        if ch == "\\":
            i += 2
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth < 0:
                problems.append(f"line {line}: '}}' closes more than it opened")
                depth = 0
        i += 1
    if in_comment:
        problems.append("unterminated comment at end of file")
    if in_string is not None:
        problems.append("unterminated string at end of file")
    if depth != 0:
        problems.append(
            f"brace depth {depth:+d} at end of file ({depth} unclosed '{{')"
        )

    for rule in tinycss2.parse_stylesheet(
        css_text, skip_comments=True, skip_whitespace=True
    ):
        if rule.type == "error":
            problems.append(
                f"line {rule.source_line}: tinycss2: {rule.message} ({rule.kind})"
            )
    return problems


def check_volumes(plugin_dir: Path) -> dict[str, list[str]]:
    """Every volume's problems, keyed by file name; volumes with no
    problem are absent from the mapping."""
    volumes_dir = plugin_dir / "content" / "windpress"
    css_files = sorted(volumes_dir.glob("*.css"))
    if not css_files:
        return {"(content/windpress)": ["no .css volumes found"]}
    out: dict[str, list[str]] = {}
    for css in css_files:
        problems = check_volume(css.read_text(encoding="utf-8"))
        if problems:
            out[css.name] = problems
    return out


def main(argv: list[str] | None = None) -> int:
    """The ``md-volume-check`` entry point: ``md-volume-check <plugin>``."""
    if argv is None:
        argv = sys.argv[1:]
    if len(argv) != 1:
        print("usage: md-volume-check <plugin-dir>", file=sys.stderr)
        return 2
    problems = check_volumes(Path(argv[0]))
    if not problems:
        volumes = sorted((Path(argv[0]) / "content" / "windpress").glob("*.css"))
        print(f"volume-check: {len(volumes)} volumes balanced, no parse errors")
        return 0
    for name, rows in problems.items():
        for row in rows:
            print(f"volume-check: {name}: {row}")
    print(f"volume-check: {len(problems)} volume(s) with problems", file=sys.stderr)
    return 1
