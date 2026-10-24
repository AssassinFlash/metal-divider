"""The licence activation state a local instance runs with.

The instance's egress is isolated (the isolation mu-plugin allows 127.0.0.1
only), so a licence server cannot be reached and activation cannot happen
online. Instead the activation state is written straight into the options, as
the de-linker stack does (its tools/pull/src/linker_pull/licenses.py, verified
against the plugins' own code there); the production host activates properly
over its real network, and the two never meet.

The keys themselves live outside the repository, in ~/.config/metal-divider/
(AGENTS.md §4): livecanvas.env (LIVECANVAS_LICENSE_KEY) and seopress.env
(SEOPRESS_LICENSE_KEY), one KEY=value per line, mode 600. Values travel from
the files' bytes into `wp option update`'s standard input, so they reach no
argument list, log or repository.

What each plugin's banner keys on (read in the plugins' code, 2026-10-24):

- LiveCanvas: the site option `lc_apikey` (modules/plugin-apikey-settings.php,
  lc_get_apikey()); any non-empty value clears the "activate" notice, and the
  updater that would verify it cannot be reached anyway. The licence code
  stands in for the per-site apikey an online activation would issue.
- SEOPress PRO: `seopress_pro_license_key` + `seopress_pro_license_status` +
  `seopress_pro_license_expiry`. With a valid status and no expiry it phones
  its store on every admin page (inc/admin/cron.php), which the isolation
  blocks; a stored expiry keeps it quiet, so a dev-only date goes in — one
  year ahead, meaningless off this machine.
"""

from __future__ import annotations

import os
from collections.abc import Callable
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Protocol

# Where the owner keeps the licence files (600, outside the repository);
# MD_LICENSE_DIR points elsewhere, for the tests.
DEFAULT_DIRECTORY = Path.home() / ".config" / "metal-divider"
LIVECANVAS_FILE = "livecanvas.env"
LIVECANVAS_VARIABLE = "LIVECANVAS_LICENSE_KEY"
SEOPRESS_FILE = "seopress.env"
SEOPRESS_VARIABLE = "SEOPRESS_LICENSE_KEY"

LIVECANVAS_APIKEY = "lc_apikey"
SEOPRESS_KEY = "seopress_pro_license_key"
SEOPRESS_STATUS = "seopress_pro_license_status"
SEOPRESS_EXPIRY = "seopress_pro_license_expiry"

# A dev-only expiry: far enough that no banner asks about it, and visibly not
# a real subscription date (rewritten on every `up`).
DEV_EXPIRY_DAYS = 365

Say = Callable[[str], None]


class Wp(Protocol):
    def run(
        self, *args: str, stdin: str | None = None, env: dict[str, str] | None = None
    ) -> str: ...


def directory() -> Path:
    return Path(os.environ.get("MD_LICENSE_DIR") or DEFAULT_DIRECTORY)


def env_value(path: Path, name: str) -> str | None:
    """`name`'s value in a KEY=value file; None when the file or the line is missing."""
    try:
        lines = path.read_text().splitlines()
    except FileNotFoundError:
        return None
    for line in lines:
        key, separator, value = line.partition("=")
        if separator and key.strip() == name:
            return value.strip().strip("'\"") or None
    return None


def apply(wp: Wp, say: Say) -> None:
    """Write the licence state into the instance; say what was and was not applied."""
    key = env_value(directory() / SEOPRESS_FILE, SEOPRESS_VARIABLE)
    if key is None:
        say(
            f"seopress-pro licence not applied: no {SEOPRESS_VARIABLE} in "
            f"{directory() / SEOPRESS_FILE} (the admin will show its notice)"
        )
    else:
        _update(wp, SEOPRESS_KEY, key)
        _update(wp, SEOPRESS_STATUS, "valid")
        _update(
            wp,
            SEOPRESS_EXPIRY,
            (datetime.now(UTC) + timedelta(days=DEV_EXPIRY_DAYS)).date().isoformat(),
        )
        say("seopress-pro licence applied")

    code = env_value(directory() / LIVECANVAS_FILE, LIVECANVAS_VARIABLE)
    if code is None:
        say(
            f"livecanvas licence not applied: no {LIVECANVAS_VARIABLE} in "
            f"{directory() / LIVECANVAS_FILE} (the admin will show its notice)"
        )
    else:
        _update(wp, LIVECANVAS_APIKEY, code)
        say("livecanvas licence applied")


def _update(wp: Wp, name: str, value: str) -> None:
    # Without a value argument `wp option update` reads it from standard input.
    # The plugins stay unloaded, so SEOPress's sanitize callback, which deletes
    # the status whenever the key changes (seopress_sanitize_license), stays out.
    wp.run(
        "option",
        "update",
        name,
        "--skip-plugins",
        "--skip-themes",
        stdin=value,
    )
