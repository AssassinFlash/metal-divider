<?php
/**
 * Tests for HtmlTokenizer class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\HtmlTokenizer;
use MetalDivider\Site\Tests\TestCase;

/**
 * Tests lossless HTML tokenization and attribute replacement.
 *
 * @covers \MetalDivider\Site\I18n\HtmlTokenizer
 */
final class HtmlTokenizerTest extends TestCase {

	/**
	 * Verifies lossless roundtrip tokenization.
	 */
	public function test_lossless_roundtrip(): void {
		$html = '<div id="test" class="foo bar" x-data="{ open: false }">'
			. '<!-- A comment -->'
			. '<h1>Title &amp; Subtitle</h1>'
			. '<script>var x = 1 < 2;</script>'
			. '<style>body { color: red; }</style>'
			. '<svg><path d="M0 0h10v10H0z"/></svg>'
			. '<p>Text with <a href="/patterns/" target="_blank">link</a> and <img src="img.jpg" alt="test" />.</p>'
			. '</div>';

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );

		$assembled = '';
		foreach ( $tokens as $t ) {
			$assembled .= $t['raw'];
		}

		$this->assertSame( $html, $assembled );
	}

	/**
	 * Verifies attribute extraction and replacement.
	 */
	public function test_attribute_extraction_and_replacement(): void {
		$tag = '<img src="/img.jpg" alt="Original &amp; clean" class="hero" />';

		$this->assertSame( 'Original & clean', HtmlTokenizer::attribute( $tag, 'alt' ) );
		$this->assertSame( '/img.jpg', HtmlTokenizer::attribute( $tag, 'src' ) );
		$this->assertNull( HtmlTokenizer::attribute( $tag, 'title' ) );

		$replaced = HtmlTokenizer::with_attribute( $tag, 'alt', 'New "Quoted" & Clean' );
		$this->assertStringContainsString( 'alt="New &quot;Quoted&quot; &amp; Clean"', $replaced );
		$this->assertStringContainsString( 'src="/img.jpg"', $replaced );
	}

	/**
	 * Verifies `<template>` blocks (desktop Mega Menu and predictive search hit) are
	 * tokenized into inner tags and text nodes rather than swallowed as opaque raw text.
	 */
	public function test_template_mega_menu_and_search_hit_tokenized_losslessly(): void {
		$html = '<template xo-mega-menu-name="header-mega-menu-v1" xo-mega-menu-index="0">'
			. '<div class="mmv2"><p class="mmv2__hub"><a class="mmv2__hub-link" href="/stainless-steel-screens/">Stainless steel screens</a></p>'
			. '<h4 class="mmv2__title"><a class="mmv2__link" href="/laser-cut-panels/">Laser Cut Panels</a></h4></div>'
			. '</template>'
			. '<template id="md-search-hit"><li class="md-search-hit"><a class="md-search-hit__link" href="/patterns/"><span class="md-search-hit__name"></span></a></li></template>';

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );

		$assembled = '';
		$tag_names = array();
		$texts     = array();
		foreach ( $tokens as $token ) {
			$assembled .= $token['raw'];
			if ( HtmlTokenizer::TYPE_TAG === $token['type'] && ! $token['closing'] ) {
				$tag_names[] = $token['name'];
			}
			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] && '' !== trim( $token['raw'] ) ) {
				$texts[] = trim( $token['raw'] );
			}
		}

		$this->assertSame( $html, $assembled );
		$this->assertContains( 'template', $tag_names );
		$this->assertContains( 'a', $tag_names );
		$this->assertContains( 'li', $tag_names );
		$this->assertContains( 'Stainless steel screens', $texts );
		$this->assertContains( 'Laser Cut Panels', $texts );
	}
}
