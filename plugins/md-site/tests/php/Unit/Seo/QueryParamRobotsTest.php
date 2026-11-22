<?php
/**
 * Tests for the non-canonical query parameter robots noindex rule.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\QueryParamRobots;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for QueryParamRobots: verifies each non-canonical tracking/gallery
 * parameter produces canonical-safe `'noindex'`, empty values and unrelated
 * marketing/utility parameters stay indexable, and existing SEOPress noindex
 * decisions are preserved.
 *
 * @covers \MetalDivider\Site\Seo\QueryParamRobots
 */
final class QueryParamRobotsTest extends TestCase {

	/**
	 * Registers passthrough sanitization mocks and clears $_GET before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$_GET = array();
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $value ) => trim( (string) $value ) );
	}

	/**
	 * Leaves $_GET clean after each test.
	 */
	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * Each of the six non-canonical query parameters triggers canonical-safe `'noindex'`.
	 *
	 * @dataProvider non_canonical_params_provider
	 *
	 * @param string $param Query parameter name.
	 * @param string $value Non-empty sample value.
	 */
	public function test_each_non_canonical_param_triggers_canonical_safe_noindex( string $param, string $value ): void {
		$_GET[ $param ] = $value;

		$result = ( new QueryParamRobots() )->noindex( '' );

		$this->assertSame( 'noindex', $result );
		$this->assertNotContains( $result, array( '1', 'yes' ), 'SEOPress strips <link rel="canonical"> only for 1/yes' );
		$this->assertTrue( QueryParamRobots::has_non_canonical_param() );
	}

	/**
	 * Data provider for all six non-canonical query parameters.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function non_canonical_params_provider(): array {
		return array(
			'source_page'   => array( 'source_page', 'home' ),
			'source_module' => array( 'source_module', 'home-projects' ),
			'source_button' => array( 'source_button', 'view_project_photos' ),
			'category'      => array( 'category', 'hospitality' ),
			'project'       => array( 'project', 'park-hyatt-shenzhen' ),
			'image'         => array( 'image', '1' ),
		);
	}

	/**
	 * Empty or whitespace-only values for non-canonical parameters stay indexable.
	 *
	 * @dataProvider non_canonical_params_provider
	 *
	 * @param string $param Query parameter name.
	 * @param string $value Unused sample value.
	 */
	public function test_empty_and_whitespace_non_canonical_params_stay_indexable( string $param, string $value ): void {
		unset( $value );

		$_GET[ $param ] = '';
		$this->assertFalse( QueryParamRobots::has_non_canonical_param() );
		$this->assertSame( '', ( new QueryParamRobots() )->noindex( '' ) );

		$_GET[ $param ] = '   ';
		$this->assertFalse( QueryParamRobots::has_non_canonical_param() );
		$this->assertSame( '', ( new QueryParamRobots() )->noindex( '' ) );
	}

	/**
	 * Existing SEOPress noindex decisions are preserved unchanged.
	 */
	public function test_existing_noindex_decision_is_preserved(): void {
		$rule = new QueryParamRobots();

		$this->assertSame( 'noindex', $rule->noindex( 'noindex' ) );
		$this->assertSame( '1', $rule->noindex( '1' ) );
		$this->assertSame( 'yes', $rule->noindex( 'yes' ) );

		$_GET['source_page'] = 'home';
		$this->assertSame( '1', $rule->noindex( '1' ) );
		$this->assertSame( 'yes', $rule->noindex( 'yes' ) );
	}

	/**
	 * Clean URLs and marketing/utility parameters are never noindexed.
	 */
	public function test_plain_and_marketing_or_utility_params_stay_indexable(): void {
		$rule = new QueryParamRobots();

		$this->assertFalse( QueryParamRobots::has_non_canonical_param() );
		$this->assertSame( '', $rule->noindex( '' ) );

		$_GET = array(
			'utm_source'   => 'google',
			'utm_medium'   => 'cpc',
			'utm_campaign' => 'screens',
			'gclid'        => 'CjwKCAiA',
			'fbclid'       => 'IwAR123',
			'mdaudit'      => '1',
			'preview'      => 'true',
		);

		$this->assertFalse( QueryParamRobots::has_non_canonical_param() );
		$this->assertSame( '', $rule->noindex( '' ) );
	}

	/**
	 * Passing an explicit query array evaluates only that map and handles non-scalars safely.
	 */
	public function test_explicit_query_array_and_non_scalar_values(): void {
		$_GET['category'] = 'hospitality';

		$this->assertFalse( QueryParamRobots::has_non_canonical_param( array( 'utm_source' => 'newsletter' ) ) );
		$this->assertFalse( QueryParamRobots::has_non_canonical_param( array( 'category' => array( 'hospitality' ) ) ) );
		$this->assertTrue( QueryParamRobots::has_non_canonical_param( array( 'project' => 'expanded-hotel-lobby' ) ) );
	}

	/**
	 * Verifies register hooks seopress_titles_noindex_bypass at priority 10.
	 */
	public function test_it_registers_on_the_seopress_bypass_filter(): void {
		$rule = new QueryParamRobots();
		WP_Mock::expectFilterAdded( 'seopress_titles_noindex_bypass', array( $rule, 'noindex' ), 10 );

		$rule->register();
		$this->assertConditionsMet();
	}
}
