"""Tests for the volume syntax gate (ticket #49)."""

from __future__ import annotations

from pathlib import Path

import pytest

from md_template_convert.volumecheck import (
    check_utility_rem,
    check_volume,
    check_volumes,
    main,
)


def _plugin_with(tmp_path: Path, name: str, css: str) -> Path:
    """A plugin directory whose windpress volume dir holds one volume."""
    volumes = tmp_path / "content" / "windpress"
    volumes.mkdir(parents=True)
    (volumes / name).write_text(css, encoding="utf-8")
    return tmp_path


def test_a_balanced_volume_passes() -> None:
    css = (
        "/* header */\n"
        ".a { color: red; }\n"
        "@media (min-width: 768px) {\n"
        ".b {\nmargin: 0;\n}\n"
        "}\n"
        "@keyframes spin { 0% { opacity: 0 } 100% { opacity: 1 } }\n"
    )
    assert check_volume(css) == []


def test_braces_inside_strings_and_comments_are_not_structural() -> None:
    css = '.a { content: "{"; }\n.b { content: "}" }\n/* } */\n.c { d: e }'
    assert check_volume(css) == []


def test_an_unclosed_rule_is_the_missing_closer_report() -> None:
    """#44's corruption shape: the closing brace lines the old per-line
    dedup dropped. The report names the depth the file ends at."""
    css = ".a {\ncolor: red;\n.b {\nmargin: 0;\n"
    problems = check_volume(css)
    assert len(problems) == 1
    assert "brace depth +2" in problems[0]


def test_a_stray_closer_is_reported_with_its_line() -> None:
    problems = check_volume(".a { color: red; }\n}\n")
    assert any(
        "'}' closes more than it opened" in p and "line 2" in p for p in problems
    )


def test_an_unterminated_comment_is_reported() -> None:
    assert any("unterminated comment" in p for p in check_volume(".a { b: c } /* oops"))


def test_an_unterminated_string_is_reported() -> None:
    assert any(
        "unterminated string" in p for p in check_volume('.a { content: "oops }')
    )


def test_the_directory_report_names_only_failing_volumes(tmp_path: Path) -> None:
    plugin = _plugin_with(tmp_path, "good.css", ".a { b: c }\n")
    (plugin / "content" / "windpress" / "bad.css").write_text(
        ".a {\nb: c;\n", encoding="utf-8"
    )
    report = check_volumes(plugin)
    assert list(report) == ["bad.css"]
    assert "brace depth +1" in report["bad.css"][0]


def test_an_empty_volume_directory_is_itself_a_problem(tmp_path: Path) -> None:
    plugin = tmp_path / "content" / "windpress"
    plugin.mkdir(parents=True)
    assert "(content/windpress)" in check_volumes(tmp_path)


def test_main_exits_zero_on_pass_and_one_on_failure(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    plugin = _plugin_with(tmp_path, "v.css", ".a { b: c }\n")
    assert main([str(plugin)]) == 0
    assert "1 volumes balanced" in capsys.readouterr().out
    (plugin / "content" / "windpress" / "v.css").write_text(
        ".a {\nb: c;\n", encoding="utf-8"
    )
    assert main([str(plugin)]) == 1
    assert "brace depth +1" in capsys.readouterr().out


def test_main_rejects_a_wrong_argument_count() -> None:
    assert main([]) == 2


# --- the px utility scale gate (#118) ---------------------------------------
#
# base.css carries the old build's 62.5% root, so a rem-valued utility
# renders at 0.625x its Tailwind declaration: px-4 computed 10px against 16px,
# h-11 27.5px against 44px, max-w-7xl 800px against 1280px. The volumes hold a
# second, literal copy of those utilities that outranks the variable-based
# ones, so the value has to be px there too.


def test_a_utility_that_declares_rem_is_a_problem() -> None:
    problems = check_utility_rem(".px-4 { padding-left:1rem;padding-right:1rem }\n")
    assert len(problems) == 1
    assert "px-4" in problems[0]
    assert "#118" in problems[0]


def test_a_negative_utility_is_checked_too() -> None:
    problems = check_utility_rem(".-mt-8 { margin-top:-2rem }\n")
    assert len(problems) == 1
    assert "-mt-8" in problems[0]


def test_a_px_utility_passes() -> None:
    assert check_utility_rem(".px-4 { padding-left:16px;padding-right:16px }\n") == []


def test_a_scoped_rule_is_the_page_own_choice() -> None:
    # `.card .px-4` is not a copy of Tailwind's definition.
    assert check_utility_rem(".card .px-4 { padding-left:1rem }\n") == []


def test_a_state_rule_is_the_page_own_choice() -> None:
    assert check_utility_rem(".px-4:hover { padding-left:1rem }\n") == []


def test_a_site_component_sharing_a_prefix_is_exempt() -> None:
    # .hero-chip is this site's own component, not a utility copy.
    assert check_utility_rem(".hero-chip { gap:0.5rem }\n") == []


def test_the_gate_runs_as_part_of_the_volume_check(tmp_path: Path) -> None:
    plugin = _plugin_with(tmp_path, "v.css", ".px-4 { padding-left:1rem }\n")
    report = check_volumes(plugin)
    assert list(report) == ["v.css"]
    assert any("#118" in row for row in report["v.css"])
    assert main([str(plugin)]) == 1
