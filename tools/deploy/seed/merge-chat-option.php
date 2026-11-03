<?php
/**
 * Merge one sanitized chat option row into the target's live option.
 *
 * Invoked by md-deploy on the target host itself:
 *
 *     wp eval-file /tmp/merge-chat-option.php <option-name> <seed-json>
 *
 * The seed JSON carries only non-secret keys (the exporter strips the
 * secret ones, and the deploy side refuses a payload that still has any).
 * The live row never leaves the host: this script reads it, overlays the
 * seed keys, and writes the merged array back. update_option() returns
 * false both for an unchanged value and for a failed write, so the return
 * alone proves nothing: the script reads the option back and requires it to
 * equal the merged array, erroring out otherwise. An idempotent re-run
 * (unchanged value) still reads back equal and prints OK. A live value that
 * is not an array is replaced outright — the chat options are always
 * associative arrays, and anything else is corruption worth overwriting.
 *
 * Prints exactly one line on success:
 *
 *     OK <option-name> merged=<seed-key-count> total=<merged-key-count>
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!isset($args) || count($args) < 2) {
    WP_CLI::error('usage: wp eval-file merge-chat-option.php <option-name> <seed-json>');
}

$name = $args[0];
$seed = json_decode($args[1], true);

if (!is_string($name) || $name === '') {
    WP_CLI::error('the option name must be a non-empty string');
}
if (!is_array($seed)) {
    WP_CLI::error('the seed payload must decode to a JSON object');
}

$existing = get_option($name, array());
if (!is_array($existing)) {
    $existing = array();
}

$merged = array_merge($existing, $seed);
update_option($name, $merged);

$readback = get_option($name, array());
if ($readback !== $merged) {
    WP_CLI::error(
        'the merged value did not persist for ' . $name . '— the readback differs'
    );
}

// Keep lh_generic_bot_rest_api bearer tokens synchronized with the live WordPress
// options on the target host so LHC webhook/bot calls never drift out of sync.
$rest_sync = array(
    'md_wecom_notify' => array('id' => 1, 'key' => 'webhook_token'),
    'md_kb_endpoint'  => array('id' => 3, 'key' => 'bearer_token'),
);
if (isset($rest_sync[$name])) {
    $token = $merged[$rest_sync[$name]['key']] ?? '';
    if (is_string($token) && $token !== '') {
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && method_exists($wpdb, 'update')) {
            $rest_id = (int) $rest_sync[$name]['id'];
            $raw_cfg = $wpdb->get_var(
                $wpdb->prepare('SELECT configuration FROM lh_generic_bot_rest_api WHERE id = %d', $rest_id)
            );
            if (is_string($raw_cfg) && $raw_cfg !== '') {
                $cfg = json_decode($raw_cfg, true);
                if (is_array($cfg)) {
                    $cfg['auth_bearer'] = $token;
                    if (isset($cfg['parameters'][0]) && is_array($cfg['parameters'][0])) {
                        $cfg['parameters'][0]['auth_bearer'] = $token;
                    }
                    $wpdb->update(
                        'lh_generic_bot_rest_api',
                        array('configuration' => wp_json_encode($cfg)),
                        array('id' => $rest_id)
                    );
                }
            }
        }
    }
}

WP_CLI::line('OK ' . $name . ' merged=' . count($seed) . ' total=' . count($merged));
