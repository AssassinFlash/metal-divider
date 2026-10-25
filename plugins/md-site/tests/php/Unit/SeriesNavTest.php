<?php
/**
 * Tests for the series archive's navigation data arms.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;
use WP_Term;

/**
 * Tests for Catalog::series_nav(), series_current_url() and
 * series_current_label(). The template used to carry one hand-written
 * `is-active` on the Organic link, so every series archive pointed the
 * visitor at Organic; these tests hold the active state to the queried
 * term instead, which is what makes them discriminating: the Organic
 * case passes under the old code too, the gradient and geometric cases
 * do not.
 *
 * @covers \MetalDivider\Site\Catalog
 */
final class SeriesNavTest extends TestCase {

	/**
	 * The series whose products are all hidden from the catalog, set by the
	 * case that models an empty-but-present series.
	 *
	 * @var list<string>
	 */
	private static array $hidden = array();

	/**
	 * Clears the memoized visible-count tally between cases: the suite runs
	 * in one process, so a tally built for one fixture would answer for the
	 * next.
	 */
	public function tearDown(): void {
		Catalog::reset_visible_counts();
		self::$hidden = array();
		parent::tearDown();
	}

	/**
	 * The archive's own term is the one marked, whatever series it is —
	 * and exactly one link carries the mark.
	 *
	 * @dataProvider series_slugs
	 *
	 * @param string $slug The series the request resolved to.
	 */
	public function test_the_queried_term_is_the_one_active_link( string $slug ): void {
		$this->given_terms( $slug );
		$nav = ( new Catalog() )->series_nav();

		$this->assertSame( 1, substr_count( $nav, 'is-active' ), "{$slug}: exactly one series is current" );
		$this->assertStringContainsString(
			sprintf( 'class="pattern-catalog__nav-link is-active" aria-current="page"><span>%s</span>', $this->label_of( $slug ) ),
			$nav,
			"{$slug}: the current term's own link carries is-active"
		);
		$this->assertStringNotContainsString(
			'class="pattern-catalog__nav-link"><span>' . $this->label_of( $slug ) . '</span>',
			$nav,
			"{$slug}: the current term's link is not also rendered as a plain one"
		);
	}

	/**
	 * The series the active-state test runs against: the two the old
	 * hardcode got wrong (gradient, which had no entry at all, and
	 * geometric) plus Organic, the one it named.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function series_slugs(): array {
		return array(
			'gradient'  => array( 'pattern-gradient' ),
			'geometric' => array( 'pattern-geometric' ),
			'organic'   => array( 'pattern-organic' ),
		);
	}

	/**
	 * The Organic link is not marked on another series — the regression
	 * this test exists for.
	 */
	public function test_another_series_never_marks_organic_active(): void {
		$this->given_terms( 'pattern-gradient' );
		$nav = ( new Catalog() )->series_nav();

		$this->assertStringNotContainsString( 'is-active" aria-current="page"><span>Organic Patterns', $nav );
		$this->assertStringContainsString( 'class="pattern-catalog__nav-link"><span>Organic Patterns</span>', $nav );
	}

	/**
	 * The counts are the terms' own, so the gradient series shows its
	 * three patterns and geometric its twelve (the migration's split,
	 * not the old sidebar's static 0 and 15).
	 */
	public function test_the_counts_come_from_the_visible_catalog_membership(): void {
		$this->given_terms( 'pattern-organic' );
		$nav = ( new Catalog() )->series_nav();

		$this->assertStringContainsString( '<span class="pattern-catalog__count">3</span>', $nav );
		$this->assertStringContainsString( '<span class="pattern-catalog__count">12</span>', $nav );
		$this->assertStringContainsString( '<span class="pattern-catalog__count">16</span>', $nav );
	}

	/**
	 * The count is never the term's own value.
	 *
	 * The fixture gives every term `count = 999` and a separate product
	 * table; a nav that prints `$term->count` shows 999 and fails here. This
	 * is the guard for the whole class of bug: WooCommerce's `get_terms`
	 * filter rewrites `$term->count` to a cached meta value, so the term is
	 * not an authority on how many patterns a series has.
	 */
	public function test_the_count_is_never_the_terms_own_value(): void {
		$this->given_terms( 'pattern-organic' );
		$nav = ( new Catalog() )->series_nav();

		$this->assertStringNotContainsString( '>999<', $nav, 'the term count is ignored' );
	}

	/**
	 * A series whose patterns are all hidden from the catalog is not in the
	 * navigation.
	 *
	 * This is the case raw term data gets wrong in the other direction:
	 * `hide_empty` is decided in SQL against `wp_term_taxonomy.count`, which
	 * still counts catalog-hidden products, so the term survives and links
	 * to an archive that lists nothing. The visible tally drops it.
	 */
	public function test_a_series_with_no_visible_pattern_is_not_listed(): void {
		self::$hidden = array( 'pattern-perforated' );
		$this->given_terms( 'pattern-organic' );
		$nav = ( new Catalog() )->series_nav();

		$this->assertStringNotContainsString( 'Perforated Patterns', $nav, 'the empty series is gone' );
		$this->assertStringContainsString( 'Organic Patterns', $nav, 'a series with visible patterns is still listed' );
		$this->assertSame( 8, substr_count( $nav, 'pattern-catalog__nav-link' ), 'the other eight remain' );
	}

	/**
	 * Every term reaches the navigation, including one the order table
	 * does not name: a series must not vanish from the sidebar by being
	 * forgotten in a list.
	 */
	public function test_a_term_outside_the_order_table_still_renders(): void {
		$this->given_terms( 'pattern-organic', array( 'pattern-new-family' => array( 'New Family', 2 ) ) );
		$nav = ( new Catalog() )->series_nav();

		$this->assertStringContainsString( '<span>New Family Patterns</span>', $nav );
	}

	/**
	 * Outside a series archive (a page, a post) the arms answer nothing
	 * rather than a list with no current position — except the shop,
	 * which declares its face and gets the unmarked list.
	 */
	public function test_outside_a_series_archive_the_arms_answer_nothing(): void {
		WP_Mock::userFunction( 'get_queried_object' )->andReturn( null );
		WP_Mock::userFunction( 'shortcode_atts' )->andReturnUsing(
			static fn( array $pairs, array $atts ): array => array_merge( $pairs, $atts )
		);

		$catalog = new Catalog();
		$this->assertSame( '', $catalog->series_nav() );
		$this->assertSame( '', $catalog->series_current_url() );
		$this->assertSame( '', $catalog->series_current_label() );
	}

	/**
	 * The shop's sidebar lists the series unmarked: the shop's own
	 * "All Patterns" link holds the position, so no series may claim it.
	 * The face is declared by the template (`face="shop"`) rather than
	 * inferred from `is_shop()`, which is a WooCommerce conditional no
	 * unit test can satisfy off a live store.
	 */
	public function test_the_shop_face_lists_the_series_without_a_current_one(): void {
		$this->given_terms( null );
		$nav = ( new Catalog() )->series_nav( array( 'face' => 'shop' ) );

		$this->assertStringNotContainsString( 'is-active', $nav, 'the shop marks its own All Patterns link, never a series' );
		$this->assertStringNotContainsString( 'aria-current', $nav );
		$this->assertSame( 9, substr_count( $nav, 'pattern-catalog__nav-link' ), 'every series is still listed' );
		$this->assertStringContainsString( '<span>Gradient Patterns</span>', $nav, 'the restored series is in the shop list too' );
	}

	/**
	 * A template that asks for the shop face on a series archive still
	 * marks the queried term: the face attribute only waives the
	 * "must be a term archive" guard, it does not blank the position.
	 */
	public function test_the_shop_face_still_marks_a_queried_term(): void {
		$this->given_terms( 'pattern-gradient' );
		$nav = ( new Catalog() )->series_nav( array( 'face' => 'shop' ) );

		$this->assertSame( 1, substr_count( $nav, 'is-active' ) );
		$this->assertStringContainsString( 'is-active" aria-current="page"><span>Gradient Patterns</span>', $nav );
	}

	/**
	 * The breadcrumb's item is the queried term's own URL and label, so
	 * the trail and the sidebar cannot disagree.
	 */
	public function test_the_breadcrumb_follows_the_queried_term(): void {
		$this->given_terms( 'pattern-gradient' );
		$catalog = new Catalog();

		$this->assertSame( 'https://site.test/collections/pattern-gradient/', $catalog->series_current_url() );
		$this->assertSame( 'Gradient Patterns', $catalog->series_current_label() );
	}

	/**
	 * The fixture: the nine migrated series as the migrator wrote them
	 * (family name, member counts) plus any extra terms, and $queried as
	 * the request's term.
	 *
	 * `$term->count` is deliberately set to a **wrong** number — the term
	 * row's own value is not what the nav must print. The catalog-visible
	 * tally is built from a separate product table (`given_products()`), so
	 * a nav that reads `$term->count` prints the wrong number and fails.
	 *
	 * @param string|null                             $queried The slug the archive resolved to, null for a non-term request (the shop).
	 * @param array<string, array{0: string, 1: int}> $extra   Additional terms, slug => array( name, count ).
	 */
	private function given_terms( ?string $queried, array $extra = array() ): void {
		$terms = array(
			'pattern-abstract'        => array( 'Abstract', 90 ),
			'pattern-asian'           => array( 'Asian Inspired', 4 ),
			'pattern-geometric'       => array( 'Geometric', 12 ),
			'pattern-gradient'        => array( 'Gradient', 3 ),
			'pattern-landscape'       => array( 'Landscape', 8 ),
			'pattern-moroccan-arabic' => array( 'Moroccan & Arabic', 8 ),
			'pattern-organic'         => array( 'Organic', 16 ),
			'pattern-perforated'      => array( 'Perforated', 1 ),
			'pattern-vintage-retro'   => array( 'Vintage & Retro', 4 ),
		);
		foreach ( $extra as $slug => $row ) {
			$terms[ $slug ] = $row;
		}
		$objects = array();
		$id      = 0;
		foreach ( $terms as $slug => $row ) {
			$term          = new WP_Term( (object) array() );
			$term->term_id = ++$id;
			$term->slug    = $slug;
			$term->name    = $row[0];
			// The trap: a term count the nav must ignore.
			$term->count    = 999;
			$term->taxonomy = 'product_cat';
			$objects[]      = $term;
		}
		WP_Mock::userFunction( 'get_terms' )->andReturn( $objects );
		$this->given_products( $terms, $queried, $objects );
		WP_Mock::userFunction( 'shortcode_atts' )->andReturnUsing(
			static function ( array $pairs, array $atts ): array {
				$out = $pairs;
				foreach ( $atts as $key => $value ) {
					if ( array_key_exists( $key, $pairs ) ) {
						$out[ $key ] = $value;
					}
				}
				return $out;
			}
		);
		WP_Mock::userFunction( 'get_queried_object' )->andReturnUsing(
			static function () use ( $objects, $queried ) {
				foreach ( $objects as $term ) {
					if ( $term->slug === $queried ) {
						return $term;
					}
				}
				return null;
			}
		);
		WP_Mock::userFunction( 'get_term_link' )->andReturnUsing(
			static fn( WP_Term $term ): string => 'https://site.test/collections/' . $term->slug . '/'
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		foreach ( array(
			'esc_url'  => static fn( string $value ): string => $value,
			'esc_html' => static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES ),
		) as $name => $callback ) {
			WP_Mock::userFunction( $name )->andReturnUsing( $callback );
		}
	}

	/**
	 * The catalog-visible product table the tally is built from: one id per
	 * visible pattern in each series, so a series' count is the number of
	 * products whose series matches it. `self::$hidden` names a
	 * series whose products are all hidden from the catalog.
	 *
	 * @param array<string, array{0: string, 1: int}> $terms   The terms, slug => array( name, count ).
	 * @param string|null                             $queried The queried slug (unused here; see given_terms()).
	 * @param array<int, WP_Term>                     $objects The term objects (unused here).
	 */
	private function given_products( array $terms, ?string $queried, array $objects ): void {
		unset( $queried, $objects );
		$hidden = self::$hidden;
		/**
		 * The product table the tally reads, keyed by product id: the series
		 * slug the product belongs to, or null for a product the catalog
		 * hides. Every id in the table has a row, so `array_key_exists()`
		 * separates "no such product" from "hidden product" — the two cases
		 * the double below must answer differently.
		 *
		 * @var array<int, string|null> $rows
		 */
		$rows = array();
		$id   = 0;
		foreach ( $terms as $slug => $row ) {
			for ( $i = 0; $i < $row[1]; $i++ ) {
				++$id;
				$rows[ $id ] = in_array( $slug, $hidden, true ) ? null : $slug;
			}
		}
		WP_Mock::userFunction( 'get_posts' )->andReturnUsing(
			/**
			 * The catalog-visible products' ids: the rows whose value is a
			 * series slug.
			 *
			 * @return list<int> The product ids.
			 */
			static function () use ( $rows ): array {
				return array_keys( array_filter( $rows ) );
			}
		);
		WP_Mock::userFunction( 'wp_get_object_terms' )->andReturnUsing(
			/**
			 * The terms of the ids the catalog asked for — one term per
			 * visible product, silently skipping a hidden or unknown one.
			 *
			 * @param list<int> $ids The product ids the tally projected.
			 * @return list<WP_Term> One term per visible product.
			 */
			static function ( array $ids ) use ( $rows ): array {
				$out = array();
				foreach ( $ids as $id ) {
					if ( ! array_key_exists( $id, $rows ) || null === $rows[ $id ] ) {
						continue;
					}
					$term       = new WP_Term( (object) array() );
					$term->slug = $rows[ $id ];
					$out[]      = $term;
				}
				return $out;
			}
		);
	}

	/**
	 * The label the nav renders for a slug (the fixture's name + noun).
	 *
	 * @param string $slug The series slug.
	 */
	private function label_of( string $slug ): string {
		$names = array(
			'pattern-abstract'        => 'Abstract',
			'pattern-asian'           => 'Asian Inspired',
			'pattern-geometric'       => 'Geometric',
			'pattern-gradient'        => 'Gradient',
			'pattern-landscape'       => 'Landscape',
			'pattern-moroccan-arabic' => 'Moroccan &amp; Arabic',
			'pattern-organic'         => 'Organic',
			'pattern-perforated'      => 'Perforated',
			'pattern-vintage-retro'   => 'Vintage &amp; Retro',
		);
		return $names[ $slug ] . ' Patterns';
	}
}
