<?php
/**
 * Tests for real-change sitemap `<lastmod>` synchronization and taxonomy `<lastmod>` injection.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Seo\SitemapLastmod;
use MetalDivider\Site\Tests\TestCase;
use Mockery;
use WP_Mock;

/**
 * Guards `SitemapLastmod` pure helpers, runtime `product_cat` / index XML filters,
 * idempotent `post_modified` propagation, SEOPress transient clearing, and `/blog/` exclusion.
 */
final class SitemapLastmodTest extends TestCase {

	/**
	 * Resets static caches and global `$wpdb` before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		SitemapLastmod::reset_cache();
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Cleans up static caches and global `$wpdb` after each test.
	 */
	public function tearDown(): void {
		SitemapLastmod::reset_cache();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * `should_advance_gmt()` strictly advances only on valid, newer UTC timestamps.
	 */
	public function test_should_advance_gmt_boundaries(): void {
		// Strictly newer valid candidate advances.
		$this->assertTrue(
			SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', '2026-11-21 18:53:02' )
		);

		// Equal timestamps must NOT advance (idempotency).
		$this->assertFalse(
			SitemapLastmod::should_advance_gmt( '2026-11-21 18:53:02', '2026-11-21 18:53:02' )
		);

		// Older candidate must NOT regress a newer timestamp.
		$this->assertFalse(
			SitemapLastmod::should_advance_gmt( '2026-11-22 10:00:00', '2026-11-21 18:53:02' )
		);

		// Empty, zeroed, malformed, or calendar-invalid candidates never advance.
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', '' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', '   ' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', '0000-00-00 00:00:00' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', 'not-a-date' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '2026-11-11 01:31:29', '2026-02-30 12:00:00' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '', '' ) );
		$this->assertFalse( SitemapLastmod::should_advance_gmt( '0000-00-00 00:00:00', '0000-00-00 00:00:00' ) );

		// Valid candidate advances empty or zeroed current timestamp.
		$this->assertTrue( SitemapLastmod::should_advance_gmt( '', '2026-11-21 18:53:02' ) );
		$this->assertTrue( SitemapLastmod::should_advance_gmt( '0000-00-00 00:00:00', '2026-11-21 18:53:02' ) );
	}

	/**
	 * `format_w3c_gmt()` emits W3C ISO-8601 UTC strings and refuses invalid/zero inputs.
	 */
	public function test_format_w3c_gmt_boundaries(): void {
		$this->assertSame(
			'2026-11-21T18:53:02+00:00',
			SitemapLastmod::format_w3c_gmt( '2026-11-21 18:53:02' )
		);
		$this->assertSame(
			'2026-11-21T18:53:02+00:00',
			SitemapLastmod::format_w3c_gmt( '  2026-11-21 18:53:02  ' )
		);
		$this->assertSame( '', SitemapLastmod::format_w3c_gmt( '' ) );
		$this->assertSame( '', SitemapLastmod::format_w3c_gmt( '0000-00-00 00:00:00' ) );
		$this->assertSame( '', SitemapLastmod::format_w3c_gmt( 'not-a-date' ) );
		$this->assertSame( '', SitemapLastmod::format_w3c_gmt( '2026-02-30 12:00:00' ) );
	}

	/**
	 * `max_gmt()` returns the latest valid UTC datetime or empty string.
	 */
	public function test_max_gmt_picks_latest_valid_timestamp(): void {
		$this->assertSame(
			'2026-11-21 18:53:02',
			SitemapLastmod::max_gmt( '', '0000-00-00 00:00:00', '2026-11-11 01:31:29', '2026-11-21 18:53:02', '2026-11-15 00:00:00' )
		);
		$this->assertSame( '', SitemapLastmod::max_gmt( '', '0000-00-00 00:00:00', 'invalid' ) );
	}

	/**
	 * `register()` attaches all three SEOPress filters at the expected priorities.
	 */
	public function test_register_attaches_seopress_filters(): void {
		$rule = new SitemapLastmod();

		WP_Mock::expectFilterAdded( 'seopress_sitemaps_term_single_url', array( $rule, 'attach_term_lastmod' ), 10, 2 );
		WP_Mock::expectFilterAdded( 'seopress_sitemaps_url', array( $rule, 'inject_url_lastmod' ), 10, 2 );
		WP_Mock::expectFilterAdded( 'seopress_sitemaps_xml_index', array( $rule, 'inject_taxonomy_index_lastmod' ), 20 );

		$rule->register();

		$this->addToAssertionCount( 3 );
	}

	/**
	 * `PAGE_TEMPLATE_SLUGS` stays aligned with `Presentation\Templates::PAGES` and never contains blog slugs.
	 */
	public function test_page_template_slugs_match_presentation_templates_and_exclude_blog(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$pages      = $reflection->getConstant( 'PAGES' );

		$this->assertIsArray( $pages );

		$expected = array();
		foreach ( $pages as $template_slug => $spec ) {
			$this->assertIsString( $template_slug );
			$this->assertIsArray( $spec );
			$expected[ $template_slug ] = (string) $spec[2];
		}

		$this->assertSame( $expected, SitemapLastmod::PAGE_TEMPLATE_SLUGS );
		$this->assertNotContains( 'blog', SitemapLastmod::PAGE_TEMPLATE_SLUGS );
		$this->assertArrayNotHasKey( 'md-blog', SitemapLastmod::PAGE_TEMPLATE_SLUGS );
		$this->assertArrayNotHasKey( 'blog-index', SitemapLastmod::PAGE_TEMPLATE_SLUGS );
	}

	/**
	 * `attach_term_lastmod()` picks max(term latest product, series-archive template) in one batched query.
	 */
	public function test_attach_term_lastmod_picks_max_of_term_products_and_series_archive_template(): void {
		$wpdb = $this->base_wpdb();
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'term_id'      => '11',
						'last_mod_gmt' => '2026-11-22 09:15:00',
					),
					array(
						'term_id'      => '12',
						'last_mod_gmt' => '2026-11-11 01:31:29',
					),
				)
			);
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '2026-11-21 18:53:02' );

		$GLOBALS['wpdb'] = $wpdb;

		$rule = new SitemapLastmod();

		// Term 11: latest product (11-22) is newer than series-archive template (11-21).
		$term_newer_product           = new \WP_Term( new \stdClass() );
		$term_newer_product->term_id  = 11;
		$term_newer_product->taxonomy = 'product_cat';

		$res1 = $rule->attach_term_lastmod(
			array(
				'loc'    => 'https://metal-divider.com/collections/geometric/',
				'mod'    => '',
				'images' => array(),
			),
			$term_newer_product
		);
		$this->assertIsArray( $res1 );
		$this->assertSame( '2026-11-22T09:15:00+00:00', $res1['mod'] );

		// Term 12: series-archive template (11-21) is newer than term product (11-11).
		// Also verifies stdClass term support and per-request memoization (get_results/get_var called once).
		$term_older_product           = new \stdClass();
		$term_older_product->term_id  = 12;
		$term_older_product->taxonomy = 'product_cat';

		$res2 = $rule->attach_term_lastmod(
			array(
				'loc'    => 'https://metal-divider.com/collections/nature/',
				'mod'    => '',
				'images' => array(),
			),
			$term_older_product
		);
		$this->assertIsArray( $res2 );
		$this->assertSame( '2026-11-21T18:53:02+00:00', $res2['mod'] );
	}

	/**
	 * `attach_term_lastmod()` does not interfere with non-`product_cat` taxonomies or null payloads.
	 */
	public function test_attach_term_lastmod_ignores_other_taxonomies_and_null_payloads(): void {
		$wpdb = $this->base_wpdb();
		$wpdb->shouldNotReceive( 'get_results' );
		$wpdb->shouldNotReceive( 'get_var' );
		$GLOBALS['wpdb'] = $wpdb;

		$rule = new SitemapLastmod();

		$category           = new \WP_Term( new \stdClass() );
		$category->term_id  = 5;
		$category->taxonomy = 'category';

		$input = array(
			'loc'    => 'https://metal-divider.com/category/guides/',
			'mod'    => '',
			'images' => array(),
		);
		$this->assertSame( $input, $rule->attach_term_lastmod( $input, $category ) );

		$product_cat           = new \WP_Term( new \stdClass() );
		$product_cat->term_id  = 11;
		$product_cat->taxonomy = 'product_cat';
		$this->assertNull( $rule->attach_term_lastmod( null, $product_cat ) );
	}

	/**
	 * `attach_term_lastmod()` never fabricates a timestamp when both product and template dates are absent.
	 */
	public function test_attach_term_lastmod_never_fabricates_when_no_timestamps_exist(): void {
		$wpdb = $this->base_wpdb();
		$wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );
		$GLOBALS['wpdb'] = $wpdb;

		$term           = new \WP_Term( new \stdClass() );
		$term->term_id  = 99;
		$term->taxonomy = 'product_cat';

		$res = ( new SitemapLastmod() )->attach_term_lastmod(
			array(
				'loc'    => 'https://metal-divider.com/collections/empty/',
				'mod'    => '',
				'images' => array(),
			),
			$term
		);

		$this->assertIsArray( $res );
		$this->assertSame( '', $res['mod'], 'must stay empty rather than inventing now()' );
	}

	/**
	 * `inject_url_lastmod()` inserts valid XML `<lastmod>` for term blocks and never duplicates existing ones.
	 */
	public function test_inject_url_lastmod_injects_only_when_missing(): void {
		$this->plain_text();
		$rule = new SitemapLastmod();

		// Term URL block without <lastmod> (exact SEOPress template-xml-sitemaps-single-term.php shape).
		$term_xml = "\n<url>\n<loc>https://metal-divider.com/collections/geometric/</loc>\n</url>";
		$injected = $rule->inject_url_lastmod(
			$term_xml,
			array(
				'loc' => 'https://metal-divider.com/collections/geometric/',
				'mod' => '2026-11-21T18:53:02+00:00',
			)
		);

		$this->assertStringContainsString( '<lastmod>2026-11-21T18:53:02+00:00</lastmod>', $injected );
		$this->assertSame( 1, substr_count( $injected, '<lastmod>' ) );

		$parsed = simplexml_load_string( trim( $injected ) );
		$this->assertNotFalse( $parsed, 'injected <url> fragment must be valid XML' );
		$this->assertSame( '2026-11-21T18:53:02+00:00', (string) $parsed->lastmod );

		// Post/page URL block that ALREADY contains <lastmod>: must pass through unchanged.
		$post_xml = "\n<url>\n<loc>https://metal-divider.com/pattern/aurora/</loc>\n<lastmod>2026-11-21T18:53:02+00:00</lastmod></url>";
		$kept     = $rule->inject_url_lastmod(
			$post_xml,
			array(
				'loc' => 'https://metal-divider.com/pattern/aurora/',
				'mod' => '2026-11-21T18:53:02+00:00',
			)
		);
		$this->assertSame( $post_xml, $kept, 'existing <lastmod> must never be duplicated' );
		$this->assertSame( 1, substr_count( $kept, '<lastmod>' ) );

		// Empty mod or dropped archive link: unchanged.
		$this->assertSame( $term_xml, $rule->inject_url_lastmod( $term_xml, array( 'mod' => '' ) ) );
		$this->assertSame( '', $rule->inject_url_lastmod( '', array( 'mod' => '2026-11-21T18:53:02+00:00' ) ) );
	}

	/**
	 * `inject_taxonomy_index_lastmod()` adds `<lastmod>` to `product_cat-sitemap1.xml` idempotently.
	 */
	public function test_inject_taxonomy_index_lastmod_injects_and_is_idempotent(): void {
		$this->plain_text();

		$wpdb = $this->base_wpdb();
		$wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn(
				array(
					array(
						'term_id'      => '11',
						'last_mod_gmt' => '2026-11-20 08:00:00',
					),
					array(
						'term_id'      => '12',
						'last_mod_gmt' => '2026-11-22 14:30:00',
					),
				)
			);
		$wpdb->shouldReceive( 'get_var' )
			->once()
			->andReturn( '2026-11-21 18:53:02' );
		$GLOBALS['wpdb'] = $wpdb;

		$index = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<sitemapindex>' . "\n"
			. '<sitemap>' . "\n"
			. '<loc>https://metal-divider.com/page-sitemap1.xml</loc>' . "\n"
			. '<lastmod>2026-11-21T18:53:02+00:00</lastmod>' . "\n"
			. '</sitemap>' . "\n"
			. '<sitemap>' . "\n"
			. '<loc>https://metal-divider.com/product_cat-sitemap1.xml</loc>' . "\n"
			. '</sitemap>' . "\n"
			. '</sitemapindex>';

		$rule  = new SitemapLastmod();
		$first = $rule->inject_taxonomy_index_lastmod( $index );

		$parsed = simplexml_load_string( $first );
		$this->assertNotFalse( $parsed, 'sitemapindex XML must stay valid after injection' );
		$this->assertSame( '2026-11-21T18:53:02+00:00', (string) $parsed->sitemap[0]->lastmod );
		$this->assertSame( '2026-11-22T14:30:00+00:00', (string) $parsed->sitemap[1]->lastmod );

		// Running a second time must be a strict no-op (no duplicate <lastmod>).
		$second = $rule->inject_taxonomy_index_lastmod( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 2, substr_count( $second, '<lastmod>' ) );
	}

	/**
	 * `inject_taxonomy_index_lastmod()` skips DB queries when `product_cat-sitemap` is absent.
	 */
	public function test_inject_taxonomy_index_lastmod_short_circuits_when_absent(): void {
		$wpdb = $this->base_wpdb();
		$wpdb->shouldNotReceive( 'get_results' );
		$wpdb->shouldNotReceive( 'get_var' );
		$GLOBALS['wpdb'] = $wpdb;

		$index = '<sitemapindex><sitemap><loc>https://site.test/page-sitemap1.xml</loc></sitemap></sitemapindex>';
		$this->assertSame( $index, ( new SitemapLastmod() )->inject_taxonomy_index_lastmod( $index ) );
	}

	/**
	 * `sync_post_modified_from_templates()` advances pages, shop, and products, clears
	 * SEOPress transients, never touches `/blog/` or its translations, and is 100% idempotent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_post_modified_from_templates_is_idempotent_and_never_touches_blog(): void {
		$state = new \stdClass();

		// Templates in wp_posts + page-type meta.
		$state->templates = array(
			array(
				'ID'                => 100,
				'post_name'         => 'md-home',
				'post_modified_gmt' => '2026-11-21 18:53:02',
			),
			array(
				'ID'                => 101,
				'post_name'         => 'md-project-gallery',
				'post_modified_gmt' => '2026-11-21 18:53:02',
			),
			array(
				'ID'                => 102,
				'post_name'         => 'pdp',
				'post_modified_gmt' => '2026-11-21 18:53:02',
			),
			array(
				'ID'                => 103,
				'post_name'         => 'shop',
				'post_modified_gmt' => '2026-11-18 10:00:00',
			),
		);

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture rows mirroring wp_postmeta column names.
		$state->template_meta = array(
			array(
				'post_id'  => 102,
				'meta_key' => 'is_single_product',
			),
			array(
				'post_id'  => 103,
				'meta_key' => 'is_shop_page',
			),
		);

		// Published pages across EN and translations, including shop, newer page, and blog pages.
		$state->pages = array(
			// Home EN (meta + slug) and ZH (translation only, no meta).
			10   => array(
				'ID'                => 10,
				'post_name'         => 'home',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			11   => array(
				'ID'                => 11,
				'post_name'         => 'shou-ye',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			// Project gallery EN (already newer than template -> must NOT regress).
			20   => array(
				'ID'                => 20,
				'post_name'         => 'project-gallery',
				'post_modified_gmt' => '2026-11-22 05:00:00',
			),
			// Project gallery ES (older -> must advance to 11-21).
			21   => array(
				'ID'                => 21,
				'post_name'         => 'galeria-de-proyectos',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			// Shop page EN + ZH -> must advance to max(shop template 11-18, max product 11-22) = 11-22!
			6    => array(
				'ID'                => 6,
				'post_name'         => 'patterns',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			60   => array(
				'ID'                => 60,
				'post_name'         => 'patterns-zh',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			// Blog EN (`page_for_posts = 1542`) + Blog ZH (`1543`, localized slug) — MUST NEVER BE TOUCHED!
			// Even if misconfigured with `lc_use_template_of_slug = md-home`, blog must be strictly skipped.
			1542 => array(
				'ID'                => 1542,
				'post_name'         => 'blog',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
			1543 => array(
				'ID'                => 1543,
				'post_name'         => 'bo-ke',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
		);

		$state->page_choices = array(
			array(
				'post_id'    => 10,
				'meta_value' => 'md-home',
			),
			array(
				'post_id'    => 20,
				'meta_value' => 'md-project-gallery',
			),
			array(
				'post_id'    => 21,
				'meta_value' => 'md-project-gallery',
			),
			// Deliberate adversarial trap: blog page carrying md-home meta.
			array(
				'post_id'    => 1542,
				'meta_value' => 'md-home',
			),
			array(
				'post_id'    => 1543,
				'meta_value' => 'md-home',
			),
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		// Published products: 2 stale (11-11) + 1 newer (11-22 08:00:00).
		$state->products = array(
			201 => '2026-11-11 01:31:29',
			202 => '2026-11-11 01:31:29',
			203 => '2026-11-22 08:00:00',
		);

		$state->transients = array(
			'_transient_seopress_sitemap_lastmod_all_page_1000',
			'_transient_seopress_sitemap_lastmod_all_product_1000',
		);

		$state->updated_page_ids = array();
		$state->cleaned_post_ids = array();
		$state->deleted_keys     = array();

		$wpdb = $this->stateful_sync_wpdb( $state );

		$GLOBALS['wpdb'] = $wpdb;

		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( string $key, mixed $default_value = false ): mixed {
				if ( 'page_for_posts' === $key ) {
					return 1542;
				}
				if ( 'woocommerce_shop_page_id' === $key ) {
					return 6;
				}
				return $default_value;
			}
		);

		WP_Mock::userFunction( 'get_date_from_gmt' )->andReturnUsing(
			static function ( string $gmt ): string {
				$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $gmt, new \DateTimeZone( 'UTC' ) );
				return $dt instanceof \DateTimeImmutable ? $dt->modify( '+8 hours' )->format( 'Y-m-d H:i:s' ) : $gmt;
			}
		);

		WP_Mock::userFunction( 'clean_post_cache' )->andReturnUsing(
			static function ( int $post_id ) use ( $state ): void {
				$state->cleaned_post_ids[] = $post_id;
			}
		);

		WP_Mock::userFunction( 'wp_cache_set_last_changed' )->andReturn( '1' );

		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			static function ( string $key ) use ( $state ): bool {
				$opt = '_transient_' . $key;
				$idx = array_search( $opt, $state->transients, true );
				if ( false === $idx ) {
					return false;
				}
				unset( $state->transients[ $idx ] );
				$state->transients     = array_values( $state->transients );
				$state->deleted_keys[] = $key;
				return true;
			}
		);

		// WPML translation map: home (10 <-> 11), project-gallery (20 <-> 21), shop (6 <-> 60), blog (1542 <-> 1543).
		$this->mock_wpml_page_translations(
			array( 6, 10, 11, 20, 21, 60, 1542, 1543 ),
			array(
				10   => array(
					'en'      => 10,
					'zh-hans' => 11,
				),
				11   => array(
					'en'      => 10,
					'zh-hans' => 11,
				),
				20   => array(
					'en' => 20,
					'es' => 21,
				),
				21   => array(
					'en' => 20,
					'es' => 21,
				),
				6    => array(
					'en'      => 6,
					'zh-hans' => 60,
				),
				60   => array(
					'en'      => 6,
					'zh-hans' => 60,
				),
				1542 => array(
					'en'      => 1542,
					'zh-hans' => 1543,
				),
				1543 => array(
					'en'      => 1542,
					'zh-hans' => 1543,
				),
			)
		);

		// --- Run 1: must advance stale pages/shop/products and clear transients ---
		$first = SitemapLastmod::sync_post_modified_from_templates();

		$this->assertSame( 2, $first['products_updated'], 'only the 2 stale products older than pdp template advance' );
		$this->assertSame( 5, $first['pages_updated'], 'home EN/ZH, project-gallery ES, and shop EN/ZH advance' );
		$this->assertSame( 2, $first['transients_cleared'], 'both SEOPress lastmod transients are cleared' );

		// Products: stale advanced to 11-21, newer preserved at 11-22.
		$this->assertSame( '2026-11-21 18:53:02', $state->products[201] );
		$this->assertSame( '2026-11-21 18:53:02', $state->products[202] );
		$this->assertSame( '2026-11-22 08:00:00', $state->products[203] );

		// Pages: advanced cleanly (both UTC post_modified_gmt and local post_modified via get_date_from_gmt),
		// newer page untouched, shop picks max(shop tpl, max product).
		$this->assertSame( '2026-11-21 18:53:02', $state->pages[10]['post_modified_gmt'] );
		$this->assertSame( '2026-11-22 02:53:02', $state->pages[10]['post_modified'] );
		$this->assertSame( '2026-11-21 18:53:02', $state->pages[11]['post_modified_gmt'] );
		$this->assertSame( '2026-11-22 05:00:00', $state->pages[20]['post_modified_gmt'], 'newer page must not regress' );
		$this->assertSame( '2026-11-21 18:53:02', $state->pages[21]['post_modified_gmt'] );
		$this->assertSame( '2026-11-22 08:00:00', $state->pages[6]['post_modified_gmt'], 'shop page reflects newer product catalog' );
		$this->assertSame( '2026-11-22 16:00:00', $state->pages[6]['post_modified'] );
		$this->assertSame( '2026-11-22 08:00:00', $state->pages[60]['post_modified_gmt'], 'translated shop page advances too' );

		// Strict `/blog/` guard: neither EN blog nor ZH blog translation was touched!
		$this->assertSame( '2026-11-11 01:31:29', $state->pages[1542]['post_modified_gmt'] );
		$this->assertSame( '2026-11-11 01:31:29', $state->pages[1543]['post_modified_gmt'] );
		$this->assertNotContains( 1542, $state->updated_page_ids, '/blog/ must never be updated' );
		$this->assertNotContains( 1543, $state->updated_page_ids, '/blog/ translation must never be updated' );
		$this->assertNotContains( 20, $state->updated_page_ids, 'newer page must never be updated' );

		// Cache cleanup and transient deletion verified.
		sort( $state->cleaned_post_ids );
		$this->assertSame( array( 6, 10, 11, 21, 60 ), $state->cleaned_post_ids );
		$this->assertSame(
			array(
				'seopress_sitemap_lastmod_all_page_1000',
				'seopress_sitemap_lastmod_all_product_1000',
			),
			$state->deleted_keys
		);

		// --- Run 2: MUST be 100% idempotent (zero updates!) ---
		$state->updated_page_ids = array();
		$state->cleaned_post_ids = array();
		$second                  = SitemapLastmod::sync_post_modified_from_templates();

		$this->assertSame(
			array(
				'pages_updated'      => 0,
				'products_updated'   => 0,
				'transients_cleared' => 0,
			),
			$second,
			'repeat run must update zero pages, zero products, and zero transients'
		);
		$this->assertSame( array(), $state->updated_page_ids );
		$this->assertSame( array(), $state->cleaned_post_ids );
	}

	/**
	 * `/blog/` exclusion holds even when `page_for_posts` is 0 (slug-based fallback).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_excludes_blog_by_slug_even_without_page_for_posts_option(): void {
		$state                = new \stdClass();
		$state->templates     = array(
			array(
				'ID'                => 100,
				'post_name'         => 'md-home',
				'post_modified_gmt' => '2026-11-21 18:53:02',
			),
		);
		$state->template_meta = array();
		$state->pages         = array(
			1542 => array(
				'ID'                => 1542,
				'post_name'         => 'blog',
				'post_modified_gmt' => '2026-11-11 01:31:29',
			),
		);
		$state->page_choices  = array(
			array(
				'post_id'    => 1542,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture row mirroring wp_postmeta column name.
				'meta_value' => 'md-home',
			),
		);
		$state->products      = array();
		$state->transients    = array();

		$state->updated_page_ids = array();
		$state->cleaned_post_ids = array();
		$state->deleted_keys     = array();

		$GLOBALS['wpdb'] = $this->stateful_sync_wpdb( $state );

		WP_Mock::userFunction( 'get_option' )->andReturn( 0 );
		WP_Mock::userFunction( 'has_filter' )->with( 'wpml_object_id' )->andReturn( false );

		$result = SitemapLastmod::sync_post_modified_from_templates();

		$this->assertSame( 0, $result['pages_updated'] );
		$this->assertSame( array(), $state->updated_page_ids );
		$this->assertSame( '2026-11-11 01:31:29', $state->pages[1542]['post_modified_gmt'] );
	}

	/**
	 * Builds a base wpdb mock with table names, `esc_like`, and `prepare`.
	 *
	 * @return \wpdb&\Mockery\MockInterface
	 */
	private function base_wpdb() {
		/**
		 * Typed wpdb mock.
		 *
		 * @var \wpdb&\Mockery\MockInterface $wpdb
		 */
		$wpdb                     = Mockery::mock( \wpdb::class );
		$wpdb->posts              = 'wp_posts';
		$wpdb->postmeta           = 'wp_postmeta';
		$wpdb->options            = 'wp_options';
		$wpdb->term_relationships = 'wp_term_relationships';
		$wpdb->term_taxonomy      = 'wp_term_taxonomy';
		$wpdb->terms              = 'wp_terms';

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static fn( string $text ): string => addcslashes( $text, '_%\\' )
		);

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, mixed ...$args ): string {
				$idx = 0;
				return (string) preg_replace_callback(
					'/%[isd]/',
					static function ( array $token ) use ( $args, &$idx ): string {
						$raw = isset( $args[ $idx ] ) && is_scalar( $args[ $idx ] ) ? (string) $args[ $idx ] : '';
						++$idx;
						if ( '%d' === $token[0] ) {
							return (string) (int) $raw;
						}
						if ( '%i' === $token[0] ) {
							return '`' . str_replace( '`', '``', $raw ) . '`';
						}
						return "'" . str_replace( "'", "\\'", $raw ) . "'";
					},
					$query
				);
			}
		);

		return $wpdb;
	}

	/**
	 * Builds a stateful wpdb mock executing `sync_post_modified_from_templates()` queries in memory.
	 *
	 * @param \stdClass $state Mutable fixture state shared with assertions.
	 * @return \wpdb&\Mockery\MockInterface
	 */
	private function stateful_sync_wpdb( \stdClass $state ) {
		$wpdb = $this->base_wpdb();

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( string $sql ) use ( $state ): array {
				if ( str_contains( $sql, "post_type = 'lc_dynamic_template'" ) && str_contains( $sql, 'pm.meta_key' ) ) {
					return $state->template_meta;
				}
				if ( str_contains( $sql, "post_type = 'lc_dynamic_template'" ) ) {
					return $state->templates;
				}
				if ( str_contains( $sql, "post_type = 'page'" ) ) {
					return array_values( $state->pages );
				}
				if ( str_contains( $sql, "meta_key = 'lc_use_template_of_slug'" ) ) {
					return $state->page_choices;
				}
				return array();
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( string $sql ) use ( $state ): ?string {
				if ( str_contains( $sql, 'MAX(post_modified_gmt)' ) && str_contains( $sql, "post_type = 'product'" ) ) {
					if ( array() === $state->products ) {
						return null;
					}
					$max = '';
					foreach ( $state->products as $gmt ) {
						if ( '' === $max || $gmt > $max ) {
							$max = $gmt;
						}
					}
					return $max;
				}
				return null;
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( string $sql ) use ( $state ): array {
				if ( str_contains( $sql, 'wp_options' ) ) {
					return $state->transients;
				}
				return array();
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $sql ) use ( $state ): int {
				if ( str_contains( $sql, 'UPDATE' ) && str_contains( $sql, 'wp_posts' ) && str_contains( $sql, "post_type = 'product'" ) ) {
					if ( 1 === preg_match( "/post_modified_gmt = '([^']+)'/", $sql, $m ) ) {
						$target  = $m[1];
						$updated = 0;
						foreach ( $state->products as $id => $current ) {
							if ( $current < $target ) {
								$state->products[ $id ] = $target;
								++$updated;
							}
						}
						return $updated;
					}
					return 0;
				}
				if ( str_contains( $sql, 'DELETE FROM' ) && str_contains( $sql, 'wp_options' ) ) {
					$count             = count( $state->transients );
					$state->transients = array();
					return $count;
				}
				return 0;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( string $table, array $data, array $where ) use ( $state ): int {
				if ( 'wp_posts' !== $table || ! isset( $where['ID'], $data['post_modified_gmt'] ) ) {
					return 0;
				}
				$id = (int) $where['ID'];
				if ( ! isset( $state->pages[ $id ] ) ) {
					return 0;
				}
				$state->pages[ $id ]['post_modified_gmt'] = (string) $data['post_modified_gmt'];
				if ( isset( $data['post_modified'] ) ) {
					$state->pages[ $id ]['post_modified'] = (string) $data['post_modified'];
				}
				$state->updated_page_ids[] = $id;
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * Registers strict-mode WP_Mock replies for `wpml_object_id` across all 9 languages.
	 *
	 * @param array<int, int>                $page_ids Page IDs queried during sync.
	 * @param array<int, array<string, int>> $map      Map of page_id => [wpml_lang => translated_id].
	 */
	private function mock_wpml_page_translations( array $page_ids, array $map ): void {
		WP_Mock::userFunction( 'has_filter' )->with( 'wpml_object_id' )->andReturn( true );
		foreach ( $page_ids as $page_id ) {
			foreach ( Languages::ALL as $code ) {
				$wpml_lang = Languages::to_wpml( $code );
				$reply     = $map[ $page_id ][ $wpml_lang ] ?? 0;
				$responder = WP_Mock::onFilter( 'wpml_object_id' )->with( $page_id, 'page', false, $wpml_lang );
				if ( $responder instanceof \WP_Mock\Filter_Responder ) {
					$responder->reply( $reply );
				}
			}
		}
	}
}
