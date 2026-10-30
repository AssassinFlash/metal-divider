<?php
/**
 * The home face's restored old-site sections stay restored.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The owner's ruling of 2026-10-30 (late 2) is that the lower half of the
 * home page had flattened the old site's designed sections into undifferentiated
 * stacks of text, and that they were to be restored.
 *
 * Two things went wrong on the new site and are what these guards hold:
 *
 * 1. Five whole sections existed here that the old site never had — three
 *    capability bands ("Bridging vision & reality.", "Proof Before
 *    Production", "Global Supply Chain") and a bare "146 Ready Patterns"
 *    grid — and they pushed the old site's four designed sections down the
 *    page, splitting them so the sequence a visitor met no longer matched
 *    the one that was designed.
 * 2. The two sections that carry an interaction carried only half of it in
 *    the presentation layer: the proof carousel's cards were flat 82vw
 *    slides rather than the two-column card the owner asked for, and the
 *    pattern-direction tabs were filled gold pills rather than the old
 *    site's underlined words.
 *
 * The interaction engines themselves were already right (`bindCarousel`,
 * `bindTabSet` in md-chrome.js), so what these tests pin is the markup and
 * the rules it depends on. A unit test cannot press a key; the keyboard
 * behaviour is asserted where it lives — the engine's own contract, held by
 * PatternTabsTest — and the real-browser evidence is in the handoff.
 *
 * @coversNothing
 */
final class HomeRestoredSectionsTest extends TestCase {

	/**
	 * The home face's source.
	 */
	private function home(): string {
		return (string) file_get_contents( Templates::path( 'home' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * One volume's source.
	 *
	 * @param string $file Volume file stem.
	 */
	private function volume( string $file ): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress/' . $file . '.css';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The five sections the old site never had are gone.
	 *
	 * Each of these was written on the new site to argue a capability the old
	 * page argued inside sections that already existed. They are the flattening
	 * itself: a heading, a paragraph, and a grid of equal cards, five times,
	 * between the designed sections. Naming them by their own visible text
	 * rather than by an id means a rewrite that brings the same block back
	 * under a new hook still fails here.
	 */
	public function test_the_flattened_bands_the_old_site_never_had_are_gone(): void {
		$home = $this->home();
		$gone = array(
			'Bridging vision &amp; reality.',
			'Proof Before Production',
			'Global Supply Chain',
			'146 Ready Patterns',
			'Factory evidence',
			'Engineering meets artistry',
		);
		foreach ( $gone as $phrase ) {
			$this->assertStringNotContainsString( $phrase, $home, "\"{$phrase}\" is one of the flattened bands the restoration removed" );
		}
	}

	/**
	 * The home page's sections are in the old site's order.
	 *
	 * The old page ran: hero, specification map, decorative grid, the proof
	 * carousel, the pattern-direction tabs, the process strip, the featured
	 * projects, the five-step process, FAQ, contact. The new site's extra
	 * bands had pushed the proof carousel past the specification table, which
	 * is the change a reader feels as "the page is a list now".
	 *
	 * Only the order of the section markers is asserted, so a section can be
	 * rewritten inside without touching this list; adding or moving a section
	 * has to be a deliberate edit here.
	 */
	public function test_the_sections_run_in_the_old_sites_order(): void {
		$home = $this->home();

		$order = array(
			'<!-- md-section: hero_banner_custom_Yy8fzp -->',
			'<!-- md-section: home_specification_map -->',
			'<!-- md-section: decorative_panels_grid_gmaCty -->',
			'<!-- md-section: why_choose_us_DeyqPH -->',
			'<!-- md-section: home_pattern_library -->',
			'<!-- md-section: manufacture_excellence_fEVjGG -->',
			'<!-- md-section: serving_projects_877GUd -->',
			'<!-- md-section: feature_case_study_NBVwgD -->',
			'<!-- md-section: technical-specifications -->',
			'<!-- md-section: faq_v1_wQVCUV -->',
			'<!-- md-section: contact_v5_yCw9Wf -->',
		);

		$positions = array();
		foreach ( $order as $marker ) {
			$at = strpos( $home, $marker );
			$this->assertNotFalse( $at, "the home face carries {$marker}" );
			$positions[ $marker ] = (int) $at;
		}

		$sorted = $positions;
		asort( $sorted );
		$out_of_place = array();
		foreach ( array_keys( $positions ) as $index => $marker ) {
			if ( array_keys( $sorted )[ $index ] !== $marker ) {
				$out_of_place[] = $marker;
			}
		}
		$this->assertSame(
			array(),
			$out_of_place,
			'the sections run in the old site\'s order; out of place: ' . implode( ', ', $out_of_place )
		);
	}

	/**
	 * The proof carousel is a four-card track with all four of its controls.
	 *
	 * The owner's spec: one card per screen, arrows on both sides, four gold
	 * progress bars and a "1 / 4" counter. The counter's total is the card
	 * count, so a fifth card added without the counter would be a carousel
	 * that lies about its length.
	 */
	public function test_the_proof_carousel_keeps_its_four_cards_and_controls(): void {
		$home = $this->home();

		$this->assertSame( 4, substr_count( $home, 'class="home-outcomes-card snap-start"' ), 'four proof cards' );
		$this->assertSame( 4, preg_match_all( '/data-home-outcomes-dot\s+data-index/', $home ), 'one progress bar per card' );
		$this->assertStringContainsString( 'data-home-outcomes-track', $home, 'the track the carousel scrolls' );
		$this->assertStringContainsString( 'data-home-outcomes-prev', $home, 'the previous arrow' );
		$this->assertStringContainsString( 'data-home-outcomes-next', $home, 'the next arrow' );
		$this->assertStringContainsString( 'data-home-outcomes-current', $home, 'the counter the runtime rewrites' );

		// The counter's denominator, spelled in the markup the runtime leaves
		// alone (it only writes the numerator).
		$this->assertMatchesRegularExpression( '/<span data-home-outcomes-current>1<\/span>\s*\/\s*4/', $home, 'the counter reads 1 / 4' );
	}

	/**
	 * The four progress bars are painted gold when active, through the
	 * selected-state attribute the runtime sets.
	 *
	 * The runtime sets `data-active="true"` on the current dot and
	 * `aria-current` with it; a rule keyed on anything else would leave four
	 * grey bars that never move.
	 */
	public function test_the_progress_bars_are_keyed_to_the_state_the_runtime_sets(): void {
		$css = $this->volume( 'home' );
		$js  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( "dot.setAttribute('data-active', active ? 'true' : 'false')", $js, 'the runtime writes data-active' );
		$this->assertStringContainsString( 'data-[active=true]:bg-[#c9a962]', $this->home(), 'the bars are gold when active' );
		$this->assertMatchesRegularExpression(
			'/data-home-outcomes-current>1<\/span>/',
			$this->home(),
			'the counter starts at one'
		);
		$this->assertNotSame( '', trim( $css ), 'the home volume exists' );
	}

	/**
	 * The proof cards are one two-column card per screen, not flat slides.
	 *
	 * The written spec asks for the photograph at ~691px on the left and the
	 * title, quote and description on the right. The card is therefore a
	 * grid whose two columns are declared in the home volume, and the photo
	 * declares the width it actually renders, so a phone is not handed a
	 * 1400px file for a 310px box.
	 */
	public function test_the_proof_card_is_the_two_column_card_the_spec_asks_for(): void {
		$home = $this->home();
		$css  = $this->volume( 'home' );

		$this->assertMatchesRegularExpression(
			'/\.home-outcomes-card\{[^}]*flex:\s*0 0 100%/',
			$css,
			'one card per screen'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(min-width: 1180px\)\{\s*\.home-outcomes-card\{\s*grid-template-columns:\s*minmax\(0, 691px\) minmax\(0, 1fr\)/',
			$css,
			'the two-column card: a 691px photo column and the text beside it'
		);

		// The photo's declared width is the column's, and it is inside the
		// card's media box rather than a bare slide.
		$this->assertStringContainsString( 'home-outcomes-media', $home, 'the card names its media box' );
		$this->assertStringContainsString( 'home-outcomes-body', $home, 'and its text column' );
		$this->assertSame(
			4,
			substr_count( $home, 'sizes="(min-width: 1180px) 691px, calc(100vw - 40px)"' ),
			'every card photo declares the column it renders in'
		);
	}

	/**
	 * The quotes are the gold serif the restored design calls for.
	 *
	 * The capture sets them in the body face at font-medium; the owner's ask
	 * is the pull-quote treatment the rest of the page already uses. The rule
	 * is one class, so it cannot drift into four slightly different stacks.
	 */
	public function test_the_proof_quotes_are_the_gold_serif(): void {
		$home = $this->home();
		$css  = $this->volume( 'home' );

		$this->assertSame( 4, substr_count( $home, 'class="home-outcomes-quote' ), 'all four quotes carry the class' );
		$this->assertMatchesRegularExpression(
			'/\.home-outcomes-quote\{[^}]*font-family:\s*"Playfair Display"/',
			$css,
			'the quote is the serif'
		);
		$this->assertMatchesRegularExpression(
			'/\.home-outcomes-quote\{[^}]*color:\s*#d9bc82/',
			$css,
			'and it is gold'
		);
		$this->assertStringNotContainsString( 'font-medium leading-snug text-white md:text-xl', $home, 'the old quote stack is gone, not sitting beside the new class' );
	}

	/**
	 * The pattern-direction strip is the old site's underlined words.
	 *
	 * The old page's own style block (capture line 4941) set 18/22/26px words
	 * with no box, the selected one white with a 2px gold rule that grows from
	 * the left. The shipped skin was filled gold pills at 14px, which reads as
	 * a filter bar. Both halves are asserted: the selected state has to be the
	 * underline, and the pill's own declaration has to be gone.
	 */
	public function test_the_pattern_direction_tabs_are_underlined_words(): void {
		$css = $this->volume( 'home' );

		// Selected: the gold rule, from the left.
		$this->assertMatchesRegularExpression(
			'/\.hpl__tab::after\{[^}]*background:\s*#c9a962/',
			$css,
			'the selected tab paints a gold rule'
		);
		$this->assertMatchesRegularExpression(
			'/\.hpl__tab::after\{[^}]*transform:\s*scaleX\(0\)/',
			$css,
			'and it is scaled away when the tab is not selected'
		);
		$this->assertMatchesRegularExpression(
			'/\.hpl__tab\.is-active::after\{[^}]*transform:\s*scaleX\(1\)/',
			$css,
			'the selected tab scales it in'
		);

		// The old site's three type sizes, at its own breakpoints.
		foreach ( array( 'font-size: 18px', 'font-size: 22px', 'font-size: 26px' ) as $size ) {
			$this->assertStringContainsString( $size, $css, "the strip carries {$size} as the capture does" );
		}

		// The pill is gone: no filled background and no full border on the
		// selected tab. A rule that keeps the fill would render as both a pill
		// and an underline.
		$this->assertMatchesRegularExpression(
			'/\.hpl__tab\{[^}]*border:\s*0/',
			$css,
			'the tab has no box'
		);
		$this->assertMatchesRegularExpression(
			'/\.hpl__tab\{[^}]*background:\s*transparent/',
			$css,
			'and no fill'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.hpl__tab\.is-active\{[^}]*background:\s*#c9a962/',
			$css,
			'the selected tab is no longer a gold pill'
		);
	}

	/**
	 * Both interactions are still bound to the one engine each.
	 *
	 * The restored markup is inert without its runtime, and the runtime is
	 * already the shared implementation: one carousel binder, one tabs binder.
	 * A second implementation is what these two assertions would catch.
	 */
	public function test_both_restored_sections_are_bound_by_the_shared_engines(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertSame( 1, substr_count( $js, 'function bindCarousel(' ), 'one carousel engine' );
		$this->assertSame( 1, substr_count( $js, 'function bindTabSet(' ), 'one tabs engine' );
		$this->assertStringContainsString( "querySelector('[data-home-outcomes-track]')", $js, 'the proof carousel is bound' );
		$this->assertStringContainsString( "querySelectorAll('xo-tabs')", $js, 'the pattern-direction strip is bound' );
		$this->assertStringContainsString( 'setupOutcomesCarousel();', $js, 'and the carousel pass runs from start()' );
		$this->assertStringContainsString( 'setupTabs();', $js, 'and the tabs pass too' );
	}
}
