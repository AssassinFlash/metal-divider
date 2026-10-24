"""The archives: collecting a plugin, and the SHA-256 every install verifies."""

from __future__ import annotations

import zipfile
from pathlib import Path

import pytest
from pytest import MonkeyPatch

from md_local import archives, versions


@pytest.fixture()
def data_root(tmp_path: Path, monkeypatch: MonkeyPatch) -> Path:
    # The archives are machine-level; the tests point that root at their own.
    monkeypatch.setattr(archives, "ROOT", tmp_path)
    return tmp_path


@pytest.fixture()
def livecanvas_dir(tmp_path: Path) -> Path:
    """An unpacked plugin directory, as the real sources are: the plugin's own
    files at the top level, the directory named whatever the archive of record
    is called (not by slug)."""
    source = tmp_path / "livecanvas-4.9.5"
    source.mkdir()
    (source / "livecanvas-plugin-index.php").write_text(
        "<?php\n/**\n * Plugin Name: LiveCanvas\n * Version: "
        f"{versions.LIVECANVAS_VERSION}\n */\n"
    )
    (source / "code.php").write_text("<?php\n")
    return source


def test_collecting_a_directory_zips_it_under_the_slug(
    data_root: Path, livecanvas_dir: Path
) -> None:
    archive = archives.collect("livecanvas", livecanvas_dir)

    assert archive.version == versions.LIVECANVAS_VERSION
    assert (
        archive.path
        == data_root / "archives" / f"livecanvas-{versions.LIVECANVAS_VERSION}.zip"
    )
    with zipfile.ZipFile(archive.path) as zip:
        files = [name for name in zip.namelist() if not name.endswith("/")]
        assert sorted(files) == [
            "livecanvas/code.php",
            "livecanvas/livecanvas-plugin-index.php",
        ]


def test_a_collected_archive_verifies(data_root: Path, livecanvas_dir: Path) -> None:
    archives.collect("livecanvas", livecanvas_dir)
    assert archives.verified("livecanvas") == archives.verified("livecanvas")


def test_a_substituted_zip_is_an_error(data_root: Path, livecanvas_dir: Path) -> None:
    archive = archives.collect("livecanvas", livecanvas_dir)
    # A different zip under the same name: the recorded SHA-256 no longer matches.
    with zipfile.ZipFile(archive.path, "w") as zip:
        zip.writestr("livecanvas/livecanvas-plugin-index.php", "<?php\n")

    with pytest.raises(archives.ArchiveError, match="SHA-256"):
        archives.verified("livecanvas")


def test_a_missing_archive_is_an_error(data_root: Path) -> None:
    with pytest.raises(archives.ArchiveError, match="collect"):
        archives.verified("livecanvas")


def test_collecting_the_wrong_version_is_an_error(
    data_root: Path, livecanvas_dir: Path
) -> None:
    header = livecanvas_dir / "livecanvas-plugin-index.php"
    header.write_text(header.read_text().replace(versions.LIVECANVAS_VERSION, "0.0.1"))
    with pytest.raises(archives.ArchiveError, match="pins"):
        archives.collect("livecanvas", livecanvas_dir)


def test_collecting_a_zip_file_copies_it(
    data_root: Path, tmp_path: Path, livecanvas_dir: Path
) -> None:
    import shutil
    import subprocess

    staged = tmp_path / "staged"
    staged.mkdir()
    shutil.copytree(livecanvas_dir, staged / "livecanvas")
    zip_path = tmp_path / "livecanvas.zip"
    subprocess.run(
        ["zip", "-q", "-r", "-X", str(zip_path), "livecanvas"], cwd=staged, check=True
    )

    archive = archives.collect("livecanvas", zip_path)
    assert archive.version == versions.LIVECANVAS_VERSION
    assert archives.verified("livecanvas").sha256 == archive.sha256


def test_an_unknown_slug_is_refused(data_root: Path, livecanvas_dir: Path) -> None:
    with pytest.raises(archives.ArchiveError, match="archived plugin"):
        archives.collect("woocommerce", livecanvas_dir)
