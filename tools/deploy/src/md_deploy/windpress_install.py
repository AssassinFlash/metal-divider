"""Install a compiled WindPress cache on a target, behind a gate that can refuse.

Tailwind v4 has no server-side build: WindPress compiles in a browser, in its
admin. On this host the production admin is unreachable from automation (the
login answers "ERROR: Stop guessing!"), so that manual step has silently not
happened and the compiled stylesheet fell behind the repository — measured
2026-10-29, production's CSS lacked both the scoped frame rule and
`.md-skip-link` while both were committed, and the missing frame rule put 85px
of empty background above the first field of every closing card.

The artifact installed here is compiled on the dev instance: same plugin
version, same volume sources. Installing someone else's build output is only
safe if it covers what the target's pages actually use, so this module refuses
unless it does.

The gate is a coverage question, not a diff against the other stylesheet. An
earlier version of this reasoning compared production's CSS with the dev
instance's and called the dev file safe because it was a superset — that proves
nothing about the pages. The check below reads the target's own HTML, collects
every class it renders, and requires the candidate to carry a rule for each.
"""

from __future__ import annotations

import concurrent.futures
import re
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any

UA = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"
)

# The characters a Tailwind utility name can carry that CSS requires escaped in
# a selector. `:` for responsive and state prefixes, `[` `]` for arbitrary
# values, `.` `/` `%` `(` `)` `,` `#` `!` `+` `~` `>` `*` `'` `"` `&` `@` `$`
# for the rest of what a class attribute may legally hold.
ESCAPED_IN_UTILITY = set(":.[]/%(),#!+~>*'\"&@$")


@dataclass
class Coverage:
    """What the gate found, in the numbers a reader needs to judge it."""

    pages_fetched: int
    pages_attempted: int
    classes_seen: int
    missing: list[str]
    missing_from_current: list[str]

    @property
    def regressions(self) -> list[str]:
        """Classes the candidate lacks that the CURRENT stylesheet does have.

        A class the candidate lacks and the current stylesheet also lacks is not
        a regression — the site does not style it today, so installing the
        candidate changes nothing about it. Only the difference is a new harm,
        and that difference is what the gate refuses on.
        """
        current = set(self.missing_from_current)
        return [c for c in self.missing if c not in current]

    @property
    def passed(self) -> bool:
        return not self.regressions and self.pages_fetched > 0


def classes_of(html: str) -> set[str]:
    """Every class token the document carries in a class attribute.

    Script and style contents are removed first. They contain markup-shaped
    strings — Alpine's `'active': ...` bindings, Tailwind class lists inside a
    JS template literal — and a matcher that reads them reports pseudo-classes
    like `'active':` and `'Care` as classes the stylesheet is missing. Measured
    on production: 395 reported "missing" classes, and the sample was almost
    entirely this noise. The gate still passed on the real question (zero
    regressions), but a report whose first thirty lines are noise does not get
    read, and a check nobody reads is not a check.
    """
    body = re.sub(r"(?is)<(script|style|template)\b[^>]*>.*?</\1>", " ", html)
    found: set[str] = set()
    for match in re.finditer(r'\bclass="([^"]*)"', body):
        for token in match.group(1).split():
            if not token or token.startswith("{"):
                continue
            # A class attribute holds class names. Anything carrying a quote, an
            # angle bracket or a JS punctuation character is a fragment of code
            # that happened to sit inside a quoted string.
            if any(ch in token for ch in "'\"<>{};$=`)("):
                continue
            # A bare number is never a class. It shows up because a `class=`
            # inside a JSON or data attribute is followed by digits in the same
            # quoted run; reporting `0` as a missing class is pure noise.
            if token.isdigit():
                continue
            found.add(token)
    return found


def has_rule_for(css: str, cls: str) -> bool:
    """A selector in `css` that targets this class as a whole token.

    Two forms have to be recognised, because Tailwind writes the second:

    * a plain class — `.md-inquiry-form`
    * an escaped one — `.md\\:text-lg` for `md:text-lg`, and `.w-\\[2px\\]` for
      an arbitrary value. CSS escapes the `:` and `[` that a utility name
      carries, so a matcher that only knows the plain form reports every
      responsive and arbitrary-value utility as missing. Measured while writing
      this gate: the first version did exactly that.

    The trailing guard keeps `.text-sm` from being satisfied by `.text-smm`; a
    class that appears only inside a longer name is not a rule for it. It is
    applied by consuming the class and then refusing specific characters, not by
    appending a lookahead to an escaped string: `re.escape("text-sm")` turns the
    dash into an escaped dash, and a lookahead built after that never fires —
    measured, the
    guard silently stopped working while the tests still passed on the plain
    case.
    """
    for pattern in _patterns_for(cls):
        if re.search(pattern, css):
            return True
    return False


def _patterns_for(cls: str) -> list[str]:
    """The two selector spellings a class can take, each with the guard."""
    guard = r"(?![\w\\-])"
    plain = re.escape(cls) + guard

    # Escaped spelling: CSS writes the characters in ESCAPED_IN_UTILITY with a
    # backslash. Each character is escaped for the regex separately, so a `[` in
    # the name becomes a literal rather than the start of a character class —
    # the first version concatenated first and crashed on real page data with
    # `bad character range l-g`.
    escaped = "".join(
        r"\\" + re.escape(ch) if ch in ESCAPED_IN_UTILITY else re.escape(ch)
        for ch in cls
    )
    return [r"\." + plain, r"\." + escaped + guard]


def _get(url: str, timeout: int = 60) -> str:
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        body: str = response.read().decode("utf-8", "replace")
        return body


def check_coverage(
    base: str,
    paths: list[str],
    candidate_css: str,
    current_css: str,
    cache_buster: str = "",
    workers: int = 6,
) -> Coverage:
    """Collect the pages' classes, then ask what the candidate is missing."""

    def one(path: str) -> tuple[str, set[str] | None]:
        url = f"{base}{path}"
        if cache_buster:
            url += f"?mdaudit={cache_buster}"
        try:
            return path, classes_of(_get(url))
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError):
            return path, None

    seen: set[str] = set()
    fetched = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        for _path, result in pool.map(one, paths):
            if result is None:
                continue
            fetched += 1
            seen |= result

    missing = sorted(c for c in seen if not has_rule_for(candidate_css, c))
    missing_current = sorted(c for c in seen if not has_rule_for(current_css, c))
    return Coverage(
        pages_fetched=fetched,
        pages_attempted=len(paths),
        classes_seen=len(seen),
        missing=missing,
        missing_from_current=missing_current,
    )


# The pages the gate reads when the caller names none. The same inventory the
# viewport audits sweep: every page type plus all nine series archives and three
# pattern detail pages, because a stylesheet is only safe if it covers all of
# them.
DEFAULT_PAGES = [
    "/", "/collections/", "/shop/", "/inquiry/", "/inquiry/thank-you/", "/privacy/",
    "/faqs/", "/about-us/", "/manufacturing-process/", "/project-gallery/", "/blog/",
    "/laser-cut-geometric-metal-screens/", "/glass-hybrid-screens/",
    "/outdoor-privacy-landscape-screens/", "/chinese-style-artistic-screens/",
    "/stainless-steel-screens/", "/laser-cut-panels/", "/perforated-panels/",
    "/expanded-metal-panels/", "/custom-metal-divider-screens/",
    "/collections/pattern-abstract/", "/collections/pattern-asian/",
    "/collections/pattern-geometric/", "/collections/pattern-gradient/",
    "/collections/pattern-landscape/", "/collections/pattern-moroccan-arabic/",
    "/collections/pattern-organic/", "/collections/pattern-perforated/",
    "/collections/pattern-vintage-retro/",
    "/pattern/agave-architectural-metal-screen-pattern/",
    "/pattern/alhambra-architectural-metal-screen-pattern/",
    "/pattern/white-sands-architectural-metal-screen-pattern/",
]


def install_cli(target: Any, args: Any, *, write: bool) -> int:
    """The `windpress-install` command: gate first, install only if it passes.

    Refusing is the normal outcome when the candidate would take styling away.
    The gate prints the classes it refuses on, so a refusal says what to fix
    rather than only that something failed.
    """
    import re
    import sys
    import time
    from pathlib import Path

    from md_deploy import plan

    candidate_path = Path(args.css)
    if not candidate_path.is_file():
        print(f"windpress-install: no such file: {candidate_path}", file=sys.stderr)
        return 1
    candidate = candidate_path.read_text(encoding="utf-8", errors="replace")

    base = args.base or target.base_url.rstrip("/")
    pages = DEFAULT_PAGES
    if args.pages is not None:
        pages = [
            line.strip()
            for line in Path(args.pages).read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]

    # The baseline is the stylesheet the pages actually link, discovered from
    # the home page rather than assumed from a path: the first version built the
    # URL by hand, got it wrong, read an empty baseline, and then reported
    # "the current stylesheet also lacks every class" — which made the gate pass
    # everything. A gate whose baseline can silently be empty is not a gate.
    current = ""
    try:
        home = _get(f"{base}/?mdaudit={int(time.time())}")
        linked = re.search(
            r'href=["\']([^"\']*windpress/cache/tailwind\.css[^"\']*)["\']', home
        )
        if linked:
            url = linked.group(1)
            if url.startswith("/"):
                root = re.match(r"^(https?://[^/]+)", base)
                url = (root.group(1) if root else base) + url
            current = _get(url, timeout=120)
    except Exception:  # noqa: BLE001 - a missing baseline is refused below, not guessed.
        current = ""
    if not current.strip():
        print(
            "windpress-install: refused — the current stylesheet could not be read, "
            "so there is no baseline to compare against",
            file=sys.stderr,
        )
        return 1

    coverage = check_coverage(base, pages, candidate, current, cache_buster=str(int(time.time())))
    print(
        f"windpress-install: pages {coverage.pages_fetched}/{coverage.pages_attempted}, "
        f"classes {coverage.classes_seen}"
    )
    print(f"windpress-install: candidate lacks {len(coverage.missing)}, "
          f"of which the current stylesheet also lacks {len(coverage.missing_from_current)}")
    print(f"windpress-install: regressions {len(coverage.regressions)}")

    if not coverage.passed:
        if coverage.pages_fetched == 0:
            print("windpress-install: refused — no page could be read, so nothing is verified", file=sys.stderr)
        else:
            print("windpress-install: refused — these classes are styled today and the candidate drops them:", file=sys.stderr)
            for cls in coverage.regressions[:40]:
                print(f"  {cls}", file=sys.stderr)
        return 1

    if not write:
        print(f"windpress-install: would install {len(candidate)} bytes to {target.label}")
        return 0

    # Copy the artifact to the target, then install it through the plugin's own
    # writer. The script travels beside the CSS so both are one upload.
    remote_dir = str(Path(target.root) / plan.WINDPRESS_CACHE).rsplit("/", 1)[0]
    remote_css = f"{remote_dir}/md-install-candidate.css"
    script = Path(__file__).resolve().parents[2] / "windpress-install.php"
    target.copy_file_in(candidate_path, plan.WINDPRESS_CACHE.rsplit("/", 1)[0] + "/md-install-candidate.css")
    target.copy_file_in(script, "wp-content/mu-plugins-md-windpress-install.php")
    output = target.wp(
        "eval-file",
        "wp-content/mu-plugins-md-windpress-install.php",
        remote_css,
    )
    print(f"windpress-install: {output.strip()}")

    # Read back the mtime the way the deploy's own check does: the file on disk
    # is the artifact, not the JSON the script printed.
    after = int(
        target.wp(
            "eval",
            'echo (int) @filemtime( wp_upload_dir()["basedir"] . "/windpress/cache/tailwind.css" );',
        ).strip().splitlines()[-1]
        or 0
    )
    print(f"windpress-install: cache mtime now {after}")
    return 0 if after > 0 else 1
