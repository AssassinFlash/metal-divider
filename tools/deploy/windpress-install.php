<?php
/**
 * Install a compiled WindPress cache through the plugin's own writer.
 *
 * Run as: wp eval-file windpress-install.php <path-to-css>
 *
 * Why this exists
 * ---------------
 * Tailwind v4 has no server-side build. WindPress compiles in a browser, in its
 * admin, and stores the result with `CoreCache::save_cache()`. The deploy
 * pipeline therefore prints "log into the admin → WindPress → Settings →
 * Performance → Generate" as a manual step — and on this host that step cannot
 * be reached by automation at all: the production login answers
 * "ERROR: Stop guessing!", so the compiled stylesheet has stayed at whatever it
 * was when a person last ran it by hand. Every presentation change since then
 * has been in the repository and not on the site: measured 2026-10-29, the
 * production stylesheet was missing the scoped frame rule and .md-skip-link
 * while both were committed.
 *
 * This script is not a second compiler. It installs an artifact compiled
 * elsewhere (the dev instance, same plugin version, same volume sources) and it
 * installs it through `CoreCache::save_cache()` — the function the plugin's own
 * admin calls after a compile, which runs the plugin's save hook and flushes its
 * object cache. Writing the file behind the plugin's back would skip both.
 *
 * The gate is the caller's: `md-deploy windpress-install` refuses to run unless
 * the candidate carries every class the target's own pages use. This script
 * only refuses an empty file or a missing plugin.
 *
 * @package MetalDivider\Deploy
 */

$args = $args ?? array();
$path = $args[0] ?? '';

if ( '' === $path || ! is_readable( $path ) ) {
	fwrite( STDERR, "usage: wp eval-file windpress-install.php <path-to-css>\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an artifact handed to CLI.
$css = file_get_contents( $path );
if ( false === $css || '' === trim( $css ) ) {
	fwrite( STDERR, "the candidate stylesheet is empty: {$path}\n" );
	exit( 1 );
}

$cache_class = '\WindPress\WindPress\Core\Cache';
if ( ! class_exists( $cache_class ) ) {
	fwrite( STDERR, "WindPress Core Cache is unavailable; is the plugin active?\n" );
	exit( 1 );
}

$cache_file = constant( $cache_class . '::CSS_CACHE_FILE' );
$cache_path = $cache_class::get_cache_path( $cache_file );
clearstatcache( true, $cache_path );
$before = (int) @filemtime( $cache_path );

$cache_class::save_cache( $css );

clearstatcache( true, $cache_path );
$after = (int) @filemtime( $cache_path );

echo wp_json_encode(
	array(
		'installed' => true,
		'bytes'     => strlen( $css ),
		'before'    => $before,
		'after'     => $after,
	)
) . "\n";
