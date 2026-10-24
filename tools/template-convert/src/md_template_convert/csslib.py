"""T2: styles out of the markup, into the WindPress volumes.

Three sources feed the volumes (spec 19 §4.2 T2):

1. the old site's linked stylesheets — the rules whose selectors the
   page actually uses are carried over, so the replica keeps its
   computed styles without carrying the 552 KB whole;
2. the ``<style>`` blocks the old theme printed into the page;
3. the inline ``style="…"`` attributes, which become minted ``md-``
   classes — same declarations, a class instead of an attribute, which
   compiles to the same computed style (#175's zero-diff evidence).

The minting is deterministic: the same declaration list always mints
the same class name, so two sections sharing an inline style share the
class, and a re-run of the pipeline mints the same names again.
"""

from __future__ import annotations

import hashlib
import re
from typing import Any

import tinycss2
import tinycss2.serializer

STYLE_BLOCK = re.compile(r"[ \t]*<style([^>]*)>(.*?)</style>\n?", re.DOTALL)
INLINE_STYLE = re.compile(r'\sstyle="([^"]*)"')


def collapse_duplicated_openers(css: str) -> str:
    """A block-open line immediately repeated collapses to one.

    The old site's own ``<style>`` blocks carry one such wart (the
    lightbox block on custom-screens: ``@media (max-width: 1023px) {``
    written twice, balanced only by the stylesheet ending — the browser
    EOF-recovered it into one wrapper). WindPress has no such recovery:
    it is fatal on the first unbalanced brace, so the carried text gets
    the single opener the browser computed. Strictly adjacent lines only;
    a deliberately balanced nested pair would collapse to a stray closer,
    which the volume syntax gate (ticket #49) then fails loudly.
    """
    out: list[str] = []
    for line in css.split("\n"):
        stripped = line.strip()
        if out and stripped and stripped == out[-1].strip() and stripped.endswith("{"):
            continue
        out.append(line)
    return "\n".join(out)


#: At-rules carried whole — their body is not selector-matched.
_WHOLE = frozenset({"font-face", "property"})


def collect_classes(html: str) -> set[str]:
    """Every class token the markup carries."""
    tokens: set[str] = set()
    for value in re.findall(r'class="([^"]*)"', html):
        tokens.update(value.split())
    return tokens


def extract_rules(css_text: str, wanted: set[str]) -> str:
    """The rules of one stylesheet whose selectors the page uses.

    A qualified rule is carried when any of its comma-separated selector
    arms references a wanted class token. ``@media``/``@supports``/
    ``@layer`` blocks keep their inner matches; ``@font-face`` blocks
    are carried whole (the replica needs the faces its rules name);
    ``@keyframes`` blocks are carried when a carried declaration
    references their name — the second pass below, after the selector
    pass has decided what is carried.
    """
    rules = _parse(css_text)
    carried: list[str] = []
    keyframes: dict[str, str] = {}
    for rule in rules:
        if _is_at(rule, "keyframes"):
            name = _first_name(rule)
            if name:
                keyframes[name] = tinycss2.serialize([rule])
            continue
        piece = _keep_rule(rule, wanted)
        if piece is not None:
            carried.append(piece)
    body = "\n".join(carried)
    for name, block in keyframes.items():
        if re.search("animation(?:-name)?:[^;}]*\\b" + re.escape(name) + "\\b", body):
            body += "\n" + block
    return body


def _parse(css_text: str) -> list[Any]:
    parsed: list[Any] = list(
        tinycss2.parse_stylesheet(css_text, skip_comments=True, skip_whitespace=True)
    )
    return parsed


def _is_at(rule: Any, keyword: str) -> bool:
    return bool(rule.type == "at-rule" and rule.lower_at_keyword == keyword)


def _first_name(rule: Any) -> str:
    prelude = tinycss2.serialize(rule.prelude).strip()
    parts = prelude.split()
    return parts[-1] if parts else ""


def _keep_rule(rule: Any, wanted: set[str]) -> str | None:
    """One rule's carried form, or None when the page does not use it."""
    if rule.type == "qualified-rule":
        selector = str(tinycss2.serialize(rule.prelude).strip())
        if _selector_matches(selector, wanted):
            return (
                selector + " { " + str(tinycss2.serialize(rule.content).strip()) + " }"
            )
        return None
    if rule.type == "at-rule":
        keyword = rule.lower_at_keyword
        if keyword in _WHOLE:
            return str(tinycss2.serialize([rule]))
        if rule.content is None:
            return None
        if keyword in ("media", "supports", "layer", "container", "scope"):
            inner = [
                piece
                for piece in (
                    _keep_rule(inner, wanted)
                    for inner in tinycss2.parse_rule_list(
                        rule.content, skip_comments=True, skip_whitespace=True
                    )
                )
                if piece is not None
            ]
            if not inner:
                return None
            prelude = tinycss2.serialize(rule.prelude).strip()
            return f"@{keyword} {prelude} {{\n" + "\n".join(inner) + "\n}"
        return None
    return None


def _selector_matches(selector: str, wanted: set[str]) -> bool:
    """Whether any comma arm of the selector references a wanted class."""
    for arm in selector.split(","):
        for token in re.findall(r"[.][A-Za-z0-9_-]+", arm):
            if token[1:] in wanted:
                return True
    return False


class Stylesheet:
    """One parsed stylesheet, indexed by the class tokens its rules use.

    The old main.css parses once (half a megabyte); every class lookup
    afterwards is a dictionary hit. ``@media`` and friends keep their
    wrapping around the inner rules that matched.
    """

    def __init__(self, name: str, css_text: str) -> None:
        self.name = name
        self._rules = _parse(css_text)
        self._keyframes: dict[str, str] = {}
        self._by_class: dict[str, list[str]] = {}
        self._bare: list[str] = []
        for rule in self._rules:
            if _is_at(rule, "keyframes"):
                key = _first_name(rule)
                if key:
                    self._keyframes[key] = tinycss2.serialize([rule])
                continue
            _index_rule(rule, self._by_class, self._bare, "")

        # Keyframes blocks an indexed rule references (the animation
        # declaration carries the name) — kept beside them.
        referenced = {
            name
            for rules in self._by_class.values()
            for name in _keyframes_names_in("\n".join(rules))
        }
        self._keyframes_used = {
            name: block for name, block in self._keyframes.items() if name in referenced
        }

    def classes(self) -> set[str]:
        """The class tokens this stylesheet defines rules for."""
        return set(self._by_class)

    def rules_for_classes(self, classes: set[str]) -> list[str]:
        """The rules whose selectors use any of these class tokens."""
        out: list[str] = []
        for token in sorted(classes):
            out.extend(self._by_class.get(token, ()))
        return _dedupe(out)

    def keyframes_for(self, css: str) -> list[str]:
        """The keyframes blocks a css body references."""
        names = _keyframes_names_in(css)
        return [block for name, block in self._keyframes.items() if name in names]


def _index_rule(
    rule: Any,
    by_class: dict[str, list[str]],
    bare: list[str],
    prefix: str,
) -> None:
    """One parsed rule into the class index; at-rules recurse."""
    if rule.type == "qualified-rule":
        selector = tinycss2.serialize(rule.prelude).strip()
        text = (
            prefix + selector + " { " + tinycss2.serialize(rule.content).strip() + " }"
        )
        tokens = _selector_classes(selector)
        if not tokens:
            bare.append(text)
            return
        for token in tokens:
            by_class.setdefault(token, []).append(text)
        return
    if rule.type == "at-rule":
        keyword = rule.lower_at_keyword
        if keyword in _WHOLE:
            bare.append(tinycss2.serialize([rule]))
            return
        if rule.content is None:
            return
        if keyword in ("media", "supports", "layer", "container", "scope"):
            prelude = tinycss2.serialize(rule.prelude).strip()
            for sub in tinycss2.parse_rule_list(
                rule.content, skip_comments=True, skip_whitespace=True
            ):
                _index_wrapped(sub, by_class, keyword, prelude)
        return


def _index_wrapped(
    rule: Any, by_class: dict[str, list[str]], keyword: str, prelude: str
) -> None:
    """A rule inside an at-rule, indexed with its wrapper."""
    if rule.type == "qualified-rule":
        selector = tinycss2.serialize(rule.prelude).strip()
        text = (
            f"@{keyword} {prelude} {{\n"
            + selector
            + " { "
            + tinycss2.serialize(rule.content).strip()
            + " }\n}"
        )
        for token in _selector_classes(selector):
            by_class.setdefault(token, []).append(text)
        return
    if rule.type == "at-rule" and rule.lower_at_keyword in _WHOLE:
        # Rare: a font-face inside a media query — carry it bare.
        return None


def _selector_classes(selector: str) -> set[str]:
    """The class tokens a selector's arms reference."""
    tokens: set[str] = set()
    for arm in selector.split(","):
        for token in re.findall(r"[.][A-Za-z0-9_-]+", arm):
            tokens.add(token[1:])
    return tokens


def _dedupe(rules: list[str]) -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for rule in rules:
        if rule not in seen:
            seen.add(rule)
            out.append(rule)
    return out


def _keyframes_names_in(css: str) -> set[str]:
    """The animation names a css body references."""
    names: set[str] = set()
    for match in re.findall(r"animation(?:-name)?:([^;}]+)", css):
        for word in match.split(","):
            word = word.strip().split()[0] if word.strip() else ""
            if (
                word
                and not word.replace(".", "")
                .replace("-", "")
                .replace("s", "")
                .isdigit()
            ):
                names.add(word)
    return names


class Minter:
    """Mints ``md-t<hash>`` classes for inline style declarations."""

    def __init__(self) -> None:
        self._by_hash: dict[str, str] = {}

    def mint(self, declarations: str) -> str:
        """The class name for one inline style declaration list."""
        normalized = ";".join(
            part.strip() for part in declarations.split(";") if part.strip()
        )
        digest = hashlib.sha1(normalized.encode()).hexdigest()[:8]
        name = f"md-t{digest}"
        self._by_hash.setdefault(name, normalized)
        return name

    def css(self) -> str:
        """The minted rules, in mint order."""
        return "\n".join(
            f".{name} {{ {decls} }}" for name, decls in self._by_hash.items()
        )
