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

Selection and context (ticket #70, the #62 C1–C5 root causes):

* the markup's class attributes are read in either quote style — the
  old snippets render whole families with ``class='…'`` — and a class
  name that carries CSS escapes (``md\\:text-5xl``) is indexed by its
  full name, not by the identifier prefix in front of the backslash;
* a rule without a class is not thrown away: its class-less selector
  arms go to the stylesheet's bare list, which the converter emits as
  the ``base`` volume — the old cascade's global baseline (``html``,
  ``body``, the heading reset, ``a``, ``img``, ``xo-container``,
  ``#xo-root``, the old Tailwind preflight) lives there;
* a selector list that mixes both (``.link, a``) keeps its class arm
  in the class index and its class-less arm in the bare list.
"""

from __future__ import annotations

import hashlib
import re
from collections.abc import Sequence
from typing import Any

import tinycss2
import tinycss2.serializer

#: One ``<style>`` element; the group is its text. Used for the section
#: bodies and for the page's head (``sections.head_style_blocks``).
STYLE_BLOCK = re.compile(r"[ \t]*<style[^>]*>(.*?)</style>\n?", re.DOTALL)

#: A ``class`` attribute in either quote style (#70 C1: the old theme
#: emits ``class='…'`` across whole families). The lookbehind keeps
#: bindings and prefixed names — ``x-bind:class``, ``data-class`` —
#: out; those are not the element's static classes.
CLASS_ATTR = re.compile(r"(?<![\w:-])class\s*=\s*(?:\"([^\"]*)\"|'([^']*)')")

#: A ``style`` attribute in either quote style (#70 C1: the same blind
#: spot; folding only the double-quoted ones left the rest inline and,
#: worse, appended a second ``class`` attribute beside a single-quoted
#: one, which HTML ignores).
STYLE_ATTR = re.compile(r"(?<![\w:-])style\s*=\s*(?:\"([^\"]*)\"|'([^']*)')")


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
    """Every class token the markup carries, either quote style.

    #70 C1: the old theme prints whole families with ``class='…'``
    (the header's menu families, the breadcrumb family); reading only
    ``class="…"`` left 62 of the header's 109 classes and all 9 of the
    breadcrumb's outside the selection model, so no rule for them ever
    reached a volume.
    """
    tokens: set[str] = set()
    for double, single in CLASS_ATTR.findall(html):
        tokens.update((double if double else single).split())
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
    return bool(_selector_classes(selector) & wanted)


class Stylesheet:
    """One parsed stylesheet, indexed by the class tokens its rules use.

    The old main.css parses once (half a megabyte); every class lookup
    afterwards is a dictionary hit. ``@media`` and friends keep their
    wrapping around the inner rules that matched.

    Rules whose selector carries no class token are not lost: their
    class-less selector arms sit in ``_bare`` (source order), which
    :meth:`bare_rules` hands to the converter's ``base`` volume (#70 C2).
    A mixed selector keeps both halves — ``.link, a`` is indexed under
    ``link`` and contributes ``a`` to the bare list.
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

    def classes(self) -> set[str]:
        """The class tokens this stylesheet defines rules for."""
        return set(self._by_class)

    def rules_for_classes(self, classes: set[str]) -> list[str]:
        """The rules whose selectors use any of these class tokens."""
        out: list[str] = []
        for token in sorted(classes):
            out.extend(self._by_class.get(token, ()))
        return _dedupe(out)

    def bare_rules(self) -> list[str]:
        """The class-less rules, source order, deduplicated, with the
        keyframes blocks they reference appended.

        These are the old cascade's global baseline — ``html`` and
        ``body``, the heading reset, ``a``, ``img``, ``xo-container``,
        ``#xo-root``, the old Tailwind preflight — which the previous
        class-only contract dropped whole (#70 C2: 140 of them in
        main.css alone).
        """
        rules = _dedupe(self._bare)
        used = [
            block
            for name, block in self._keyframes.items()
            if name in _keyframes_names_in("\n".join(rules))
        ]
        return rules + used

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
    """One parsed rule into the class index and the bare list; at-rules
    recurse with their wrapper kept on both."""
    if rule.type == "qualified-rule":
        selector = tinycss2.serialize(rule.prelude).strip()
        body = tinycss2.serialize(rule.content).strip()
        tokens = _selector_classes(selector)
        if tokens:
            text = prefix + selector + " { " + body + " }"
            for token in tokens:
                by_class.setdefault(token, []).append(text)
        bare_selector = _bare_selector(selector)
        if bare_selector:
            bare.append(prefix + bare_selector + " { " + body + " }")
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
                _index_wrapped(sub, by_class, bare, keyword, prelude)
        return


def _index_wrapped(
    rule: Any,
    by_class: dict[str, list[str]],
    bare: list[str],
    keyword: str,
    prelude: str,
) -> None:
    """A rule inside an at-rule, indexed with its wrapper."""
    if rule.type == "qualified-rule":
        selector = tinycss2.serialize(rule.prelude).strip()
        body = tinycss2.serialize(rule.content).strip()
        wrapper = f"@{keyword} {prelude} {{\n"
        tokens = _selector_classes(selector)
        if tokens:
            text = wrapper + selector + " { " + body + " }\n}"
            for token in tokens:
                by_class.setdefault(token, []).append(text)
        bare_selector = _bare_selector(selector)
        if bare_selector:
            bare.append(wrapper + bare_selector + " { " + body + " }\n}")
        return
    if rule.type == "at-rule" and rule.lower_at_keyword in _WHOLE:
        # Rare: a font-face inside a media query — carry it bare.
        return None


def _selector_arms(selector: str) -> list[list[Any]]:
    """The selector's comma-separated arms, as token lists.

    The split is on top-level commas only: a comma inside ``:is(…)`` or
    ``:not(…)`` belongs to that functional pseudo-class, and a comma
    inside a quoted attribute value is text.
    """
    arms: list[list[Any]] = [[]]
    for token in tinycss2.parse_component_value_list(selector):
        if token.type == "literal" and token.value == ",":
            arms.append([])
        else:
            arms[-1].append(token)
    return [arm for arm in arms if arm]


def _class_names(tokens: Sequence[Any]) -> set[str]:
    """The class names a token run references, functional pseudos included.

    ``.md\\:text-5xl`` tokenizes as a dot and one identifier whose value
    is the *unescaped* ``md:text-5xl``; reading the identifier's value
    keeps the utility's full name (the old tokenizer stopped at the
    backslash and indexed the rule under ``md`` — #70 C4).
    """
    names: set[str] = set()
    for index, token in enumerate(tokens):
        if (
            token.type == "literal"
            and token.value == "."
            and index + 1 < len(tokens)
            and tokens[index + 1].type == "ident"
        ):
            names.add(str(tokens[index + 1].value))
        elif token.type == "function":
            names |= _class_names(token.arguments)
    return names


def _selector_classes(selector: str) -> set[str]:
    """The class tokens a selector's arms reference."""
    names: set[str] = set()
    for arm in _selector_arms(selector):
        names |= _class_names(arm)
    return names


def _bare_selector(selector: str) -> str:
    """The selector's class-less arms as one selector, or ``""``.

    ``.link, a`` → ``a``. The class arms stay with the class index; the
    class-less arms are what the base layer needs (``a`` must keep its
    declarations even though it shares a rule with ``.link``).
    """
    arms = [
        tinycss2.serialize(arm).strip()
        for arm in _selector_arms(selector)
        if not _class_names(arm)
    ]
    return ", ".join(arm for arm in arms if arm)


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


#: The suffix that puts a minted declaration back in the cascade's
#: style-attribute slot (a class rule's only route there).
IMPORTANT = "!important"


def _important(declarations: str) -> str:
    """One inline declaration list with its cascade position preserved.

    Every declaration is marked ``!important`` (:data:`IMPORTANT`). The
    parser's own ``!important`` is kept, never doubled; a declaration the
    parser cannot read (the old Liquid rendered empty values) keeps its
    raw text — the browser dropped it in the old cascade too.
    """
    pieces: list[str] = []
    for declaration in tinycss2.parse_declaration_list(
        declarations, skip_comments=True, skip_whitespace=True
    ):
        if declaration.type != "declaration":
            raw = tinycss2.serialize([declaration]).strip().rstrip(";").strip()
            if raw:
                pieces.append(raw)
            continue
        value = tinycss2.serialize(declaration.value).strip()
        if not value and not declaration.name.startswith("--"):
            # ``background:`` — invalid inline before the fold, invalid
            # now: keeping it verbatim would only add noise.
            continue
        pieces.append(f"{declaration.name}: {value} {IMPORTANT}")
    return "; ".join(pieces)


class Minter:
    """Mints ``md-t<hash>`` classes for inline style declarations.

    Cascade policy (#70 C5/C7): an inline declaration sits in the
    cascade's style-attribute slot — above every selector-based author
    declaration at any specificity, below only author ``!important``
    rules. A plain class rule cannot hold that slot: the old nav label's
    inline ``font-size: 13px`` became ``h3.xo-menu…__label { font-size:
    13px }`` (0,1,1) and lost to the deployed overlay's
    ``#HeaderV9… .xo-menu…__label`` rule (1,1,0), rendering 22.4px
    instead of 13px. A class rule has no route to the style-attribute
    slot except ``!important``, so every minted declaration carries it
    (:func:`_important`).

    Known deviation, recorded rather than hidden: an old stylesheet
    ``!important`` declaration that targeted the same element and
    property would have outranked a *non-important* inline declaration;
    the minted ``!important`` now outranks it instead. Measured on the
    carried input (#70): of the 243 tags on the old home page that carry
    an inline style, none declares a property that an ``!important``
    rule on one of the same element's own classes declares (main.css's
    important declarations are the ``html, body`` height reset and
    component modifiers such as ``.xo-visually-hidden`` and
    ``.xo-menu-horizontal-v1__arrow--hide``), so the deviation changes
    no carried value today. A future input that hits the pair — an
    element with an inline property that one of its classes marks
    important — must make a new ruling rather than inherit this one.
    """

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
        """The minted rules, in mint order, cascade position preserved.

        A declaration list the browser dropped whole (``background:``
        with an empty Liquid value) mints an empty rule; it is skipped
        rather than written as ``.md-t… { }``.
        """
        rules: list[str] = []
        for name, declarations in self._by_hash.items():
            body = _important(declarations)
            if body:
                rules.append(f".{name} {{ {body} }}")
        return "\n".join(rules)
