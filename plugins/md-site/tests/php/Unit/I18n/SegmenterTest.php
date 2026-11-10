<?php
/**
 * Tests for Segmenter class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Segmenter;
use MetalDivider\Site\Tests\TestCase;

/**
 * Tests segmenting text, HTML, and placeholder expansion.
 *
 * @covers \MetalDivider\Site\I18n\Segmenter
 */
final class SegmenterTest extends TestCase {

	/**
	 * Verifies plain text segmentation and whitespace preservation.
	 */
	public function test_plain_text_segmentation(): void {
		$segmenter = new Segmenter();
		$doc       = $segmenter->segment_text( "  Laser-Cut Metal Screens  \n" );

		$this->assertCount( 1, $doc['segments'] );
		$this->assertSame( 'Laser-Cut Metal Screens', $doc['segments'][0]['text'] );

		$reassembled = $segmenter->reassemble( $doc, array( '激光切割金属屏风' ) );
		$this->assertSame( "  激光切割金属屏风  \n", $reassembled );
	}

	/**
	 * Verifies HTML segmentation with placeholders and reassembly.
	 */
	public function test_html_segmentation_with_placeholders_and_reassembly(): void {
		$segmenter = new Segmenter();
		$html      = '<div class="content"><p>Welcome to <strong>Metal Divider</strong>. Browse our <a href="/patterns/">patterns</a>!</p></div>';
		$doc       = $segmenter->segment_html( $html );

		$this->assertNotEmpty( $doc['segments'] );
		$seg = $doc['segments'][0];

		// Strong and a tags become <t1>...</t1> placeholders.
		$this->assertStringContainsString( '<t1>', $seg['text'] );
		$this->assertStringContainsString( '</t1>', $seg['text'] );
		$this->assertStringContainsString( '<t2>', $seg['text'] );
		$this->assertStringContainsString( '</t2>', $seg['text'] );

		// Translate while moving/preserving placeholders.
		$translated  = '欢迎来到 <t1>Metal Divider</t1>。浏览我们的 <t2>图案</t2>！';
		$reassembled = $segmenter->reassemble( $doc, array( $translated ) );

		$this->assertStringContainsString( '<strong>Metal Divider</strong>', $reassembled );
		$this->assertStringContainsString( '<a href="/patterns/">图案</a>', $reassembled );
		$this->assertStringStartsWith( '<div class="content"><p>', $reassembled );
	}

	/**
	 * Verifies shortcodes and variables masked as k placeholders.
	 */
	public function test_shortcodes_and_variables_masked_as_k_placeholders(): void {
		$segmenter = new Segmenter();
		$html      = '<p>Contact info@metal-divider.com or view %%sitetitle%% [md_pdp_material].</p>';
		$doc       = $segmenter->segment_html( $html );

		$this->assertNotEmpty( $doc['segments'] );
		$seg = $doc['segments'][0];

		// Check <kN/> placeholders.
		$this->assertMatchesRegularExpression( '/<k\d+\/>/', $seg['text'] );
		$this->assertStringNotContainsString( 'info@metal-divider.com', $seg['text'] );
		$this->assertStringNotContainsString( '%%sitetitle%%', $seg['text'] );
		$this->assertStringNotContainsString( '[md_pdp_material]', $seg['text'] );

		$reassembled = $segmenter->reassemble( $doc, array( $seg['text'] ) );
		$this->assertSame( $html, $reassembled );
	}

	/**
	 * Verifies shortcodes with quoted attributes keep literal quotes after reassembly.
	 */
	public function test_shortcodes_with_quoted_attributes_preserve_unescaped_quotes(): void {
		$segmenter = new Segmenter();
		$html      = '<div class="md-footer__bar">[md_lang_switcher context="footer"]</div>'
			. '<div class="md-quote-modal__card"><p>Tell us the pattern.</p>[md_inquiry_form source_module="quote-modal"]</div>';
		$doc       = $segmenter->segment_html( $html );

		$translations = array();
		foreach ( $doc['segments'] as $idx => $seg ) {
			$translations[ $idx ] = str_replace( 'Tell us the pattern.', '请告知图案。', $seg['text'] );
		}

		$reassembled = $segmenter->reassemble( $doc, $translations );
		$this->assertStringContainsString( '[md_lang_switcher context="footer"]', $reassembled );
		$this->assertStringContainsString( '[md_inquiry_form source_module="quote-modal"]', $reassembled );
		$this->assertStringNotContainsString( '&quot;', $reassembled );
	}
}
