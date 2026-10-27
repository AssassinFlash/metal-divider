<?php
/**
 * The custom-screens landing face and the two previously unstyled sections.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The landing face was converted from a theme that carried its entire
 * interaction layer as inline `onclick` attributes. The CSP baseline blocks
 * inline handlers, and the functions those attributes named were never
 * migrated — so all twenty-three of them were dead: the pattern chips, the
 * feature gallery arrows, three application lightboxes, the trust tabs, the
 * mobile menu, the mobile thumbnails, Get Quote and the success dialog.
 *
 * Two other sections shipped markup and state classes and no stylesheet at
 * all: the manufacturing quality deck (dots computed to 0x0) and the
 * perforated comparison (the split handle computed to 3px and the mask
 * revealed nothing).
 *
 * @coversNothing
 */
final class LandingControlsTest extends TestCase {

	/**
	 * The chrome runtime's source.
	 */
	private function runtime(): string {
		$path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * A template's source with its HTML comments removed.
	 *
	 * @param string $file The template file stem.
	 */
	private function markup( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$source = (string) file_get_contents( Templates::path( $file ) );
		return (string) preg_replace( '/<!--.*?-->/s', '', $source );
	}

	/**
	 * A WindPress volume's source.
	 *
	 * @param string $volume The volume file name.
	 */
	private function volume( string $volume ): string {
		$class  = (string) ( new \ReflectionClass( Templates::class ) )->getFileName();
		$source = (string) file_get_contents( dirname( $class ) . '/../../content/windpress/' . $volume ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		return $source;
	}

	/**
	 * The body of one setup pass.
	 *
	 * @param string $name The function name.
	 */
	private function pass( string $name ): string {
		$source = $this->runtime();
		$start  = strpos( $source, 'function ' . $name . '(' );
		$this->assertNotFalse( $start, 'the runtime defines ' . $name );
		$end = strpos( $source, "\n  function ", (int) $start + 10 );
		$this->assertNotFalse( $end, $name . ' terminates' );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * No inline handler survives on the landing face.
	 *
	 * This is the defect itself. Inline handlers are blocked by the CSP
	 * baseline, so every one of them is a control that cannot work — and
	 * because they look wired in the markup, they hide the fact that nothing
	 * is bound.
	 */
	public function test_the_landing_face_carries_no_inline_handlers(): void {
		$html = $this->markup( 'custom-screens' );

		$this->assertSame( 0, preg_match_all( '/\son(click|change|input|submit|keydown|load)\s*=/i', $html ), 'no inline event attribute remains' );
		// The functions the attributes named are gone with them.
		foreach ( array( 'openLandingQuoteModal', 'toggleMobileMenu', 'openLightbox', 'closeLightbox', 'switchTrustTab', 'updateMobileGallery', 'closeSectionModal' ) as $dead ) {
			$this->assertStringNotContainsString( $dead, $html, $dead . ' is not referenced any more' );
		}
	}

	/**
	 * No element carries two class attributes.
	 *
	 * A second `class` attribute is invalid HTML and the browser ignores it, so
	 * the conversion's leftover `class="md-t…"` silently dropped whatever it
	 * named. It also hid why the comparison handle was 3px: the utilities in
	 * the first `class` won over the volume.
	 */
	public function test_no_element_carries_two_class_attributes(): void {
		// Every template and partial, not just the ones this change touched.
		//
		// A second `class` attribute is invalid HTML and the browser ignores it,
		// so a leftover `class="md-t…"` silently dropped whatever it named. It
		// is also how the comparison handle came to be 3px wide: the utilities
		// in the first `class` won over the volume, because the volume's rules
		// lost the layer fight regardless of selector weight.
		//
		// `:class` and `x-bind:class` are Alpine bindings — different attributes
		// with different values — so the lookbehind excludes them. Merging one
		// of those into `class` was a real mistake made once in this repo; the
		// check has to be precise enough to allow `class` + `:class` on one tag.
		$reflection = new \ReflectionClass( \MetalDivider\Site\Presentation\Templates::class );
		$directory  = dirname( (string) $reflection->getFileName() ) . '/../../content/livecanvas';

		$top      = glob( $directory . '/*.html' );
		$partials = glob( $directory . '/partials/*.html' );
		$files    = array_merge( false === $top ? array() : $top, false === $partials ? array() : $partials );
		$this->assertNotEmpty( $files, 'the template inventory was found' );

		foreach ( $files as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$html = (string) file_get_contents( $path );
			$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );

			$this->assertSame(
				0,
				preg_match_all( '/<[a-z][^>]*(?<![\w:-])class="[^"]*"[^>]*(?<![\w:-])class="[^"]*"/i', $html ),
				basename( $path ) . ': no element repeats its class attribute'
			);
		}
	}

	/**
	 * Every duplicated attribute merge kept both class lists.
	 *
	 * The four component classes that were the second `class` on these
	 * elements are real rules, and the browser was dropping all four: the FAQ
	 * icon's transition, two radial-glow layers and the carousel's hidden
	 * scrollbar. They have to survive the merge — a clean-up that deletes them
	 * would remove the very styling the duplicate was hiding.
	 */
	public function test_the_merged_class_lists_keep_their_component_classes(): void {
		$expectations = array(
			'home'                    => array( 'md-ta5ddcc35', 'md-tf4f8deb9', 'md-t312247e4', 'md-ta2e71e3d' ),
			'faqs'                    => array( 'md-t2a320089' ),
			'family-expanded-metal'   => array( 'md-tf4f8deb9' ),
			'family-laser-cut-panels' => array( 'md-te05c0d99' ),
		);
		foreach ( $expectations as $file => $classes ) {
			$html = $this->markup( $file );
			foreach ( $classes as $class ) {
				$this->assertStringContainsString( $class, $html, $file . ' keeps ' . $class );
			}
		}

		// And the utilities they were merged with are still there too.
		$home = $this->markup( 'home' );
		$this->assertMatchesRegularExpression( '/class="[^"]*opacity-\[0\.04\][^"]*md-ta5ddcc35"/', $home, 'the glow keeps its utility classes' );
		$this->assertMatchesRegularExpression( '/class="[^"]*snap-x[^"]*md-t312247e4"/', $home, 'the carousel keeps its scroll utilities' );
	}

	/**
	 * The QC chart arcs are not clipped by an animation nothing runs.
	 *
	 * These three `chart-arc` elements carried a second class whose only job was
	 * `--len` / `--off`, consumed by a rule that starts each arc at
	 * `stroke-dashoffset: var(--len)` — fully hidden — and only reveals it under
	 * `.qc-card.is-revealed`. No code in this repo ever adds that class.
	 *
	 * While the duplicate attribute was ignored the variables were empty, so
	 * `stroke-dasharray` fell back to the element's own presentation attribute
	 * and the arcs drew. Merging the class in would have made them invisible,
	 * which is the opposite of what a class clean-up is for. They are removed
	 * instead, and this test holds that: a later "restore the missing class"
	 * would be a regression, not a fix.
	 */
	public function test_the_qc_chart_arcs_are_not_hidden_by_an_unrun_animation(): void {
		$html = $this->markup( 'family-laser-cut-panels' );

		foreach ( array( 'md-td4af0f6b', 'md-t58129e41', 'md-t28c04a94' ) as $class ) {
			$this->assertStringNotContainsString( $class, $html, $class . ' is not reattached to a chart arc' );
		}
		$this->assertGreaterThanOrEqual( 3, substr_count( $html, 'class="chart-arc"' ), 'the arcs keep their own class' );
	}

	/**
	 * Merging never folded an Alpine binding into `class`.
	 *
	 * `class` and `:class` are separate attributes that legitimately share a
	 * tag. An earlier clean-up attempt merged them and pushed a JS ternary into
	 * a `class` attribute, which broke both the styling and the binding.
	 */
	public function test_directive_bindings_were_not_folded_into_class(): void {
		foreach ( array( 'home', 'faqs', 'family-expanded-metal', 'family-laser-cut-panels' ) as $file ) {
			$html = $this->markup( $file );

			// A JS expression inside a *plain* class attribute is the symptom.
			// The lookbehind is what keeps `:class="activeQ === 0 ? …"` — a
			// legitimate binding — out of the match.
			$this->assertDoesNotMatchRegularExpression(
				'/(?<![\w:-])class="[^"]*===/',
				$html,
				$file . ': no JS expression leaked into a class attribute'
			);
		}

		// The bindings themselves are still counted where they were.
		$this->assertSame( 19, substr_count( $this->markup( 'home' ), ':class=' ), 'home keeps its Alpine class bindings' );
		$this->assertSame( 56, substr_count( $this->markup( 'faqs' ), ':class=' ), 'faqs keeps its Alpine class bindings' );
	}

	/**
	 * Every landing pass runs, and each is bound to a data hook.
	 *
	 * A pass that exists and is never called is what produced the dead controls
	 * in the first place.
	 */
	public function test_every_landing_pass_is_wired_and_finds_its_markup(): void {
		$source = $this->runtime();
		$start  = strpos( $source, 'function start()' );
		$this->assertNotFalse( $start );
		$body = substr( $source, (int) $start );
		$html = $this->markup( 'custom-screens' );

		$passes = array(
			'setupHeroChips'             => '.hero-chip[data-pattern-image]',
			'setupFeatureGallery'        => 'feature-panel',
			'setupApplicationLightboxes' => '[data-app-lightbox]',
			'setupTrustTabs'             => '.trust-tabs-section',
			'setupMobileGallery'         => '[data-mobile-thumb]',
			'setupSectionModalClose'     => '[data-section-modal-close]',
			'setupLandingNav'            => 'landing-nav',
		);
		foreach ( $passes as $pass => $selector ) {
			$this->assertStringContainsString( $pass . '();', $body, $pass . ' is called from start()' );
			$this->assertStringContainsString( 'function ' . $pass . '(', $source, $pass . ' is defined' );
		}

		foreach ( array( 'data-app-lightbox', 'data-mobile-thumb', 'data-section-modal-close', 'data-mobile-menu-close', 'id="landing-nav"', 'id="mobile-toggle"' ) as $hook ) {
			$this->assertStringContainsString( $hook, $html, 'the markup carries ' . $hook );
		}
	}

	/**
	 * Get Quote goes through the site's existing quote channel.
	 *
	 * The landing face used to call a page-local function that no longer
	 * exists. `[data-quote-source]` is the site-wide hook setupModals already
	 * dispatches `open-quote` for, and this face carries its own
	 * `quoteModal()` component, so the button needs no code of its own.
	 */
	public function test_get_quote_uses_the_shared_quote_channel(): void {
		$html = $this->markup( 'custom-screens' );

		$this->assertStringContainsString( 'data-quote-source="header-landing"', $html, 'the desktop button names its source' );
		$this->assertStringContainsString( 'data-quote-source="header-landing-mobile"', $html, 'and the mobile one' );
		$this->assertStringContainsString( 'x-data="quoteModal()"', $html, 'the face carries the shared quote component' );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html, 'the buttons announce that they open a dialog' );
	}

	/**
	 * The three application case studies are real dialogs.
	 *
	 * Each was a `div` with an inline handler and no role. The dialogs now ship
	 * `role="dialog"`, `aria-modal` and `inert`, and their close control is a
	 * `<button>` rather than a `div` with `&times;`.
	 */
	public function test_the_application_lightboxes_are_dialogs_with_real_close_buttons(): void {
		$html = $this->markup( 'custom-screens' );

		$this->assertSame( 3, preg_match_all( '/class="content-collage[^"]*" data-app-lightbox="lightbox-application_[^"]+"/', $html ), 'three triggers, each naming its dialog' );
		$this->assertSame( 3, preg_match_all( '/id="lightbox-application_[^"]+" class="app-lightbox" role="dialog" aria-modal="true"/', $html ), 'three dialogs with a role' );
		$this->assertSame( 3, preg_match_all( '/<button type="button" class="app-lightbox-close" data-app-lightbox-close/', $html ), 'three real close buttons' );

		$body = $this->pass( 'setupApplicationLightboxes' );
		foreach ( array( 'pushSurface(dialog)', 'popSurface(dialog)', 'restoreFocus(opener)', 'dialog.__mdClose = close' ) as $call ) {
			$this->assertStringContainsString( $call, $body, 'the lightbox is a surface on the shared stack: ' . $call );
		}
		$this->assertStringContainsString( "setAttribute('inert', '')", $body, 'a closed lightbox is inert' );
	}

	/**
	 * The hero chips swap the preview from the chip's own data.
	 */
	public function test_hero_chips_drive_the_preview(): void {
		$html = $this->markup( 'custom-screens' );
		$body = $this->pass( 'setupHeroChips' );

		$this->assertSame( 4, preg_match_all( '/class="hero-chip[^"]*"[^>]*data-pattern-image=/s', $html ), 'four pattern chips' );
		$this->assertStringContainsString( 'aria-pressed', $html, 'the chips state their selection' );
		$this->assertStringContainsString( "other.setAttribute('aria-pressed'", $body, 'and the runtime drives it' );
		$this->assertStringContainsString( 'removeAttribute(\'srcset\')', $body, 'the stale source set is dropped when the image changes' );
		$this->assertStringContainsString( 'data-pattern-alt', $body, 'the alt text follows the chip' );
		$this->assertStringContainsString( 'data-hero-pattern-label', $body, 'and so does the caption' );
	}

	/**
	 * The feature gallery is a stepper over `.active`, and wraps.
	 */
	public function test_the_feature_gallery_steps_and_wraps(): void {
		$html = $this->markup( 'custom-screens' );
		$body = $this->pass( 'setupFeatureGallery' );

		$this->assertGreaterThanOrEqual( 3, substr_count( $html, 'class="feature-panel' ), 'three feature panels' );
		$this->assertStringContainsString( 'gallery-nav--prev', $html, 'a previous control exists' );
		$this->assertStringContainsString( 'gallery-nav--next', $html, 'and a next one' );
		$this->assertStringContainsString( "classList.toggle('active'", $body, 'the active panel is switched' );
		$this->assertMatchesRegularExpression( '/\(\(next_index % panels\.length\) \+ panels\.length\) % panels\.length/', $body, 'stepping wraps at both ends' );
		$this->assertStringContainsString( 'aria-hidden', $body, 'inactive panels are hidden from screen readers' );
	}

	/**
	 * The trust tabs use the one tab engine.
	 */
	public function test_the_trust_tabs_use_the_shared_tab_engine(): void {
		$html = $this->markup( 'custom-screens' );
		$body = $this->pass( 'setupTrustTabs' );

		$this->assertSame( 4, preg_match_all( '/class="tab-btn[^"]*"\s*role="tab"/', $html ), 'four role="tab" controls' );
		$this->assertSame( 4, preg_match_all( '/class="tab-content-item/', $html ), 'four panels' );
		$this->assertStringContainsString( 'bindTabSet(', $body, 'the strip is bound by the shared engine' );
		// The engine owns selection: the pass only *reads* aria-selected (to pick
		// the initial tab and to place the underline) and never writes it. A
		// `setAttribute` here would be a second implementation of the state.
		$this->assertStringNotContainsString( "setAttribute('aria-selected'", $body, 'selection state is written only by the shared engine' );
		$this->assertStringContainsString( "getAttribute('aria-selected') === 'true'", $body, 'the pass reads the engine\u2019s state to place the underline' );
		$this->assertStringContainsString( 'moveIndicator', $body, 'the volume\u2019s underline is positioned' );
	}

	/**
	 * The mobile menu states its state and takes a closed drawer out of reach.
	 */
	public function test_the_mobile_menu_states_and_hides_its_drawer(): void {
		$html = $this->markup( 'custom-screens' );
		$body = $this->pass( 'setupLandingNav' );

		$this->assertStringContainsString( 'id="mobile-toggle"', $html, 'the toggle exists' );
		$this->assertStringContainsString( 'aria-expanded="false"', $html, 'and ships collapsed' );
		$this->assertStringContainsString( 'aria-controls="mobile-menu-drawer"', $html, 'and names what it controls' );

		$this->assertStringContainsString( "setAttribute('aria-expanded'", $body, 'the toggle states the drawer' );
		$this->assertStringContainsString( "drawer.setAttribute('inert', '')", $body, 'a closed drawer is inert' );
		$this->assertStringContainsString( "removeAttribute('inert')", $body, 'an open one is not' );
		$this->assertStringContainsString( "event.key === 'Escape'", $body, 'Escape closes it' );
	}

	/**
	 * The landing nav clears the site's sticky header.
	 *
	 * Both boxes are fixed to the top of the viewport: the site header at
	 * z-index 100 and this nav at 40. Without an offset they occupy the same
	 * pixels and the header wins, so the face's own navigation disappeared
	 * under it.
	 */
	public function test_the_landing_nav_clears_the_sticky_header(): void {
		$body = $this->pass( 'setupLandingNav' );
		$css  = $this->volume( 'custom-screens.css' );

		$this->assertStringContainsString( '--landing-nav-offset', $body, 'the runtime measures the band' );
		$this->assertStringContainsString( 'header#lc-header', $body, 'from the site header itself' );
		$this->assertStringContainsString( 'var(--landing-nav-offset', $css, 'and the stylesheet consumes it' );
		// Both the floating and the locked states have to clear the band.
		$this->assertSame( 2, substr_count( $css, 'var(--landing-nav-offset' ), 'the floating and scrolled states both use the offset' );
	}

	/**
	 * The mobile thumbnails swap the main image.
	 */
	public function test_the_mobile_gallery_switches_the_main_image(): void {
		$html = $this->markup( 'custom-screens' );
		$body = $this->pass( 'setupMobileGallery' );

		$this->assertSame( 4, substr_count( $html, 'data-mobile-thumb' ), 'four thumbnails' );
		$this->assertStringContainsString( 'id="mobile-main-label"', $html, 'the caption element exists' );
		$this->assertStringContainsString( 'removeAttribute(\'srcset\')', $body, 'the stale source set is dropped' );
		$this->assertStringContainsString( 'data-label', $body, 'the caption follows the thumbnail' );
		$this->assertStringContainsString( "setAttribute('aria-pressed'", $body, 'the chosen thumbnail is marked' );
	}

	/**
	 * The quality deck now has a stylesheet, and its controls are usable.
	 *
	 * Measured before: every `mfg-quality` rule was missing, the dots computed
	 * to 0x0 and the four cards stacked at full width (2224px of page). The
	 * stage also has to keep the controls out from under the card stack.
	 */
	public function test_the_quality_deck_is_styled_with_usable_controls(): void {
		$css = $this->volume( 'manufacturing.css' );

		foreach ( array( '.mfg-quality {', '.mfg-quality__stage', '.mfg-quality__doc', '.mfg-quality__dot', '.mfg-quality__arrow' ) as $rule ) {
			$this->assertStringContainsString( $rule, $css, 'the deck styles ' . $rule );
		}
		// The four deck positions each get their own placement, or the cards
		// pile in normal flow again.
		foreach ( array( 'is-active', 'is-next', 'is-prev', 'is-hidden' ) as $state ) {
			$this->assertStringContainsString( '.mfg-quality__doc.' . $state, $css, $state . ' has a placement' );
		}
		// A dot button must be bigger than the dot it draws; the visual dot is
		// a pseudo-element for that reason.
		$this->assertMatchesRegularExpression( '/\.mfg-quality__dot \{[^}]*width: 24px/', $css, 'the dots keep a 24px target' );
		$this->assertStringContainsString( '.mfg-quality__dot::after', $css, 'and draw a smaller visual inside it' );
		// The stack must not cover the caption and controls, which are its
		// siblings inside the stage.
		$this->assertMatchesRegularExpression( '/\.mfg-quality__stage \{[^}]*display: flex/', $css, 'the stage lays out as a column' );
		$this->assertStringNotContainsString( ".mfg-quality__stack {\nposition: absolute;\ninset: 0;", $css, 'the stack is not an overlay over the controls' );
	}

	/**
	 * The comparison split is visible and grabbable.
	 *
	 * Measured before: the handle computed to 3px wide at an unset position and
	 * the mask revealed nothing, so the drag moved a divider nobody could see.
	 */
	public function test_the_comparison_split_is_visible_and_grabbable(): void {
		$css  = $this->volume( 'family-perforated-panels.css' );
		$html = $this->markup( 'family-perforated-panels' );

		$this->assertMatchesRegularExpression( '/\[data-pa-slider\] \{[^}]*width: 44px/s', $css, 'the handle has a grabbable width' );
		$this->assertStringContainsString( 'touch-action: none', $css, 'the drag does not scroll the page' );
		$this->assertStringContainsString( '[data-pa-slider]::before', $css, 'the divider line is drawn' );
		$this->assertStringContainsString( '[data-pa-slider]:focus-visible', $css, 'the handle shows a focus ring' );
		$this->assertStringContainsString( 'data-pa-masked-left', $html, 'the masked half exists to reveal' );

		// The template must not carry the utilities that used to beat these
		// rules through the utilities layer.
		$this->assertDoesNotMatchRegularExpression(
			'/<div class="[^"]*perforated-applications-slider[^"]*\bw-1\b/',
			$html,
			'the slider row no longer carries the w-1 utility that pinned it to 3px'
		);
	}

	/**
	 * The split is measured against the panel, not the section.
	 *
	 * The comparison lives in the left half of the section, so a ratio taken
	 * from the section put the handle at half the pointer's distance.
	 */
	public function test_the_split_is_measured_against_its_panel(): void {
		$body = $this->pass( 'setupCompareDrag' );

		$this->assertStringContainsString( "closest('[data-pa-left-panel]')", $body, 'the drag measures the panel that holds the mask' );
		$this->assertStringContainsString( 'aria-valuenow', $body, 'the handle reports its value' );
		$this->assertStringContainsString( 'pointermove', $body, 'the drag tracks outside the handle' );
		$this->assertStringContainsString( 'ArrowLeft', $body, 'and the keyboard moves it' );
	}

	/**
	 * The three case studies are printed on the page, not only in a dialog.
	 *
	 * The old landing page showed each project's name, city, constraint and
	 * five specification lines as visible text. The rebuild moved all of it
	 * into a `VIEW CASE` dialog, so the page carried no named project and no
	 * proof at all until a visitor clicked — the section read as three empty
	 * cards. The band below keeps the dialog (which holds the photographs at
	 * full size) and states the same facts in the open.
	 *
	 * Both must carry the same strings: one fact, one source, no drift.
	 */
	public function test_the_case_studies_are_visible_on_the_page(): void {
		$stripped = preg_replace( '/<!--.*?-->/s', '', $this->markup( 'custom-screens' ) );
		$this->assertIsString( $stripped, 'the template reads as a string' );
		$html = $stripped;

		$start = strpos( $html, 'id="custom-project-proof"' );
		$this->assertNotFalse( $start, 'the page prints a project-proof band' );
		$band = substr( $html, (int) $start );

		foreach ( array(
			'Centuria Flagship Store',
			'Orchard Road, Singapore',
			'SS316 Exterior Grade',
			'1,200m² Cladding',
			'The Opus VIP Lounge',
			'Mayfair, London',
			'Rose Gold Brushed',
			'12 Divider Units',
			'Silverlake Estate Garden',
			'California, USA',
			'SS316 Marine Grade',
			'45 Linear Meters',
		) as $fact ) {
			$this->assertStringContainsString( $fact, $band, $fact . ' is printed in the open' );
		}

		// Each case study's own constraint sentence, not a paraphrase.
		$this->assertStringContainsString( 'custom hook-on system to ensure 10mm uniform shadow gaps', $band );
		$this->assertStringContainsString( 'without visible intermediate supports', $band );
		$this->assertStringContainsString( 'Required a specific Antique Bronze PVD finish', $band );

		// The dialog still holds the photographs.
		$this->assertSame( 3, substr_count( $html, 'class="app-lightbox"' ), 'the three case dialogs remain' );
	}
}
