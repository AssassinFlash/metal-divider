# metal-divider tasks. Toolchains, versions and setup: README.md.

set shell := ["bash", "-euo", "pipefail", "-c"]

# Composer projects: tools/local for its local mu-plugins and router (the
# plugins and mu-plugins of the site join this list as they land).
php_projects := "tools/local"

# uv projects: tools/local (md-local). The services and migrations of the
# repository join this list as they land.
py_projects := "tools/local"

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

# List the recipes.
default:
    @just --list

# Lint and static analysis of every chain.
check: php-check py-check

# Unit tests of every chain. tools/local's stack tests build real instances
# with this Mac's MySQL and WP-CLI; CI, which has none of them, skips them.
test: py-test

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

# Collect a licensed plugin into the local archives (a .zip file, or an
# unpacked plugin directory, e.g. a snapshot's copy).
[positional-arguments]
archive slug source:
    {{ md_local }} archive "$@"

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
