# Chat-plane production runbook

Operator runbook for the chat plane (Live Helper Chat + Rapls + our code). The operator is an agent session. Production `--write` is an owner-window item; this document does not authorize it.

Golden source is staging. Staging and production are two sites on one Hostinger account, reached by the ssh alias `hostinger-metal-divider`. Secrets stay on that host. This repository is public: no secret values, no customer conversations, no visitor identifiers in the repo or in logs.

## Purpose

Ship the chat surface from the staging golden copy onto a target (production, when the owner window is open) as one plane:

- the golden `/chat/` tree (LHC 4.97v and Rapls 1.21.4 are third-party and are not in the repository);
- our code from the checkout (`lhc-extensions/mdvisitor`, `lhc-extensions/mdmobile`, five mu-plugins);
- a config-only seed, secrets stripped, rewritten for the target.

Runtime data never ships. Each step below ends on a check the operator can read without printing a secret.

## Preconditions

All three must be true before any target write. A miss is a stop, not a prompt to improvise.

| Gate | Done when |
| --- | --- |
| Staging is the golden source | SSH `hostinger-metal-divider` reaches `/home/u388076051/domains/staging.metal-divider.com/public_html`. Chat root is `chat/` under that docroot. LHC is 4.97v. Rapls is 1.21.4. Extensions on the golden tree are `mdvisitor` and `mdmobile`. |
| Seed is validated | The export in Sequence step 2 printed counts and paths only, and the ship set passed the seed checks in that step. |
| Owner window | A production `--write`, a production DNS or GSC change, or anything on the Shopify side is not started from this runbook. See Manual-owner-items. A staging write still needs a fresh target backup first (AGENTS.md §5). |

Stack the seed describes, and must still match before export:

- WordPress on staging, table prefix `stg_`. LHC tables are `lh_` with no prefix. Rapls knowledge table in the dump is `stg_raplsaich_knowledge`.
- Five mu-plugins, shipped from the repo, not from the golden tree: `md-kb-endpoint.php`, `md-wecom-notify.php`, `md-chat-email.php`, `md-chat-delete.php`, `md-chat-launcher.php`.
- Rapls plugin directory relayed from the golden host, not from the repo: `rapls-ai-chatbot`.

Public-repo rule for every command in this document: stdout may contain counts, paths, key names, and byte lengths. It must not contain a token, a password, a chat body, or a visitor hash.

## Sequence

Prescribed chat entry points, same shape as the other `deploy.just` recipes (`uv run --locked --project <repo>/tools/deploy md-deploy <step>`):

```bash
uv run --locked --project tools/deploy md-deploy chat-seed
uv run --locked --project tools/deploy md-deploy chat-push
uv run --locked --project tools/deploy md-deploy chat-verify
```

Dry-run is the default on every existing `md-deploy` step. `--write` performs it. `--target` is `staging` or `prod` (default on the existing CLI is `dev`). Pass `--target` explicitly.

**Wiring.** `chat-seed`, `chat-push`, and `chat-verify` are registered in `tools/deploy/src/md_deploy/cli.py` and have `tools/deploy/deploy.just` recipes. All three take `--target` (staging/prod/dev); `chat-push` and `chat-seed` dry-run by default and execute only with `--write`; `chat-verify` is read-only. If the process exits "unknown command", stop. Do not hand-roll an import, and do not treat a missing command as a passed dry-run.

### 1. Reach the host

```bash
ssh -o BatchMode=yes hostinger-metal-divider 'php -v'
```

Done when the alias resolves and prints a PHP version. Credentials are not in this command and must not be added to it.

### 2. Export the seed from golden staging

```bash
uv run --locked --project tools/deploy md-deploy chat-seed --target staging
```

The only export implementation in the tree today is the two scripts that run **on the golden host** (they hardcode the staging docroot and write `/tmp/md-seed` and `/tmp/md-seed.tar.gz` there):

- `tools/deploy/seed/dump-chat-seed.sh` — data-only dumps. Prints `DUMPS-OK` and `TAR-OK`.
- `tools/deploy/seed/export-chat-seed.php` — blanks credentials. Prints `prefix`, `users`, `chat_config_rows`, `blanked`, `secret_rows_struck`, `users_setting_rows`, `users_setting_struck`, `options`. Never a secret value.

`chat-seed` is the entry. If it is not registered, run those two scripts on the host and stop there — that is the export, not the push. The tarball is the sanitized seed artifact. Do not pull `chat/settings/settings.ini.php`. The retired `staging-passwords-local-only.sql` is no longer written; if one is ever seen, stop — it is residue from an old export.

Seed contents (config only):

| Artifact | What it is | Ships? |
| --- | --- | --- |
| `lhc-config-data.sql` | Whitelisted `lh_` config rows, data only, `REPLACE` | Yes, after the sanitized user and `lh_chat_config` rows are part of the same seed |
| `lhc-users-sanitized.sql` | `lh_users` with `password` blanked | Yes, as the user rows. A password-hash file no longer exists |
| `lhc-chat-config-sanitized.sql` | `lh_chat_config` with `export_hash`, `autologin_data.secret_hash`, `mobile_options.fcm_key` blanked | Yes |
| `lhc-secret-tables.sql` | `lh_generic_bot_rest_api` and `lh_abstract_rest_api_key`, hex runs of 40–64 chars struck, IP allowlist kept | Yes |
| `lhc-users-setting-sanitized.sql` | `lh_users_setting` + `lh_users_setting_option` desk preferences; identifiers matching the credential regex are blanked and hex runs struck | Yes. The golden host and completed bring-ups skip it |
| `rapls-knowledge.sql` | `stg_raplsaich_knowledge`, 10 rows, data only | Yes. Prefix is rewritten at import |
| `chat-options.json` | `md_wecom_notify` and `md_kb_endpoint` with secret keys removed | Yes. Secret keys are written on the target, not from this file |
| `lhc-schema-local-only.sql` | Schema of the seed tables | No. Local validation only. The target installer creates schema |

`dump-chat-seed.sh` does **not** put `lh_users`, `lh_chat_config`, `lh_users_setting`, or `lh_users_setting_option` into `lhc-config-data.sql`. Those rows come from the PHP exporter's sanitized files. A seed that is only `lhc-config-data.sql` is incomplete.

Whitelisted LHC data tables: `lh_departament`, `lh_generic_bot_bot`, `lh_generic_bot_group`, `lh_generic_bot_trigger`, `lh_webhook`, `lh_users` (password blank), `lh_groupuser`, `lh_userdep`, `lh_canned_msg`, `lh_canned_msg_dep`, `lh_abstract_email_template`, `lh_chat_config` (secrets blank), `lh_group`, `lh_role`, `lh_grouprole`, `lh_rolefunction`, `lh_users_setting`, `lh_users_setting_option`, `lh_speech_language`, `lh_speech_language_dialect`.

Never in the seed: chats, messages, `md_wecom_notify_chats`, `md_wecom_notify_sends`, `raplsaich_unanswered_log`, the derived 146-row Rapls index (rebuilt on the target), and `raplsaich_settings` (shipping it would enable the stock Rapls badge instead of the LHC launcher).

Done when all of the following hold, and the check printed counts only:

- export stdout has no 40–64 hex run and no password value;
- the ship set has no `staging-passwords-local-only.sql` and will not import `lhc-schema-local-only.sql`;
- knowledge dump is the 10-row table;
- a scan of the ship set for `[a-f0-9]{40,64}` reports zero matches (report the count, not the match).

### 3. Back up the target before a data write

WordPress plane, existing tools. Dry-run first, then `--write`.

```bash
just deploy backup --target prod
just deploy snapshot --target prod
just deploy backup --target prod --write
just deploy snapshot --target prod
```

Use `--target staging` when the target is staging. Backup and snapshot write to this Mac under the deploy data root; they are not a second copy of chat secrets into the repo.

Done when the backup directory and the snapshot name (`db-YYYYMMDDTHHMMSSZ.sql.gz`) are recorded, and the log line has a size and a hash prefix, not a secret. Keep the snapshot name for Rollback.

### 4. Keep the previous chat tree on the host

The WordPress rollback tools do not restore `/chat/`. `Target.rsync` is `rsync -az --delete` and does not pass `--backup`. Take the previous copy **on the host, before any relay**, under this project's namespace (`$HOME/deploy-prev-metal-divider/<site>`, not `~/deploy-prev`, which de-linker prunes).

Production:

```bash
ssh -o BatchMode=yes hostinger-metal-divider 'set -euo pipefail
src=/home/u388076051/domains/metal-divider.com/public_html/chat
stamp=$(date -u +%Y%m%dT%H%M%SZ)
dest="$HOME/deploy-prev-metal-divider/metal-divider.com/chat-prev-$stamp"
mkdir -p "$dest"
if [ -d "$src" ]; then rsync -a "$src/" "$dest/"; printf "chat-prev=%s\n" "$dest"; else printf "chat-prev=absent\n"; fi'
```

Staging: same command with `staging.metal-divider.com` in both the docroot and the namespace directory.

Done when stdout is `chat-prev=<host path>` or `chat-prev=absent` (first install). The path stays in the operator log. The tree stays on the host. Do not rsync it to the Mac.

### 5. Dry-run the push

```bash
uv run --locked --project tools/deploy md-deploy chat-push --target prod
```

`chat_push_plan()` is the order a push must show, whether or not the CLI prints it yet:

1. Snapshot the target database (kept on this Mac).
2. Open the maintenance window.
3. Relay the golden `/chat/` tree. Excludes: `/settings/`, `/cache/`, `/var/`, `/sites/`, `/storage/`.
4. Relay `rapls-ai-chatbot` from the golden host.
5. Ship `lhc-extensions/mdvisitor` and `lhc-extensions/mdmobile` from the repository.
6. Ship the five mu-plugins from the repository.
7. Import the sanitized seed (`REPLACE`, data only). The golden host and any target whose `md_chat_push_bringup` marker reads back `1` skip the four sanitized credential files (`users`, `users_setting`, `chat_config`, `secret_tables`) so a later push never blanks secrets that were set on the host. `chat.py` blanks those rows once on the first import and never generates secrets.
8. Rewrite seed URLs (bare hostname) and the Rapls prefix (`stg_raplsaich_` → the target's own prefix, read from that site's `wp-config.php`). Do not assume the production prefix.
9. Merge the chat option rows on the target host. Secrets never transit the Mac.
10. Hash-verify the shipped lhc-extensions and mu-plugins on both sides.
11. Reset the LHC compiled-template cache dirs inside the window (`rm -rf` + `mkdir -p`: LHC never recreates a deleted cache directory, it fatal-errors).
12. Close the maintenance window.
13. Purge the WordPress page cache after the window closes (the purge notify is an HTTP self-request that 503s against the live maintenance page).

The three things the push does NOT do stay manual: fresh secrets (next section), the launcher host gate, the operator password.

Done when the dry-run printed the plan and changed nothing. If the subcommand is missing, stop. Do not run a hand-rolled `rsync --delete` from the Mac.

### 6. Execute the push

Owner window required for `--target prod --write`. Not required for a staging write that already has the step 3 backup.

```bash
uv run --locked --project tools/deploy md-deploy chat-push --target prod --write
```

Done when the command exits 0, both sides' md5 list matches, and the log has no secret value. Then complete Fresh-on-target checklist before any probe. A push that skipped a checklist row is not done.

### 7. Purge caches

LiteSpeed serves full-page HTML for up to a week. A mu-plugin or template change is invisible until the purge. `cache/` is excluded from the relay, so the target's compiled LHC templates are the previous copy until cleared (`chat/cache/compiledtemplates/`, `chat/cache/cacheconfig/`).

```bash
ssh -o BatchMode=yes hostinger-metal-divider '/usr/local/bin/wp --path=/home/u388076051/domains/metal-divider.com/public_html litespeed-purge all'
```

Done when the purge command exits 0. Staging uses its own docroot. The exact LHC cache-clear command is unverified; clearing those two cache directories on the target is the known stale-compile location. Do not delete `chat/settings/`.

### 8. Verify

Follow Verification gates. Done when every gate in that section passes. A 200 with a stale body is a fail.

## Fresh-on-target checklist

Created on the target. Never copied from staging's files, never taken from the seed's blanked fields, never written into the repo.

| Item | Write | Read back (no secret in the log) |
| --- | --- | --- |
| `chat/settings/settings.ini.php` | DB credentials the target's own database uses. `secrethash`, `site_address`, `trusted_host_patterns`. Extensions `mdmobile` and `mdvisitor` enabled. `settings/` is a relay exclude, so a relay must not be what creates this file. | File exists on the target. `export-chat-seed.php`'s db read shape is `settings.db.host`, `.database`, `.port`, `.user`, `.password`. Other key paths: unverified. |
| Operator password | New password on the target after `lh_users` imports with `password` blank. | Operator can sign in. Log the user id, not the password. Set-password command: unverified. |
| Operator desk flags | The sanitized `lh_users_setting` file blanks identifiers that match the credential regex, so flags like `auto_accept_mail` arrive empty (the exporter printout shows `users_setting_struck`). The operator re-enables any flag they use; everything else (language, list toggles, filters) arrives as-is. | Read back the non-secret flags the operator expects. |
| `lh_abstract_rest_api_key.api_key` | New key, generated on the host. The same value is `md_wecom_notify.lhc_api_key` (they are one key). | Row exists; log byte length only. |
| `md_wecom_notify` secrets | `bearer_token`, `lhc_api_key`, `wecom_callback_token`, `wecom_callback_aes_key`, `wecom_secret`. Generated on the host. | Each key present; log name and byte length. |
| `md_wecom_notify` non-secrets | `lhc_base_url` = `https://<target-host>/chat` (the connector posts to `{lhc_base_url}/index.php/restapi/generateautologin`). `lhc_username` = a username that arrived in the sanitized `lh_users` seed. `wecom_to_user` = `John`. `wecom_corp_id` = the target's corp id (value not in the repo; write it on the host, do not print it). `wecom_agent_id` = `1000002`. | Read back the non-secret strings. No staging hostname left in `lhc_base_url`. |
| `md_kb_endpoint` secrets | `bearer_token`, `deepseek_api_key`. Generated on the host. | Name and byte length only. |
| `md_chat_launcher_hosts` | Production: `['metal-divider.com','www.metal-divider.com']`. Not a seed row. Empty or malformed falls back to `staging.metal-divider.com` and `localhost`, so production stays off until this option is a valid string list. | `wp option get` prints that list and nothing else. |
| REST API URLs | Bare replace `staging.metal-divider.com` → target host inside the imported seed, including `lh_generic_bot_rest_api`. | Count of the golden hostname in the imported seed tables is 0. Do not print the rows. |
| `lh_chat_config` rewrites | `customer_site_url` and `customer_company_name` are the target's own values (explicit rewrite fields). SMTP `default_from` / sender = `info@<target host>` (public mailbox). | Those non-secret fields equal the target values. Identifier names for the SMTP fields: unverified. `export_hash`, `autologin_data.secret_hash`, `mobile_options.fcm_key` are blank in the seed and regenerated on the target; do not print them. |

IP allowlist rows in `lh_generic_bot_rest_api` stay (same physical host). Do not copy the IP list into the repo or the log.

## Secrets relay procedure

Path constraint: staging and production share the host behind `hostinger-metal-divider`. A secret moves only inside that host. It does not transit this Mac, the repository, CI logs, `/tmp/md-seed.tar.gz`, or a file under the checkout.

There is no relay script in the repo. Do not add one to the checkout to make this step "easier".

1. SSH with `BatchMode=yes`. Do not add `-v`. Do not `scp` `settings.ini.php` or an options dump.
2. On the host, generate the fresh-on-target secret values and write them into the target's `settings.ini.php`, `lh_abstract_rest_api_key.api_key`, and the two WP options. The fresh list is not a copy of the staging secret row. `chat.py` never generates secrets; it blanks the seed credential rows once and defers regeneration to this procedure.
3. The one required coupling: the new `lh_abstract_rest_api_key.api_key` and `md_wecom_notify.lhc_api_key` are the same value, written once on the host into both places.
4. Seal stdout the way `export-chat-seed.php` already does: key name, byte length, `set` or `blank`. A command that would echo the value is the wrong command. Redirect nothing into a file that later gets committed or attached to a log.
5. Non-secret rewrites (`lhc_base_url`, launcher hosts, `customer_site_url`, SMTP from-address) may be printed. Secret fields may not.
6. After the write, repeat the seed hex scan against any artifact that will leave the host. Zero matches, count only.

Done when every fresh secret row in the checklist has `set` and a non-zero length, and no secret-bearing file exists under the repo or the seed tarball.

## Verification gates

Three gates. All three. Probes are cache-busted: `chat_verify_probes()` does not append a query string, so add one. Use a UTC stamp `YYYYMMDDHHMMSS` (the same shape `cli.py` uses) and a new stamp per run. Quote URLs; the widget path contains parentheses.

```bash
uv run --locked --project tools/deploy md-deploy chat-verify --target prod
```

If that subcommand is missing, run the five probes yourself with `curl -fsS` and the same stamp. Staging directory protection is `MD_DEPLOY_STAGING_AUTH` (environment only; the WP verify step reads it). `chat_verify_probes()` does not attach that header. A staging 401 is the protection, not a chat failure — supply the env pair without writing it down. Production probes are anonymous.

| Probe | URL | Body must contain |
| --- | --- | --- |
| widget-document | `https://<target>/chat/index.php/chat/chatwidget/(department)/1?cb=<stamp>` | `md-card` |
| launcher-script | `https://<target>/chat/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-launcher.js?cb=<stamp>` | `mdvl-launcher` |
| widget-css | `https://<target>/chat/extension/mdvisitor/design/mdvisitortheme/css/mdvisitor-chat.css?cb=<stamp>` | `mdv-closed-actions` |
| rest-route-exists | `https://<target>/wp-json/md/v1/chat/delete?cb=<stamp>` | `rest` |
| home-carries-launcher | `https://<target>/?cb=<stamp>` | `mdvisitor-launcher.js` |

Done for this gate when all five return the marker. A hit without the unique query string does not count (LiteSpeed may still be serving last week's HTML).

### Real Chrome

AGENTS.md §7: this machine's real Chrome, Playwright `channel: 'chrome'`, persistent profile `~/metal-divider-local/hpanel-profile`. Not bundled Chromium, not Chrome for Testing, not headless. No chat-chain script path is in the tree (unverified). The chain the operator runs:

1. Open `https://<target>/?cb=<stamp>` in that Chrome. The launcher script is on the page.
2. Open the widget. The document shows the visitor card (`md-card`).
3. Send one probe message. Do not use a customer name, a customer address, or a pasted conversation.
4. Continue to the delete gate before calling the chain done.

### One live probe, then delete

One conversation on the target, created by the Chrome chain, deleted before the session ends.

Delete through the widget's own control. That control POSTs `chat_id` and `hash` to `/wp-json/md/v1/chat/delete`. The hash is the capability; it stays in the browser. Do not log it. Do not `SELECT` message text. Do not delete only `lh_chat` — that row has no cascade, and the mu-plugin exists because a bare chat-row delete leaves satellite rows.

Done when a count query for that `chat_id` in `lh_chat` returns 0, the count is the only row content in the log, and no transcript was written to the repo, the issue, or the deploy log.

## Rollback

WordPress file plane, existing command (the recipe name is `rollback`; the CLI subcommand is `rollback-deploy` — there is no `md-deploy rollback`):

```bash
just deploy rollback --target prod --write
```

That restores the previous deployed files by rename. It does not import a database. When the chat seed import must be undone, restore the snapshot from Sequence step 3:

```bash
just deploy restore-snapshot db-YYYYMMDDTHHMMSSZ.sql.gz --target prod --write
```

Chat tree: copy the host path from Sequence step 4 back over the target `chat/` **on the host**. Do not pull that tree to the Mac to "restore" it. There is no `md-deploy chat-rollback`. After the copy, purge LiteSpeed again and re-run the five probes. The probe conversation must already be deleted; a rollback is not a reason to keep it.

Done when the previous chat tree is the one on the docroot, the snapshot name used is the one taken before the failed push, and the five probes match the restored tree or are an explicit fail that stops the window.

## Manual-owner-items

The operator does not start these. An explicit owner approval is the only thing that opens them.

- Production `--write` (`chat-push --target prod --write`, and any other `md-deploy` `--write` against `prod`).
- Production DNS or GSC changes. None are expected for the chat plane. If one appears, stop.
- The Shopify side. Forbidden. `metalpartition.com` domain, DNS, store, and subscription stay untouched. No old-domain 301, no cancel, no migration.

hPanel credentials, if SSH is down, are read from `~/Downloads/wp-admin.txt` on the operator machine and are never copied into the repo or the log.

## References

- Wayfinder map: GitHub issue #148. Chat-surface implementation evidence: GitHub issue #161.
- Plane definition and probe list: `tools/deploy/src/md_deploy/chat.py`.
- Seed export: `tools/deploy/seed/export-chat-seed.php`, `tools/deploy/seed/dump-chat-seed.sh`.
- Existing deploy recipes and the `--write` default: `tools/deploy/deploy.just`, `tools/deploy/src/md_deploy/cli.py`.
- Host alias, docroots, backup namespace: `tools/deploy/src/md_deploy/target.py` (`hostinger-metal-divider`, staging and production `public_html`, `$HOME/deploy-prev-metal-divider/<site>`).
- Public-repo rule and the real-Chrome rule: `AGENTS.md` §4 and §7. Backup-before-write: `AGENTS.md` §5. Local retention of the Mac-side snapshot: `docs/agents/local-storage.md`.

## Unverified

Listed here instead of invented. Do not fill these gaps with a guessed command.

- `chat-seed` / `chat-push` / `chat-verify` registration is verified in `cli.py` and `deploy.just` as of 2026-10-28 (dry-run default, `--write` to execute, `--target` on all three).
- `Target.rsync` does not pass `--backup`. The previous-copy step above is the runbook's retention, not a behavior of an existing chat-push.
- No secrets-relay script exists. The procedure is the constraint (host-side, sealed stdout), not a command name.
- `settings.ini.php` key paths for `secrethash`, `site_address`, `trusted_host_patterns`, and the extensions enable list are not in the repo (`settings/` is excluded; zero matches). The `settings.db.*` keys are verified from the exporter.
- `lh_chat_config` identifiers for SMTP `default_from` / sender are not in `CHAT_CONFIG_REWRITE_FIELDS` (that tuple is only `customer_site_url` and `customer_company_name`). The bare hostname replace covers an address that still contains `staging.metal-divider.com`. It does not invent `info@<target host>` if the field holds something else.
- Production table prefix is not in the files read. Read it from the target `wp-config.php` at deploy time.
- `wecom_corp_id` and `lhc_username` values are not in the files read. `wecom_to_user=John` and `wecom_agent_id=1000002` are the values this runbook writes; the unit fixture uses `wecom_to_user` `chester` and the same agent id. Live staging option values were not read.
- LiteSpeed full-page TTL of up to a week is a given operating fact. The files read confirm `wp litespeed-purge all`, not the TTL number.
- Rapls 1.21.4 is named by the task and by a test-stub comment. No Rapls version file is in the repository (the plugin is relayed from the golden host).
- The LHC cache-dir reset runs inside the maintenance window (`_reset_lhc_caches`: `rm -rf && mkdir -p` on `chat/cache/compiledtemplates` and `chat/cache/cacheconfig` — LHC does not recreate a deleted cache directory, it fatal-errors through a template titled "Site Maintenance"); the WordPress page-cache purge runs after the window closes (`_purge_page_cache`: `wp litespeed-purge all`; the notify is an HTTP self-request that 503s against the live maintenance page).
- The operator-password set command is not in the repo.
- No real-Chrome chat-chain script path is in the grounding files.
- Issue #148 and #161 were not re-fetched in this session; the roles above are the ones this runbook was told to cite.
