"""Pytest fixtures over the synthetic corpus helpers."""

from __future__ import annotations

from pathlib import Path

import pytest
from corpus import Corpus


@pytest.fixture
def corpus(tmp_path: Path) -> Corpus:
    return Corpus(tmp_path / "corpus")
