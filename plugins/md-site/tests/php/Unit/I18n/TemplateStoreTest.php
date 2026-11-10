<?php
/**
 * Tests for TemplateStore class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\I18n\TemplateStore;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests template/partial caching, hashing, and retrieval.
 *
 * @covers \MetalDivider\Site\I18n\TemplateStore
 */
final class TemplateStoreTest extends TestCase {

	/**
	 * Sets up the fixture.
	 */
	public function setUp(): void {
		parent::setUp();
		TemplateStore::forget();
		Languages::force_for_tests( null );
	}

	/**
	 * Tears down the fixture.
	 */
	public function tearDown(): void {
		TemplateStore::forget();
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * Verifies English source returns fallback untouched.
	 */
	public function test_english_returns_fallback_untouched(): void {
		$html = '<div>English Template</div>';
		$res  = TemplateStore::get_template( 'home', $html, 'en' );
		$this->assertSame( $html, $res );

		$res_part = TemplateStore::get_partial( 'header', $html, 'en' );
		$this->assertSame( $html, $res_part );
	}

	/**
	 * Verifies put and get template with SHA-256 hash verification.
	 */
	public function test_put_and_get_template_with_sha256_hash_verification(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$en_html = '<div>Explore <a href="/patterns/">patterns</a></div>';
		$zh_html = '<div>浏览 <a href="/patterns/">图案</a></div>';

		TemplateStore::put_template( 'home', $en_html, $zh_html, 'zh' );

		// Exact source hash match -> localized template with rewritten URLs.
		$res = TemplateStore::get_template( 'home', $en_html, 'zh' );
		$this->assertSame( '<div>浏览 <a href="/zh/patterns/">图案</a></div>', $res );

		// Modified source HTML (drift) -> falls back to modified source with rewritten URLs.
		$modified_en = '<div>Explore new <a href="/patterns/">patterns</a></div>';
		$res_drift   = TemplateStore::get_template( 'home', $modified_en, 'zh' );
		$this->assertSame( '<div>Explore new <a href="/zh/patterns/">patterns</a></div>', $res_drift );
	}
}
