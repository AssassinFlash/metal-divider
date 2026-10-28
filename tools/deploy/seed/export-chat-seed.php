<?php
/**
 * Seed transformer v2: runs ON the golden host. Reads LHC settings and the
 * WordPress config host-side, emits seed SQL with every credential blanked.
 * Prints only counts and paths - never a secret value.
 */
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

function q($pdo, $s) { return $pdo->quote($s); }
function replace_row($pdo, $table, $row) {
    $cols = array_keys($row);
    $vals = array();
    foreach ($row as $v) {
        $vals[] = $v === null ? 'NULL' : q($pdo, (string)$v);
    }
    return 'REPLACE INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $vals) . ');' . "\n";
}
/* Credential runs: bearer tokens and api keys are 40-64 hex chars. Struck
 * from every value of the secret tables, wherever they are duplicated (JSON
 * configuration, serialized mirror columns). */
function strip_credentials($row) {
    foreach ($row as $k => $v) {
        if (is_string($v) && $v !== '') {
            $row[$k] = preg_replace('/[a-f0-9]{40,64}/', '', $v);
        }
    }
    return $row;
}

/* 1. lh_users with the password hash blanked. */
$users = $pdo->query('SELECT * FROM lh_users')->fetchAll(PDO::FETCH_ASSOC);
$seed_users = '';
foreach ($users as $u) {
    $seed = $u;
    $seed['password'] = '';
    $seed_users .= replace_row($pdo, 'lh_users', $seed);
}
file_put_contents("$out_dir/lhc-users-sanitized.sql", $seed_users);

/* 2. lh_chat_config with per-install secrets blanked. */
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
    $sql .= replace_row($pdo, 'lh_chat_config', $r);
}
file_put_contents("$out_dir/lhc-chat-config-sanitized.sql", $sql);

/* 3. Secret tables: rows ship, every credential run struck (the mirrored
 * serialized columns duplicate them). ip_restrictions keeps its IPs: the
 * golden host and the target share one physical host. */
$out = ''; $struck = 0;
foreach ($pdo->query('SELECT * FROM lh_generic_bot_rest_api')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $before = implode('', $r);
    $r = strip_credentials($r);
    if (implode('', $r) !== $before) { $struck++; }
    $out .= replace_row($pdo, 'lh_generic_bot_rest_api', $r);
}
foreach ($pdo->query('SELECT * FROM lh_abstract_rest_api_key')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $before = implode('', $r);
    $r = strip_credentials($r);
    if (implode('', $r) !== $before) { $struck++; }
    $out .= replace_row($pdo, 'lh_abstract_rest_api_key', $r);
}
file_put_contents("$out_dir/lhc-secret-tables.sql", $out);

/* 5. Operator desk preferences. lh_users_setting and lh_users_setting_option
 * can carry per-operator credentials (mail/IMAP passwords, API tokens), so
 * they ship ONLY through this transformer: an identifier that names a
 * credential gets its value blanked, and every value additionally loses any
 * 40-64 hex run. The plain mysqldump never touches these tables. */
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
        $r = strip_credentials($r);
        if (implode('', $r) !== $before) { $us_struck++; }
        $us_sql .= replace_row($pdo, $us_table, $r);
        $us_rows++;
    }
}
file_put_contents($out_dir . '/lhc-users-setting-sanitized.sql', $us_sql);

/* 4. Chat option rows, secret keys removed. */
$specs = array(
    'md_wecom_notify' => array('bearer_token','lhc_api_key','wecom_callback_token','wecom_callback_aes_key','wecom_secret'),
    'md_kb_endpoint' => array('bearer_token','deepseek_api_key'),
);
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
echo 'options=' . implode(',', array_keys($options)) . PHP_EOL;

function maybe_unserialize_option($pdo, $table, $name) {
    $st = $pdo->prepare('SELECT option_value FROM `' . $table . '` WHERE option_name = ?');
    $st->execute(array($name));
    $raw = $st->fetchColumn();
    if ($raw === false) { return null; }
    $v = @unserialize((string)$raw);
    return is_array($v) ? $v : null;
}
