<?php
/**
 * The converted controls that were drawn and dead.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Every converted control family shipped as markup plus a stylesheet state
 * class that nothing ever set: the tab strips, the accordions, the carousels,
 * the announcement dots. The section was drawn, hover states worked, and
 * pressing anything did nothing.
 *
 * The fix is four primitives in md-chrome.js — bindCarousel, bindAccordion,
 * bindSwitchable, bindIndexedGroups (plus the existing bindTabSet, reused) —
 * and one small setup pass per family that says how to read that family's
 * attribute names. Nothing re-implements the behaviour.
 *
 * These are guard tests: this suite has no layout engine, so they hold the
 * template pairing each pass reads and the source facts the browser acceptance
 * depends on. The real-Chrome evidence at 1440 and 390 for every family is in
 * the handoff.
 *
 * @coversNothing
 */
final class ControlFamiliesTest extends TestCase {

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
	 * The body of one setup pass, from its signature to the next top-level
	 * function.
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
	 * Every pass is actually run.
	 *
	 * The failure mode this guards is the one that produced the dead controls
	 * in the first place: behaviour that exists in the file and is never
	 * called. A pass missing from start() is dead code that looks like a fix.
	 */
	public function test_every_control_pass_runs_at_start(): void {
		$source = $this->runtime();
		$start  = strpos( $source, 'function start()' );
		$this->assertNotFalse( $start );
		$body = substr( $source, (int) $start );

		$passes = array(
			'setupDeadTabFamilies',
			'setupMaterialAccordion',
			'setupOutcomesCarousel',
			'setupShowcaseCarousels',
			'setupProjectProofCarousels',
			'setupQualityDeck',
			'setupSpecTableSort',
			'setupFaqFilter',
			'setupPerforatedMarkers',
			'setupCompareDrag',
			'setupPredictiveSearchReset',
			'setupSpecDecisionCards',
		);
		foreach ( $passes as $pass ) {
			$this->assertStringContainsString( $pass . '();', $body, $pass . ' is called from start()' );
		}
	}

	/**
	 * There is one carousel engine, not seven.
	 *
	 * Six pages carry a case showcase, the home page carries the proof strip
	 * and the catalog pages carry the project-proof strip: three vocabularies,
	 * one behaviour. A second copy would drift — this is the same rule the tab
	 * engine and the surface stack already follow.
	 */
	public function test_the_carousel_families_share_one_engine(): void {
		$source = $this->runtime();

		$this->assertSame( 1, substr_count( $source, 'function bindCarousel(' ), 'one carousel implementation' );
		foreach ( array( 'setupOutcomesCarousel', 'setupShowcaseCarousels', 'setupProjectProofCarousels' ) as $pass ) {
			$this->assertStringContainsString( 'bindCarousel(', $this->pass( $pass ), $pass . ' uses the shared engine' );
		}
	}

	/**
	 * The carousel reads its step from the cards, and its ends from geometry.
	 *
	 * Two facts the browser evidence depends on:
	 * - the step is measured, because the cards differ per page;
	 * - "at the start" is a position test, not `scrollLeft === 0`. A snap
	 *   container with padding settles at scrollLeft = padding on load
	 *   (measured 25px on the home strip), so a zero comparison left the
	 *   previous arrow enabled on a strip that could not go back.
	 */
	public function test_the_carousel_measures_its_step_and_its_travel_ends(): void {
		$body = $this->pass( 'bindCarousel' );

		$this->assertStringContainsString( 'getBoundingClientRect().width', $body, 'the step is measured from the first card' );
		$this->assertStringContainsString( 'scrollWidth - track.clientWidth', $body, 'the end of travel is the true maximum' );
		$this->assertStringContainsString( 'atStart()', $body, 'the previous arrow asks the geometry, not scrollLeft' );
		$this->assertStringContainsString( 'atEnd()', $body, 'and so does the next arrow' );
		$this->assertStringNotContainsString( 'track.scrollLeft <= 1', $body, 'no zero comparison decides the arrows' );
	}

	/**
	 * Reduced motion is honoured by every animated move.
	 *
	 * A smooth scroll across a carousel is exactly the motion a visitor asked
	 * not to see; the destination stays the same.
	 */
	public function test_carousels_and_sliders_honour_reduced_motion(): void {
		$this->assertStringContainsString(
			'prefers-reduced-motion: reduce',
			$this->pass( 'bindCarousel' ),
			'the carousel picks an instant move under reduced motion'
		);
	}

	/**
	 * The laser FAQ's four parts are all bound.
	 *
	 * Categories, the text filter, the collapsible groups and the per-question
	 * answers were four separate dead controls in one section; a fix that
	 * wired three of them would look complete on a screenshot.
	 */
	public function test_the_laser_faq_binds_categories_search_groups_and_answers(): void {
		$html = $this->markup( 'family-laser-cut-panels' );
		$body = $this->pass( 'setupFaqFilter' );

		$this->assertGreaterThanOrEqual( 4, substr_count( $html, 'class="cat-tab' ), 'the category tabs exist' );
		$this->assertGreaterThanOrEqual( 4, substr_count( $html, 'class="faq-group"' ), 'the groups exist' );
		$this->assertGreaterThanOrEqual( 8, substr_count( $html, 'faq-item' ), 'the questions exist' );
		$this->assertStringContainsString( 'faq-search', $html, 'the search field exists' );

		$this->assertStringContainsString( 'cat-tab[data-cat]', $body, 'categories are bound' );
		$this->assertStringContainsString( 'faq-search', $body, 'search is bound' );
		$this->assertStringContainsString( 'group-body', $body, 'groups are bound' );
		$this->assertStringContainsString( 'aria-expanded', $body, 'answers state their state' );
	}

	/**
	 * The spec table sorts, and says so.
	 *
	 * The volume ships the sort arrows and the header hook; the arrows pointed
	 * at nothing. `aria-sort` is the part a screen reader needs — a reordered
	 * table without it is a silent reshuffle.
	 */
	public function test_the_spec_table_sorts_and_announces_the_order(): void {
		$html = $this->markup( 'family-expanded-metal' );
		$body = $this->pass( 'setupSpecTableSort' );

		$this->assertGreaterThanOrEqual( 4, substr_count( $html, 'data-sort=' ), 'the sortable headers exist' );
		$this->assertStringContainsString( 'th[data-sort]', $body, 'the headers are the controls' );
		// The announcement itself, not the word: a header that merely mentions
		// aria-sort read nowhere is the defect being guarded against.
		$this->assertMatchesRegularExpression(
			"/other\\.setAttribute\\('aria-sort', sortedThis/",
			$body,
			'the sort is written onto every header'
		);
		$this->assertStringContainsString( "'ascending'", $body, 'the direction is stated, not just "sorted"' );
		$this->assertStringContainsString( "'descending'", $body, 'and the reverse' );
		$this->assertStringContainsString( 'appendChild', $body, 'rows are actually reordered' );
		// Numeric and textual cells share a column set: a range like
		// "0.8 — 12.0 mm" and a note like "Sheet or coil format" must both sort.
		$this->assertStringContainsString( 'localeCompare', $body, 'textual cells sort as text' );
		$this->assertStringContainsString( 'parseFloat', $body, 'numeric cells sort as numbers' );
	}

	/**
	 * The switch families pair their controls with their panels by value.
	 *
	 * Five sections use this shape (expanded-metal ×2, manufacturing ×2, the
	 * catalog pagination). `hidden` is the state, and the volumes already
	 * style `[data-…-panel][hidden]` — so the pairing is what has to hold.
	 */
	public function test_the_switch_families_pair_controls_with_panels(): void {
		$body = $this->pass( 'bindSwitchableGroup' );

		$this->assertStringContainsString( 'panel.hidden', $body, 'a panel is switched with hidden' );
		$this->assertStringContainsString( 'aria-selected', $body, 'the control states its selection' );
		$this->assertStringContainsString( 'data-active', $body, 'and the style hook the volumes read' );

		// The state has to be decided by comparing the panel's own attribute
		// against the clicked value. `panel.hidden = <constant>` would leave
		// every panel showing, which is the dead-control state itself.
		$this->assertMatchesRegularExpression(
			'/panel\.hidden = panel\.getAttribute\(panelAttr\) !== value/',
			$body,
			'a panel is hidden by comparing its own attribute with the chosen value'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/panel\.hidden = (false|true)\b/',
			$body,
			'no panel visibility is set to a constant'
		);

		foreach ( array(
			array( 'family-expanded-metal', 'data-evp-switch', 'data-evp-panel' ),
			array( 'family-expanded-metal', 'data-evp-tab', 'data-evp-tab-content' ),
			array( 'manufacturing', 'data-mfg-spec-switch', 'data-mfg-spec-panel' ),
			array( 'manufacturing', 'data-mfg-spec-tab', 'data-mfg-spec-tab-content' ),
			array( 'series-archive', 'data-pag-tab', 'data-pag-panel' ),
		) as $pair ) {
			$html = $this->markup( $pair[0] );
			$this->assertGreaterThanOrEqual( 2, substr_count( $html, $pair[1] . '=' ), $pair[0] . ': ' . $pair[1] . ' controls exist' );
			$this->assertGreaterThanOrEqual( 2, substr_count( $html, $pair[2] . '=' ), $pair[0] . ': ' . $pair[2] . ' panels exist' );
		}
	}

	/**
	 * The indexed families name each group's attribute and class explicitly.
	 *
	 * The first draft inferred which data attribute identified an item by
	 * scanning for a numeric value, which paired the story rows with the wrong
	 * scene images. Every group now states its own contract.
	 */
	public function test_the_indexed_families_state_their_groups_explicitly(): void {
		$body = $this->pass( 'bindIndexedGroups' );

		$this->assertStringContainsString( 'group.attr', $body, 'each group names its attribute' );
		$this->assertStringContainsString( 'group.activeClass', $body, 'each group names its state class' );
		$this->assertStringNotContainsString( 'attributes[i].name', $body, 'nothing is inferred by scanning attributes' );

		// The item's active test must read that group's declared attribute.
		// Substituting any fixed attribute name would silently pair the story
		// rows with the wrong scene images — the exact defect this replaced.
		$this->assertMatchesRegularExpression(
			'/var active = keyOf\(item, group\.attr\) === value;/',
			$body,
			'an item is matched through its own group attribute'
		);

		$setup = $this->pass( 'setupDeadTabFamilies' );
		$this->assertStringContainsString( "attr: 'data-mfg-tech-bg'", $setup, 'the technique backgrounds state theirs' );
		$this->assertStringContainsString( "attr: 'data-sps-scene'", $setup, 'the story scenes state theirs' );
	}

	/**
	 * The accordion families open by measurement, never by a fixed cap.
	 *
	 * The expanded-metal volume caps an open body at a fixed 640px, which is
	 * shorter than these bodies on a phone.
	 */
	public function test_the_material_accordion_is_not_clipped_to_the_volume_cap(): void {
		$body = $this->pass( 'setupMaterialAccordion' );

		$this->assertMatchesRegularExpression(
			"/body\\.style\\.maxHeight = 'none'/",
			$body,
			'the open body is not capped'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/body\\.style\\.maxHeight = '\\d+px'/",
			$body,
			'no fixed pixel cap stands in for the open height'
		);
		$this->assertStringContainsString( 'aria-expanded', $body, 'the trigger states its state' );
		$this->assertStringContainsString( 'emf-active', $body, 'the paired image follows the open material' );

		$html = $this->markup( 'family-expanded-metal' );
		$this->assertSame( 3, substr_count( $html, 'data-emf-acc=' ), 'three materials' );
		$this->assertSame( 3, substr_count( $html, 'data-emf-img=' ), 'three paired images' );
	}

	/**
	 * Two switchable regions in one template never share a value namespace.
	 *
	 * The stainless page's tab strip counts from 1 and the story accordion below
	 * it counted from 0, both under `data-sps-panel`. One generic pass merged
	 * them, then hid the accordion's open panel because no tab carried its
	 * value — the row said "expanded", the panel it controlled was `hidden`,
	 * and the section rendered as an accordion that opens onto nothing.
	 *
	 * The pairing primitive now scopes each control to its own enclosing block,
	 * and the two regions carry different attribute names so they cannot collide
	 * again even if the scoping is bypassed.
	 */
	public function test_two_switchable_regions_never_share_a_value_namespace(): void {
		$body = $this->pass( 'bindSwitchable' );
		$this->assertStringContainsString( 'regionOf', $body, 'a control is scoped to its own block' );
		$this->assertStringContainsString( 'group.region.contains(panel)', $body, 'a group owns only the panels in its block' );

		$html = $this->markup( 'family-stainless-steel' );
		$this->assertSame( 4, substr_count( $html, 'data-sps-panel=' ), 'the four project tabs keep their own attribute' );
		$this->assertSame( 4, substr_count( $html, 'data-sps-story-panel=' ), 'the four story panels name themselves' );

		// Every story row that starts open must own a panel that is not hidden
		// in the shipped markup.
		$this->assertDoesNotMatchRegularExpression(
			'/class="sps-story-panel mt-5"\s*\n\s*data-sps-story-panel="\d+"\s*\n\s*aria-hidden="false"\s*\n\s*hidden/',
			$html,
			'an open story panel is not shipped hidden'
		);
	}

	/**
	 * The perforated section's markers and its drag both work, and the drag is
	 * operable by keyboard.
	 *
	 * A pointer-only split control strands every keyboard visitor, and the
	 * handle is 4px wide — the move and release listeners have to live on the
	 * window or the drag dies as soon as the pointer leaves it.
	 */
	public function test_markers_and_the_compare_drag_are_wired(): void {
		$html   = $this->markup( 'family-perforated-panels' );
		$marker = $this->pass( 'setupPerforatedMarkers' );
		$drag   = $this->pass( 'setupCompareDrag' );

		$this->assertSame( 4, substr_count( $html, 'data-pa-marker=' ), 'four application markers' );
		$this->assertGreaterThanOrEqual( 4, substr_count( $html, 'data-pa-bg-panel=' ), 'four backgrounds to switch' );
		$this->assertStringContainsString( 'data-pa-slider', $html, 'the split handle exists' );

		$this->assertStringContainsString( 'aria-pressed', $marker, 'a marker states its selection' );
		$this->assertStringContainsString( 'data-pa-position', $marker, 'the open-area readout follows the marker' );

		$this->assertStringContainsString( "window.addEventListener('pointermove'", $drag, 'the drag tracks outside the handle' );
		$this->assertStringContainsString( 'ArrowLeft', $drag, 'the split is operable by keyboard' );
		$this->assertStringContainsString( 'role', $drag, 'the handle announces itself as a control' );
	}

	/**
	 * The catalog's spec-decision cards fill their detail panel from the card.
	 *
	 * Every value the panel shows is already on the card, so the runtime copies
	 * rather than duplicating the content in four panels.
	 */
	public function test_the_spec_decision_cards_fill_the_detail_panel(): void {
		$html = $this->markup( 'series-archive' );
		$body = $this->pass( 'setupSpecDecisionCards' );

		$this->assertGreaterThanOrEqual( 4, substr_count( $html, 'data-psd-card' ), 'four route cards' );
		$this->assertStringContainsString( 'data-psd-detail', $html, 'one shared detail panel' );

		foreach ( array( 'data-psd-title', 'data-psd-desc', 'data-psd-image', 'data-psd-cta', 'data-psd-best', 'data-psd-notes' ) as $field ) {
			$this->assertStringContainsString( $field, $body, $field . ' is copied to the panel' );
		}
		// The list items are the volume's own classes, not generic <li>.
		$this->assertStringContainsString( "'psd__chip'", $body, 'best-fit chips keep the volume class' );
		$this->assertStringContainsString( "'psd__note'", $body, 'notes keep the volume class' );
	}

	/**
	 * The header's predictive search can be cleared.
	 *
	 * The field had no working clear control: pressing it did nothing, so a
	 * query could only be replaced by selecting the text by hand.
	 */
	public function test_the_predictive_search_reset_clears_and_refocuses(): void {
		$html = $this->markup( 'partials/header' );
		$body = $this->pass( 'setupPredictiveSearchReset' );

		$this->assertStringContainsString( 'data-predictive-search-input', $html, 'the field exists' );
		$this->assertStringContainsString( 'data-predictive-search-reset', $html, 'the clear control exists' );

		$this->assertStringContainsString( "input.value = ''", $body, 'the reset clears the query' );
		$this->assertStringContainsString( 'input.focus()', $body, 'focus returns to the field' );
		// The outcome is an empty result region; `innerHTML = ''` and
		// `textContent = ''` both remove every child, and the latter cannot
		// create nodes, so the assertion names the effect, not one spelling.
		$this->assertMatchesRegularExpression(
			'/results\.(?:innerHTML|textContent)\s*=\s*\'\'/',
			$body,
			'the result region is emptied'
		);
	}

	/**
	 * The quality deck states the shown document and hides the rest.
	 *
	 * The four cards carry a deck vocabulary (is-active / is-next / is-prev /
	 * is-hidden). Only the shown one may be announced or reachable — the others
	 * are stacked behind it.
	 */
	public function test_the_quality_deck_announces_only_the_shown_document(): void {
		$html = $this->markup( 'manufacturing' );
		$body = $this->pass( 'setupQualityDeck' );

		$this->assertSame( 4, substr_count( $html, 'data-mfg-quality-card' ), 'four documents' );
		$this->assertStringContainsString( "'is-active'", $body, 'the shown card is marked' );
		$this->assertStringContainsString( "'is-next'", $body, 'the deck positions are set' );
		$this->assertStringContainsString( 'aria-hidden', $body, 'only the shown card is announced' );
		$this->assertStringContainsString( "setAttribute('inert'", $body, 'and the stacked cards are unreachable' );
	}
}
