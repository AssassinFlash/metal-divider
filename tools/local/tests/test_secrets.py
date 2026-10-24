"""The zero-leak guard (docs/specs/14-local-stack.md §3.3, branch research/spec-local-stack).

The public repository must never contain a licence value (AGENTS.md §4). This
test reads the real values from ~/.config/metal-divider/*.env and fails if any
of them appears anywhere in the working tree. Where the files do not exist —
CI, which has no licences — the test skips, by design.
"""

from __future__ import annotations

from pathlib import Path

import pytest

from md_local import licenses
from md_local.checkout import Checkout

# Files never searched: binaries' homes, dependency trees, and git's own database.
SKIP_DIRECTORIES = {".git", ".venv", "vendor", "node_modules", "__pycache__", ".cache"}
MAX_FILE_SIZE = 2 * 1024 * 1024


def licence_values() -> list[str]:
    values: list[str] = []
    for file, variable in (
        (licenses.LIVECANVAS_FILE, licenses.LIVECANVAS_VARIABLE),
        (licenses.SEOPRESS_FILE, licenses.SEOPRESS_VARIABLE),
    ):
        found = licenses.env_value(licenses.directory() / file, variable)
        if found and len(found) >= 8:
            values.append(found)
    return values


def worktree_files(root: Path) -> list[Path]:
    files = []
    for path in root.rglob("*"):
        if path.is_dir():
            continue
        relative = path.relative_to(root)
        if any(part in SKIP_DIRECTORIES for part in relative.parts):
            continue
        try:
            if path.stat().st_size > MAX_FILE_SIZE:
                continue
        except OSError:
            continue
        files.append(path)
    return files


@pytest.mark.skipif(
    not licence_values(),
    reason="no licence files on this machine (CI): nothing to guard",
)
def test_no_licence_value_is_in_the_worktree() -> None:
    root = Checkout.here().root
    values = licence_values()
    leaked: list[str] = []
    for file in worktree_files(root):
        try:
            text = file.read_text(errors="replace")
        except OSError:
            continue
        for value in values:
            if value in text:
                leaked.append(f"{value[:4]}… in {file.relative_to(root)}")
    assert not leaked, f"licence values leaked into the repository: {leaked}"
