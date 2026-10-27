# metal-divider tasks. Toolchains, versions and setup: README.md.

set shell := ["bash", "-euo", "pipefail", "-c"]

# Composer projects: the site's plugins, tools/local for its local
# mu-plugins and router, and mu-plugins for the site's must-use plugins.
php_projects := "plugins/md-site plugins/md-inquiry tools/local tools/migrate mu-plugins"

# uv projects: tools/local (md-local), tools/media (md-media) and
# tools/migrate (md-migrate). The services of the repository join this list
# as they land.
py_projects := "tools/local tools/media tools/migrate tools/template-convert tools/deploy"
# The conversion pipeline joins both chains: ruff/mypy under check,
# pytest under test (its fixtures are in-repo; the raw old-site pages
# are the ticket's local evidence under .scratch, not shipped).
# uv projects: tools/local (md-local). The services and migrations of the
# repository join this list as they land.
# uv projects: tools/local (md-local), tools/media (md-media) and
# tools/deploy (md-deploy). The services and migrations of the repository
# join this list as they land.
# `just deploy …` is the deployment pipeline: dev/staging/prod targets, the
# rename-atomic file deploy with rollback, the snapshot every deploy carries,
# present and the media plane (tools/deploy/deploy.just,
# docs/specs/20-staging-deploy.md, branch research/spec-deploy).
mod deploy "tools/deploy/deploy.just"
# The PHP tools run on PHP 8.3, the stack's pinned version and the version
# contract the production host must meet (docs/specs/14-local-stack.md §2.3,
# branch research/spec-local-stack): Homebrew's php@8.3 when it is installed,
# otherwise `php` on PATH (CI). `PHP=/path/to/php just …` wins.
php := env("PHP", if path_exists("/opt/homebrew/opt/php@8.3/bin/php") == "true" { "/opt/homebrew/opt/php@8.3/bin/php" } else { "php" })

# Local WordPress instances (docs/specs/14-local-stack.md, branch
# research/spec-local-stack): `up` creates the instance on first use, then
# (re)starts it with this checkout's plugins. The instance name defaults to
# `dev`: one instance, reused by every ticket.
md_local := "uv run --locked --project tools/local md-local"

# The final media pipeline (docs/specs/15-media-pipeline.md, branch
# research/spec-media-pipeline). The corpus lives in data/ on this Mac and is
# never in the repository; media-build/media-check therefore run locally only.
md_media := "uv run --locked --project tools/media md-media"

# List the recipes.
default:
    @just --list --list-submodules

# Lint and static analysis of every chain.
check: php-check py-check volume-check

# Unit tests of every chain. The plugins' and mu-plugins' PHPUnit runs
# everywhere; tools/local's stack tests build real instances with this Mac's
# MySQL and WP-CLI; CI, which has none of them, skips them.
test: php-test py-test

# The must-use plugins' smoke suite against a real instance (`just up <name>`
# first): the security headers in real responses, the closed doors, the
# recorder's file. CI runs it in the mu-smoke job on a bare instance.
[positional-arguments]
mu-smoke name="dev":
    #!/usr/bin/env bash
    set -euo pipefail
    cd "{{ justfile_directory() }}/mu-plugins"
    MD_MU_WP="just wp {{ name }}" PHP={{ php }} {{ php }} vendor/bin/phpunit --testsuite integration

# The inquiry chain's smoke suite against a real instance (`just up <name>`
# first): the form, the 303, the record, the queue's routing, the one test
# mail, and the rate window — one mail per whole run, per the discipline.
[positional-arguments]
inquiry-smoke name="dev":
    #!/usr/bin/env bash
    set -euo pipefail
    cd "{{ justfile_directory() }}/plugins/md-inquiry"
    MD_INQUIRY_WP="just wp {{ name }}" PHP={{ php }} {{ php }} vendor/bin/phpunit --testsuite integration

# Create the instance if needed, then (re)start it with this checkout's plugins.
[positional-arguments]
up name="dev" *options: php-install
    PHP={{ php }} {{ md_local }} up "$@"

# Stop the instance's server; the data stays.
[positional-arguments]
down name="dev":
    PHP={{ php }} {{ md_local }} down "$@"

# Delete the instance's database and files, then build it again.
[positional-arguments]
reset name="dev" *options: php-install
    PHP={{ php }} {{ md_local }} reset "$@"

# Print a one-time login link: ten minutes, one use (--user <login>, default local-admin).
[positional-arguments]
login name="dev" *options:
    PHP={{ php }} {{ md_local }} login "$@"

# List the instances: port, database, versions, linked checkout.
status:
    PHP={{ php }} {{ md_local }} status

# Run WP-CLI against the instance, e.g. `just wp plugin list`.
[positional-arguments]
wp name="dev" *args:
    PHP={{ php }} {{ md_local }} wp "$@"

# Install md-site's presentation files into the instance (spec 17 §4.4: the
# WindPress volume plus the two legacy-copy guards; idempotent, safe to rerun).
[positional-arguments]
present name="dev": php-install
    PHP={{ php }} {{ md_local }} wp "{{ name }}" md-site present --user=local-admin

# Install the SEOPress configuration plane into the instance (spec 22,
# ticket #34: title/description templates, noindex faces, sitemap, robots.txt,
# knowledge graph, schema builder posts, /pages redirect, page metas). With
# --check, the read-only drift report instead. Idempotent, safe to rerun.
[positional-arguments]
seo name="dev" *args: php-install
    PHP={{ php }} {{ md_local }} wp "{{ name }}" md-site seo --user=local-admin {{ args }}

# Collect a licensed plugin into the local archives (a .zip file, or an
# unpacked plugin directory, e.g. a snapshot's copy).
[positional-arguments]
archive slug source:
    {{ md_local }} archive "$@"

# Rebuild the replica LiveCanvas templates and WindPress volumes from the
# old-site captures (ticket #32's conversion pipeline, spec 19 §4). The raw
# pages and the theme-assets directory are the ticket's local evidence
# (.scratch/<ticket>/raw, the old theme checkout); the media state is the
# media pipeline's source→final map when it has run (ticket #29).
templates raw=".scratch/32/raw" theme-assets="" media-state="":
    #!/usr/bin/env bash
    set -euo pipefail
    args=""
    if [ -n "{{ theme-assets }}" ]; then args="$args --theme-assets {{ theme-assets }}"; fi
    if [ -n "{{ media-state }}" ]; then args="$args --media-state {{ media-state }}"; fi
    cd tools/template-convert
    uv run --locked md-template-convert --raw "{{ justfile_directory() }}/{{ raw }}" $args --plugin "{{ justfile_directory() }}/plugins/md-site" --scratch "{{ justfile_directory() }}/.scratch/32"

# Fork the approved journal (blog) faces from the de-linker checkout
# (spec 19 §2.2 R1): brand, addresses and tokens mapped, markup intact.
blog-fork source:
    cd tools/template-convert && uv run --locked md-template-convert --blog-source {{ source }} --plugin "{{ justfile_directory() }}/plugins/md-site"

# The WindPress volumes' syntax gate (ticket #49): every volume under
# plugins/md-site/content/windpress brace-balanced (strings and comments
# respected) and free of tinycss2 parse errors. The WindPress compiler is
# fatal on the first unbalanced brace — #44's extraction debt shipped 24
# such volumes and took the site's styles down unnoticed; this gate makes
# it a check-time failure instead.
volume-check:
    cd tools/template-convert && uv run --locked md-volume-check "{{ justfile_directory() }}/plugins/md-site"

# Build the full final media set: data/media-raw -> data/media-final
# (variants, sidecars, checkpoint state). Full corpus run, minutes.
media-build:
    {{ md_media }} build
# Verify the final set: coverage, sha256, sizes, formats, gallery rules,
# lossless pixel identity, budget. Non-zero exit = FAIL.
media-check:
    {{ md_media }} check
# Produce the final single file for an owner upload, e.g.
# `just media-one ~/Desktop/foo.jpg` (no tier ladder, spec §8.3).
[positional-arguments]
media-one *file:
    {{ md_media }} one "$@"

# The Shopify -> WordPress migration (docs/specs/18-migrator.md, branch
# research/spec-migrator): plan, the five apply stages and the reconcile,
# e.g. `just migrate plan`, `just migrate apply --stage products --write`,
# `just migrate reconcile`. The corpus lives on this Mac, so these run
# locally only, against the dev instance by default.
[positional-arguments]
migrate *args:
    uv run --locked --project tools/migrate tools/migrate/18-migrate.py "$@"

# Export the dev instance's attachment map (_md_source_url -> the
# uploads-relative _wp_attached_file plus the _md_variants the import
# recorded) into tools/migrate/data/dev-attachment-map.json: the remote-mode
# media binding authority (branch imp/54-remote-media). The variant names
# matter as much as the file: the sidecar tier names postdate the dev import
# (renamed by a later media rebuild), so the target's real sibling names are
# the ones dev recorded at import time. A remote plan (--wp) binds the
# already-deployed uploads through this map instead of copying from this
# Mac's media-final paths. Regenerate after any dev media write, then
# re-plan.
[positional-arguments]
attachment-map name="dev":
    #!/usr/bin/env bash
    set -euo pipefail
    cd "{{ justfile_directory() }}"
    just wp {{ name }} db query "SELECT s.meta_value AS src, f.meta_value AS file, v.meta_value AS variants FROM wp_postmeta s JOIN wp_postmeta f ON f.post_id = s.post_id AND f.meta_key = '_wp_attached_file' LEFT JOIN wp_postmeta v ON v.post_id = s.post_id AND v.meta_key = '_md_variants' WHERE s.meta_key = '_md_source_url'" \
        | python3 -c 'import csv, json, sys; rows = list(csv.DictReader(sys.stdin, delimiter="\t")); print(json.dumps({"schema": "md-migrate/attachment-map@1", "map": {r["src"]: {"file": r["file"], "variants": [{"w": int(t["w"]), "f": t["f"]} for t in (json.loads(r["variants"]) if r["variants"] else [])]} for r in rows}}, ensure_ascii=False, indent=1, sort_keys=True))' \
        > tools/migrate/data/dev-attachment-map.json
    python3 -c 'import json; d = json.load(open("tools/migrate/data/dev-attachment-map.json"))["map"]; print("attachment map:", len(d), "entries,", sum(len(v["variants"]) for v in d.values()), "variant tiers")'

# Pixel gate: capture the instance's pages with the baseline protocol and
# diff them against the metalpartition.com baseline (tools/pixel-check,
# ticket #30). Baseline = data/pixel-baseline of this checkout; extra args
# pass through (e.g. `just pixel-check dev --filter '^home' --viewports 1440`).
# Captures are taken through opencli against the owner's Chrome; the npm
# install here is pixelmatch and pngjs only — no browser is downloaded.
[positional-arguments]
pixel-check name="dev" *args:
    #!/usr/bin/env bash
    set -euo pipefail
    # [positional-arguments] passes the parameters as $1…, not as $name; the
    # instance name stays $1 and the rest are the checker's own arguments.
    name="$1"; shift
    registry="${MD_LOCAL_HOME:-$HOME/metal-divider-local}/instances.toml"
    port=$(awk -v n="$name" '$0 == "[" n "]" { f=1; next } f && $1 == "port" { gsub(/[^0-9]/, "", $3); print $3; exit }' "$registry")
    [ -n "$port" ] || { echo "pixel-check: no instance '$name' in $registry" >&2; exit 1; }
    if [ ! -d tools/pixel-check/node_modules ]; then
        echo "pixel-check: installing tools/pixel-check (npm ci)"
        (cd tools/pixel-check && npm ci --no-fund --no-audit)
    fi
    cd tools/pixel-check
    exec node check.mjs --base "http://127.0.0.1:$port" "$@"

[private]
php-install:
    #!/usr/bin/env bash
    set -euo pipefail
    if ! {{ php }} -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 ? 0 : 1);'; then
        echo "PHP 8.3 is required; {{ php }} is $({{ php }} -r 'echo PHP_VERSION;'). See README.md." >&2
        exit 1
    fi
    composer="$(command -v composer)"
    for project in {{ php_projects }}; do
        cd "{{ justfile_directory() }}/$project"
        # `composer install` only warns about a composer.lock that no longer
        # matches composer.json; `validate` fails, as `npm ci` and `uv --locked` do.
        {{ php }} "$composer" validate --no-check-publish
        {{ php }} "$composer" install --no-interaction --no-progress --quiet
    done

[private]
php-test: php-install
    #!/usr/bin/env bash
    set -euo pipefail
    for project in {{ php_projects }}; do
        cd "{{ justfile_directory() }}/$project"
        # The unit tests run everywhere, CI included; the integration suite is
        # `just mu-smoke`, which needs an instance. phpunit.xml.dist names the
        # unit suite its default, and a project without one has nothing to run.
        if [ -f phpunit.xml.dist ]; then
            echo "== $project: PHPUnit"
            {{ php }} vendor/bin/phpunit
        fi
    done

[private]
php-check: php-install
    #!/usr/bin/env bash
    set -euo pipefail
    for project in {{ php_projects }}; do
        echo "== $project: PHPCS, PHPStan"
        cd "{{ justfile_directory() }}/$project"
        {{ php }} vendor/bin/phpcs -q
        # PHP's default 128 MB is too small once the WordPress stubs load.
        {{ php }} vendor/bin/phpstan analyse --no-progress --memory-limit=-1
    done

[private]
py-check:
    #!/usr/bin/env bash
    set -euo pipefail
    for project in {{ py_projects }}; do
        echo "== $project: Ruff, mypy"
        cd "{{ justfile_directory() }}/$project"
        uv run --locked ruff check .
        uv run --locked ruff format --check .
        uv run --locked mypy .
    done

[private]
py-test:
    #!/usr/bin/env bash
    set -euo pipefail
    for project in {{ py_projects }}; do
        echo "== $project: pytest"
        cd "{{ justfile_directory() }}/$project"
        # tools/local's stack tests build real instances with this PHP.
        PHP={{ php }} uv run --locked pytest
    done
