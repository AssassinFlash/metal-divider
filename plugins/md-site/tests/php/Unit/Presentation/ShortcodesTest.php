<?php
/**
 * Tests for the PDP data shortcodes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Shortcodes;
use MetalDivider\Site\Tests\GalleryReadCounter;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Shortcodes: the template's data assemblies (spec 19 §6.2)
 * — each returns text or semantic markup with class names, zero inline
 * styles, zero design decisions.
 *
 * @covers \MetalDivider\Site\Presentation\Shortcodes
 */
final class ShortcodesTest extends TestCase {

	/**
	 * Sets up translation, escaping, WordPress's error predicate, and a clean
	 * slot memo — the last is per request in production, but this suite runs
	 * many "requests" in one PHP process and every fixture reuses post 7.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plain_text();
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		Shortcodes::flush();
		\MetalDivider\Site\Catalog::reset_visible_counts();
		// The related and series cards are rendered from partials now, so the
		// renderer and its value shortcodes have to be live for those cases —
		// the same wiring `md-site.php` does, and without it a case would
		// assert against raw `[md_media_url]` text instead of a card.
		shortcode_tags_reset();
		\MetalDivider\Site\Presentation\PartialFields::forget();
		\MetalDivider\Site\Presentation\Partials::forget();
		\MetalDivider\Site\Presentation\PatternFacts::forget();
		// `add_shortcode()` is WordPress's and WP_Mock stands in for it; the
		// default writes into the real registry so any case may call
		// `register()`. The one case that asserts the registration *count*
		// re-declares this mock with `times()`, and Mockery replaces the
		// earlier expectation's callback for the same function.
		WP_Mock::userFunction( 'add_shortcode' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $tag, callable $callback ): void {
				$GLOBALS['shortcode_tags'][ $tag ] = $callback;
			}
		);
		\MetalDivider\Site\Presentation\PartialFields::shared()->register();
		\MetalDivider\Site\Presentation\Partials::shared()->register();
	}

	/**
	 * Removes the gallery double and the multi-product fixture's globals.
	 */
	public function tearDown(): void {
		unset(
			$GLOBALS['md_wc_product_double'],
			$GLOBALS['md_wc_products'],
			$GLOBALS['md_current_post'],
			$GLOBALS['md_hub_visible']
		);
		Shortcodes::flush();
		\MetalDivider\Site\Catalog::reset_visible_counts();
		parent::tearDown();
	}


	/**
	 * The register() method adds every shortcode the templates reference, and
	 * nothing else.
	 *
	 * Asserted against WordPress's own registry rather than against a call
	 * count on `add_shortcode()`: the registry is what the templates resolve
	 * their tags from, so it is the thing whose shape matters — a registration
	 * that reached the mock but not the registry would render raw shortcode
	 * text to a visitor while a count-based test passed.
	 */
	public function test_register_adds_every_shortcode(): void {
		$GLOBALS['shortcode_tags'] = array();

		( new Shortcodes() )->register();

		$this->assertSame(
			array(
				Shortcodes::MATERIAL,
				Shortcodes::DESIGN_INTENT,
				Shortcodes::OPEN_AREA,
				Shortcodes::BEST_FIT,
				Shortcodes::IMAGE,
				Shortcodes::RELATED,
				Shortcodes::SERIES_GRID,
			),
			array_keys( $GLOBALS['shortcode_tags'] ),
			'every shortcode registers, and nothing else'
		);
	}

	/**
	 * The material shortcode assembles the site-wide constant as plain
	 * text — the template wraps it in the cell's own markup.
	 */
	public function test_material_assembles_the_site_wide_constant(): void {
		$this->assertSame( 'Stainless Steel (304 / 316)', ( new Shortcodes() )->material() );
	}

	/**
	 * The open-area cell renders the number as a percentage.
	 */
	public function test_open_area_renders_the_number(): void {
		$this->mock_product_meta( 'open_area', '33' );
		$this->assertSame( '33%', ( new Shortcodes() )->open_area() );
	}

	/**
	 * The sixteen patterns without a sourced value render "On request"
	 * (spec 08 O1: never an invented number).
	 */
	public function test_open_area_without_a_source_renders_on_request(): void {
		$this->mock_product_meta( 'open_area', '' );
		$this->assertSame( 'On request', ( new Shortcodes() )->open_area() );
	}

	/**
	 * The best-fit cell renders the ACF key's display word.
	 */
	public function test_best_fit_renders_the_display_word(): void {
		$this->mock_product_meta( 'best_fit', 'architectural-screening' );
		$this->assertSame( 'Architectural screening', ( new Shortcodes() )->best_fit() );
	}

	/**
	 * The design-intent hero slot is the site-wide sentence with the
	 * pattern's own best-fit word — the one PDP line that is not
	 * per-product data.
	 */
	public function test_design_intent_hero_slot_fills_the_best_fit(): void {
		$this->mock_product_meta( 'best_fit', 'decorative-screening' );

		$this->assertSame(
			'Built for light behavior, privacy, and surface impact in Decorative screening.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'hero' ) )
		);
	}

	/**
	 * A pattern without a best-fit field still gets the sentence, not a
	 * dangling "in .".
	 */
	public function test_design_intent_hero_slot_without_a_best_fit_reads_whole(): void {
		$this->mock_product_meta( 'best_fit', '' );

		$this->assertSame(
			'Built for light behavior, privacy, and surface impact.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'hero' ) )
		);
	}

	/**
	 * The design-intent lead slot prefers the pattern's own carried-over
	 * summary (spec 08 D2: the forty-three summaries are an asset).
	 */
	public function test_design_intent_lead_prefers_the_summary(): void {
		$this->mock_pattern(
			array(
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => 'The short description.',
				'term'    => 'A series description nobody should see here.',
			)
		);

		$this->assertSame(
			'A carried-over summary sentence.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) )
		);
	}

	/**
	 * The lead is the series' style blurb — the first paragraph the old
	 * PDP rendered — ahead of any pattern-specific field.
	 *
	 * This is the parity repair: the blurb is series-level editorial copy
	 * (one sentence per family, measured identical for all 146 members),
	 * so the term carries it and the product does not. Leading with
	 * `summary` instead made 103 of 146 patterns read differently from the
	 * old page.
	 */
	public function test_design_intent_lead_is_the_series_style_blurb(): void {
		$this->mock_pattern(
			array(
				'blurb'   => 'Abstract geometry gives this screen its project read.',
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => 'The short description.',
				'term'    => 'A series description nobody should see here.',
			)
		);

		$this->assertSame(
			'Abstract geometry gives this screen its project read.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) ),
			'the series blurb leads, exactly as the old PDP rendered it'
		);
	}

	/**
	 * The two paragraphs the old page showed: the series blurb, then the
	 * pattern's own notable effect. This is the whole block's contract.
	 */
	public function test_design_intent_renders_the_old_two_paragraph_block(): void {
		$this->mock_pattern(
			array(
				'blurb'   => 'Abstract geometry gives this screen its project read.',
				'notable' => 'Filtered light and pattern shadow',
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertSame(
			array(
				'Abstract geometry gives this screen its project read.',
				'Filtered light and pattern shadow',
			),
			array(
				$shortcodes->design_intent( array( 'slot' => 'lead' ) ),
				$shortcodes->design_intent( array( 'slot' => 'supplemental' ) ),
			)
		);
	}

	/**
	 * A series with no blurb falls back to the pattern's own copy, so a
	 * partially seeded site still says something true rather than nothing.
	 */
	public function test_design_intent_lead_falls_back_to_the_summary_without_a_blurb(): void {
		$this->mock_pattern(
			array(
				'blurb'   => '',
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => 'The short description.',
				'term'    => 'A series description nobody should see here.',
			)
		);

		$this->assertSame(
			'A carried-over summary sentence.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) )
		);
	}

	/**
	 * Without a summary the lead takes the product's own short
	 * description — the same value in WooCommerce's native carrier.
	 */
	public function test_design_intent_lead_falls_back_to_the_product_excerpt(): void {
		$this->mock_pattern(
			array(
				'summary' => '',
				'excerpt' => 'The short description.',
				'term'    => 'A series description nobody should see here.',
			)
		);

		$this->assertSame(
			'The short description.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) )
		);
	}

	/**
	 * A pattern with no copy of its own takes its series' term
	 * description, and the supplemental slot then stays empty rather than
	 * printing the same paragraph twice.
	 */
	public function test_design_intent_lead_falls_back_to_the_series_and_the_supplemental_stays_out(): void {
		$this->mock_pattern(
			array(
				'summary' => '',
				'excerpt' => '',
				'term'    => 'Abstract decorative screen designs — flowing curves.',
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertSame(
			'Abstract decorative screen designs — flowing curves.',
			$shortcodes->design_intent( array( 'slot' => 'lead' ) )
		);
		$this->assertSame( '', $shortcodes->design_intent( array( 'slot' => 'supplemental' ) ) );
	}

	/**
	 * A pattern with its own copy adds the series' line as the
	 * supplemental paragraph, so the block reads "this pattern" then
	 * "this family".
	 */
	public function test_design_intent_supplemental_is_the_series_line(): void {
		$this->mock_pattern(
			array(
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => '',
				'term'    => 'Abstract decorative screen designs — flowing curves.',
			)
		);

		$this->assertSame(
			'Abstract decorative screen designs — flowing curves.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'supplemental' ) )
		);
	}

	/**
	 * The supplemental paragraph is the pattern's own notable effect — the
	 * old PDP's design-intent second line — ahead of the series line, so
	 * the block reads as one pattern's copy rather than its family's.
	 * The migration carries this field 146/146; dropping it cost 103 of
	 * 146 patterns their second paragraph.
	 */
	public function test_design_intent_supplemental_is_the_patterns_own_notable_effect(): void {
		$this->mock_pattern(
			array(
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => '',
				'notable' => 'Filtered light and pattern shadow',
				'term'    => 'Abstract decorative screen designs — flowing curves.',
			)
		);

		$this->assertSame(
			'Filtered light and pattern shadow',
			( new Shortcodes() )->design_intent( array( 'slot' => 'supplemental' ) ),
			'the pattern’s own effect wins over the series line'
		);
	}

	/**
	 * A pattern with its own copy but no effect of its own falls back to
	 * the series line, so the paragraph is never invented and never
	 * silently dropped while the family has something to say.
	 */
	public function test_design_intent_supplemental_falls_back_to_the_series_line(): void {
		$this->mock_pattern(
			array(
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => '',
				'notable' => '',
				'term'    => 'Abstract decorative screen designs — flowing curves.',
			)
		);

		$this->assertSame(
			'Abstract decorative screen designs — flowing curves.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'supplemental' ) )
		);
	}

	/**
	 * A pattern with no prose anywhere in Woo renders nothing: the
	 * honest answer, and never a sentence invented to fill the slot.
	 */
	public function test_design_intent_is_empty_when_woo_holds_no_prose(): void {
		$this->mock_pattern(
			array(
				'summary' => '',
				'excerpt' => '',
				'term'    => '',
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertSame( '', $shortcodes->design_intent( array( 'slot' => 'lead' ) ) );
		$this->assertSame( '', $shortcodes->design_intent( array( 'slot' => 'supplemental' ) ) );
	}

	/**
	 * No assembly reads a repository file at render time (owner ruling
	 * 2026-10-26: published WooCommerce data is the only daily
	 * maintenance source). The defect this guards: the design-intent
	 * prose was read from docs/copy/pdp-series-phrases.json, a file the
	 * deploy never ships, so production rendered three empty paragraphs.
	 */
	public function test_no_assembly_reads_a_repository_file_at_render_time(): void {
		$source = (string) file_get_contents( (string) ( new \ReflectionClass( Shortcodes::class ) )->getFileName() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringNotContainsString( 'file_get_contents', $source, 'the assemblies hold no file read' );
		$this->assertStringNotContainsString( 'docs/copy', $source, 'no copy file in the checkout is a prose source' );
		$this->assertStringNotContainsString( 'dirname( __DIR__', $source, 'no path is resolved out of the plugin at render time' );
	}

	/**
	 * The template's `[lc_if]` guard asks this predicate before it draws a
	 * paragraph, so an empty slot leaves no `.pdp-body` (and its margin)
	 * in the DOM. The predicate must answer exactly where design_intent()
	 * renders — the two are one resolution, not two.
	 */
	public function test_has_design_intent_agrees_with_the_rendered_slots(): void {
		$this->mock_pattern(
			array(
				'summary' => 'A carried-over summary sentence.',
				'excerpt' => '',
				'term'    => '',
			)
		);
		$shortcodes = new Shortcodes();

		foreach ( array( 'hero', 'lead', 'supplemental' ) as $slot ) {
			$this->assertSame(
				'' !== $shortcodes->design_intent( array( 'slot' => $slot ) ),
				$shortcodes->has_design_intent( $slot ),
				"{$slot}: the guard and the paragraph agree"
			);
		}
		$this->assertTrue( $shortcodes->has_design_intent( 'lead' ) );
		$this->assertFalse( $shortcodes->has_design_intent( 'supplemental' ), 'nothing left to add: the paragraph stays out of the DOM' );
	}

	/**
	 * The series word comes from the product_cat term slug's tail, for
	 * the eyebrow and the spec bar's Style cell.
	 */
	public function test_style_comes_from_the_term_slug_tail(): void {
		$this->mock_pattern(
			array(
				'summary' => '',
				'excerpt' => '',
				'term'    => '',
				'slug'    => 'pattern-vintage-retro',
			)
		);

		$this->assertSame( 'vintage-retro', Shortcodes::style() );
	}

	/**
	 * A product with no series term has no style word: the assembly
	 * answers nothing rather than a bare slug.
	 */
	public function test_style_is_empty_without_a_series(): void {
		$this->mock_pattern(
			array(
				'summary' => '',
				'excerpt' => '',
				'term'    => '',
				'slug'    => '',
			)
		);

		$this->assertSame( '', Shortcodes::style() );
	}

	/**
	 * The hub's series cards read WooCommerce's own term fields: the
	 * `thumbnail_id` the category screen writes, and the term
	 * description. The defect this guards: the cards read a bespoke
	 * `hero_image` URL meta nothing but a repository command wrote, so
	 * every card rendered an empty media box on production.
	 */
	public function test_series_grid_renders_the_native_term_thumbnail_and_description(): void {
		$term              = new \WP_Term( (object) array() );
		$term->term_id     = 18;
		$term->slug        = 'pattern-abstract';
		$term->name        = 'Abstract';
		$term->description = 'Abstract decorative screen designs.';

		WP_Mock::userFunction( 'get_terms' )->once()->andReturnUsing(
			static function ( array $args ) use ( $term ): array {
				// The visibility filter is the caller's: series_grid asks for
				// every term and then keeps the ones with a catalog-visible
				// pattern (Catalog::series_visible_counts()), because
				// `hide_empty` decides in SQL against a count column that
				// disagrees with WooCommerce's cached product_count meta.
				\PHPUnit\Framework\Assert::assertFalse(
					(bool) $args['hide_empty'],
					'the hub does not delegate visibility to hide_empty'
				);
				return array( $term );
			}
		);
		WP_Mock::userFunction( 'get_term_link' )->once()->andReturn( 'https://site.test/collections/pattern-abstract/' );
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 18, 'thumbnail_id', true )->andReturn( '1502' );
		// The hub's membership filter: Catalog::series_visible_counts() reads
		// the catalog-visible products once for the whole page, then their
		// product_cat terms.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( array $args = array() ): array => 'ids' === ( $args['fields'] ?? '' ) ? array( 7, 9 ) : array()
		);
		WP_Mock::userFunction( 'wp_get_object_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->once()->with( 1502, 'large' )->andReturn( 'https://site.test/uploads/aurum-1024w.webp' );
		// The card draws a ladder now, so the attachment's own srcset is part
		// of its contract (the hub image measured 2048px in a 348px box).
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->once()->with( 1502, 'large' )->andReturn( 'aurum-480w.webp 480w, aurum-1024w.webp 1024w' );
		WP_Mock::userFunction( 'get_post_meta' )->once()->with( 1502, '_wp_attachment_image_alt', true )->andReturn( 'Aurum circle screen' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->once()->with( 1502 )->andReturn(
			array(
				'width'  => 2048,
				'height' => 2048,
				'file'   => '2026/10/aurum-2048w.webp',
				'sizes'  => array(
					'large' => array(
						'file'   => 'aurum-1024w.webp',
						'width'  => 1024,
						'height' => 1024,
					),
				),
			)
		);

		$cards = ( new Shortcodes() )->series_grid();

		$this->assertStringContainsString( 'src="aurum-480w.webp"', $cards, 'the source is the ladder\'s smallest rung: a 348–439px box, and a browser without srcset support should not fetch the master' );
		$this->assertStringContainsString( 'srcset="', $cards, 'the card carries a ladder — the master in a 348px box was the LCP on /collections/' );
		$this->assertStringContainsString( 'alt="Aurum circle screen"', $cards, 'the attachment’s own alt wins' );
		$this->assertStringContainsString( 'width="480" height="480"', $cards, 'the numbers are the drawn file’s own, not the master’s — otherwise the box reserves the wrong ratio' );
		$this->assertStringContainsString( '<p class="cph__card-copy">Abstract decorative screen designs.</p>', $cards );
		$this->assertStringContainsString( '<h3 class="cph__card-title">Abstract</h3>', $cards );
		$this->assertStringNotContainsString( ' style=', $cards, 'zero inline styles (AGENTS.md §5)' );
	}

	/**
	 * A term with no `thumbnail_id` renders no image. The media box carries
	 * the series-name fallback instead of an empty frame, and no placeholder
	 * image is invented.
	 */
	public function test_series_grid_without_a_thumbnail_renders_no_image(): void {
		$term              = new \WP_Term( (object) array() );
		$term->term_id     = 19;
		$term->slug        = 'pattern-asian';
		$term->name        = 'Asian Inspired';
		$term->description = '';

		WP_Mock::userFunction( 'get_terms' )->once()->andReturn( array( $term ) );
		WP_Mock::userFunction( 'get_term_link' )->once()->andReturn( 'https://site.test/collections/pattern-asian/' );
		$this->mock_hub_membership( $term );
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 19, 'thumbnail_id', true )->andReturn( '' );

		$cards = ( new Shortcodes() )->series_grid();

		$this->assertStringContainsString( '<span class="cph__media-fallback">Asian Inspired</span>', $cards );
		$this->assertStringNotContainsString( '<img', $cards );
	}

	/**
	 * A term whose attachment has no alt text falls back to the term
	 * name, as the card's own copy would read it aloud.
	 */
	public function test_series_grid_falls_back_to_the_term_name_for_a_missing_alt(): void {
		$term          = new \WP_Term( (object) array() );
		$term->term_id = 20;
		$term->slug    = 'pattern-geometric';
		$term->name    = 'Geometric';

		WP_Mock::userFunction( 'get_terms' )->once()->andReturn( array( $term ) );
		WP_Mock::userFunction( 'get_term_link' )->once()->andReturn( 'https://site.test/collections/pattern-geometric/' );
		$this->mock_hub_membership( $term );
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 20, 'thumbnail_id', true )->andReturn( '1503' );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->once()->with( 1503, 'large' )->andReturn( 'https://site.test/uploads/grid-1024w.webp' );
		// The card draws a ladder now, so the attachment's own srcset is part
		// of its contract (the hub image measured 2048px in a 348px box).
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->once()->with( 1503, 'large' )->andReturn( 'grid-480w.webp 480w, grid-1024w.webp 1024w' );
		WP_Mock::userFunction( 'get_post_meta' )->once()->with( 1503, '_wp_attachment_image_alt', true )->andReturn( '' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->once()->with( 1503 )->andReturn( array() );

		$cards = ( new Shortcodes() )->series_grid();

		$this->assertStringContainsString( 'alt="Geometric"', $cards, 'the term name stands in for a missing alt' );
		$this->assertStringNotContainsString( 'width=', $cards, 'dimensions are omitted when the metadata has none' );
	}

	/**
	 * A term whose thumbnail attachment is gone renders no image at all —
	 * never a `src=""` — and the rest of the grid still renders.
	 */
	public function test_series_grid_skips_an_unresolvable_attachment(): void {
		$gone          = new \WP_Term( (object) array() );
		$gone->term_id = 22;
		$gone->slug    = 'pattern-landscape';
		$gone->name    = 'Landscape';

		WP_Mock::userFunction( 'get_terms' )->once()->andReturn( array( $gone ) );
		WP_Mock::userFunction( 'get_term_link' )->once()->andReturn( 'https://site.test/collections/pattern-landscape/' );
		$this->mock_hub_membership( $gone );
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 22, 'thumbnail_id', true )->andReturn( '1601' );
		// The card asks for `large` first and only falls back to `full` when
		// that size is gone, so an unresolvable attachment answers both.
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->once()->with( 1601, 'large' )->andReturn( false );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->once()->with( 1601, 'full' )->andReturn( false );

		$cards = ( new Shortcodes() )->series_grid();

		$this->assertStringContainsString( '<span class="cph__media-fallback">Landscape</span>', $cards );
		$this->assertStringNotContainsString( 'src=""', $cards );
		$this->assertStringContainsString( '<h3 class="cph__card-title">Landscape</h3>', $cards, 'the card still carries its title' );
	}


	/**
	 * The related cards render the WooCommerce related products in the
	 * replica's card markup.
	 */
	public function test_related_renders_the_cards(): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $this->product_post() );
		// No partial is deployed in a unit run, so the renderer takes the
		// repository file — the fallback `present` exists to make unnecessary.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( array $args = array() ): array => 'ids' === ( $args['fields'] ?? '' ) ? array() : array()
		);
		WP_Mock::userFunction( 'wc_get_related_products' )->once()->andReturn( array( 11 ) );
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://site.test/pattern/zelda-architectural-metal-screen-pattern/' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( 'https://site.test/wp-content/uploads/zelda.jpg' );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Zelda | Architectural Metal Screen Pattern' );

		$cards = ( new Shortcodes() )->related();

		$this->assertStringContainsString( 'class="pdp-related-card gs-reveal"', $cards );
		$this->assertStringContainsString( '<h3>Zelda</h3>', $cards );
		$this->assertStringNotContainsString( ' style=', $cards, 'zero inline styles (AGENTS.md §5)' );
	}

	/**
	 * The hero background is the first scene, with the attachment's real
	 * dimensions and the decorative empty alt the old theme used.
	 */
	public function test_image_hero_is_the_first_scene_with_its_real_dimensions(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role'   => 'finish preview',
					'alt'    => 'Akira finish preview',
					'width'  => 400,
					'height' => 800,
				),
				102 => array(
					'role'   => 'accent render',
					'alt'    => 'Akira accent render',
					'width'  => 3000,
					'height' => 1700,
				),
				103 => array(
					'role'   => 'installed screen scene',
					'alt'    => 'Akira installed screen scene',
					'width'  => 1353,
					'height' => 820,
				),
				104 => array(
					'role'   => 'official installation scene',
					'alt'    => 'Akira official installation scene 2',
					'width'  => 1000,
					'height' => 563,
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'hero-bg' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/103.webp"', $image );
		$this->assertStringContainsString( 'alt=""', $image, 'the hero is the decorative background' );
		$this->assertStringContainsString( 'width="1353" height="820"', $image );
		$this->assertStringContainsString( 'loading="eager"', $image );
	}

	/**
	 * The plate is the first detail, ahead of a finish.
	 */
	public function test_image_plate_prefers_the_first_detail(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role'   => 'installed screen scene',
					'alt'    => 'Akira installed screen scene',
					'width'  => 1353,
					'height' => 820,
				),
				102 => array(
					'role'   => 'finish preview',
					'alt'    => 'Akira finish preview',
					'width'  => 400,
					'height' => 800,
				),
				103 => array(
					'role'   => 'detail reference',
					'alt'    => 'Akira pattern detail reference',
					'width'  => 1000,
					'height' => 1998,
				),
				104 => array(
					'role'   => 'accent render',
					'alt'    => 'Akira accent render',
					'width'  => 1500,
					'height' => 850,
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'plate' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/103.webp"', $image );
		$this->assertStringContainsString( 'alt="Akira pattern detail reference"', $image );
		$this->assertStringContainsString( 'width="1000" height="1998"', $image );
		$this->assertStringContainsString( 'loading="lazy"', $image );
	}

	/**
	 * Without a detail, the plate falls back to the first finish.
	 */
	public function test_image_plate_falls_back_to_the_first_finish(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				103 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'plate' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/102.webp"', $image );
	}

	/**
	 * The square is the second scene, not the first (which is the hero).
	 */
	public function test_image_square_is_the_second_scene(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'official installation scene',
					'alt'  => 'Akira official installation scene 2',
				),
				103 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'square' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/102.webp"', $image );
		$this->assertStringContainsString( 'alt="Akira official installation scene 2"', $image );
	}

	/**
	 * Without a second scene, the square falls back to the first accent.
	 */
	public function test_image_square_falls_back_to_the_first_accent(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				103 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'square' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/103.webp"', $image );
	}

	/**
	 * The wide slot takes the first accent only when no other slot used it.
	 */
	public function test_image_wide_is_the_first_accent_only_when_unused(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'official installation scene',
					'alt'  => 'Akira official installation scene 2',
				),
				103 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				104 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertStringContainsString( 'src="https://site.test/uploads/104.webp"', $shortcodes->image( array( 'slot' => 'wide' ) ) );
	}

	/**
	 * When the first accent is already the square, wide is hidden.
	 */
	public function test_image_wide_is_hidden_when_the_first_accent_is_taken(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				103 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);

		$this->assertSame( '', ( new Shortcodes() )->image( array( 'slot' => 'wide' ) ) );
	}

	/**
	 * A two-image pattern (scene + render) fills the hero only: plate and
	 * square return nothing, so the template emits no empty src.
	 */
	public function test_image_two_image_pattern_hides_the_empty_slots(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Pebble Creek installed screen scene',
				),
				102 => array(
					'role' => 'pattern render',
					'alt'  => 'Pebble Creek pattern render',
				),
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertStringContainsString( 'src="https://site.test/uploads/101.webp"', $shortcodes->image( array( 'slot' => 'hero-bg' ) ) );
		$this->assertSame( '', $shortcodes->image( array( 'slot' => 'plate' ) ) );
		$this->assertSame( '', $shortcodes->image( array( 'slot' => 'square' ) ) );
		$this->assertSame( '', $shortcodes->image( array( 'slot' => 'wide' ) ) );
	}

	/**
	 * The template's frames ask this predicate, so it must answer exactly
	 * where image() draws — the two are one resolution, not two.
	 */
	public function test_has_image_agrees_with_the_drawn_slots(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Pebble Creek installed screen scene',
				),
				102 => array(
					'role' => 'detail reference',
					'alt'  => 'Pebble Creek pattern detail reference',
				),
			)
		);
		$shortcodes = new Shortcodes();

		foreach ( array( 'hero-bg', 'plate', 'square', 'wide' ) as $slot ) {
			$this->assertSame(
				'' !== $shortcodes->image( array( 'slot' => $slot ) ),
				$shortcodes->has_image( $slot ),
				"{$slot}: the frame predicate and the drawn element agree"
			);
		}
		$this->assertTrue( $shortcodes->has_image( 'plate' ) );
		$this->assertFalse( $shortcodes->has_image( 'square' ), 'no second scene, no accent: the square frame stays out of the DOM' );
	}

	/**
	 * An unknown slot and a non-product context draw nothing, so neither
	 * may claim a frame.
	 */
	public function test_has_image_is_false_without_a_slot_or_a_product(): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( null );

		$shortcodes = new Shortcodes();
		$this->assertFalse( $shortcodes->has_image( 'plate' ) );
		$this->assertFalse( $shortcodes->has_image( 'nonsense' ) );
	}

	/**
	 * The template's guard and its element resolve the slot once, not once
	 * each — on different objects, which is how LiveCanvas actually calls
	 * them.
	 *
	 * The PDP asks `[lc_if condition="md_pdp_slot_has_image" param="plate"]`
	 * and then renders `[md_pdp_image slot="plate"]`. The guard goes through
	 * the global function, which builds its own Shortcodes instance, so an
	 * instance property would memoize nothing. This counts the underlying
	 * reads: the gallery once per product, and each attachment's role/alt
	 * once however many slots ask.
	 */
	public function test_the_guard_and_the_element_resolve_a_slot_once(): void {
		Shortcodes::flush();
		$fixture = $this->mock_products(
			array(
				7 => array(
					101 => array(
						'role' => 'installed screen scene',
						'alt'  => 'Akira installed screen scene',
					),
					102 => array(
						'role' => 'detail reference',
						'alt'  => 'Akira pattern detail reference',
					),
				),
			)
		);

		// Every slot twice, each pair on a fresh instance: guard, then element.
		foreach ( array( 'hero-bg', 'plate', 'square', 'wide' ) as $slot ) {
			( new Shortcodes() )->has_image( $slot );
			( new Shortcodes() )->image( array( 'slot' => $slot ) );
		}

		$this->assertSame( 1, $fixture['counts']->gallery, 'the gallery is read once for the product, however many slots ask' );
		$this->assertSame(
			count( $fixture['media'] ),
			$fixture['counts']->class_reads,
			'each attachment is classified once, not once per slot that asks about it'
		);
	}

	/**
	 * Two products rendered in one request keep their own slots: the memo is
	 * keyed by product id, so a second product can never answer for the
	 * first.
	 */
	public function test_the_slot_memo_does_not_leak_between_products(): void {
		Shortcodes::flush();
		$fixture = $this->mock_products(
			array(
				7 => array(
					101 => array(
						'role' => 'installed screen scene',
						'alt'  => 'First installed screen scene',
					),
				),
				8 => array(
					201 => array(
						'role' => 'installed screen scene',
						'alt'  => 'Second installed screen scene',
					),
				),
			)
		);

		$shortcodes = new Shortcodes();

		foreach ( array( array( 7, '101' ), array( 8, '201' ), array( 7, '101' ) ) as $case ) {
			$GLOBALS['md_current_post'] = $fixture['posts'][ $case[0] ]; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the fixture's own global, named after the plugin prefix.
			$this->assertStringContainsString(
				"uploads/{$case[1]}.webp",
				$shortcodes->image( array( 'slot' => 'hero-bg' ) ),
				"product {$case[0]} resolves its own hero"
			);
			$this->assertTrue( $shortcodes->has_image( 'hero-bg' ) );
		}
		// The gallery is read once per product, and the first product's
		// answer is reused rather than recomputed on its second visit.
		$this->assertSame( 2, $fixture['counts']->gallery );
	}

	/**
	 * The eleven-word role vocabulary does not cover every old alt: the
	 * sixty "pattern installation scene visualization" images are unknown
	 * roles whose alt the old substring classifier reads as a scene.
	 */
	public function test_image_unknown_role_falls_back_to_the_old_alt_classification(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'unknown',
					'alt'  => 'Zelda pattern installation scene visualization',
				),
				102 => array(
					'role' => 'accent render',
					'alt'  => 'Zelda accent render',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'hero-bg' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/101.webp"', $image );
	}

	/**
	 * A pattern render whose alt carries "room divider" is a scene under
	 * the old classifier (the Winderoo case), so it can fill the hero.
	 */
	public function test_image_pattern_render_with_a_room_divider_alt_is_a_scene(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'pattern render',
					'alt'  => 'Winderoo Decorative Room Divider pattern render',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'hero-bg' ) );

		$this->assertStringContainsString( 'src="https://site.test/uploads/101.webp"', $image );
	}

	/**
	 * Without any scene the hero is hidden; the plate still takes the old
	 * pos0 and the square stays hidden (the no-hero fallback).
	 */
	public function test_image_without_a_scene_hides_the_hero(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				102 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);
		$shortcodes = new Shortcodes();

		$this->assertSame( '', $shortcodes->image( array( 'slot' => 'hero-bg' ) ) );
		$this->assertStringContainsString( 'src="https://site.test/uploads/101.webp"', $shortcodes->image( array( 'slot' => 'plate' ) ) );
		$this->assertSame( '', $shortcodes->image( array( 'slot' => 'square' ) ) );
	}

	/**
	 * Unknown dimensions are omitted rather than invented.
	 */
	public function test_image_omits_dimensions_when_the_attachment_has_none(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'hero-bg' ) );

		$this->assertStringNotContainsString( 'width=', $image );
		$this->assertStringNotContainsString( 'height=', $image );
	}

	/**
	 * A missing attachment alt falls back to the product title, as the old
	 * template did (hero stays decorative and keeps the empty alt).
	 */
	public function test_image_plate_falls_back_to_the_product_title_for_a_missing_alt(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'detail reference',
					'alt'  => '',
				),
			)
		);

		$image = ( new Shortcodes() )->image( array( 'slot' => 'plate' ) );

		$this->assertStringContainsString( 'alt="Akira | Architectural Metal Screen Pattern"', $image );
	}

	/**
	 * The three photographic slots never share one attachment.
	 */
	public function test_image_slots_never_share_an_attachment(): void {
		$this->mock_media_product(
			array(
				101 => array(
					'role' => 'installed screen scene',
					'alt'  => 'Akira installed screen scene',
				),
				102 => array(
					'role' => 'official installation scene',
					'alt'  => 'Akira official installation scene 2',
				),
				103 => array(
					'role' => 'finish preview',
					'alt'  => 'Akira finish preview',
				),
				104 => array(
					'role' => 'accent render',
					'alt'  => 'Akira accent render',
				),
			)
		);
		$shortcodes = new Shortcodes();
		$sources    = array();
		foreach ( array( 'hero-bg', 'plate', 'square', 'wide' ) as $slot ) {
			if ( preg_match( '/src="([^"]+)"/', $shortcodes->image( array( 'slot' => $slot ) ), $matches ) ) {
				$sources[] = $matches[1];
			}
		}

		$this->assertSame( $sources, array_values( array_unique( $sources ) ), 'no image fills two slots' );
	}

	/**
	 * One product media fixture, keyed by attachment id in media order:
	 * the first id is the featured image, the rest the gallery. Each entry
	 * carries the attachment's `_md_role`, alt and real dimensions.
	 *
	 * @param array<int, array<string, mixed>> $media The attachment fixtures.
	 */
	private function mock_media_product( array $media ): void {
		$ids       = array_keys( $media );
		$thumbnail = (int) $ids[0];
		$gallery   = array_map( 'intval', array_slice( $ids, 1 ) );

		// Every mock hands out a WP_Post (the WordPress contract), never a
		// bare stdClass: a bare object silently omits the members the
		// production code is entitled to read, and the next reader added to
		// the assembly turns that omission into a fatal in the suite.
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $this->product_post() );
		WP_Mock::userFunction( 'get_the_title' )->zeroOrMoreTimes()->andReturn( 'Akira | Architectural Metal Screen Pattern' );
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->zeroOrMoreTimes()->andReturn( $thumbnail );
		// The slot image is a partial now, and no lc_partial is installed in
		// the unit suite, so the renderer reads the repository file — the same
		// fallback a site before `wp md-site present` takes.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );
		$GLOBALS['md_wc_product_double'] = new \FakeGalleryProduct( $gallery ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the shared WooCommerce double's global (tests/php/Doubles/seopress-woocommerce.php).
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( $media ) {
				if ( '_md_role' === $key ) {
					return (string) ( $media[ $id ]['role'] ?? '' );
				}
				if ( '_wp_attachment_image_alt' === $key ) {
					return (string) ( $media[ $id ]['alt'] ?? '' );
				}
				return '';
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}.webp"
		);
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id ) use ( $media ) {
				$width  = (int) ( $media[ $id ]['width'] ?? 0 );
				$height = (int) ( $media[ $id ]['height'] ?? 0 );
				return ( $width > 0 && $height > 0 ) ? array(
					'width'  => $width,
					'height' => $height,
				) : array();
			}
		);
		// The slots declare a ladder now, so the fixture answers WordPress's
		// own read the way a real uploader does: each attachment has its own
		// widths. The alt-role tests are about which attachment a slot picks,
		// not about the ladder's contents.
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}-400w.webp 400w, https://site.test/uploads/{$id}-800w.webp 800w"
		);
	}

	/**
	 * The hub's membership read: series_grid keeps a term by asking
	 * Catalog::series_visible_counts(), which projects the catalog-visible
	 * products once for the page and then their product_cat terms.
	 *
	 * @param \WP_Term $term The term the page's visible products belong to.
	 */
	private function mock_hub_membership( \WP_Term $term ): void {
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( array $args = array() ): array => 'ids' === ( $args['fields'] ?? '' ) ? array( 7 ) : array()
		);
		WP_Mock::userFunction( 'wp_get_object_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );
	}

	/**
	 * One or more products with their own media, for the tests that render
	 * several products in one process.
	 *
	 * `get_post()` answers whichever product `$GLOBALS['md_current_post']`
	 * names, so a test switches products the way the template's loop does.
	 * The returned `counts` object is live: its `gallery` and `class_reads`
	 * keep counting as the test runs, which is what the memoization tests
	 * assert on.
	 *
	 * @param non-empty-array<int, array<int, array<string, mixed>>> $products Media by product id, then attachment id.
	 * @return array{counts: GalleryReadCounter, posts: array<int, \WP_Post>, media: array<int, mixed>}
	 */
	private function mock_products( array $products ): array {
		$posts  = array();
		$media  = array();
		$by_id  = array();
		$counts = new GalleryReadCounter();

		foreach ( $products as $post_id => $attachments ) {
			$ids               = array_keys( $attachments );
			$posts[ $post_id ] = $this->product_post( array(), $post_id );
			foreach ( $attachments as $id => $data ) {
				$media[ $id ] = $data;
			}
			// One double per product, holding that product's own gallery —
			// the real WooCommerce model: a product object answers for one
			// catalog row.
			$by_id[ $post_id ] = new \FakeGalleryProduct(
				array_map( 'intval', array_slice( $ids, 1 ) ),
				static function () use ( $counts ): void {
					++$counts->gallery;
				}
			);
		}
		$GLOBALS['md_current_post'] = reset( $posts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the fixture's own global, named after the plugin prefix.
		$GLOBALS['md_wc_products']  = $by_id; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the shared WooCommerce double's per-id map (tests/php/Doubles/seopress-woocommerce.php).

		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturnUsing(
			static fn() => $GLOBALS['md_current_post'] ?? null
		);
		// The slot image renders from a partial, and no lc_partial is
		// installed in the unit suite: the renderer reads the repository file.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );
		WP_Mock::userFunction( 'get_the_title' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int|\WP_Post $post = 0 ): string {
				$id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
				return "Pattern {$id} | Architectural Metal Screen Pattern";
			}
		);
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $post_id ) use ( $products ) {
				$ids = array_keys( $products[ $post_id ] ?? array() );
				return array() === $ids ? 0 : (int) $ids[0];
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( $media, $counts ) {
				if ( '_md_role' === $key ) {
					++$counts->class_reads;
					return (string) ( $media[ $id ]['role'] ?? '' );
				}
				if ( '_wp_attachment_image_alt' === $key ) {
					++$counts->alt_reads;
					return (string) ( $media[ $id ]['alt'] ?? '' );
				}
				return '';
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}.webp"
		);
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->zeroOrMoreTimes()->andReturn( array() );
		// A slot declares a ladder now, so the element asks for one; the counts
		// this fixture tracks are the slot's own reads, not the ladder's.
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}-400w.webp 400w"
		);

		return array(
			'counts' => $counts,
			'posts'  => $posts,
			'media'  => $media,
		);
	}

	/**
	 * One product meta read, mocked: the product post plus the one key.
	 *
	 * @param string $key   The field name.
	 * @param string $value The value the meta read returns.
	 */
	private function mock_product_meta( string $key, string $value ): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $this->product_post() );
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $asked ) use ( $key, $value ) {
				return $asked === $key ? $value : '';
			}
		);
	}

	/**
	 * One pattern fixture: its product screen fields (`summary`,
	 * `excerpt`, `notable`), its series term (`blurb` style blurb, `term`
	 * description, `slug`).
	 *
	 * @param array<string, string> $fields The fixture.
	 */
	private function mock_pattern( array $fields ): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn(
			$this->product_post( array( 'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ) ) )
		);
		WP_Mock::userFunction( 'get_the_title' )->zeroOrMoreTimes()->andReturn( 'Akira | Architectural Metal Screen Pattern' );
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( $fields ) {
				return match ( $key ) {
					'summary'        => (string) ( $fields['summary'] ?? '' ),
					'notable_effect' => (string) ( $fields['notable'] ?? '' ),
					default          => '',
				};
			}
		);

		$slug  = (string) ( $fields['slug'] ?? '' );
		$blurb = (string) ( $fields['blurb'] ?? '' );
		// A blurb is a reason to have a term even when the fixture names no
		// slug or description: it lives on the term, so the term must exist.
		if ( '' === $slug && '' === (string) ( $fields['term'] ?? '' ) && '' === $blurb ) {
			WP_Mock::userFunction( 'get_the_terms' )->zeroOrMoreTimes()->andReturn( false );
			return;
		}
		$term              = new \WP_Term( (object) array() );
		$term->term_id     = 18;
		$term->slug        = '' === $slug ? 'pattern-abstract' : $slug;
		$term->name        = 'Abstract';
		$term->description = (string) ( $fields['term'] ?? '' );
		WP_Mock::userFunction( 'get_the_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );
		// The series' own ACF fields: the style blurb is the PDP lead, and a
		// fixture that omits it must read as "the term carries none".
		WP_Mock::userFunction( 'get_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $key ) use ( $blurb ) {
				return 'style_blurb' === $key ? $blurb : '';
			}
		);
	}

	/**
	 * The current product, as WordPress hands it to a template: the
	 * shared WP_Post double (tests/php/Doubles/wordpress.php), which
	 * carries every member the real class does. Fixtures build on it
	 * rather than on a bare stdClass so a member the production code
	 * gains later is present by default instead of fatal.
	 *
	 * The row object is passed the way WordPress's own constructor takes
	 * it (`__construct($post)`): an empty object, since the plugin reads
	 * the members, not the constructor's argument. The runtime double
	 * ignores it, so the double's defaults stand.
	 *
	 * @param array<string, mixed> $fields  Members to override.
	 * @param int                  $post_id The product id (post 7 by default).
	 * @return \WP_Post The product post.
	 */
	private function product_post( array $fields = array(), int $post_id = 7 ): \WP_Post {
		$post            = new \WP_Post( new \stdClass() );
		$post->ID        = max( 0, $post_id );
		$post->post_type = 'product';
		foreach ( $fields as $name => $value ) {
			$post->{$name} = $value;
		}
		return $post;
	}
}
