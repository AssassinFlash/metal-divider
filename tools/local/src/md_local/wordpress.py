"""Building an instance: WordPress, the plugin face, and the local mu-plugins.

`provision()` makes a new instance; `refresh()` brings an existing one up to
the files of the checkout that runs it. Every `up` calls one of the two.

The plugin face (owner rulings 2026-10-24, docs/specs/14-local-stack.md §2):
WooCommerce, ACF free, WindPress and SEOPress from wp.org (versions verified
file by file); SEOPress PRO, LiveCanvas and yansir-md from the local archives
(SHA-256 checked); Polylang NOT installed — the phase-2 slot lives in
versions.py only. Phase 1 ships no object cache (the production host has no
Redis), so no Redis plugin either.

A bare instance (`up --bare`) skips the plugin face and the licences: it is
the smallest WordPress the must-use plugins' smoke suite needs, buildable
where the licensed archives do not exist — CI (docs/specs/23-mu-plugins.md
§5.4, branch research/spec-mu-plugins).
"""

from __future__ import annotations

import re
import secrets
import shutil
import zipfile
from collections.abc import Callable
from datetime import UTC, datetime
from pathlib import Path

from md_local import archives, downloads, licenses, permalinks, secret, server, versions
from md_local.checkout import Checkout
from md_local.instance import Instance
from md_local.mysql import USER, MySQL
from md_local.wpcli import WpCli

WORDPRESS_ZIP = f"https://wordpress.org/wordpress-{versions.WORDPRESS_VERSION}.zip"
WOOCOMMERCE_ZIP = (
    "https://downloads.wordpress.org/plugin/"
    f"woocommerce.{versions.WOOCOMMERCE_VERSION}.zip"
)
ACF_ZIP = (
    "https://downloads.wordpress.org/plugin/"
    f"advanced-custom-fields.{versions.ACF_VERSION}.zip"
)
WINDPRESS_ZIP = (
    f"https://downloads.wordpress.org/plugin/windpress.{versions.WINDPRESS_VERSION}.zip"
)
SEOPRESS_ZIP = (
    "https://downloads.wordpress.org/plugin/"
    f"wp-seopress.{versions.SEOPRESS_VERSION}.zip"
)
LITESPEED_CACHE_ZIP = (
    "https://downloads.wordpress.org/plugin/"
    f"litespeed-cache.{versions.LITESPEED_CACHE_VERSION}.zip"
)

# The wp.org plugins in install order: (slug, the say() line's name, zip).
WP_ORG_PLUGINS: tuple[tuple[str, str, str], ...] = (
    ("woocommerce", f"WooCommerce {versions.WOOCOMMERCE_VERSION}", WOOCOMMERCE_ZIP),
    ("advanced-custom-fields", f"ACF {versions.ACF_VERSION}", ACF_ZIP),
    ("windpress", f"WindPress {versions.WINDPRESS_VERSION}", WINDPRESS_ZIP),
    ("wp-seopress", f"SEOPress {versions.SEOPRESS_VERSION}", SEOPRESS_ZIP),
    (
        "litespeed-cache",
        f"LiteSpeed Cache {versions.LITESPEED_CACHE_VERSION}",
        LITESPEED_CACHE_ZIP,
    ),
)

# The archived plugins in install order: (archive slug, activation slug).
# yansir-md activates under its real directory name (archives.KNOWN).
ARCHIVED_PLUGINS: tuple[tuple[str, str], ...] = (
    ("wp-seopress-pro", "wp-seopress-pro"),
    ("livecanvas", "livecanvas"),
    ("yansir-md", "28-wp-markdown-editor"),
)

ADMIN_USER = "local-admin"
# .test is reserved and never resolves to a real mailbox (RFC 6761 §6.2).
ADMIN_EMAIL = "local-admin@example.test"
TABLE_PREFIX = "wp_"

SALTS = (
    "AUTH_KEY",
    "SECURE_AUTH_KEY",
    "LOGGED_IN_KEY",
    "NONCE_KEY",
    "AUTH_SALT",
    "SECURE_AUTH_SALT",
    "LOGGED_IN_SALT",
    "NONCE_SALT",
)

Say = Callable[[str], None]


def provision(
    instance: Instance,
    php: Path,
    checkout: Checkout,
    mysql: MySQL,
    say: Say,
    bare: bool = False,
) -> None:
    """Create the instance's database and files; the instance must not exist yet.

    With `bare`, the plugin face and the licences are skipped: WordPress, the
    router and the local mu-plugins only.
    """
    for directory in (
        instance.dir,
        instance.mail_dir,
        instance.log_dir,
        instance.run_dir,
    ):
        directory.mkdir(parents=True, exist_ok=True)
    instance.dir.chmod(0o700)
    # The phase-2 egress allowlist, empty in phase 1 (docs/specs/14-local-stack.md §1.4).
    instance.allow_hosts_file.touch(mode=0o644)

    say(f"database {instance.database}")
    password = mysql.ensure_user()
    mysql.create_database(instance.database)

    say(f"WordPress {versions.WORDPRESS_VERSION}")
    archive = downloads.fetch(WORDPRESS_ZIP, sha1=versions.WORDPRESS_SHA1)
    _extract_wordpress(archive, instance.docroot)
    _install_server(instance, php, checkout)

    wp = WpCli(php, instance)
    wp.run(
        "config",
        "create",
        f"--dbname={instance.database}",
        f"--dbuser={USER}",
        f"--dbpass={password}",
        f"--dbhost=localhost:{mysql.socket()}",
        f"--dbprefix={TABLE_PREFIX}",
        "--skip-salts",
        "--extra-php",
        stdin=wp_config_constants(instance),
    )
    (instance.docroot / "wp-config.php").chmod(0o600)
    admin_password = secret.password()
    wp.run(
        "core",
        "install",
        f"--url={instance.url}",
        f"--title=Metal Divider (local {instance.name})",
        f"--admin_user={ADMIN_USER}",
        f"--admin_password={admin_password}",
        f"--admin_email={ADMIN_EMAIL}",
        "--skip-email",
    )
    instance.admin_file.touch(mode=0o600)
    instance.admin_file.write_text(
        f"url: {instance.url}/wp-admin/\nuser: {ADMIN_USER}\npassword: {admin_password}\n"
    )

    if bare:
        say("bare instance: no plugin face, no licences")
    else:
        _install_plugin_face(wp, say)
        # The base theme comes from the licensed archives and the child theme
        # activates only beside it: both are part of the face a bare instance
        # skips (which is what lets CI, with no archives, build one).
        _install_base_theme(instance, wp, say)
        _link_our_themes(instance, checkout, wp, say)

    _link_our_plugins(instance, checkout, wp, say)
    _apply_permalinks(wp, say)
    instance.provisioned_marker.write_text(datetime.now(UTC).isoformat() + "\n")


def _apply_permalinks(wp: WpCli, say: Say) -> None:
    """Apply the URL scheme options (`md_local.permalinks`) and say what happened.

    The scheme is two options, so it is data: apply() reads them first and
    writes only on drift, and its readback after a write is the proof. Both
    `provision()` and `refresh()` go through here, so a fresh instance and an
    existing one are configured by one code path.
    """
    applied, wrote = permalinks.apply(wp.run)
    scheme = f"{applied.structure} categories {applied.category_base}"
    say(f"permalinks {scheme}" + ("" if wrote else " (already set)"))


def _install_plugin_face(wp: WpCli, say: Say) -> None:
    """The pinned plugin face: the wp.org plugins, then the archives, then licences."""
    for slug, name, zip_url in WP_ORG_PLUGINS:
        say(name)
        wp.run("plugin", "install", str(downloads.fetch(zip_url)))
        wp.run("plugin", "verify-checksums", slug)
        wp.run("plugin", "activate", slug)

    for archive_slug, activate_as in ARCHIVED_PLUGINS:
        archive_path = archives.verified(archive_slug)
        say(f"{archive_slug} {archive_path.version} (archive)")
        wp.run("plugin", "install", str(archive_path.path))
        wp.run("plugin", "activate", activate_as)

    say("licences")
    licenses.apply(wp, say)


def refresh(instance: Instance, php: Path, checkout: Checkout, say: Say) -> None:
    """Bring a built instance to this checkout: files, then the option planes it owns.

    The files: php.ini, router, mu-plugins, the plugin and theme links.
    Then the two option planes, both idempotent so `up` is a safe rerun: the
    licences (the isolated instance cannot activate online) and the URL
    scheme (`md_local.permalinks`, which writes only on drift). A fresh
    instance has the scheme from `provision()`, so this matters after a
    database import or a checkout that changed it.

    An instance is never recycled between checkouts or tickets: it keeps its
    database and its options; the files follow whichever checkout runs `up`;
    a ticket that needs a clean database takes `just reset`.
    """
    installed = versions_of(instance)
    drift = ", ".join(
        f"{name} {installed[name]} (pinned {pin})"
        for name, pin in PINNED_VERSIONS.items()
        if installed[name] != pin
    )
    if drift:
        say(f"runs {drift}: `just reset {instance.name}` rebuilds the pinned versions")
    _install_server(instance, php, checkout)
    wp = WpCli(php, instance)
    _install_base_theme(instance, wp, say)
    _link_our_plugins(instance, checkout, wp, say)
    _link_our_themes(instance, checkout, wp, say)
    say("licences")
    licenses.apply(wp, say)
    _apply_permalinks(wp, say)


def linked_checkout(instance: Instance) -> Path | None:
    """The checkout the instance's plugins point at, if any."""
    for link in sorted((instance.wp_content / "plugins").glob("md-*")):
        if link.is_symlink():
            return link.readlink().parent.parent
    return None


def versions_of(instance: Instance) -> dict[str, str | None]:
    """The pinned packages' versions as the instance's files state them."""
    plugins = instance.wp_content / "plugins"
    return {
        "WordPress": _first_match(
            instance.docroot / "wp-includes" / "version.php",
            r"\$wp_version = '([^']+)'",
        ),
        "WooCommerce": _header(plugins / "woocommerce" / "woocommerce.php"),
        "ACF": _header(plugins / "advanced-custom-fields" / "acf.php"),
        "WindPress": _header(plugins / "windpress" / "windpress.php"),
        "SEOPress": _header(plugins / "wp-seopress" / "seopress.php"),
        "SEOPress PRO": _header(plugins / "wp-seopress-pro" / "seopress-pro.php"),
        "LiveCanvas": _header(plugins / "livecanvas" / "livecanvas-plugin-index.php"),
        "yansir-md": _header(plugins / "28-wp-markdown-editor" / "yansir-md.php"),
        "picostrap5": _header(
            instance.wp_content / "themes" / "picostrap5" / "style.css"
        ),
        "LiteSpeed Cache": _header(plugins / "litespeed-cache" / "litespeed-cache.php"),
    }


# What refresh() compares an instance with: versions.py, the single source.
PINNED_VERSIONS: dict[str, str] = {
    "WordPress": versions.WORDPRESS_VERSION,
    "WooCommerce": versions.WOOCOMMERCE_VERSION,
    "ACF": versions.ACF_VERSION,
    "WindPress": versions.WINDPRESS_VERSION,
    "SEOPress": versions.SEOPRESS_VERSION,
    "SEOPress PRO": versions.SEOPRESS_PRO_VERSION,
    "LiveCanvas": versions.LIVECANVAS_VERSION,
    "yansir-md": versions.YANSIR_MD_VERSION,
    "picostrap5": versions.PICOSTRAP5_VERSION,
    "LiteSpeed Cache": versions.LITESPEED_CACHE_VERSION,
}


def _install_server(instance: Instance, php: Path, checkout: Checkout) -> None:
    """The php.ini, the router and the mu-plugins of this checkout."""
    server.install(instance, checkout)
    target = instance.wp_content / "mu-plugins"
    target.mkdir(exist_ok=True)
    wanted = {path.name for path in checkout.mu_plugins()}
    for stale in target.glob("*md-local*.php"):
        if stale.name not in wanted:
            stale.unlink()
    for source in checkout.mu_plugins():
        shutil.copyfile(source, target / source.name)


def _install_base_theme(instance: Instance, wp: WpCli, say: Say) -> None:
    """Install the pinned picostrap5 parent theme from the local archive.

    A child theme activates only beside its parent, so the base theme is in
    place before the checkout's themes link in (spec 17 §2.1, branch
    research/spec-theme-base).
    """
    if (instance.wp_content / "themes" / "picostrap5").is_dir():
        return
    archive = archives.verified("picostrap5")
    say(f"picostrap5 {archive.version} (archive)")
    wp.run("theme", "install", str(archive.path))


def _link_our_plugins(
    instance: Instance, checkout: Checkout, wp: WpCli, say: Say
) -> None:
    """Point wp-content/plugins/md-* at this checkout's plugins; activate new ones."""
    plugins_dir = instance.wp_content / "plugins"
    names = [plugin.name for plugin in checkout.plugins()]
    for stale in plugins_dir.glob("md-*"):
        if stale.is_symlink() and stale.name not in names:
            stale.unlink()
    for plugin in checkout.plugins():
        link = plugins_dir / plugin.name
        if link.is_symlink():
            link.unlink()
        elif link.exists():
            raise RuntimeError(f"{link} is a directory, not a link to a checkout")
        link.symlink_to(plugin, target_is_directory=True)
    if names:
        say(f"plugins {', '.join(names)} -> {checkout.root}")
        active = wp.run(
            "plugin", "list", "--status=active", "--field=name"
        ).splitlines()
        new = [name for name in names if name not in active]
        if new:
            wp.run("plugin", "activate", *new)


def _link_our_themes(
    instance: Instance, checkout: Checkout, wp: WpCli, say: Say
) -> None:
    """Point wp-content/themes/md-* at this checkout's themes; activate new ones."""
    themes_dir = instance.wp_content / "themes"
    names = [theme.name for theme in checkout.themes()]
    for stale in themes_dir.glob("md-*"):
        if stale.is_symlink() and stale.name not in names:
            stale.unlink()
    for theme in checkout.themes():
        link = themes_dir / theme.name
        if link.is_symlink():
            link.unlink()
        elif link.exists():
            raise RuntimeError(f"{link} is a directory, not a link to a checkout")
        link.symlink_to(theme, target_is_directory=True)
    if names:
        say(f"themes {', '.join(names)} -> {checkout.root}")
        # The stylesheet option names the active theme's directory.
        active = wp.run("option", "get", "stylesheet").strip()
        new = [name for name in names if name != active]
        if new:
            wp.run("theme", "activate", *new)


def wp_config_constants(instance: Instance) -> str:
    """wp-config.php constants after the database settings."""
    # 64 characters per key, as WordPress makes them itself
    # (wp_generate_password( 64, ... ) in wp-admin/setup-config.php);
    # token_urlsafe(48) gives 64 characters of [A-Za-z0-9_-].
    lines = [
        f"define( '{name}', {_php_string(secrets.token_urlsafe(48))} );"
        for name in SALTS
    ]
    lines += [
        "",
        "// This instance (md-local, tools/local).",
        f"define( 'WP_HOME', {_php_string(instance.url)} );",
        f"define( 'WP_SITEURL', {_php_string(instance.url)} );",
        "define( 'WP_ENVIRONMENT_TYPE', 'local' );",
        # A cron spawn in the middle of a request races the work a session or
        # its tests run explicitly: queued work runs when a session runs it.
        "define( 'DISABLE_WP_CRON', true );",
        f"define( 'MD_LOCAL_MAIL_DIR', {_php_string(str(instance.mail_dir))} );",
        f"define( 'MD_LOCAL_ISOLATION_LOG', {_php_string(str(instance.isolation_log))} );",
        f"define( 'MD_LOCAL_ALLOW_HOSTS', {_php_string(str(instance.allow_hosts_file))} );",
    ]
    return "\n".join(lines) + "\n"


def _php_string(value: str) -> str:
    """A single-quoted PHP string literal."""
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def _first_match(path: Path, pattern: str) -> str | None:
    try:
        found = re.search(pattern, path.read_text(errors="replace"))
    except FileNotFoundError:
        return None
    return found.group(1) if found else None


def _header(path: Path) -> str | None:
    """The plugin header's Version line, with or without the docblock asterisk."""
    return _first_match(path, r"Version:\s+(\S+)")


def _extract_wordpress(archive: Path, docroot: Path) -> None:
    """Unpack the release archive, whose files sit under wordpress/, into the docroot."""
    staging = docroot.with_name(docroot.name + ".unpacking")
    shutil.rmtree(staging, ignore_errors=True)
    with zipfile.ZipFile(archive) as release:
        release.extractall(staging)
    (staging / "wordpress").rename(docroot)
    staging.rmdir()
