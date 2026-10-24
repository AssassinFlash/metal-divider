<?php
/**
 * Tests for the PDP data shortcodes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Shortcodes;
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
	 * Sets up translation and escaping.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plain_text();
	}

	/**
	 * The register() method adds every shortcode the templates reference.
	 */
	public function test_register_adds_every_shortcode(): void {
		$shortcodes = new Shortcodes();
		$added      = array();
		WP_Mock::userFunction( 'add_shortcode' )->times( 7 )->andReturnUsing(
			static function ( string $tag, callable $callback ) use ( &$added ): void {
				$added[ $tag ] = $callback;
			}
		);

		$shortcodes->register();

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
			array_keys( $added ),
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
	 * The design-intent hero slot fills the phrase groups' hero phrase
	 * with the pattern's own best-fit word.
	 */
	public function test_design_intent_hero_slot_fills_the_best_fit(): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		$this->mock_product_meta( 'best_fit', 'decorative-screening' );
		WP_Mock::userFunction( 'get_the_title' )->zeroOrMoreTimes()->andReturn( 'Akira | Architectural Metal Screen Pattern' );

		$this->assertSame(
			'Built for light behavior, privacy, and surface impact in Decorative screening.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'hero' ) )
		);
	}

	/**
	 * The design-intent lead slot prefers the pattern's own carried-over
	 * summary (spec 08 D2: the forty-three summaries are an asset).
	 */
	public function test_design_intent_lead_prefers_the_summary(): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) {
				return 'summary' === $key ? 'A carried-over summary sentence.' : '';
			}
		);

		$this->assertSame(
			'A carried-over summary sentence.',
			( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) )
		);
	}

	/**
	 * The design-intent slot falls back to the series group when the
	 * pattern carries no summary, with the attribute slots filled.
	 */
	public function test_design_intent_lead_falls_back_to_the_series_group(): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		$meta = array(
			'summary'         => '',
			'best_fit'        => 'architectural-screening',
			'_sku'            => 'Alhambra',
			'open_area'       => '28',
			'repeat_behavior' => 'repeating',
		);
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		$term       = new \stdClass();
		$term->slug = 'pattern-moroccan-arabic';
		WP_Mock::userFunction( 'get_the_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );

		$assembled = ( new Shortcodes() )->design_intent( array( 'slot' => 'lead' ) );
		$this->assertStringContainsString( 'Alhambra', $assembled );
		$this->assertStringContainsString( 'mashrabiya', $assembled );
	}

	/**
	 * The series word comes from the product_cat term slug's tail, for
	 * the eyebrow and the spec bar's Style cell.
	 */
	public function test_style_comes_from_the_term_slug_tail(): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		$term       = new \stdClass();
		$term->slug = 'pattern-vintage-retro';
		WP_Mock::userFunction( 'get_the_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );

		$this->assertSame( 'vintage-retro', Shortcodes::style() );
	}

	/**
	 * The related cards render the WooCommerce related products in the
	 * replica's card markup.
	 */
	public function test_related_renders_the_cards(): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
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
	 * One product meta read, mocked: the product post plus the one key.
	 *
	 * @param string $key   The field name.
	 * @param string $value The value the meta read returns.
	 */
	private function mock_product_meta( string $key, string $value ): void {
		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $asked ) use ( $key, $value ) {
				return $asked === $key ? $value : '';
			}
		);
	}
}
