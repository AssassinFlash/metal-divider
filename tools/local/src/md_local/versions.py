"""The pinned versions of the local stack: the single source for local and CI.

The production host does not exist yet, so the pins here are the version
contract the host supply ticket must meet (docs/specs/14-local-stack.md §2.3,
branch research/spec-local-stack); once the host is up, the contract flips to
"host first, local follows" as in the de-linker stack this was cut from.

Every component's version was verified against its source on 2026-10-24
(api.wordpress.org version-check and plugin-info APIs for the wp.org entries;
the archives under ~/metal-divider-local/archives/ carry their own SHA-256 in
archives.toml).
"""

from __future__ import annotations

# WordPress: the version api.wordpress.org offers (core/version-check/1.7) and
# the SHA-1 published next to the archive (wordpress.org/wordpress-<v>.zip.sha1).
# A new version needs its own SHA-1.
#
# 7.1.4 on 2026-10-29, because the contract flipped: this module's own rule is
# "host first, local follows" once the host is up, and the host is up. Evidence,
# all read the same day: production reports 7.1.4 while every plugin still
# matches this file exactly; api.wordpress.org/core/version-check/1.7/ answers
# `response=upgrade, current=7.1.4` (and offers 7.1.4 as an autoupdate), so
# 7.1.4 is the release WordPress itself publishes, not a local drift; and
# wordpress.org/wordpress-7.1.4.zip.sha1 returns the hash below. 7.1.3 -> 7.1.4
# is a minor release, which WordPress applies unattended, so holding the older
# pin would only mean the contract fails every future deploy.
WORDPRESS_VERSION = "7.1.4"
WORDPRESS_SHA1 = "b552c39cd95d89895b4e7a55af53a03687aff4f6"

# The wp.org plugins, each installed from downloads.wordpress.org and verified
# file by file with `wp plugin verify-checksums` (wp.org publishes no archive
# hash). Versions read from the plugin API on 2026-10-24.
WOOCOMMERCE_VERSION = "11.2.1"
ACF_VERSION = "6.8.10"  # ACF free (owner ruling 2026-10-24; ACF Pro is out)
WINDPRESS_VERSION = "3.2.92"
SEOPRESS_VERSION = "10.4"  # the free base SEOPress PRO builds on
# The page-cache plugin of the production host (LiteSpeed Web Server runs the
# whole Hostinger fleet, docs/specs/20-staging-deploy.md §4.1). The host is
# the first place it does anything — the local `php -S` has no LiteSpeed
# module, so locally it is installed and active but inert: the plugin face
# stays identical, the behaviour differs by environment (spec 20 §2.2).
LITESPEED_CACHE_VERSION = "7.9.2"

# The paid and off-wp.org plugins, installed from the local archives
# (~/metal-divider-local/archives/, provenance and SHA-256 in archives.toml).
# SEOPress PRO and LiveCanvas are the owner's existing licences, carried over
# from the de-linker snapshot of 2026-10-22 (owner ruling 2026-10-24).
SEOPRESS_PRO_VERSION = "10.2.1"
LIVECANVAS_VERSION = "4.9.5"
YANSIR_MD_VERSION = "1.0.1"

# The base theme, from the local archives like the off-wp.org plugins
# (docs/specs/14-local-stack.md §2.1: picostrap.com distributes it, wp.org
# does not carry it). The checkout's own child theme (themes/md-child) links
# on top of it (spec 17 §2.1, branch research/spec-theme-base).
PICOSTRAP5_VERSION = "3.5.0"

# Not installed in phase 1 (owner ruling 2026-10-24: English site first,
# multilingual is phase 2). The slot records the version the phase-2 ticket
# will install, so `just status` can show the contract.
POLYLANG_VERSION = "3.9.1"

# WP-CLI as on this Mac (`brew pin wp-cli`); the version the stack requires.
WPCLI_VERSION = "2.12"

# PHP of the whole stack (instances and WP-CLI): the version the production
# host will be required to supply (docs/specs/14-local-stack.md §2.3).
PHP_VERSION = "8.3"
