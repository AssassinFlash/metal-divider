<?php
/**
 * LiveCanvas runtime template and partial localizer.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

use MetalDivider\Site\Presentation\Templates;
use WP_Post;

/**
 * Intercepts LiveCanvas dynamic template resolution and partial rendering on
 * non-default frontend requests, swapping template content before shortcode execution.
 */
final class LiveCanvasLocalizer {

	/**
	 * Page-type meta keys mapped to canonical template keys.
	 */
	private const PAGE_TYPE_FILES = array(
		'is_single_product'              => 'pdp',
		'is_archive_for_tax_product_cat' => 'series-archive',
		'is_shop_page'                   => 'shop',
		'is_blog_posts_index'            => 'blog-index',
		'is_single_post'                 => 'blog-single',
		'is_archive_for_tax_category'    => 'blog-category',
		'is_search'                      => 'blog-search',
	);

	/**
	 * Registers all runtime localization hooks.
	 */
	public function register(): void {
		if ( ! \function_exists( 'add_filter' ) ) {
			return;
		}

		\add_filter( 'lc_modify_template_id', array( $this, 'prime_dynamic_template' ), 20, 1 );
		\add_filter( 'wpml_object_id', array( $this, 'prime_chrome_partial' ), 20, 2 );
		\add_filter( 'pre_do_shortcode_tag', array( $this, 'render_lc_partial_shortcode' ), 20, 3 );
		\add_filter( 'lc_modify_template_content', array( $this, 'rewrite_urls' ), 20, 1 );
		\add_filter( 'lc_modify_header_content', array( $this, 'rewrite_urls' ), 20, 1 );
		\add_filter( 'lc_modify_footer_content', array( $this, 'rewrite_urls' ), 20, 1 );
		\add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20, 1 );
	}

	/**
	 * Primes dynamic template cache with localized content before LiveCanvas renders it.
	 *
	 * @param mixed $template_id Dynamic template ID.
	 * @return mixed
	 */
	public function prime_dynamic_template( mixed $template_id ): mixed {
		if ( ! is_numeric( $template_id ) || (int) $template_id <= 0 ) {
			return $template_id;
		}

		$id = (int) $template_id;
		if ( ! Languages::is_frontend_non_default() ) {
			return $id;
		}

		$post = \function_exists( 'get_post' ) ? \get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return $id;
		}

		$file_key  = $this->resolve_template_key( $post );
		$localized = TemplateStore::get_template( $file_key, $post->post_content, Languages::current() );

		$clone               = clone $post;
		$clone->post_content = $localized;

		if ( \function_exists( 'wp_cache_set' ) ) {
			\wp_cache_set( $id, $clone, 'posts' );
		}

		return $id;
	}

	/**
	 * Primes chrome partial cache (header/footer) with localized content.
	 *
	 * @param mixed  $element_id   Partial post ID.
	 * @param string $element_type Element type (e.g. 'lc_partial').
	 * @return mixed
	 */
	public function prime_chrome_partial( mixed $element_id, string $element_type ): mixed {
		if ( ! is_numeric( $element_id ) || (int) $element_id <= 0 || 'lc_partial' !== $element_type ) {
			return $element_id;
		}

		$id = (int) $element_id;
		if ( ! Languages::is_frontend_non_default() ) {
			return $id;
		}

		$post = \function_exists( 'get_post' ) ? \get_post( $id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return $id;
		}

		$localized           = TemplateStore::get_partial( $post->post_name, $post->post_content, Languages::current() );
		$clone               = clone $post;
		$clone->post_content = $localized;

		if ( \function_exists( 'wp_cache_set' ) ) {
			\wp_cache_set( $id, $clone, 'posts' );
		}

		return $id;
	}

	/**
	 * Intercepts [lc_get_partial slug="..."] shortcodes to render localized content.
	 *
	 * @param false|string         $return_shortcode Shortcode output or false.
	 * @param string               $tag              Shortcode tag name.
	 * @param array<string, mixed> $attr     Shortcode attributes.
	 * @return false|string
	 */
	public function render_lc_partial_shortcode( false|string $return_shortcode, string $tag, array $attr ): false|string {
		if ( 'lc_get_partial' !== $tag || empty( $attr['slug'] ) || ! is_string( $attr['slug'] ) ) {
			return $return_shortcode;
		}

		if ( ! Languages::is_frontend_non_default() ) {
			return $return_shortcode;
		}

		$slug = trim( $attr['slug'] );
		$post = $this->find_partial_post( $slug );
		if ( ! $post instanceof WP_Post ) {
			return $return_shortcode;
		}

		$localized = TemplateStore::get_partial( $slug, $post->post_content, Languages::current() );

		if ( \function_exists( 'lc_process_custom_elements' ) && \function_exists( 'lc_strip_lc_attributes' ) && \function_exists( 'lct_do_shortcode' ) ) {
			return \lct_do_shortcode( \lc_strip_lc_attributes( \lc_process_custom_elements( $localized ) ) );
		}

		if ( \function_exists( 'do_shortcode' ) ) {
			return \do_shortcode( $localized );
		}

		return $localized;
	}

	/**
	 * Rewrites internal URLs and applies target-language post-processing
	 * (RTL arrow mirroring and Chinese glossary rules) in rendered LiveCanvas content.
	 *
	 * @param mixed $content Template HTML content.
	 * @return mixed
	 */
	public function rewrite_urls( mixed $content ): mixed {
		if ( ! is_string( $content ) || '' === $content || ! Languages::is_frontend_non_default() ) {
			return $content;
		}

		$lang = Languages::current();
		if ( 'zh' === $lang ) {
			$content = TemplateStore::sanitize_zh_glossary_html( $content );
		}
		if ( Languages::is_rtl( $lang ) ) {
			$content = TemplateStore::mirror_rtl_arrows_html( $content );
		}

		return UrlLocalizer::rewrite_html( $content, $lang );
	}

	/**
	 * Filters HTML language attributes for strict BCP-47 and RTL support.
	 *
	 * @param string $output Default language attributes string.
	 */
	public function filter_language_attributes( string $output ): string {
		$lang     = Languages::current();
		$all      = Languages::all();
		$info     = $all[ $lang ] ?? $all['en'];
		$hreflang = $info['hreflang'];
		$is_rtl   = Languages::is_rtl( $lang );

		// Set or replace lang="...".
		if ( str_contains( $output, 'lang="' ) ) {
			$output = (string) preg_replace( '/lang="[^"]*"/', 'lang="' . $hreflang . '"', $output );
		} else {
			$output .= ' lang="' . $hreflang . '"';
		}

		// Set or replace dir="...".
		$dir = $is_rtl ? 'rtl' : 'ltr';
		if ( str_contains( $output, 'dir="' ) ) {
			$output = (string) preg_replace( '/dir="[^"]*"/', 'dir="' . $dir . '"', $output );
		} else {
			$output .= ' dir="' . $dir . '"';
		}

		return trim( $output );
	}

	/**
	 * Resolves canonical template file key for a dynamic template post.
	 *
	 * @param WP_Post $post Template post.
	 */
	private function resolve_template_key( WP_Post $post ): string {
		if ( \function_exists( 'get_post_meta' ) ) {
			foreach ( self::PAGE_TYPE_FILES as $meta_key => $file_key ) {
				$val = \get_post_meta( $post->ID, $meta_key, true );
				if ( '1' === (string) $val || 1 === $val || true === $val ) {
					return $file_key;
				}
			}
		}

		$post_name = (string) $post->post_name;
		if ( str_starts_with( $post_name, 'md-' ) ) {
			return substr( $post_name, 3 );
		}

		return $post_name;
	}

	/**
	 * Finds an lc_partial post by slug.
	 *
	 * @param string $slug Partial slug.
	 */
	private function find_partial_post( string $slug ): ?WP_Post {
		if ( \function_exists( 'get_page_by_path' ) ) {
			$post = \get_page_by_path( $slug, OBJECT, 'lc_partial' );
			if ( $post instanceof WP_Post ) {
				return $post;
			}
		}

		if ( \function_exists( 'get_posts' ) ) {
			$posts = \get_posts(
				array(
					'post_type'      => 'lc_partial',
					'name'           => $slug,
					'posts_per_page' => 1,
					'post_status'    => 'publish',
				)
			);
			if ( ! empty( $posts ) ) {
				return $posts[0];
			}
		}

		return null;
	}
}
