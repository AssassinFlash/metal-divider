"""Tests for the volume syntax gate (ticket #49)."""

from __future__ import annotations

from pathlib import Path

import pytest

from md_template_convert.volumecheck import check_volume, check_volumes, main


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
