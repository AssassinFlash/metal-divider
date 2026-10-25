"""Tests for the journal fork (spec 19 §2.2 R1).

The fork has to be the whole of each volume: a rerun of `just blog-fork`
rewrites them, so anything a hand edit added and the fork does not know about
is lost on the next regeneration. These tests pin the three things that used to
live only in the committed files — the folded journal volume, its geometry, and
the display-face split — plus the fact that a rerun is idempotent.
"""

from __future__ import annotations

import os
from pathlib import Path

import pytest

from md_template_convert.blogfork import (
    FACES,
    JOURNAL_TARGET,
    fork_volumes,
    journal_geometry,
    restate_at_root,
)

#: A source checkout with the journal volume, the token volume it carries the
#: geometry in, and one standing rule per face that would survive a naive
#: transform. Deliberately minimal: only what the fold reads.
FACE_CSS = ":is(#tv-blog-index-v1,#tv-blog-category-v1,#tv-blog-search-v1) { padding: var(--proto-space-6); }\n"


def _source(
    tmp_path: Path, *, tokens: str | None = None, journal: str | None = None
) -> Path:
    """A source site's content directory, as the fork reads it."""
    source = tmp_path / "source"
    (source / "windpress").mkdir(parents=True, exist_ok=True)
    for volume in FACES.values():
        (source / "windpress" / volume).write_text(FACE_CSS, encoding="utf-8")
    (source / "windpress" / "tokens.css").write_text(
        tokens
        if tokens is not None
        else ":root {\n  --proto-teal-500: #c9a962;\n  --journal-topic-rail: 224px;\n  --journal-hairline: 1px;\n}\n",
        encoding="utf-8",
    )
    (source / "windpress" / "journal.css").write_text(
        journal
        if journal is not None
        else "/* The source volume's own note. */\n"
        ":is(#tv-blog-index-v1,#tv-blog-category-v1,#tv-blog-search-v1) .journal-shell { padding: var(--proto-space-6); }\n",
        encoding="utf-8",
    )
    return source


def _target(tmp_path: Path) -> Path:
    target = tmp_path / "target"
    (target / "windpress").mkdir(parents=True)
    return target


def _fork(tmp_path: Path, **kwargs: str) -> dict[str, str]:
    """The four volumes, run once, as text by file name."""
    target = _target(tmp_path)
    for path in fork_volumes(_source(tmp_path, **kwargs), target):
        assert path.parent == target / "windpress"
    return {
        path.name: path.read_text(encoding="utf-8")
        for path in sorted((target / "windpress").iterdir())
    }


def test_rem_is_restated_in_px_at_the_design_source_root() -> None:
    """De-linker authored its journal against a 16px root; this site's base
    volume restores the old build's 10px root, so a carried `2rem` would
    compute 20px where the design renders 32px."""
    assert restate_at_root("padding: 2rem;") == "padding: 32px;"
    assert restate_at_root("min-height: 2.75rem;") == "min-height: 44px;"
    assert restate_at_root("font-size: .9375rem;") == "font-size: 15px;"


def test_a_rem_outside_a_length_is_left_alone() -> None:
    """`arem` is an identifier, not a length: only a standalone rem counts."""
    assert restate_at_root(".arem { b: c }") == ".arem { b: c }"


def test_the_fork_writes_exactly_the_four_faces(tmp_path: Path) -> None:
    """The fold must not add a volume: the site's inventory is fixed, and the
    journal volume has no slot of its own (spec 19 §7)."""
    assert sorted(_fork(tmp_path)) == sorted(FACES.values())


def test_the_journal_volume_is_folded_into_the_index_face(tmp_path: Path) -> None:
    """journal.css has no slot in this site's inventory, so the fork folds it
    into the index face. A rerun that forgot the fold would drop the shared
    rules and take three faces' layout with it."""
    volumes = _fork(tmp_path)
    folded = volumes[JOURNAL_TARGET]

    assert ".journal-shell" in folded, "the shared journal rules reach the index volume"
    assert "The source volume's own note." not in folded, (
        "the fold replaces the source's own header"
    )
    assert "The shared journal layout" in folded, "the fold carries its own note"
    for other in set(FACES.values()) - {JOURNAL_TARGET}:
        assert ".journal-shell" not in volumes[other], (
            f"{other} carries only its own deltas"
        )


def test_the_geometry_is_lifted_from_the_token_volume(tmp_path: Path) -> None:
    """The `--journal-*` values live in the source's tokens.css. They are read,
    not hardcoded, so a change at the source reaches the fork."""
    tokens = ":root {\n  --journal-topic-rail: 321px;\n  --journal-hairline: 2px;\n}\n"
    folded = _fork(tmp_path, tokens=tokens)[JOURNAL_TARGET]

    assert "--journal-topic-rail: 321px;" in folded
    assert "--journal-hairline: 2px;" in folded


def test_the_geometry_lands_on_the_three_discovery_faces(tmp_path: Path) -> None:
    """The source scopes the geometry at :root; this site's tokens volume has
    no journal block, so the fold states it on the faces that consume it."""
    geometry = journal_geometry(_source(tmp_path))

    assert geometry.startswith(
        ":is(#md-blog-index,#md-blog-category,#md-blog-search) {"
    )
    assert "--journal-topic-rail: 224px;" in geometry
    assert ":root" not in geometry


def test_a_source_without_journal_geometry_fails_loudly(tmp_path: Path) -> None:
    """An empty tokens volume means the fork is reading something else; a
    silent empty geometry block would ship faces with no rail width."""
    with pytest.raises(SystemExit, match="no --journal-\\* declarations"):
        journal_geometry(
            _source(tmp_path, tokens=":root { --proto-teal-500: #fff; }\n")
        )


def test_the_display_face_reaches_the_journal_heading_only(tmp_path: Path) -> None:
    """The source's one --proto-font-display token feeds both the journal
    headings and the single-post section headings; this site gives the display
    face to the first and keeps the sans face on the second. A token-level
    mapping cannot express that split — it would put the display face on the
    post's section headings too — so the journal's rule is a scoped rewrite."""
    journal = (
        ":is(#tv-blog-index-v1,#tv-blog-category-v1,#tv-blog-search-v1) h1 "
        "{ font-family: var(--proto-font-display); font-size: clamp(1.5rem,2.2vw,2rem); }\n"
    )
    single = (
        "#tv-blog-single-v1 .content h2 "
        "{ margin: var(--proto-space-7) 0 var(--proto-space-4); font: 600 1.5rem/1.4 var(--proto-font-display); }\n"
    )
    source = _source(tmp_path, journal=journal)
    (source / "windpress" / "blog.css").write_text(single, encoding="utf-8")
    target = _target(tmp_path)
    fork_volumes(source, target)

    folded = (target / "windpress" / JOURNAL_TARGET).read_text(encoding="utf-8")
    assert (
        "font-family: var(--md-font-display); font-size: clamp(24px,2.2vw,32px);"
        in folded
    )

    post = (target / "windpress" / "blog.css").read_text(encoding="utf-8")
    assert "font: 600 24px/1.4 var(--md-font-sans);" in post, (
        "the post's section heading keeps the sans face, even though the source "
        "named the same display token"
    )


def test_the_source_slug_icon_mappings_are_dropped(tmp_path: Path) -> None:
    """The mappings key on the source site's own category slugs; this site's
    categories cannot match them, and the generic topic icon covers every one."""
    journal = (
        ":is(#tv-blog-index-v1) .journal-topics .cat-item > a { gap: var(--proto-space-3); }\n"
        ':is(#tv-blog-index-v1) .journal-topics .cat-item > a:is([href$="/applications-blog/"])::before '
        '{ mask-image: url("data:image/svg+xml,AAA"); }\n'
    )
    folded = _fork(tmp_path, journal=journal)[JOURNAL_TARGET]

    assert "applications-blog" not in folded, "the source-slug mapping is gone"
    assert ".journal-topics .cat-item > a { gap: 12px; }" in folded, (
        "the generic icon rule stays"
    )


def test_a_rerun_over_existing_output_is_idempotent(tmp_path: Path) -> None:
    """`just blog-fork` is a rerun, not an append: the second pass writes the
    same bytes, so a fold can never accumulate twice."""
    target = _target(tmp_path)
    first = {
        path.name: path.read_bytes() for path in fork_volumes(_source(tmp_path), target)
    }

    second = {
        path.name: path.read_bytes() for path in fork_volumes(_source(tmp_path), target)
    }

    assert first == second


#: The real design source, when this Mac has the de-linker checkout. The repo
#: does not vendor it (it is another project's working tree), so the check that
#: regeneration reproduces the committed volumes runs only where it is present.
DE_LINKER = (
    Path(os.environ.get("MD_DE_LINKER", Path.home() / "Documents/de-linker"))
    / "plugins/linker-commerce/content"
)


@pytest.mark.skipif(
    not (DE_LINKER / "windpress" / "journal.css").is_file(),
    reason=f"the de-linker design source is not checked out at {DE_LINKER}",
)
def test_regeneration_reproduces_the_committed_volumes(tmp_path: Path) -> None:
    """The committed volumes are the fork's output, byte for byte. This is the
    guard against a hand edit: anything added to a volume and not taught to the
    fork fails here, before a rerun silently drops it."""
    target = _target(tmp_path)
    fork_volumes(DE_LINKER, target)

    committed = Path(__file__).parents[3] / "plugins/md-site/content/windpress"
    for volume in FACES.values():
        assert (target / "windpress" / volume).read_bytes() == (
            committed / volume
        ).read_bytes(), f"{volume} is not what the fork writes: it was hand-edited"
