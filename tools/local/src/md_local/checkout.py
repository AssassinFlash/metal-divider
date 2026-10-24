"""The metal-divider checkout `md-local` runs from: what it links and copies into an instance."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Checkout:
    root: Path

    @classmethod
    def here(cls) -> Checkout:
        """The checkout containing this package (uv installs it editable from tools/local/src)."""
        root = Path(__file__).resolve().parents[4]
        if not (root / "tools" / "local" / "pyproject.toml").is_file():
            raise RuntimeError(
                f"{root} is not a metal-divider checkout; run md-local through "
                "`just` or `uv run --project tools/local`"
            )
        return cls(root)

    def plugins(self) -> list[Path]:
        """Our plugins, `plugins/md-*`, each symlinked into an instance."""
        directory = self.root / "plugins"
        if not directory.is_dir():
            return []
        return sorted(path for path in directory.glob("md-*") if path.is_dir())

    def mu_plugins(self) -> list[Path]:
        """The local-only mu-plugins, copied into every instance."""
        return sorted((self.root / "tools" / "local" / "mu").glob("*.php"))

    @property
    def router(self) -> Path:
        return self.root / "tools" / "local" / "router.php"
