"""Loading the driver, whose module name starts with a digit."""

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path
from types import ModuleType

DRIVER = Path(__file__).resolve().parent.parent / "18-migrate.py"


def migrate() -> ModuleType:
    if "md_migrate_driver" in sys.modules:
        return sys.modules["md_migrate_driver"]
    spec = importlib.util.spec_from_file_location("md_migrate_driver", DRIVER)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules["md_migrate_driver"] = module
    spec.loader.exec_module(module)
    return module
