<?php
/**
 * The thank-you page: the redirect target and its verified context.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * The 303's destination (spec 07 §6): the /inquiry/thank-you/ page the
 * template face owns, carrying the inquiry's id and a keyed hash — enough
 * for the page to greet the customer with their pattern, never enough to
 * enumerate anyone's inquiries. The hash is wp_hash over the id, so it needs
 * no secret of its own and changes with the site's salts.
 */
final class ThankYou {

	/**
	 * The thank-you page's path.
	 */
	public const PATH = 'inquiry/thank-you';

	/**
	 * The shortcode that renders the pattern context line for the template.
	 */
	public const SHORTCODE = 'md_inquiry_thank_you_pattern';

	/**
	 * The WP_Query variable the page's template reads.
	 */
	public const QUERY_VAR = 'md_inquiry';

	/**
	 * Registers the template-facing pieces.
	 */
	public function register(): void {
		\add_shortcode( self::SHORTCODE, array( $this, 'render_pattern' ) );
	}

	/**
	 * The redirect URL for one inquiry.
	 *
	 * @param int $inquiry_id The inquiry record's ID.
	 * @return string
	 */
	public static function url( int $inquiry_id ): string {
		$page = \get_page_by_path( self::PATH );
		$base = $page instanceof \WP_Post ? (string) \get_permalink( $page ) : \home_url( '/' . self::PATH . '/' );
		return \add_query_arg(
			array(
				self::QUERY_VAR => $inquiry_id,
				'key'           => self::key( $inquiry_id ),
			),
			$base
		);
	}

	/**
	 * The keyed hash that proves a query string was issued by the chain.
	 *
	 * @param int $inquiry_id The inquiry record's ID.
	 * @return string
	 */
	public static function key( int $inquiry_id ): string {
		return \wp_hash( 'md_inquiry_thank_you_' . $inquiry_id );
	}

	/**
	 * The verified context of a thank-you request, for the page's template:
	 * the pattern the customer asked about, from the record the redirect
	 * named — never from anything the query string claims beyond id and key.
	 *
	 * @return array{pattern_name: string, pattern_code: string}|null The context, or null when the request is not a verified one.
	 */
	public static function context(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification -- the key below is this request's verification: a forged id without the site's hash answers nothing.
		$id_raw = isset( $_GET[ self::QUERY_VAR ] ) ? \sanitize_text_field( \wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		$id     = \ctype_digit( $id_raw ) ? (int) $id_raw : 0;
		$key    = isset( $_GET['key'] ) ? \sanitize_text_field( \wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- the key is the verification.
		if ( 0 === $id || '' === $key || ! \hash_equals( self::key( $id ), $key ) ) {
			return null;
		}
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || PostType::NAME !== $post->post_type ) {
			return null;
		}
		return array(
			'pattern_name' => (string) \get_post_meta( $id, 'pattern_name', true ),
			'pattern_code' => (string) \get_post_meta( $id, 'pattern_code', true ),
		);
	}

	/**
	 * Renders the thank-you page's pattern line: the pattern the customer
	 * asked about, when the request is a verified one. An empty string
	 * otherwise — the page's own copy stands on its own.
	 *
	 * @return string
	 */
	public function render_pattern(): string {
		$context = self::context();
		if ( null === $context ) {
			return '';
		}
		$line = \esc_html( $context['pattern_name'] );
		if ( '' !== $context['pattern_code'] ) {
			$line .= ' <span class="md-inquiry-thank-you-code">' . \esc_html( $context['pattern_code'] ) . '</span>';
		}
		return '<p class="md-inquiry-thank-you-pattern">' . $line . '</p>';
	}
}
