#!/bin/bash
# Dump the chat plane seed data from the golden host (staging).
#
# Part of the md-deploy chat plane (tools/deploy/src/md_deploy/chat.py).
# Runs ON the golden host, next to export-chat-seed.php. Reads DB
# credentials from LHC's settings.ini.php (never from the command line)
# and emits to /tmp/md-seed/:
#
#   lhc-config-data.sql     whitelisted lh_ config rows (data only)
#   lhc-users-setting-sanitized.sql  operator desk preferences, emitted
#                              by export-chat-seed.php with credentials
#                              blanked (these tables can carry per-operator
#                              mail/IMAP passwords and tokens, so they never
#                              ride the plain dump)
#   lhc-schema-local-only.sql  schema of every seed table - LOCAL
#                              validation only, never imported on a
#                              target (installers there create schema)
#   rapls-knowledge.sql     the 10 knowledge rows (data only)
#
# The tarball it leaves in /tmp is the seed artifact to pull; secrets
# handling is export-chat-seed.php's job.
set -euo pipefail
CHAT=/home/u388076051/domains/staging.metal-divider.com/public_html
PHP_BIN=$(command -v php || echo /usr/local/bin/php)
DBPASS=$($PHP_BIN -r '$c = require $argv[1]; echo $c["settings"]["db"]["password"];' "$CHAT/chat/settings/settings.ini.php")
DBUSER=$($PHP_BIN -r '$c = require $argv[1]; echo $c["settings"]["db"]["user"];' "$CHAT/chat/settings/settings.ini.php")
DBNAME=$($PHP_BIN -r '$c = require $argv[1]; echo $c["settings"]["db"]["database"];' "$CHAT/chat/settings/settings.ini.php")
export MYSQL_PWD="$DBPASS"
DBARGS="-h 127.0.0.1 -P 3306 -u $DBUSER $DBNAME"

mysqldump --single-transaction --skip-lock-tables --no-create-info --replace --complete-insert $DBARGS \
  lh_departament lh_generic_bot_bot lh_generic_bot_group lh_generic_bot_trigger lh_webhook \
  lh_groupuser lh_userdep lh_canned_msg lh_canned_msg_dep lh_abstract_email_template \
  lh_group lh_role lh_grouprole lh_rolefunction \
  lh_speech_language lh_speech_language_dialect > /tmp/md-seed/lhc-config-data.sql
mysqldump --single-transaction --skip-lock-tables --no-data --skip-triggers $DBARGS \
  lh_departament lh_generic_bot_bot lh_generic_bot_group lh_generic_bot_trigger lh_webhook \
  lh_users lh_groupuser lh_userdep lh_canned_msg lh_canned_msg_dep lh_abstract_email_template \
  lh_group lh_role lh_grouprole lh_rolefunction lh_users_setting lh_users_setting_option \
  lh_speech_language lh_speech_language_dialect lh_generic_bot_rest_api lh_abstract_rest_api_key \
  stg_raplsaich_knowledge > /tmp/md-seed/lhc-schema-local-only.sql
mysqldump --single-transaction --skip-lock-tables --no-create-info --replace --complete-insert $DBARGS \
  stg_raplsaich_knowledge > /tmp/md-seed/rapls-knowledge.sql
unset MYSQL_PWD
echo DUMPS-OK
wc -c /tmp/md-seed/*.sql /tmp/md-seed/*.json | tail -8
SEED_MEMBERS=(lhc-config-data.sql lhc-users-setting-sanitized.sql lhc-schema-local-only.sql \
  lhc-users-sanitized.sql lhc-chat-config-sanitized.sql lhc-secret-tables.sql \
  rapls-knowledge.sql chat-options.json)
tar czf /tmp/md-seed.tar.gz -C /tmp/md-seed "${SEED_MEMBERS[@]}"
echo TAR-OK
