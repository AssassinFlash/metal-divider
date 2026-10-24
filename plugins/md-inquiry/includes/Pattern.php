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
	public const SHAPE = 'array{post_id: int, name: string, code: string, url: string, thumb: string}';

	/**
	 * Resolves a pattern by its product slug.
	 *
	 * @param string $slug The pattern's product slug.
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string}|null The context, or null when no published product carries the slug.
	 */
	public function resolve( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}
		$posts = \get_posts(
			array(
				'name'        => $slug,
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => 1,
			)
		);
		if ( array() === $posts ) {
			return null;
		}
		return $this->context( (int) $posts[0]->ID );
	}

	/**
	 * The current request's pattern context, when the form renders inside a
	 * product (the PDP's own placement; the shortcode's `pattern` attribute is
	 * the explicit alternative).
	 *
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string}|null
	 */
	public function from_current_post(): ?array {
		$post = \get_post();
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return null;
		}
		return $this->context( (int) $post->ID );
	}

	/**
	 * Assembles one product's context.
	 *
	 * @param int $post_id The product's ID.
	 * @return array{post_id: int, name: string, code: string, url: string, thumb: string}
	 */
	private function context( int $post_id ): array {
		$thumb = \get_the_post_thumbnail_url( $post_id, 'thumbnail' );
		return array(
			'post_id' => $post_id,
			'name'    => \get_the_title( $post_id ),
			'code'    => (string) \get_post_meta( $post_id, '_sku', true ),
			'url'     => (string) \get_permalink( $post_id ),
			'thumb'   => \is_string( $thumb ) ? $thumb : '',
		);
	}
}
