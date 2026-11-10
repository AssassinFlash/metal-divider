<?php
/**
 * The inquiry stylesheet's part of the form's contract.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Tests\TestCase;

/**
 * Form.php renders semantic markup and zero styles (AGENTS.md §5); the
 * WindPress volume `md-site/content/windpress/inquiry.css` owns the look.
 * These are the rules that decide whether the form reads as a form: the
 * empty Turnstile slot must not draw a box, the fields must share one right
 * edge in the two-column cards, and the submit must stay visibly gold while
 * the challenge has not unlocked it.
 *
 * The test lives here because these three regressions are all in this
 * plugin's output; the stylesheet is where its classes land.
 *
 * @coversNothing
 */
final class InquiryStylesheetTest extends TestCase {

	/**
	 * The volume's text.
	 *
	 * @var string
	 */
	private string $css = '';

	/**
	 * Reads the volume once per test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$path = \dirname( __DIR__, 4 ) . '/md-site/content/windpress/inquiry.css';
		$this->assertFileExists( $path, 'the volume that styles this plugin\'s classes is missing' );
		$this->css = (string) \file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The Turnstile slot sizes to its content and an empty slot disappears.
	 *
	 * Interaction-only leaves the slot empty until the challenge needs an
	 * interaction. The old dashed 65px box therefore drew a full-width empty
	 * rectangle under the drawing hint, which read as a broken upload target.
	 */
	public function test_the_empty_turnstile_slot_draws_nothing(): void {
		$base = $this->rule( '.md-inquiry .md-inquiry-turnstile' );

		$this->assertNotSame( '', $base );
		$this->assertStringContainsString( 'border: 0;', $base );
		$this->assertStringContainsString( 'padding: 0;', $base );
		$this->assertStringContainsString( 'min-height: 0;', $base );
		$this->assertStringContainsString( 'margin: 12px 0;', $base );
		$this->assertStringNotContainsString( 'dashed', $base, 'the dashed frame was the broken-upload look' );
		$this->assertStringNotContainsString( 'min-height: 65px', $base, 'a bare slot must not reserve 65px' );
		$this->assertStringNotContainsString( 'border-radius', $base );

		$empty = $this->rule( '.md-inquiry .md-inquiry-turnstile:empty' );
		$this->assertNotSame( '', $empty );
		$this->assertStringContainsString( 'display: none;', $empty );
		$this->assertStringContainsString( 'margin: 0;', $empty );

		// turnstile.render() will not build its iframe in a display:none
		// container, so the behavior file adds a 1px mount surface for the
		// duration of the call. It must beat :empty while both apply.
		$mount = $this->rule( '.md-inquiry .md-inquiry-turnstile.md-inquiry-turnstile--mounting' );
		$this->assertNotSame( '', $mount, 'the Turnstile mount surface is missing' );
		$this->assertStringContainsString( 'display: flex;', $mount );
		$this->assertStringContainsString( 'min-height: 1px;', $mount );
		$this->assertGreaterThan(
			\strpos( $this->css, '.md-inquiry .md-inquiry-turnstile:empty' ),
			\strpos( $this->css, '.md-inquiry .md-inquiry-turnstile.md-inquiry-turnstile--mounting' ),
			'the mount rule must come after :empty to win the equal-specificity tie'
		);
	}

	/**
	 * The 320px breakout still gives Cloudflare's 300px frame room.
	 *
	 * The base rule losing its border and padding must not remove the one
	 * place the frame would otherwise be cropped by the 230px column.
	 */
	public function test_the_320_turnstile_breakout_survives(): void {
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 389px\)[\s\S]*?#md-inquiry \.md-inquiry-turnstile \{[^}]*width: 300px/',
			$this->css
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 389px\)[\s\S]*?margin-left: calc\(\(100% - 300px\) \/ 2\)/',
			$this->css
		);
	}

	/**
	 * The submit is gold and near-black even while it is disabled.
	 */
	public function test_the_submit_keeps_its_gold_in_the_disabled_state(): void {
		$base = $this->rule( '.md-inquiry .md-inquiry-submit' );
		$this->assertStringContainsString( 'background: #d4b475;', $base );
		$this->assertStringContainsString( 'border: 1px solid #d4b475;', $base );
		$this->assertStringContainsString( 'color: #111;', $base );

		$disabled = $this->rule( '.md-inquiry .md-inquiry-submit:disabled' );
		$this->assertStringContainsString( 'opacity: 0.72;', $disabled );
		$this->assertStringContainsString( 'border-color: rgba(212, 180, 117, 0.55);', $disabled );
		$this->assertStringContainsString( 'background: #d4b475;', $disabled, 'the disabled state is a softer gold, not a grey block' );
		$this->assertStringContainsString( 'color: #111;', $disabled );
	}

	/**
	 * The two-column rows share one right edge.
	 *
	 * The page volumes put Name and Email in a row and left Phone in one half
	 * cell; the optional block and the note were not in their full-row lists
	 * either, so both stopped halfway across the card. The rules must span
	 * those rows and pin the textarea's width to the column, and they must be
	 * scoped strongly enough to beat the 0-3-0 page rules without !important.
	 */
	public function test_the_full_row_fields_span_both_columns(): void {
		$selectors = array(
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-field--phone,',
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-field--message,',
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-optional,',
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-promise,',
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-note,',
			'.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-notice',
		);
		foreach ( $selectors as $selector ) {
			$this->assertStringContainsString( $selector, $this->css, 'a full-row field is missing from the span rule: ' . $selector );
		}

		$this->assertMatchesRegularExpression(
			'/\.md-inquiry \.md-inquiry-form \.md-inquiry-fields > \.md-inquiry-field--phone,[\s\S]*?\{[^}]*grid-column: 1 \/ -1;[^}]*max-width: none;[^}]*width: 100%;/',
			$this->css
		);

		$textarea = $this->rule( '.md-inquiry .md-inquiry-form .md-inquiry-fields > .md-inquiry-field--message .md-inquiry-field__input' );
		$this->assertStringContainsString( 'max-width: none;', $textarea );
		$this->assertStringContainsString( 'width: 100%;', $textarea );

		$this->assertMatchesRegularExpression(
			'/\.md-inquiry \.md-inquiry-pattern__code\[hidden\] \{[^}]*display: none !important;/',
			$this->css,
			'the code row the behavior file hides must actually collapse'
		);
	}

	/**
	 * The declarations between one selector's braces.
	 *
	 * @param string $selector The selector, as written in the volume.
	 * @return string The rule body, or '' when the selector is absent.
	 */
	private function rule( string $selector ): string {
		$pattern = '/' . \preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/';
		if ( 1 !== \preg_match( $pattern, $this->css, $matches ) ) {
			return '';
		}
		return $matches[1];
	}
}
