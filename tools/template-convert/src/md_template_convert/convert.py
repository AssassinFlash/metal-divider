"""The conversion pipeline's driver: raw rendered pages in, LiveCanvas
templates and WindPress volumes out (spec 19 §4).

Per page: split the old site's rendered HTML into its Shopify sections,
keep the chrome ones out (they become the two partials), and move each
content section through the mechanical transforms — T1 scaffolding, T3
scripts, T2 styles into volumes (the minted classes and the old
stylesheets' rules), T4 asset addresses, T7 root-relative — without
ever re-serialising the markup.

The volumes fill in two passes, so a rule lands where it belongs (spec
19 §5.3/§7): the first pass records which sections carry which classes
across every page, the second writes each stylesheet rule into one
volume — chrome for the chrome classes, a component volume for the
classes a component's sections share across pages, the one template
that uses them otherwise. The minted inline classes always land in the
page's own template volume: an inline style is page-specific even when
its section is a component (§5.3: the shared thing is the recipe, not
the copy).

The ``base`` volume sits below all of them (#70 C2/C3): the old head's
``:root`` design tokens (the ``settings-adapter`` block, outside every
Shopify section) and every stylesheet's class-less rules — ``html`` and
``body``, the heading reset, ``a``, ``img``, ``xo-container``,
``#xo-root``, the old Tailwind preflight. The class distribution above
is untouched: a class's rules still land in exactly the volume the page
plan gives it.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

from . import pageplan
from .csslib import (
    STYLE_ATTR,
    STYLE_BLOCK,
    Minter,
    Stylesheet,
    collapse_duplicated_openers,
    collect_classes,
)
from .sections import head_style_blocks, section_html, sections
from .transform import map_assets, root_relative, strip_scaffolding, strip_scripts

SETTINGS_VARS = re.compile(r":root|--color-|--gradient-")

#: The volume the class-less baseline and the head tokens land in (#70).
#: WindPress imports the volume files alphabetically into the components
#: layer (Presentation/WindPressVolume.php), so this name places the
#: baseline ahead of every component and page volume but ``about.css``
#: — which carries no element selectors (checked over the 36 volumes).
BASE_VOLUME = "base"

#: The old theme's stylesheets the pages link (F9: one external CSS link
#: per page, these four behind it). Their rules move into the volumes
#: for the classes the templates still carry.
STYLESHEETS = (
    "main.css",
    "tailwind-production.css",
    "tailwin.min.css",
    "gp-global.css",
)

HEADER_SECTIONS = (
    "header_v1_main",
    "search",
    "mega_menu_v2_HEAtbw",
    "mega_menu_v2_x7pRC4",
)
FOOTER_SECTION = "footer"


class Converter:
    """One run over the raw pages; one instance accumulates the volumes."""

    def __init__(
        self,
        raw_dir: Path,
        theme_assets: Path,
        plugin_dir: Path,
        asset_map: dict[str, str],
    ) -> None:
        self.raw = raw_dir
        self.plugin = plugin_dir
        self.asset_map = asset_map
        self.stylesheets = [
            Stylesheet(name, (theme_assets / name).read_text(encoding="utf-8"))
            for name in STYLESHEETS
            if (theme_assets / name).is_file()
        ]
        #: volume name → ordered unique rules
        self.volume_rules: dict[str, list[str]] = {}
        self._volume_seen: dict[str, set[str]] = {}
        #: per-volume counters for the DEBT ledger (spec 19 §4.3: the
        #: incorporate commit reports what left each page)
        self.debt: dict[str, dict[str, int]] = {}
        #: pass one's record: class token → the volumes it would land in
        self._class_volumes: dict[str, set[str]] = {}
        self._page_minters: dict[str, Minter] = {}
        self._page_style_blocks: dict[str, list[tuple[str, str]]] = {}
        self._chrome_minter: Minter | None = None
        self._chrome_classes: set[str] = set()
        self._chrome_style_blocks: list[tuple[str, str]] = []
        #: the head's design-token blocks, first page seen, deduplicated
        #: (every page carries the same rendered settings-adapter block)
        self._head_tokens: list[str] = []
        self._pending_style_blocks = 0

    # -- volume plumbing ------------------------------------------------

    def add_rules(self, volume: str, css: str) -> None:
        """CSS rules into a volume, deduplicated across pages.

        The dedup unit is the whole rule (a multi-line rule is one unit),
        so a repeated rule drops as one piece and a rule's inner lines —
        the closing brace, a declaration another rule already carried —
        always travel with it. The line-per-unit form this replaces
        (#49: 24 of the 36 volumes were brace-unbalanced, WindPress
        fatal on the first missing brace) deduplicated bare ``}`` lines
        and repeated declaration lines away mid-rule.
        """
        seen = self._volume_seen.setdefault(volume, set())
        rules = self.volume_rules.setdefault(volume, [])
        for rule in _rule_units(css):
            if rule not in seen:
                seen.add(rule)
                rules.append(rule)

    def note(self, template: str, key: str, value: int) -> None:
        page = self.debt.setdefault(
            template,
            {"sections": 0, "scripts": 0, "inline_styles": 0, "style_blocks": 0},
        )
        page[key] = page.get(key, 0) + value

    # -- the per-section pipeline ---------------------------------------

    def convert_body(
        self, body: str, minter: Minter
    ) -> tuple[str, int, int, list[str]]:
        """One section's body through T1→T3→T2→T4→T7: its markup, the
        script count, the inline-style count, and its style blocks' css
        (each still raw, for the caller to file into a volume). The slot
        pass (spec 19 §2.2) runs before this — on the raw body — so the
        transforms never touch a slot's template tags."""
        body, _ = strip_scaffolding(body)
        scripts = len(re.findall(r"<script\b", body))
        inline = len(STYLE_ATTR.findall(body))
        blocks = [
            collapse_duplicated_openers(css.strip())
            for css in STYLE_BLOCK.findall(body)
        ]
        body, _ = strip_scripts(body)
        body = STYLE_BLOCK.sub("", body)
        body = _fold_inline(body, minter)
        body, _ = map_assets(body, self.asset_map)
        body = root_relative(body)
        return body, scripts, inline, blocks

    # -- the per-page pipeline ------------------------------------------

    def ingest_head(self, html: str) -> None:
        """The page head's design-token blocks into the model (#70 C3).

        Every standard page carries the same rendered
        ``settings-adapter`` block, so the first page's copy is kept and
        later duplicates drop; ``distribute()`` writes it to the
        ``base`` volume, where the rules that consume the variables —
        the carried class rules and the class-less baseline — can
        resolve them.
        """
        for css in head_style_blocks(html):
            if css not in self._head_tokens:
                self._head_tokens.append(css)

    def convert_page(
        self,
        raw_file: Path,
        template: str,
        slot: Callable[[str], str] | None = None,
    ) -> dict[str, object]:
        """One page: its template HTML, and the classes it contributes to
        the volume distribution (which runs once every page is done).

        ``slot``, when the page plan names one, runs on each section's
        raw body before the transforms: the data-driven faces (PDP, the
        catalog grid, the hub) become one template whose per-product
        values are LiveCanvas tags and the md_* assemblies.
        """
        html = raw_file.read_text(encoding="utf-8")
        self.ingest_head(html)
        page_sections = sections(html)
        minter = Minter()
        pieces: list[str] = []
        for section in page_sections:
            if pageplan.is_chrome(section.name):
                continue
            body = section_html(html, section)
            if slot is not None:
                body = slot(body)
            piece, scripts, inline, blocks = self.convert_body(body, minter)
            pieces.append(f"<!-- md-section: {section.name} -->\n{piece}")
            self.note(template, "sections", 1)
            self.note(template, "scripts", scripts)
            self.note(template, "inline_styles", inline)
            self.note(template, "style_blocks", len(blocks))
            # Pass one: which classes this section carries, and which
            # volume the page plan would give it.
            volume = pageplan.volume_for(section.name, template)
            for token in collect_classes(piece):
                self._class_volumes.setdefault(token, set()).add(volume)
            # The style blocks are page-specific: settings variables go
            # to chrome (every page shares them), the rest to the
            # section's volume.
            for css in blocks:
                target = "chrome" if SETTINGS_VARS.search(css) else volume
                self._page_style_blocks.setdefault(template, []).append((target, css))
        template_html = "\n".join(pieces)

        # The page's volume contributions (pass two runs after every
        # page's pass one; see distribute()).
        self._page_minters[template] = minter
        return {"template": template, "html": template_html}

    def convert_chrome(self) -> dict[str, str]:
        """The two partials, from the standard pages' chrome sections.

        The four header sections and the footer are the same markup on
        every standard page; the home page's copy is the source, moved
        through the same transforms with their styles in chrome.css.
        custom-screens' landing chrome is deliberately not here (spec
        19 §2.2 L17): it is page content with a DEBT ledger row, not a
        second global chrome.
        """
        html = (self.raw / "home" / "index.html").read_text(encoding="utf-8")
        self.ingest_head(html)
        header: list[str] = []
        footer: list[str] = []
        minter = Minter()
        for section in sections(html):
            if section.name in HEADER_SECTIONS:
                piece, _s, _i, blocks = self.convert_body(
                    section_html(html, section), minter
                )
                header.append(f"<!-- md-section: {section.name} -->\n{piece}")
                self._chrome_style_blocks.extend(("chrome", css) for css in blocks)
            elif section.name == FOOTER_SECTION:
                piece, _s, _i, blocks = self.convert_body(
                    section_html(html, section), minter
                )
                footer.append(f"<!-- md-section: {section.name} -->\n{piece}")
                self._chrome_style_blocks.extend(("chrome", css) for css in blocks)
        self._chrome_minter = minter
        return {"header": "\n".join(header), "footer": "\n".join(footer)}

    # -- pass two: the volumes ------------------------------------------

    def distribute(self) -> None:
        """Write every class's stylesheet rules into its one volume.

        A class's volume: chrome's when the chrome sections carry it; a
        component's when it appears in more than one volume candidate
        and a component is among them; otherwise the single volume the
        one section that carries it maps to. The minted inline rules
        join the page's own template volume.

        The ``base`` volume is written first and holds what belongs to
        no class: the head's design tokens and every stylesheet's
        class-less rules (#70 C2/C3). It is a separate pass on purpose —
        adding it does not move a single class out of the volume the
        distribution above gives it.
        """
        self._distribute_base()

        # The chrome classes come from the chrome minter's output — the
        # partials' markup — plus what the header/footer sections' own
        # classes add.
        # Every class the chrome partials carry belongs to chrome.css.
        for token in self._chrome_classes:
            self._class_volumes.setdefault(token, set()).add("chrome")

        for token, candidates in sorted(self._class_volumes.items()):
            if "chrome" in candidates:
                volume = "chrome"
            elif len(candidates) > 1:
                component = [
                    c for c in sorted(candidates) if c in pageplan.COMPONENT_VOLUMES
                ]
                volume = component[0] if component else sorted(candidates)[0]
            else:
                volume = next(iter(candidates))
            for sheet in self.stylesheets:
                rules = sheet.rules_for_classes({token})
                if rules:
                    body = "\n".join(rules)
                    carried = sheet.keyframes_for(body)
                    if carried:
                        body += "\n" + "\n".join(carried)
                    self.add_rules(volume, body)

        # The style blocks the sections carried and the minted inline
        # rules close the volumes out. Minted rules land in the page's
        # own template volume — an inline style is page-specific even
        # when its section is a component (§5.3).
        for blocks in self._page_style_blocks.values():
            for volume, css in blocks:
                self.add_rules(volume, css)
        for volume, css in self._chrome_style_blocks:
            self.add_rules(volume, css)
        for template, minter in self._page_minters.items():
            self.add_rules(template, minter.css())
        if self._chrome_minter is not None:
            self.add_rules("chrome", self._chrome_minter.css())

    def _distribute_base(self) -> None:
        """The ``base`` volume: head tokens, then each sheet's class-less
        rules in the sheets' own order (the old pages' link order), so
        the later sheet's global declarations keep the precedence they
        had in the old cascade — main.css's baseline first, the old
        Tailwind preflight after it."""
        pieces: list[str] = list(self._head_tokens)
        for sheet in self.stylesheets:
            bare = sheet.bare_rules()
            if bare:
                pieces.append("\n".join(bare))
        if pieces:
            self.add_rules(BASE_VOLUME, "\n".join(pieces))

    def record_chrome_classes(self, partials: dict[str, str]) -> None:
        """Pass one for the chrome: the classes the partials carry."""
        for html in partials.values():
            self._chrome_classes |= collect_classes(html)

    # -- output ----------------------------------------------------------

    def write(self) -> dict[str, Path]:
        """The volumes as files, each with the repo comment header."""
        out: dict[str, Path] = {}
        volumes_dir = self.plugin / "content" / "windpress"
        volumes_dir.mkdir(parents=True, exist_ok=True)
        for volume, rules in sorted(self.volume_rules.items()):
            path = volumes_dir / f"{volume}.css"
            header = (
                f"/* md-site volume: {volume}.css — carried from the old site's\n"
                " * markup and stylesheets by the conversion pipeline (ticket\n"
                " * #32, spec 19 §4.2 T2). Rules keep their source order.\n"
                " */\n"
            )
            path.write_text(header + "\n".join(rules) + "\n", encoding="utf-8")
            out[volume] = path
        return out


def _rule_units(css: str) -> list[str]:
    """A css body's top-level rule units: stripped lines, grouped so a
    unit opens at brace depth 0 and runs until the depth returns to 0.

    A multi-line rule — a ``@media`` wrapper, a hand-formatted block from
    the old pages' ``<style>`` elements — is therefore one unit, and its
    closing brace cannot be deduplicated away from the declarations it
    closes. The brace count is per stripped line and string-blind; a
    brace inside a quoted value can only merge two adjacent units into
    one, which keeps more text than strictly necessary and never drops
    a line the way the per-line split did.
    """
    units: list[list[str]] = []
    depth = 0
    for line in css.split("\n"):
        piece = line.strip()
        if not piece:
            continue
        if depth == 0:
            units.append([])
        units[-1].append(piece)
        depth = max(0, depth + piece.count("{") - piece.count("}"))
    return ["\n".join(unit) for unit in units]


def _fold_inline(body: str, minter: Minter) -> str:
    """Mint classes for inline styles and fold them into class attributes.

    Tag by tag, either quote style (#70 C1/C5): each ``style`` attribute
    — ``style="…"`` or ``style='…'`` — mints ``md-t<hash>`` and the
    minted name joins the tag's **first** ``class`` attribute, in that
    attribute's own quote style, or becomes one when the tag has none.

    Appending to the *first* class attribute is load-bearing: the old
    theme repeats the attribute on some tags (a wrapper carries
    ``class="xo-header-v9__inner" class="xo-section …"``), and HTML
    honours the first, so a minted name appended to the second would sit
    invisible — the same defect the old marker merge produced on a
    single-quoted class by creating a second attribute (three elements in
    the header partial, #62 C1).
    """
    out: list[str] = []
    cursor = 0
    for match in _TAG.finditer(body):
        if match.start() < cursor:
            continue
        start = match.start()
        if body.startswith("<!--", start):
            close = body.find("-->", start)
            end = len(body) if close < 0 else close + 3
            out.append(body[cursor:end])  # comments are text, never folded
        else:
            end = _tag_end(body, start)
            out.append(body[cursor:start])
            out.append(_fold_tag(body[start:end], minter))
        cursor = end
    out.append(body[cursor:])
    return "".join(out)


#: The start of an element tag or a comment. An attribute's closing
#: ``>`` is found by :func:`_tag_end`, so a value may hold one
#: (``x-data="a > b"``, an inline handler).
_TAG = re.compile(r"<!--|</?[A-Za-z]")


def _tag_end(body: str, start: int) -> int:
    """The offset just past the tag's ``>``, attribute quotes respected."""
    index = start + 1
    quote: str | None = None
    while index < len(body):
        char = body[index]
        if quote is not None:
            if char == quote:
                quote = None
        elif char in "\"'":
            quote = char
        elif char == ">":
            return index + 1
        index += 1
    return len(body)


@dataclass(frozen=True)
class Attr:
    """One attribute of a tag: its name and its value's span.

    ``value_start``/``value_end`` bound the value's content — the quotes
    excluded. ``quote`` is the quote character, or ``""`` for an
    unquoted value and for a valueless attribute.
    """

    name: str
    name_start: int
    value_start: int
    value_end: int
    quote: str


def _attributes(tag: str) -> list[Attr]:
    """A tag's attributes, its quoted values walked over, not through.

    A ``style=`` written inside another attribute's value — an HTML
    string in a ``data-…`` value, a handler body such as
    ``this.style='…'`` — is text, not an attribute, so the fold never
    mistakes it for one. Tag names are skipped; an attribute without a
    value carries an empty span.
    """
    attrs: list[Attr] = []
    index = 1
    if tag.startswith("</"):
        return attrs
    while index < len(tag) and tag[index] not in " \t\r\n/>":  # the tag name
        index += 1
    while index < len(tag):
        char = tag[index]
        if char in " \t\r\n/":
            index += 1
            continue
        if char == ">":
            break
        name_start = index
        while index < len(tag) and tag[index] not in " \t\r\n=/>":
            index += 1
        name = tag[name_start:index].lower()
        probe = index
        while probe < len(tag) and tag[probe] in " \t\r\n":
            probe += 1
        if probe >= len(tag) or tag[probe] != "=":
            attrs.append(Attr(name, name_start, index, index, ""))
            index = probe if probe > index else index + 1
            continue
        probe += 1
        while probe < len(tag) and tag[probe] in " \t\r\n":
            probe += 1
        if probe < len(tag) and tag[probe] in "\"'":
            quote = tag[probe]
            value_start = probe + 1
            close = tag.find(quote, value_start)
            value_end = len(tag) if close < 0 else close
            attrs.append(Attr(name, name_start, value_start, value_end, quote))
            index = value_end + 1
        else:
            value_start = probe
            while probe < len(tag) and tag[probe] not in " \t\r\n>":
                probe += 1
            attrs.append(Attr(name, name_start, value_start, probe, ""))
            index = probe
    return attrs


def _fold_tag(tag: str, minter: Minter) -> str:
    """One tag's inline styles folded into its first class attribute."""
    attrs = _attributes(tag)
    styles = [attr for attr in attrs if attr.name == "style"]
    if not styles:
        return tag
    minted = " ".join(
        minter.mint(tag[attr.value_start : attr.value_end]) for attr in styles
    )
    edits: list[tuple[int, int, str]] = []
    for attr in styles:
        # The attribute goes, with the whitespace that separated it.
        start = attr.name_start
        while start > 0 and tag[start - 1] in " \t\r\n":
            start -= 1
        edits.append((start, attr.value_end + (1 if attr.quote else 0), ""))
    class_attr = next((attr for attr in attrs if attr.name == "class"), None)
    if class_attr is not None:
        existing = tag[class_attr.value_start : class_attr.value_end].strip()
        merged = f"{existing} {minted}".strip()
        if class_attr.quote:
            edits.append((class_attr.value_start, class_attr.value_end, merged))
        else:  # an unquoted class value becomes a quoted one
            edits.append(
                (class_attr.name_start, class_attr.value_end, f'class="{merged}"')
            )
    else:
        # Create the attribute before the tag closes (and before a
        # self-closing ``/``), with a separator space unless one is
        # already there.
        insert = len(tag) - 1
        if tag[insert - 1] == "/":
            insert -= 1
        space = "" if insert > 0 and tag[insert - 1] in " \t\r\n" else " "
        edits.append((insert, insert, f'{space}class="{minted}"'))
    folded = tag
    for start, end, replacement in sorted(edits, reverse=True):
        folded = folded[:start] + replacement + folded[end:]
    return folded
