<?php
/**
 * The header opens with a skip link whose target the theme skeleton actually renders.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Keyboard measurement on production 2026-10-29: thirty Tab presses never
 * reached `<main>` on `/` or `/shop/`, and a search for `skip-link` /
 * `skip to content` on `/`, `/shop/`, `/inquiry/` and `/faqs/` returned zero.
 * The old site's own skip link reads "Skip to content".
 *
 * The theme skeleton (picostrap `header.php`) already opens
 * `<main id='theme-main'>` after `lc_custom_header()`, which wraps this
 * partial in `<header id='lc-header'>`. One link at the start of the shared
 * header therefore covers every face, including home, whose smaller nav count
 * is page-level `<nav>` elements, not a second header.
 *
 * @coversNothing
 */
final class SkipLinkTest extends TestCase {

	/**
	 * The shared header carries one skip link, and it is the first focusable control.
	 *
	 * A link later in the header, or one that exists only inside an HTML comment,
	 * does not shorten the tab walk. The wording is the old site's, not a rewrite.
	 */
	public function test_the_header_opens_with_a_skip_link(): void {

		$header = $this->header();
		$link   = $this->skip_link( $header );

		$this->assertSame( 1, substr_count( $header, 'md-skip-link' ), 'exactly one skip link, not a copy in the drawer' );
		$this->assertSame( 1, substr_count( $header, '>Skip to content<' ), 'the visible name is the old site\'s "Skip to content"' );
		$this->assertMatchesRegularExpression(
			'/\\bclass=[\'"][^\'"]*\\bmd-skip-link\\b/',
			$link['attrs'],
			'the skip link carries the class the volume styles'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\\btabindex=[\'"]-/',
			$link['attrs'],
			'a negative tabindex takes the skip link out of the tab order'
		);
		$this->assertSame(
			'theme-main',
			$link['id'],
			'the link must target #theme-main, the id the theme skeleton renders on <main>'
		);
		$this->assertSame(
			$link['offset'],
			$this->first_focusable_offset( $header ),
			'the skip link must be the first focusable element in the header'
		);
	}

	/**
	 * Until focused the link is clipped out of view; focused, it pins inside the viewport.
	 *
	 * `display: none` would remove it from the tab order, which is the defect.
	 * The reveal has to be top-level: a phone-only block would leave every wider
	 * width without it. px, not rem, because the carried 62.5% root renders a
	 * rem figure at 0.625×.
	 */
	public function test_the_skip_link_is_clipped_until_focused_and_then_lands_in_the_viewport(): void {

		$css   = $this->css();
		$clean = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

		$rest = strpos( $clean, '.md-skip-link{' );
		$this->assertNotFalse( $rest, 'chrome.css must hide .md-skip-link; a new stylesheet is not allowed' );
		$this->assertSame( 0, $this->depth_at( $clean, (int) $rest ), '.md-skip-link must not be buried in a media query' );
		$rest_body = $this->rule_body( $clean, (int) $rest );
		$this->assertStringContainsString( 'clip: rect(0, 0, 0, 0)', $rest_body, 'the resting link must be clipped out of view' );
		$this->assertStringContainsString( 'clip-path: inset(50%)', $rest_body );
		$this->assertStringNotContainsString( 'display: none', $rest_body, 'display:none removes the link from the tab order' );
		$this->assertStringNotContainsString( 'position: fixed', $rest_body, 'the resting link must not paint in the viewport' );
		$this->assertDoesNotMatchRegularExpression( '/\\d+(\\.\\d+)?rem\\b/', $rest_body, 'a rem length renders at 0.625× under the carried root' );

		$focus = strpos( $clean, '.md-skip-link:focus' );
		$this->assertNotFalse( $focus, 'the focused skip link needs its own rule' );
		$this->assertSame( 0, $this->depth_at( $clean, (int) $focus ), 'the focus reveal must hold at every width' );
		$focus_body = $this->rule_body( $clean, (int) $focus );
		$this->assertStringContainsString( 'position: fixed', $focus_body, 'focus must pin the link to the viewport, not to a scrolled ancestor' );
		$this->assertStringContainsString( 'top: 8px', $focus_body, 'the focused link must sit inside the viewport' );
		$this->assertStringContainsString( 'left: 8px', $focus_body, 'the focused link must sit inside the viewport' );
		$this->assertStringContainsString( 'z-index: 200', $focus_body, 'the link must paint above the sticky band (100) and below the modals (1000)' );
		$this->assertStringContainsString( 'clip: auto', $focus_body, 'focus must undo the clip or the link stays invisible' );
		$this->assertStringContainsString( 'clip-path: none', $focus_body );
		$this->assertStringNotContainsString( 'display: none', $focus_body );
		$this->assertDoesNotMatchRegularExpression( '/\\d+(\\.\\d+)?rem\\b/', $focus_body, 'a rem length renders at 0.625× under the carried root' );
	}

	/**
	 * The href fragment is an id the theme skeleton actually renders.
	 *
	 * Picostrap's `header.php` is not in this checkout. The local instance and
	 * an installed `wp-content/themes/picostrap5/header.php` are the rendered
	 * skeleton; CI has neither, and skipping there is the same rule the other
	 * instance-only assertions use. On a machine that has the file, a made-up
	 * fragment fails this test.
	 */
	public function test_the_skip_target_is_an_id_in_the_theme_skeleton(): void {
		$path = $this->skeleton_path();
		if ( null === $path ) {
			$this->markTestSkipped( 'picostrap header.php is not on this machine; the skeleton id cannot be read' );
		}

		$header   = $this->header();
		$link     = $this->skip_link( $header );
		$skeleton = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the theme skeleton, read in a test.

		$this->assertMatchesRegularExpression(
			'/<main\\b[^>]*\\bid=[\'"]' . preg_quote( $link['id'], '/' ) . '[\'"]/i',
			$skeleton,
			'#' . $link['id'] . ' is not the id of <main> in ' . $path
		);
	}

	/**
	 * Header partial with HTML comments removed, so a comment cannot satisfy the search.
	 */
	private function header(): string {
		$html = (string) file_get_contents( Templates::path( 'partials/header.html', true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		return (string) preg_replace( '/<!--.*?-->/s', '', $html );
	}

	/**
	 * The chrome volume the skip link is styled in.
	 */
	private function css(): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress/chrome.css';
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The one "Skip to content" anchor, or a failure that names the miss.
	 *
	 * @param string $html Comment-stripped header.
	 * @return array{attrs: string, offset: int, id: string}
	 */
	private function skip_link( string $html ): array {
		$found = preg_match( '/<a\\b([^>]*)>\\s*Skip to content\\s*<\\/a>/', $html, $match, PREG_OFFSET_CAPTURE );
		$this->assertSame( 1, $found, 'the header has no "Skip to content" link' );
		$attrs = $match[1][0];
		if ( 1 !== preg_match( '/\\bhref=[\'"]#([^\'"]+)[\'"]/', $attrs, $href ) ) {
			$this->fail( 'the skip link href must be a same-page fragment' );
		}

		return array(
			'attrs'  => $attrs,
			'offset' => $match[0][1],
			'id'     => $href[1],
		);
	}

	/**
	 * Offset of the first native focusable start tag.
	 *
	 * Custom elements are not in the tab order unless they carry a tabindex.
	 * A negative tabindex is not focusable and must not count.
	 *
	 * @param string $html Comment-stripped header.
	 */
	private function first_focusable_offset( string $html ): int {
		$found = preg_match(
			'/<(?:a|button|input|select|textarea)\\b|tabindex=[\'"](?!-)\\d/i',
			$html,
			$match,
			PREG_OFFSET_CAPTURE
		);
		$this->assertSame( 1, $found, 'the header has no focusable element' );
		return $match[0][1];
	}

	/**
	 * Declarations of the rule that opens at `$offset`, up to its closing brace.
	 *
	 * @param string $css    Comment-stripped stylesheet.
	 * @param int    $offset Position of the selector.
	 */
	private function rule_body( string $css, int $offset ): string {
		$open = strpos( $css, '{', $offset );
		$this->assertNotFalse( $open, 'the skip-link selector must open a rule' );
		$close = strpos( $css, '}', (int) $open );
		$this->assertNotFalse( $close, 'the skip-link rule must be closed' );
		return substr( $css, (int) $open, (int) $close - (int) $open );
	}

	/**
	 * Brace depth at `$offset`. Zero means the rule is not inside a media query.
	 *
	 * @param string $css    Comment-stripped stylesheet.
	 * @param int    $offset The position to measure.
	 */
	private function depth_at( string $css, int $offset ): int {
		$length = min( $offset, strlen( $css ) );
		$opens  = substr_count( substr( $css, 0, $length ), '{' );
		$closes = substr_count( substr( $css, 0, $length ), '}' );
		return max( 0, $opens - $closes );
	}

	/**
	 * Picostrap's header.php, the file that prints `<main id='theme-main'>`.
	 *
	 * Tried in the local instance first, then beside an installed plugin
	 * (`wp-content/plugins/md-site` → `wp-content/themes/picostrap5`).
	 */
	private function skeleton_path(): ?string {
		$home       = getenv( 'MD_LOCAL_HOME' );
		$root       = ( false === $home || '' === $home ) ? getenv( 'HOME' ) . '/metal-divider-local' : $home;
		$plugin     = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName(), 3 );
		$candidates = array(
			$root . '/dev/wordpress/wp-content/themes/picostrap5/header.php',
			dirname( $plugin, 2 ) . '/themes/picostrap5/header.php',
		);
		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		return null;
	}
	/**
	 * The link must move the reader, not only the viewport.
	 *
	 * Measured with a real keyboard on production 2026-10-29: Tab reached the
	 * link, Enter fired, and document.activeElement was still the link; the next
	 * Tab went to the header logo. A skip link that leaves focus where it was has
	 * not skipped anything.
	 *
	 * The behaviour lives in md-chrome.js and must: move focus to the target,
	 * make the target focusable first (it is <main>, tabIndex -1), and keep the
	 * URL fragment so the back button still works.
	 */
	public function test_the_skip_link_moves_focus_into_the_target(): void {
		$js = $this->chrome_js();

		$this->assertStringContainsString( 'function setupSkipLink', $js, 'the focus behaviour exists' );
		$this->assertStringContainsString( 'setupSkipLink();', $js, 'and it is called from start()' );
		$this->assertStringContainsString(
			"setAttribute('tabindex', '-1')",
			$js,
			'the target is made focusable; <main> is not focusable by default'
		);
		$this->assertMatchesRegularExpression(
			'/target\.focus\(/',
			$js,
			'focus actually moves to the target'
		);
		$this->assertMatchesRegularExpression(
			'/history\.pushState/',
			$js,
			'the fragment is kept without a full navigation'
		);
		// preventDefault plus a manual jump must not lose the URL fragment; the
		// pair below is what keeps the back button working.
		$this->assertMatchesRegularExpression(
			'/preventDefault\(\)/',
			$js,
			'the default jump is replaced, not duplicated'
		);
	}

	/**
	 * The behaviour file.
	 */
	private function chrome_js(): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../assets/js/md-chrome.js';
		$this->assertFileExists( $path, 'the behaviour file is part of the plugin' );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}
}
