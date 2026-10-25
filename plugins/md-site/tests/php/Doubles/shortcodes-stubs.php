<?php
/**
 * Static-analysis declaration for the shortcode engine double.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- a third-party symbol declaration for static analysis; the
// WordPress prefix and naming sniffs do not apply to WordPress's own names.

/*
 * `tests/php/Doubles/shortcodes.php` supplies WordPress's shortcode engine to
 * the unit suite at runtime, and PHPStan excludes `tests/php/Doubles` from
 * analysis (the runtime doubles shadow real WordPress classes). The reset
 * helper is this project's own addition rather than a WordPress function, so
 * it has no stub to be found in and is declared here instead. Only the
 * helpers the tests call directly are declared: everything else the tests
 * reach is WordPress's, and the WordPress stubs already carry it.
 */

if ( ! function_exists( 'shortcode_tags_reset' ) ) {
	/**
	 * Empties the shortcode registry between tests.
	 *
	 * @return void
	 */
	function shortcode_tags_reset() {}
}
