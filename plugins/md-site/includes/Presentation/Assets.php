<?php
/**
 * The chrome's front-end scripts.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * Enqueues the site's interactive layer: Alpine (the CSP-friendly build,
 * because the security baseline's script-src carries no 'unsafe-eval'),
 * its collapse and focus plugins, and md-chrome.js — the first-party
 * runtime for the converted templates' custom elements (ticket #19 T3/T6:
 * xo-webcomponents does not enter the new site; the interactions are
 * reimplemented here).
 *
 * Load order is the contract: md-chrome registers its Alpine components
 * on `alpine:init`, the plugins register on the same event, and Alpine
 * starts last. All four are footer scripts, so the order is the enqueue
 * dependency chain and nothing blocks the document.
 */
final class Assets {

	/**
	 * The plugin version these files ship with (the cache-busting query).
	 */
	public const VERSION = '0.3.0';

	/**
	 * Adds the front-end hook.
	 */
	public function register(): void {
		\add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the four files, in dependency order.
	 */
	public function enqueue(): void {
		$base = \plugin_dir_url( dirname( __DIR__, 2 ) . '/md-site.php' ) . 'assets/js/';

		\wp_enqueue_script( 'md-chrome', $base . 'md-chrome.js', array(), self::VERSION, true );
		\wp_enqueue_script( 'md-alpine-collapse', $base . 'alpine-collapse.min.js', array(), '3.17.5', true );
		\wp_enqueue_script( 'md-alpine-focus', $base . 'alpine-focus.min.js', array(), '3.17.5', true );
		\wp_enqueue_script( 'md-alpine', $base . 'alpine-csp.min.js', array( 'md-chrome', 'md-alpine-collapse', 'md-alpine-focus' ), '3.17.5', true );
	}
}
