<?php
/**
 * Tests for the FAQPage schema's single-source extraction.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\FaqSchema;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for FaqSchema: the Q&A pairs it pulls from the faqs face's
 * markup, and the JSON-LD it builds from them — the schema can never
 * carry a question the page does not show.
 *
 * @covers \MetalDivider\Site\Seo\FaqSchema
 */
final class FaqSchemaTest extends TestCase {

	/**
	 * A two-question fragment of the face's real markup: the toggle
	 * ids, the question spans, the answer divs (one multi-paragraph,
	 * one with an entity).
	 */
	private const HTML = <<<'HTML'
		<div><article>
			<button type="button" x-on:click="toggle('faq_01')" aria-controls="faq-download-answer-x-faq_01">
				<span class="max-w-[860px] text-lg font-semibold leading-[1.35] text-white md:text-xl">How do I get a quote?</span>
			</button>
			<div id="faq-download-answer-x-faq_01" x-show="openFaq === 'faq_01'"><p>Send the application, approximate dimensions.</p><p>We reply with pricing.</p></div>
		</article><article>
			<button type="button" x-on:click="toggle('faq_02')" aria-controls="faq-download-answer-x-faq_02">
				<span class="max-w-[860px] text-lg font-semibold leading-[1.35] text-white md:text-xl">Pricing &amp; MOQ?</span>
			</button>
			<div id="faq-download-answer-x-faq_02" x-show="openFaq === 'faq_02'"><p>Quotes are per project.</p></div>
		</article></div>
		HTML;

	/**
	 * Sets up the WordPress text helpers the extraction calls.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::userFunction( 'wp_strip_all_tags' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( string $text ) => trim( strip_tags( $text ) )
		);
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		WP_Mock::userFunction( 'untrailingslashit' )->andReturnUsing( static fn( string $value ) => rtrim( $value, '/' ) );
	}

	/**
	 * The pairs come out in template order, tags stripped, entities
	 * decoded, block joins collapsed to single spaces.
	 */
	public function test_pairs_extract_in_order_as_text(): void {
		$pairs = FaqSchema::pairs( self::HTML );
		$this->assertCount( 2, $pairs );
		$this->assertSame( 'How do I get a quote?', $pairs[0]['question'] );
		$this->assertSame( 'Send the application, approximate dimensions. We reply with pricing.', $pairs[0]['answer'] );
		$this->assertSame( 'Pricing & MOQ?', $pairs[1]['question'] );
		$this->assertSame( 'Quotes are per project.', $pairs[1]['answer'] );
	}

	/**
	 * Markup without the toggle ids yields nothing — a face that
	 * changed shape is an empty schema, never a guessed one.
	 */
	public function test_pairs_empty_without_the_component(): void {
		$this->assertSame( array(), FaqSchema::pairs( '<div>nothing here</div>' ) );
	}

	/**
	 * The JSON-LD is a complete script element: the FAQPage type, the
	 *
	 * @id on the faqs page, and one Question per pair with its
	 * acceptedAnswer.
	 */
	public function test_json_ld_is_the_faqpage_script_element(): void {
		$json_ld = FaqSchema::json_ld( self::HTML, 'https://metal-divider.com/faqs/' );
		$this->assertStringStartsWith( '<script type="application/ld+json">', $json_ld );
		$this->assertStringEndsWith( '</script>', $json_ld );
		$decoded = json_decode( substr( $json_ld, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) ), true );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
		$this->assertSame( 'FAQPage', $decoded['@type'] );
		$this->assertSame( 'https://metal-divider.com/faqs/#faqpage', $decoded['@id'] );
		$this->assertCount( 2, $decoded['mainEntity'] );
		$this->assertSame( 'How do I get a quote?', $decoded['mainEntity'][0]['name'] );
		$this->assertSame( 'Answer', $decoded['mainEntity'][0]['acceptedAnswer']['@type'] );
		$this->assertSame( 'Quotes are per project.', $decoded['mainEntity'][1]['acceptedAnswer']['text'] );
	}

	/**
	 * Without pairs there is no element at all.
	 */
	public function test_json_ld_empty_without_pairs(): void {
		$this->assertSame( '', FaqSchema::json_ld( '<div>nothing</div>', 'https://metal-divider.com/faqs/' ) );
	}
}
