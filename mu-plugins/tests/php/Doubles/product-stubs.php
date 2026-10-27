<?php
/**
 * Static-analysis stubs for the ACF and WooCommerce reads the knowledge
 * endpoint makes at answer time. phpstan reads this file via scanFiles; the
 * test bootstrap never loads it (it defines the same names for the suite).
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis.

namespace {
	/**
	 * An ACF field of a post.
	 *
	 * @param string    $selector The field name.
	 * @param int|false $post_id  The post.
	 * @return mixed The field value.
	 */
	function get_field( string $selector, int|false $post_id = false ): mixed {
		unset( $selector, $post_id );
		return null;
	}

	/**
	 * The terms of a post. Untyped on purpose: the caller must not assume a
	 * WP_Term class that this stub does not declare.
	 *
	 * @param int    $post_id  The post.
	 * @param string $taxonomy The taxonomy.
	 * @return mixed The terms, false, or an error.
	 */
	function get_the_terms( int $post_id, string $taxonomy ): mixed {
		unset( $post_id, $taxonomy );
		return false;
	}

	/**
	 * A post's permalink.
	 *
	 * @param int $post The post id.
	 * @return string|false The permalink.
	 */
	function get_permalink( int $post = 0 ): string|false {
		unset( $post );
		return false;
	}

	/**
	 * The page or product at a path.
	 *
	 * @param string               $page_path The path.
	 * @param string               $output    The return shape.
	 * @param string|array<string> $post_type The post type.
	 * @return \WP_Post|null The post, null when none matches.
	 */
	/**
	 * parse_url() in WordPress's shape.
	 *
	 * @param string $url The URL.
	 * @return array<string, mixed>|false The parts, or false.
	 */
	function wp_parse_url( string $url ): array|false {
		unset( $url );
		return false;
	}

	function get_page_by_path( string $page_path, string $output = OBJECT, string|array $post_type = 'page' ): ?\WP_Post {
		unset( $page_path, $output, $post_type );
		return null;
	}

	/**
	 * A post's featured image URL.
	 *
	 * @param int          $post The post id.
	 * @param string|array $size The image size.
	 * @return string|false The URL.
	 */
	function get_the_post_thumbnail_url( int $post = 0, string|array $size = 'post-thumbnail' ): string|false {
		unset( $post, $size );
		return false;
	}

	/**
	 * A post's meta value.
	 *
	 * @param int    $post_id The post.
	 * @param string $key     The meta key.
	 * @param bool   $single  Whether one value is returned.
	 * @return mixed The value.
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		unset( $post_id, $key, $single );
		return '';
	}

	/**
	 * A WooCommerce product, or false when it does not exist.
	 *
	 * @param int $post_id The product.
	 * @return object|false The product.
	 */
	function wc_get_product( int $post_id ): object|false {
		unset( $post_id );
		return false;
	}
}
