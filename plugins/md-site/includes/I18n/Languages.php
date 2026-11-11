<?php
/**
 * Canonical 9-language definitions and routing helpers for metal-divider.com.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Single source of truth for the site's nine languages, WPML language code
 * mappings, URL resolution, and switcher item generation.
 */
final class Languages {

	public const SOURCE = 'en';

	public const ALL = array( 'en', 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' );

	public const TARGETS = array( 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' );

	/**
	 * Test override for current language.
	 *
	 * @var string|null
	 */
	private static ?string $forced_language = null;

	/**
	 * Metadata for all supported languages keyed by canonical code.
	 *
	 * @return array<string, array{code: string, wpml: string, locale: string, hreflang: string, label: string, short: string, english_name: string, rtl: bool}>
	 */
	public static function all(): array {
		return array(
			'en'    => array(
				'code'         => 'en',
				'wpml'         => 'en',
				'locale'       => 'en_US',
				'hreflang'     => 'en',
				'label'        => 'English',
				'short'        => 'EN',
				'english_name' => 'English',
				'rtl'          => false,
			),
			'zh'    => array(
				'code'         => 'zh',
				'wpml'         => 'zh-hans',
				'locale'       => 'zh_CN',
				'hreflang'     => 'zh-Hans',
				'label'        => '简体中文',
				'short'        => '中文',
				'english_name' => 'Chinese',
				'rtl'          => false,
			),
			'es'    => array(
				'code'         => 'es',
				'wpml'         => 'es',
				'locale'       => 'es_ES',
				'hreflang'     => 'es',
				'label'        => 'Español',
				'short'        => 'ES',
				'english_name' => 'Spanish',
				'rtl'          => false,
			),
			'pt-br' => array(
				'code'         => 'pt-br',
				'wpml'         => 'pt-br',
				'locale'       => 'pt_BR',
				'hreflang'     => 'pt-BR',
				'label'        => 'Português (Brasil)',
				'short'        => 'PT',
				'english_name' => 'Portuguese',
				'rtl'          => false,
			),
			'de'    => array(
				'code'         => 'de',
				'wpml'         => 'de',
				'locale'       => 'de_DE',
				'hreflang'     => 'de',
				'label'        => 'Deutsch',
				'short'        => 'DE',
				'english_name' => 'German',
				'rtl'          => false,
			),
			'fr'    => array(
				'code'         => 'fr',
				'wpml'         => 'fr',
				'locale'       => 'fr_FR',
				'hreflang'     => 'fr',
				'label'        => 'Français',
				'short'        => 'FR',
				'english_name' => 'French',
				'rtl'          => false,
			),
			'ar'    => array(
				'code'         => 'ar',
				'wpml'         => 'ar',
				'locale'       => 'ar',
				'hreflang'     => 'ar',
				'label'        => 'العربية',
				'short'        => 'AR',
				'english_name' => 'Arabic',
				'rtl'          => true,
			),
			'id'    => array(
				'code'         => 'id',
				'wpml'         => 'id',
				'locale'       => 'id_ID',
				'hreflang'     => 'id',
				'label'        => 'Bahasa Indonesia',
				'short'        => 'ID',
				'english_name' => 'Indonesian',
				'rtl'          => false,
			),
			'vi'    => array(
				'code'         => 'vi',
				'wpml'         => 'vi',
				'locale'       => 'vi',
				'hreflang'     => 'vi',
				'label'        => 'Tiếng Việt',
				'short'        => 'VI',
				'english_name' => 'Vietnamese',
				'rtl'          => false,
			),
		);
	}

	/**
	 * Converts a canonical code to the WPML internal code.
	 *
	 * @param string $code Canonical code (e.g. 'zh').
	 */
	public static function to_wpml( string $code ): string {
		$lower = strtolower( trim( $code ) );
		return 'zh' === $lower ? 'zh-hans' : $lower;
	}

	/**
	 * Converts a WPML internal code to canonical code.
	 *
	 * @param string $wpml_code WPML language code (e.g. 'zh-hans').
	 */
	public static function from_wpml( string $wpml_code ): string {
		$lower = strtolower( trim( $wpml_code ) );
		return 'zh-hans' === $lower ? 'zh' : $lower;
	}

	/**
	 * Sets or clears a forced language for tests.
	 *
	 * @param string|null $code Canonical language code or null to clear.
	 */
	public static function force_for_tests( ?string $code ): void {
		self::$forced_language = null !== $code ? self::from_wpml( $code ) : null;
	}

	/**
	 * Determines the current active canonical language.
	 */
	public static function current(): string {
		if ( null !== self::$forced_language && in_array( self::$forced_language, self::ALL, true ) ) {
			return self::$forced_language;
		}

		if ( \function_exists( 'has_filter' ) && \has_filter( 'wpml_current_language' ) ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
			$raw = \apply_filters( 'wpml_current_language', null );
			if ( is_string( $raw ) && '' !== trim( $raw ) ) {
				$canonical = self::from_wpml( $raw );
				if ( in_array( $canonical, self::ALL, true ) ) {
					return $canonical;
				}
			}
		}

		return self::SOURCE;
	}

	/**
	 * Whether the current request is a non-default language on the front-end.
	 */
	public static function is_frontend_non_default(): bool {
		if ( \defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( self::SOURCE === self::current() ) {
			return false;
		}
		$is_admin = false;
		if ( \function_exists( 'is_admin' ) ) {
			try {
				$is_admin = (bool) \is_admin();
			} catch ( \Throwable $e ) {
				$is_admin = false;
			}
		}
		return ! $is_admin;
	}

	/**
	 * Whether the given or current language is right-to-left.
	 *
	 * @param string|null $code Canonical language code or null for current.
	 */
	public static function is_rtl( ?string $code = null ): bool {
		$lang = null !== $code ? self::from_wpml( $code ) : self::current();
		$all  = self::all();
		return $all[ $lang ]['rtl'] ?? false;
	}

	/**
	 * Raw base home URL without WPML language filtering.
	 *
	 * @param string $path Optional path to append.
	 */
	public static function unfiltered_home_url( string $path = '/' ): string {
		$root = '';
		if ( \function_exists( 'get_option' ) ) {
			try {
				$opt = \get_option( 'home' );
				if ( is_string( $opt ) && '' !== trim( $opt ) ) {
					$root = rtrim( trim( $opt ), '/\\' );
				}
			} catch ( \Throwable ) {
				$root = '';
			}
		}

		if ( '' === $root && \function_exists( 'home_url' ) ) {
			try {
				$root = rtrim( (string) \home_url( '/' ), '/\\' );
			} catch ( \Throwable ) {
				$root = '';
			}
		}

		if ( '' === $root ) {
			$root = 'https://metal-divider.com';
		}

		$clean_path = '/' . ltrim( $path, '/' );
		if ( '/' === $clean_path ) {
			return $root . '/';
		}

		return $root . $clean_path;
	}

	/**
	 * Builds a localized canonical URL for a path.
	 *
	 * @param string      $path Root-relative path.
	 * @param string|null $code Target language code or null for current.
	 */
	public static function home_url( string $path = '/', ?string $code = null ): string {
		$lang = null !== $code ? self::from_wpml( $code ) : self::current();
		if ( ! in_array( $lang, self::ALL, true ) ) {
			$lang = self::SOURCE;
		}

		$root       = rtrim( self::unfiltered_home_url( '/' ), '/\\' );
		$clean_path = '/' . ltrim( $path, '/' );

		if ( self::SOURCE === $lang ) {
			return '/' === $clean_path ? $root . '/' : $root . $clean_path;
		}

		if ( '/' === $clean_path ) {
			return $root . '/' . $lang . '/';
		}

		return $root . '/' . $lang . $clean_path;
	}

	/**
	 * Resolves a post translation ID in the target language.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $code    Target canonical language code.
	 */
	public static function post_translation( int $post_id, string $code ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( ! \function_exists( 'has_filter' ) || ! \has_filter( 'wpml_object_id' ) ) {
			return self::SOURCE === self::from_wpml( $code ) ? $post_id : 0;
		}

		$post_type = 'post';
		if ( \function_exists( 'get_post_type' ) ) {
			$detected = \get_post_type( $post_id );
			if ( is_string( $detected ) && '' !== $detected ) {
				$post_type = $detected;
			}
		}
		$wpml_lang = self::to_wpml( $code );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
		$translated = \apply_filters( 'wpml_object_id', $post_id, $post_type, false, $wpml_lang );
		return is_numeric( $translated ) ? (int) $translated : 0;
	}

	/**
	 * Resolves a term translation ID in the target language.
	 *
	 * @param int    $term_id  Source term ID.
	 * @param string $taxonomy Taxonomy name (e.g. 'product_cat').
	 * @param string $code     Target canonical language code.
	 */
	public static function term_translation( int $term_id, string $taxonomy, string $code ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}

		if ( ! \function_exists( 'has_filter' ) || ! \has_filter( 'wpml_object_id' ) ) {
			return self::SOURCE === self::from_wpml( $code ) ? $term_id : 0;
		}

		$wpml_lang = self::to_wpml( $code );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
		$translated = \apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $wpml_lang );
		return is_numeric( $translated ) ? (int) $translated : 0;
	}

	/**
	 * Executes a callback within a temporary WPML language context.
	 *
	 * @param string   $code     Canonical language code.
	 * @param callable $callback Operation to run.
	 * @return mixed
	 */
	public static function with_language( string $code, callable $callback ): mixed {
		$target_wpml = self::to_wpml( $code );
		$sitepress   = $GLOBALS['sitepress'] ?? null;
		$orig_wpml   = null;

		$prev_forced           = self::$forced_language;
		self::$forced_language = self::from_wpml( $code );

		$sitepress_switcher = is_object( $sitepress ) && method_exists( $sitepress, 'switch_lang' ) && method_exists( $sitepress, 'get_current_language' ) ? $sitepress : null;
		if ( null !== $sitepress_switcher ) {
			$orig_wpml = $sitepress_switcher->get_current_language();
			$sitepress_switcher->switch_lang( $target_wpml );
		}

		try {
			return $callback();
		} finally {
			self::$forced_language = $prev_forced;
			if ( null !== $orig_wpml ) {
				$sitepress_switcher->switch_lang( $orig_wpml );
			}
		}
	}

	/**
	 * Generates language switcher items for all nine languages for the current view.
	 *
	 * @return list<array{code: string, short: string, label: string, hreflang: string, lang_attr: string, dir: string, url: string, active: bool}>
	 */
	public static function switcher_items(): array {
		$current_lang = self::current();
		$all          = self::all();
		$items        = array();

		foreach ( self::ALL as $code ) {
			$info       = $all[ $code ];
			$target_url = self::resolve_target_url( $code );

			$items[] = array(
				'code'      => $code,
				'short'     => $info['short'],
				'label'     => $info['label'],
				'hreflang'  => $info['hreflang'],
				'lang_attr' => $info['hreflang'],
				'dir'       => $info['rtl'] ? 'rtl' : 'ltr',
				'url'       => $target_url,
				'active'    => $code === $current_lang,
			);
		}

		return $items;
	}

	/**
	 * Resolves the contextual target URL for a language code.
	 *
	 * @param string $code Canonical target language code.
	 */
	private static function resolve_target_url( string $code ): string {
		$preserve_query = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( isset( $_GET['pattern_search'] ) && is_string( $_GET['pattern_search'] ) && '' !== trim( $_GET['pattern_search'] ) ) {
			$preserve_query['pattern_search'] = sanitize_text_field( wp_unslash( $_GET['pattern_search'] ) );
		}
		if ( isset( $_GET['sort_by'] ) && is_string( $_GET['sort_by'] ) && '' !== trim( $_GET['sort_by'] ) ) {
			$preserve_query['sort_by'] = sanitize_text_field( wp_unslash( $_GET['sort_by'] ) );
		}
		// phpcs:enable

		if ( \function_exists( 'is_front_page' ) && \is_front_page() ) {
			return self::home_url( '/', $code );
		}

		if ( ( \function_exists( 'is_shop' ) && \is_shop() ) || ( \function_exists( 'is_post_type_archive' ) && \is_post_type_archive( 'product' ) ) ) {
			$url = self::home_url( '/patterns/', $code );
			return ! empty( $preserve_query ) && \function_exists( 'add_query_arg' ) ? \add_query_arg( $preserve_query, $url ) : $url;
		}

		$queried = \function_exists( 'get_queried_object' ) ? \get_queried_object() : null;
		if ( $queried instanceof \WP_Term ) {
			$term_id = (int) $queried->term_id;
			$tax     = (string) $queried->taxonomy;
			$tr_id   = self::term_translation( $term_id, $tax, $code );
			$url     = '';

			if ( $tr_id > 0 ) {
				$link = self::with_language(
					$code,
					static function () use ( $tr_id, $tax ): string {
						$res = \function_exists( 'get_term_link' ) ? \get_term_link( $tr_id, $tax ) : '';
						return is_string( $res ) ? $res : '';
					}
				);
				if ( is_string( $link ) && '' !== $link ) {
					$url = $link;
				}
			}

			if ( '' === $url ) {
				$base = 'product_cat' === $tax ? '/pattern-category/' : '/category/';
				$url  = self::home_url( $base, $code );
			}

			return ! empty( $preserve_query ) && \function_exists( 'add_query_arg' ) ? \add_query_arg( $preserve_query, $url ) : $url;
		}

		if ( \function_exists( 'is_singular' ) && \is_singular() ) {
			$post_id = \function_exists( 'get_queried_object_id' ) ? (int) \get_queried_object_id() : 0;
			if ( $post_id > 0 ) {
				$tr_id = self::post_translation( $post_id, $code );
				if ( $tr_id > 0 ) {
					$link = self::with_language(
						$code,
						static function () use ( $tr_id ): string {
							$res = \function_exists( 'get_permalink' ) ? \get_permalink( $tr_id ) : '';
							return is_string( $res ) ? $res : '';
						}
					);
					if ( is_string( $link ) && '' !== $link ) {
						return $link;
					}
				}
			}
			return self::home_url( '/', $code );
		}

		if ( \function_exists( 'is_home' ) && \is_home() ) {
			return self::home_url( '/blog/', $code );
		}

		if ( \function_exists( 'is_search' ) && \is_search() ) {
			$base_search = self::home_url( '/', $code );
			$search_q    = \function_exists( 'get_search_query' ) ? \get_search_query( false ) : '';
			return \function_exists( 'add_query_arg' ) ? \add_query_arg( 's', $search_q, $base_search ) : $base_search;
		}

		return self::home_url( '/', $code );
	}

	/**
	 * Checks whether a term belongs to the given canonical language.
	 *
	 * When WPML's `$sitepress` is absent (such as unit tests), returns true so
	 * single-language callers behave unchanged.
	 *
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy name.
	 * @param string   $code     Canonical language code.
	 */
	public static function is_term_in_language( \WP_Term $term, string $taxonomy, string $code ): bool {
		$canonical = self::from_wpml( $code );
		$wpml_lang = self::to_wpml( $canonical );
		$sitepress = $GLOBALS['sitepress'] ?? null;

		if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_language_details' ) ) {
			$details = $sitepress->get_element_language_details( (int) $term->term_taxonomy_id, 'tax_' . $taxonomy );
			if ( is_object( $details ) && isset( $details->language_code ) && '' !== (string) $details->language_code ) {
				return (string) $details->language_code === $wpml_lang;
			}
			if ( 'product_cat' === $taxonomy && 1 === preg_match( '/-(zh|es|pt-br|de|fr|ar|id|vi)$/', (string) $term->slug ) ) {
				return false;
			}
			return self::SOURCE === $canonical;
		}

		return true;
	}

	/**
	 * Checks whether a post belongs to the given canonical language.
	 *
	 * When WPML's `$sitepress` is absent (such as unit tests), returns true so
	 * single-language callers behave unchanged.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @param string $code      Canonical language code.
	 */
	public static function is_post_in_language( int $post_id, string $post_type, string $code ): bool {
		$canonical = self::from_wpml( $code );
		$wpml_lang = self::to_wpml( $canonical );
		$sitepress = $GLOBALS['sitepress'] ?? null;

		if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_language_details' ) ) {
			$details = $sitepress->get_element_language_details( $post_id, 'post_' . $post_type );
			if ( is_object( $details ) && isset( $details->language_code ) && '' !== (string) $details->language_code ) {
				return (string) $details->language_code === $wpml_lang;
			}
			return self::SOURCE === $canonical;
		}

		return true;
	}

	/**
	 * Queries English source terms for a taxonomy, strictly excluding non-English translations.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, \WP_Term>
	 */
	public static function source_terms( string $taxonomy ): array {
		if ( ! \function_exists( 'get_terms' ) ) {
			return array();
		}

		$raw = self::with_language(
			self::SOURCE,
			static function () use ( $taxonomy ): array {
				$res = \get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				return is_array( $res ) ? $res : array();
			}
		);

		$filtered = array();
		foreach ( $raw as $term ) {
			if ( $term instanceof \WP_Term && self::is_term_in_language( $term, $taxonomy, self::SOURCE ) ) {
				$filtered[] = $term;
			}
		}

		return $filtered;
	}

	/**
	 * Queries published English source posts of a given type, strictly excluding non-English translations.
	 *
	 * @param string               $post_type  Post type slug.
	 * @param array<string, mixed> $extra_args Optional extra get_posts arguments.
	 * @return array<int, \WP_Post>
	 */
	public static function source_posts( string $post_type, array $extra_args = array() ): array {
		if ( ! \function_exists( 'get_posts' ) ) {
			return array();
		}

		$args = array_merge(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => false,
			),
			$extra_args
		);

		$raw = self::with_language(
			self::SOURCE,
			static function () use ( $args ): array {
				return (array) \get_posts( $args );
			}
		);

		$filtered = array();
		foreach ( $raw as $post ) {
			if ( $post instanceof \WP_Post && self::is_post_in_language( (int) $post->ID, $post_type, self::SOURCE ) ) {
				$filtered[] = $post;
			}
		}

		return $filtered;
	}
}
