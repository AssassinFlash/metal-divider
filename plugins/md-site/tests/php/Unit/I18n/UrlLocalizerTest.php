<?php
/**
 * Tests for UrlLocalizer class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\UrlLocalizer;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests link rewriting in HTML markup and URL attributes.
 *
 * @covers \MetalDivider\Site\I18n\UrlLocalizer
 */
final class UrlLocalizerTest extends TestCase {

	/**
	 * Verifies rewrite_url skips English, external, and static URLs.
	 */
	public function test_rewrite_url_skips_en_and_external_and_static(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );

		// English untouched.
		$this->assertSame( '/patterns/', UrlLocalizer::rewrite_url( '/patterns/', 'en' ) );

		// Non-English gets prefixed.
		$this->assertSame( '/zh/patterns/', UrlLocalizer::rewrite_url( '/patterns/', 'zh' ) );
		$this->assertSame( '/de/pattern/apiary/', UrlLocalizer::rewrite_url( '/pattern/apiary/', 'de' ) );
		$this->assertSame( '/zh/', UrlLocalizer::rewrite_url( '/', 'zh' ) );

		// Preserves query and hash.
		$this->assertSame( '/zh/patterns/?sort_by=name#grid', UrlLocalizer::rewrite_url( '/patterns/?sort_by=name#grid', 'zh' ) );

		// Skips external, mailto, tel, anchors.
		$this->assertSame( 'https://example.com/foo', UrlLocalizer::rewrite_url( 'https://example.com/foo', 'zh' ) );
		$this->assertSame( 'mailto:info@metal-divider.com', UrlLocalizer::rewrite_url( 'mailto:info@metal-divider.com', 'zh' ) );
		$this->assertSame( 'tel:+85264731687', UrlLocalizer::rewrite_url( 'tel:+85264731687', 'zh' ) );
		$this->assertSame( '#contact-form', UrlLocalizer::rewrite_url( '#contact-form', 'zh' ) );

		// Skips static and system URLs.
		$this->assertSame( '/wp-content/uploads/2026/img.webp', UrlLocalizer::rewrite_url( '/wp-content/uploads/2026/img.webp', 'zh' ) );
		$this->assertSame( '/wp-admin/admin.php', UrlLocalizer::rewrite_url( '/wp-admin/admin.php', 'zh' ) );
		$this->assertSame( '/wp-json/wp/v2/posts', UrlLocalizer::rewrite_url( '/wp-json/wp/v2/posts', 'zh' ) );

		// Skips already localized URLs.
		$this->assertSame( '/zh/patterns/', UrlLocalizer::rewrite_url( '/zh/patterns/', 'zh' ) );
		$this->assertSame( '/de/patterns/', UrlLocalizer::rewrite_url( '/de/patterns/', 'zh' ) );
	}

	/**
	 * Verifies rewrite_html replaces relevant attributes.
	 */
	public function test_rewrite_html_replaces_attributes(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );

		$html = '<a href="/patterns/" data-psd-url="/inquiry/">Explore</a>'
			. '<form action="/inquiry/" method="post">'
			. '<a href="/wp-content/uploads/doc.pdf">Download</a>'
			. '</form>';

		$rewritten = UrlLocalizer::rewrite_html( $html, 'zh' );

		$this->assertStringContainsString( 'href="/zh/patterns/"', $rewritten );
		$this->assertStringContainsString( 'data-psd-url="/zh/inquiry/"', $rewritten );
		$this->assertStringContainsString( 'action="/zh/inquiry/"', $rewritten );
		$this->assertStringContainsString( 'href="/wp-content/uploads/doc.pdf"', $rewritten );
	}

	/**
	 * Verifies rewrite_html skips language-switcher hreflang links and preserves ports.
	 */
	public function test_rewrite_html_preserves_hreflang_links_and_ports(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'http://127.0.0.1:9890' );

		$html = '<nav class="md-lang-switcher">'
			. '<a class="md-lang-switcher__link" href="http://127.0.0.1:9890/" hreflang="en" lang="en" dir="ltr">English</a>'
			. '<a class="md-lang-switcher__link" href="http://127.0.0.1:9890/pattern/agave/" hreflang="en" lang="en" dir="ltr">English PDP</a>'
			. '</nav>'
			. '<a href="http://127.0.0.1:9890/patterns/">All patterns</a>';

		$rewritten = UrlLocalizer::rewrite_html( $html, 'zh' );

		$this->assertStringContainsString( 'href="http://127.0.0.1:9890/" hreflang="en"', $rewritten );
		$this->assertStringContainsString( 'href="http://127.0.0.1:9890/pattern/agave/" hreflang="en"', $rewritten );
		$this->assertStringContainsString( 'href="http://127.0.0.1:9890/zh/patterns/"', $rewritten );
	}

	/**
	 * Verifies rewrite_html prefixes all internal links inside `<template xo-mega-menu-name="...">`
	 * and `<template id="md-search-hit">` while keeping static media paths untouched.
	 */
	public function test_rewrite_html_localizes_links_inside_template_mega_menu_and_search_hit(): void {
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );

		$html = '<template xo-mega-menu-name="header-mega-menu-v1" xo-mega-menu-index="0">'
			. '<div class="mmv2__wrap">'
			. '<p class="mmv2__hub"><a class="mmv2__hub-link" href="/stainless-steel-screens/">Stainless steel screens</a></p>'
			. '<div class="mmv2__item">'
			. '<a class="mmv2__img" href="/laser-cut-geometric-metal-screens/">'
			. '<img is="xo-lazyload" xo-src="/wp-content/uploads/2026/10/ext-gold-laser-cut-screen-collage-480w.webp" alt="Screen">'
			. '</a>'
			. '<h4 class="mmv2__title"><a class="mmv2__link" href="/laser-cut-geometric-metal-screens/">Laser-Cut &amp; Geometric Metal Screens</a></h4>'
			. '</div>'
			. '</div>'
			. '</template>'
			. '<template id="md-search-hit"><li class="md-search-hit"><a class="md-search-hit__link" href="/patterns/"></a></li></template>';

		$zh_html = UrlLocalizer::rewrite_html( $html, 'zh' );

		$this->assertStringContainsString( 'href="/zh/stainless-steel-screens/"', $zh_html );
		$this->assertStringContainsString( 'href="/zh/laser-cut-geometric-metal-screens/"', $zh_html );
		$this->assertStringContainsString( 'href="/zh/patterns/"', $zh_html );
		$this->assertStringContainsString( 'xo-src="/wp-content/uploads/2026/10/ext-gold-laser-cut-screen-collage-480w.webp"', $zh_html );
		$this->assertSame( $html, UrlLocalizer::rewrite_html( $html, 'en' ) );
	}
}
