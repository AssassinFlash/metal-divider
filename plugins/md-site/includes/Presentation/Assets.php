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
		\add_action( 'wp_enqueue_scripts', array( $this, 'retire_plugin_assets' ), 320 );
		\add_action( 'wp_head', array( $this, 'strip_windpress_metadata' ), 1000000 );
	}

	/**
	 * The customer face carries no WooCommerce or Hostinger Reach UI: patterns
	 * are LiveCanvas templates and the inquiry chain is md-inquiry's own. The
	 * spec's gates say one external stylesheet (CHECK-3) and a frozen script
	 * whitelist (CHECK-4); Woo's inline configuration is also what the CSP
	 * blocks, so the handles go on the front end only. Admin keeps everything.
	 */
	public function retire_plugin_assets(): void {
		if ( \is_admin() ) {
			return;
		}
		foreach ( array( 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-general', 'photoswipe', 'photoswipe-default-skin' ) as $style ) {
			\wp_dequeue_style( $style );
		}
		foreach ( array( 'woocommerce', 'wc-add-to-cart', 'wc-jquery-blockui', 'wc-zoom', 'wc-flexslider', 'wc-photoswipe', 'wc-photoswipe-ui-default', 'wc-single-product', 'wc-js-cookie', 'sourcebuster-js', 'wc-order-attribution', 'hostinger-reach-embed-js' ) as $script ) {
			\wp_dequeue_script( $script );
		}
		\remove_action( 'wp_footer', 'wc_no_js' );
	}

	/**
	 * WindPress prints a metadata script for its front-end panel; cached mode
	 * serves the precompiled CSS and loads no observer, so anonymous visitors
	 * have nothing that reads it — and the CSP blocks it. Strip exactly that
	 * script for them; administrators keep the panel.
	 */
	public function strip_windpress_metadata(): void {
		if ( \is_user_logged_in() ) {
			return;
		}
		\ob_start();
		\add_action(
			'wp_head',
			static function (): void {
				$head = (string) \ob_get_clean();
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the head buffer, already escaped by its producers.
				echo \preg_replace( '#<script id="windpress:metadata">.*?</script>#s', '', $head );
			},
			1000002
		);
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
