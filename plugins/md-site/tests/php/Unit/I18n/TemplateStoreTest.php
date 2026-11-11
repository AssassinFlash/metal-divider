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

	/**
	 * Verifies Arabic RTL output mirrors unilateral right arrows in text nodes only.
	 */
	public function test_arabic_rtl_arrows_mirrored_in_text_only(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$en_html = '<!-- HTML-first → keep -->'
			. '<a href="/inquiry/" data-arrow="→">Request a Quote →</a>'
			. '<p>Send project scope &rarr; receive engineering reply</p>'
			. '<span>← Drag to explore →</span>'
			. '<script>var s = "a → b";</script>';

		$ar_html = '<!-- HTML-first → keep -->'
			. '<a href="/inquiry/" data-arrow="→">اطلب عرض سعر →</a>'
			. '<p>أرسل نطاق مشروعك &rarr; نعيد ردًا هندسيًا</p>'
			. '<span>← اسحب للاستكشاف →</span>'
			. '<script>var s = "a → b";</script>';

		TemplateStore::put_template( 'home', $en_html, $ar_html, 'ar' );
		$rendered = TemplateStore::get_template( 'home', $en_html, 'ar' );

		$this->assertStringContainsString( '<!-- HTML-first → keep -->', $rendered );
		$this->assertStringContainsString( 'href="/ar/inquiry/"', $rendered );
		$this->assertStringContainsString( 'data-arrow="→"', $rendered );
		$this->assertStringContainsString( '>اطلب عرض سعر ←</a>', $rendered );
		$this->assertStringContainsString( '<p>أرسل نطاق مشروعك ← نعيد ردًا هندسيًا</p>', $rendered );
		$this->assertStringContainsString( '<span>← اسحب للاستكشاف →</span>', $rendered );
		$this->assertStringContainsString( '<script>var s = "a → b";</script>', $rendered );
	}

	/**
	 * Verifies Simplified Chinese output sanitizes banned glossary terms (订单 / 产品).
	 */
	public function test_chinese_glossary_sanitization_replaces_banned_terms(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$en_html = '<section><p>Batch orders and export orders across our product catalog.</p><img src="/a.webp" alt="10 products in your order"></section>';
		$zh_html = '<section><p>支持批量订单与出口订单，订单发出前每笔订单均会核验我们的产品与产品目录图案。</p><img src="/a.webp" alt="您的订单含 10 件产品"></section>';

		TemplateStore::put_template( 'about', $en_html, $zh_html, 'zh' );
		$rendered = TemplateStore::get_template( 'about', $en_html, 'zh' );

		$this->assertStringNotContainsString( '订单', $rendered );
		$this->assertStringNotContainsString( '产品', $rendered );
		$this->assertStringContainsString( '批量项目', $rendered );
		$this->assertStringContainsString( '出口批次', $rendered );
		$this->assertStringContainsString( '装箱发出前', $rendered );
		$this->assertStringContainsString( '每个项目', $rendered );
		$this->assertStringContainsString( '我们的屏风板材', $rendered );
		$this->assertStringContainsString( '目录图案', $rendered );
		$this->assertStringContainsString( 'alt="您的项目含 10 件屏风成品"', $rendered );
	}

	/**
	 * Verifies header partial syncs localized submenu labels into `<template xo-mega-menu-name="...">`
	 * and prefixes Mega Menu internal links.
	 */
	public function test_header_partial_syncs_mega_menu_labels_and_rewrites_template_urls(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$en_header = '<nav><a class="xo-menu-horizontal-v1__link xo-menu-horizontal-v1__link--sub" href="/laser-cut-panels/"><span class="xo-menu-horizontal-v1__label">Laser Cut Panels</span></a></nav>'
			. '<template xo-mega-menu-name="header-mega-menu-v1"><h4 class="mmv2__title"><a class="mmv2__link" href="/laser-cut-panels/">Laser Cut Panels</a></h4></template>';

		$zh_header = '<nav><a class="xo-menu-horizontal-v1__link xo-menu-horizontal-v1__link--sub" href="/laser-cut-panels/"><span class="xo-menu-horizontal-v1__label">激光切割板材</span></a></nav>'
			. '<template xo-mega-menu-name="header-mega-menu-v1"><h4 class="mmv2__title"><a class="mmv2__link" href="/laser-cut-panels/">Laser Cut Panels</a></h4></template>';

		TemplateStore::put_partial( 'header', $en_header, $zh_header, 'zh' );
		$rendered = TemplateStore::get_partial( 'header', $en_header, 'zh' );

		$this->assertStringContainsString( '<a class="mmv2__link" href="/zh/laser-cut-panels/">激光切割板材</a>', $rendered );
	}
}
