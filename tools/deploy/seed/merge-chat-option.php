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

WP_CLI::line('OK ' . $name . ' merged=' . count($seed) . ' total=' . count($merged));
