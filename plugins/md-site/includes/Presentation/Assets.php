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
	 * The cache-busting query for the plugin's assets: the plugin version plus
	 * a digest of the file itself.
	 *
	 * The version alone was not enough, and the failure was invisible for a
	 * while: a code-only deploy keeps the plugin header at its last release,
	 * so the URL kept `?ver=0.3.2` while the file behind it changed, and
	 * Hostinger's CDN serves `assets/*.js|css` with `max-age=604800` — seven
	 * days. Measured on production 2026-10-27: the footer dock shipped, the
	 * file on disk carried `setupFooterDock`, the served bytes were the new
	 * ones, and visitors still got the previous script from `x-hcdn-cache-status:
	 * HIT, age: 81`; injecting the freshly-fetched file into the same page made
	 * the feature work, which is what proved the stale copy rather than a code
	 * fault.
	 *
	 * A digest makes the URL change whenever the bytes do, so a deploy cannot
	 * outrun the cache at all. The plugin header stays in front so a release
	 * is still readable in the URL, and it is read from the header rather than
	 * repeated as a literal (the two had drifted once already: header 0.2.0
	 * against a hard-coded 0.3.2).
	 *
	 * @param string $relative Asset path relative to the plugin root; '' asks
	 *                         for the plugin version alone.
	 */
	public static function version( string $relative = '' ): string {
		static $version = null;
		if ( null === $version ) {
			// `get_file_data()` is a wp-admin include. A front-end request that
			// asks before it loads — and any unit test without the stub — must
			// fall back rather than fatal on a missing ABSPATH.
			if ( ! \function_exists( 'get_file_data' ) && \defined( 'ABSPATH' ) ) {
				$plugin_api = ABSPATH . 'wp-admin/includes/plugin.php';
				if ( \is_readable( $plugin_api ) ) {
					require_once $plugin_api;
				}
			}
			$header  = \function_exists( 'get_file_data' )
				? \get_file_data( dirname( __DIR__, 2 ) . '/md-site.php', array( 'Version' => 'Version' ) )
				: array();
			$version = ! empty( $header['Version'] ) ? (string) $header['Version'] : '0';
		}
		if ( '' === $relative ) {
			return $version;
		}
		$file = dirname( __DIR__, 2 ) . '/' . \ltrim( $relative, '/' );
		if ( ! \is_readable( $file ) ) {
			return $version;
		}
		$digest = @\md5_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a race with a deploy removing the file must fall back, not fatal.
		return '' === (string) $digest ? $version : $version . '.' . \substr( (string) $digest, 0, 10 );
	}

	/**
	 * Adds the front-end hook.
	 */
	public function register(): void {
		\add_action( 'wp_head', array( $this, 'preload_fonts_and_i18n_config' ), 1 );
		\add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		\add_action( 'wp_enqueue_scripts', array( $this, 'retire_plugin_assets' ), 320 );
		\add_action( 'wp_head', array( $this, 'strip_windpress_metadata' ), 1000000 );
	}

	/**
	 * Emits early woff2 font preloads for LCP headings/body and guarantees
	 * `[data-md-i18n-config]` is present in `<head>` even on chromeless faces.
	 */
	public function preload_fonts_and_i18n_config(): void {
		if ( \is_admin() ) {
			return;
		}
		$fonts_base = \plugin_dir_url( dirname( __DIR__, 2 ) . '/md-site.php' ) . 'assets/fonts/';
		foreach ( array( 'inter-var.woff2', 'playfair-display-var.woff2' ) as $font_file ) {
			printf(
				'<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>' . "\n",
				\esc_url( $fonts_base . $font_file )
			);
		}

		$current     = \MetalDivider\Site\I18n\Languages::current();
		$config      = array(
			'lang'       => $current,
			'wpmlLang'   => \MetalDivider\Site\I18n\Languages::to_wpml( $current ),
			'dir'        => \MetalDivider\Site\I18n\Languages::is_rtl( $current ) ? 'rtl' : 'ltr',
			'restPrefix' => 'en' === $current ? '' : '/' . $current,
			'shopUrl'    => \MetalDivider\Site\I18n\Languages::home_url( '/patterns/', $current ),
			'strings'    => \MetalDivider\Site\I18n\Dictionary::chrome_config( $current ),
		);
		$json_config = (string) \wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( '' !== $json_config ) {
			printf(
				'<meta name="md-i18n-config" content="%s" data-md-i18n-config="%s">' . "\n",
				\esc_attr( $current ),
				\esc_attr( $json_config )
			);
		}
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
		foreach ( array(
			'woocommerce-layout',
			'woocommerce-smallscreen',
			'woocommerce-general',
			'photoswipe',
			'photoswipe-default-skin',
			'wpml-legacy-horizontal-list-0',
			'wpml-legacy-vertical-list-0',
			'wpml-legacy-dropdown-0',
			'wpml-legacy-dropdown-click-0',
			'wpml-legacy-post-translations-0',
			'wpml-tm-styles',
			'sitepress-style',
			'wcml-dropdown-0',
		) as $style ) {
			\wp_dequeue_style( $style );
		}
		foreach ( array(
			'woocommerce',
			'wc-add-to-cart',
			'wc-jquery-blockui',
			'wc-zoom',
			'wc-flexslider',
			'wc-photoswipe',
			'wc-photoswipe-ui-default',
			'wc-single-product',
			'wc-js-cookie',
			'sourcebuster-js',
			'wc-order-attribution',
			'hostinger-reach-embed-js',
			'wpml-cookie',
			'wpml-browser-redirect',
			'sitepress-scripts',
			'wcml-front-scripts',
			'cart-widget',
		) as $script ) {
			\wp_dequeue_script( $script );
		}
		// Woo attaches the flipper on body_class, after this hook: remove it
		// again in wp_footer, before its own priority-10 print.
		\add_action(
			'wp_footer',
			static function (): void {
				\remove_action( 'wp_footer', 'wc_no_js' );
			},
			1
		);
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
	 * Enqueues the front-end scripts, in dependency order.
	 */
	public function enqueue(): void {
		$base = \plugin_dir_url( dirname( __DIR__, 2 ) . '/md-site.php' ) . 'assets/js/';

		\wp_enqueue_script( 'md-chrome', $base . 'md-chrome.js', array(), self::version( 'assets/js/md-chrome.js' ), true );
		\wp_enqueue_script( 'md-alpine-collapse', $base . 'alpine-collapse.min.js', array(), '3.17.5', true );
		\wp_enqueue_script( 'md-alpine-focus', $base . 'alpine-focus.min.js', array(), '3.17.5', true );
		\wp_enqueue_script( 'md-alpine', $base . 'alpine-csp.min.js', array( 'md-chrome', 'md-alpine-collapse', 'md-alpine-focus' ), '3.17.5', true );
		if ( ! \is_user_logged_in() ) {
			\wp_enqueue_script( 'md-clarity', $base . 'md-clarity.js', array(), self::version( 'assets/js/md-clarity.js' ), true );
			\wp_enqueue_script( 'md-ga4', $base . 'md-ga4.js', array(), self::version( 'assets/js/md-ga4.js' ), true );
		}
	}
}
