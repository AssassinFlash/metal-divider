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
}
