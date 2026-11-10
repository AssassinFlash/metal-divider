<?php
/**
 * Filters and normalizes hreflang tags emitted by WPML.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Normalizes hreflang codes, drops hreflang on non-canonical views,
 * and ensures an x-default tag pointing to the English canonical URL.
 */
final class Hreflang {

	/**
	 * Registers the WPML hreflangs filter.
	 */
	public function register(): void {
		\add_filter( 'wpml_hreflangs', array( $this, 'filter' ), 20 );
	}

	/**
	 * Filters the hreflang tags array.
	 *
	 * Drops hreflangs on search results, 404s, paged archives, parameter-driven
	 * catalog filter views, and noindexed singular posts.
	 * For indexable canonical pages, normalizes hreflang tags (e.g. zh-Hans, pt-BR)
	 * and injects x-default pointing to the English URL.
	 *
	 * @param mixed $hreflangs Raw hreflangs array from WPML.
	 * @return array<string, string> Filtered and normalized hreflang map.
	 */
	public function filter( $hreflangs ): array {
		if ( ! \is_array( $hreflangs ) || array() === $hreflangs ) {
			return array();
		}

		if ( ( \function_exists( 'is_search' ) && \is_search() ) || ( \function_exists( 'is_404' ) && \is_404() ) ) {
			return array();
		}

		if ( \function_exists( 'is_paged' ) && \is_paged() ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['pattern_search'] ) || ! empty( $_GET['q'] ) || ! empty( $_GET['sort_by'] ) ) {
			return array();
		}

		if ( \function_exists( 'is_singular' ) && \is_singular() ) {
			$post_id = \function_exists( 'get_queried_object_id' ) ? (int) \get_queried_object_id() : 0;
			if ( $post_id > 0 && \function_exists( 'get_post_meta' ) ) {
				$noindex = (string) \get_post_meta( $post_id, '_seopress_robots_index', true );
				if ( 'yes' === $noindex ) {
					return array();
				}
			}
		}

		$normalized   = array();
		$replacements = array(
			'zh-hans' => 'zh-Hans',
			'pt-br'   => 'pt-BR',
		);

		foreach ( $hreflangs as $code => $url ) {
			if ( ! \is_string( $code ) || ! \is_string( $url ) ) {
				continue;
			}
			$lower              = \strtolower( $code );
			$key                = $replacements[ $lower ] ?? $code;
			$normalized[ $key ] = $url;
		}

		if ( isset( $normalized['en'] ) ) {
			$normalized['x-default'] = $normalized['en'];
		}

		return $normalized;
	}
}
