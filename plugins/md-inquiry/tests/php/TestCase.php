<?php
/**
 * Base of this plugin's unit tests.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests;

use MetalDivider\Inquiry\Tests\Support\FakeWpdb;
use MetalDivider\Inquiry\Tests\Support\PassthruFilter;
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
	 * The test locale for get_locale and determine_locale.
	 *
	 * @var string
	 */
	public static string $current_test_locale = 'en_US';

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
		self::$current_test_locale = 'en_US';
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
		foreach ( array( '__', '_x', '_n_noop', 'esc_html__', 'esc_attr__', '__return_false' ) as $function ) {
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
		WP_Mock::userFunction( 'get_locale' )->andReturnUsing(
			static function (): string {
				return self::$current_test_locale;
			}
		);
		WP_Mock::userFunction( 'determine_locale' )->andReturnUsing(
			static function (): string {
				return self::$current_test_locale;
			}
		);

		$ref_manager   = new \ReflectionProperty( \WP_Mock::class, 'event_manager' );
		$event_manager = $ref_manager->getValue();
		if ( $event_manager instanceof \WP_Mock\EventManager ) {
			$ref_filters                      = new \ReflectionProperty( \WP_Mock\EventManager::class, 'filters' );
			$filters                          = (array) $ref_filters->getValue( $event_manager );
			$filters['wpml_current_language'] = new PassthruFilter( 'wpml_current_language' );
			$filters['wpml_object_id']        = new PassthruFilter( 'wpml_object_id' );
			$filters['wpml_permalink']        = new PassthruFilter( 'wpml_permalink' );
			$ref_filters->setValue( $event_manager, $filters );
		}
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
