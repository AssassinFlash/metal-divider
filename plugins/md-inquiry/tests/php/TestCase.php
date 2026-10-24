<?php
/**
 * Base of this plugin's unit tests.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests;

use MetalDivider\Inquiry\Tests\Support\FakeWpdb;
use WP_Mock;
use WP_Mock\Tools\TestCase as WP_Mock_TestCase;

/**
 * WP_Mock's test case, plus the mocks every test in this plugin ends up
 * wanting: the translation and escaping pass-throughs, the email check, and
 * the queue's database double.
 */
abstract class TestCase extends WP_Mock_TestCase {

	/**
	 * The queue's database double, the last one installed.
	 *
	 * @var FakeWpdb|null
	 */
	protected static ?FakeWpdb $db = null;

	/**
	 * Installs the hand-rolled database double as the global the queue reads.
	 *
	 * @return FakeWpdb The installed double.
	 */
	protected function fake_db(): FakeWpdb {
		self::$db        = new FakeWpdb();
		$GLOBALS['wpdb'] = self::$db; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the queue reads this global; the double is what the tests give it.
		return self::$db;
	}

	/**
	 * Puts the global database double away.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		self::$db = null;
		parent::tearDown();
	}

	/**
	 * The pass-throughs: translation and escaping return what they are given,
	 * which is exactly what assertions against output want.
	 *
	 * @return void
	 */
	protected function mock_passthroughs(): void {
		foreach ( array( '__', '_x', '_n_noop', 'esc_html__', '__return_false' ) as $function ) {
			WP_Mock::passthruFunction( $function );
		}
		foreach ( array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'sanitize_title', 'sanitize_text_field', 'sanitize_textarea_field', 'sanitize_email', 'sanitize_key', 'wp_unslash' ) as $function ) {
			WP_Mock::passthruFunction( $function );
		}
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static function ( mixed $value ): string {
				return (string) \json_encode( $value );
			}
		);
	}

	/**
	 * The email check, with the filter PHP ships: the shape is_email enforces,
	 * without WordPress in the room.
	 *
	 * @return void
	 */
	protected function mock_is_email(): void {
		WP_Mock::userFunction( 'is_email' )->andReturnUsing(
			static function ( string $email ): string|false {
				return false !== \filter_var( $email, \FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
	}
}
