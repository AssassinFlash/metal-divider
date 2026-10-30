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
 * Guards the breadcrumb colours against the bands they are painted on.
 *
 * The site carries two breadcrumb faces. `.breadcrumb__*` is the converted one
 * every page-type template carries; `.clb__*` is the series hub's inline
 * trail. Both sit on dark surfaces now.
 *
 * Every `.clb__*` colour was `#000`, carried over from the white sibling.
 * Measured on production 2026-10-29: the row's backdrop samples at
 * `rgb(18, 18, 18)`, so black text on it is a ratio near 1.1:1 — the labels are
 * effectively invisible, not merely dim.
 *
 * The `.breadcrumb__*` face was the opposite case and is now the same case.
 * Its black text was correct while it really sat on a white band; measured at
 * 1440 on 2026-10-31, that "band" was the browser's default canvas — every
 * ancestor painted `rgba(0,0,0,0)` — and the owner ruled the old site's
 * breadcrumb a compromise to be redesigned (GOAL.md 2026-10-31). The tests
 * below pin the redesign as properties: a dark ground, an eyebrow's voice, a
 * quiet slash, a light variant, and no black ink on any of it.
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
	 * The trail's band is the site's deep charcoal, and it is no longer a white
	 * strip with black text.
	 *
	 * This test used to pin the opposite: `.breadcrumb__*` "really is on a white
	 * band, so black text is correct". The owner reversed that premise on
	 * 2026-10-31 (GOAL.md): the old site's breadcrumb was a compromise, not a
	 * design, and the new site was ordered to make one that belongs to its own
	 * design system. Measured at 1440 before this change: the section chain up
	 * to `<html>` painted nothing at all, so the "white band" was the browser's
	 * default canvas — a white rectangle on an otherwise dark page.
	 *
	 * The guard is stated as the property that has to hold, not as a copy of
	 * the rule: the band paints a dark ground, and no `.breadcrumb__*` rule
	 * declares the black that used to sit on it.
	 */
	public function test_the_trail_band_is_dark_and_carries_no_black_text(): void {
		$css = $this->volume();

		$this->assertMatchesRegularExpression(
			'/\.xo-section\[class\*="__breadcrumb"\]\s*\{[^}]*background-color:\s*var\(--md-bg-deep/s',
			$css,
			'the band paints the site ground; transparent let the white canvas through (measured 2026-10-31)'
		);

		// Every colour on the trail's own face, base and hover alike.
		$found = preg_match_all( '/\.xo-section\[class\*="__breadcrumb"\][^{]*\.breadcrumb__(?:item a|link|list)[^{]*\{([^}]*)\}/s', $css, $blocks );
		$this->assertGreaterThanOrEqual( 3, (int) $found, 'the trail declares its list, item link and link colours' );
		foreach ( $blocks[1] as $block ) {
			if ( ! preg_match_all( '/color:\s*([^;}]+)/', $block, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $value ) {
				$value = strtolower( trim( $value ) );
				$this->assertNotSame( '#000', $value, 'the trail sits on #141414; black there measures about 1.1:1' );
				$this->assertNotSame( 'black', $value, 'the trail sits on #141414; black there measures about 1.1:1' );
			}
		}
	}

	/**
	 * The trail is an eyebrow in the design system's own voice: uppercase
	 * Inter at the small tracked size, a quiet slash between levels, the
	 * current level reading louder than the ones you can click, and gold on
	 * hover — the same words the owner's ruling used (GOAL.md 2026-10-31).
	 */
	public function test_the_trail_reads_as_the_systems_eyebrow(): void {
		$css = $this->volume();

		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__list\s*\{[^}]*text-transform:\s*uppercase/s',
			$css,
			'the trail is an eyebrow: uppercase'
		);
		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__list\s*\{[^}]*letter-spacing:\s*0\.12em/s',
			$css,
			'the eyebrow tracking the site uses elsewhere'
		);
		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__separator::before\s*\{[^}]*content:\s*"\/"/s',
			$css,
			'the separator is a quiet slash, not a chevron icon'
		);
		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__separator svg\s*\{[^}]*display:\s*none/s',
			$css,
			'the converted chevron is retired by the same stylesheet, so fifteen templates need no edit'
		);
		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__item a:hover[^{]*\{[^}]*color:\s*#c9a962/s',
			$css,
			'hover goes to the single gold'
		);
		$this->assertMatchesRegularExpression(
			'/\.breadcrumb__item:last-child a[^{]*\{[^}]*color:\s*rgba\(\s*255,\s*255,\s*255,\s*0?\.9\s*\)/s',
			$css,
			'the current level is the brightest step of the trail'
		);
	}

	/**
	 * There is a light variant of the same object, for a face that ever sits on
	 * a pale panel: same metrics, the gold's dark form for the ink (the site's
	 * own `--md-gold-ink` measure), so the shape survives the surface change.
	 */
	public function test_the_trail_has_a_light_variant(): void {
		$css = $this->volume();

		$this->assertStringContainsString( '.md-breadcrumb--light', $css, 'the light variant is declared' );
		$this->assertMatchesRegularExpression(
			'/\.md-breadcrumb--light[^{]*\{[^}]*color:\s*rgba\(\s*20,\s*20,\s*20/s',
			$css,
			'the light variant inks the trail in deep charcoal'
		);
		$this->assertMatchesRegularExpression(
			'/\.md-breadcrumb--light[^{]*:hover[^{]*\{[^}]*color:\s*#85692e/s',
			$css,
			'the light hover is the dark gold, past the 4.5:1 floor on a pale panel'
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
