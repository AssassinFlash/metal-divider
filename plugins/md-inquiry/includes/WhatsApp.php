<?php
/**
 * The WhatsApp route's URL builders.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Builds the wa.me links of the inquiry chain (spec 07 §4 and appendix B,
 * branch research/spec-inquiry-chain): plain anchors, no third-party script.
 * The number is the owner's single customer-facing one (owner ruling
 * 2026-10-24: +852 6473 1687; wa.me wants it international, without the plus).
 * The prefilled texts went through the adversarial review of ticket #33;
 * the final wording is archived under docs/copy/inquiry-emails.md.
 */
final class WhatsApp {

	/**
	 * The customer-facing number, in wa.me's format: international, no plus
	 * (spec 07 §4, per WhatsApp's own click-to-chat FAQ).
	 */
	public const NUMBER = '85264731687';

	/**
	 * The number as people read it.
	 */
	public const DISPLAY = '+852 6473 1687';

	/**
	 * The general greeting (spec 07 appendix B, template W1).
	 */
	public const GREETING = "Hi, I'm interested in a custom metal divider screen project.";

	/**
	 * The shortcode that renders a WhatsApp anchor for the templates.
	 */
	public const SHORTCODE = 'md_inquiry_whatsapp';

	/**
	 * Registers the shortcode.
	 */
	public function register(): void {
		\add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Renders the WhatsApp anchor: `[md_inquiry_whatsapp module="hero"]Label[/md_inquiry_whatsapp]`,
	 * pre-filled for the current pattern when the form sits in one. A pure
	 * link — the class carries no style, the template's WindPress volume does.
	 *
	 * @param array<string, mixed>|string $atts    The shortcode attributes.
	 * @param string|null                 $content The anchor's label.
	 * @return string
	 */
	public function render_shortcode( $atts = array(), ?string $content = '' ): string {
		$atts    = \is_array( $atts ) ? $atts : array();
		$atts    = \shortcode_atts(
			array(
				'module' => 'hero',
				'page'   => '',
			),
			$atts,
			self::SHORTCODE
		);
		$pattern = ( new Pattern() )->from_current_post();
		$page    = Validator::source_token( (string) $atts['page'] );
		if ( '' === $page ) {
			$page = null !== $pattern ? 'pattern-pdp' : 'inquiry';
		}
		$url    = self::url( self::cta_text( (string) $atts['module'], $pattern, $page ) );
		$label  = \is_string( $content ) && '' !== \trim( $content ) ? \trim( $content ) : \__( 'WhatsApp', 'md-inquiry' );
		$module = \esc_attr( (string) $atts['module'] );
		return '<a class="md-inquiry-whatsapp-link" data-md-inquiry-module="' . $module . '" href="' . \esc_url( $url ) . '" target="_blank" rel="noopener">' . \esc_html( $label ) . '</a>';
	}

	/**
	 * A WhatsApp URL for a prefilled text.
	 *
	 * @param string $text The message text.
	 * @return string
	 */
	public static function url( string $text ): string {
		return 'https://wa.me/' . self::NUMBER . '?text=' . \rawurlencode( $text );
	}

	/**
	 * The prefilled text of a page's WhatsApp CTA (the hero and FAQ buttons of
	 * the PDP): the greeting, the pattern line, and the Ref line that carries
	 * the source — inside the text, where WhatsApp keeps it, never as a query
	 * parameter the old site threw away (spec 07 §3/F5).
	 *
	 * @param string                                                                           $module  The source module (hero, faq…).
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern The pattern context, when the page is a pattern's.
	 * @param string                                                                           $page    The source page.
	 * @return string
	 */
	public static function cta_text( string $module, ?array $pattern, string $page ): string {
		$lines = array( self::GREETING );
		if ( null !== $pattern ) {
			$lines[] = 'Pattern: ' . $pattern['name'] . ' (' . $pattern['code'] . ')';
		}
		$lines[] = 'Ref: ' . $page . '/' . Validator::source_token( $module ) . '/whatsapp';
		return \implode( "\n", $lines );
	}

	/**
	 * The prefilled text the form's WhatsApp button opens, assembled live in
	 * the browser from what the customer has typed (the JavaScript mirror of
	 * this method; kept here as the reference wording).
	 *
	 * @param string                                                                           $name    The name, when filled.
	 * @param string                                                                           $company The company, when filled.
	 * @param string                                                                           $message The message, when filled.
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern The pattern context.
	 * @param array{page: string, module: string, button: string}                              $source The source triple.
	 * @return string
	 */
	public static function form_text( string $name, string $company, string $message, ?array $pattern, array $source ): string {
		$lines = array();
		if ( '' !== $name ) {
			$lines[] = "Hi, I'm " . $name . ( '' !== $company ? ' (' . $company . ')' : '' ) . '.';
		}
		if ( null !== $pattern ) {
			$lines[] = 'I\'d like a quote for this pattern:';
			$lines[] = $pattern['name'] . ' (' . $pattern['code'] . ')';
		}
		if ( '' !== $message ) {
			$lines[] = $message;
		}
		$lines[] = 'Ref: ' . Validator::source_token( $source['page'] ) . '/' . Validator::source_token( $source['module'] ) . '/' . Validator::source_token( $source['button'] );
		return \implode( "\n", $lines );
	}
}
