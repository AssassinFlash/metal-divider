<?php
/**
 * Unit tests for managed /llms.txt endpoint, discovery link, sanitization,
 * WPML isolation, transient caching, and legacy Hostinger cleanup.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Seo\LlmsTxt;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for MetalDivider\Site\Seo\LlmsTxt.
 *
 * @covers \MetalDivider\Site\Seo\LlmsTxt
 */
final class LlmsTxtTest extends TestCase {

	/**
	 * Restores superglobals, forced language, and WPML global double after each test.
	 */
	public function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $GLOBALS['sitepress'] );
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * Verifies register() hooks parse_request, wp_head, and WooCommerce cache invalidation actions.
	 */
	public function test_register_hooks_route_head_and_cache_invalidation(): void {
		$llms = new LlmsTxt();

		WP_Mock::expectFilterAdded( 'do_parse_request', array( $llms, 'maybe_serve_early' ), -10, 1 );
		WP_Mock::expectActionAdded( 'init', array( $llms, 'unhook_seopress_llms_txt' ), 100, 0 );
		WP_Mock::expectFilterAdded( 'seopress_llms_txt_file', array( $llms, 'filter_seopress_llms_txt' ), 99, 1 );
		WP_Mock::expectActionAdded( 'wp_head', array( $llms, 'output_head_link' ), 5, 0 );
		WP_Mock::expectActionAdded( 'save_post_product', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'deleted_post', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'trashed_post', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'untrashed_post', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'edited_product_cat', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'created_product_cat', array( $llms, 'invalidate_cache' ), 10, 0 );
		WP_Mock::expectActionAdded( 'delete_product_cat', array( $llms, 'invalidate_cache' ), 10, 0 );

		$llms->register();
		$this->assertConditionsMet();
	}

	/**
	 * Verifies strict request routing boundaries for /llms.txt.
	 */
	public function test_should_serve_request_matching_boundaries(): void {
		// Exact root hits on GET and HEAD (with or without query string).
		$this->assertTrue( LlmsTxt::should_serve_request( '/llms.txt', 'GET' ) );
		$this->assertTrue( LlmsTxt::should_serve_request( '/llms.txt', 'HEAD' ) );
		$this->assertTrue( LlmsTxt::should_serve_request( '/llms.txt', 'head' ) );
		$this->assertTrue( LlmsTxt::should_serve_request( '/llms.txt?foo=1', 'GET' ) );
		$this->assertTrue( LlmsTxt::should_serve_request( '/llms.txt?utm_source=bot&x=2#top', 'HEAD' ) );

		// Non-root localized paths, sub-paths, trailing slash, case mismatch, and non-read methods must miss.
		$this->assertFalse( LlmsTxt::should_serve_request( '/zh/llms.txt', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/de/llms.txt', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt/foo', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt/', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '//llms.txt', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/LLMS.TXT', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '', 'GET' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt', 'POST' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt', 'PUT' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt', 'DELETE' ) );
		$this->assertFalse( LlmsTxt::should_serve_request( '/llms.txt', '' ) );
	}

	/**
	 * Verifies maybe_serve() emits required HTTP headers and body for GET,
	 * headers-only for HEAD, and stays silent for non-matching requests.
	 */
	public function test_maybe_serve_emits_headers_and_body_for_get_and_omits_body_for_head(): void {
		$status_calls = array();
		WP_Mock::userFunction( 'status_header' )->andReturnUsing(
			static function ( int $code ) use ( &$status_calls ): void {
				$status_calls[] = $code;
			}
		);
		WP_Mock::userFunction( 'wp_doing_cron' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )->with( LlmsTxt::TRANSIENT_KEY )->andReturn( "# MetalDivider\n" );

		$sent_headers = array();
		$terminated   = 0;
		$llms         = new LlmsTxt(
			static function ( string $header ) use ( &$sent_headers ): void {
				$sent_headers[] = $header;
			},
			static function () use ( &$terminated ): void {
				++$terminated;
			}
		);

		// 1. GET /llms.txt emits status 200, headers, and body.
		$_SERVER['REQUEST_URI']    = '/llms.txt?v=1';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		$llms->maybe_serve();
		$get_body = (string) ob_get_clean();

		$this->assertSame( array( 200 ), $status_calls );
		$this->assertSame(
			array(
				'Content-Type: text/plain; charset=utf-8',
				'Cache-Control: public, max-age=3600',
				'X-Content-Type-Options: nosniff',
			),
			$sent_headers
		);
		$this->assertSame( "# MetalDivider\n", $get_body );
		$this->assertSame( 1, $terminated );

		// 2. HEAD /llms.txt emits status 200 and headers, but no body.
		$sent_headers              = array();
		$_SERVER['REQUEST_URI']    = '/llms.txt';
		$_SERVER['REQUEST_METHOD'] = 'HEAD';

		ob_start();
		$llms->maybe_serve();
		$head_body = (string) ob_get_clean();

		$this->assertSame( array( 200, 200 ), $status_calls );
		$this->assertSame(
			array(
				'Content-Type: text/plain; charset=utf-8',
				'Cache-Control: public, max-age=3600',
				'X-Content-Type-Options: nosniff',
			),
			$sent_headers
		);
		$this->assertSame( '', $head_body );
		$this->assertSame( 2, $terminated );

		// 3. Non-root localized path /zh/llms.txt is ignored completely.
		$sent_headers              = array();
		$_SERVER['REQUEST_URI']    = '/zh/llms.txt';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		$llms->maybe_serve();
		$ignored_body = (string) ob_get_clean();

		$this->assertSame( array(), $sent_headers );
		$this->assertSame( '', $ignored_body );
		$this->assertSame( 2, $terminated );
	}

	/**
	 * Verifies 146-pattern rendering stays within 40 KB, strictly deduplicates all URLs,
	 * includes all 9 language root URLs and all core pages, strips forbidden terms/routes,
	 * avoids "product/products" in link text, and ends with a complete closed line.
	 */
	public function test_build_146_patterns_meets_size_dedupe_language_and_hygiene_contracts(): void {
		$home = 'https://metal-divider.com';

		$series_defs = array(
			array(
				'name' => 'Abstract',
				'slug' => 'pattern-abstract',
			),
			array(
				'name' => 'Asian',
				'slug' => 'pattern-asian',
			),
			array(
				'name' => 'Geometric',
				'slug' => 'pattern-geometric',
			),
			array(
				'name' => 'Gradient',
				'slug' => 'pattern-gradient',
			),
			array(
				'name' => 'Landscape',
				'slug' => 'pattern-landscape',
			),
			array(
				'name' => 'Moroccan & Arabic',
				'slug' => 'pattern-moroccan-arabic',
			),
			array(
				'name' => 'Organic',
				'slug' => 'pattern-organic',
			),
			array(
				'name' => 'Perforated',
				'slug' => 'pattern-perforated',
			),
			array(
				'name' => 'Vintage / Retro',
				'slug' => 'pattern-vintage-retro',
			),
		);

		$series = array();
		foreach ( $series_defs as $s ) {
			$series[] = array(
				'name' => $s['name'],
				'url'  => $home . '/pattern-category/' . $s['slug'] . '/',
			);
		}
		// Inject duplicate and disallowed series entries that must be filtered out.
		$series[] = array(
			'name' => 'Geometric Duplicate',
			'url'  => $home . '/pattern-category/pattern-geometric',
		);
		$series[] = array(
			'name' => 'German Geometric',
			'url'  => $home . '/de/pattern-category/pattern-geometric-de/',
		);

		$patterns = array();
		for ( $i = 1; $i <= 146; $i++ ) {
			$series_item = $series_defs[ ( $i - 1 ) % count( $series_defs ) ];
			$slug        = sprintf( 'architectural-motif-%03d-metal-screen-pattern', $i );
			$raw_title   = match ( $i ) {
				1       => 'DSD Breeze 001 | Decorative Screen Direct',
				2       => 'Parasoleil Lattice 002 | Architectural Product',
				3       => 'Generated by Hostinger Canopy 003 | MetalDivider',
				default => sprintf( 'Motif %03d | Architectural Metal Screen Pattern', $i ),
			};
			$open_area = ( 0 === $i % 9 ) ? '' : (string) ( 15 + ( $i % 45 ) );

			$patterns[] = array(
				'name'      => $raw_title,
				'url'       => $home . '/pattern/' . $slug . '/',
				'series'    => $series_item['name'],
				'open_area' => $open_area,
			);
		}

		// Inject duplicate, localized, paginated, query-string, and excluded-route patterns.
		$patterns[] = array(
			'name'   => 'Motif 001 Duplicate',
			'url'    => $home . '/pattern/architectural-motif-001-metal-screen-pattern',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'Thank You Leakage',
			'url'    => $home . '/inquiry/thank-you/',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'Blog Leakage',
			'url'    => $home . '/blog/metal-screens-guide/',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'Paged Leakage',
			'url'    => $home . '/patterns/page/2/',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'Query Param Leakage',
			'url'    => $home . '/pattern/query-leak/?utm_source=test',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'Chinese Translation Leakage',
			'url'    => $home . '/zh/pattern/architectural-motif-001-metal-screen-pattern/',
			'series' => 'Abstract',
		);
		$patterns[] = array(
			'name'   => 'DSD',
			'url'    => $home . '/pattern/empty-after-sanitize/',
			'series' => 'Abstract',
		);

		$out = LlmsTxt::build( $home, $series, $patterns );

		// 1. Byte budget: strictly <= 40 KB and practically within ~12-25 KB.
		$bytes = strlen( $out );
		$this->assertLessThanOrEqual( LlmsTxt::MAX_BYTES, $bytes, 'Output must never exceed 40 KB.' );
		$this->assertGreaterThan( 10000, $bytes, '146-pattern catalog should produce a substantive index.' );
		$this->assertLessThan( 30000, $bytes, '146-pattern index should remain compact.' );

		// 2. All 9 language root URLs present.
		$this->assertStringContainsString( '(https://metal-divider.com/)', $out );
		foreach ( Languages::TARGETS as $code ) {
			$this->assertStringContainsString(
				'(https://metal-divider.com/' . $code . '/)',
				$out,
				"Missing root entry for language {$code}"
			);
		}

		// 3. All required Core Pages present.
		$expected_core_paths = array(
			'/',
			'/patterns/',
			'/pattern-category/',
			'/stainless-steel-screens/',
			'/laser-cut-geometric-metal-screens/',
			'/glass-hybrid-screens/',
			'/outdoor-privacy-landscape-screens/',
			'/chinese-style-artistic-screens/',
			'/laser-cut-panels/',
			'/perforated-panels/',
			'/expanded-metal-panels/',
			'/custom-metal-divider-screens/',
			'/project-gallery/',
			'/manufacturing-process/',
			'/faqs/',
			'/about-us/',
			'/inquiry/',
			'/privacy/',
		);
		foreach ( $expected_core_paths as $path ) {
			$this->assertStringContainsString(
				'(' . $home . $path . ')',
				$out,
				"Core page {$path} missing from llms.txt"
			);
		}

		// 4. Every URL in the entire document is strictly unique.
		preg_match_all( '/\((https?:\/\/[^)]+)\)/', $out, $url_matches );
		$urls = $url_matches[1];
		$this->assertNotEmpty( $urls );
		$this->assertSame(
			array_values( array_unique( $urls ) ),
			$urls,
			'Every URL in /llms.txt must be strictly deduplicated.'
		);

		// Count pattern links: must be exactly 146.
		$pattern_urls = array_values(
			array_filter(
				$urls,
				static fn( string $u ): bool => str_contains( $u, '/pattern/' )
			)
		);
		$this->assertCount( 146, $pattern_urls, 'All 146 unique English patterns must appear exactly once.' );

		// 5. Forbidden routes, query strings, third-party brands, and non-stainless metals absent.
		$forbidden_substrings = array(
			'thank-you',
			'/blog/',
			'/cart',
			'/checkout',
			'/account',
			'/page/',
			'?',
			'DSD',
			'Decorative Screen Direct',
			'Parasoleil',
			'Generated by Hostinger',
			'Hostinger',
			'Corten',
			'Aluminium',
			'Aluminum',
			'Mild Steel',
			'Copper',
		);
		foreach ( $forbidden_substrings as $banned ) {
			$this->assertStringNotContainsStringIgnoringCase(
				$banned,
				$out,
				"Forbidden substring '{$banned}' leaked into /llms.txt"
			);
		}

		// 6. Link texts never contain "product" or "products".
		preg_match_all( '/\[([^\]]+)\]\(https?:\/\/[^)]+\)/', $out, $label_matches );
		$this->assertNotEmpty( $label_matches[1] );
		foreach ( $label_matches[1] as $link_label ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\bproducts?\b/i',
				$link_label,
				"Link label '{$link_label}' must not use 'product/products'."
			);
		}

		// 7. Dirty titles were cleaned properly and retained.
		$this->assertStringContainsString( '- [Breeze 001](https://metal-divider.com/pattern/architectural-motif-001-metal-screen-pattern/)', $out );
		$this->assertStringContainsString( '- [Lattice 002](https://metal-divider.com/pattern/architectural-motif-002-metal-screen-pattern/)', $out );
		$this->assertStringContainsString( '- [Canopy 003](https://metal-divider.com/pattern/architectural-motif-003-metal-screen-pattern/)', $out );

		// 8. Final line is complete and cleanly terminated (never truncated mid-link).
		$this->assertStringEndsWith( "\n", $out );
		$lines     = explode( "\n", rtrim( $out, "\n" ) );
		$last_line = $lines[ count( $lines ) - 1 ];
		$this->assertMatchesRegularExpression(
			'/^- \[[^\]]+\]\(https:\/\/[^)\s]+\)(?:: .+)?$/',
			$last_line,
			'Final pattern line must be complete and well-formed.'
		);
	}

	/**
	 * Verifies the 40 KB hard byte cap trims oversized catalogs on whole-line boundaries
	 * and appends a closed footer without breaking any Markdown link or duplicating URLs.
	 */
	public function test_build_enforces_40kb_hard_cap_with_clean_closed_truncation(): void {
		$home     = 'https://metal-divider.com';
		$patterns = array();
		for ( $i = 1; $i <= 600; $i++ ) {
			$patterns[] = array(
				'name'      => sprintf( 'Extended Architectural Pattern Name %04d', $i ),
				'url'       => sprintf( '%s/pattern/extended-architectural-pattern-name-%04d-metal-screen-pattern/', $home, $i ),
				'series'    => 'Geometric',
				'open_area' => '25',
			);
		}

		$out = LlmsTxt::build( $home, array(), $patterns );

		$this->assertLessThanOrEqual( LlmsTxt::MAX_BYTES, strlen( $out ) );
		$this->assertStringContainsString( 'additional patterns omitted to stay within the 40 KB llms.txt budget', $out );
		$this->assertStringEndsWith( "Core Pages.)\n", $out );

		// Every bullet line must be an intact Markdown link.
		foreach ( explode( "\n", $out ) as $line ) {
			if ( str_starts_with( $line, '- ' ) ) {
				$this->assertMatchesRegularExpression(
					'/^- \[[^\]]+\]\(https:\/\/[^)\s]+\)(?:: .+)?$/',
					$line,
					"Truncated or malformed Markdown line detected: {$line}"
				);
			}
		}

		// URL uniqueness still holds under truncation.
		preg_match_all( '/\((https?:\/\/[^)]+)\)/', $out, $matches );
		$this->assertSame( array_values( array_unique( $matches[1] ) ), $matches[1] );
	}

	/**
	 * Verifies sanitize_label() cleans SEO suffixes, third-party brands, product wording,
	 * entities, query punctuation, and Markdown-breaking characters.
	 */
	public function test_sanitize_label_strips_seo_suffix_third_party_brands_and_product_wording(): void {
		$this->assertSame( 'Apiary', LlmsTxt::sanitize_label( 'Apiary | Architectural Metal Screen Pattern' ) );
		$this->assertSame( 'Apiary', LlmsTxt::sanitize_label( 'Apiary｜MetalDivider' ) );
		$this->assertSame( 'Breeze', LlmsTxt::sanitize_label( 'DSD Breeze | Decorative Screen Direct' ) );
		$this->assertSame( 'Lotus', LlmsTxt::sanitize_label( 'Lotus (DSD) by Parasoleil' ) );
		$this->assertSame( 'Lattice', LlmsTxt::sanitize_label( 'Decorative Screen Direct - Lattice' ) );
		$this->assertSame( 'Geo Screen', LlmsTxt::sanitize_label( 'Parasoleil Geo Screen' ) );
		$this->assertSame( 'Alpha', LlmsTxt::sanitize_label( 'Generated by Hostinger - Alpha' ) );
		$this->assertSame( 'Custom Metal Pattern', LlmsTxt::sanitize_label( 'Custom Metal Product' ) );
		$this->assertSame( 'Architectural Patterns & Screens', LlmsTxt::sanitize_label( 'Architectural Products &amp; Screens' ) );
		$this->assertSame( 'Geometric Pattern', LlmsTxt::sanitize_label( 'Geometric Product Pattern' ) );
		$this->assertSame( 'Alpha (Beta) Next', LlmsTxt::sanitize_label( "Alpha [Beta]?\nNext" ) );
		$this->assertSame( '', LlmsTxt::sanitize_label( '  DSD  ' ) );
		$this->assertSame( '', LlmsTxt::sanitize_label( '' ) );
	}

	/**
	 * Verifies render() switches WPML to English, restores the previous language,
	 * drops non-English translations, caches via transient, and supports invalidate_cache().
	 */
	public function test_render_filters_wpml_non_english_items_and_uses_transient_cache(): void {
		$sitepress = new class() {
			/**
			 * Active WPML language code.
			 *
			 * @var string
			 */
			public string $lang = 'de';

			/**
			 * Recorded switch_lang calls.
			 *
			 * @var list<string>
			 */
			public array $history = array();

			/**
			 * Returns current language.
			 */
			public function get_current_language(): string {
				return $this->lang;
			}

			/**
			 * Switches active language.
			 *
			 * @param string $lang Language code.
			 */
			public function switch_lang( string $lang ): void {
				$this->lang      = $lang;
				$this->history[] = $lang;
			}

			/**
			 * Returns language details by element ID and type.
			 *
			 * @param int    $element_id   Post ID or term_taxonomy_id.
			 * @param string $element_type WPML element type.
			 */
			public function get_element_language_details( int $element_id, string $element_type ): object {
				$map                = array(
					'post_product:101'   => 'en',
					'post_product:102'   => 'de',
					'post_product:103'   => 'zh-hans',
					'tax_product_cat:10' => 'en',
					'tax_product_cat:11' => 'de',
				);
				$obj                = new \stdClass();
				$obj->language_code = $map[ $element_type . ':' . $element_id ] ?? 'en';
				return $obj;
			}
		};

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Third-party WPML global double.
		$GLOBALS['sitepress'] = $sitepress;

		/**
		 * Simulated transient store.
		 *
		 * @var array<string, string> $transient_store
		 */
		$transient_store = array();
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );
		WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
			static function ( string $key ) use ( &$transient_store ) {
				return $transient_store[ $key ] ?? false;
			}
		);
		WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			static function ( string $key, string $val ) use ( &$transient_store ): bool {
				$transient_store[ $key ] = $val;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			static function ( string $key ) use ( &$transient_store ): bool {
				unset( $transient_store[ $key ] );
				return true;
			}
		);

		$en_post             = new \WP_Post( new \stdClass() );
		$en_post->ID         = 101;
		$en_post->post_title = 'Agave | Architectural Metal Screen Pattern';

		$de_post             = new \WP_Post( new \stdClass() );
		$de_post->ID         = 102;
		$de_post->post_title = 'Agave DE';

		$zh_post             = new \WP_Post( new \stdClass() );
		$zh_post->ID         = 103;
		$zh_post->post_title = 'Agave ZH';

		$en_term                   = new \WP_Term( new \stdClass() );
		$en_term->term_id          = 10;
		$en_term->term_taxonomy_id = 10;
		$en_term->taxonomy         = 'product_cat';
		$en_term->slug             = 'pattern-organic';
		$en_term->name             = 'Organic';
		$en_term->count            = 12;

		$de_term                   = new \WP_Term( new \stdClass() );
		$de_term->term_id          = 11;
		$de_term->term_taxonomy_id = 11;
		$de_term->taxonomy         = 'product_cat';
		$de_term->slug             = 'pattern-organic-de';
		$de_term->name             = 'Organisch';
		$de_term->count            = 12;

		$uncat                   = new \WP_Term( new \stdClass() );
		$uncat->term_id          = 1;
		$uncat->term_taxonomy_id = 1;
		$uncat->taxonomy         = 'product_cat';
		$uncat->slug             = 'uncategorized';
		$uncat->name             = 'Uncategorized';
		$uncat->count            = 5;

		$get_posts_calls = 0;
		WP_Mock::userFunction( 'get_posts' )->andReturnUsing(
			static function () use ( &$get_posts_calls, $en_post, $de_post, $zh_post ): array {
				++$get_posts_calls;
				return array( $en_post, $de_post, $zh_post );
			}
		);
		WP_Mock::userFunction( 'get_terms' )->andReturn( array( $en_term, $de_term, $uncat ) );
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static fn( int $id ): string => match ( $id ) {
				101     => 'https://metal-divider.com/pattern/agave-metal-screen-pattern/',
				102     => 'https://metal-divider.com/de/pattern/agave-metal-screen-pattern/',
				103     => 'https://metal-divider.com/zh/pattern/agave-metal-screen-pattern/',
				default => '',
			}
		);
		WP_Mock::userFunction( 'get_term_link' )->andReturnUsing(
			static fn( \WP_Term $t ): string => 'pattern-organic' === $t->slug
				? 'https://metal-divider.com/pattern-category/pattern-organic/'
				: 'https://metal-divider.com/de/pattern-category/pattern-organic-de/'
		);
		WP_Mock::userFunction( 'get_the_terms' )->with( 101, 'product_cat' )->andReturn( array( $en_term ) );
		WP_Mock::userFunction( 'get_post_meta' )->with( 101, 'open_area', true )->andReturn( '28' );

		$llms  = new LlmsTxt();
		$first = $llms->render();

		// WPML switched to English during query and restored back to German.
		$this->assertSame( array( 'en', 'de' ), $sitepress->history );
		$this->assertSame( 'de', $sitepress->lang );

		// Only the English pattern and English series appear (no German/Chinese/Uncategorized).
		$this->assertStringContainsString( '- [Organic](https://metal-divider.com/pattern-category/pattern-organic/)', $first );
		$this->assertStringContainsString( '- [Agave](https://metal-divider.com/pattern/agave-metal-screen-pattern/): Organic, 28% open area', $first );
		$this->assertStringNotContainsString( 'Organisch', $first );
		$this->assertStringNotContainsString( 'Uncategorized', $first );
		$this->assertStringNotContainsString( 'Agave DE', $first );
		$this->assertStringNotContainsString( 'Agave ZH', $first );
		$this->assertSame( 1, $get_posts_calls );

		// Second call hits the transient cache without querying posts again.
		$second = $llms->render();
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $get_posts_calls );

		// Cache invalidation forces regeneration on next render.
		$llms->invalidate_cache();
		$this->assertArrayNotHasKey( LlmsTxt::TRANSIENT_KEY, $transient_store );

		$llms->render();
		$this->assertSame( 2, $get_posts_calls );
	}

	/**
	 * Verifies <link rel="describedby"> emits only on home and /patterns/ archive,
	 * always pointing to the canonical root /llms.txt even on non-English routes.
	 */
	public function test_output_head_link_emits_only_on_home_and_pattern_library_with_root_url(): void {
		$this->plain_text();
		WP_Mock::userFunction( 'get_option' )->with( 'home' )->andReturn( 'https://metal-divider.com' );

		$view = new class() {
			/**
			 * Whether search view is active.
			 *
			 * @var bool
			 */
			public bool $search = false;

			/**
			 * Whether front page view is active.
			 *
			 * @var bool
			 */
			public bool $front = false;

			/**
			 * Whether WooCommerce shop archive is active.
			 *
			 * @var bool
			 */
			public bool $shop = false;

			/**
			 * Whether product post-type archive is active.
			 *
			 * @var bool
			 */
			public bool $archive = false;
		};

		WP_Mock::userFunction( 'is_search' )->andReturnUsing(
			static function () use ( $view ): bool {
				return $view->search;
			}
		);
		WP_Mock::userFunction( 'is_front_page' )->andReturnUsing(
			static function () use ( $view ): bool {
				return $view->front;
			}
		);
		WP_Mock::userFunction( 'is_shop' )->andReturnUsing(
			static function () use ( $view ): bool {
				return $view->shop;
			}
		);
		WP_Mock::userFunction( 'is_post_type_archive' )->andReturnUsing(
			static function ( string $post_type ) use ( $view ): bool {
				return 'product' === $post_type && $view->archive;
			}
		);

		$llms     = new LlmsTxt();
		$expected = '<link rel="describedby" href="https://metal-divider.com/llms.txt" />' . "\n";

		// 1. English home page -> emitted.
		$view->front = true;
		ob_start();
		$llms->output_head_link();
		$this->assertSame( $expected, (string) ob_get_clean() );

		// 2. German home page -> STILL emits unfiltered root /llms.txt (never /de/llms.txt).
		Languages::force_for_tests( 'de' );
		ob_start();
		$llms->output_head_link();
		$de_home_out = (string) ob_get_clean();
		$this->assertSame( $expected, $de_home_out );
		$this->assertStringNotContainsString( '/de/llms.txt', $de_home_out );

		// 3. Chinese pattern library (/patterns/ via is_shop) -> emits root /llms.txt.
		Languages::force_for_tests( 'zh' );
		$view->front = false;
		$view->shop  = true;
		ob_start();
		$llms->output_head_link();
		$this->assertSame( $expected, (string) ob_get_clean() );

		// 4. Pattern archive via is_post_type_archive('product') -> emits root /llms.txt.
		$view->shop    = false;
		$view->archive = true;
		ob_start();
		$llms->output_head_link();
		$this->assertSame( $expected, (string) ob_get_clean() );

		// 5. Regular interior/PDP page -> silent.
		$view->archive = false;
		ob_start();
		$llms->output_head_link();
		$this->assertSame( '', (string) ob_get_clean() );

		// 6. Search page -> silent even if archive flag were set.
		$view->search = true;
		$view->front  = true;
		ob_start();
		$llms->output_head_link();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Verifies disable_hostinger_generator() and hostinger_drift() cleanly detect,
	 * repair, and stay idempotent across repeated runs with zero leftover files.
	 */
	public function test_hostinger_cleanup_and_drift_are_idempotent(): void {
		$tmp_dir = sys_get_temp_dir() . '/md-llms-test-' . bin2hex( random_bytes( 6 ) );
		$this->assertTrue( mkdir( $tmp_dir, 0700, true ) );

		$legacy_file = $tmp_dir . '/llms.txt';
		$this->assertNotFalse( file_put_contents( $legacy_file, "# Generated by Hostinger\n" ) );

		try {
			$options      = array(
				LlmsTxt::HOSTINGER_OPTION => array(
					'enable_llms_txt' => true,
					'other_setting'   => 'keep-me',
				),
			);
			$update_calls = 0;

			WP_Mock::userFunction( 'get_option' )->andReturnUsing(
				static function ( string $key ) use ( &$options ) {
					return $options[ $key ] ?? false;
				}
			);
			WP_Mock::userFunction( 'update_option' )->andReturnUsing(
				static function ( string $key, $value ) use ( &$options, &$update_calls ): bool {
					++$update_calls;
					$options[ $key ] = $value;
					return true;
				}
			);

			// 1. Read-only drift check reports both option and static file without mutating state.
			$drift_before = LlmsTxt::hostinger_drift( $tmp_dir . '/' );
			$this->assertCount( 2, $drift_before );
			$this->assertStringContainsString( 'enable_llms_txt', $drift_before[0] );
			$this->assertStringContainsString( $legacy_file, $drift_before[1] );
			$this->assertFileExists( $legacy_file );
			$this->assertSame( 0, $update_calls );

			// 2. First cleanup disables option (preserving sibling keys) and deletes static file.
			$first = LlmsTxt::disable_hostinger_generator( $tmp_dir . '/' );
			$this->assertSame(
				array(
					'option_updated' => true,
					'file_deleted'   => true,
					'file_path'      => $legacy_file,
				),
				$first
			);
			$this->assertFileDoesNotExist( $legacy_file );
			$this->assertSame(
				array(
					'enable_llms_txt' => false,
					'other_setting'   => 'keep-me',
				),
				$options[ LlmsTxt::HOSTINGER_OPTION ]
			);
			$this->assertSame( 1, $update_calls );

			// 3. Drift check is now completely clean.
			$this->assertSame( array(), LlmsTxt::hostinger_drift( $tmp_dir ) );

			// 4. Second cleanup is a pure no-op (idempotent).
			$second = LlmsTxt::disable_hostinger_generator( $tmp_dir );
			$this->assertSame(
				array(
					'option_updated' => false,
					'file_deleted'   => false,
					'file_path'      => $legacy_file,
				),
				$second
			);
			$this->assertSame( 1, $update_calls );

			// 5. Empty ABSPATH guard returns empty file_path safely.
			$empty_res = LlmsTxt::disable_hostinger_generator( '' );
			$this->assertSame(
				array(
					'option_updated' => false,
					'file_deleted'   => false,
					'file_path'      => '',
				),
				$empty_res
			);
		} finally {
			if ( file_exists( $legacy_file ) ) {
				unlink( $legacy_file );
			}
			if ( is_dir( $tmp_dir ) ) {
				rmdir( $tmp_dir );
			}
		}
	}
}
