<?php
/**
 * The header's menu order and the facade route list's restored form.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Two owner rulings from 2026-10-30 (batch two), and the guards that keep
 * them from drifting back.
 *
 * **Menu order.** The old site ran SCREENS, PATTERN, MANUFACTURING PROCESS,
 * GALLERY, RESOURCES, ABOUT, CONTACT. The new site had MANUFACTURING PROCESS
 * before PATTERN, which is a different reading order: a buyer looking for a
 * design first met the process of making it. The rule is about which item
 * comes first, not about the wording, so the assertion is on the order of
 * the two blocks' own labels rather than on line numbers.
 *
 * **The facade route list.** The old site draws six routes as bare words
 * over a darkened photograph. The new site had grown an opaque #0e0e0e panel
 * with a hairline, a radius and a shadow (home.css, `min-width: 992px`) —
 * put there to fix a 1.25:1 contrast failure, and by 2026-10-30 reading as a
 * card pinned onto the picture. The owner's ruling is that the card is the
 * defect and the list goes back to the old form: no box, a thin rule between
 * rows, the gold left rule and the gold numbers kept.
 *
 * Neither of these can be checked by pressing a key in PHPUnit, so the tests
 * hold the markup and the rules the browser then renders; the per-row
 * contrast measurements that justify the label opacity are recorded in the
 * home.css comment, taken from a real Chrome against the scrimmed photo.
 *
 * @coversNothing
 */
final class HeaderOrderAndFacadeIndexTest extends TestCase {

	/**
	 * The header partial's source.
	 */
	private function header(): string {
		return (string) file_get_contents( Templates::path( 'partials/header.html', true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The home face's source.
	 */
	private function home(): string {
		return (string) file_get_contents( Templates::path( 'home' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The list's own rule inside the desktop media block, declarations only.
	 *
	 * The block holds the list rule, six row rules and the button rules; the
	 * card properties belong to the first of those alone, and the others
	 * legitimately declare the same properties with list-row values. Slicing
	 * the selector's own braces is what keeps the assertions about the card
	 * from being answered by a child.
	 *
	 * @param string $css The home volume.
	 */
	private function panel_rule( string $css ): string {
		$block = $this->desktop_route_block( $css );

		// Start inside the media query's own brace: the block begins with
		// `@media (min-width: 992px) {`, and the list's rule is the first
		// thing in there.
		$media_open = strpos( $block, '{' );
		$this->assertNotFalse( $media_open, 'the desktop block opens a media query' );

		$inner = substr( $block, (int) $media_open + 1 );

		// Split rather than regex: `selector {declarations}`. PHPStan cannot
		// narrow preg_match's `array{}|array{string,string}` from a hit count,
		// and a guard should be about the CSS, not about that signature.
		$open = strpos( $inner, '{' );
		$this->assertNotFalse( $open, 'the route list has a rule of its own in the desktop block' );

		$selector = trim( substr( $inner, 0, (int) $open ) );
		$this->assertStringContainsString(
			'.emt-nav-template--',
			$selector,
			'the first rule inside the media query is the route list itself'
		);

		$rest = substr( $inner, (int) $open + 1 );
		$end  = strpos( $rest, '}' );
		$this->assertNotFalse( $end, 'and that rule has a declaration block' );

		return substr( $rest, 0, (int) $end );
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
	 * PATTERN precedes MANUFACTURING PROCESS in the desktop menu.
	 *
	 * Read off the two items' own labels, so moving either block (or
	 * rewording to "Patterns") still lands on the right answer, and a third
	 * item inserted between them does not break the assertion.
	 */
	public function test_pattern_comes_before_manufacturing_process(): void {
		$header = $this->header();

		$pattern = strpos( $header, "<h3 class='xo-menu-horizontal-v1__label'>Pattern</h3>" );
		$mfg     = strpos( $header, 'Manufacturing Process' );

		$this->assertNotFalse( $pattern, 'the desktop menu carries the Pattern item' );
		$this->assertNotFalse( $mfg, 'the desktop menu carries the Manufacturing Process item' );
		$this->assertLessThan(
			(int) $mfg,
			(int) $pattern,
			'the old site put PATTERN before MANUFACTURING PROCESS, so a buyer choosing a design meets it first'
		);
	}

	/**
	 * The whole desktop order, not just the one pair.
	 *
	 * The pair above is the ruling; this list is the old site's complete
	 * sequence, so a later reshuffle of the other five is caught too. The
	 * labels are matched as a subsequence of the file, which tolerates the
	 * sub-menu lines that sit between them.
	 */
	public function test_the_desktop_menu_runs_in_the_old_sites_order(): void {
		$header = $this->header();

		$order = array(
			'Stainless steel screens',
			'Pattern',
			'Manufacturing process',
			'Gallery',
			'FAQs & Download',
			'About',
			'Contact',
		);

		$cursor  = 0;
		$missing = array();
		foreach ( $order as $label ) {
			$at = strpos( $header, $label, $cursor );
			if ( false === $at ) {
				$missing[] = $label;
				continue;
			}
			$cursor = $at + strlen( $label );
		}
		$this->assertSame( array(), $missing, 'menu items out of the old order or absent: ' . implode( ', ', $missing ) );
	}

	/**
	 * The route list carries no opaque panel at desktop width.
	 *
	 * Each property is asserted separately so a partial revert names itself:
	 * the fill, the hairline that drew the card's edge, the radius and the
	 * shadow are four different declarations in one block.
	 */
	public function test_the_facade_route_list_is_not_a_card(): void {
		$css = $this->volume( 'home' );

		// Scoped to the list's own rule. The media block also carries the
		// button rule, which legitimately declares `background: transparent`
		// and `border-left: 2px solid transparent` — asserting over the whole
		// block let a restored `background: #0e0e0e` on the list pass, because
		// the button's transparent fill answered instead (found by putting the
		// panel back and watching this test stay green).
		$rule = $this->panel_rule( $css );

		$this->assertMatchesRegularExpression( '/background:\s*transparent/', $rule, 'the list has no fill' );
		$this->assertMatchesRegularExpression( '/border:\s*0(;|\s)/', $rule, 'and no card edge' );
		$this->assertMatchesRegularExpression( '/border-radius:\s*0(;|\s)/', $rule, 'and no card radius' );
		$this->assertMatchesRegularExpression( '/box-shadow:\s*none/', $rule, 'and no card shadow' );
		$this->assertMatchesRegularExpression( '/padding:\s*0(;|\s)/', $rule, 'and no panel inset of its own' );
		$this->assertDoesNotMatchRegularExpression(
			'/background:\s*#0e0e0e/',
			$rule,
			'the opaque panel that read as a card on the photograph is gone'
		);
	}

	/**
	 * The rows are separated by a thin rule, with none on the first or last.
	 *
	 * The old capture stacks six white words with a hairline under each, and
	 * that is what makes the column read as a list once the box is gone.
	 */
	public function test_the_rows_are_separated_by_a_hairline(): void {
		$css   = $this->volume( 'home' );
		$block = $this->desktop_route_block( $css );

		$this->assertMatchesRegularExpression(
			'/\.emt-nav-template--\S+ > li\s*\{[^}]*border-bottom:\s*1px solid rgba\(255, 255, 255, 0\.22\)/',
			$block,
			'each row carries a thin rule'
		);
		$this->assertMatchesRegularExpression(
			'/\.emt-nav-template--\S+ > li:last-child\s*\{[^}]*border-bottom:\s*0/',
			$block,
			'and the last row has none, so the list does not end on a stray line'
		);
	}

	/**
	 * The two things the owner asked to keep are still declared.
	 *
	 * The gold rule on the active row and the gold numbers are what carries
	 * the old site's accent through the list; a rewrite that dropped them
	 * would leave six plain words and pass every assertion above.
	 */
	public function test_the_gold_rule_and_numbers_survive(): void {
		$css   = $this->volume( 'home' );
		$home  = $this->home();
		$block = $this->desktop_route_block( $css );

		$this->assertMatchesRegularExpression(
			'/\.emt-nav-btn-template--\S+\.emt-active\s*\{[^}]*border-left-color:\s*#c9a962/',
			$block,
			'the active row keeps its gold left rule'
		);
		$this->assertStringContainsString(
			'.md-td8ee4148 { font-variant-numeric: tabular-nums;font-weight: 600;font-size: 14px;color: #c9a962',
			$css,
			'the numbers keep their gold and their tabular figures'
		);
		$this->assertSame( 6, preg_match_all( '/class="md-td8ee4148"/', $home ), 'one number per route' );
	}

	/**
	 * The labels are opaque enough to clear 4.5:1 over the photograph.
	 *
	 * The ruling removed the panel that used to guarantee contrast, so the
	 * label's own value is now load-bearing. `.5` was the failing value
	 * (2.66:1 measured at the brightest route-list background); anything at
	 * or below 0.6 is that defect coming back.
	 */
	public function test_the_labels_are_opaque_enough_to_carry_their_own_contrast(): void {
		$block = $this->desktop_route_block( $this->volume( 'home' ) );

		$this->assertMatchesRegularExpression(
			'/\.emt-nav-btn-template--\S+\s*\{[^}]*color:\s*rgba\(255, 255, 255, 0\.86\)/',
			$block,
			'the route labels are near-white, not the 50% that failed'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.emt-nav-btn-template--\S+\s*\{[^}]*color:\s*rgba\(255, 255, 255, 0\.5\)/',
			$block,
			'the 2.66:1 value is gone'
		);
	}

	/**
	 * The six routes and their numbers are the old site's.
	 *
	 * The list is data, not styling: a route renamed or renumbered would
	 * still render fine and would no longer match the section's six scenes.
	 */
	public function test_the_six_routes_and_numbers_are_unchanged(): void {
		$home = $this->home();

		$routes = array(
			'Facade Screens'             => '01',
			'Balcony Privacy Screens'    => '02',
			'Interior Partitions'        => '03',
			'Railing &amp; Fence Infill' => '04',
			'Gate Inserts'               => '05',
			'Wall Cladding'              => '06',
		);

		foreach ( $routes as $label => $number ) {
			$this->assertStringContainsString( ">{$label}</span>", $home, "the route {$label} is present" );
		}

		preg_match_all( '/class="md-td8ee4148">(\d{2})</', $home, $found );
		$this->assertSame( array_values( $routes ), $found[1], 'the numbers run 01..06 in order' );
	}

	/**
	 * The desktop route-index block: the `min-width: 992px` media query that
	 * owns the list at the width the panel used to live at.
	 *
	 * Sliced rather than matched across the whole volume, because the same
	 * class names carry the tablet and mobile rules too and those have a
	 * different shape on purpose (the phone turns the list into a scrolling
	 * chip row).
	 *
	 * @param string $css The home volume.
	 */
	private function desktop_route_block( string $css ): string {
		$start = strpos( $css, '@media (min-width: 992px)' );
		$this->assertNotFalse( $start, 'the desktop route-index block exists' );

		// The block runs to the next top-level closing brace followed by a
		// blank line, which is how this volume separates its sections.
		$end = strpos( $css, '/* Closing quote card', (int) $start );
		$this->assertNotFalse( $end, 'the desktop route-index block is delimited' );

		$block = substr( $css, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString( '.emt-nav-template-', $block, 'the slice holds the route-list rules' );
		return $block;
	}
}
