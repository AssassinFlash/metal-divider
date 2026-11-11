<?php
/**
 * Unit tests for Catalog `?q=` search parameter handling and 9-language
 * semantic motif/series query expansion.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Tests\TestCase;
use Mockery;
use WP_Mock;

/**
 * Verifies predictive-search Enter submissions (`?q=...`) and cross-language
 * motif/series resolution on the catalog and REST search queries.
 *
 * @covers \MetalDivider\Site\Catalog
 */
final class CatalogTest extends TestCase {

	/**
	 * Cleans request globals after each test case.
	 */
	public function tearDown(): void {
		unset(
			$GLOBALS['wp_query'],
			$GLOBALS['wpdb'],
			$_GET['pattern_search'],
			$_GET['q'],
			$_GET['s'],
			$_GET['sort_by']
		);
		Catalog::reset_visible_counts();
		parent::tearDown();
	}

	/**
	 * Registers both `pre_get_posts` and `posts_search` filters alongside all
	 * catalog shortcodes.
	 */
	public function test_register_hooks_archive_and_semantic_search_filters(): void {
		$catalog = new Catalog();

		WP_Mock::userFunction( 'add_shortcode' )->times( 15 );
		WP_Mock::expectActionAdded( 'pre_get_posts', array( $catalog, 'filter_query' ), 20 );
		WP_Mock::expectFilterAdded( 'posts_search', array( $catalog, 'expand_semantic_product_search' ), 20, 2 );

		$catalog->register();
		$this->assertConditionsMet();
	}

	/**
	 * Submitting the predictive-search modal form via Enter (`?q=...`) filters
	 * the main archive query, fills the search input, preserves the search in
	 * the sort form, and updates the result summary and empty state.
	 */
	public function test_q_query_param_drives_filter_summary_input_preserve_and_empty_state(): void {
		$this->mock_text_helpers();
		WP_Mock::userFunction( 'is_admin' )->once()->andReturn( false );
		$_GET['q'] = '树叶';

		/**
		 * Typed WP_Query mock.
		 *
		 * @var \WP_Query&\Mockery\MockInterface $query
		 */
		$query = Mockery::mock( \WP_Query::class );
		$query->shouldReceive( 'is_main_query' )->once()->andReturn( true );
		$query->shouldReceive( 'is_post_type_archive' )->with( 'product' )->once()->andReturn( true );
		$query->shouldReceive( 'set' )->with( 'posts_per_page', 24 )->once();
		$query->shouldReceive( 'set' )->with( 's', '树叶' )->once();
		$query->shouldReceive( 'set' )->with( 'orderby', 'title' )->once();
		$query->shouldReceive( 'set' )->with( 'order', 'ASC' )->once();

		$catalog = new Catalog();
		$catalog->filter_query( $query );

		$this->assertSame( '树叶', $catalog->search_value() );
		$this->assertSame(
			'<input type="hidden" name="pattern_search" value="树叶">',
			$catalog->preserve_param( array( 'name' => 'pattern_search' ) )
		);

		$main_query                = new \WP_Query();
		$main_query->found_posts   = 5;
		$main_query->max_num_pages = 1;
		$GLOBALS['wp_query']       = $main_query;

		$summary = $catalog->summary();
		$this->assertStringContainsString( 'Search results for "树叶"', $summary );
		$this->assertStringContainsString( '<strong>5</strong> patterns', $summary );

		$main_query->found_posts = 0;
		$empty_html              = $catalog->empty_state();
		$this->assertStringContainsString( 'No patterns match &ldquo;树叶&rdquo;', $empty_html );
	}

	/**
	 * When both `pattern_search` and `q` are present, `pattern_search` takes
	 * precedence; when `pattern_search` is blank, `q` is used.
	 */
	public function test_pattern_search_takes_precedence_over_q_unless_blank(): void {
		$this->mock_text_helpers();
		$_GET['pattern_search'] = 'Bamboo';
		$_GET['q']              = 'Agave';

		$catalog = new Catalog();
		$this->assertSame( 'Bamboo', $catalog->search_value() );

		$_GET['pattern_search'] = '   ';
		$this->assertSame( 'Agave', $catalog->search_value() );
	}

	/**
	 * Resolves all 12 Chinese motif/style terms and compound phrases into
	 * canonical ACF motif keys, `product_cat` series slugs, and English stems.
	 */
	public function test_resolve_semantic_targets_for_chinese_motif_and_series_terms(): void {
		$expected_motifs = array(
			'树叶'   => 'leaf',
			'树叶图案' => 'leaf',
			'竹'    => 'bamboo',
			'竹屏风'  => 'bamboo',
			'花卉'   => 'floral',
			'几何'   => 'geometric',
			'摩洛哥'  => 'moroccan',
			'冲孔'   => 'perforated',
			'山水'   => 'landscape',
			'东方'   => 'asian',
			'复古'   => 'vintage-retro',
			'抽象'   => 'abstract',
			'自然'   => 'organic',
		);

		foreach ( $expected_motifs as $query => $motif ) {
			$targets = Catalog::resolve_semantic_targets( $query );
			$this->assertContains(
				$motif,
				$targets['motifs'],
				sprintf( 'Expected Chinese query "%s" to resolve motif "%s".', $query, $motif )
			);
		}

		$gradient = Catalog::resolve_semantic_targets( '渐变' );
		$this->assertContains( 'pattern-gradient', $gradient['series'] );
		$this->assertContains( 'Gradient', $gradient['keywords'] );
	}

	/**
	 * Resolves native motif/series vocabulary across the remaining languages
	 * (`es`, `pt-br`, `de`, `fr`, `ar`, `id`, `vi`) without false positives on
	 * unrelated pattern names.
	 */
	public function test_resolve_semantic_targets_across_nine_languages_without_false_positives(): void {
		$cases = array(
			'hojas'       => 'leaf',
			'folhas'      => 'leaf',
			'Blätter'     => 'leaf',
			'feuilles'    => 'leaf',
			'أوراق شجر'   => 'leaf',
			'خيزران'      => 'bamboo',
			'daun'        => 'leaf',
			'mẫu lá'      => 'leaf',
			'tre trúc'    => 'bamboo',
			'geométrico'  => 'geometric',
			'geometrisch' => 'geometric',
			'marocain'    => 'moroccan',
		);

		foreach ( $cases as $query => $motif ) {
			$targets = Catalog::resolve_semantic_targets( $query );
			$this->assertContains(
				$motif,
				$targets['motifs'],
				sprintf( 'Expected query "%s" to resolve motif "%s".', $query, $motif )
			);
		}

		$unrelated = Catalog::resolve_semantic_targets( 'Fantasia Agave' );
		$this->assertSame( array(), $unrelated['motifs'] );
		$this->assertSame( array(), $unrelated['series'] );
		$this->assertSame( array(), $unrelated['keywords'] );
	}

	/**
	 * Expands `posts_search` SQL for product and multi-type REST search queries
	 * while preserving top-level `post_password = ''` and ignoring blog queries.
	 */
	public function test_expand_semantic_product_search_builds_safe_product_or_branch(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb();
		$catalog         = new Catalog();

		/**
		 * Typed WP_Query mock for REST multi-type search.
		 *
		 * @var \WP_Query&\Mockery\MockInterface $product_query
		 */
		$product_query = Mockery::mock( \WP_Query::class );
		$product_query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( array( 'post', 'page', 'product' ) );
		$product_query->shouldReceive( 'get' )->with( 's' )->andReturn( '几何' );

		$raw_sql  = " AND (((wp_posts.post_title LIKE '%几何%') OR (wp_posts.post_content LIKE '%几何%'))) AND (wp_posts.post_password = '') ";
		$expanded = $catalog->expand_semantic_product_search( $raw_sql, $product_query );

		$this->assertStringContainsString( "OR (`wp_posts`.post_type = 'product' AND (", $expanded );
		$this->assertStringContainsString( "md_pm.meta_key = 'motifs' AND md_pm.meta_value LIKE '%\"geometric\"%'", $expanded );
		$this->assertStringContainsString( "md_t.slug = 'pattern-geometric'", $expanded );
		$this->assertStringContainsString( "md_t.slug = 'pattern-geometric-zh'", $expanded );
		$this->assertStringContainsString( "md_t.slug = 'pattern-geometric-zh-hans'", $expanded );
		$this->assertStringContainsString( "`wp_posts`.post_title LIKE '%Geometric%'", $expanded );
		$this->assertStringEndsWith( "AND (wp_posts.post_password = '') ", $expanded );

		/**
		 * Typed WP_Query mock for blog search.
		 *
		 * @var \WP_Query&\Mockery\MockInterface $blog_query
		 */
		$blog_query = Mockery::mock( \WP_Query::class );
		$blog_query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'post' );
		$blog_query->shouldReceive( 'is_post_type_archive' )->with( 'product' )->andReturn( false );
		$blog_query->shouldReceive( 'is_tax' )->with( 'product_cat' )->andReturn( false );

		$this->assertSame(
			$raw_sql,
			$catalog->expand_semantic_product_search( $raw_sql, $blog_query ),
			'Non-product queries must remain untouched.'
		);
	}

	/**
	 * Mocks WordPress text/URL helpers used by Catalog output methods.
	 */
	private function mock_text_helpers(): void {
		$this->plain_text();
		WP_Mock::passthruFunction( 'sanitize_text_field' );
		WP_Mock::passthruFunction( 'wp_unslash' );
		WP_Mock::userFunction( 'get_query_var' )->with( 'paged' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_queried_object' )->andReturn( null );
		WP_Mock::userFunction( 'get_post_type_archive_link' )->with( 'product' )->andReturn( 'https://site.test/patterns/' );
	}

	/**
	 * Minimal wpdb mock implementing `%i` / `%s` `prepare` and `esc_like`.
	 *
	 * @return \wpdb&\Mockery\MockInterface
	 */
	private function fake_wpdb() {
		/**
		 * Typed wpdb mock.
		 *
		 * @var \wpdb&\Mockery\MockInterface $wpdb
		 */
		$wpdb                     = Mockery::mock( \wpdb::class );
		$wpdb->posts              = 'wp_posts';
		$wpdb->postmeta           = 'wp_postmeta';
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
					'/%[is]/',
					static function ( array $token ) use ( $args, &$idx ): string {
						$raw = isset( $args[ $idx ] ) && is_scalar( $args[ $idx ] ) ? (string) $args[ $idx ] : '';
						++$idx;
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
}
