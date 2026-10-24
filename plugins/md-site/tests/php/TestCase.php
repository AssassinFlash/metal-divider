<?php
/**
 * Base of this plugin's unit tests.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests;

use WP_Mock;

/**
 * WP_Mock's test case with the shortcuts for the WordPress functions most
 * classes of this plugin call: translation and escaping return their
 * argument unchanged.
 */
abstract class TestCase extends WP_Mock\Tools\TestCase {

	/**
	 * Lets translation and escaping functions return (or print) their first argument.
	 */
	protected function plain_text(): void {
		foreach ( array( '__', '_x', 'esc_html__', 'esc_attr__', 'esc_html_x', 'esc_html', 'esc_attr', 'esc_url', 'esc_url_raw', 'wp_kses_post', 'wp_kses' ) as $function ) {
			WP_Mock::passthruFunction( $function );
		}
	}
}
