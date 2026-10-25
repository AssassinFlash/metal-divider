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
	 * Removes the gallery product double.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['md_wc_product_double'] );
		parent::tearDown();
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

		$post            = new \stdClass();
		$post->ID        = 7;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( $post );
		WP_Mock::userFunction( 'get_the_title' )->zeroOrMoreTimes()->andReturn( 'Akira | Architectural Metal Screen Pattern' );
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->zeroOrMoreTimes()->andReturn( $thumbnail );
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
