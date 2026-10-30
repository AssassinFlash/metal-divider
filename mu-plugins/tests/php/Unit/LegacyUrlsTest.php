<?php
/**
 * Tests for the legacy-URL alias.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The old Shopify addresses and their one destination each: `/shop/` to
 * `/patterns/`, `/collections/<series>` to `/pattern-category/<series>`, with
 * the pager and the query string carried along, and the two Shopify handles
 * that have no series here landing on the catalogue (owner ruling 2026-10-30,
 * GOAL.md: Shopify semantics are not the site's).
 *
 * @covers ::md_legacy_urls_is_under
 * @covers ::md_legacy_urls_rest
 * @covers ::md_legacy_urls_target
 * @covers ::md_legacy_urls_url
 * @covers ::md_legacy_urls_series_exists
 * @covers ::md_legacy_urls_redirect
 */
final class LegacyUrlsTest extends TestCase {

	/**
	 * The series the tests treat as live: one per shape the mapping has to
	 * tell apart from a deleted collection.
	 *
	 * @var array<int, string>
	 */
	private const SERIES = array( 'pattern-organic', 'pattern-abstract', 'pattern-gradient' );

	/**
	 * Resets the request globals the alias reads.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_series']   = self::SERIES;
		$GLOBALS['md_mu_test_is_admin'] = false;
		unset( $GLOBALS['md_mu_test_doing_ajax'], $GLOBALS['md_mu_test_redirect'] );
	}

	/**
	 * Resets the request globals the alias reads.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['md_mu_test_series'],
			$GLOBALS['md_mu_test_is_admin'],
			$GLOBALS['md_mu_test_doing_ajax'],
			$GLOBALS['md_mu_test_redirect']
		);
		parent::tearDown();
	}

	/**
	 * The catalogue moves even though only its base word changed.
	 *
	 * @dataProvider catalogue_paths
	 *
	 * @param string $path     The legacy request path.
	 * @param string $expected The destination.
	 */
	public function test_the_catalogue_paths_keep_everything_after_the_base( string $path, string $expected ): void {
		self::assertSame( $expected, md_legacy_urls_target( $path, static fn ( string $slug ): bool => in_array( $slug, self::SERIES, true ) ) );
	}

	/**
	 * `/shop/` in every shape a visitor or a crawler arrives with: bare, with
	 * the trailing slash, with the pager, and without any slash at all.
	 *
	 * @return array<string, array{0: string, 1: string}> The shapes and destinations.
	 */
	public static function catalogue_paths(): array {
		return array(
			'bare'          => array( '/shop/', '/patterns/' ),
			'no slash'      => array( '/shop', '/patterns/' ),
			'page two'      => array( '/shop/page/2/', '/patterns/page/2/' ),
			'page ten'      => array( '/shop/page/10/', '/patterns/page/10/' ),
			'deep fragment' => array( '/shop/anything/else', '/patterns/anything/else' ),
		);
	}

	/**
	 * A live series keeps its slug; only the base word moves.
	 *
	 * @dataProvider series_paths
	 *
	 * @param string $path     The legacy series path.
	 * @param string $expected The destination.
	 */
	public function test_a_live_series_keeps_its_slug( string $path, string $expected ): void {
		self::assertSame( $expected, md_legacy_urls_target( $path, static fn ( string $slug ): bool => in_array( $slug, self::SERIES, true ) ) );
	}

	/**
	 * The series shapes: with and without the trailing slash, and with the
	 * pager the archives carry.
	 *
	 * @return array<string, array{0: string, 1: string}> The shapes and destinations.
	 */
	public static function series_paths(): array {
		return array(
			'trailing slash' => array( '/collections/pattern-organic/', '/pattern-category/pattern-organic/' ),
			'no slash'       => array( '/collections/pattern-organic', '/pattern-category/pattern-organic' ),
			'pager'          => array( '/collections/pattern-abstract/page/3/', '/pattern-category/pattern-abstract/page/3/' ),
			'gradient'       => array( '/collections/pattern-gradient/', '/pattern-category/pattern-gradient/' ),
		);
	}

	/**
	 * The two Shopify handles with no series behind them land on the
	 * catalogue.
	 *
	 * `/collections/all` was the whole catalogue on the old site, and the
	 * redirect map exported from Shopify points four deleted test collections
	 * at the shop — one of them at another deleted collection, a target that
	 * 404s on the old site too. A `/pattern-category/` page for a series that
	 * does not exist would be this site inventing a 404, so both go where the
	 * migration plan already sends them.
	 */
	public function test_the_shopify_handles_without_a_series_land_on_the_catalogue(): void {
		$live = static fn ( string $slug ): bool => in_array( $slug, self::SERIES, true );

		self::assertSame( '/patterns/', md_legacy_urls_target( '/collections/all/', $live ), 'the old all-collections handle was the catalogue' );
		self::assertSame( '/patterns/', md_legacy_urls_target( '/collections/frontpage/', $live ), 'a deleted test collection lands where the migration plan sends it' );
		self::assertSame( '/patterns/', md_legacy_urls_target( '/collections/pattern-1/', $live ) );
		self::assertSame( '/patterns/', md_legacy_urls_target( '/collections/arabesque-patterns/', $live ) );
	}

	/**
	 * The old series base on its own is the series index, which the site
	 * publishes as its own page: it moves there rather than to the catalogue.
	 */
	public function test_the_old_series_index_moves_to_the_new_one(): void {
		self::assertSame( '/pattern-category/', md_legacy_urls_target( '/collections/', static fn (): bool => false ) );
		self::assertSame( '/pattern-category/', md_legacy_urls_target( '/collections', static fn (): bool => false ) );
	}

	/**
	 * A path that is not a legacy one asks for nothing.
	 *
	 * The prefix test is a segment test: `/shopkeeper`, `/collections-x` and
	 * `/pattern-category/…` itself are different addresses, and a redirect
	 * that swallowed them would take a working page away.
	 *
	 * @dataProvider foreign_paths
	 *
	 * @param string $path The path that must not redirect.
	 */
	public function test_a_path_that_is_not_legacy_is_left_alone( string $path ): void {
		self::assertSame( '', md_legacy_urls_target( $path, static fn (): bool => true ) );
	}

	/**
	 * The near misses: a word that merely starts with the base, the new paths
	 * themselves, and the site's other faces.
	 *
	 * @return array<string, array{0: string}> The paths.
	 */
	public static function foreign_paths(): array {
		return array(
			'shopkeeper'    => array( '/shopkeeper/' ),
			'shopping'      => array( '/shopping-cart/' ),
			'collections-x' => array( '/collections-x/' ),
			'new catalogue' => array( '/patterns/' ),
			'new series'    => array( '/pattern-category/pattern-organic/' ),
			'pattern pdp'   => array( '/pattern/agave/' ),
			'inquiry'       => array( '/inquiry/' ),
			'root'          => array( '/' ),
		);
	}

	/**
	 * The query string the old address arrived with travels with it.
	 *
	 * The catalogue's own tools submit `?pattern_search=` and `?sort_by=`, so
	 * an indexed or bookmarked filtered view must land on the same filtered
	 * view at its new address, not on a bare page that silently drops what the
	 * visitor asked for.
	 */
	public function test_the_query_string_travels_with_the_path(): void {
		$live = static fn ( string $slug ): bool => in_array( $slug, self::SERIES, true );

		self::assertSame( '/patterns/?pattern_search=lattice', md_legacy_urls_url( '/shop/?pattern_search=lattice', $live ) );
		self::assertSame( '/patterns/?sort_by=name', md_legacy_urls_url( '/shop/?sort_by=name', $live ) );
		self::assertSame(
			'/pattern-category/pattern-organic/?pattern_search=moroccan',
			md_legacy_urls_url( '/collections/pattern-organic/?pattern_search=moroccan', $live )
		);
		self::assertSame( '', md_legacy_urls_url( '/patterns/?pattern_search=lattice', $live ) );
	}

	/**
	 * The alias fires on `template_redirect` at priority 1 — after WordPress
	 * has parsed the request (so the rule answers whether the legacy path
	 * still resolves or already 404s) and before `redirect_canonical` at 10,
	 * so a legacy URL reaches its new home in one hop.
	 */
	public function test_the_alias_runs_before_wordpress_own_canonical_redirect(): void {
		$hooked = $GLOBALS['md_mu_test_hooks']['template_redirect'] ?? array();
		$found  = array();
		foreach ( $hooked as $entry ) {
			if ( 'md_legacy_urls_redirect' === $entry[0] ) {
				$found[] = $entry[1];
			}
		}
		self::assertSame( array( 1 ), $found, 'the alias is attached once, ahead of redirect_canonical' );
	}

	/**
	 * The 301 itself: status, absolute destination, and the request read from
	 * `REQUEST_URI`.
	 *
	 * `md_mu_test_redirect_fails` is set because the plugin stops the request
	 * (`exit`) only once the redirect was issued — WordPress's own idiom, and
	 * the reason a failed redirect still renders the page it was on. The
	 * stand-in answers "issued" by default, which would end the test process,
	 * so the recording is read while pretending the header could not go out:
	 * the destination and the status are what this asserts, and they are
	 * decided before the redirect is attempted.
	 */
	public function test_the_request_redirects_permanently_to_the_new_address(): void {
		$GLOBALS['md_mu_test_redirect_fails'] = true;
		$_SERVER['REQUEST_URI']               = '/collections/pattern-organic/?pattern_search=moroccan';

		try {
			md_legacy_urls_redirect();
		} finally {
			unset( $GLOBALS['md_mu_test_redirect_fails'] );
		}

		self::assertSame(
			array( 'https://site.test/pattern-category/pattern-organic/?pattern_search=moroccan', 301 ),
			$GLOBALS['md_mu_test_redirect'] ?? null
		);
	}

	/**
	 * A request that is not a legacy path redirects nowhere.
	 */
	public function test_a_current_path_is_not_redirected(): void {
		$_SERVER['REQUEST_URI'] = '/patterns/?pattern_search=lattice';

		md_legacy_urls_redirect();

		self::assertArrayNotHasKey( 'md_mu_test_redirect', $GLOBALS );
	}

	/**
	 * An admin screen is left alone: it is not a customer following an old
	 * link, and a redirect there would break the thing that keeps the site
	 * running.
	 */
	public function test_an_admin_request_is_not_redirected(): void {
		$GLOBALS['md_mu_test_is_admin'] = true;
		$_SERVER['REQUEST_URI']         = '/shop/';

		md_legacy_urls_redirect();

		self::assertArrayNotHasKey( 'md_mu_test_redirect', $GLOBALS );
	}

	/**
	 * An AJAX request is left alone for the same reason.
	 */
	public function test_an_ajax_request_is_not_redirected(): void {
		$GLOBALS['md_mu_test_doing_ajax'] = true;
		$_SERVER['REQUEST_URI']           = '/shop/';

		md_legacy_urls_redirect();

		self::assertArrayNotHasKey( 'md_mu_test_redirect', $GLOBALS );
	}

	/**
	 * The REST API is left alone too.
	 *
	 * `REST_REQUEST` is a constant WP defines before the request is routed,
	 * so it cannot be undefined inside one process: the case runs in its own
	 * process, where the bootstrap has not seen it yet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_rest_api_is_not_redirected(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- REST_REQUEST is WordPress's own constant, and defining it is how a process says "this request is the REST API".
		define( 'REST_REQUEST', true );
		$_SERVER['REQUEST_URI'] = '/shop/';

		md_legacy_urls_redirect();

		self::assertArrayNotHasKey( 'md_mu_test_redirect', $GLOBALS );
	}

	/**
	 * The live-series lookup asks WordPress for a `product_cat` term and
	 * answers false when none carries the slug.
	 */
	public function test_the_live_series_lookup_reads_the_product_category(): void {
		self::assertTrue( md_legacy_urls_series_exists( 'pattern-organic' ) );
		self::assertFalse( md_legacy_urls_series_exists( 'arabesque-patterns' ), 'a deleted old-site collection is not a series here' );
	}
}
