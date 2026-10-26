"""What a deploy ships and checks, in one place (docs/specs/20-staging-deploy.md).

Spec 20 §3.2 is the requirement; this module is its inventory. Every list
below is read by the deploy step and by the drill, so what the drill proves
and what the deploy window runs cannot drift apart.

Two kinds of entry:

- Things the deploy does (`PLANNED_PLUGINS`, `STAGING_MU`, `DEPLOY_EXCLUDES`,
  `VERIFY_*`).
- Things only a person can do (`MANUAL`). They are listed here so the
  checklist is complete, and the deploy prints them instead of pretending to
  have run them.
"""

from __future__ import annotations

# The repository's own plugins, in the order they are activated after the
# switch. Each lands with its own ticket (the skeletons in #27, the inquiry
# chain in #33) — until a plugin's source directory exists, `deploy` says so
# in its log and ships the rest, so the pipeline is provable before those
# tickets merge and needs no change after they do. The presentation plugin
# md-site owns present (spec 17 D6: 模板、卷、present、drift、短代码), which is
# why it comes first: the inquiry chain's pages render through it.
PLANNED_PLUGINS = ("md-site", "md-inquiry")

# The repository's files inside the live theme: `themes/<slug>/<file>` mirrors
# `wp-content/themes/<slug>/<file>`. The child theme is wholly this
# repository's (functions.php + style.css, spec 17 §1: 子主题只有
# functions.php,视觉零决策), so it ships as one tree; the parent theme
# (picostrap5) is an install-time archive piece (spec 20 §2.1 step 9), never
# in the deploy.
THEMES_REPO = "themes"
CHILD_THEME = "md-child"
THEMES_DIR = "wp-content/themes"
CHILD_THEME_TARGET = f"{THEMES_DIR}/{CHILD_THEME}"

# Never pushed to the host: the checkout's own bookkeeping and the projects a
# published plugin does not run (the vendor tree is rebuilt by the staging
# step, `composer install --no-dev`).
REPO = "plugins"
DEPLOY_EXCLUDES = (
    ".git/",
    "node_modules/",
    "vendor/",
    "tests/",
    ".phpunit.result.cache",
    "phpcs.xml.dist",
    "phpstan.neon.dist",
    "phpunit.xml.dist",
    "phpunit.integration.xml.dist",
)
PLUGINS_DIR = "wp-content/plugins"

# The repository's must-use plugins, and the site directory they land in.
# The top-level *.php files ship: the recorder (md-error-recorder.php), the
# public site's guards (md-site-guards.php) and the deploy's own opcode-cache
# reset (md-deploy-reset.php). The directory's project files (composer.json,
# tests/) are not deployed.
MU_PLUGINS_REPO = "mu-plugins"
MU_PLUGINS_DIR = "wp-content/mu-plugins"

# The mu-plugin of the review site only (noindex header, robots.txt fallback,
# mail written to files — spec 20 §1.2/§1.3): it ships to the staging target
# alone. Production's inventory filters it out; the local dev instance needs
# neither (the stack's own isolation mu-plugin already does all three there).
STAGING_MU = "md-staging-guards.php"

# The presentation plugin's DB-plane step (spec 20 §3.2: the LiveCanvas
# templates and the WindPress volumes are data, not files). The blueprint's
# present grew an `--allow-stale` guard against a stale WindPress cache; this
# plugin has not grown one yet (its first edition installs the tokens and the
# volume), so the call passes only what it accepts — the page-template
# tickets add the guard when they add the templates.
PRESENT = ("md-site", "present")

# The series cards' one-time seed (the hub's card images and copy). It rides
# the same DB plane as present, and for the same reason: the hub reads
# WooCommerce's own `thumbnail_id` and the term description, a freshly
# migrated site carries neither, and a clean target would otherwise ship nine
# blank cards. The command writes only an EMPTY field and marks each term it
# handled, so a later deploy onto a maintained site is a no-op and an
# administrator's edit — including a deliberate clearing — is never restored.
SEED_SERIES = ("md-site", "seed-series")

# The custom-screens pattern library's one-time tile order. Same DB plane,
# same discipline: the page's curation is WooCommerce's `menu_order`, a fresh
# target has every product at 0 (no tiles at all), and the seed gives the
# eighteen curated patterns their positions. It marks every product it has
# handled, so a later deploy is a no-op and an administrator's edit —
# including the deliberate REMOVAL of a tile (clearing `menu_order` back to
# 0, which the field alone cannot tell from "never seeded") — is never
# restored. It runs beside seed-series and before seo, so the editorial
# faces are in their seeded state for anything the SEO plane reads.
SEED_TILES = ("md-site", "seed-tiles")

# The SEO configuration plane's DB-plane step (spec 22, ticket #34): the same
# deploy that ships md-site installs its SEOPress state, so staging and
# production never see a deploy whose SEO surface went stale. Idempotent, and
# it reports (never blocks on) the pages that have not landed yet.
SEO = ("md-site", "seo")

# The WindPress cache compile is browser-side only (Tailwind v4 has no
# server-side build): the deploy prints how to run it and checks the result.
# The anchor-class half of the assertion is the presentation plugin's own
# checker (spec 17 §5.4, plugins/md-site/includes/Presentation/
# WindPressAnchors.php), reached through this WP-CLI contract: it prints the
# anchor classes the compiled cache does not carry, `[]` when it carries them
# all.
WINDPRESS_CACHE = "wp-content/uploads/windpress/cache/tailwind.css"
WINDPRESS_ANCHORS = ("md-site", "windpress-anchors", "--format=json")

# The read-only verify subset (spec 20 §3.3 step 7): the home page always, and
# the content entry points — asserted once the content import has run (its
# primary artifact is a published product; before it, the pattern pages and
# archives answer 404 by construction and a machinery deploy must still be
# able to verify and archive). The pattern page is the first published
# product's, read at verify time. /pattern/ is the URL base the owner ruled
# (AGENTS.md §2, 晨间修订 5).
HOME_PATH = "/"
CONTENT_PATHS = (
    "/collections/",
    "/inquiry/",
    "/blog/",
)
VERIFY_PATHS = (HOME_PATH, *CONTENT_PATHS)
PATTERN_BASE = "/pattern/"

# What only a person can do. The deploy prints these; it does not claim them.
# Cloudflare is out of the DNS and out of the request path (owner ruling
# 2026-10-25: DNS stays at the host panel, no NS migration, no CDN in front —
# Cloudflare's only role is the Turnstile widget), so there is no edge cache to
# bypass and no Development Mode step. The live response carries the host's
# own edge and LiteSpeed instead (`server: hcdn`, `x-litespeed-cache`; no
# `cf-ray`, seen on production 2026-10-26). The page and asset caches are
# therefore the deploy's own business: the LiteSpeed purge after the switch,
# and the browser-side WindPress compile.
MANUAL = (
    "hPanel: the staging site's directory password protection stays on (spec 20 §1.3)",
    "WindPress: run the browser compile after a presentation change (the deploy "
    "prints the command for this target and checks the result)",
)

# The full backup is weekly and before data writes (AGENTS.md §5); nothing
# schedules it, so each deploy says when the newest one is older than this.
FULL_BACKUP_DAYS = 7

# The host revalidates compiled PHP every 2 s (opcache.revalidate_freq=2,
# opcache.validate_timestamps=1, read on the platform 2026-10-18): the deploy
# asks the site to reset its opcode cache straight after the switch and
# retries for longer than one revalidation period.
OPCACHE_RESET_ATTEMPTS = 15
OPCACHE_RESET_PAUSE = 0.2  # seconds; 15 × 0.2 s = 3 s > the 2 s period

# The production page-cache contract: one anonymous GET must be answered from
# LiteSpeed's cache. LiteSpeed generates the entry for a miss on the first
# request and can finish after that response is already on its way, so the
# second immediate GET is not yet evidence of anything — measured on
# production 2026-10-26: a deploy's two back-to-back GETs both missed, and the
# next curls were all hits with `public, max-age=604800`. The check therefore
# polls to a deadline instead of demanding a hit on request two. 15 × 0.5 s =
# 7.5 s, well past a generation and short enough that a window does not hang.
PAGE_CACHE_ATTEMPTS = 15
PAGE_CACHE_PAUSE = 0.5
