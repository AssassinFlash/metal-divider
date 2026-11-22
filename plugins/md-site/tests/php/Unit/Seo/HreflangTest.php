<?php
/**
 * Tests for the WPML hreflang filter and normalization rule.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\Hreflang;
use MetalDivider\Site\Seo\QueryParamRobots;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Hreflang filter: normalization, x-default mapping,
 * and dropping on non-canonical views.
 *
 * @covers \MetalDivider\Site\Seo\Hreflang
 */
final class HreflangTest extends TestCase {

	/**
	 * Clean up superglobals after each test.
	 */
	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * Verifies register hooks wpml_hreflangs with priority 20.
	 */
	public function test_register_hooks_filter(): void {
		$rule = new Hreflang();
		WP_Mock::expectFilterAdded( 'wpml_hreflangs', array( $rule, 'filter' ), 20 );

		$rule->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Non-array or empty inputs yield empty array.
	 */
	public function test_empty_or_non_array_yields_empty(): void {
		$rule = new Hreflang();

		$this->assertSame( array(), $rule->filter( null ) );
		$this->assertSame( array(), $rule->filter( '' ) );
		$this->assertSame( array(), $rule->filter( array() ) );
	}

	/**
	 * Search results drop hreflangs.
	 */
	public function test_search_results_drop_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( true );
		$rule = new Hreflang();

		$out = $rule->filter( array( 'en' => 'https://metal-divider.com/?s=mesh' ) );
		$this->assertSame( array(), $out );
	}

	/**
	 * 404 views drop hreflangs.
	 */
	public function test_404_drops_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( true );
		$rule = new Hreflang();

		$out = $rule->filter( array( 'en' => 'https://metal-divider.com/missing/' ) );
		$this->assertSame( array(), $out );
	}

	/**
	 * Paged views drop hreflangs.
	 */
	public function test_paged_views_drop_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( true );
		$rule = new Hreflang();

		$out = $rule->filter( array( 'en' => 'https://metal-divider.com/patterns/page/2/' ) );
		$this->assertSame( array(), $out );
	}

	/**
	 * Catalog filter query parameters drop hreflangs.
	 */
	public function test_query_parameters_drop_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( false );
		$rule = new Hreflang();

		$_GET['pattern_search'] = 'hex';

		$out = $rule->filter( array( 'en' => 'https://metal-divider.com/patterns/?pattern_search=hex' ) );
		$this->assertSame( array(), $out );

		unset( $_GET['pattern_search'] );

		$_GET['sort_by'] = 'title';

		$out = $rule->filter( array( 'en' => 'https://metal-divider.com/patterns/?sort_by=title' ) );
		$this->assertSame( array(), $out );
	}

	/**
	 * Singular posts with robots noindex drop hreflangs.
	 */
	public function test_singular_noindex_drops_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( false );
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 100 );
		WP_Mock::userFunction( 'get_post_meta' )->with( 100, '_seopress_robots_index', true )->andReturn( 'yes' );

		$rule = new Hreflang();
		$out  = $rule->filter( array( 'en' => 'https://metal-divider.com/inquiry/thank-you/' ) );
		$this->assertSame( array(), $out );
	}

	/**
	 * Canonical indexable views normalize tags and inject x-default.
	 */
	public function test_canonical_views_normalize_tags_and_inject_x_default(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( false );
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 42 );
		WP_Mock::userFunction( 'get_post_meta' )->with( 42, '_seopress_robots_index', true )->andReturn( '' );

		$rule = new Hreflang();
		$raw  = array(
			'en'      => 'https://metal-divider.com/pattern/cube-zenith/',
			'zh-hans' => 'https://metal-divider.com/zh/pattern/cube-zenith/',
			'pt-br'   => 'https://metal-divider.com/pt-br/pattern/cube-zenith/',
			'es'      => 'https://metal-divider.com/es/pattern/cube-zenith/',
		);

		$out = $rule->filter( $raw );

		$expected = array(
			'en'        => 'https://metal-divider.com/pattern/cube-zenith/',
			'zh-Hans'   => 'https://metal-divider.com/zh/pattern/cube-zenith/',
			'pt-BR'     => 'https://metal-divider.com/pt-br/pattern/cube-zenith/',
			'es'        => 'https://metal-divider.com/es/pattern/cube-zenith/',
			'x-default' => 'https://metal-divider.com/pattern/cube-zenith/',
		);

		$this->assertSame( $expected, $out );
	}

	/**
	 * All six non-canonical tracking and gallery-state query parameters drop hreflangs.
	 *
	 * @dataProvider non_canonical_params_provider
	 *
	 * @param string $param Non-canonical query parameter name.
	 * @param string $value Non-empty sample value.
	 */
	public function test_non_canonical_query_params_drop_hreflangs( string $param, string $value ): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( false );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => trim( (string) $v ) );

		$_GET[ $param ] = $value;

		$rule = new Hreflang();
		$out  = $rule->filter( $this->nine_language_hreflangs() );
		$this->assertSame( array(), $out );
	}

	/**
	 * Data provider for all six QueryParamRobots non-canonical parameters.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function non_canonical_params_provider(): array {
		$cases = array();
		foreach ( QueryParamRobots::NON_CANONICAL_PARAMS as $param ) {
			$cases[ $param ] = array( $param, 'sample-value' );
		}
		return $cases;
	}

	/**
	 * Clean URLs and marketing utm_source URLs preserve all 10 hreflang tags (9 languages + x-default).
	 */
	public function test_clean_and_utm_source_views_emit_all_ten_hreflangs(): void {
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_404' )->andReturn( false );
		WP_Mock::userFunction( 'is_paged' )->andReturn( false );
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 77 );
		WP_Mock::userFunction( 'get_post_meta' )->with( 77, '_seopress_robots_index', true )->andReturn( '' );

		$rule     = new Hreflang();
		$raw      = $this->nine_language_hreflangs();
		$expected = array(
			'en'        => 'https://metal-divider.com/project-gallery/',
			'zh-Hans'   => 'https://metal-divider.com/zh/project-gallery/',
			'es'        => 'https://metal-divider.com/es/project-gallery/',
			'pt-BR'     => 'https://metal-divider.com/pt-br/project-gallery/',
			'de'        => 'https://metal-divider.com/de/project-gallery/',
			'fr'        => 'https://metal-divider.com/fr/project-gallery/',
			'ar'        => 'https://metal-divider.com/ar/project-gallery/',
			'id'        => 'https://metal-divider.com/id/project-gallery/',
			'vi'        => 'https://metal-divider.com/vi/project-gallery/',
			'x-default' => 'https://metal-divider.com/project-gallery/',
		);

		$clean_out = $rule->filter( $raw );
		$this->assertCount( 10, $clean_out );
		$this->assertSame( $expected, $clean_out );

		$_GET['utm_source'] = 'newsletter';
		$utm_out            = $rule->filter( $raw );
		$this->assertCount( 10, $utm_out );
		$this->assertSame( $expected, $utm_out );
	}

	/**
	 * Full 9-language WPML hreflang input map.
	 *
	 * @return array<string, string>
	 */
	private function nine_language_hreflangs(): array {
		return array(
			'en'      => 'https://metal-divider.com/project-gallery/',
			'zh-hans' => 'https://metal-divider.com/zh/project-gallery/',
			'es'      => 'https://metal-divider.com/es/project-gallery/',
			'pt-br'   => 'https://metal-divider.com/pt-br/project-gallery/',
			'de'      => 'https://metal-divider.com/de/project-gallery/',
			'fr'      => 'https://metal-divider.com/fr/project-gallery/',
			'ar'      => 'https://metal-divider.com/ar/project-gallery/',
			'id'      => 'https://metal-divider.com/id/project-gallery/',
			'vi'      => 'https://metal-divider.com/vi/project-gallery/',
		);
	}
}
