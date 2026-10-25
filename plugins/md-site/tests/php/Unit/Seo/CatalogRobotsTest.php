<?php
/**
 * Tests for the catalog faces' search/sort noindex rule.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\CatalogRobots;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for CatalogRobots: the narrow rule that makes an internal
 * pattern-search or sort result `noindex, follow` while leaving the plain
 * catalog indexed and its canonical untouched.
 *
 * The cases that matter are the negative ones. The shipped behaviour was
 * `index, follow` on every filtered view, so a test that only asserted the
 * positive case would pass against the old code too; the plain-catalog and
 * "parameter does not change the query" cases are what make a regression
 * visible.
 *
 * @covers \MetalDivider\Site\Seo\CatalogRobots
 */
final class CatalogRobotsTest extends TestCase {

	/**
	 * A filtered shop view is noindex: the search box submitted a term.
	 */
	public function test_a_searched_shop_view_is_noindex(): void {
		$this->given_catalog_request( 'shop', array( 'pattern_search' => 'lattice' ) );
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * A sorted shop view is noindex: the order menu submitted an accepted
	 * value.
	 */
	public function test_a_sorted_shop_view_is_noindex(): void {
		$this->given_catalog_request( 'shop', array( 'sort_by' => 'title-descending' ) );
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * Search and sort together are one filtered view, still noindex.
	 */
	public function test_search_and_sort_together_are_noindex(): void {
		$this->given_catalog_request(
			'shop',
			array(
				'pattern_search' => 'leaf',
				'sort_by'        => 'created-descending',
			)
		);
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * A series archive's filtered view is noindex too — the same tools, the
	 * same duplicate-URL problem.
	 */
	public function test_a_filtered_series_archive_is_noindex(): void {
		$this->given_catalog_request( 'product_cat', array( 'pattern_search' => 'leaf' ) );
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * The plain catalog stays indexed: no parameter, no noindex. This is the
	 * case the shipped behaviour got right and a careless rule would break.
	 */
	public function test_the_plain_shop_stays_indexable(): void {
		$this->given_catalog_request( 'shop', array() );
		$this->assertSame( '', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * The plain series archive stays indexed.
	 */
	public function test_the_plain_series_archive_stays_indexable(): void {
		$this->given_catalog_request( 'product_cat', array() );
		$this->assertSame( '', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * An empty search box is not a filtered view: `Catalog` ignores an empty
	 * term, so the response is the plain catalog and must stay indexable.
	 */
	public function test_an_empty_search_term_stays_indexable(): void {
		$this->given_catalog_request( 'shop', array( 'pattern_search' => '   ' ) );
		$this->assertSame( '', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * An unrecognised `sort_by` leaves the query at its default order, so the
	 * page is the plain catalog. `Catalog::requested_sort` validates against
	 * its own list; this rule must agree rather than trust the parameter's
	 * presence.
	 */
	public function test_an_unrecognised_sort_value_stays_indexable(): void {
		$this->given_catalog_request( 'shop', array( 'sort_by' => 'price-ascending' ) );
		$this->assertSame( '', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * The default order the menu pre-selects is a real applied order, so it
	 * is a real filtered view (the URL differs from the canonical).
	 */
	public function test_the_default_sort_value_is_noindex(): void {
		$this->given_catalog_request( 'shop', array( 'sort_by' => 'title-ascending' ) );
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * A non-catalog page is never touched, even carrying the same
	 * parameters: `/` and a PDP do not render the archive tools.
	 */
	public function test_a_non_catalog_request_is_never_touched(): void {
		$this->given_catalog_request( 'none', array( 'pattern_search' => 'lattice' ) );
		$this->assertSame( '', ( new CatalogRobots() )->noindex( '' ) );
	}

	/**
	 * SEOPress's own noindex decision is preserved, never cleared: a request
	 * it already noindexed (a 404, a paged archive) stays noindexed whatever
	 * the catalog rule would say.
	 */
	public function test_an_existing_noindex_decision_is_preserved(): void {
		$this->given_catalog_request( 'none', array() );
		$this->assertSame( 'noindex', ( new CatalogRobots() )->noindex( 'noindex' ) );
		$this->assertSame( '1', ( new CatalogRobots() )->noindex( '1' ) );
	}

	/**
	 * The rule returns the string `noindex`, never `1`/`yes`: SEOPress
	 * suppresses the canonical only for those two values, so returning them
	 * would strip the canonical this rule must leave alone.
	 */
	public function test_the_rule_returns_the_canonical_safe_value(): void {
		$this->given_catalog_request( 'shop', array( 'pattern_search' => 'lattice' ) );
		$value = ( new CatalogRobots() )->noindex( '' );

		$this->assertSame( 'noindex', $value );
		$this->assertNotContains( $value, array( '1', 'yes' ), 'the canonical is suppressed only for 1/yes' );
	}

	/**
	 * The rule registers on SEOPress's own bypass filter, the documented
	 * extension point — not on a filter that would replace its decision.
	 */
	public function test_it_registers_on_the_seopress_bypass_filter(): void {
		WP_Mock::expectFilterAdded( 'seopress_titles_noindex_bypass', array( new CatalogRobots(), 'noindex' ) );
		( new CatalogRobots() )->register();
		$this->assertConditionsMet();
	}

	/**
	 * The fixture: what the request looks like, and what $_GET carries.
	 *
	 * @param string                $face One of shop | product_cat | none.
	 * @param array<string, string> $get  The request's query parameters.
	 */
	private function given_catalog_request( string $face, array $get ): void {
		$_GET = $get;
		WP_Mock::userFunction( 'is_shop' )->andReturn( 'shop' === $face );
		WP_Mock::userFunction( 'is_product_taxonomy' )->andReturn( 'product_cat' === $face );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $value ) => trim( (string) $value ) );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => $value );
	}

	/**
	 * Each test leaves $_GET as it found it.
	 */
	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}
}
