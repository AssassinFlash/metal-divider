"""The licence injection: what is read, and which options it writes."""

from __future__ import annotations

from pathlib import Path

import pytest
from pytest import MonkeyPatch

from md_local import licenses


class Wp:
    """Records every `wp option update` the way md-local's WpCli would run it."""

    def __init__(self) -> None:
        self.updates: list[tuple[str, str]] = []

    def run(
        self, *args: str, stdin: str | None = None, env: dict[str, str] | None = None
    ) -> str:
        assert args[:2] == ("option", "update")
        assert "--skip-plugins" in args and "--skip-themes" in args
        assert stdin is not None, "the value must travel by standard input"
        self.updates.append((args[2], stdin))
        return ""


@pytest.fixture()
def licence_dir(tmp_path: Path, monkeypatch: MonkeyPatch) -> Path:
    monkeypatch.setenv("MD_LICENSE_DIR", str(tmp_path))
    (tmp_path / licenses.LIVECANVAS_FILE).write_text(
        f"{licenses.LIVECANVAS_VARIABLE}=lc-code-for-tests\n"
    )
    (tmp_path / licenses.SEOPRESS_FILE).write_text(
        f"{licenses.SEOPRESS_VARIABLE}=seopress-key-for-tests\n"
    )
    return tmp_path


def test_apply_writes_both_licences(licence_dir: Path) -> None:
    wp = Wp()
    said: list[str] = []
    licenses.apply(wp, said.append)

    written = dict(wp.updates)
    assert written[licenses.SEOPRESS_KEY] == "seopress-key-for-tests"
    assert written[licenses.SEOPRESS_STATUS] == "valid"
    assert written[licenses.LIVECANVAS_APIKEY] == "lc-code-for-tests"
    # The expiry is what keeps SEOPress PRO from phoning its store on every
    # admin page (see the module docstring): a date, a year ahead.
    assert licenses.SEOPRESS_EXPIRY in written
    assert said == ["seopress-pro licence applied", "livecanvas licence applied"]


def test_a_missing_file_is_reported_not_applied(licence_dir: Path) -> None:
    (licence_dir / licenses.LIVECANVAS_FILE).unlink()
    wp = Wp()
    said: list[str] = []
    licenses.apply(wp, said.append)

    assert dict(wp.updates).keys() == {
        licenses.SEOPRESS_KEY,
        licenses.SEOPRESS_STATUS,
        licenses.SEOPRESS_EXPIRY,
    }
    assert any("livecanvas licence not applied" in message for message in said)


def test_env_value_reads_the_named_variable(licence_dir: Path) -> None:
    found = licenses.env_value(
        licence_dir / licenses.SEOPRESS_FILE, licenses.SEOPRESS_VARIABLE
    )
    assert found == "seopress-key-for-tests"
    assert licenses.env_value(licence_dir / licenses.SEOPRESS_FILE, "OTHER") is None
    assert licenses.env_value(licence_dir / "missing.env", "ANY") is None
