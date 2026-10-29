<?php
/**
 * Guards for the PDP template's structure: the wide slot is wired, and the
 * technical FAQ is one closed three-question list.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Two defects lived in these two files at once, and neither could be seen on
 * the page:
 *
 * - `Shortcodes::SLOTS` named a `wide` slot and `pdp.css` styled `.pdp-wide`,
 *   but no template ever asked for it, so the 21:9 band the old page showed on
 *   its four accent-carrying patterns was simply absent — no error, no empty
 *   box, nothing to notice. The slot's guard is the same
 *   `md_pdp_slot_has_image` the plate and square frames use, so the wiring is
 *   what has to be held here: the shortcode existing proves nothing.
 * - `partials/md-pdp-faq.html` opened two `<div>` and closed none, and the
 *   template's own two hard-coded questions plus three stray `</div>`
 *   absorbed the imbalance. The rendered page had three questions and looked
 *   right; the source was nested one section deep, and the third question sat
 *   outside `.pdp-faq-list` where the list's max-width did not reach it.
 *
 * A browser would confirm both, but the failure mode of both is "renders
 * plausibly wrong", which is exactly what a structural guard catches and a
 * screenshot does not. The markup numbers below are asserted against the
 * files as the site ships them, which is what gets compiled into the
 * WindPress volumes and deployed by `wp md-site present`.
 *
 * @coversNothing
 */
final class PdpStructureTest extends TestCase {

	/**
	 * The template draws the wide slot, guarded, inside the applications
	 * panel where the old page's row placed it.
	 *
	 * The order matters and is asserted: `.pdp-wide + .pdp-app-row` in
	 * `pdp.css` drops the first application row's top border *only* when the
	 * wide band immediately precedes it, so a `wide` block that drifted below
	 * the rows would keep the rule but lose its effect.
	 */
	public function test_the_template_draws_the_wide_slot_guarded_above_the_application_rows(): void {
		$html = $this->template();

		$this->assertStringContainsString(
			'[lc_if condition="md_pdp_slot_has_image" param="wide"]',
			$html,
			'the wide band is guarded like the plate and square frames: a slot with no accent must leave no empty 21:9 box in the DOM'
		);
		$this->assertStringContainsString(
			'[md_pdp_image slot="wide"]',
			$html,
			'the wide band draws the slot SLOTS names; otherwise the 21:9 styles in pdp.css style nothing'
		);
		$this->assertStringContainsString(
			'class="pdp-wide',
			$html,
			'the element carries the class pdp.css targets'
		);

		$wide = strpos( $html, '[md_pdp_image slot="wide"]' );
		$apps = strpos( $html, 'id="applications"' );
		$rows = strpos( $html, '[lc_get_partial slug="md-where-works"]' );
		$this->assertNotFalse( $wide, 'the wide slot is in the template' );
		$this->assertNotFalse( $apps, 'the applications panel is in the template' );
		$this->assertNotFalse( $rows, 'the application rows are in the template' );
		$this->assertGreaterThan( (int) $apps, (int) $wide, 'the wide band sits inside the applications panel, not in the hero' );
		$this->assertLessThan( (int) $rows, (int) $wide, 'the wide band precedes the application rows, which is what .pdp-wide + .pdp-app-row depends on' );
	}

	/**
	 * The FAQ partial is a closed, self-contained three-question list.
	 *
	 * `[lc_get_partial]` splices the file's text in as-is, so imbalance in the
	 * partial becomes imbalance in the page: it can only be caught here.
	 */
	public function test_the_faq_partial_is_a_closed_three_question_list(): void {
		$partial = (string) file_get_contents( Templates::path( 'partials/md-pdp-faq.html', true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertSame( 3, substr_count( $partial, 'class="pdp-faq-item"' ), 'the partial carries all three questions' );
		$this->assertSame( 3, substr_count( $partial, 'class="pdp-faq-q"' ), 'one button per question' );
		$this->assertSame( 3, substr_count( $partial, 'class="pdp-faq-a"' ), 'one answer per question' );

		$this->assertSame(
			substr_count( $partial, '<div' ),
			substr_count( $partial, '</div>' ),
			'the partial closes every <div> it opens: the template must not be the thing that balances it'
		);

		// Three items x (item frame + answer) = six divs, and no more: the
		// list wrapper and the section's container belong to the template.
		$this->assertSame( 6, substr_count( $partial, '<div' ), 'each question is a frame and an answer, and nothing else' );

		// Each question's index appears in two bindings (the click that sets
		// it and the aria-expanded that reports it), so the unique set is the
		// question count and no index is shared.
		preg_match_all( '/activeFaq === (\d+) \?/', $partial, $bindings );
		$found = $bindings[1];
		$this->assertSame( array( '0', '1', '2' ), array_values( array_unique( $found ) ), 'each question toggles its own index, so opening one closes the other' );
		$this->assertCount( 6, $found, 'two bindings per question: the click and the reported state' );
	}

	/**
	 * The template adds no question of its own beside the partial.
	 *
	 * The defect was two hard-coded items standing in for the partial's
	 * missing two; a fix that moved them into the partial but left them in
	 * the template would render five questions where the design has three.
	 */
	public function test_the_template_carries_no_faq_items_of_its_own(): void {
		$html = $this->template();

		$this->assertSame(
			1,
			substr_count( $html, '[lc_get_partial slug="md-pdp-faq"]' ),
			'the template includes the FAQ partial exactly once'
		);
		$this->assertSame(
			0,
			substr_count( $html, 'class="pdp-faq-item"' ),
			'the questions live in the partial, not in the template'
		);
	}

	/**
	 * The related grid's widest row is the number of cards the template asks
	 * WooCommerce for, and it steps down rather than squeezing.
	 *
	 * The two halves of this change live in different files — the ceiling in
	 * `Shortcodes::RELATED_LIMIT`, the tracks in `pdp.css` — so a change to
	 * one alone would leave four cards in a three-track row (one wrapped onto
	 * a second line) or three tracks' worth of space for four. This reads the
	 * constant and asserts the stylesheet's base track count against it, then
	 * asserts the narrower steps exist.
	 */
	public function test_the_related_grid_holds_as_many_tracks_as_the_card_ceiling(): void {
		$css = (string) file_get_contents( dirname( Templates::path( 'x', true ) ) . '/../windpress/pdp.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertMatchesRegularExpression(
			'/\.pdp-related-grid \{ display: grid; grid-template-columns: repeat\((\d+), minmax\(0, 1fr\)\)/',
			$css,
			'the related grid declares its tracks with minmax(0, 1fr), so a card can shrink instead of overflowing'
		);
		preg_match( '/\.pdp-related-grid \{ display: grid; grid-template-columns: repeat\((\d+), minmax\(0, 1fr\)\)/', $css, $base );
		$tracks = isset( $base[1] ) ? (int) $base[1] : 0;
		$this->assertSame(
			\MetalDivider\Site\Presentation\Shortcodes::RELATED_LIMIT,
			$tracks,
			'the grid holds one track per card the template asks for'
		);

		// The steps down, at the widths the comment names: 3 tracks at 1199,
		// 2 at 1023, 1 at 749. Each must be present, or a narrow viewport
		// squeezes four cards into the width of one.
		foreach ( array(
			'1199' => 3,
			'1023' => 2,
			'749'  => 1,
		) as $width => $tracks ) {
			$pattern = $tracks > 1
				? '/@media \(max-width: ' . $width . 'px\) \{\s*#[^{]*\.pdp-related-grid \{ grid-template-columns: repeat\(' . $tracks . ', minmax\(0, 1fr\)\); \}\s*\}.*?@media \(max-width: 749px\)/s'
				: '/@media \(max-width: 749px\) \{.*?\.pdp-related-grid \{ grid-template-columns: 1fr; \}/s';
			$this->assertMatchesRegularExpression(
				$pattern,
				$css,
				"the related grid steps to {$tracks} track(s) at {$width}px"
			);
		}
	}

	/**
	 * The FAQ section closes where it opened: the list wrapper, the container
	 * and the section each take one `</div>`/`</section>` and no more.
	 *
	 * Asserted as a div balance over the whole template rather than by line
	 * count: the defect was an extra `</div>` closing the container early, and
	 * an early close is invisible to any test that only counts the FAQ items.
	 */
	public function test_the_whole_template_balances_its_tags(): void {
		$html = $this->template();

		$this->assertSame(
			substr_count( $html, '<div' ),
			substr_count( $html, '</div>' ),
			'every <div> in the template is closed in the template'
		);
		$this->assertSame(
			substr_count( $html, '<section' ),
			substr_count( $html, '</section>' ),
			'every <section> in the template is closed in the template'
		);
	}

	/**
	 * The PDP template's source.
	 *
	 * @return string
	 */
	private function template(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		return (string) file_get_contents( Templates::path( 'pdp' ) );
	}
}
