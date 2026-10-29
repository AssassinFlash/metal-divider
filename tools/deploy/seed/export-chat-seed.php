<?php
/**
 * Seed transformer v3: runs ON the golden host. Reads LHC settings and the
 * WordPress config host-side, emits seed SQL with every credential blanked.
 * Prints only counts and paths - never a secret value.
 *
 * v3 gate: the hex-run strike of v2 assumed every credential is 40-64 hex
 * characters. The golden host's own options disprove the assumption (at
 * least one callback token and one upstream api key on it are not hex at
 * all), so v3 first COLLECTS the real secret values host-side - the option
 * specs' keys at any depth, the rest-api key table's api_key column, the
 * bot configuration's auth_bearer/authorization JSON keys - and strikes
 * those exact values wherever a seed cell carries them, with the hex run
 * kept as the fallback for credentials this host no longer knows.
 *
 * The pipeline only runs when the file is executed directly (the way
 * chat-seed runs it: `php /tmp/export-chat-seed.php`); requiring the file
 * - a transformer test - defines the functions and runs nothing.
 */

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    md_seed_export_main();
}

function md_seed_export_main() {
    $chat_root = '/home/u388076051/domains/staging.metal-divider.com/public_html';
    $cfg = require $chat_root . '/chat/settings/settings.ini.php';
    $db = $cfg['settings']['db'];
    preg_match('/^\$table_prefix\s*=\s*[\'"]([^\'"]+)/m', file_get_contents($chat_root . '/wp-config.php'), $pm);
    $prefix = $pm[1];
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $db['database'] . ';port=' . $db['port'],
        $db['user'], $db['password'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    $out_dir = '/tmp/md-seed';
    @mkdir($out_dir, 0700, true);

    /* 0. Collect the real secret values host-side (never printed): the
     * option specs' keys at any depth, the rest-api key table's api_key
     * column, and the bot configuration's auth_bearer/authorization JSON
     * keys. A collection floor of 16 characters keeps common words out of
     * the strike set - a shorter "secret" is not one. */
    $specs = array(
        'md_wecom_notify' => array('bearer_token','lhc_api_key','wecom_callback_token','wecom_callback_aes_key','wecom_secret'),
        'md_kb_endpoint' => array('bearer_token','deepseek_api_key'),
    );
    $secret_values = array();
    foreach ($specs as $name => $keys) {
        $v = maybe_unserialize_option($pdo, $prefix . 'options', $name);
        if (is_array($v)) {
            $secret_values = array_merge($secret_values, md_seed_collect_key_values($v, $keys));
        }
    }
    $api_key_rows = $pdo->query('SELECT api_key FROM lh_abstract_rest_api_key')->fetchAll(PDO::FETCH_COLUMN);
    $secret_values = array_merge($secret_values, md_seed_collect_strings($api_key_rows));
    $bot_rows = $pdo->query('SELECT configuration FROM lh_generic_bot_rest_api')->fetchAll(PDO::FETCH_COLUMN);
    $secret_values = array_merge($secret_values, md_seed_collect_json_key_values($bot_rows, array('auth_bearer', 'authorization')));
    $secret_values = array_values(array_unique(array_filter($secret_values)));

    /* 1. lh_users with the password hash blanked. */
    $users = $pdo->query('SELECT * FROM lh_users')->fetchAll(PDO::FETCH_ASSOC);
    $seed_users = '';
    foreach ($users as $u) {
        $seed = $u;
        $seed['password'] = '';
        $seed_users .= replace_row($pdo, 'lh_users', $seed);
    }
    file_put_contents("$out_dir/lhc-users-sanitized.sql", $seed_users);

    /* 2. lh_chat_config with per-install secrets blanked. Beyond the field
     * rules, every collected secret value is struck from the raw value -
     * a config row that embeds one of the collected credentials must not
     * ship it, even where that leaves a serialized length prefix stale:
     * the relay re-writes the real values on the target host anyway. */
    $rows = $pdo->query('SELECT * FROM lh_chat_config')->fetchAll(PDO::FETCH_ASSOC);
    $sql = ''; $blanked_fields = array();
    foreach ($rows as $r) {
        $ident = $r['identifier'];
        if (in_array($ident, array('export_hash'), true)) {
            if ($r['value'] !== '' && $r['value'] !== null) { $blanked_fields[] = $ident; }
            $r['value'] = '';
        } elseif (in_array($ident, array('autologin_data', 'mobile_options'), true)) {
            $decoded = @unserialize((string)$r['value']);
            if (is_array($decoded)) {
                if ($ident === 'autologin_data' && isset($decoded['secret_hash'])) { $decoded['secret_hash'] = ''; $blanked_fields[] = $ident . '.secret_hash'; }
                if ($ident === 'mobile_options' && isset($decoded['fcm_key'])) { $decoded['fcm_key'] = ''; $blanked_fields[] = $ident . '.fcm_key'; }
                $r['value'] = serialize($decoded);
            }
        }
        $r = md_seed_strip_credentials($r, $secret_values, false);
        $sql .= replace_row($pdo, 'lh_chat_config', $r);
    }
    file_put_contents("$out_dir/lhc-chat-config-sanitized.sql", $sql);

    /* 3. Secret tables: rows ship, every credential run struck (the mirrored
     * serialized columns duplicate them). ip_restrictions keeps its IPs: the
     * golden host and the target share one physical host. */
    $out = ''; $struck = 0;
    foreach ($pdo->query('SELECT * FROM lh_generic_bot_rest_api')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $before = implode('', $r);
        $r = md_seed_strip_credentials($r, $secret_values);
        if (implode('', $r) !== $before) { $struck++; }
        $out .= replace_row($pdo, 'lh_generic_bot_rest_api', $r);
    }
    foreach ($pdo->query('SELECT * FROM lh_abstract_rest_api_key')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $before = implode('', $r);
        $r = md_seed_strip_credentials($r, $secret_values);
        if (implode('', $r) !== $before) { $struck++; }
        $out .= replace_row($pdo, 'lh_abstract_rest_api_key', $r);
    }
    file_put_contents("$out_dir/lhc-secret-tables.sql", $out);

    /* 5. Operator desk preferences. lh_users_setting and lh_users_setting_option
     * can carry per-operator credentials (mail/IMAP passwords, API tokens), so
     * they ship ONLY through this transformer: an identifier that names a
     * credential gets its value blanked, and every value additionally loses
     * every collected secret and any 40-64 hex run. The plain mysqldump
     * never touches these tables. */
    $us_sql = ''; $us_rows = 0; $us_struck = 0;
    $cred_ident = '/password|secret|token|key|auth|mail|imap|smtp|oauth/i';
    foreach (array('lh_users_setting', 'lh_users_setting_option') as $us_table) {
        foreach ($pdo->query('SELECT * FROM `' . $us_table . '`')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $before = implode('', $r);
            if (isset($r['identifier'], $r['value'])
                && preg_match($cred_ident, (string)$r['identifier'])
                && $r['value'] !== '' && $r['value'] !== null) {
                $r['value'] = '';
            }
            $r = md_seed_strip_credentials($r, $secret_values);
            if (implode('', $r) !== $before) { $us_struck++; }
            $us_sql .= replace_row($pdo, $us_table, $r);
            $us_rows++;
        }
    }
    file_put_contents($out_dir . '/lhc-users-setting-sanitized.sql', $us_sql);

    /* 4. Chat option rows, secret keys removed. */
    $options = array();
    $strip_secrets = function ($value, $secrets) use (&$strip_secrets) {
        if (!is_array($value)) { return $value; }
        foreach ($secrets as $k) { unset($value[$k]); }
        foreach ($value as $k => $x) { $value[$k] = $strip_secrets($x, $secrets); }
        return $value;
    };
    foreach ($specs as $name => $secrets) {
        $v = maybe_unserialize_option($pdo, $prefix . 'options', $name);
        if ($v === null) { continue; }
        $v = $strip_secrets($v, $secrets);
        $options[$name] = $v;
    }
    file_put_contents("$out_dir/chat-options.json", json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    echo 'prefix=' . $prefix . PHP_EOL;
    echo 'users=' . count($users) . PHP_EOL;
    echo 'chat_config_rows=' . count($rows) . PHP_EOL;
    echo 'blanked=' . implode(',', $blanked_fields) . PHP_EOL;
    echo 'secret_rows_struck=' . $struck . PHP_EOL;
    echo 'users_setting_rows=' . $us_rows . PHP_EOL;
    echo 'users_setting_struck=' . $us_struck . PHP_EOL;
    echo 'secrets_collected=' . count($secret_values) . PHP_EOL;
    echo 'options=' . implode(',', array_keys($options)) . PHP_EOL;
    /* The golden LHC release (erLhcoreClassUpdate::LHC_RELEASE in
     * chat/lib/core/lhcore/lhupdate.php) — the version pin the seed
     * manifest records, so a later push can warn on drift. '-' when the
     * file or the constant cannot be read. */
    $lhc = md_seed_lhc_version($chat_root . '/chat/lib/core/lhcore/lhupdate.php');
    echo 'lhc_version=' . ($lhc === null ? '-' : $lhc) . PHP_EOL;
}

/* The LHC release constant from the golden tree's lhupdate.php, or null
 * when the file or the constant is missing. The release is the raw int
 * LHC ships (497 → "4.97v"); nothing here touches the database. */
function md_seed_lhc_version($lhupdate_php) {
    $source = @file_get_contents($lhupdate_php);
    if ($source === false) { return null; }
    if (!preg_match('/const\s+LHC_RELEASE\s*=\s*(\d+)/', $source, $m)) { return null; }
    return $m[1];
}

function q($pdo, $s) { return $pdo->quote($s); }
function replace_row($pdo, $table, $row) {
    $cols = array_keys($row);
    $vals = array();
    foreach ($row as $v) {
        $vals[] = $v === null ? 'NULL' : q($pdo, (string)$v);
    }
    return 'REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ');' . "\n";
}

/* The shortest value the strike set will carry: long enough that no common
 * word or mode name can land in it, short enough for any real credential. */
function md_seed_min_secret_length() { return 16; }

/* Collect the string values at the named keys, at any depth of an option's
 * unserialized structure. Only strings at or above the floor are returned:
 * the strike set must never carry a value short enough to appear in living
 * data for innocent reasons. */
function md_seed_collect_key_values($node, $keys) {
    $found = array();
    if (!is_array($node)) { return $found; }
    foreach ($keys as $k) {
        if (isset($node[$k]) && is_string($node[$k])
            && strlen($node[$k]) >= md_seed_min_secret_length()) {
            $found[] = $node[$k];
        }
    }
    foreach ($node as $v) {
        if (is_array($v)) { $found = array_merge($found, md_seed_collect_key_values($v, $keys)); }
    }
    return $found;
}

/* Collect the column's string values, at or above the floor. */
function md_seed_collect_strings($values) {
    $found = array();
    foreach ($values as $v) {
        if (is_string($v) && $v !== '' && strlen($v) >= md_seed_min_secret_length()) {
            $found[] = $v;
        }
    }
    return $found;
}

/* Collect the string values at the named keys of a JSON document, at any
 * depth - the bot configuration carries its bearer inside a parameters
 * array. Undecodable strings simply contribute nothing. */
function md_seed_collect_json_key_values($strings, $keys) {
    $found = array();
    foreach ($strings as $s) {
        if (!is_string($s) || $s === '') { continue; }
        $decoded = json_decode($s, true);
        if (is_array($decoded)) { $found = array_merge($found, md_seed_collect_key_values($decoded, $keys)); }
    }
    return $found;
}

/* Strike the credentials from one seed row: every collected secret value
 * first (exact replacement, wherever the value was duplicated), then the
 * 40-64 hex run as the fallback for credentials this host no longer knows.
 * Non-string cells pass through untouched. */
function md_seed_strip_credentials($row, $secret_values, $hex = true) {
    foreach ($row as $k => $v) {
        if (is_string($v) && $v !== '') {
            if ($secret_values) { $v = str_replace($secret_values, '', $v); }
            if ($hex) { $v = preg_replace('/[a-f0-9]{40,64}/', '', $v); }
            $row[$k] = $v;
        }
    }
    return $row;
}

function maybe_unserialize_option($pdo, $table, $name) {
    $st = $pdo->prepare('SELECT option_value FROM `' . $table . '` WHERE option_name = ?');
    $st->execute(array($name));
    $raw = $st->fetchColumn();
    if ($raw === false) { return null; }
    $v = @unserialize((string)$raw);
    return is_array($v) ? $v : null;
}
