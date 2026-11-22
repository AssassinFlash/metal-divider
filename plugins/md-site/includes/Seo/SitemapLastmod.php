<?php
/**
 * Real-change sitemap `<lastmod>` synchronization and taxonomy `<lastmod>` injection.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Presentation\Templates;

/**
 * Keeps sitemap `<lastmod>` timestamps grounded in real content/template changes
 * and adds missing `<lastmod>` elements to `product_cat-sitemap*.xml` and its
 * `sitemaps.xml` index entry.
 *
 * LiveCanvas stores page and pattern body markup in `lc_dynamic_template` posts,
 * while `wp_posts` `page` and `product` rows act as lightweight shells. Updating
 * a template via `wp md-site present` updates the template row's `post_modified`
 * but does not touch bound `page` or `product` rows, leaving SEOPress's
 * `page-sitemap*.xml` and `product-sitemap*.xml` frozen at stale timestamps.
 *
 * In addition, SEOPress's single-term sitemap template omits `<lastmod>` for
 * taxonomy terms and for taxonomy entries in `sitemaps.xml`.
 *
 * This class provides:
 * 1. An idempotent synchronizer (`sync_post_modified_from_templates()`) that
 *    strictly advances `post_modified` / `post_modified_gmt` on bound pages,
 *    the `/patterns/` shop page, and published `product` rows whenever their
 *    driving template (or product catalog for `/patterns/`) is strictly newer,
 *    never fabricates `now()`, never regresses newer timestamps, never touches
 *    `/blog/` or its translations, and clears SEOPress's cached lastmod
 *    transients so `sitemaps.xml` reflects updates immediately.
 * 2. Runtime SEOPress filters that compute `product_cat` `<lastmod>` from
 *    `max(latest published product in term, series-archive template)` in one
 *    batched query per request and inject valid W3C UTC `<lastmod>` nodes into
 *    `product_cat-sitemap*.xml` and `sitemaps.xml`.
 */
final class SitemapLastmod {

	/**
	 * LiveCanvas dynamic template post type.
	 */
	private const TEMPLATE_POST_TYPE = Templates::POST_TYPE;

	/**
	 * Per-page LiveCanvas template assignment meta key.
	 */
	private const PAGE_CHOICE_META = Templates::PAGE_CHOICE;

	/**
	 * LiveCanvas page-type assignment meta keys used for lastmod propagation.
	 */
	private const META_SINGLE_PRODUCT = 'is_single_product';
	private const META_SHOP_PAGE      = 'is_shop_page';
	private const META_SERIES_ARCHIVE = 'is_archive_for_tax_product_cat';

	/**
	 * SEOPress sitemap index lastmod transient option prefixes.
	 */
	private const TRANSIENT_PREFIX         = '_transient_seopress_sitemap_lastmod_all_';
	private const TRANSIENT_TIMEOUT_PREFIX = '_transient_timeout_seopress_sitemap_lastmod_all_';

	/**
	 * Per-page LiveCanvas template slug => English page slug.
	 *
	 * Mirrors `Presentation\Templates::PAGES` (private). Strictly excludes
	 * `/blog/` and every blog template.
	 *
	 * @var array<string, string>
	 */
	public const PAGE_TEMPLATE_SLUGS = array(
		'md-home'                       => 'home',
		'md-hub'                        => 'pattern-category',
		'md-family-laser-cut-geometric' => 'laser-cut-geometric-metal-screens',
		'md-family-glass-hybrid'        => 'glass-hybrid-screens',
		'md-family-outdoor-privacy'     => 'outdoor-privacy-landscape-screens',
		'md-family-chinese-style'       => 'chinese-style-artistic-screens',
		'md-family-stainless-steel'     => 'stainless-steel-screens',
		'md-family-laser-cut-panels'    => 'laser-cut-panels',
		'md-family-perforated-panels'   => 'perforated-panels',
		'md-family-expanded-metal'      => 'expanded-metal-panels',
		'md-manufacturing'              => 'manufacturing-process',
		'md-project-gallery'            => 'project-gallery',
		'md-about'                      => 'about-us',
		'md-faqs'                       => 'faqs',
		'md-custom-screens'             => 'custom-metal-divider-screens',
		'md-inquiry'                    => 'inquiry',
		'md-inquiry-thank-you'          => 'thank-you',
		'md-privacy'                    => 'privacy',
	);

	/**
	 * Per-request cache of `product_cat` term_id => latest published product `post_modified_gmt`.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $term_max_product_gmt = null;

	/**
	 * Per-request cache of the `series-archive` template `post_modified_gmt`.
	 *
	 * @var string|null
	 */
	private static ?string $series_archive_template_gmt = null;

	/**
	 * Registers runtime SEOPress sitemap `<lastmod>` filters.
	 *
	 * Index injection runs at priority 20, strictly after
	 * `SitemapDedupe::drop_empty_type_entries()` (priority 10).
	 */
	public function register(): void {
		\add_filter( 'seopress_sitemaps_term_single_url', array( $this, 'attach_term_lastmod' ), 10, 2 );
		\add_filter( 'seopress_sitemaps_url', array( $this, 'inject_url_lastmod' ), 10, 2 );
		\add_filter( 'seopress_sitemaps_xml_index', array( $this, 'inject_taxonomy_index_lastmod' ), 20 );
	}

	/**
	 * Clears per-request in-memory caches (for tests and post-sync freshness).
	 */
	public static function reset_cache(): void {
		self::$term_max_product_gmt        = null;
		self::$series_archive_template_gmt = null;
	}

	/**
	 * Whether `$candidate_gmt` is a valid UTC MySQL datetime strictly newer than `$current_gmt`.
	 *
	 * Returns false when `$candidate_gmt` is empty, zeroed (`0000-00-00 00:00:00`),
	 * malformed, equal to `$current_gmt`, or earlier than `$current_gmt`.
	 * Returns true when `$candidate_gmt` is valid and `$current_gmt` is empty,
	 * zeroed, malformed, or strictly earlier.
	 *
	 * @param string $current_gmt   Current `post_modified_gmt` value.
	 * @param string $candidate_gmt Candidate `post_modified_gmt` value.
	 * @return bool
	 */
	public static function should_advance_gmt( string $current_gmt, string $candidate_gmt ): bool {
		$candidate = self::parse_gmt( $candidate_gmt );
		if ( null === $candidate ) {
			return false;
		}

		$current = self::parse_gmt( $current_gmt );
		if ( null === $current ) {
			return true;
		}

		return $candidate > $current;
	}

	/**
	 * Formats a valid UTC MySQL datetime (`YYYY-MM-DD HH:MM:SS`) as W3C ISO-8601 UTC
	 * (`YYYY-MM-DDTHH:MM:SS+00:00`), or returns an empty string for invalid/empty inputs.
	 *
	 * Never fabricates a fallback timestamp such as `now()`.
	 *
	 * @param string $gmt UTC MySQL datetime string.
	 * @return string W3C ISO-8601 UTC string or empty string.
	 */
	public static function format_w3c_gmt( string $gmt ): string {
		$dt = self::parse_gmt( $gmt );
		if ( null === $dt ) {
			return '';
		}

		return $dt->format( 'Y-m-d\TH:i:s+00:00' );
	}

	/**
	 * Returns the latest valid UTC MySQL datetime among the given candidates, or `''`.
	 *
	 * @param string ...$candidates Candidate `post_modified_gmt` values.
	 * @return string Latest valid `YYYY-MM-DD HH:MM:SS` string or empty string.
	 */
	public static function max_gmt( string ...$candidates ): string {
		$best = '';
		foreach ( $candidates as $candidate ) {
			if ( self::should_advance_gmt( $best, $candidate ) ) {
				$best = trim( $candidate );
			}
		}

		return $best;
	}

	/**
	 * Idempotently advances `wp_posts.post_modified` / `post_modified_gmt` on bound
	 * `page` and `product` rows from their driving `lc_dynamic_template` timestamps,
	 * and invalidates SEOPress's cached sitemap lastmod transients.
	 *
	 * Order of operations guarantees strict idempotency on repeat runs:
	 * 1. Advance published `product` rows from `is_single_product` template in one SQL UPDATE.
	 * 2. Advance published non-blog `page` rows (and all WPML translations) from
	 *    their per-page template in `PAGE_TEMPLATE_SLUGS`, and advance `/patterns/`
	 *    (`woocommerce_shop_page_id` + translations) from `max(shop template, max product)`.
	 * 3. Explicitly exclude `/blog/` (`page_for_posts`, slug `blog`, and all their translations).
	 * 4. Clear SEOPress's `seopress_sitemap_lastmod_all_*` transients.
	 *
	 * @return array{pages_updated: int, products_updated: int, transients_cleared: int}
	 */
	public static function sync_post_modified_from_templates(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $wpdb instanceof \wpdb || '' === $wpdb->posts || '' === $wpdb->postmeta ) {
			return array(
				'pages_updated'      => 0,
				'products_updated'   => 0,
				'transients_cleared' => 0,
			);
		}

		$templates        = self::load_template_timestamps( $wpdb );
		$products_updated = self::sync_products_from_pdp_template( $wpdb, $templates['pdp'] );
		$pages_updated    = self::sync_pages_from_templates( $wpdb, $templates['by_slug'], $templates['shop'] );
		$transients       = self::clear_seopress_lastmod_transients();

		self::reset_cache();

		return array(
			'pages_updated'      => $pages_updated,
			'products_updated'   => $products_updated,
			'transients_cleared' => $transients,
		);
	}

	/**
	 * Deletes SEOPress's cached `seopress_sitemap_lastmod_all_*` transients so
	 * `sitemaps.xml` immediately reflects updated `post_modified` timestamps.
	 *
	 * @return int Number of transient entries cleared.
	 */
	public static function clear_seopress_lastmod_transients(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! $wpdb instanceof \wpdb || '' === $wpdb->options ) {
			return 0;
		}

		$like_value   = $wpdb->esc_like( self::TRANSIENT_PREFIX ) . '%';
		$like_timeout = $wpdb->esc_like( self::TRANSIENT_TIMEOUT_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discovering dynamic SEOPress lastmod transient keys for immediate invalidation.
		$option_names = $wpdb->get_col(
			(string) $wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$like_value
			)
		);

		$cleared    = 0;
		$prefix_len = strlen( '_transient_' );
		foreach ( $option_names as $option_name ) {
			if ( ! is_string( $option_name ) || ! str_starts_with( $option_name, self::TRANSIENT_PREFIX ) ) {
				continue;
			}
			$transient_key = substr( $option_name, $prefix_len );
			if ( '' === $transient_key ) {
				continue;
			}
			if ( \function_exists( 'delete_transient' ) && \delete_transient( $transient_key ) ) {
				++$cleared;
			}
		}

		// Clean any remaining value or orphaned timeout rows directly in wp_options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing any remaining or orphaned SEOPress sitemap lastmod transient rows.
		$deleted = $wpdb->query(
			(string) $wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$like_value,
				$like_timeout
			)
		);

		if ( 0 === $cleared && is_int( $deleted ) && $deleted > 0 ) {
			$cleared = $deleted;
		}

		return $cleared;
	}

	/**
	 * Computes and attaches W3C UTC `mod` for `product_cat` entries in SEOPress's
	 * single-term sitemap payload.
	 *
	 * Leaves non-`product_cat` taxonomies and excluded (`null`) payloads untouched.
	 * Never fabricates a timestamp when neither products nor the `series-archive`
	 * template have a valid modification date.
	 *
	 * @param array<string, mixed>|null $seopress_url Sitemap URL payload from SEOPress (null when excluded).
	 * @param mixed                     $term         Term object being rendered.
	 * @return array<string, mixed>|null
	 */
	public function attach_term_lastmod( ?array $seopress_url, mixed $term ): ?array {
		if ( null === $seopress_url ) {
			return null;
		}

		$taxonomy = '';
		$term_id  = 0;
		if ( $term instanceof \WP_Term ) {
			$taxonomy = (string) $term->taxonomy;
			$term_id  = (int) $term->term_id;
		} elseif ( is_object( $term ) && property_exists( $term, 'taxonomy' ) && property_exists( $term, 'term_id' ) ) {
			$taxonomy = is_scalar( $term->taxonomy ) ? (string) $term->taxonomy : '';
			$term_id  = is_numeric( $term->term_id ) ? (int) $term->term_id : 0;
		}

		if ( 'product_cat' !== $taxonomy || $term_id <= 0 ) {
			return $seopress_url;
		}

		$term_map     = self::load_product_cat_max_gmt();
		$term_gmt     = $term_map[ $term_id ] ?? '';
		$template_gmt = self::get_series_archive_template_gmt();
		$best_gmt     = self::max_gmt( $term_gmt, $template_gmt );

		$seopress_url['mod'] = self::format_w3c_gmt( $best_gmt );
		return $seopress_url;
	}

	/**
	 * Injects `<lastmod>` into a single `<url>...</url>` XML block when `$seopress_url['mod']`
	 * is non-empty and the block does not already carry a `<lastmod>` element.
	 *
	 * Post/page/product sitemaps already emit `<lastmod>` natively and pass through untouched.
	 * Accepts `mixed` inputs because SEOPress's `templates/sitemap/single.php` can pass `null`
	 * as `$seopress_url` on empty/excluded entries.
	 *
	 * @param mixed $xml          Assembled `<url>...</url>` XML fragment.
	 * @param mixed $seopress_url Sitemap URL payload from SEOPress (may be null).
	 * @return string Filtered `<url>...</url>` XML fragment.
	 */
	public function inject_url_lastmod( mixed $xml, mixed $seopress_url = array() ): string {
		if ( ! is_string( $xml ) ) {
			return '';
		}
		if ( ! is_array( $seopress_url ) ) {
			return $xml;
		}
		$mod = isset( $seopress_url['mod'] ) && is_string( $seopress_url['mod'] ) ? trim( $seopress_url['mod'] ) : '';
		if ( '' === $mod ) {
			return $xml;
		}

		if ( false !== stripos( $xml, '<lastmod>' ) ) {
			return $xml;
		}

		$pos = strrpos( $xml, '</url>' );
		if ( false === $pos ) {
			return $xml;
		}

		$safe_mod = \esc_html( $mod );
		return substr_replace( $xml, '<lastmod>' . $safe_mod . "</lastmod>\n</url>", $pos, strlen( '</url>' ) );
	}

	/**
	 * Injects `<lastmod>` into `product_cat-sitemap*.xml` entries in `sitemaps.xml`
	 * when they do not already contain one.
	 *
	 * @param string $xml The assembled `<sitemapindex>` XML document.
	 * @return string Filtered `<sitemapindex>` document.
	 */
	public function inject_taxonomy_index_lastmod( string $xml ): string {
		if ( ! str_contains( $xml, 'product_cat-sitemap' ) ) {
			return $xml;
		}

		$lastmod = self::product_cat_max_lastmod_w3c();
		if ( '' === $lastmod ) {
			return $xml;
		}

		$safe_mod = \esc_html( $lastmod );

		return (string) preg_replace_callback(
			'#(<sitemap>\s*<loc>[^<]*/product_cat-sitemap\d*\.xml</loc>\s*)(</sitemap>)#',
			static function ( array $matches ) use ( $safe_mod ): string {
				return $matches[1] . '<lastmod>' . $safe_mod . "</lastmod>\n" . $matches[2];
			},
			$xml
		);
	}

	/**
	 * Computes the overall maximum W3C UTC `<lastmod>` across all `product_cat`
	 * terms and the `series-archive` template.
	 *
	 * @return string W3C ISO-8601 UTC string or empty string when unavailable.
	 */
	public static function product_cat_max_lastmod_w3c(): string {
		$best_gmt = self::get_series_archive_template_gmt();
		foreach ( self::load_product_cat_max_gmt() as $gmt ) {
			$best_gmt = self::max_gmt( $best_gmt, $gmt );
		}

		return self::format_w3c_gmt( $best_gmt );
	}

	/**
	 * Parses a strict UTC MySQL datetime (`YYYY-MM-DD HH:MM:SS`) without rollover.
	 *
	 * @param string $gmt Raw datetime string.
	 * @return \DateTimeImmutable|null
	 */
	private static function parse_gmt( string $gmt ): ?\DateTimeImmutable {
		$trimmed = trim( $gmt );
		if ( '' === $trimmed || '0000-00-00 00:00:00' === $trimmed ) {
			return null;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $trimmed ) ) {
			return null;
		}

		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $trimmed, new \DateTimeZone( 'UTC' ) );
		if ( ! $dt instanceof \DateTimeImmutable || $dt->format( 'Y-m-d H:i:s' ) !== $trimmed ) {
			return null;
		}

		return $dt;
	}

	/**
	 * Converts a validated UTC MySQL datetime into site-local MySQL datetime for `post_modified`.
	 *
	 * @param string $gmt Validated UTC `YYYY-MM-DD HH:MM:SS` string.
	 * @return string Site-local `YYYY-MM-DD HH:MM:SS` string.
	 */
	private static function gmt_to_local( string $gmt ): string {
		if ( \function_exists( 'get_date_from_gmt' ) ) {
			$local = (string) \get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );
			if ( '' !== $local ) {
				return $local;
			}
		}

		return $gmt;
	}

	/**
	 * Reads published `lc_dynamic_template` timestamps indexed by slug and page-type meta key.
	 *
	 * @param \wpdb $wpdb Global WordPress database connection.
	 * @return array{by_slug: array<string, string>, pdp: string, shop: string, series_archive: string}
	 */
	private static function load_template_timestamps( \wpdb $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading LiveCanvas template timestamps for sitemap lastmod sync.
		$template_rows = $wpdb->get_results(
			(string) $wpdb->prepare(
				"SELECT ID, post_name, post_modified_gmt FROM %i WHERE post_type = %s AND post_status = 'publish'",
				$wpdb->posts,
				self::TEMPLATE_POST_TYPE
			),
			ARRAY_A
		);

		$by_id   = array();
		$by_slug = array();
		if ( is_array( $template_rows ) ) {
			foreach ( $template_rows as $row ) {
				$id   = isset( $row['ID'] ) && is_numeric( $row['ID'] ) ? (int) $row['ID'] : 0;
				$name = isset( $row['post_name'] ) && is_scalar( $row['post_name'] ) ? (string) $row['post_name'] : '';
				$gmt  = isset( $row['post_modified_gmt'] ) && is_scalar( $row['post_modified_gmt'] ) ? trim( (string) $row['post_modified_gmt'] ) : '';
				if ( $id <= 0 || null === self::parse_gmt( $gmt ) ) {
					continue;
				}
				$by_id[ $id ] = $gmt;
				if ( '' !== $name ) {
					$by_slug[ $name ] = self::max_gmt( $by_slug[ $name ] ?? '', $gmt );
				}
			}
		}

		$pdp            = '';
		$shop           = '';
		$series_archive = '';

		if ( array() !== $by_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolving LiveCanvas page-type assignment meta keys.
			$meta_rows = $wpdb->get_results(
				(string) $wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key
					FROM %i pm
					INNER JOIN %i p ON p.ID = pm.post_id
					WHERE p.post_type = %s
					  AND p.post_status = 'publish'
					  AND pm.meta_key IN (%s, %s, %s)
					  AND pm.meta_value = '1'",
					$wpdb->postmeta,
					$wpdb->posts,
					self::TEMPLATE_POST_TYPE,
					self::META_SINGLE_PRODUCT,
					self::META_SHOP_PAGE,
					self::META_SERIES_ARCHIVE
				),
				ARRAY_A
			);

			if ( is_array( $meta_rows ) ) {
				foreach ( $meta_rows as $row ) {
					$post_id  = isset( $row['post_id'] ) && is_numeric( $row['post_id'] ) ? (int) $row['post_id'] : 0;
					$meta_key = isset( $row['meta_key'] ) && is_scalar( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
					$gmt      = $by_id[ $post_id ] ?? '';
					if ( '' === $gmt ) {
						continue;
					}
					if ( self::META_SINGLE_PRODUCT === $meta_key ) {
						$pdp = self::max_gmt( $pdp, $gmt );
					} elseif ( self::META_SHOP_PAGE === $meta_key ) {
						$shop = self::max_gmt( $shop, $gmt );
					} elseif ( self::META_SERIES_ARCHIVE === $meta_key ) {
						$series_archive = self::max_gmt( $series_archive, $gmt );
					}
				}
			}
		}

		return array(
			'by_slug'        => $by_slug,
			'pdp'            => $pdp,
			'shop'           => $shop,
			'series_archive' => $series_archive,
		);
	}

	/**
	 * Advances all published `product` rows older than the `pdp` template in one SQL UPDATE.
	 *
	 * @param \wpdb  $wpdb             Global WordPress database connection.
	 * @param string $pdp_template_gmt Validated or empty `pdp` template `post_modified_gmt`.
	 * @return int Number of updated product rows.
	 */
	private static function sync_products_from_pdp_template( \wpdb $wpdb, string $pdp_template_gmt ): int {
		if ( null === self::parse_gmt( $pdp_template_gmt ) ) {
			return 0;
		}

		$pdp_local = self::gmt_to_local( $pdp_template_gmt );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single batched UPDATE avoids N+1 and save_post side effects across 1,314 published products.
		$affected = $wpdb->query(
			(string) $wpdb->prepare(
				"UPDATE %i
				SET post_modified = %s, post_modified_gmt = %s
				WHERE post_type = 'product'
				  AND post_status = 'publish'
				  AND post_modified_gmt < %s",
				$wpdb->posts,
				$pdp_local,
				$pdp_template_gmt,
				$pdp_template_gmt
			)
		);

		if ( is_int( $affected ) && $affected > 0 ) {
			if ( \function_exists( 'wp_cache_set_last_changed' ) ) {
				\wp_cache_set_last_changed( 'posts' );
			}
			return $affected;
		}

		return 0;
	}

	/**
	 * Advances published non-blog `page` rows (and all WPML translations) from their
	 * bound LiveCanvas templates, and `/patterns/` shop pages from `max(shop template, max product)`.
	 *
	 * @param \wpdb                 $wpdb              Global WordPress database connection.
	 * @param array<string, string> $template_by_slug  Template post_name => `post_modified_gmt`.
	 * @param string                $shop_template_gmt `shop` template `post_modified_gmt`.
	 * @return int Number of updated page rows.
	 */
	private static function sync_pages_from_templates( \wpdb $wpdb, array $template_by_slug, string $shop_template_gmt ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Loading published pages for idempotent lastmod sync.
		$page_rows = $wpdb->get_results(
			(string) $wpdb->prepare(
				"SELECT ID, post_name, post_modified_gmt FROM %i WHERE post_type = 'page' AND post_status = 'publish'",
				$wpdb->posts
			),
			ARRAY_A
		);

		if ( ! is_array( $page_rows ) || array() === $page_rows ) {
			return 0;
		}

		$pages_by_id = array();
		foreach ( $page_rows as $row ) {
			$id = isset( $row['ID'] ) && is_numeric( $row['ID'] ) ? (int) $row['ID'] : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$pages_by_id[ $id ] = array(
				'post_name'         => isset( $row['post_name'] ) && is_scalar( $row['post_name'] ) ? (string) $row['post_name'] : '',
				'post_modified_gmt' => isset( $row['post_modified_gmt'] ) && is_scalar( $row['post_modified_gmt'] ) ? trim( (string) $row['post_modified_gmt'] ) : '',
			);
		}

		if ( array() === $pages_by_id ) {
			return 0;
		}

		$translation_cache = array();
		$excluded_ids      = self::collect_excluded_blog_page_ids( $pages_by_id, $translation_cache );
		$page_targets      = array();

		// 1. Bind pages carrying `lc_use_template_of_slug` for known non-blog templates (+ their translations).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading LiveCanvas per-page template assignments in one query.
		$choice_rows = $wpdb->get_results(
			(string) $wpdb->prepare(
				'SELECT post_id, meta_value FROM %i WHERE meta_key = %s',
				$wpdb->postmeta,
				self::PAGE_CHOICE_META
			),
			ARRAY_A
		);

		if ( is_array( $choice_rows ) ) {
			foreach ( $choice_rows as $row ) {
				$post_id       = isset( $row['post_id'] ) && is_numeric( $row['post_id'] ) ? (int) $row['post_id'] : 0;
				$template_slug = isset( $row['meta_value'] ) && is_scalar( $row['meta_value'] ) ? trim( (string) $row['meta_value'] ) : '';
				if ( $post_id <= 0 || ! isset( $pages_by_id[ $post_id ] ) || ! isset( self::PAGE_TEMPLATE_SLUGS[ $template_slug ] ) ) {
					continue;
				}
				$template_gmt = $template_by_slug[ $template_slug ] ?? '';
				if ( '' === $template_gmt ) {
					continue;
				}
				foreach ( self::page_translation_ids( $post_id, $translation_cache ) as $pid ) {
					if ( ! isset( $pages_by_id[ $pid ] ) || isset( $excluded_ids[ $pid ] ) ) {
						continue;
					}
					$page_targets[ $pid ] = self::max_gmt( $page_targets[ $pid ] ?? '', $template_gmt );
				}
			}
		}

		// 2. Bind pages matching canonical English page slugs in PAGE_TEMPLATE_SLUGS (+ their translations).
		foreach ( self::PAGE_TEMPLATE_SLUGS as $template_slug => $en_page_slug ) {
			$template_gmt = $template_by_slug[ $template_slug ] ?? '';
			if ( '' === $template_gmt ) {
				continue;
			}
			foreach ( $pages_by_id as $page_id => $page_info ) {
				if ( $page_info['post_name'] !== $en_page_slug ) {
					continue;
				}
				foreach ( self::page_translation_ids( $page_id, $translation_cache ) as $pid ) {
					if ( ! isset( $pages_by_id[ $pid ] ) || isset( $excluded_ids[ $pid ] ) ) {
						continue;
					}
					$page_targets[ $pid ] = self::max_gmt( $page_targets[ $pid ] ?? '', $template_gmt );
				}
			}
		}

		// 3. Bind `/patterns/` shop page (+ all translations) to max(shop template, latest published product).
		$shop_page_id = \function_exists( 'get_option' ) ? (int) \get_option( 'woocommerce_shop_page_id', 0 ) : 0;
		if ( $shop_page_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading latest published product timestamp for shop page lastmod.
			$max_product_raw = $wpdb->get_var(
				(string) $wpdb->prepare(
					"SELECT MAX(post_modified_gmt) FROM %i WHERE post_type = 'product' AND post_status = 'publish'",
					$wpdb->posts
				)
			);
			$max_product_gmt = is_scalar( $max_product_raw ) ? trim( (string) $max_product_raw ) : '';
			$shop_target_gmt = self::max_gmt( $shop_template_gmt, $max_product_gmt );
			if ( '' !== $shop_target_gmt ) {
				foreach ( self::page_translation_ids( $shop_page_id, $translation_cache ) as $pid ) {
					if ( ! isset( $pages_by_id[ $pid ] ) || isset( $excluded_ids[ $pid ] ) ) {
						continue;
					}
					$page_targets[ $pid ] = self::max_gmt( $page_targets[ $pid ] ?? '', $shop_target_gmt );
				}
			}
		}

		// 4. Apply direct SQL updates only where target is strictly later; never touch `/blog/`.
		$pages_updated = 0;
		foreach ( $page_targets as $page_id => $target_gmt ) {
			if ( isset( $excluded_ids[ $page_id ] ) || 'blog' === strtolower( $pages_by_id[ $page_id ]['post_name'] ) ) {
				continue;
			}
			$current_gmt = $pages_by_id[ $page_id ]['post_modified_gmt'];
			if ( ! self::should_advance_gmt( $current_gmt, $target_gmt ) ) {
				continue;
			}

			$local_date = self::gmt_to_local( $target_gmt );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating post_modified directly to preserve exact template timestamp without save_post hooks or now() drift.
			$updated = $wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => $local_date,
					'post_modified_gmt' => $target_gmt,
				),
				array( 'ID' => $page_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( is_int( $updated ) && $updated > 0 ) {
				++$pages_updated;
				if ( \function_exists( 'clean_post_cache' ) ) {
					\clean_post_cache( $page_id );
				}
			}
		}

		return $pages_updated;
	}

	/**
	 * Collects all `/blog/` page IDs (`page_for_posts`, slug `blog`, and every WPML
	 * translation thereof) so sync never modifies any blog page.
	 *
	 * @param array<int, array{post_name: string, post_modified_gmt: string}> $pages_by_id       Published pages map.
	 * @param array<int, list<int>>                                           $translation_cache Per-sync WPML ID cache (by reference).
	 * @return array<int, true> Set of excluded page IDs.
	 */
	private static function collect_excluded_blog_page_ids( array $pages_by_id, array &$translation_cache ): array {
		$excluded     = array();
		$blog_page_id = \function_exists( 'get_option' ) ? (int) \get_option( 'page_for_posts', 0 ) : 0;

		if ( $blog_page_id > 0 ) {
			foreach ( self::page_translation_ids( $blog_page_id, $translation_cache ) as $pid ) {
				$excluded[ $pid ] = true;
			}
		}

		foreach ( $pages_by_id as $page_id => $page_info ) {
			if ( 'blog' !== strtolower( $page_info['post_name'] ) ) {
				continue;
			}
			foreach ( self::page_translation_ids( $page_id, $translation_cache ) as $pid ) {
				$excluded[ $pid ] = true;
			}
		}

		return $excluded;
	}

	/**
	 * Resolves a page ID and all of its WPML translation IDs across all 9 languages.
	 *
	 * @param int                   $post_id           Page post ID.
	 * @param array<int, list<int>> $translation_cache Per-sync cache (by reference).
	 * @return list<int> Unique positive page IDs.
	 */
	private static function page_translation_ids( int $post_id, array &$translation_cache ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		if ( isset( $translation_cache[ $post_id ] ) ) {
			return $translation_cache[ $post_id ];
		}

		$ids = array( $post_id => true );
		if ( \function_exists( 'has_filter' ) && \has_filter( 'wpml_object_id' ) ) {
			foreach ( Languages::ALL as $code ) {
				$wpml_lang = Languages::to_wpml( $code );
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
				$translated = \apply_filters( 'wpml_object_id', $post_id, 'page', false, $wpml_lang );
				if ( is_numeric( $translated ) && (int) $translated > 0 ) {
					$ids[ (int) $translated ] = true;
				}
			}
		}

		$resolved = array_keys( $ids );
		foreach ( $resolved as $id ) {
			$translation_cache[ $id ] = $resolved;
		}

		return $resolved;
	}

	/**
	 * Loads and caches `product_cat` term_id => latest published product `post_modified_gmt`
	 * across all languages in a single grouped SQL query.
	 *
	 * @return array<int, string>
	 */
	private static function load_product_cat_max_gmt(): array {
		if ( null !== self::$term_max_product_gmt ) {
			return self::$term_max_product_gmt;
		}

		self::$term_max_product_gmt = array();

		global $wpdb;
		if (
			! isset( $wpdb ) ||
			! $wpdb instanceof \wpdb ||
			'' === $wpdb->posts ||
			'' === $wpdb->term_relationships ||
			'' === $wpdb->term_taxonomy
		) {
			return self::$term_max_product_gmt;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single batched GROUP BY query for all 81 product_cat terms, memoized per request.
		$rows = $wpdb->get_results(
			(string) $wpdb->prepare(
				"SELECT tt.term_id, MAX(p.post_modified_gmt) AS last_mod_gmt
				FROM %i tt
				INNER JOIN %i tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN %i p ON p.ID = tr.object_id
				WHERE tt.taxonomy = 'product_cat'
				  AND p.post_type = 'product'
				  AND p.post_status = 'publish'
				GROUP BY tt.term_id",
				$wpdb->term_taxonomy,
				$wpdb->term_relationships,
				$wpdb->posts
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$term_id = isset( $row['term_id'] ) && is_numeric( $row['term_id'] ) ? (int) $row['term_id'] : 0;
				$gmt     = isset( $row['last_mod_gmt'] ) && is_scalar( $row['last_mod_gmt'] ) ? trim( (string) $row['last_mod_gmt'] ) : '';
				if ( $term_id > 0 && null !== self::parse_gmt( $gmt ) ) {
					self::$term_max_product_gmt[ $term_id ] = $gmt;
				}
			}
		}

		return self::$term_max_product_gmt;
	}

	/**
	 * Loads and caches the published `series-archive` LiveCanvas template `post_modified_gmt`.
	 *
	 * @return string Validated UTC `YYYY-MM-DD HH:MM:SS` or empty string.
	 */
	private static function get_series_archive_template_gmt(): string {
		if ( null !== self::$series_archive_template_gmt ) {
			return self::$series_archive_template_gmt;
		}

		self::$series_archive_template_gmt = '';

		global $wpdb;
		if ( ! isset( $wpdb ) || ! $wpdb instanceof \wpdb || '' === $wpdb->posts || '' === $wpdb->postmeta ) {
			return self::$series_archive_template_gmt;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lookup of series-archive template timestamp, memoized per request.
		$raw = $wpdb->get_var(
			(string) $wpdb->prepare(
				"SELECT MAX(p.post_modified_gmt)
				FROM %i p
				INNER JOIN %i pm ON pm.post_id = p.ID
				WHERE p.post_type = %s
				  AND p.post_status = 'publish'
				  AND pm.meta_key = %s
				  AND pm.meta_value = '1'",
				$wpdb->posts,
				$wpdb->postmeta,
				self::TEMPLATE_POST_TYPE,
				self::META_SERIES_ARCHIVE
			)
		);

		$gmt = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( null !== self::parse_gmt( $gmt ) ) {
			self::$series_archive_template_gmt = $gmt;
		}

		return self::$series_archive_template_gmt;
	}
}
