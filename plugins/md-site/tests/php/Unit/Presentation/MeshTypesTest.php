<?php
/**
 * The expanded-mesh sections drive their scenes from scroll position.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The home page's "decorative screens for every application" band is a 1200vh
 * scroll track with a sticky 100vh stage holding six scenes: a background, a
 * copy block, a FAQ group and a nav item each. The volumes shipped the whole
 * visual vocabulary for switching scenes — `.emt-bg-active`, `.emt-bg-prev`,
 * the `.emt-rev` variant for travelling upward, `.emt-active` on the copy and
 * FAQ groups, `aria-expanded` styling on the FAQ buttons and a `max-height: 0`
 * answer element waiting for a height — and no runtime ever set any of it.
 *
 * So the track scrolled through 1200vh with the stage pinned on scene one:
 * five scenes and thousands of pixels of blank stage. The old section had an
 * inline script; the conversion dropped it, and the CSP blocks inline handlers
 * anyway, so the behaviour belongs in md-chrome.js with the rest of the
 * converted interactions.
 *
 * These are guard tests, not behaviour tests — this suite has no layout engine.
 * They hold the template shape the runtime reads and the source facts the
 * browser acceptance depends on. The scene/scroll/FAQ evidence at 1440 and 390
 * is recorded in the handoff.
 *
 * @coversNothing
 */
final class MeshTypesTest extends TestCase {

	/**
	 * The sections carrying this vocabulary, by template file stem.
	 *
	 * @return array<string, int>
	 */
	private function sections(): array {
		return array(
			'home'                  => 6,
			'family-expanded-metal' => 4,
		);
	}

	/**
	 * One template's source with its HTML comments removed.
	 *
	 * @param string $file The template file stem.
	 */
	private function markup( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$source = (string) file_get_contents( Templates::path( $file ) );
		return (string) preg_replace( '/<!--.*?-->/s', '', $source );
	}

	/**
	 * The chrome runtime's source.
	 */
	private function runtime(): string {
		$path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The runtime's setupMeshTypes body.
	 */
	private function block(): string {
		$source = $this->runtime();
		$start  = strpos( $source, 'function setupMeshTypes()' );
		$this->assertNotFalse( $start, 'the runtime binds the expanded-mesh sections' );

		$end = strpos( $source, 'function setupSticky()', (int) $start );
		$this->assertNotFalse( $end, 'setupMeshTypes terminates before the next stage' );

		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * Every scene has all four parts, so no scene can be half-drawn.
	 *
	 * The runtime switches four parallel groups by index. A scene present in
	 * one group and missing from another would leave the stage showing a
	 * background with no copy, or a nav item that scrolls to nothing.
	 */
	public function test_every_scene_has_a_background_copy_faq_and_nav_item(): void {
		foreach ( $this->sections() as $file => $scenes ) {
			$html = $this->markup( $file );

			foreach ( array( 'data-emt-bg', 'data-emt-content', 'data-emt-faq', 'data-emt-nav' ) as $attribute ) {
				$this->assertSame(
					$scenes,
					preg_match_all( '/\b' . $attribute . '="\d+"/', $html ),
					$file . ': the scene count is consistent across ' . $attribute
				);
			}
		}
	}

	/**
	 * Each scene index is present exactly once in each group.
	 *
	 * `apply()` switches on the index, so a duplicated or skipped index would
	 * leave one scene permanently hidden and another fighting itself.
	 */
	public function test_scene_indices_are_dense_and_unique(): void {
		foreach ( $this->sections() as $file => $scenes ) {
			$html = $this->markup( $file );

			foreach ( array( 'data-emt-bg', 'data-emt-content', 'data-emt-faq', 'data-emt-nav' ) as $attribute ) {
				preg_match_all( '/\b' . $attribute . '="(\d+)"/', $html, $matches );
				$indices = array_map( 'intval', $matches[1] );
				sort( $indices );
				$this->assertSame(
					range( 0, $scenes - 1 ),
					$indices,
					$file . ': ' . $attribute . ' indices run 0..' . ( $scenes - 1 ) . ' with no gaps or repeats'
				);
			}
		}
	}

	/**
	 * The FAQ is a button/answer pair per question, and the answer starts shut.
	 *
	 * The runtime opens an answer by measuring `scrollHeight` and setting
	 * `max-height`; that only works because the volume starts the element at
	 * zero with `overflow: hidden`, and because the state it reads back is
	 * `aria-expanded` on the button beside it.
	 */
	public function test_each_faq_question_pairs_a_button_with_a_collapsible_answer(): void {
		foreach ( $this->sections() as $file => $scenes ) {
			$html = $this->markup( $file );

			preg_match_all( '/data-emt-faq-wrap/', $html, $wraps );
			preg_match_all( '/data-emt-faq-btn/', $html, $buttons );
			preg_match_all( '/data-emt-faq-ans/', $html, $answers );

			$this->assertGreaterThan( $scenes, count( $wraps[0] ), $file . ': the section carries questions beyond one per scene' );
			$this->assertSame( count( $wraps[0] ), count( $buttons[0] ), $file . ': every question has a button' );
			$this->assertSame( count( $wraps[0] ), count( $answers[0] ), $file . ': every question has an answer' );

			// Shipped closed: the runtime treats a missing aria-expanded as
			// "not open", so a template that started one open would have its
			// first click close an already-closed answer.
			$this->assertSame(
				0,
				preg_match_all( '/data-emt-faq-btn[^>]*aria-expanded="true"/', $html ),
				$file . ': every answer ships collapsed'
			);
			$this->assertSame(
				count( $buttons[0] ),
				preg_match_all( '/data-emt-faq-btn[^>]*aria-expanded="false"/', $html ),
				$file . ': every button states its collapsed state'
			);
		}
	}

	/**
	 * The runtime is wired into start(), and it finds the sections by prefix.
	 *
	 * The outer and stage ids carry a page-specific template suffix
	 * (`…__decorative_panels_grid_gmaCty`), so a full id would only ever serve
	 * one page. The prefix is what lets one function serve both sections — and
	 * the scene count is read from the markup for the same reason.
	 */
	public function test_the_runtime_is_wired_and_reads_the_markup_rather_than_a_count(): void {
		$source = $this->runtime();

		$this->assertStringContainsString( 'setupMeshTypes();', $source, 'the setup runs at start' );
		$this->assertStringContainsString( 'querySelectorAll(\'[id^="emt-outer-"]\')', $source, 'sections are found by id prefix' );
		$this->assertStringContainsString( 'querySelector(\'[id^="emt-stage-"]\')', $source, 'the sticky stage is found by id prefix' );

		// The scene count is measured from the markup: a literal six would
		// silently leave the four-scene section's last scenes unreachable.
		$this->assertMatchesRegularExpression(
			'/scene \+ 1 > count/',
			$this->block(),
			'the scene count is derived from the highest index in the markup'
		);
		$this->assertStringNotContainsString( 'var count = 6', $this->block(), 'no hard-coded scene count' );
	}

	/**
	 * Scroll position is the single source of truth for the active scene.
	 *
	 * A nav click that set the scene directly would be a second source of
	 * truth: the next scroll event would overwrite it, and a click that landed
	 * mid-scroll would fight the handler. The click therefore scrolls, and the
	 * same `apply()` decides what is visible either way.
	 */
	/**
	 * The last chip must land inside its own slice, not on the leading edge.
	 *
	 * Wall Cladding is index 5 of 6. Aiming at `distance * 5 / 6` is the
	 * boundary, and the browser's integer scroll offset falls one pixel short,
	 * so floor() reports scene 4. The last slice is the end of the track; sync
	 * already gives that pixel to the last scene.
	 */
	public function test_the_last_chip_aims_at_the_end_of_the_track_not_the_slice_edge(): void {
		$block = $this->block();
		$aim   = $this->between( $block, 'var scrollTopFor = function (index) {', 'var sync = function' );
		$sync  = $this->between( $block, 'var sync = function () {', 'var frame = 0' );

		$this->assertStringContainsString( 'index >= count - 1', $aim, 'the last chip is a special target' );
		$this->assertStringContainsString( 'return top + total', $aim, 'the last chip scrolls to the end of the track' );
		$this->assertDoesNotMatchRegularExpression(
			'/return top \+ \(distance\(\) \* index\) \/ count/',
			$aim,
			'aiming at the leading edge rounds down into the previous scene'
		);
		$this->assertStringContainsString( 'travelled >= total - 1', $sync, 'the final pixel still belongs to the last scene' );
	}

	/**
	 * A nav click travels to the scene rather than changing it in place.
	 */
	public function test_a_nav_click_scrolls_instead_of_setting_the_scene(): void {
		$block = $this->block();

		$this->assertStringContainsString( 'window.scrollTo(', $block, 'the nav scrolls the page' );
		$this->assertStringContainsString( 'scrollTopFor(', $block, 'to the scene the item names' );
		$this->assertStringContainsString( 'data-emt-nav', $block, 'the nav items are the click targets' );

		// Reduced motion: no smooth travel, same destination.
		$this->assertStringContainsString( 'prefers-reduced-motion: reduce', $block, 'reduced motion is honoured' );
		$this->assertStringContainsString( "behavior: reduced ? 'auto' : 'smooth'", $block, 'the jump is instant when motion is reduced' );
	}

	/**
	 * Travelling upward replays the reverse animation.
	 *
	 * The volumes carry two extra keyframes behind `.emt-rev`; they are dead
	 * code unless the runtime sets the class when the scene index decreases.
	 * Without it a reversed scroll plays the forward slide and the section
	 * looks broken rather than merely finished.
	 */
	public function test_scrolling_back_sets_the_reverse_state(): void {
		$block = $this->block();

		$this->assertStringContainsString( "classList.add('emt-rev')", $block, 'going back marks the section reversed' );
		$this->assertStringContainsString( "classList.remove('emt-rev')", $block, 'going forward clears it' );
		$this->assertMatchesRegularExpression(
			'/if \(index < current\)/',
			$block,
			'the direction test compares the new scene against the previous one'
		);
	}

	/**
	 * Scenes the visitor cannot see are out of the tab order.
	 *
	 * The volume hides the inactive copy and FAQ groups with opacity and
	 * `pointer-events: none` only. Those buttons stay focusable, so Tab walks
	 * into answers belonging to a scene that is not on screen — the same defect
	 * the closed lightboxes had. `inert` is what removes them.
	 */
	public function test_hidden_scenes_are_removed_from_the_tab_order(): void {
		$block = $this->block();

		$this->assertStringContainsString( "item.setAttribute('inert', '')", $block, 'an inactive scene is inert' );
		$this->assertStringContainsString( "item.removeAttribute('inert')", $block, 'the active one is not' );
		$this->assertStringContainsString( 'aria-hidden', $block, 'and the state is announced' );
	}

	/**
	 * Opening an answer measures its height and states the state.
	 *
	 * The volume's answer rule is `max-height: 0`, so a `max-height` of any
	 * fixed number would clip longer answers and leave gaps under shorter
	 * ones. `scrollHeight` — the content's own height while the box is clipped
	 * — is the only value that fits every answer, and it is re-measured on
	 * resize because the copy reflows and the old snapshot would then clip.
	 */
	public function test_an_opened_answer_is_measured_and_re_expanded_on_resize(): void {
		$block = $this->block();

		// The `open` branch specifically. Asserting `scrollHeight` somewhere in
		// the block is not enough: the resize re-measure below also mentions it,
		// so a fixed height in the opener would still pass.
		$opener = $this->between( $block, 'var setAnswer = function (wrap, open) {', '} else {' );
		$this->assertMatchesRegularExpression(
			'/answer\.style\.maxHeight = answer\.scrollHeight \+ \'px\'/',
			$opener,
			'the box opens to the content\'s measured height'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/maxHeight = [\'"]?\d/',
			$opener,
			'no fixed pixel height can stand in for the measurement'
		);

		$this->assertStringContainsString( "button.setAttribute('aria-expanded'", $block, 'the button states the answer state' );

		// The same measurement again on resize, where the copy reflows and the
		// height recorded at open time would clip the text.
		$resize = $this->between( $block, "window.addEventListener('resize', function () {", '});' );
		$this->assertStringContainsString( 'scrollHeight', $resize, 'an open answer is re-measured when the layout changes' );

		// One answer at a time in a group: a second open answer pushes the rest
		// of the list below the bottom of the stage.
		$this->assertStringContainsString( 'other !== wrap', $block, 'siblings are collapsed when one opens' );
	}

	/**
	 * The source between the start of `$from` and the first `$to` after it.
	 *
	 * @param string $source The enclosing source.
	 * @param string $from   The opening marker, included.
	 * @param string $to     The closing marker, excluded.
	 */
	private function between( string $source, string $from, string $to ): string {
		$start = strpos( $source, $from );
		$this->assertNotFalse( $start, 'the runtime defines ' . $from );
		$end = strpos( $source, $to, (int) $start );
		$this->assertNotFalse( $end, $from . ' is followed by ' . $to );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * Reduced motion removes the travel, not the scene change.
	 *
	 * The scene changing is the content; only the animation is optional. The
	 * stylesheet therefore drops animation and transition durations while
	 * leaving every state class in place.
	 */
	public function test_reduced_motion_drops_the_animation_but_keeps_the_state(): void {
		$volume = (string) ( new \ReflectionClass( Templates::class ) )->getFileName();
		$dir    = dirname( $volume ) . '/../../content/windpress';
		$css    = (string) file_get_contents( $dir . '/chrome.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$home   = (string) file_get_contents( $dir . '/home.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
		$this->assertMatchesRegularExpression(
			'/@media \(prefers-reduced-motion: reduce\)\{[\s\S]{0,400}?\[data-emt-bg\][\s\S]{0,400}?animation: none !important/',
			$css,
			'the scene animation is disabled under reduced motion'
		);
		// The rule that gives the class its meaning is the volume's, and it is
		// not scoped under the media query: reduced motion must change how fast
		// a scene arrives, never whether it does.
		$this->assertMatchesRegularExpression(
			'/\n\.emt-bg-template[^,\n]*\.emt-bg-active \{/',
			$home,
			'the active-state class the runtime sets keeps its rule'
		);
	}

	/**
	 * The outgoing scene's background has to lose `.emt-bg-prev` when its
	 * animation finishes.
	 *
	 * The volume's rule is `visibility: visible; z-index: 1` plus the
	 * slide-out keyframes, so while the class stays the old image remains
	 * painted underneath the new one. Measured before this: 2.5s after a
	 * click the outgoing scene was still `visibility: visible`, and after a
	 * few swipes every scene visited was stacked under the current one. The
	 * class name promises a one-shot transition state, so the runtime has to
	 * clear it — on `animationend`, with a timer as the floor for the cases
	 * where the event never fires (reduced motion turns the animation off,
	 * and a backgrounded tab pauses it).
	 */
	public function test_the_outgoing_background_is_cleared_when_its_animation_ends(): void {
		$runtime = $this->runtime();

		$this->assertMatchesRegularExpression(
			'/addEventListener\(\s*\'animationend\'\s*,\s*settle\s*\)/',
			$runtime,
			'the transition state is cleared on animationend'
		);
		$this->assertMatchesRegularExpression(
			'/__emtPrevTimer\s*=\s*setTimeout\(\s*settle\s*,/',
			$runtime,
			'a timer clears it too, so reduced motion and a paused tab still settle'
		);
		$this->assertMatchesRegularExpression(
			'/bg\.classList\.remove\(\s*\'emt-bg-prev\'\s*\)/',
			$runtime,
			'the stale class is actually removed'
		);
	}
}
