<?php
/**
 * Tests for the WhatsApp route's URL builders.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Pattern;
use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\WhatsApp;
use WP_Mock;

/**
 * The wa.me links and their prefilled texts.
 *
 * @covers \MetalDivider\Inquiry\WhatsApp
 */
final class WhatsAppTest extends TestCase {

	/**
	 * The number is the owner's single customer-facing one, in wa.me's format:
	 * international, no plus — and the display form groups it the way people
	 * read it.
	 */
	public function test_the_number_is_the_one_and_only(): void {
		$this->assertSame( '85264731687', WhatsApp::NUMBER );
		$this->assertSame( '+852 6473 1687', WhatsApp::DISPLAY );
	}

	/**
	 * A URL is the official click-to-chat shape: wa.me, the bare number, the
	 * text URL-encoded.
	 */
	public function test_the_url_is_the_official_shape(): void {
		$this->assertSame(
			'https://wa.me/85264731687?text=' . \rawurlencode( WhatsApp::GREETING ),
			WhatsApp::url( WhatsApp::GREETING )
		);
	}

	/**
	 * A pattern CTA's prefilled text: the greeting, the pattern with its code,
	 * and the Ref line inside the text — never a query parameter the old site
	 * threw away (spec 07 §4/F5).
	 */
	public function test_the_cta_text_carries_the_pattern_and_the_ref_line(): void {
		$pattern = array(
			'post_id' => 42,
			'name'    => 'Apiary',
			'code'    => 'APIARY',
			'url'     => 'https://metal-divider.com/pattern/apiary',
			'thumb'   => '',
		);

		$expected = "Hi, I'm interested in a custom metal divider screen project.\n"
			. "Pattern: Apiary (APIARY)\n"
			. 'Ref: pattern-pdp/hero/whatsapp';
		$this->assertSame( $expected, WhatsApp::cta_text( 'hero', $pattern, 'pattern-pdp' ) );
	}

	/**
	 * The form's text: the introduction when a name is filled, the pattern
	 * line, the message, the Ref line — and the introduction skipped when the
	 * name is empty, so a chat never opens on "Hi, I'm .".
	 */
	public function test_the_form_text_assembles_the_lived_fields(): void {
		$pattern = array(
			'post_id' => 42,
			'name'    => 'Apiary',
			'code'    => 'APIARY',
			'url'     => 'https://metal-divider.com/pattern/apiary',
			'thumb'   => '',
		);
		$source  = array(
			'page'   => 'pattern-pdp',
			'module' => 'hero',
			'button' => 'request_quote',
		);

		$this->assertSame(
			"Hi, I'm Jane Chan (Chan Design Studio).\nI'd like a quote for this pattern:\nApiary (APIARY)\n40 panels, please.\nRef: pattern-pdp/hero/request_quote",
			WhatsApp::form_text( 'Jane Chan', 'Chan Design Studio', '40 panels, please.', $pattern, $source )
		);
		$this->assertSame(
			"I'd like a quote for this pattern:\nApiary (APIARY)\nRef: pattern-pdp/hero/request_quote",
			WhatsApp::form_text( '', '', '', $pattern, $source ),
			'no name: no introduction line, and nothing else is invented'
		);
	}

	/**
	 * The shortcode renders a plain anchor with the module-aimed URL — pure
	 * link, class name for the WindPress volume, no style of its own.
	 */
	public function test_the_shortcode_renders_a_plain_anchor(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'shortcode_atts' )->once()->andReturnUsing(
			static function ( array $defaults, array $atts ): array {
				return \array_merge( $defaults, $atts );
			}
		);
		$post = new \WP_Post(
			(object) array(
				'ID'        => 42,
				'post_type' => 'product',
			)
		);
		WP_Mock::userFunction( 'get_post' )->andReturn( $post );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Apiary' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( 'APIARY' );
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/apiary' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( '' );

		$html = ( new WhatsApp() )->render_shortcode( array( 'module' => 'faq' ), 'Chat on WhatsApp' );

		$this->assertStringStartsWith( '<a class="md-inquiry-whatsapp-link" data-md-inquiry-module="faq" href="https://wa.me/85264731687?text=', $html );
		$this->assertStringEndsWith( '">Chat on WhatsApp</a>', $html );
		$this->assertStringNotContainsString( ' style=', $html );
		$this->assertStringContainsString( \rawurlencode( 'Pattern: Apiary (APIARY)' ), $html );
		$this->assertStringContainsString( \rawurlencode( 'Ref: pattern-pdp/faq/whatsapp' ), $html );
	}
}
