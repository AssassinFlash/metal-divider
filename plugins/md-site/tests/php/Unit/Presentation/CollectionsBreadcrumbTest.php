<?php
/**
 * The collections breadcrumb is legible on the surface it sits on.
 *
 * @package MetalDivider\Site\Tests\Unit\Presentation
 */

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Guards the `.clb__*` colours against the band they are painted on.
 *
 * The site carries two breadcrumbs. `.breadcrumb__*` sits on a white band (spec
 * #65) and is correctly black. `.clb__*` is the collections list breadcrumb and
 * sits on the dark page surface.
 *
 * Every `.clb__*` colour was `#000`, carried over from the white sibling.
 * Measured on production 2026-10-29: the row's backdrop samples at
 * `rgb(18, 18, 18)`, so black text on it is a ratio near 1.1:1 — the labels are
 * effectively invisible, not merely dim.
 *
 * The old site has no `.clb__*` markup at all (`grep -c 'clb__'` on
 * `metalpartition.com/collections/all` returns 0), so there is no replica value
 * to match and no ambiguity about what "correct" means: the text has to be
 * readable on the surface it is on.
 */
final class CollectionsBreadcrumbTest extends TestCase {

	/**
	 * No `.clb__*` rule paints text pure black.
	 *
	 * A literal `#000` (or `black`) cannot be legible on `rgb(18,18,18)`
	 * whatever the surrounding context, so the assertion needs no luminance
	 * maths to be sound.
	 */
	public function test_the_dark_breadcrumb_has_no_black_text(): void {
		$css = $this->volume();

		foreach ( array( 'clb__list', 'clb__link', 'clb__current', 'clb__sep' ) as $selector ) {
			// Pull the declaration out and compare the value, rather than using a
			// negative lookahead: `color:\s*(?!\#000)` matches from a position
			// where the lookahead succeeds by matching nothing, so the first
			// version of this test passed on the broken CSS too (measured
			// 2026-10-29 — it stayed green with `color: #000` still in place).
			// Every block for this selector, not just the first: `.clb__link` has a
			// base rule and a `:hover` rule, and reading only the first one made
			// this test look at the wrong declaration (measured 2026-10-29).
			$found = preg_match_all( '/' . preg_quote( '.' . $selector, '/' ) . '[^{]*\{([^}]*)\}/s', $css, $blocks );
			$this->assertGreaterThanOrEqual( 1, (int) $found, '.' . $selector . ' must have a rule' );
			$values = array();
			foreach ( $blocks[1] as $block ) {
				if ( preg_match_all( '/color:\s*([^;}]+)/', $block, $m ) ) {
					foreach ( $m[1] as $value ) {
						$values[] = strtolower( trim( $value ) );
					}
				}
			}
			$this->assertNotEmpty( $values, '.' . $selector . ' must declare a colour' );
			foreach ( $values as $value ) {
				$this->assertNotSame( '#000', $value, '.' . $selector . ' sits on rgb(18,18,18); #000 there measures about 1.1:1' );
				$this->assertNotSame( 'black', $value, '.' . $selector . ' sits on rgb(18,18,18); black there measures about 1.1:1' );
			}
		}
	}

	/**
	 * The white breadcrumb keeps its black text.
	 *
	 * This is the half that makes the fix narrow: `.breadcrumb__*` really is on
	 * a white band, so changing it would be the bug rather than the fix.
	 */
	public function test_the_white_breadcrumb_still_uses_black_text(): void {
		$css = $this->volume();

		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__link[^{]*\{[^}]*color:\s*#000/s',
			$css,
			'the white band breadcrumb must stay black; making it white would erase it'
		);
	}

	/**
	 * Reads the breadcrumb volume with comments stripped.
	 *
	 * The comment added beside the fix quotes both `#000` and `rgb(18,18,18)`;
	 * a search that did not strip comments would match its own explanation.
	 */
	private function volume(): string {
		$dir = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress';
		$css = (string) file_get_contents( $dir . '/breadcrumb.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $css );
	}
}
