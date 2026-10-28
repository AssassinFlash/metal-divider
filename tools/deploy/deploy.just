# `just deploy …`: the deployment pipeline of metal-divider.com
# (tools/deploy, docs/specs/20-staging-deploy.md, branch research/spec-deploy).
# Three targets: dev (the local instance), staging, prod — one tool, one
# inventory, the same code the drill proves.
#
# Every step is a dry run by default: it prints what it would do. `--write`
# performs it, e.g. `just deploy-prod --write`. The target is a flag the same
# way the blueprint's cutover module takes it (`--target staging`); recipes
# without a target of their own default to dev. `just deploy` lists the
# recipes.

set shell := ["bash", "-euo", "pipefail", "-c"]
set ignore-comments := true

# `justfile_directory()` is the directory of the root justfile that imported this
# module (tools/local's usage is the same pattern).
project := justfile_directory() / "tools/deploy"
md_deploy := "uv run --locked --project " + quote(project) + " md-deploy"

# The PHP tools run on the stack's pinned PHP (the root justfile's rule).
php := env("PHP", if path_exists("/opt/homebrew/opt/php@8.3/bin/php") == "true" { "/opt/homebrew/opt/php@8.3/bin/php" } else { "php" })
md_local := "uv run --locked --project " + quote(justfile_directory() / "tools/local") + " md-local"

# List the recipes.
default:
    @just --list --justfile {{ justfile() }}

# The dev target's deploy (the local instance): what the drill proves, run
# by hand — `just deploy dev --write`. Dry run unless `--write` is passed.
[positional-arguments]
dev *args:
    PHP={{ php }} {{ md_deploy }} deploy --target dev "$@"

# The review site's deploy: the same artifact set plus the staging mu-plugin,
# `https://staging.metal-divider.com` — `just deploy staging --write`. Dry run
# unless `--write` is passed; `MD_DEPLOY_STAGING_AUTH=user:pass` carries the
# directory protection to verify.
[positional-arguments]
staging *args:
    PHP={{ php }} {{ md_deploy }} deploy --target staging "$@"

# Production's deploy: snapshot → build → upload → atomic switch → present →
# verify — `just deploy prod --write`. Dry run unless `--write` is passed; the
# version contract is checked first and a wrong plugin face refuses the deploy.
[positional-arguments]
prod *args:
    PHP={{ php }} {{ md_deploy }} deploy --target prod "$@"

# Restore the previous deployed files by rename; no database import (pair it
# with restore-snapshot when the database must go back too) —
# `just deploy rollback --target prod --write`; `--target` defaults to dev.
[positional-arguments]
rollback *args:
    PHP={{ php }} {{ md_deploy }} rollback-deploy "$@"

# Verify/archive a pending batch after an interrupted deploy or a failed verify.
[positional-arguments]
resume-deploy *args:
    PHP={{ php }} {{ md_deploy }} resume-deploy "$@"

# A database snapshot streamed to this Mac (~/metal-divider-local/snapshots/;
# a deploy takes one itself before the switch).
[positional-arguments]
snapshot *args:
    PHP={{ php }} {{ md_deploy }} snapshot "$@"

# The database snapshots on this Mac, newest first.
[positional-arguments]
snapshots *args:
    PHP={{ php }} {{ md_deploy }} snapshots "$@"

# Put a database snapshot back (previews; add `--write` to restore).
# The name is the CLI's positional: `just deploy restore-snapshot db-… --write`.
[positional-arguments]
restore-snapshot *args:
    PHP={{ php }} {{ md_deploy }} restore-snapshot "$@"

# The complete backup of a site (database, uploads, plugins, mu-plugins,
# .htaccess, options): weekly, and before every data write. Dry run unless
# `--write` is passed; e.g. `just deploy backup --target prod --write`.
[positional-arguments]
backup *args:
    PHP={{ php }} {{ md_deploy }} backup "$@"

# The media plane: push the finalized uploads in chunks (one connection per
# year/month). `--source <dir>` names the finalized set (default: the dev
# instance's uploads); a fresh complete backup is the gate.
[positional-arguments]
media *args:
    PHP={{ php }} {{ md_deploy }} media "$@"

# The read-only regression subset: the fixed entry points, the first pattern
# page, and on production the page-cache contract (miss then hit).
[positional-arguments]
verify *args:
    PHP={{ php }} {{ md_deploy }} verify "$@"

# The version contract readback: the target's versions against the local pins
# (tools/local versions.py).
[positional-arguments]
contract *args:
    PHP={{ php }} {{ md_deploy }} contract "$@"

# The checks after a WindPress compile: the cache exists, is newer than
# `--before <mtime>`, and holds the anchor classes.
[positional-arguments]
windpress-check *args:
    PHP={{ php }} {{ md_deploy }} windpress-check "$@"

# The maintenance window's on/off (the `.maintenance` file); the state is the
# CLI's positional: `just deploy maintenance on --target prod --write`.
[positional-arguments]
maintenance *args:
    PHP={{ php }} {{ md_deploy }} maintenance "$@"

# Rehearse the pipeline on a local instance: deploy → rollback → deploy
# again, the precondition of every window (spec 20 §3.3 step 0).
# `just up <name>` first; the record lands under ~/metal-divider-local/.
[positional-arguments]
drill *args:
    PHP={{ php }} {{ md_deploy }} drill "$@"

# Pull the sanitized chat seed from the golden host (staging) into
# ~/metal-divider-local/chat-seed/. Dry run unless --write is passed.
# --target is pinned to staging: the seed exports from the golden host only
# (md-deploy rejects any other --target with exit 2; forwarding a passed one
# would only duplicate the guard).
[positional-arguments]
chat-seed *args:
    PHP={{ php }} {{ md_deploy }} chat-seed --target staging "$@"

# Deploy the chat plane: relay the golden /chat/ tree and Rapls plugin, ship
# the two LHC extensions and the five chat mu-plugins from the repository, and
# apply the sanitized seed (SQL import + option rows). The caller must
# pass --target explicitly (a bare call would deploy to the local dev
# instance); the golden host is staging.
[positional-arguments]
chat-push *args:
    PHP={{ php }} {{ md_deploy }} chat-push "$@"

# The read-only chat probes against the target's base URL. No --write needed.
[positional-arguments]
chat-verify *args:
    PHP={{ php }} {{ md_deploy }} chat-verify "$@"

# Recompile an instance's WindPress cache through its admin — the only
# compile that exists (Tailwind v4 has no server-side build): one-time login,
# WindPress → Settings → Performance → Generate, the stored cache must be
# newer than the one before, and the deploy's windpress-check follows.
# The driver is tools/deploy/windpress-compile.mjs on the shared real-Chrome
# Playwright install (AGENTS §7: never the bundled Chromium).
[positional-arguments]
windpress-compile name="dev":
    #!/usr/bin/env bash
    set -euo pipefail
    repo="{{ justfile_directory() }}"
    before="$(PHP={{ php }} {{ md_local }} wp "{{ name }}" eval 'echo (int) @filemtime( wp_upload_dir()["basedir"] . "/windpress/cache/tailwind.css" );' | tail -n 1)"
    user="$(PHP={{ php }} {{ md_local }} wp "{{ name }}" user list --role=administrator --field=user_login | tail -n 1 | head -n 1)"
    if [ -z "$user" ]; then
        echo "no administrator found on {{ name }}" >&2
        exit 1
    fi
    login_url="$(PHP={{ php }} {{ md_local }} login "{{ name }}" --user="$user" | tail -n 1)"
    echo "windpress: compiling through the admin (user: $user, cache mtime before: $before)"
    node "$repo/tools/deploy/windpress-compile.mjs" "$login_url" "$before"
    after="$(PHP={{ php }} {{ md_local }} wp "{{ name }}" eval 'echo (int) @filemtime( wp_upload_dir()["basedir"] . "/windpress/cache/tailwind.css" );' | tail -n 1)"
    if [ -z "$after" ] || [ "$after" -le "$before" ]; then
        echo "windpress: the cache file did not get newer ($before -> $after)" >&2
        exit 1
    fi
    echo "windpress: cache recompiled ($before -> $after); now: just deploy windpress-check --target {{ name }}"

# Ruff (lint + format) and mypy (strict) over tools/deploy.
check:
    cd {{ project }} && uv run --locked ruff check . && uv run --locked ruff format --check . && uv run --locked mypy .

# pytest; no WordPress is started (the drill itself is the end-to-end run).
test:
    cd {{ project }} && uv run --locked pytest
