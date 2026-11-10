<?php
/**
 * Tests for Setup class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Setup;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests Setup drift detection and apply steps.
 *
 * @covers \MetalDivider\Site\I18n\Setup
 */
final class SetupTest extends TestCase {

	/**
	 * Verifies check reports drift when settings are mismatched.
	 */
	public function test_check_reports_drift_when_settings_mismatched(): void {
		WP_Mock::userFunction( 'icl_get_setting' )->andReturnUsing(
			static function ( string $key ) {
				return match ( $key ) {
					'setup_complete'               => 0, // Drift! Expected 1.
					'default_language'             => '', // Drift! Expected en.
					'language_negotiation_type'    => 3, // Drift! Expected 1.
					'store_frontend_cookie'        => 1, // Drift! Expected 0.
					'browser_lang_redirect'        => 1, // Drift! Expected 0.
					'translated_document_page_url' => 'Translate', // Drift! Expected Copy.
					'seo'                          => array( 'head_langs' => 0 ), // Drift! Expected 1.
					default                        => null,
				};
			}
		);

		$check = Setup::check();

		$this->assertFalse( $check['ok'] );
		$this->assertCount( 7, $check['issues'] );
	}

	/**
	 * Verifies check passes when settings match desired state.
	 */
	public function test_check_passes_when_settings_match_desired_state(): void {
		WP_Mock::userFunction( 'icl_get_setting' )->andReturnUsing(
			static function ( string $key ) {
				return match ( $key ) {
					'setup_complete'               => 1,
					'default_language'             => 'en',
					'language_negotiation_type'    => 1,
					'store_frontend_cookie'        => 0,
					'browser_lang_redirect'        => 0,
					'translated_document_page_url' => 'Copy',
					'seo'                          => array( 'head_langs' => 1 ),
					default                        => null,
				};
			}
		);

		$check = Setup::check();

		$this->assertTrue( $check['ok'] );
		$this->assertSame( array(), $check['issues'] );
	}
}
