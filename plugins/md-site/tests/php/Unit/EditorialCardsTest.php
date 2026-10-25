<?php
/**
 * Tests for the editorial faces' Woo-backed cards.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for the custom-screens pattern library and the home page's
 * pattern-direction cards.
 *
 * Both faces used to carry hand-written product snapshots: nineteen literal
 * tiles (one with no product behind it at all) and nine literal home cards,
 * each repeating a name, an image path and a spec value that nothing kept in
 * step with the catalog.
 *
 * The two faces curate differently and this suite is built so a regression in
 * either is visible: the tiles are WooCommerce's `menu_order` (a product the
 * owner has not ordered is not on the page, and the order is the owner's),
 * while the home cards lead with the featured flag. A test that passes under
 * both would prove nothing, so each case below asserts a fact only the
 * correct field can produce.
 *
 * @covers \MetalDivider\Site\Catalog
 */
final class EditorialCardsTest extends TestCase {

	/**
	 * The request's superglobal as this case found it, put back in tearDown().
	 *
	 * Three cases below drive the archive tools by writing `$_GET`, because
	 * that is where the tools read the request: `preserve_param()`,
	 * `search_value()` and `sort_selected()` answer from it, and the query
	 * filter reads the same keys. Those cases used to end with
	 * `unset( $_GET['pattern_search'] )`, which reads like a restore and is not
	 * one — it runs only when the method reaches its last line, so a failing
	 * assertion skipped it.
	 *
	 * The suite did not actually leak: `WP_Mock\Tools\TestCase::tearDown()`
	 * already clears `$_GET` (vendor/10up/wp_mock …/Tools/TestCase.php, the
	 * `$_GET = $_POST = $_REQUEST = []` line) and both setUp() and tearDown()
	 * here call the parent, which is what the injected-failure check confirmed.
	 * So this is not a repair; it is where the isolation belongs. Relying on
	 * the framework's own reset is relying on a detail of a dependency, and the
	 * per-method `unset()` misdescribes what keeps the cases apart: it says
	 * "this method cleans up", when the guarantee is the base class's. Holding
	 * the whole superglobal and writing it back here states the guarantee in
	 * the class that needs it, covers keys this class does not name, and
	 * survives a fatal error the framework's teardown might not reach.
	 *
	 * @var array<string, mixed>
	 */
	private array $get = array();

	/**
	 * The tiles are the series' curated members in `menu_order`, and each one
	 * links to its canonical pattern URL.
	 */
	public function test_tiles_render_the_curated_members_in_menu_order(): void {
		$this->mock_site();

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		$this->assertSame( 2, substr_count( $html, 'class="pattern-item"' ), 'one tile per curated member' );
		$this->assertStringContainsString( 'href="https://site.test/pattern/zebra-architectural-metal-screen-pattern/"', $html );
		$this->assertStringContainsString( 'href="https://site.test/pattern/alpha-architectural-metal-screen-pattern/"', $html );
		$this->assertStringNotContainsString( '/products/', $html, 'the old /products/ address never reaches the page' );
	}

	/**
	 * The sequence is `menu_order`, not the alphabet: a product ordered first
	 * but titled later leads. Under the old title-ordered query the assertion
	 * on the first tile fails, which is what makes this discriminating.
	 */
	public function test_the_tile_sequence_is_menu_order_not_the_title(): void {
		$this->mock_site();

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		$zebra = strpos( $html, 'data-pattern-name="Zebra"' );
		$alpha = strpos( $html, 'data-pattern-name="Alpha"' );
		$this->assertNotFalse( $zebra );
		$this->assertNotFalse( $alpha );
		$this->assertLessThan( $alpha, $zebra, 'menu_order 1 (Zebra) precedes menu_order 2 (Alpha)' );
	}

	/**
	 * A product the owner has not ordered is not curated, so it never renders
	 * — even though it belongs to the series. This is the behaviour that
	 * keeps the panels from filling up with the whole series again.
	 */
	public function test_a_product_without_a_menu_order_is_not_curated(): void {
		$this->mock_site();

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		$this->assertStringNotContainsString( 'data-pattern-name="Uncurated"', $html );
	}

	/**
	 * Catalog visibility is WooCommerce's: a product the owner hid from the
	 * catalog is absent from the tiles, exactly as it is absent from the shop
	 * archive.
	 */
	public function test_a_catalog_hidden_product_never_renders(): void {
		$this->mock_site();

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		$this->assertStringNotContainsString( 'data-pattern-name="Hidden"', $html );
	}

	/**
	 * The phantom name is gone for good: Kasbah never had a product, so no
	 * panel's output can contain it.
	 */
	public function test_the_phantom_name_cannot_appear(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$html    = $catalog->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		)
			. $catalog->tiles(
				array(
					'term'  => 'pattern-moroccan-arabic',
					'panel' => 'Moroccan & Arabic',
				)
			);

		$this->assertStringNotContainsString( 'Kasbah', $html );
	}

	/**
	 * The tile's image is the product's own, and its dimensions are the
	 * attachment's — not the literal 400×400 the old tiles carried.
	 */
	public function test_tiles_take_the_image_and_its_dimensions_from_the_product(): void {
		$this->mock_site();

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		$this->assertStringContainsString( 'src="https://site.test/uploads/232-large.webp"', $html, 'the product\'s own featured image' );
		$this->assertStringContainsString( 'width="500" height="1000"', $html, 'the real attachment size, not a literal' );
	}

	/**
	 * A card group leads with the featured products — the home page's own
	 * WooCommerce field — and fills any remaining slot from the series.
	 */
	public function test_card_groups_lead_with_the_featured_products(): void {
		$this->mock_site();

		$html = ( new Catalog() )->cards(
			array(
				'term'    => 'pattern-moroccan-arabic',
				'eyebrow' => 'Mashrabiya pattern',
				'limit'   => '1',
			)
		);

		$this->assertStringContainsString( 'mandala-architectural-metal-screen-pattern', $html, 'the featured pattern takes the slot' );
		$this->assertStringNotContainsString( 'alhambra-architectural-metal-screen-pattern', $html, 'the unfeatured one waits' );
	}

	/**
	 * The cards' facts are the product's: the best-fit word, the series badge
	 * and the open area all come from the catalog, and the eyebrow is the
	 * template's family word.
	 */
	public function test_card_groups_read_their_facts_from_the_products(): void {
		$this->mock_site();

		$html = ( new Catalog() )->cards(
			array(
				'term'    => 'pattern-moroccan-arabic',
				'eyebrow' => 'Mashrabiya pattern',
				'limit'   => '3',
			)
		);

		$this->assertStringContainsString( 'href="https://site.test/pattern/mandala-architectural-metal-screen-pattern/"', $html );
		$this->assertStringContainsString( 'Architectural screening', $html, 'the best-fit word comes from the product meta' );
		$this->assertStringContainsString( 'moroccan-arabic', $html, 'the series badge is the product term slug' );
		$this->assertStringContainsString( 'Open: 58%', $html, 'the open area is the product meta' );
		$this->assertStringContainsString( 'Mashrabiya pattern', $html, 'the eyebrow is the template\'s family word' );
	}

	/**
	 * A card image carries the `srcset`/`sizes` pair, so a phone downloads
	 * the small variant instead of the desktop one.
	 */
	public function test_a_card_image_carries_a_srcset_and_sizes(): void {
		$this->mock_site();

		$html = ( new Catalog() )->cards(
			array(
				'term'    => 'pattern-organic',
				'eyebrow' => 'Floral pattern',
				'limit'   => '1',
			)
		);

		$this->assertStringContainsString( '480w', $html, 'the width descriptors are present' );
		$this->assertMatchesRegularExpression(
			'/srcset="[^"]*300-480w\.webp 480w/',
			$html,
			'the sibling width the pipeline wrote is one of the alternatives'
		);
		$this->assertStringContainsString( 'sizes="(min-width: 768px) 33vw, 100vw"', $html );
	}

	/**
	 * A pattern with no sourced open area says so instead of inventing a
	 * number, and the badge names the effect.
	 */
	public function test_a_card_without_an_open_area_says_on_request(): void {
		$this->mock_site();

		$html = ( new Catalog() )->cards(
			array(
				'term'    => 'pattern-organic',
				'eyebrow' => 'Floral pattern',
				'limit'   => '3',
			)
		);

		$this->assertStringContainsString( 'Effect: Decorative screening', $html );
		$this->assertStringNotContainsString( 'Open: 0', $html, 'a missing value is never rendered as a zero' );
	}

	/**
	 * The counts answer the catalog's own numbers: the total is the
	 * catalog-visible product count, and a panel's count is the same curated
	 * query its grid runs — so the badge cannot disagree with the tiles.
	 */
	public function test_the_counts_come_from_the_catalog(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$this->assertSame( '6', $catalog->total(), 'every catalog-visible product, across all series' );
		$this->assertSame( '2', $catalog->group_count( array( 'term' => 'pattern-geometric' ) ), 'the panel counts its curated tiles' );
	}

	/**
	 * The total applies catalog visibility: the hidden product is in the
	 * series but not in the count.
	 */
	public function test_the_total_excludes_catalog_hidden_products(): void {
		$this->mock_site();

		// Seven fixture products, one hidden from the catalog: a count that
		// used wp_count_posts() would answer 7.
		$this->assertSame( '6', ( new Catalog() )->total(), 'the hidden product is not counted' );
	}

	/**
	 * A panel with no curated product renders nothing rather than falling
	 * back to the whole series.
	 */
	public function test_an_uncurated_series_renders_nothing(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$this->assertSame(
			'',
			$catalog->tiles(
				array(
					'term'  => 'pattern-vintage-retro',
					'panel' => 'Vintage & Retro',
				)
			)
		);
		$this->assertSame( '0', $catalog->group_count( array( 'term' => 'pattern-vintage-retro' ) ) );
	}

	/**
	 * A product without a featured image cannot be drawn, and is skipped
	 * instead of emitting an empty <img src="">.
	 */
	public function test_a_product_without_an_image_is_skipped(): void {
		$this->mock_site( true );

		$html = ( new Catalog() )->tiles(
			array(
				'term'  => 'pattern-geometric',
				'panel' => 'Geometric',
			)
		);

		// The curated product whose thumbnail is missing drops out; the one
		// with an image still renders, so the panel is not blanked.
		$this->assertSame( 1, substr_count( $html, 'class="pattern-item"' ) );
		$this->assertStringContainsString( 'data-pattern-name="Zebra"', $html );
		$this->assertStringNotContainsString( 'data-pattern-name="Alpha"', $html );
	}

	/**
	 * A term the site does not carry yields no tiles: an unknown slug is a
	 * template mistake, not an invitation to render every product.
	 */
	public function test_an_unknown_term_renders_nothing(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$this->assertSame( '', $catalog->tiles( array( 'term' => 'no-such-series' ) ) );
		$this->assertSame( '', $catalog->tiles( array() ) );
	}

	/**
	 * The two archive tools carry each other's state through a submission, so
	 * a search no longer discards the sort and a sort no longer discards the
	 * search.
	 */
	public function test_each_tool_carries_the_other_state(): void {
		$this->mock_site();
		$_GET['sort_by']        = 'title-descending';
		$_GET['pattern_search'] = 'lattice';

		$catalog = new Catalog();
		$this->assertSame(
			'<input type="hidden" name="sort_by" value="title-descending">',
			$catalog->preserve_param( array( 'name' => 'sort_by' ) ),
			'the search form carries the order'
		);
		$this->assertSame(
			'<input type="hidden" name="pattern_search" value="lattice">',
			$catalog->preserve_param( array( 'name' => 'pattern_search' ) ),
			'the sort form carries the search'
		);
	}

	/**
	 * A form never carries its own control, and nothing is carried when the
	 * request is not using that state.
	 */
	public function test_a_form_never_carries_its_own_control(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$this->assertSame(
			'',
			$catalog->preserve_param(
				array(
					'name'   => 'sort_by',
					'except' => 'sort_by',
				)
			)
		);
		$this->assertSame( '', $catalog->preserve_param( array( 'name' => 'sort_by' ) ), 'the default is not pinned into the form' );
	}

	/**
	 * The sort menu marks the order in force, and an unrecognised value falls
	 * back to the default rather than claiming an order the archive did not
	 * apply.
	 */
	public function test_the_sort_menu_marks_the_order_in_force(): void {
		$this->mock_site();

		$catalog = new Catalog();
		$this->assertSame( ' selected', $catalog->sort_selected( array( 'value' => 'title-ascending' ) ), 'the default' );
		$this->assertSame( '', $catalog->sort_selected( array( 'value' => 'title-descending' ) ) );

		$_GET['sort_by'] = 'title-descending';
		$this->assertSame( ' selected', $catalog->sort_selected( array( 'value' => 'title-descending' ) ) );
		$this->assertSame( '', $catalog->sort_selected( array( 'value' => 'title-ascending' ) ) );

		$_GET['sort_by'] = 'not-a-sort';
		$this->assertSame( ' selected', $catalog->sort_selected( array( 'value' => 'title-ascending' ) ), 'an unknown value is the default' );
		$this->assertSame( '', $catalog->sort_selected( array( 'value' => 'title-descending' ) ) );
	}

	/**
	 * The search box keeps the term the visitor submitted.
	 */
	public function test_the_search_box_keeps_its_term(): void {
		$this->mock_site();
		$_GET['pattern_search'] = 'lace';

		$this->assertSame( 'lace', ( new Catalog() )->search_value() );
	}

	/**
	 * The two tools submit to the series archive's own base address, not to
	 * the address the visitor is standing on.
	 *
	 * Both forms carried `action=""`, which a browser resolves to the current
	 * address — `/collections/pattern-abstract/page/2/` included. A search
	 * sent from that page asked for `/collections/pattern-abstract/page/2/
	 * ?pattern_search=…`, and WordPress resolves the page number against the
	 * narrowed result set: a search that matched one pattern has no page 2,
	 * so the visitor who searched from page 2 got a 404. The base is the
	 * series the request resolved to, so no suffix and no page number can
	 * survive into the submission.
	 */
	public function test_a_series_archive_submits_to_its_own_base_address(): void {
		$this->mock_archive_base();
		WP_Mock::userFunction( 'get_queried_object' )->andReturnUsing(
			static function (): \WP_Term {
				$term           = new \WP_Term( (object) array() );
				$term->slug     = 'pattern-abstract';
				$term->taxonomy = 'product_cat';
				return $term;
			}
		);

		$this->assertSame(
			'https://site.test/collections/pattern-abstract/',
			( new Catalog() )->archive_form_action()
		);
	}

	/**
	 * The shop submits to the product archive's base, which is where
	 * WooCommerce serves its shop page — the same rule on both faces.
	 */
	public function test_the_shop_submits_to_the_product_archive_base(): void {
		$this->mock_archive_base();
		WP_Mock::userFunction( 'get_queried_object' )->andReturn( new \WP_Post( (object) array() ) );

		$this->assertSame( 'https://site.test/shop/', ( new Catalog() )->archive_form_action() );
	}

	/**
	 * A face WordPress cannot resolve a base for submits nowhere rather than
	 * to the current address, which is the bug this replaced.
	 */
	public function test_a_face_without_a_base_has_no_action(): void {
		WP_Mock::userFunction( 'get_queried_object' )->andReturn( null );
		WP_Mock::userFunction( 'get_post_type_archive_link' )->andReturn( false );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn( string $value ): string => $value );

		$this->assertSame( '', ( new Catalog() )->archive_form_action() );
	}

	/**
	 * The two WordPress reads the form action needs: the base address of the
	 * archive under test, and escaping.
	 */
	private function mock_archive_base(): void {
		WP_Mock::userFunction( 'get_post_type_archive_link' )->andReturn( 'https://site.test/shop/' );
		WP_Mock::userFunction( 'get_term_link' )->andReturnUsing(
			static fn( \WP_Term $term ): string => 'https://site.test/collections/' . $term->slug . '/'
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn( string $value ): string => $value );
	}

	/**
	 * The sibling width files the site's media pipeline produced, in a
	 * directory the srcset fallback can glob for real.
	 */
	private function fixture_dir(): string {
		$dir = sys_get_temp_dir() . '/md-fixture-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a local test fixture.
		}
		foreach ( array( 232, 218, 240, 241, 888, 200, 300 ) as $id ) {
			foreach ( array( 400, 480, 768 ) as $width ) {
				$path = $dir . "/{$id}-{$width}w.webp";
				if ( ! file_exists( $path ) ) {
					file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test fixture.
				}
			}
		}
		return $dir;
	}

	/**
	 * Registers the card partials' shortcodes on the shared renderer, the
	 * way the site wires them (`md-site.php`): the cards are rendered from
	 * partials, so a test that skipped registration would assert against raw
	 * `[md_pattern_code]` text instead of the page a visitor reads.
	 */
	public function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a test fixture, not a request handler.
		$this->get = $_GET;
		shortcode_tags_reset();
		\MetalDivider\Site\Presentation\PartialFields::forget();
		\MetalDivider\Site\Presentation\Partials::forget();
		\MetalDivider\Site\Presentation\PatternFacts::forget();
		// `add_shortcode()` is WordPress's and WP_Mock stands in for it: the
		// mock writes into the registry the real engine reads, so this is the
		// production path rather than a test-only shortcut.
		WP_Mock::userFunction( 'add_shortcode' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $tag, callable $callback ): void {
				$GLOBALS['shortcode_tags'][ $tag ] = $callback;
			}
		);
		\MetalDivider\Site\Presentation\PartialFields::shared()->register();
		\MetalDivider\Site\Presentation\Partials::shared()->register();
	}

	/**
	 * Drops the registry and the request's renderers, and puts the request back
	 * the way this case found it — on failure as well as on success, which a
	 * `unset()` at the end of a test method is not. The capture is taken after
	 * `parent::setUp()`, so this restores the request the base class established
	 * rather than whatever a previous case left behind.
	 */
	public function tearDown(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a test fixture, not a request handler.
		$_GET = $this->get;
		shortcode_tags_reset();
		\MetalDivider\Site\Presentation\Partials::forget();
		\MetalDivider\Site\Presentation\PatternFacts::forget();
		parent::tearDown();
	}

	/**
	 * The fixture: the WordPress and WooCommerce functions the renderers
	 * call, over a small catalog that makes each curation rule visible.
	 *
	 * @param bool $drop_alpha Whether Alpha's featured image is missing.
	 */
	private function mock_site( bool $drop_alpha = false ): void {
		$products = array(
			51  => array(
				'title'    => 'Zebra | Architectural Metal Screen Pattern',
				'slug'     => 'zebra-architectural-metal-screen-pattern',
				'order'    => 1,
				'thumb'    => 232,
				'series'   => 'pattern-geometric',
				'visible'  => true,
				'featured' => false,
				'meta'     => array(
					'best_fit'  => 'decorative-screening',
					'open_area' => '49.97',
				),
			),
			48  => array(
				'title'    => 'Alpha | Architectural Metal Screen Pattern',
				'slug'     => 'alpha-architectural-metal-screen-pattern',
				'order'    => 2,
				'thumb'    => 218,
				'series'   => 'pattern-geometric',
				'visible'  => true,
				'featured' => false,
				'meta'     => array(),
			),
			300 => array(
				'title'    => 'Uncurated | Architectural Metal Screen Pattern',
				'slug'     => 'uncurated-architectural-metal-screen-pattern',
				'order'    => 0,
				'thumb'    => 240,
				'series'   => 'pattern-geometric',
				'visible'  => true,
				'featured' => false,
				'meta'     => array(),
			),
			301 => array(
				'title'    => 'Hidden | Architectural Metal Screen Pattern',
				'slug'     => 'hidden-architectural-metal-screen-pattern',
				'order'    => 3,
				'thumb'    => 241,
				'series'   => 'pattern-geometric',
				'visible'  => false,
				'featured' => false,
				'meta'     => array(),
			),
			146 => array(
				'title'    => 'Mandala | Architectural Metal Screen Pattern',
				'slug'     => 'mandala-architectural-metal-screen-pattern',
				'order'    => 1,
				'thumb'    => 888,
				'series'   => 'pattern-moroccan-arabic',
				'visible'  => true,
				'featured' => true,
				'meta'     => array(
					'best_fit'  => 'architectural-screening',
					'open_area' => '58',
				),
			),
			47  => array(
				'title'    => 'Alhambra | Architectural Metal Screen Pattern',
				'slug'     => 'alhambra-architectural-metal-screen-pattern',
				'order'    => 2,
				'thumb'    => 200,
				'series'   => 'pattern-moroccan-arabic',
				'visible'  => true,
				'featured' => false,
				'meta'     => array(),
			),
			130 => array(
				'title'    => 'Winderoo Decorative Room Divider | Architectural Metal Screen Pattern',
				'slug'     => 'winderoo-architectural-metal-screen-pattern',
				'order'    => 0,
				'thumb'    => 300,
				'series'   => 'pattern-organic',
				'visible'  => true,
				'featured' => false,
				'meta'     => array(
					'best_fit'  => 'decorative-screening',
					'open_area' => '',
				),
			),
		);

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
		WP_Mock::userFunction( 'sanitize_title' )->andReturnUsing(
			static fn( string $value ): string => (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $value ) )
		);
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( string $value ): string => trim( $value )
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing(
			static fn( string $value ): string => $value
		);
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES )
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			static fn( string $value ): string => $value
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( string $value ): string => htmlspecialchars( $value, ENT_QUOTES )
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		// The cards are rendered from partials now, and a partial renders
		// inside the loop for one product: the renderer sets that post up the
		// way LiveCanvas's own loop does and asks the loop which post it is.
		WP_Mock::userFunction( 'setup_postdata' )->andReturn( true );
		WP_Mock::userFunction( 'wp_reset_postdata' )->andReturn( null );
		WP_Mock::userFunction( 'get_the_ID' )->andReturnUsing(
			static fn(): int => 51
		);

		// The term queries. Both the tiles/cards read and the total read go
		// through get_posts, so one double serves them and applies the same
		// two rules the real query does: publish + catalog visibility.
		WP_Mock::userFunction( 'get_posts' )->andReturnUsing(
			static function ( array $args ) use ( $products ): array {
				$slug   = '';
				$hidden = false;
				foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
					if ( 'product_cat' === ( $clause['taxonomy'] ?? '' ) ) {
						$slug = (string) ( $clause['terms'] ?? '' );
					}
					if ( 'product_visibility' === ( $clause['taxonomy'] ?? '' ) ) {
						$hidden = true;
					}
				}
				$out = array();
				foreach ( $products as $id => $row ) {
					if ( '' !== $slug && $row['series'] !== $slug ) {
						continue;
					}
					// A NOT IN clause hides the catalog-hidden products.
					if ( $hidden && ! $row['visible'] ) {
						continue;
					}
					$post             = new \WP_Post( (object) array( 'ID' => $id ) );
					$post->ID         = $id;
					$post->post_type  = 'product';
					$post->post_title = $row['title'];
					$post->menu_order = $row['order'];
					$out[ $id ]       = $post;
				}
				// WordPress applies the caller's orderby, so the double does
				// too: a test whose fixture sorted itself would pass even if
				// the production query asked for the wrong order.
				$orderby = $args['orderby'] ?? 'title';
				$order   = strtoupper( (string) ( $args['order'] ?? 'ASC' ) );
				$sign    = 'DESC' === $order ? -1 : 1;
				uasort(
					$out,
					static function ( \WP_Post $a, \WP_Post $b ) use ( $orderby, $sign ): int {
						if ( is_array( $orderby ) ) {
							foreach ( $orderby as $key => $direction ) {
								if ( 'menu_order' === $key && $a->menu_order !== $b->menu_order ) {
									return ( $a->menu_order <=> $b->menu_order ) * ( 'DESC' === strtoupper( (string) $direction ) ? -1 : 1 );
								}
							}
							return strcmp( $a->post_title, $b->post_title );
						}
						if ( 'menu_order' === $orderby && $a->menu_order !== $b->menu_order ) {
							return ( $a->menu_order <=> $b->menu_order ) * $sign;
						}
						return strcmp( $a->post_title, $b->post_title ) * ( 'menu_order' === $orderby ? 1 : $sign );
					}
				);
				// WordPress returns bare ids when the caller asks for them,
				// and post objects otherwise; a double that always answered
				// objects would hide a caller asking for the wrong shape.
				if ( 'ids' === ( $args['fields'] ?? '' ) ) {
					return array_map( static fn( \WP_Post $p ): int => (int) $p->ID, array_values( $out ) );
				}
				return array_values( $out );
			}
		);
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			static function ( int $id ) use ( $products ) {
				if ( ! isset( $products[ $id ] ) ) {
					return null;
				}
				$post             = new \WP_Post( (object) array( 'ID' => $id ) );
				$post->ID         = $id;
				$post->post_type  = 'product';
				$post->post_title = $products[ $id ]['title'];
				$post->menu_order = $products[ $id ]['order'];
				return $post;
			}
		);
		WP_Mock::userFunction( 'get_the_title' )->andReturnUsing(
			static function ( $id ) use ( $products ): string {
				$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
				return $products[ $id ]['title'] ?? '';
			}
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static function ( $id ) use ( $products ): string {
				$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
				return 'https://site.test/pattern/' . ( $products[ $id ]['slug'] ?? 'unknown' ) . '/';
			}
		);
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturnUsing(
			static fn( int $id ): int => $drop_alpha && 48 === $id ? 0 : ( $products[ $id ]['thumb'] ?? 0 )
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ) use ( $products ) {
				if ( '_product_image_gallery' === $key ) {
					return '';
				}
				return $products[ $id ]['meta'][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_image_src' )->andReturnUsing(
			static function ( int $id ): array {
				return array( "https://site.test/uploads/{$id}-large.webp", 500, 1000, false );
			}
		);
		// WordPress's own srcset answers nothing here — the site's media
		// pipeline stores each width as a separate file — so the fallback
		// runs. The fixture writes those sibling files for real, because the
		// fallback lists only the widths that exist on disk: a test that
		// faked the listing would not prove the file check works.
		$dir = $this->fixture_dir();
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->andReturn( false );
		WP_Mock::userFunction( 'get_attached_file' )->andReturnUsing(
			static fn( int $id ): string => $dir . "/{$id}-400w.webp"
		);
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn(
			array(
				'baseurl' => 'https://site.test/uploads',
				'basedir' => $dir,
			)
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}-480w.webp"
		);
		WP_Mock::userFunction( 'get_the_terms' )->andReturnUsing(
			static function ( int $id, string $taxonomy ) use ( $products ): array {
				$row  = $products[ $id ] ?? null;
				$term = new \WP_Term( (object) array() );
				if ( 'product_visibility' === $taxonomy ) {
					$term->slug = $row && $row['featured'] ? 'featured' : 'none';
					return array( $term );
				}
				$term->slug     = $row ? $row['series'] : '';
				$term->taxonomy = 'product_cat';
				return array( $term );
			}
		);
		// wc_get_product_visibility_term_ids() is a real function in the test
		// runtime (tests/php/Doubles/seopress-woocommerce.php), not a mock:
		// the production code guards on function_exists(), so the declaration
		// is what lets the visibility path run here at all.
	}
}
