<?php
/**
 * The pattern context an inquiry attaches.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Resolves the pattern an inquiry is about. The form carries the pattern's
 * slug and nothing else: the name and the code are rebuilt server-side from
 * the product (spec 07 §3, branch research/spec-inquiry-chain — the client's
 * text is never trusted), with the code read from the product's SKU, the
 * carrier the data model fixed for pattern_code (spec 06 §3, branch
 * research/spec-data-model).
 */
final class Pattern {

	/**
	 * The resolved context: the product's id, title, code, URL and thumbnail.
	 */
	public const SHAPE = 'array{post_id: int, name: string, code: string, url: string, thumb: string, canonical_slug?: string, name_en?: string}';

	/**
	 * Resolves a pattern by its product slug.
	 *
	 * @param string $slug The pattern's product slug.
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string, canonical_slug?: string, name_en?: string}|null The context, or null when no published product carries the slug.
	 */
	public function resolve( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}
		$posts = \get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);
		if ( array() === $posts ) {
			$posts = \get_posts(
				array(
					'name'             => $slug,
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'suppress_filters' => true,
				)
			);
		}
		if ( array() === $posts ) {
			return null;
		}
		return $this->context( (int) $posts[0]->ID, $slug );
	}

	/**
	 * The current request's pattern context, when the form renders inside a
	 * product (the PDP's own placement; the shortcode's `pattern` attribute is
	 * the explicit alternative).
	 *
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string, canonical_slug?: string, name_en?: string}|null
	 */
	public function from_current_post(): ?array {
		if ( isset( $GLOBALS['wp_query'] ) && \is_object( $GLOBALS['wp_query'] ) && \method_exists( $GLOBALS['wp_query'], 'is_singular' ) && ! $GLOBALS['wp_query']->is_singular( 'product' ) ) {
			return null;
		}
		$post = \get_post();
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return null;
		}
		return $this->context( (int) $post->ID, $post->post_name );
	}

	/**
	 * A product title without its SEO suffix.
	 *
	 * Production titles carry the pattern's name and a keyword suffix after a
	 * pipe: "Agave | Architectural Metal Screen Pattern". The suffix belongs
	 * to the page's title tag, not to the pattern. Every surface this context
	 * feeds — the form's pattern row, the prefilled WhatsApp texts, the stored
	 * record and the notification emails — names the pattern, so only the
	 * first segment is used.
	 *
	 * @param string $title The raw product title.
	 * @return string The name segment, trimmed.
	 */
	public static function clean_title( string $title ): string {
		$parts = \preg_split( '/[|｜]/u', $title );
		return \is_array( $parts ) && isset( $parts[0] ) ? \trim( $parts[0] ) : \trim( $title );
	}

	/**
	 * Assembles one product's context.
	 *
	 * @param int    $found_id   The product's ID.
	 * @param string $known_slug The product's known slug, if available.
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string, canonical_slug?: string, name_en?: string}
	 */
	private function context( int $found_id, string $known_slug = '' ): array {
		$target_wpml = Locale::to_wpml( Locale::current() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML public filter.
		$current_id = (int) \apply_filters( 'wpml_object_id', $found_id, 'product', true, $target_wpml );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML public filter.
		$en_id = (int) \apply_filters( 'wpml_object_id', $found_id, 'product', true, 'en' );

		if ( $current_id <= 0 ) {
			$current_id = $found_id;
		}
		if ( $en_id <= 0 ) {
			$en_id = $found_id;
		}

		$thumb = \get_the_post_thumbnail_url( $current_id, 'thumbnail' );
		if ( ! \is_string( $thumb ) || '' === $thumb ) {
			$thumb = \get_the_post_thumbnail_url( $en_id, 'thumbnail' );
		}

		$code = (string) \get_post_meta( $current_id, '_sku', true );
		if ( '' === $code ) {
			$code = (string) \get_post_meta( $en_id, '_sku', true );
		}

		return array(
			'post_id'        => $en_id,
			'name'           => self::clean_title( (string) \get_the_title( $current_id ) ),
			'code'           => $code,
			'url'            => (string) \get_permalink( $current_id ),
			'thumb'          => \is_string( $thumb ) ? $thumb : '',
			'canonical_slug' => $known_slug,
			'name_en'        => self::clean_title( (string) \get_the_title( $en_id ) ),
		);
	}
}
