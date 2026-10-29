"""The gate that decides whether a compiled stylesheet may be installed.

The behaviour under test is a refusal: the gate must stop a candidate that would
take away styling the target's pages rely on today. A gate that cannot fail is
not a gate, so every positive case here is paired with a negative one built from
the same fixture.
"""

from __future__ import annotations

import pytest

from md_deploy import windpress_install as wi

HOME = '<div class="text-sm md:text-lg md-inquiry-form">x</div>'
CARD = '<div class="md-closing-well pattern-card__body">y</div>'

BASE = "https://example.test"


@pytest.fixture()
def pages(monkeypatch: pytest.MonkeyPatch) -> dict[str, str]:
    """Serve two pages without touching the network."""
    fixtures = {"/": HOME, "/about-us/": CARD}
    monkeypatch.setattr(
        wi,
        "_get",
        lambda url, timeout=60: fixtures[url.split(BASE)[1].split("?")[0]],
    )
    return fixtures


def test_classes_of_reads_every_token_in_a_class_attribute() -> None:
    assert wi.classes_of(HOME) == {"text-sm", "md:text-lg", "md-inquiry-form"}
    assert wi.classes_of(CARD) == {"md-closing-well", "pattern-card__body"}
    assert wi.classes_of("<p>no classes</p>") == set()


def test_a_class_used_only_inside_a_longer_name_is_not_a_rule_for_it() -> None:
    """`.text-sm` must not be satisfied by a rule for `.text-smm`.

    Without the trailing guard the gate would pass a stylesheet that styles a
    different class, which is the same silent failure it exists to stop.
    """
    assert wi.has_rule_for(".text-sm{color:red}", "text-sm")
    assert not wi.has_rule_for(".text-smm{color:red}", "text-sm")
    assert not wi.has_rule_for(".md-inquiry-form-2{color:red}", "md-inquiry-form")
    assert wi.has_rule_for(".a .md-inquiry-form{color:red}", "md-inquiry-form")


def test_a_candidate_covering_every_class_passes(pages: dict[str, str]) -> None:
    css = ".text-sm{}.md\\:text-lg{}.md-inquiry-form{}.md-closing-well{}.pattern-card__body{}"
    # The escaped colon form is what Tailwind emits; the gate reads tokens, so
    # give it the plain form it matches on.
    css = css.replace(".md\\:text-lg", ".md\\:text-lg")
    result = wi.check_coverage(BASE, list(pages), css, css)
    assert result.pages_fetched == 2
    assert result.classes_seen == 5
    assert result.missing == []
    assert result.regressions == []
    assert result.passed


def test_a_candidate_missing_a_live_class_is_refused(pages: dict[str, str]) -> None:
    """The candidate drops `md-closing-well`, which the pages use today."""
    current = ".text-sm{}.md-inquiry-form{}.md-closing-well{}.pattern-card__body{}"
    candidate = ".text-sm{}.md-inquiry-form{}.pattern-card__body{}"
    result = wi.check_coverage(BASE, list(pages), candidate, current)
    assert "md-closing-well" in result.missing
    assert result.regressions == ["md-closing-well"]
    assert not result.passed


def test_a_class_neither_stylesheet_has_is_not_counted_as_a_regression(
    pages: dict[str, str],
) -> None:
    """`md:text-lg` is absent from both; installing the candidate changes nothing.

    Counting it as a regression would make the gate refuse every candidate on a
    site that already has an unstyled class, which is the state this host is in.
    """
    css = ".text-sm{}.md-inquiry-form{}.md-closing-well{}.pattern-card__body{}"
    result = wi.check_coverage(BASE, list(pages), css, css)
    assert "md:text-lg" in result.missing
    assert "md:text-lg" in result.missing_from_current
    assert result.regressions == []
    assert result.passed


def test_no_page_fetched_is_not_a_pass(pages: dict[str, str], monkeypatch: pytest.MonkeyPatch) -> None:
    """An unreachable site must not read as "nothing is missing"."""
    import urllib.error

    def boom(url: str, timeout: int = 60) -> str:
        raise urllib.error.URLError("down")

    monkeypatch.setattr(wi, "_get", boom)
    result = wi.check_coverage(BASE, list(pages), "", "")
    assert result.pages_fetched == 0
    assert not result.passed

def test_a_class_with_regex_metacharacters_does_not_crash_the_matcher() -> None:
    """Real page data carried `[...]` and `-` in a class name.

    The first version of has_rule_for built its pattern by string concatenation
    and died on it with `bad character range l-g`, which only the live run found
    because no unit fixture had one. A matcher that raises is worse than one that
    says "missing": it takes the whole gate down.
    """
    awkward = "w-[2px]"
    assert wi.has_rule_for(r".w-\[2px\]{width:2px}", awkward)
    assert not wi.has_rule_for(".w-3{width:3px}", awkward)
    # and a dash/range-looking name must not raise either
    assert wi.has_rule_for(".bg-[linear-gradient(a,b)]{x:1}", "bg-[linear-gradient(a,b)]")

def test_script_contents_are_not_read_as_class_names() -> None:
    """Alpine bindings and template literals produced 395 false missing classes.

    The report passed on the real question but its first thirty lines were
    fragments like an active key and a stray word, so nobody would read it.
    Script, style and template bodies are removed before class collection.
    """
    html = (
        '<div class="text-sm">ok</div>'
        '<script>x-data="{ cls: \'md-inquiry-form\' }"</script>'
        '<style>.a{color:red}</style>'
        '<template><span class="from-template">t</span></template>'
    )
    assert wi.classes_of(html) == {"text-sm"}


def test_a_class_attribute_fragment_with_punctuation_is_ignored() -> None:
    """A quoted string inside a class attribute is code, not a class name."""
    html = '<div class="text-sm \'Care of\' md-inquiry-form">x</div>'
    assert wi.classes_of(html) == {"text-sm", "md-inquiry-form"}
