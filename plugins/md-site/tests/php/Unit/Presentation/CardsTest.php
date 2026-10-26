<?php
/**
 * Tests for the card partials and the renderer that fills them.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Cards;
use MetalDivider\Site\Presentation\PartialFields;
use MetalDivider\Site\Presentation\Partials;
use MetalDivider\Site\Presentation\PatternFacts;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for the presentation half of the pattern cards (AGENTS §5): the
 * markup a visitor reads comes from `content/livecanvas/partials/*.html`,
 * and PHP hands it values.
 *
 * What these assert is the *split*, because the split is what can silently
 * rot: a design token written back into PHP, a value shortcode that starts
 * returning an element, a partial whose file stops being found. Each case
 * names the failure it would catch.
 *
 * @covers \MetalDivider\Site\Presentation\Cards
 * @covers \MetalDivider\Site\Presentation\PartialFields
 * @covers \MetalDivider\Site\Presentation\Partials
 * @covers \MetalDivider\Site\Presentation\PatternFacts
 */
final class CardsTest extends TestCase {

	/**
	 * The product the fixtures render.
	 */
	private const PRODUCT = 146;

	/**
	 * The product's featured attachment.
	 */
	private const THUMB = 888;

	/**
	 * Registers the real shortcode engine and the plugin's own shortcodes.
	 *
	 * The instances are the *shared* ones, because that is how the site wires
	 * them (`md-site.php` hands `Partials::shared()` and
	 * `PartialFields::shared()` to the plugin): a shortcode can only reach the
	 * object its global name was registered on, so a renderer built on another
	 * instance would push a scope no value shortcode can see. Registering
	 * anything else here would test a wiring the site does not have.
	 */
	public function setUp(): void {
		parent::setUp();
		shortcode_tags_reset();
		PartialFields::forget();
		Partials::forget();
		// The facts memo is request-scoped and static, so it outlives a test
		// case unless it is cleared: a case that mocked one product's images
		// would otherwise answer the next case's question.
		PatternFacts::forget();
		// `add_shortcode()` is WordPress's, and WP_Mock is what stands in for
		// it: the mock writes into the same global registry WordPress keeps,
		// so the real engine below resolves the tags `register()` added — the
		// production path, not a test-only shortcut.
		WP_Mock::userFunction( 'add_shortcode' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $tag, callable $callback ): void {
				$GLOBALS['shortcode_tags'][ $tag ] = $callback;
			}
		);
		PartialFields::shared()->register();
		Partials::shared()->register();
		// WordPress's escape functions, doing what they do: the cards are
		// asserted on their real bytes, so a value that is not escaped when it
		// should be fails here rather than in a browser.
		foreach ( array( '__', '_x', 'esc_html__', 'esc_attr__' ) as $function ) {
			WP_Mock::passthruFunction( $function );
		}
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing(
			static fn( $value = '' ): string => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing(
			static fn( $value = '' ): string => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing(
			static fn( $value = '' ): string => (string) $value
		);
		// No partial is installed as an lc_partial in the unit suite, so the
		// renderer reads the repository files — the same fallback a site
		// before `wp md-site present` takes.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
	}

	/**
	 * Drops the registry and the request's renderers.
	 */
	public function tearDown(): void {
		shortcode_tags_reset();
		Partials::forget();
		PatternFacts::forget();
		parent::tearDown();
	}

	/**
	 * The partial files exist for every card the renderer names, and the two
	 * fragments the tile embeds: a renamed or deleted file is a card that
	 * renders empty on the site, which no other test would notice.
	 */
	public function test_every_card_partial_has_a_file(): void {
		foreach ( array( Cards::EDITORIAL, Cards::CATALOG, Cards::TILE, 'tile-thumbs', 'tile-strip', 'tile-thumb', 'lightbox-thumb' ) as $name ) {
			$this->assertFileExists( Partials::path( $name ), $name . ' must be a partial file' );
		}
	}

	/**
	 * The design lives in the templates, not in PHP: whatever the partial
	 * carries, the renderer's own source writes none of the Tailwind
	 * utilities, gradients or SVG paths a card is drawn with. This is the
	 * regression the refactor exists to prevent — the markup having been
	 * inside `Catalog::card_of()`.
	 */
	public function test_no_design_markup_is_left_in_the_php_renderer(): void {
		$sources = array(
			'PatternFacts.php'  => \MetalDivider\Site\Presentation\PatternFacts::class,
			'PartialFields.php' => \MetalDivider\Site\Presentation\PartialFields::class,
			'Partials.php'      => \MetalDivider\Site\Presentation\Partials::class,
			'Cards.php'         => \MetalDivider\Site\Presentation\Cards::class,
		);
		foreach ( $sources as $file => $class ) {
			$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Presentation/' . $file );
			$this->assertDoesNotMatchRegularExpression( '/<svg\b/', $source, $file . ' must not carry SVG markup' );
			$this->assertDoesNotMatchRegularExpression( '/class="[^"]*\b(?:flex|grid|rounded-|text-\[|px-|py-|gap-)/', $source, $file . ' must not carry a class list' );
			$this->assertStringNotContainsString( 'stroke-linecap', $source, $file . ' must not carry icon attributes' );
		}
	}

	/**
	 * The catalog does not keep a second copy of a card: its three renderers
	 * delegate to this layer, and no markup lives in them.
	 *
	 * This is the state the refactor left behind, and the one worth locking:
	 * a card re-inlined into `Catalog` would still render correctly in every
	 * other test here (the same bytes come out either way), so only a
	 * structural assertion catches the second rendering system coming back.
	 */
	public function test_the_catalog_delegates_its_cards_instead_of_drawing_them(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Catalog.php' );

		$this->assertStringNotContainsString( '<svg', $source, 'no icon markup in the catalog' );
		$this->assertStringNotContainsString( 'hpl__card', $source, 'the home card is the partial\'s' );
		$this->assertStringNotContainsString( 'pattern-card__action', $source, 'the catalog card is the partial\'s' );
		$this->assertStringNotContainsString( 'pattern-lightbox', $source, 'the tile lightbox is the partial\'s' );
		foreach ( array( 'card_of', 'tile' ) as $method ) {
			$this->assertMatchesRegularExpression(
				'/\$this->cards->(?:editorial|tile)\(/',
				$source,
				'the catalog renders ' . $method . '() through the presentation layer'
			);
		}
	}

	/**
	 * A card is the partial's markup with the product's facts filled in: the
	 * code, the series, the badges and the image all come from WooCommerce,
	 * and the partial's own class list survives the render intact.
	 */
	public function test_the_editorial_card_fills_the_partial_with_the_products_facts(): void {
		$this->mock_product();

		$html = ( new Cards() )->editorial( self::PRODUCT, 'Mashrabiya pattern' );

		$this->assertStringContainsString( 'class="hpl__card', $html, 'the partial\'s own class list' );
		$this->assertStringContainsString( 'Mandala', $html, 'the pattern code' );
		$this->assertStringContainsString( 'Mashrabiya pattern', $html, 'the call site\'s eyebrow' );
		$this->assertStringContainsString( 'Architectural screening', $html, 'the best-fit word' );
		$this->assertStringContainsString( 'moroccan-arabic', $html, 'the series badge' );
		$this->assertStringContainsString( 'Open: 58%', $html, 'the open area' );
		$this->assertStringContainsString( 'href="https://site.test/pattern/mandala/"', $html );
		$this->assertStringNotContainsString( '[md_', $html, 'no shortcode may survive the render' );
	}

	/**
	 * The catalog card draws the pattern's four spec rows from the product,
	 * including the family, which no other card carries.
	 */
	public function test_the_catalog_card_fills_its_spec_rows(): void {
		$this->mock_product();

		$html = ( new Cards() )->catalog( self::PRODUCT );

		$this->assertStringContainsString( 'class="pattern-card"', $html );
		$this->assertStringContainsString( 'aria-label="View Mandala specification"', $html );
		$this->assertStringContainsString( 'Moroccan &amp; Arabic', $html, 'the family row, escaped' );
		$this->assertStringContainsString( 'Architectural screening', $html );
		$this->assertStringContainsString( '58%', $html );
		$this->assertStringNotContainsString( '[md_', $html );
	}

	/**
	 * The tile's lightbox strip is the main image followed by the gallery,
	 * and the main one carries the selection state the interaction layer
	 * reads. The two-slot thumb frame carries the first two gallery images.
	 */
	public function test_the_tile_builds_its_thumb_frame_and_lightbox_strip(): void {
		$this->mock_product( array( 901, 902, 903 ) );

		$html = ( new Cards() )->tile( self::PRODUCT, 'Moroccan & Arabic' );

		$this->assertSame( 2, substr_count( $html, 'class="project-thumb"' ), 'the two-slot frame holds the first two gallery images' );
		$this->assertSame( 4, substr_count( $html, 'data-pattern-thumb' ), 'the strip is the main image plus the three gallery images' );
		$this->assertStringNotContainsString( 'pattern-lightbox-thumb active', $html, 'no button is preselected in the markup' );
		$this->assertStringContainsString( 'data-pattern-category="Moroccan &amp; Arabic"', $html );
		$this->assertStringContainsString( 'id="pattern-lightbox-pattern-146"', $html );
		$this->assertStringContainsString( '>Preview<', $html );
		$this->assertStringNotContainsString( '[md_', $html );
	}

	/**
	 * The tile's lightbox opens on the product's real first image and its
	 * real count — not on the placeholder `src=""` and the literal `1 / 1`
	 * the converted markup carried.
	 *
	 * A stage image with no `src` makes the interaction layer's first
	 * `show(0)` the only thing that fills it, and a counter that starts at
	 * "1 / 1" tells the visitor the pattern has one image when the strip
	 * lists four. Both are server-known facts, so both are rendered.
	 */
	public function test_the_tile_lightbox_starts_on_real_server_values(): void {
		$this->mock_product( array( 901, 902, 903 ) );

		$html = ( new Cards() )->tile( self::PRODUCT, 'Moroccan & Arabic' );

		$this->assertMatchesRegularExpression(
			'/data-pattern-main-image src="https:\/\/site\.test\/uploads\/888-large\.webp" alt="Mandala"/',
			$html,
			'the stage image is the product\'s featured image with its code as alt'
		);
		$this->assertStringNotContainsString( 'src=""', $html, 'no empty src reaches the browser' );
		$this->assertStringContainsString( '1 / 4', $html, 'the count is the strip\'s real length' );
		$this->assertStringNotContainsString( '1 / 1', $html, 'the literal placeholder is gone' );
	}

	/**
	 * Every image the tile draws carries its own attachment's ladder and a
	 * `sizes` describing the box it lands in.
	 *
	 * The grid image, the thumb frame and the strip are three *different*
	 * attachments, so one product-level ladder cannot serve them: asking for
	 * the featured image's `srcset` would describe the wrong picture at the
	 * wrong widths. This is the check that fails if the partials go back to a
	 * bare `src` — the acceptance scan in `ImageSizesTest` cannot see it,
	 * because it reads the partial files and those hold shortcode
	 * placeholders, not uploads.
	 */
	public function test_every_tile_image_carries_its_own_srcset_and_sizes(): void {
		$this->mock_product( array( 901, 902 ) );

		$html = ( new Cards() )->tile( self::PRODUCT, 'Moroccan & Arabic' );

		// The grid image: the featured attachment's ladder.
		$this->assertMatchesRegularExpression(
			'/<img src="[^"]*888-large\.webp" srcset="[^"]*888-400w\.webp 400w'
			. '[^"]*888-800w\.webp 800w" sizes="\(min-width: 1025px\) 181px[^"]*"/',
			$html,
			'the grid image offers the featured attachment\'s ladder at the grid width'
		);
		// A thumb: a *gallery* attachment, so the ladder names that file.
		$this->assertMatchesRegularExpression(
			'/<img src="[^"]*901-large\.webp" srcset="[^"]*901-400w\.webp 400w[^"]*" sizes="\(min-width: 1025px\) 87px/',
			$html,
			'the thumb offers its own attachment\'s ladder at the thumb width'
		);
		$this->assertStringNotContainsString(
			'888-400w.webp 400w, https://site.test/uploads/888-800w.webp 800w" sizes="(min-width: 1025px) 87px',
			$html,
			'the featured image\'s ladder never describes a gallery thumb'
		);
		// The stage: sized to the dialog, not to a grid cell.
		$this->assertMatchesRegularExpression(
			'/data-pattern-main-image[^>]*srcset="[^"]*888-400w\.webp 400w[^"]*" sizes="\(min-width: 900px\) 900px, 100vw"/',
			$html,
			'the stage describes the dialog\'s width'
		);
	}

	/**
	 * The strip buttons carry the switching data the interaction layer needs:
	 * the `srcset` list and the `sizes` value, so a button press can move both
	 * attributes onto the stage.
	 *
	 * Without these the stage could only ever be given a `src`, which is the
	 * defect this covers: the previous image's `srcset` would survive the
	 * switch and the browser could pick a candidate from the wrong picture.
	 */
	public function test_every_strip_button_carries_its_switching_data(): void {
		$this->mock_product( array( 901, 902 ) );

		$html = ( new Cards() )->tile( self::PRODUCT, 'Moroccan & Arabic' );

		$buttons = array();
		preg_match_all( '/<button[^>]*data-pattern-thumb[^>]*>/', $html, $buttons );
		$this->assertCount( 3, $buttons[0], 'main image plus two gallery images' );
		foreach ( $buttons[0] as $button ) {
			$this->assertStringContainsString( ' data-src="http', $button );
			$this->assertStringContainsString( ' data-alt="', $button );
			$this->assertMatchesRegularExpression( '/ data-srcset="[^"]*-400w\.webp 400w[^"]*"/', $button );
			// The button carries no `sizes`: that describes the *box*, and the
			// box these buttons are drawn in (a 64px strip thumb) is not the box
			// their picture is shown in (a 900px stage). The stage declares its
			// own, and moving the thumb's onto it would ask for a candidate
			// five times too small.
			$this->assertStringNotContainsString( ' data-sizes=', $button, 'the ladder travels, not the thumb\'s own sizes' );
		}
	}

	/**
	 * The interaction layer moves the ladder with the source, and leaves
	 * `sizes` to the stage.
	 *
	 * A source assertion rather than a behavioural one, because this suite has
	 * no DOM; the behaviour is verified in real Chrome (`show()` moves `src`
	 * and `srcset` together and the stage keeps `(min-width: 900px) 900px,
	 * 100vw` across presses, measured 2026-10-26). What the source cannot tell
	 * you — and what this asserts — is that the *stale* case is handled: the
	 * attribute is removed, never blanked, so the previous picture's
	 * candidates cannot survive the switch.
	 */
	public function test_the_lightbox_switch_replaces_both_src_and_srcset(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' );

		$this->assertMatchesRegularExpression(
			'/stageImage\.src = active\.getAttribute\(\'data-src\'\)/',
			$source,
			'the source moves'
		);
		$this->assertMatchesRegularExpression(
			'/var srcset = active\.getAttribute\(\'data-srcset\'\)/',
			$source,
			'the ladder is read from the button being shown'
		);
		$this->assertMatchesRegularExpression(
			'/stageImage\.setAttribute\(\'srcset\', srcset\)/',
			$source,
			'the ladder moves with it'
		);
		$this->assertMatchesRegularExpression(
			'/stageImage\.removeAttribute\(\'srcset\'\)/',
			$source,
			'no ladder means the attribute goes, so no stale candidate can win'
		);
		// `sizes` is the stage's, so the switch must not overwrite it with the
		// thumb's.
		$this->assertStringNotContainsString(
			"stageImage.setAttribute('sizes'",
			$source,
			'the thumb\'s sizes is never applied to the stage'
		);
	}

	/**
	 * A gallery attachment with no ladder of its own yields no `srcset`, and
	 * the element is then a plain `src` — the pair is omitted whole rather
	 * than emitted as `srcset=""`.
	 */
	public function test_an_attachment_without_a_ladder_renders_no_srcset(): void {
		$this->mock_product( array( 901 ), true, '58', 'product', array( 400, 800 ), null, array( 'native_srcset' => false ) );

		$html = ( new Cards() )->tile( self::PRODUCT, 'Moroccan' );

		$this->assertStringNotContainsString( 'srcset=""', $html, 'no empty srcset attribute' );
		$this->assertStringNotContainsString( ' sizes=""', $html, 'no empty sizes attribute' );
		$this->assertStringContainsString( 'class="pattern-image"', $html, 'the image is still drawn' );
	}

	/**
	 * Every card image declares the attachment's real dimensions, never the
	 * replica's literal numbers. The catalog card used to carry the old
	 * markup's fixed `400×800` while the editorial card carried the real pair,
	 * so the same photo was announced at two sizes on one site.
	 */
	public function test_every_card_image_declares_the_real_dimensions(): void {
		$this->mock_product();

		$cards = new Cards();
		$html  = $cards->catalog( self::PRODUCT ) . $cards->editorial( self::PRODUCT, 'x' ) . $cards->tile( self::PRODUCT, 'x' );

		$this->assertStringContainsString( 'width="400" height="800"', $html, 'the fixture attachment\'s real size reaches the markup' );

		foreach ( array( Cards::CATALOG, Cards::EDITORIAL ) as $name ) {
			$file = (string) file_get_contents( Partials::path( $name ) );
			$this->assertDoesNotMatchRegularExpression(
				'/\bwidth="\d+"\s+height="\d+"/',
				$file,
				$name . ' must not hardcode image dimensions'
			);
		}
	}

	/**
	 * No partial carries a placeholder value a server already knows: an empty
	 * `src`, a literal image count, or a hardcoded dimension pair.
	 *
	 * This is the general form of the two cases above, so a future partial
	 * cannot reintroduce a fake value the way the converted markup did.
	 */
	public function test_no_partial_ships_a_placeholder_value(): void {
		foreach ( Partials::names() as $name ) {
			$file = (string) file_get_contents( Partials::path( $name ) );
			$this->assertDoesNotMatchRegularExpression( '/\ssrc=""\s/', $file, $name . ' must not ship an empty src' );
			$this->assertDoesNotMatchRegularExpression( '/\salt=""\s/', $file, $name . ' must not ship an empty alt' );
		}
	}

	/**
	 * No card value is ever written into a `class` attribute: a class token
	 * is the template's (AGENTS §5), and a shortcode there both hands PHP a
	 * design decision and makes WindPress try to compile the token.
	 */
	public function test_no_shortcode_is_written_into_a_class_attribute(): void {
		foreach ( Partials::names() as $name ) {
			$file = (string) file_get_contents( Partials::path( $name ) );
			// Tailwind's arbitrary values legitimately carry brackets
			// (`font-['Playfair_Display']`); a shortcode is a bracket *followed
			// by a tag name*, which is what must never appear in a class.
			$this->assertDoesNotMatchRegularExpression(
				'/class="[^"]*\[md_/',
				$file,
				$name . ' must not put a shortcode inside a class attribute'
			);
		}
	}

	/**
	 * The PDP's related cards are the `pdp-related-card` partial, one render
	 * per related product.
	 *
	 * The card's link and heading belong to the *related* product, not to the
	 * one the PDP renders — which is why they come from the item scope
	 * (`[md_item_link]`, `[md_media_name]`) and not from
	 * `[md_pattern_permalink]`: a card that reused the current post's
	 * permalink would send every related click back to the page it is on.
	 */
	public function test_the_related_cards_are_rendered_from_the_partial(): void {
		// WP_Mock keeps one handler per function, so the ids this case cares
		// about go through the fixture rather than through a second mock.
		$this->mock_product( array(), true, '58', 'product', array( 400, 800 ), null, array(), array( 11, 12 ) );

		$html = ( new Cards() )->related( self::PRODUCT, array( 11, 12 ) );

		$this->assertSame( 2, substr_count( $html, 'class="pdp-related-card gs-reveal"' ), 'one card per related product' );
		$this->assertStringContainsString( 'href="https://site.test/pattern/11/"', $html );
		$this->assertStringContainsString( 'href="https://site.test/pattern/12/"', $html );
		$this->assertStringContainsString( '<h3>Zelda</h3>', $html, 'the heading is the code, as the replica had it' );
		$this->assertStringContainsString( 'alt="Zelda | Architectural Metal Screen Pattern"', $html, 'the alt is the full title' );
		$this->assertStringContainsString( '<span>Compare density and rhythm</span>', $html );
		$this->assertStringNotContainsString( '[md_', $html );
		$this->assertStringNotContainsString( ' style=', $html );
	}

	/**
	 * No related products, no markup — WooCommerce's answer decides, and the
	 * renderer adds no empty wrapper (which is the failure mode for a
	 * container in a grid).
	 */
	public function test_the_related_cards_are_empty_without_related_products(): void {
		$this->mock_product();

		$cards = new Cards();
		$this->assertSame( '', $cards->related( self::PRODUCT, array() ), 'no related products, no wrapper' );
		$this->assertNotSame( '', $cards->related( self::PRODUCT, array( 11 ) ), 'the control: a non-empty list does render' );
	}

	/**
	 * A hub series card is the `hub-series-card` partial: the term's own
	 * thumbnail at full size with the attachment's metadata, its name, its
	 * description and the fixed call.
	 */
	public function test_the_hub_series_card_is_rendered_from_the_partial(): void {
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 18, 'thumbnail_id', true )->andReturn( '1502' );
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->once()->with( 1502, 'full' )->andReturn( 'https://site.test/uploads/abstract.webp' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->once()->with( 1502 )->andReturn(
			array(
				'width'  => 2048,
				'height' => 2048,
			)
		);
		WP_Mock::userFunction( 'get_post_meta' )->once()->with( 1502, '_wp_attachment_image_alt', true )->andReturn( '' );

		$html = ( new Cards() )->series_card(
			array(
				'term_id'     => 18,
				'name'        => 'Abstract',
				'description' => 'Flowing curves and organic shapes.',
				'link'        => 'https://site.test/collections/pattern-abstract/',
			)
		);

		$this->assertStringContainsString( '<li><a class="cph__card" href="https://site.test/collections/pattern-abstract/">', $html );
		$this->assertStringContainsString( '<img src="https://site.test/uploads/abstract.webp" alt="Abstract" loading="lazy" class="cph__media-image" width="2048" height="2048">', $html );
		$this->assertStringContainsString( '<h3 class="cph__card-title">Abstract</h3>', $html );
		$this->assertStringContainsString( '<p class="cph__card-copy">Flowing curves and organic shapes.</p>', $html );
		$this->assertStringContainsString( '<span class="cph__card-cta">Explore</span>', $html );
		$this->assertStringNotContainsString( '[md_', $html );
	}

	/**
	 * A series without a thumbnail draws the empty media box the card CSS
	 * styles — not an `<img>` with no source, which the browser would fetch
	 * as the page itself.
	 */
	public function test_a_hub_series_without_a_thumbnail_draws_the_empty_media_box(): void {
		WP_Mock::userFunction( 'get_term_meta' )->once()->with( 22, 'thumbnail_id', true )->andReturn( '' );

		$html = ( new Cards() )->series_card(
			array(
				'term_id'     => 22,
				'name'        => 'Landscape',
				'description' => '',
				'link'        => 'https://site.test/collections/pattern-landscape/',
			)
		);

		$this->assertStringContainsString( '<div class="cph__media"></div>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'src=""', $html );
	}

	/**
	 * The value shortcodes the two new partials read: each is a value, so an
	 * empty scope yields an empty string rather than a stray attribute.
	 */
	public function test_the_item_scope_shortcodes_render_values_only(): void {
		$fields = PartialFields::shared();
		$fields->push(
			array(
				'link'   => 'https://site.test/x/',
				'name'   => 'A & B',
				'text'   => 'Copy',
				'width'  => 10,
				'height' => 20,
			)
		);
		try {
			$this->assertSame( 'https://site.test/x/', $fields->item_link() );
			$this->assertSame( 'A &amp; B', $fields->media_name() );
			$this->assertSame( 'Copy', $fields->media_text() );
			$this->assertSame( ' width="10" height="20"', $fields->media_dimensions() );
		} finally {
			$fields->pop();
		}

		$this->assertSame( '', $fields->item_link(), 'an empty scope yields nothing, not a stray href' );
		$this->assertSame( '', $fields->media_dimensions(), 'no dimensions, no attribute pair' );
	}

	/**
	 * The PDP gallery-slot image is the `pdp-image` partial, and its output is
	 * the element the inline renderer built: same attribute order, same
	 * loading tier, same dimension pair.
	 *
	 * The slot *identity* stays in `Shortcodes::slot_attachment()` (its own
	 * tests cover the alt-role algorithm); this asserts the element.
	 */
	public function test_a_slot_image_is_rendered_from_the_partial(): void {
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( 'https://site.test/uploads/slot.webp' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->andReturn(
			array(
				'width'  => 800,
				'height' => 600,
			)
		);

		$html = ( new Cards() )->slot_image( self::THUMB, 'A pattern', false );

		$this->assertSame(
			'<img src="https://site.test/uploads/slot.webp" alt="A pattern" loading="lazy" width="800" height="600">',
			$html
		);
	}

	/**
	 * The hero slot is the page's first paint, so it is eager and carries the
	 * `fetchpriority` hint; the same partial produces it, and the decorative
	 * empty alt the old theme used is the caller's decision that arrives as an
	 * empty string.
	 */
	public function test_a_hero_slot_image_is_eager_and_decorative(): void {
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( 'https://site.test/uploads/hero.webp' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->andReturn(
			array(
				'width'  => 1024,
				'height' => 1024,
			)
		);

		$html = ( new Cards() )->slot_image( self::THUMB, '', true );

		$this->assertSame(
			'<img src="https://site.test/uploads/hero.webp" alt="" loading="eager" fetchpriority="high" width="1024" height="1024">',
			$html
		);
	}

	/**
	 * An attachment with no metadata renders the element without a dimension
	 * pair rather than `width="0" height="0"`.
	 */
	public function test_a_slot_image_without_metadata_omits_the_dimensions(): void {
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( 'https://site.test/uploads/plain.webp' );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->andReturn( false );

		$html = ( new Cards() )->slot_image( self::THUMB, 'A pattern', false );

		$this->assertSame( '<img src="https://site.test/uploads/plain.webp" alt="A pattern" loading="lazy">', $html );
	}

	/**
	 * An attachment whose URL does not resolve renders nothing, so the
	 * template's `[lc_if]` guard and the element it guards cannot disagree.
	 */
	public function test_a_slot_image_without_a_url_renders_nothing(): void {
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( '' );

		$this->assertSame( '', ( new Cards() )->slot_image( self::THUMB, 'A pattern', false ) );
	}

	/**
	 * The `pdp-image` partial carries exactly one shortcode inside the tag.
	 *
	 * WordPress's `do_shortcodes_in_html_tags()` cannot expand two *adjacent*
	 * shortcodes in an HTML tag: `wp_kses_attr_parse()` fails on the element
	 * and every bracket in it is entity-encoded, so nothing expands and the
	 * visitor sees the raw tags (measured on the dev instance, 2026-10-26).
	 * The dimensions and the loading tier therefore travel as one combined
	 * suffix, and this test is what stops them being split back into two.
	 */
	public function test_the_slot_image_partial_uses_one_attribute_suffix(): void {
		$file = (string) file_get_contents( Partials::path( Cards::SLOT_IMAGE ) );

		$this->assertSame( 3, preg_match_all( '/\[md_[a-z_]+\]/', $file ), 'src, alt and one combined suffix' );
		$this->assertStringContainsString( '[md_media_attributes]', $file );
		$this->assertStringNotContainsString( '][md_', $file, 'no two adjacent shortcodes' );
		$this->assertStringNotContainsString( 'md_media_priority', $file, 'the tier is not a second suffix' );
		$this->assertStringNotContainsString( 'md_media_dimensions', $file, 'the dimensions are not a second suffix' );
	}

	/**
	 * The combined suffix still composes the two independent answers: the
	 * loading tier always, the dimensions only when the item declares them.
	 */
	public function test_the_attribute_suffix_composes_loading_and_dimensions(): void {
		$fields = PartialFields::shared();

		$fields->push( array( 'priority' => 'eager' ) );
		try {
			$this->assertSame( ' loading="eager" fetchpriority="high"', $fields->media_attributes(), 'eager, no dimensions' );
		} finally {
			$fields->pop();
		}

		$fields->push(
			array(
				'priority' => 'lazy',
				'width'    => 10,
				'height'   => 20,
			)
		);
		try {
			$this->assertSame( ' loading="lazy" width="10" height="20"', $fields->media_attributes() );
		} finally {
			$fields->pop();
		}

		$fields->push( array( 'priority' => 'lazy' ) );
		try {
			$this->assertSame( ' loading="lazy"', $fields->media_attributes(), 'lazy and unsized' );
		} finally {
			$fields->pop();
		}
	}

	/**
	 * A product without a featured image draws no card at all. The previous
	 * inline renderer emitted the whole card with `src=""`, which makes the
	 * browser re-request the page URL; the pattern faces skip the product
	 * instead, and `[md_pattern_total]`-style counts are unaffected because
	 * they query WooCommerce, not this renderer.
	 */
	public function test_a_product_without_an_image_draws_no_card(): void {
		$this->mock_product( array(), false );

		$cards = new Cards();
		$this->assertSame( '', $cards->editorial( self::PRODUCT, 'x' ), 'the home card is skipped' );
		$this->assertSame( '', $cards->catalog( self::PRODUCT ), 'the catalog card is skipped' );
		$this->assertSame( '', $cards->tile( self::PRODUCT, 'x' ), 'the tile is skipped' );
	}

	/**
	 * All three faces skip a product that is not a product: a stray id is a
	 * caller bug, and rendering a card of empty values would put a blank
	 * frame in a grid instead of surfacing it.
	 */
	public function test_all_three_faces_skip_a_non_product(): void {
		$this->mock_product( array(), true, '58', 'page' );

		$cards = new Cards();
		$this->assertSame( '', $cards->editorial( self::PRODUCT, 'x' ) );
		$this->assertSame( '', $cards->catalog( self::PRODUCT ) );
		$this->assertSame( '', $cards->tile( self::PRODUCT, 'x' ) );
	}

	/**
	 * The context scope belongs to one render: a value pushed for a tile is
	 * gone once the render returns, so one card's eyebrow can never leak into
	 * the next card, and a nested fragment sees its own item rather than the
	 * outer partial's.
	 */
	public function test_the_render_scope_is_popped_when_the_render_ends(): void {
		$fields  = PartialFields::shared();
		$partial = new Partials();

		$html = $partial->render(
			'tile-thumb',
			array(
				'url' => 'https://site.test/a.webp',
				'alt' => 'A',
			)
		);
		$this->assertStringContainsString( 'src="https://site.test/a.webp"', $html );
		$this->assertSame( '', $fields->value( 'url' ), 'the scope is empty again' );
	}

	/**
	 * A render puts back every loop global it moved, not just the post.
	 *
	 * `setup_postdata()` writes nine globals besides `$GLOBALS['post']` —
	 * `$id`, `$authordata`, `$currentday`, `$currentmonth`, `$page`, `$pages`,
	 * `$multipage`, `$more`, `$numpages` — and `wp_reset_postdata()` restores
	 * only what the main query's post derives. On these faces the main query
	 * is a `page` and the rendered post is a `product`, so the two disagree:
	 * the product's `$pages` and `$numpages` would leak into whatever the
	 * template asks next.
	 *
	 * The fixture's `setup_postdata` writes the product's values, so a render
	 * that restores nothing leaves every one of them behind and this fails.
	 */
	public function test_a_render_restores_every_loop_global_it_moved(): void {
		$this->mock_product();
		$page   = (object) array( 'ID' => 7 );
		$before = array(
			'post'         => $page,
			'id'           => 7,
			'authordata'   => 'author-of-the-page',
			'currentday'   => '01',
			'currentmonth' => '01',
			'page'         => 1,
			'pages'        => array( 'the page body' ),
			'multipage'    => '',
			'more'         => 0,
			'numpages'     => 1,
		);
		foreach ( $before as $name => $value ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.WP.GlobalVariablesOverride.Prohibited -- the fixture sets WordPress's own loop globals to the page-shaped values a render has to put back.
			$GLOBALS[ $name ] = $value;
		}

		( new Cards() )->editorial( self::PRODUCT, 'x' );

		foreach ( $before as $name => $value ) {
			$this->assertSame( $value, $GLOBALS[ $name ] ?? null, '$' . $name . ' is the caller\'s again' );
		}
	}

	/**
	 * A global the render found unset comes back unset, not as a null.
	 *
	 * `array_key_exists` is the difference that matters: restoring `null`
	 * would make a later `isset( $pages )` answer "yes" for a request that
	 * never had one. The fixture's `setup_postdata` sets these, so a restore
	 * that writes null instead of unsetting still fails here.
	 */
	public function test_a_render_leaves_an_unset_global_unset(): void {
		$this->mock_product();
		foreach ( array( 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' ) as $name ) {
			unset( $GLOBALS[ $name ] );
		}

		( new Cards() )->editorial( self::PRODUCT, 'x' );

		foreach ( array( 'pages', 'numpages', 'more', 'id', 'authordata' ) as $name ) {
			$this->assertArrayNotHasKey( $name, $GLOBALS, '$' . $name . ' is unset again' );
		}
	}

	/**
	 * A render throws, and the globals still come back: the restore is in a
	 * `finally`, not after the happy path.
	 */
	public function test_a_failed_render_still_restores_the_loop(): void {
		$this->mock_product();
		$sentinel         = (object) array( 'ID' => 7 );
		$GLOBALS['post']  = $sentinel; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fixture's loop state.
		$GLOBALS['pages'] = array( 'the page body' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the fixture's loop state.

		// A partial name that resolves to no markup still runs the try/finally.
		$partial = new Partials();
		$this->assertSame( '', $partial->render_post( 'no-such-partial', self::PRODUCT ) );

		$this->assertSame( $sentinel, $GLOBALS['post'], '$post is the caller\'s again' );
		$this->assertSame( array( 'the page body' ), $GLOBALS['pages'], '$pages is the caller\'s again' );
	}

	/**
	 * The other direction: a `srcset` list is dropped entirely when the
	 * attachment's file is not under the uploads directory.
	 *
	 * The list is built by stripping the uploads prefix off each file and
	 * appending the rest to the uploads URL. When the file lives elsewhere (a
	 * symlinked uploads directory, a file recorded with an absolute path, a
	 * CDN copy) `str_replace` would remove an arbitrary substring and produce
	 * a URL naming a file that does not exist. The guard drops the list
	 * instead, and the image renders at its `src` alone.
	 */
	public function test_a_srcset_list_is_dropped_when_the_file_is_outside_uploads(): void {
		$uploads = $this->temporary_dir( 'guard-uploads' );
		$outside = $this->temporary_dir( 'guard-outside' );
		// The file must be readable and globbable for this to be discriminating:
		// a missing file bails out before the prefix guard ever runs, and a test
		// built on one would pass with the guard removed.
		file_put_contents( $outside . '/888-400w.webp', '' );
		file_put_contents( $outside . '/888-800w.webp', '' );

		$this->mock_product(
			array(),
			true,
			'58',
			'product',
			array( 400, 800 ),
			null,
			array(
				'native_srcset' => false,
				'file'          => $outside . '/888-400w.webp',
				'basedir'       => $uploads,
				'baseurl'       => 'https://site.test/uploads',
				'url'           => 'https://site.test/uploads/888-400w.webp',
			)
		);

		$pair = PatternFacts::srcset( self::PRODUCT, '(min-width: 768px) 33vw, 100vw' );

		$this->assertSame( '', $pair['srcset'], 'no list is invented for a file outside uploads' );
		$this->assertSame( '', $pair['sizes'] );

		foreach ( array( '888-400w.webp', '888-800w.webp' ) as $file ) {
			unlink( $outside . '/' . $file );
		}
		rmdir( $outside );
		rmdir( $uploads );
	}

	/**
	 * The positive branch: a file under the uploads directory gets its list,
	 * with each URL built from the same prefix and one slash.
	 *
	 * The fix has to drop foreign files *without* dropping the site's own, and
	 * the URL assembly (`baseurl` + the path below `basedir`) is what has to
	 * stay exact. The list is built from a real glob, so the fixture writes
	 * real files: a faked glob would not exercise the prefix check at all.
	 */
	public function test_a_file_under_uploads_gets_its_list_and_exact_urls(): void {
		$uploads = $this->temporary_dir( 'inside' );
		$monthly = $uploads . '/2026/10';
		if ( ! is_dir( $monthly ) ) {
			mkdir( $monthly, 0777, true );
		}
		file_put_contents( $monthly . '/888-400w.webp', '' );
		file_put_contents( $monthly . '/888-800w.webp', '' );

		$this->mock_product(
			array(),
			true,
			'58',
			'product',
			array( 400, 800 ),
			null,
			array(
				'native_srcset' => false,
				'file'          => $monthly . '/888-400w.webp',
				'basedir'       => $uploads,
				'baseurl'       => 'https://site.test/uploads',
				'url'           => 'https://site.test/uploads/2026/10/888-400w.webp',
			)
		);

		$pair = PatternFacts::srcset( self::PRODUCT, 'sizes' );

		$this->assertSame(
			'https://site.test/uploads/2026/10/888-400w.webp 400w, https://site.test/uploads/2026/10/888-800w.webp 800w',
			$pair['srcset'],
			'both widths, each under the uploads URL with one slash'
		);
		$this->assertSame( 'sizes', $pair['sizes'] );

		foreach ( array( $monthly . '/888-400w.webp', $monthly . '/888-800w.webp' ) as $file ) {
			unlink( $file );
		}
		rmdir( $monthly );
		rmdir( $uploads . '/2026' );
		rmdir( $uploads );
	}

	/**
	 * WordPress's own `srcset` wins when it has one: the fallback is for the
	 * attachments the media pipeline left without sub-size metadata, and it
	 * must never replace a list WordPress already computed.
	 */
	public function test_wordpresss_own_srcset_is_used_when_it_exists(): void {
		$this->mock_product(
			array(),
			true,
			'58',
			'product',
			array( 400, 800 ),
			null,
			array( 'native_srcset' => 'https://site.test/uploads/888-760w.webp 760w' )
		);

		$pair = PatternFacts::srcset( self::PRODUCT, 'sizes' );

		$this->assertSame( 'https://site.test/uploads/888-760w.webp 760w', $pair['srcset'] );
		$this->assertSame( 'sizes', $pair['sizes'] );
	}

	/**
	 * The facts are read once per product per request, however many times a
	 * face asks for them.
	 *
	 * A page draws many cards, and the tile alone asks about one product's
	 * gallery for its thumb frame and again for its lightbox strip; without a
	 * memo every one of those reads the same `_product_image_gallery` meta and
	 * re-resolves the same attachments. The memo is request-scoped by
	 * construction (a static array), so what this asserts is that a second
	 * ask is answered without a second read.
	 */
	public function test_the_facts_are_read_once_per_product_per_request(): void {
		$counter                = new \stdClass();
		$counter->gallery_reads = 0;
		$counter->size_reads    = 0;
		$this->mock_product( array( 901, 902, 903 ), true, '58', 'product', array( 400, 800 ), $counter );

		PatternFacts::gallery( self::PRODUCT );
		PatternFacts::gallery( self::PRODUCT );

		$this->assertSame( 1, $counter->gallery_reads, 'the gallery meta is read once, however many times it is asked for' );

		// The gallery resolves one size per member; a repeated ask for one
		// attachment must add nothing on top.
		$after_gallery = $counter->size_reads;
		PatternFacts::size( self::THUMB );
		PatternFacts::size( self::THUMB );
		$this->assertSame( $after_gallery + 1, $counter->size_reads, 'a repeated size ask is answered from the memo' );
	}

	/**
	 * The memo is per request, not per product for ever: clearing it makes the
	 * next ask read again. That is what keeps a long-lived worker (and the
	 * test suite) from answering with one request's data.
	 */
	public function test_forgetting_the_memo_makes_the_next_ask_read_again(): void {
		$counter                = new \stdClass();
		$counter->gallery_reads = 0;
		$counter->size_reads    = 0;
		$this->mock_product( array( 901 ), true, '58', 'product', array( 400, 800 ), $counter );

		PatternFacts::gallery( self::PRODUCT );
		PatternFacts::forget();
		PatternFacts::gallery( self::PRODUCT );

		$this->assertSame( 2, $counter->gallery_reads );
	}

	/**
	 * A partial composed of nested partials and item loops renders through
	 * one path: the tile's strip partial walks the list the renderer
	 * supplied, and each item's own values win over the outer frame's.
	 */
	public function test_a_fragment_walks_the_list_its_caller_supplied(): void {
		$partial = new Partials();

		$html = $partial->render(
			'tile-strip',
			array(
				'strip' => array(
					array(
						'url' => 'https://site.test/1.webp',
						'alt' => 'One',
					),
					array(
						'url' => 'https://site.test/2.webp',
						'alt' => 'Two',
					),
				),
			)
		);

		$this->assertStringContainsString( 'data-src="https://site.test/1.webp"', $html );
		$this->assertStringContainsString( 'data-src="https://site.test/2.webp"', $html );
		$this->assertStringContainsString( 'alt="Two"', $html, 'each item\'s own alt, not the outer frame\'s' );
		$this->assertSame( 0, substr_count( $html, 'active' ), 'the selection state is the interaction layer\'s, not the template\'s' );
	}

	/**
	 * A list with one image prints no thumb frame at all — the converted
	 * markup had none, and an empty grid would leave its gap behind.
	 */
	public function test_an_empty_space_list_prints_no_frame(): void {
		$partial = new Partials();

		$this->assertSame( '', $partial->render( 'tile-thumbs', array( 'thumbs' => array() ) ) );
	}

	/**
	 * An unknown partial renders nothing rather than an error or a warning:
	 * a template naming a partial that was renamed must not break the page.
	 */
	public function test_an_unknown_partial_renders_nothing(): void {
		$this->assertSame( '', ( new Partials() )->render( 'no-such-partial' ) );
	}

	/**
	 * The partials' files are what the site installs, and `wp md-site present`
	 * finds them through the same directory the renderer reads: the drift
	 * check and the render cannot disagree about where a partial lives.
	 */
	public function test_the_partial_path_is_the_installed_template_directory(): void {
		$this->assertStringContainsString(
			'content/livecanvas/partials',
			Partials::path( 'card-editorial' )
		);
		$this->assertContains( 'card-editorial', Partials::names() );
		$this->assertContains( 'pattern-tile', Partials::names() );
	}

	/**
	 * The best-fit and open-area wordings are the site's own rules, not a
	 * template's: a pattern without a sourced open area says so, and a card
	 * never shows "0%" or an empty pill.
	 */
	public function test_a_card_without_an_open_area_names_the_effect(): void {
		$this->mock_product( array(), true, '' );

		$html = ( new Cards() )->editorial( self::PRODUCT, 'Floral pattern' );

		$this->assertStringContainsString( 'Effect: Architectural screening', $html );
		$this->assertStringNotContainsString( 'Open: 0', $html );
	}

	/**
	 * The renderer never writes markup: every method returns the partial's
	 * own bytes with the values substituted, so a Tailwind change is a file
	 * change and never a code change.
	 */
	public function test_the_renderer_output_is_the_partial_file(): void {
		$this->mock_product();

		$html = ( new Cards() )->editorial( self::PRODUCT, 'Mashrabiya pattern' );
		$file = (string) file_get_contents( Partials::path( Cards::EDITORIAL ) );

		$stripped = (string) preg_replace( '/\[md_[^\]]*\]/', '', $file );
		$needle   = trim( (string) preg_replace( '/\s+/', ' ', $stripped ) );
		$haystack = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/>[^<]*</', '><', $html ) ) );

		$this->assertNotSame( '', $needle );
		$this->assertStringContainsString(
			'class="hpl__card',
			$haystack,
			'the rendered card is the partial with values, never markup of its own'
		);
	}

	/**
	 * A throwaway directory under the system temp dir, named for this test.
	 *
	 * The `srcset` fallback reads real files (it globs the attachment's
	 * directory and checks each candidate's path), so the cases that exercise
	 * it write real files rather than faking the filesystem.
	 *
	 * @param string $suffix A name to keep concurrent cases apart.
	 */
	private function temporary_dir( string $suffix ): string {
		$dir = sys_get_temp_dir() . '/md-cards-' . getmypid() . '-' . $suffix;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		return $dir;
	}

	/**
	 * The product the fixtures draw.
	 *
	 * @param int[]                                                                                                $gallery    The gallery attachment ids.
	 * @param bool                                                                                                 $with_thumb Whether the product has a featured image.
	 * @param string                                                                                               $open_area  The open-area field.
	 * @param non-empty-string                                                                                     $post_type  The post type the id resolves to.
	 * @param array{0: int, 1: int}                                                                                $dimensions The attachment's width and height.
	 * @param \stdClass|null                                                                                       $counter    Counts the gallery-meta reads when given.
	 * @param array{file?: string, basedir?: string, baseurl?: string, url?: string, native_srcset?: string|false} $media Overrides for the srcset inputs.
	 * @param int[]                                                                                                $related_ids Product ids that resolve as their own post (the related-cards case).
	 */
	private function mock_product( array $gallery = array(), bool $with_thumb = true, string $open_area = '58', string $post_type = 'product', array $dimensions = array( 400, 800 ), ?\stdClass $counter = null, array $media = array(), array $related_ids = array() ): void {
		$related          = array_fill_keys( array_map( 'intval', $related_ids ), true );
		$post             = new \WP_Post( (object) array() );
		$post->ID         = max( 0, self::PRODUCT );
		$post->post_type  = $post_type;
		$post->post_title = 'Mandala | Architectural Metal Screen Pattern';

		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			static function ( $id = 0 ) use ( $post, $related ) {
				$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
				if ( isset( $related[ $id ] ) ) {
					$other             = new \WP_Post( (object) array() );
					$other->ID         = max( 0, $id );
					$other->post_type  = 'product';
					$other->post_title = 'Zelda | Architectural Metal Screen Pattern';
					return $other;
				}
				return $post;
			}
		);
		WP_Mock::userFunction( 'get_the_ID' )->andReturn( self::PRODUCT );
		// The related-cards case lists *other* products, so an id in that set
		// resolves to its own title, permalink and thumbnail; every other id is
		// the product under test.

		$naming = static function ( $id ) use ( $post, $related ): string {
			$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
			return isset( $related[ $id ] ) ? 'Zelda | Architectural Metal Screen Pattern' : $post->post_title;
		};
		WP_Mock::userFunction( 'get_the_title' )->andReturnUsing( $naming );
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static function ( $id ) use ( $related ): string {
				$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
				return isset( $related[ $id ] ) ? 'https://site.test/pattern/' . $id . '/' : 'https://site.test/pattern/mandala/';
			}
		);
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturnUsing(
			static function ( $id, string $size ) use ( $related ): string {
				$id = $id instanceof \WP_Post ? (int) $id->ID : (int) $id;
				return isset( $related[ $id ] )
					? 'https://site.test/uploads/' . $id . '-' . $size . '.webp'
					: 'https://site.test/uploads/mandala-' . $size . '.webp';
			}
		);
		// The fixture models the real function: `WP_Query::setup_postdata()`
		// writes these nine globals besides `$GLOBALS['post']`, and a fixture
		// that only returned true would let a render that restores nothing
		// pass every test about restoring.
		WP_Mock::userFunction( 'setup_postdata' )->andReturnUsing(
			static function ( $post ) {
				$product_id              = $post instanceof \WP_Post ? (int) $post->ID : self::PRODUCT;
				$GLOBALS['id']           = $product_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['authordata']   = 'author-of-product-' . $product_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['currentday']   = '26'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['currentmonth'] = '10'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['page']         = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['pages']        = array( 'the product body' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['multipage']    = ''; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['more']         = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				$GLOBALS['numpages']     = 3; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- modelling WordPress's own write.
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_reset_postdata' )->andReturn( null );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );

		WP_Mock::userFunction( 'get_post_thumbnail_id' )->andReturn( $with_thumb ? self::THUMB : 0 );
		WP_Mock::userFunction( 'wp_get_attachment_image_src' )->andReturnUsing(
			static function ( int $id, string $size ) use ( $dimensions, $counter ) {
				unset( $size );
				if ( null !== $counter ) {
					++$counter->size_reads;
				}
				return $id > 0
					? array( 'https://site.test/uploads/' . $id . '-large.webp', $dimensions[0], $dimensions[1], false )
					: false;
			}
		);
		// A `native_srcset` override pins one value for every attachment; the
		// default answers per attachment, which is what a real site looks like
		// once the uploader has registered sub-sizes — and it is the branch
		// that matters here, because a ladder is what `srcset` needs to exist
		// at all.
		WP_Mock::userFunction( 'wp_get_attachment_image_srcset' )->andReturnUsing(
			static function ( int $id, string $size ) use ( $media ) {
				unset( $size );
				if ( array_key_exists( 'native_srcset', $media ) ) {
					return $media['native_srcset'];
				}
				// Two real widths per attachment, the shape the media pipeline
				// produces: a small and a large sibling of the same image.
				return 'https://site.test/uploads/' . $id . '-400w.webp 400w, '
					. 'https://site.test/uploads/' . $id . '-800w.webp 800w';
			}
		);
		WP_Mock::userFunction( 'get_attached_file' )->andReturn( $media['file'] ?? '' );
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn(
			array(
				'baseurl' => $media['baseurl'] ?? 'https://site.test/uploads',
				'basedir' => $media['basedir'] ?? sys_get_temp_dir() . '/md-cards-none',
			)
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->andReturnUsing(
			static function ( int $id ) use ( $media ) {
				return $media['url'] ?? 'https://site.test/uploads/' . $id . '-400w.webp';
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ) use ( $gallery, $open_area, $counter ) {
				unset( $id );
				if ( '_product_image_gallery' === $key ) {
					if ( null !== $counter ) {
						++$counter->gallery_reads;
					}
					return implode( ',', $gallery );
				}
				if ( 'open_area' === $key ) {
					return $open_area;
				}
				if ( 'best_fit' === $key ) {
					return 'architectural-screening';
				}
				return '';
			}
		);
		WP_Mock::userFunction( 'get_the_terms' )->andReturnUsing(
			static function () {
				$term              = new \WP_Term( (object) array() );
				$term->name        = 'Moroccan & Arabic';
				$term->slug        = 'pattern-moroccan-arabic';
				$term->description = '';
				$term->taxonomy    = 'product_cat';
				return array( $term );
			}
		);
	}
}
