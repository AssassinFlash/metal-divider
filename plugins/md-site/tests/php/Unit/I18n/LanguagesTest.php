<?php
/**
 * Tests for Languages class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests language codes, WPML mappings, RTL detection, and URL generation.
 *
 * @covers \MetalDivider\Site\I18n\Languages
 */
final class LanguagesTest extends TestCase {

	/**
	 * Tears down the fixture.
	 */
	public function tearDown(): void {
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * Verifies all 9 languages and metadata.
	 */
	public function test_all_returns_nine_languages_with_expected_metadata(): void {
		$all = Languages::all();
		$this->assertCount( 9, $all );
		$this->assertSame( Languages::ALL, array_keys( $all ) );

		$this->assertSame( 'en', $all['en']['code'] );
		$this->assertSame( 'zh-hans', $all['zh']['wpml'] );
		$this->assertSame( 'zh_CN', $all['zh']['locale'] );
		$this->assertSame( 'zh-Hans', $all['zh']['hreflang'] );
		$this->assertSame( 'pt-BR', $all['pt-br']['hreflang'] );

		// Only Arabic is RTL.
		$this->assertTrue( $all['ar']['rtl'] );
		$this->assertFalse( $all['en']['rtl'] );
		$this->assertFalse( $all['zh']['rtl'] );
		$this->assertFalse( $all['es']['rtl'] );
		$this->assertFalse( $all['pt-br']['rtl'] );
		$this->assertFalse( $all['de']['rtl'] );
		$this->assertFalse( $all['fr']['rtl'] );
		$this->assertFalse( $all['id']['rtl'] );
		$this->assertFalse( $all['vi']['rtl'] );
	}

	/**
	 * Verifies WPML code mapping roundtrip.
	 */
	public function test_to_and_from_wpml(): void {
		$this->assertSame( 'zh-hans', Languages::to_wpml( 'zh' ) );
		$this->assertSame( 'zh', Languages::from_wpml( 'zh-hans' ) );
		$this->assertSame( 'pt-br', Languages::to_wpml( 'pt-br' ) );
		$this->assertSame( 'pt-br', Languages::from_wpml( 'pt-br' ) );
		$this->assertSame( 'en', Languages::to_wpml( 'EN' ) );
		$this->assertSame( 'en', Languages::from_wpml( 'EN' ) );
	}

	/**
	 * Verifies current language defaults to en.
	 */
	public function test_current_language_default(): void {
		$this->assertSame( 'en', Languages::current() );
	}

	/**
	 * Verifies forced language override.
	 */
	public function test_current_language_forced_override(): void {
		Languages::force_for_tests( 'zh' );
		$this->assertSame( 'zh', Languages::current() );
	}

	/**
	 * Verifies WPML code alias in forced language.
	 */
	public function test_current_language_wpml_code_alias(): void {
		Languages::force_for_tests( 'zh-hans' );
		$this->assertSame( 'zh', Languages::current() );
	}

	/**
	 * Verifies invalid forced language defaults to en.
	 */
	public function test_current_language_invalid_override(): void {
		Languages::force_for_tests( 'invalid' );
		$this->assertSame( 'en', Languages::current() );
	}

	/**
	 * Verifies clearing forced language defaults to en.
	 */
	public function test_current_language_cleared_override(): void {
		Languages::force_for_tests( null );
		$this->assertSame( 'en', Languages::current() );
	}

	/**
	 * Verifies RTL checks.
	 */
	public function test_is_rtl(): void {
		$this->assertTrue( Languages::is_rtl( 'ar' ) );
		$this->assertFalse( Languages::is_rtl( 'en' ) );
		$this->assertFalse( Languages::is_rtl( 'zh' ) );

		Languages::force_for_tests( 'ar' );
		$this->assertTrue( Languages::is_rtl() );

		Languages::force_for_tests( 'en' );
		$this->assertFalse( Languages::is_rtl() );
	}

	/**
	 * Verifies localized home URL generation.
	 */
	public function test_home_url_generation(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );

		$this->assertSame( 'https://metal-divider.com/', Languages::home_url( '/', 'en' ) );
		$this->assertSame( 'https://metal-divider.com/zh/', Languages::home_url( '/', 'zh' ) );
		$this->assertSame( 'https://metal-divider.com/patterns/', Languages::home_url( '/patterns/', 'en' ) );
		$this->assertSame( 'https://metal-divider.com/zh/patterns/', Languages::home_url( '/patterns/', 'zh' ) );
		$this->assertSame( 'https://metal-divider.com/de/pattern/apiary/', Languages::home_url( '/pattern/apiary/', 'de' ) );
	}

	/**
	 * Verifies with_language switches and restores language context.
	 */
	public function test_with_language_switches_and_restores(): void {
		Languages::force_for_tests( 'en' );

		$observed = Languages::with_language(
			'zh',
			static function (): string {
				return Languages::current();
			}
		);

		$this->assertSame( 'zh', $observed );
		$this->assertSame( 'en', Languages::current() );
	}

	/**
	 * Verifies switcher items output.
	 */
	public function test_switcher_items_contains_all_nine_languages(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );
		WP_Mock::userFunction( 'is_front_page' )->andReturn( true );

		Languages::force_for_tests( 'en' );
		$items = Languages::switcher_items();

		$this->assertCount( 9, $items );
		$this->assertTrue( $items[0]['active'] );
		$this->assertSame( 'en', $items[0]['code'] );
		$this->assertSame( 'https://metal-divider.com/', $items[0]['url'] );

		$this->assertFalse( $items[1]['active'] );
		$this->assertSame( 'zh', $items[1]['code'] );
		$this->assertSame( 'https://metal-divider.com/zh/', $items[1]['url'] );
	}
}
