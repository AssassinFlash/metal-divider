"""The conversion pipeline's section scanner.

The pipeline's first rule is the verbatim move (spec 19 §2.1): the old
site's rendered HTML is the only structural source, and the converter
never re-serialises it. This module therefore works on string offsets —
it finds a Shopify section wrapper, balances the wrapping ``div`` by
counting open/close tags inside, and returns the byte range of the
wrapper. Everything downstream slices the original bytes.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

# The wrapper every Shopify section renders as — a div on most templates,
# a section element on others (the product template):
# <div|section id="shopify-section-template--…__name" class="shopify-section …">
SECTION_OPEN = re.compile(
    r'<(?P<tag>div|section)\s+id="shopify-section-(?P<key>[^"]+)"[^>]*>', re.IGNORECASE
)

# The page's head and its style blocks. The old theme's design tokens are
# printed by ``snippets/settings-adapter.liquid`` into the layout head —
# 174 custom properties in one ``<style>`` block, outside every Shopify
# section, so the section-only model never saw them (#70 C3).
HEAD = re.compile(r"<head\b[^>]*>(.*?)</head>", re.DOTALL | re.IGNORECASE)
HEAD_STYLE = re.compile(r"[ \t]*<style[^>]*>(.*?)</style>\n?", re.DOTALL)
#: A style block is a design-token block when it defines custom
#: properties or a ``:root`` scope; the platform's own head blocks
#: (accelerated checkout, app widgets) carry neither.
TOKEN_BLOCK = re.compile(r":root\b|--[A-Za-z0-9_-]+\s*:")


@dataclass(frozen=True)
class Section:
    """One Shopify section wrapper: its key and its byte range."""

    key: str
    start: int
    end: int  # exclusive; end of the wrapper's closing </div>

    @property
    def name(self) -> str:
        """The section's own name, without the section/template prefix."""
        return self.key.rsplit("__", 1)[-1]


def sections(html: str) -> list[Section]:
    """The page's section wrappers, in document order.

    The scan is the one Shopify guarantees: one wrapping div per section,
    each carrying a ``shopify-section-…`` id. Nested divs are handled by
    the balance count, so a section's content can contain any number of
    its own divs.
    """
    found: list[Section] = []
    for match in SECTION_OPEN.finditer(html):
        # Balance only the wrapper's own kind: a div wrapper closes on
        # </div>, a section wrapper on </section>. The other kind nests
        # freely inside without disturbing the count, which is how the
        # product template nests its own sections inside the wrapper.
        tag = match.group("tag").lower()
        step = re.compile(rf"(<{tag}\b[^>]*>|</{tag}>)", re.IGNORECASE)
        depth = 1
        pos = match.end()
        while depth > 0:
            inner = step.search(html, pos)
            if inner is None:  # malformed input: refuse, never guess.
                raise ValueError(
                    f"unbalanced section wrapper at offset {match.start()}"
                )
            depth += 1 if inner.group(0).startswith(f"<{tag}") else -1
            pos = inner.end()
        found.append(Section(match.group("key"), match.start(), pos))
    return found


def section_html(html: str, section: Section, *, strip_wrapper: bool = True) -> str:
    """A section's original bytes; the wrapper's inner content when asked."""
    if not strip_wrapper:
        return html[section.start : section.end]
    tag_match = SECTION_OPEN.match(html, section.start)
    if tag_match is None:  # pragma: no cover — sections() built the range.
        raise ValueError(f"section at {section.start} no longer opens the same way")
    inner_start = html.index(">", section.start) + 1
    # The wrapper's own closing tag: the count balanced there, which
    # sections() guarantees, so this slice holds the inner content.
    inner_end = section.end - len(f"</{tag_match.group('tag').lower()}>")
    return html[inner_start:inner_end]


def head_style_blocks(html: str) -> list[str]:
    """The design-token ``<style>`` blocks of the page's ``<head>``.

    The old site's ``:root`` custom properties — the colour schemes, the
    font families and scales, the spacing and card variables — are
    printed into the layout head, not into a section. The converter's
    section-only model cannot reach them (#70 C3: 42 variables used by
    the carried rules and defined nowhere), so the page's head is read
    separately and its token blocks become the volumes' ``base`` layer.

    A head block counts as a token block when it defines custom
    properties or a ``:root`` scope; the platform's own blocks carry
    neither. A page without a ``<head>`` (a test fixture, a fragment)
    yields none.
    """
    head = HEAD.search(html)
    if head is None:
        return []
    blocks: list[str] = []
    for css in HEAD_STYLE.findall(head.group(1)):
        stripped = css.strip()
        if stripped and TOKEN_BLOCK.search(stripped):
            blocks.append(stripped)
    return blocks
