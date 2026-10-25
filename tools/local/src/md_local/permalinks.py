"""The site's URL scheme: journal articles under /blog/<slug>/, categories under /category/<slug>/.

The blog IA (ticket #5 §3.2) puts a journal article at /blog/<slug>/, and the
scheme is two WordPress options (`permalink_structure`, `category_base`) — so
no file deploy can carry it. A site whose options drifted answers at URLs no
template, link or sitemap entry names, which is why the deploy applies this
plane beside the presentation data (tools/deploy, `md_deploy.steps`) and reads
it back in verify.

`apply()` is the one write. It reads the options first and writes only when
they have drifted, so provisioning, `refresh()` and the deploy all call it and
a second call does nothing (no rewrite flush on every `up`). The readback after
the write is the proof; the command's exit status is not.

Why the explicit category base: with an empty `category_base` WordPress
prepends the permalink front (/blog) to the category permastruct and every blog
category moves under /blog/category/ — `register_taxonomy()` in
wp-includes/taxonomy.php sets `'with_front' => ! get_option( 'category_base' )`
and WP_Rewrite::get_extra_permastruct() applies it.

The write is `wp rewrite structure` without `--hard`: it sets the two options
and refreshes the `rewrite_rules` option, and leaves `.htaccess` alone (a hard
flush rewrites that file only where the server reports mod_rewrite, WP-CLI's
own `--hard` help). The host's rewrite block is host setup, not this plane's.
"""

from __future__ import annotations

import json
from collections.abc import Callable
from typing import NamedTuple

# A WP-CLI seam: `md_local.wpcli.WpCli.run` and `md_deploy.target.Target.wp`
# both fit — the callable takes the command's words and answers with stdout.
Wp = Callable[..., str]

# Both options, in one read: `wp option get` exits non-zero on an option the
# site does not have, and an unset option is exactly the drift being looked
# for (a fresh install, or a database import from a differently configured
# site).
READBACK = (
    "echo wp_json_encode( array( "
    "(string) get_option( 'permalink_structure' ), "
    "(string) get_option( 'category_base' ) ) );"
)


class Scheme(NamedTuple):
    """The two options the URL scheme is made of, as the site holds them."""

    structure: str
    category_base: str


# The scheme itself. The base is bare (`category`), the form WP-CLI's
# `--category-base` and WP_Rewrite::set_category_base() store.
SCHEME = Scheme("/blog/%postname%/", "category")


class PermalinkError(RuntimeError):
    """The scheme did not read back as the site's options say it should."""


def read(wp: Wp) -> Scheme:
    """The site's two permalink options, as it holds them now.

    The answer is scanned from its last line back for one that parses as
    JSON: `wp eval` prints whatever a plugin says on load above the payload,
    and the deploy reads WP-CLI answers this way elsewhere
    (`windpress_cache_mtime`, steps.py).
    """
    answer = wp("eval", READBACK)
    found: object = None
    for line in reversed(answer.strip().splitlines()):
        try:
            found = json.loads(line)
        except ValueError:
            continue
        break
    if found is None:
        raise PermalinkError(f"the readback carries no JSON line: {answer!r}")
    if not isinstance(found, list) or len(found) != 2:
        raise PermalinkError(f"the readback is not two values: {answer!r}")
    return Scheme(str(found[0]), str(found[1]))


def apply(wp: Wp) -> tuple[Scheme, bool]:
    """Make the site's options the scheme; returns it applied, and whether this call wrote.

    Idempotent by readback: a site already on the scheme gets one read and no
    write. A write that does not read back as applied raises — a site that
    looks configured but is not must not pass as a successful deploy.
    """
    found = read(wp)
    if found == SCHEME:
        return found, False
    wp(
        "rewrite",
        "structure",
        SCHEME.structure,
        f"--category-base={SCHEME.category_base}",
    )
    applied = read(wp)
    if applied != SCHEME:
        raise PermalinkError(
            f"the options read back as {applied.structure!r} / "
            f"{applied.category_base!r}, not {SCHEME.structure!r} / "
            f"{SCHEME.category_base!r}"
        )
    return applied, True
