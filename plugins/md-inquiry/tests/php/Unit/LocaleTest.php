<?php
/**
 * Tests for the 9-language locale helper.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Locale;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * Validates canonical locale codes, normalization, WPML mapping and RTL detection.
 *
 * @covers \MetalDivider\Inquiry\Locale
 */
final class LocaleTest extends TestCase {

	/**
	 * All 9 canonical languages are declared.
	 */
	public function test_all_contains_nine_canonical_languages(): void {
		$this->assertSame(
			array( 'en', 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' ),
			Locale::ALL
		);
		$this->assertSame( 'en', Locale::DEFAULT );
	}

	/**
	 * Normalization maps various inputs to canonical codes or empty string.
	 *
	 * @dataProvider normalization_cases
	 * @param string $input    Raw locale input.
	 * @param string $expected Expected canonical code.
	 */
	public function test_normalize( string $input, string $expected ): void {
		$this->assertSame( $expected, Locale::normalize( $input ) );
		$this->assertSame( '' !== $expected, Locale::is_valid( '' !== $expected ? $expected : $input ) );
	}

	/**
	 * Data provider for test_normalize.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function normalization_cases(): array {
		return array(
			'en plain'         => array( 'en', 'en' ),
			'en_US standard'   => array( 'en_US', 'en' ),
			'en-gb hyphen'     => array( 'en-gb', 'en' ),
			'EN uppercase'     => array( 'EN', 'en' ),
			'zh plain'         => array( 'zh', 'zh' ),
			'zh-hans WPML'     => array( 'zh-hans', 'zh' ),
			'zh-hant trad'     => array( 'zh-hant', 'zh' ),
			'zh_CN wp'         => array( 'zh_CN', 'zh' ),
			'zh-cn lowercase'  => array( 'zh-cn', 'zh' ),
			'ZH-HANS caps'     => array( 'ZH-HANS', 'zh' ),
			'es plain'         => array( 'es', 'es' ),
			'es_ES wp'         => array( 'es_ES', 'es' ),
			'pt-br hyphen'     => array( 'pt-br', 'pt-br' ),
			'pt_BR underscore' => array( 'pt_BR', 'pt-br' ),
			'pt plain'         => array( 'pt', 'pt-br' ),
			'de plain'         => array( 'de', 'de' ),
			'de_DE wp'         => array( 'de_DE', 'de' ),
			'fr plain'         => array( 'fr', 'fr' ),
			'fr_FR wp'         => array( 'fr_FR', 'fr' ),
			'ar plain'         => array( 'ar', 'ar' ),
			'ar_AE wp'         => array( 'ar_AE', 'ar' ),
			'id plain'         => array( 'id', 'id' ),
			'id_ID wp'         => array( 'id_ID', 'id' ),
			'vi plain'         => array( 'vi', 'vi' ),
			'vi_VN wp'         => array( 'vi_VN', 'vi' ),
			'unknown ja'       => array( 'ja', '' ),
			'unknown ko'       => array( 'ko', '' ),
			'empty'            => array( '', '' ),
			'whitespace'       => array( '   ', '' ),
			'malicious'        => array( '<script>alert(1)</script>', '' ),
		);
	}

	/**
	 * WPML mapping transforms 'zh' to 'zh-hans' and keeps others canonical.
	 */
	public function test_to_wpml(): void {
		$this->assertSame( 'zh-hans', Locale::to_wpml( 'zh' ) );
		$this->assertSame( 'en', Locale::to_wpml( 'en' ) );
		$this->assertSame( 'es', Locale::to_wpml( 'es' ) );
		$this->assertSame( 'pt-br', Locale::to_wpml( 'pt-br' ) );
		$this->assertSame( 'de', Locale::to_wpml( 'de' ) );
		$this->assertSame( 'fr', Locale::to_wpml( 'fr' ) );
		$this->assertSame( 'ar', Locale::to_wpml( 'ar' ) );
		$this->assertSame( 'id', Locale::to_wpml( 'id' ) );
		$this->assertSame( 'vi', Locale::to_wpml( 'vi' ) );
	}

	/**
	 * WordPress locale codes mapping.
	 */
	public function test_to_wp_locale(): void {
		$this->assertSame( 'en_US', Locale::to_wp_locale( 'en' ) );
		$this->assertSame( 'zh_CN', Locale::to_wp_locale( 'zh' ) );
		$this->assertSame( 'es_ES', Locale::to_wp_locale( 'es' ) );
		$this->assertSame( 'pt_BR', Locale::to_wp_locale( 'pt-br' ) );
		$this->assertSame( 'de_DE', Locale::to_wp_locale( 'de' ) );
		$this->assertSame( 'fr_FR', Locale::to_wp_locale( 'fr' ) );
		$this->assertSame( 'ar', Locale::to_wp_locale( 'ar' ) );
		$this->assertSame( 'id_ID', Locale::to_wp_locale( 'id' ) );
		$this->assertSame( 'vi', Locale::to_wp_locale( 'vi' ) );
	}

	/**
	 * Turnstile language codes mapping ('zh' becomes 'zh-cn').
	 */
	public function test_to_turnstile(): void {
		$this->assertSame( 'zh-cn', Locale::to_turnstile( 'zh' ) );
		$this->assertSame( 'pt-br', Locale::to_turnstile( 'pt-br' ) );
		$this->assertSame( 'en', Locale::to_turnstile( 'en' ) );
		$this->assertSame( 'de', Locale::to_turnstile( 'de' ) );
		$this->assertSame( 'ar', Locale::to_turnstile( 'ar' ) );
	}

	/**
	 * RTL check returns true only for Arabic.
	 */
	public function test_is_rtl(): void {
		$this->assertTrue( Locale::is_rtl( 'ar' ) );
		$this->assertFalse( Locale::is_rtl( 'en' ) );
		$this->assertFalse( Locale::is_rtl( 'zh' ) );
		$this->assertFalse( Locale::is_rtl( 'he' ) );
	}

	/**
	 * Current locale prioritizes WPML filter, then determine_locale(), then get_locale().
	 */
	public function test_current_prefers_wpml_then_determine_locale_then_get_locale(): void {
		$this->mock_passthroughs();

		// Case 1: WPML filter returns 'zh-hans' -> normalized to 'zh'.
		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( 'zh-hans' );
		$this->assertSame( 'zh', Locale::current() );

		// Case 2: WPML returns null, determine_locale() returns 'de_DE' -> normalized to 'de'.
		WP_Mock::onFilter( 'wpml_current_language' )->withAnyArgs()->reply( null );
		self::$current_test_locale = 'de_DE';
		$this->assertSame( 'de', Locale::current() );

		// Case 3: WPML null, get_locale returns 'fr_FR' -> 'fr'.
		self::$current_test_locale = 'fr_FR';
		$this->assertSame( 'fr', Locale::current() );

		// Case 4: all empty/invalid -> fallback to 'en'.
		self::$current_test_locale = 'xx_YY';
		$this->assertSame( 'en', Locale::current() );
	}
}
