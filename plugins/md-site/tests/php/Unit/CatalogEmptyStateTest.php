<?php
/**
 * Tests for the catalog's empty state — the one thing both catalog faces
 * showed nothing for when a search matched nothing.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * `Catalog::empty_state()`, and the pager it has to agree with.
 *
 * The two faces render the same grid, the same results bar and the same pager,
 * and neither carried any markup for the case the grid is empty. A visitor who
 * searched for a pattern the catalog does not carry saw "… 0 patterns" over an
 * empty grid, a pager that prints nothing (it needs two pages), and the rest of
 * the page — never a statement that the search failed and never a way back
 * except the browser's own back button.
 *
 * The styling was already there: `.pattern-catalog__empty` in the pattern-grid
 * volume carries the card surface, the Playfair heading and the grid-span rule
 * for sitting inside `.pattern-catalog__products`. So the assertions below hold
 * the markup to that stylesheet — the class the CSS targets, the `h3` and `p`
 * children it styles — as well as to the two facts a visitor needs: which term
 * found nothing, and a link that drops it.
 *
 * Every case drives the real `$GLOBALS['wp_query']`, because that is what the
 * state reads on a live request: `found_posts` is the same number the loop and
 * the pager use, which is what makes "state shown" and "grid empty" impossible
 * to disagree.
 *
 * @covers \MetalDivider\Site\Catalog
 */
final class CatalogEmptyStateTest extends TestCase {

	/**
	 * Removes the request state a case installed: the query, its inputs and the
	 * memoized series tally (the suite runs every case in one process).
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wp_query'], $_GET['pattern_search'], $_GET['sort_by'] );
		Catalog::reset_visible_counts();
		parent::tearDown();
	}

	/**
	 * A search that matched nothing names the term and offers the way out: a
	 * link to the archive's own base address, which is the catalog with no
	 * search and no order — the page the visitor started from.
	 */
	public function test_a_search_with_no_match_names_the_term_and_links_back(): void {
		$this->given_empty_query( 'shop' );
		$_GET['pattern_search'] = 'velvet lattice';

		$html = ( new Catalog() )->empty_state();

		$this->assertStringContainsString( 'No patterns match', $html );
		$this->assertStringContainsString( 'velvet lattice', $html, 'the term the visitor typed is named' );
		$this->assertStringContainsString(
			'<a href="https://site.test/shop/">clear the search</a>',
			$html,
			'the way out is the archive itself, not the browser back button'
		);
		$this->assertStringContainsString( 'pattern-catalog__empty', $html, 'the class the stylesheet targets' );
	}

	/**
	 * The series archive's clear link is that series, not the shop: a visitor
	 * who searched inside Organic and found nothing clears the search and stays
	 * in Organic.
	 */
	public function test_a_series_archive_clears_its_search_back_to_itself(): void {
		$this->given_empty_query( 'pattern-organic' );
		$_GET['pattern_search'] = 'zzz';

		$html = ( new Catalog() )->empty_state();

		$this->assertStringContainsString(
			'<a href="https://site.test/collections/pattern-organic/">clear the search</a>',
			$html,
			'the series the request resolved to, never a literal'
		);
		$this->assertStringNotContainsString( 'https://site.test/shop/', $html, 'the series face does not send the visitor to the shop' );
	}

	/**
	 * An empty series reached by URL — the shop never links to one — carries a
	 * different statement: there is no search to clear, so the state says the
	 * series is empty and points at the shop, where every series that does
	 * carry patterns is listed.
	 */
	public function test_an_empty_series_says_so_and_points_at_the_shop(): void {
		$this->given_empty_query( 'pattern-organic' );

		$html = ( new Catalog() )->empty_state();

		$this->assertStringContainsString( 'No patterns here yet', $html );
		$this->assertStringNotContainsString( 'clear the search', $html, 'there is no search to clear' );
		$this->assertStringContainsString( '<a href="https://site.test/shop/">Browse all patterns</a>', $html );
	}

	/**
	 * Normal results are untouched: the state is absent whenever the query
	 * found a pattern, so a grid with cards cannot also carry "no patterns".
	 * This is the assertion that keeps the change from reaching the 146-pattern
	 * catalog the visitor normally sees.
	 */
	public function test_a_query_with_results_renders_no_empty_state(): void {
		$this->given_query( 12, 1 );

		$this->assertSame( '', ( new Catalog() )->empty_state() );
	}

	/**
	 * With no main query there is nothing to state — the arms answer '' rather
	 * than announcing an empty catalog a template render never saw.
	 */
	public function test_no_main_query_renders_no_empty_state(): void {
		$this->assertSame( '', ( new Catalog() )->empty_state() );
	}

	/**
	 * The pager stays out of the empty state: `pagination()` needs two pages,
	 * and a search that matched nothing has one page of nothing. This is the
	 * pair the visitor sees — the state, and no "1 2 3" beside it.
	 */
	public function test_the_empty_state_comes_with_no_pager(): void {
		$this->given_empty_query( 'shop' );
		$_GET['pattern_search'] = 'velvet lattice';
		$catalog                = new Catalog();

		$this->assertNotSame( '', $catalog->empty_state() );
		$this->assertSame( '', $catalog->pagination(), 'no pager on a zero-result search' );
	}

	/**
	 * …and the pager is still there for the results the state is not shown
	 * for, so the two arms agree in both directions.
	 */
	public function test_a_query_with_pages_still_renders_the_pager(): void {
		$this->given_query( 146, 7 );
		WP_Mock::userFunction( 'get_query_var' )->andReturnUsing(
			static fn( string $name ): int => 'paged' === $name ? 1 : 0
		);
		WP_Mock::userFunction( 'paginate_links' )->andReturn( array( '<a class="page-numbers" href="#">1</a>', '<a class="page-numbers" href="#">2</a>' ) );

		$catalog = new Catalog();

		$this->assertSame( '', $catalog->empty_state() );
		$this->assertStringContainsString( 'pattern-catalog__pagination', $catalog->pagination() );
	}

	/**
	 * The markup is the stylesheet's: the class the empty state prints is the
	 * one `pattern-grid.css` styles, with the `h3` and `p` children its rules
	 * target. A renamed class would render an unstyled paragraph block, and
	 * nothing else in the suite would notice — the CSS volume is hand-written
	 * and does not follow the markup.
	 */
	public function test_the_empty_state_uses_the_class_the_stylesheet_styles(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/content/windpress/pattern-grid.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( '.pattern-catalog__empty {', $css, 'the stylesheet carries the state\'s surface' );
		$this->assertStringContainsString( '.pattern-catalog__empty h3 {', $css );
		$this->assertStringContainsString( '.pattern-catalog__empty p {', $css );
		$this->assertStringContainsString(
			'.pattern-catalog__products .pattern-catalog__empty {',
			$css,
			'the grid span the template relies on by placing the state inside the grid'
		);

		$this->given_empty_query( 'shop' );
		$html = ( new Catalog() )->empty_state();
		$this->assertStringContainsString( '<h3>', $html, 'the heading the stylesheet styles' );
		$this->assertStringContainsString( '<p>', $html, 'the body copy the stylesheet styles' );
	}

	/**
	 * Both catalog faces render the state, inside the grid the stylesheet
	 * spans: one face fixed and the other forgotten is exactly the half-fixed
	 * state this test exists for.
	 */
	public function test_both_catalog_faces_render_the_empty_state_inside_the_grid(): void {
		foreach ( array( 'shop', 'series-archive' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

			$grid_start = strpos( $source, 'class="pattern-catalog__products"' );
			$this->assertNotFalse( $grid_start, "{$face} carries the pattern grid" );
			$grid = substr( $source, $grid_start, 900 );

			$this->assertStringContainsString( '[md_catalog_empty]', $grid, "{$face}: the state lives inside the grid, where the CSS spans it" );
			$this->assertStringContainsString( '[md_pattern_pagination]', $source, "{$face}: the pager is still asked for (it prints nothing on zero results)" );
		}
	}

	/**
	 * The condition is the main query's own count, and it reads the same
	 * property the loop and the pager read — no second query, no cached flag to
	 * fall out of step with the grid it describes.
	 */
	public function test_the_condition_is_the_main_query_count(): void {
		$this->given_empty_query( 'shop' );
		$this->assertNotSame( '', ( new Catalog() )->empty_state(), 'found_posts 0 is the empty state' );

		$this->given_query( 1, 1 );
		$this->assertSame( '', ( new Catalog() )->empty_state(), 'one pattern is not the empty state' );
	}

	/**
	 * Installs the main query and the WordPress reads the arms make of it.
	 *
	 * @param int $found The posts the query matched.
	 * @param int $pages The pages those posts span.
	 */
	private function given_query( int $found, int $pages ): void {
		$query                = new \WP_Query();
		$query->found_posts   = $found;
		$query->max_num_pages = $pages;
		$GLOBALS['wp_query']  = $query;
	}

	/**
	 * The empty-query fixture for one face: no results, and the face's own base
	 * address, which is what the clear-search link has to resolve to.
	 *
	 * @param string $face `shop`, or a `product_cat` slug.
	 */
	private function given_empty_query( string $face ): void {
		$this->given_query( 0, 0 );

		$term = null;
		if ( 'shop' !== $face ) {
			$term           = new \WP_Term( (object) array() );
			$term->slug     = $face;
			$term->taxonomy = 'product_cat';
		}
		WP_Mock::userFunction( 'get_queried_object' )->andReturn( $term );
		WP_Mock::userFunction( 'get_term_link' )->andReturnUsing(
			static fn( \WP_Term $given ): string => 'https://site.test/collections/' . $given->slug . '/'
		);
		WP_Mock::userFunction( 'get_post_type_archive_link' )->andReturn( 'https://site.test/shop/' );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( string $value ): string => trim( $value )
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing(
			static fn( string $value ): string => $value
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES )
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			static fn( string $value ): string => $value
		);
	}
}
