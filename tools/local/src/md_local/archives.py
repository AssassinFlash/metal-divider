"""The local archives: the paid and off-wp.org plugins, with their SHA-256.

Nothing in this repository holds a licence or a plugin archive
(AGENTS.md §4: the public repo carries path references only). The archives
live in `<data root>/archives/`, described by `<data root>/archives.toml`:

    [livecanvas]
    path = "archives/livecanvas-4.9.5.zip"   # relative to the data root
    version = "4.9.5"
    sha256 = "…"                             # recorded when first collected

Every install verifies the SHA-256 first: a substituted zip is an error, not a
surprise. `md-local archive <slug> <source>` collects a new archive — a .zip
copied as it is, a directory (a snapshot's unpacked plugin) zipped — and
records its version read from the plugin header inside.
"""

from __future__ import annotations

import hashlib
import re
import shutil
import subprocess
import tomllib
import zipfile
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

from md_local import versions
from md_local.instance import SHARED

# The archives are machine-level, like the download cache: every data root
# (a test run's own MD_LOCAL_HOME included) installs from the same zips.
ROOT = SHARED


def _quiet(message: str) -> None:
    """The default sayer: silent (the CLI passes its own printing one)."""
    return None


ARCHIVES_TOML = "archives.toml"
ARCHIVES_DIR = "archives"
# Two characters that cannot be a slug would do; this keeps the parser honest.
SHA256 = re.compile(r"[0-9a-f]{64}")


class ArchiveError(RuntimeError):
    """An archive is missing, substituted, or not the pinned version."""


@dataclass(frozen=True)
class Archive:
    slug: str
    path: Path  # absolute, inside the data root
    version: str
    sha256: str


# What each archived plugin is: the directory the zip unpacks (its slug inside
# WordPress) and the file its header sits in. yansir-md unpacks to
# 28-wp-markdown-editor/ with the header in yansir-md.php — the install goes by
# the real directory, not by the zip's name.
KNOWN: dict[str, tuple[str, str, str]] = {
    "livecanvas": (
        "livecanvas",
        "livecanvas-plugin-index.php",
        versions.LIVECANVAS_VERSION,
    ),
    "wp-seopress-pro": (
        "wp-seopress-pro",
        "seopress-pro.php",
        versions.SEOPRESS_PRO_VERSION,
    ),
    "yansir-md": (
        "28-wp-markdown-editor",
        "yansir-md.php",
        versions.YANSIR_MD_VERSION,
    ),
}


def collect(slug: str, source: Path, say: Callable[[str], None] = _quiet) -> Archive:
    """Put `source` (a .zip file or an unpacked plugin directory) into the archives.

    A directory source holds the plugin's own files at its top level, however
    the directory itself is called (the archive of record, a snapshot's
    plugins/<slug>/); the zip is built with the plugin's real directory name.
    The zip is verified to hold the pinned version and its SHA-256 recorded;
    collecting again overwrites the entry (the previous zip is replaced).
    """
    if slug not in KNOWN:
        raise ArchiveError(
            f"{slug} is not an archived plugin of this stack: {sorted(KNOWN)}"
        )
    directory, main_file, pinned = KNOWN[slug]
    archives = ROOT / ARCHIVES_DIR
    archives.mkdir(parents=True, exist_ok=True)
    if source.is_dir():
        target = archives / f"{slug}-{pinned}.zip"
        _zip_directory(source, directory, target)
    elif source.is_file():
        target = archives / f"{slug}-{pinned}.zip"
        shutil.copyfile(source, target)
    else:
        raise ArchiveError(f"{source} is neither a zip nor a plugin directory")
    found = _header_version(target, directory, main_file)
    if found != pinned:
        raise ArchiveError(f"{target} holds version {found}, the stack pins {pinned}")
    archive = Archive(slug, target, found, _sha256(target))
    _write(archive)
    say(f"archived {slug} {found} (sha256 {archive.sha256[:16]}…)")
    return archive


def verified(slug: str) -> Archive:
    """The recorded archive of `slug`, its file present and its SHA-256 intact."""
    if slug not in KNOWN:
        raise ArchiveError(f"{slug} is not an archived plugin of this stack")
    file = ROOT / ARCHIVES_TOML
    try:
        with file.open("rb") as handle:
            data = tomllib.load(handle)
    except FileNotFoundError:
        raise ArchiveError(
            f"{file} does not exist: collect the archives first "
            "(README.md, local stack; the sources are the owner's licensed copies)"
        ) from None
    if slug not in data:
        raise ArchiveError(
            f"{slug} is not in {file}: `md-local archive {slug} <zip or dir>`"
        )
    entry = data[slug]
    try:
        archive = Archive(
            slug,
            (ROOT / str(entry["path"])).resolve(),
            str(entry["version"]),
            str(entry["sha256"]),
        )
    except KeyError as error:
        raise ArchiveError(f"{file}: {slug} has no {error.args[0]}") from None
    if not SHA256.fullmatch(archive.sha256):
        raise ArchiveError(f"{file}: {slug} has no SHA-256 recorded; collect it again")
    if not archive.path.is_file():
        raise ArchiveError(f"{archive.path} is missing; collect the archive again")
    actual = _sha256(archive.path)
    if actual != archive.sha256:
        raise ArchiveError(
            f"{archive.path}: SHA-256 is {actual}, recorded {archive.sha256} "
            "(the zip changed after it was collected; collect it again)"
        )
    return archive


def _write(archive: Archive) -> None:
    """Rewrite archives.toml with `archive` (order: the known slugs' order)."""
    file = ROOT / ARCHIVES_TOML
    file.parent.mkdir(parents=True, exist_ok=True)
    data: dict[str, dict[str, str]] = {}
    if file.exists():
        with file.open("rb") as handle:
            data = tomllib.load(handle)
    data[archive.slug] = {
        "path": str(archive.path.relative_to(ROOT)),
        "version": archive.version,
        "sha256": archive.sha256,
    }
    lines = [
        "# The local plugin archives, written by `md-local archive`.\n"
        "# Paid and off-wp.org plugins only; never in the repository.\n"
    ]
    for slug in sorted(data):
        entry = data[slug]
        lines.append(f"\n[{slug}]\n")
        lines.append(f'path = "{entry["path"]}"\n')
        lines.append(f'version = "{entry["version"]}"\n')
        lines.append(f'sha256 = "{entry["sha256"]}"\n')
    temporary = file.with_suffix(".toml.tmp")
    temporary.write_text("".join(lines), encoding="utf-8")
    temporary.replace(file)


def _zip_directory(source: Path, directory: str, target: Path) -> None:
    """Zip `source`'s content as `<directory>/…` inside `target` (deterministic order).

    The zip's top-level directory is the plugin's real slug (`livecanvas`),
    whatever the source directory happens to be called (the archive of record
    is `livecanvas-4.9.5`), so the staging copy carries the right name.
    """
    staging = target.with_name(f".{target.stem}.staging")
    shutil.rmtree(staging, ignore_errors=True)
    staged = staging / directory
    shutil.copytree(source, staged, ignore=shutil.ignore_patterns(".DS_Store"))
    partial = target.with_suffix(".zip.tmp")
    partial.unlink(missing_ok=True)
    subprocess.run(
        ["zip", "-q", "-r", "-X", str(partial), directory, "-x", "*.DS_Store"],
        cwd=staging,
        check=True,
    )
    shutil.rmtree(staging)
    partial.rename(target)


def _header_version(archive: Path, directory: str, main_file: str) -> str:
    """The version in the plugin header of `<directory>/<main_file>` inside the zip."""
    with zipfile.ZipFile(archive) as zip:
        try:
            header = zip.read(f"{directory}/{main_file}").decode("utf-8", "replace")
        except KeyError:
            raise ArchiveError(
                f"{archive} holds no {directory}/{main_file}; not the plugin this stack archives"
            ) from None
    found = re.search(r"^\s*\*?\s*Version:\s*(\S+)", header, re.MULTILINE)
    if found is None:
        raise ArchiveError(f"{archive}: no Version header in {directory}/{main_file}")
    return found.group(1)


def _sha256(path: Path) -> str:
    with path.open("rb") as handle:
        return hashlib.file_digest(handle, "sha256").hexdigest()
