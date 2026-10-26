<?php
/**
 * The process sections' "Watch" tiles open their video dialogs.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Both family pages draw a play tile over one step of their process and a
 * full-screen dialog holding that step's video, and nothing bound the two.
 *
 * The old section carried an inline script that the conversion dropped, and
 * the CSP blocks inline handlers anyway, so `.video-trigger` was a button that
 * only looked pressable: no dialog, no playback, no focus change. The volumes
 * had already kept the halves — `#…-process-lightbox{display:none}` and
 * `.is-open{display:flex}` — waiting for state nobody set.
 *
 * The fix is `setupProcessVideos()` in md-chrome.js, which pairs a tile with
 * its dialog through the tile's own `aria-controls` and drives the dialog as a
 * surface on the existing stack (pushSurface/popSurface for the scroll lock and
 * the inert page, `__mdClose` for Escape, the shared Tab trap, restoreFocus).
 *
 * These are guard tests, not behaviour tests: this suite has no DOM. They hold
 * the template pairing the runtime reads and the source facts the acceptance
 * depends on — the browser evidence for open/play/close on both pages at 1440
 * and 390 is recorded in the handoff.
 *
 * @coversNothing
 */
final class ProcessVideoDialogTest extends TestCase {

	/**
	 * The two pages carrying a process video dialog, by template file stem.
	 *
	 * @return array<string, string>
	 */
	private function pages(): array {
		return array(
			'laser-cut-panels'  => 'family-laser-cut-panels',
			'perforated-panels' => 'family-perforated-panels',
		);
	}

	/**
	 * One template's source with its HTML comments removed.
	 *
	 * The comments in these files name the very attributes the assertions look
	 * for, so matching raw source would fail on the explanation of the rule
	 * rather than on a violation of it.
	 *
	 * @param string $file The template file stem.
	 */
	private function markup( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$source = (string) file_get_contents( Templates::path( $file ) );
		return (string) preg_replace( '/<!--.*?-->/s', '', $source );
	}

	/**
	 * The dialog element the tile points at, as the runtime finds it: the
	 * element whose id is the tile's `aria-controls`.
	 *
	 * @param string $html The template source.
	 */
	private function dialog_id( string $html ): string {
		$matched = preg_match( '/<button\b[^>]*\bvideo-trigger\b[^>]*>/', $html, $button );
		$this->assertSame( 1, $matched, 'the page ships one process play tile' );
		$this->assertArrayHasKey( 0, $button, 'the tile matches' );

		$matched = preg_match( '/\baria-controls="([^"]+)"/', $button[0], $controls );
		$this->assertSame( 1, $matched, 'the tile names the dialog it opens' );
		$this->assertArrayHasKey( 1, $controls, 'the control target is captured' );

		return $controls[1];
	}

	/**
	 * Every play tile points at a dialog that is actually in the same page.
	 *
	 * This is the pairing the runtime reads. Without it `setupProcessVideos()`
	 * skips the tile rather than guessing an id, so a renamed dialog would stop
	 * the tile working with nothing else to notice it.
	 */
	public function test_each_play_tile_controls_a_dialog_on_its_own_page(): void {
		foreach ( $this->pages() as $label => $file ) {
			$html = $this->markup( $file );
			$id   = $this->dialog_id( $html );

			$this->assertStringContainsString(
				'id="' . $id . '"',
				$html,
				$label . ': the tile\'s aria-controls resolves to an element on the page'
			);
			$this->assertSame(
				1,
				preg_match( '/id="' . preg_quote( $id, '/' ) . '"[^>]*\brole="dialog"/', $html ),
				$label . ': the target is the dialog element, not an inner box'
			);
		}
	}

	/**
	 * The dialog still holds the real player.
	 *
	 * The whole point of the surface is the video the section filmed: a poster,
	 * a source, and native controls. Swapping any of that for an embed or for a
	 * poster-only frame would make the tile open an empty black box.
	 */
	public function test_the_dialog_keeps_the_real_video_player(): void {
		foreach ( $this->pages() as $label => $file ) {
			$html = $this->markup( $file );
			$id   = $this->dialog_id( $html );
			$open = strpos( $html, 'id="' . $id . '"' );
			$this->assertNotFalse( $open, $label . ': the dialog exists' );

			// The dialog is a flat block: its closing </div> is the first one
			// after the player, so the slice up to </video> is exactly its body.
			$end = strpos( $html, '</video>', (int) $open );
			$this->assertNotFalse( $end, $label . ': the dialog holds a closed video element' );
			$block = substr( $html, (int) $open, (int) $end - (int) $open );

			$this->assertStringContainsString( '<video', $block, $label . ': the dialog contains a video element' );
			$this->assertStringContainsString( 'controls', $block, $label . ': the player keeps its native controls' );
			$this->assertSame(
				1,
				preg_match( '#<source src="[^"]+\.mp4"#', $block ),
				$label . ': the player keeps its source'
			);
		}
	}

	/**
	 * A `<video>` has no `loading` attribute.
	 *
	 * `loading` is the `<img>`/`<iframe>` attribute; on `<video>` it is an
	 * invalid attribute that no browser reads, so a conversion that copied it
	 * across implied a laziness the element never had. `preload` is the video
	 * element's own control and is what stays.
	 */
	public function test_no_video_carries_an_invalid_loading_attribute(): void {
		foreach ( $this->pages() as $label => $file ) {
			$html = $this->markup( $file );

			$this->assertSame(
				0,
				preg_match_all( '/<video\b[^>]*\bloading=/', $html ),
				$label . ': no video element carries a loading attribute'
			);
		}
	}

	/**
	 * The poster the mid-CTA video ships is untouched.
	 *
	 * Removing the invalid `loading` attribute was the only edit those
	 * elements were meant to take: the poster is the frame a visitor sees
	 * before playback, and a rewrite that dropped it would leave a black band.
	 */
	public function test_stripping_loading_leaves_the_posters_in_place(): void {
		$posters = array(
			'laser-cut-panels'  => '/wp-content/uploads/2026/10/00af6f1ec2da47bfbb8e296ba63de4f1-thumbnail-0000000000-2200x-398w.webp',
			'perforated-panels' => '/wp-content/uploads/2026/10/09254a60368d4d1e87ddf4bec24378d8-thumbnail-0000000000-1100x-720w.webp',
		);

		foreach ( $this->pages() as $label => $file ) {
			$html = $this->markup( $file );

			$this->assertStringContainsString(
				'poster="' . $posters[ $label ] . '"',
				$html,
				$label . ': the video keeps the poster it shipped with'
			);
			$this->assertStringNotContainsString(
				'poster=""',
				$html,
				$label . ': no poster was blanked'
			);
		}
	}

	/**
	 * The dialog is a surface on the shared stack, not a second modal engine.
	 *
	 * The behaviour that matters cannot be unit-tested here, so what is held is
	 * that the runtime reaches for the mechanisms that already exist: the
	 * surface stack (scroll lock + inert page), the `__mdClose` hook the shared
	 * Escape handler calls, the shared Tab trap's focus list, and the shared
	 * focus restore. A local copy of any of these is the regression.
	 */
	public function test_the_runtime_reuses_the_shared_surface_mechanisms(): void {
		$source = $this->runtime();
		$start  = strpos( $source, 'function setupProcessVideos()' );
		$this->assertNotFalse( $start, 'the runtime binds the process tiles' );

		$end   = strpos( $source, 'function setupSticky()', (int) $start );
		$block = false === $end ? $source : substr( $source, (int) $start, (int) $end - (int) $start );

		foreach ( array( 'pushSurface(dialog)', 'popSurface(dialog)', 'restoreFocus(opener)', 'initialFocusTarget(dialog)' ) as $call ) {
			$this->assertStringContainsString( $call, $block, 'the dialog uses the shared surface manager: ' . $call );
		}
		$this->assertStringContainsString( 'dialog.__mdClose = close', $block, 'Escape closes through the shared handler' );

		// The Escape handler itself is the shared one: it closes the top of the
		// stack through whatever __mdClose the surface published.
		$this->assertMatchesRegularExpression(
			'/openStack\[openStack\.length - 1\][\s\S]{0,200}__mdClose/',
			$source,
			'the one keydown handler routes Escape to the topmost surface'
		);
	}

	/**
	 * Opening starts the player and closing stops it.
	 *
	 * Both halves are asserted inside the function that owns them. A grep over
	 * the whole setup block would pass on the *setup* pause alone (the closed
	 * state stops the player too), which is exactly how a close() that forgot
	 * to pause still looked green.
	 */
	public function test_opening_plays_and_closing_pauses_the_video(): void {
		$source = $this->runtime();
		$start  = strpos( $source, 'function setupProcessVideos()' );
		$this->assertNotFalse( $start );

		$end   = strpos( $source, 'function setupSticky()', (int) $start );
		$block = false === $end ? $source : substr( $source, (int) $start, (int) $end - (int) $start );

		$close = $this->function_body( $block, 'var close = function () {' );
		$this->assertStringContainsString( 'video.pause()', $close, 'closing stops playback' );

		$open = $this->function_body( $block, 'var open = function () {' );
		$this->assertStringContainsString( 'video.play()', $open, 'opening starts playback' );

		$this->assertStringContainsString( "closest('[data-close]')", $block, 'the close button closes the dialog' );
		$this->assertStringContainsString( 'event.target === dialog', $block, 'a click on the backdrop closes it' );
	}

	/**
	 * The body of the anonymous function that starts at `$signature`.
	 *
	 * Slice by the next `\n      };` — the indentation the runtime's inner
	 * functions close on — so an assertion lands inside the function it names
	 * rather than somewhere later in the setup pass.
	 *
	 * @param string $source    The enclosing source.
	 * @param string $signature The function's opening line.
	 */
	private function function_body( string $source, string $signature ): string {
		$start = strpos( $source, $signature );
		$this->assertNotFalse( $start, 'the runtime defines ' . $signature );
		$end = strpos( $source, "\n      };", (int) $start );
		$this->assertNotFalse( $end, $signature . ' terminates' );
		return substr( $source, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * The player is in the Tab order the trap walks.
	 *
	 * `focusablesIn` skipped `<video controls>` before this change, so the trap
	 * treated the close button as the last stop and wrapped straight back to
	 * itself: the player — the one control the dialog exists for — was
	 * reachable by pointer only.
	 */
	public function test_the_trap_counts_the_video_player_as_focusable(): void {
		$source = $this->runtime();

		$this->assertMatchesRegularExpression(
			'/root\.querySelectorAll\(\'[^\']*\bvideo\[controls\]/',
			$source,
			'the shared focus list includes a video with native controls'
		);
	}

	/**
	 * The chrome runtime's source.
	 */
	private function runtime(): string {
		$path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}
}
