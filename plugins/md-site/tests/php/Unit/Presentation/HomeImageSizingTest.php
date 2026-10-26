<?php
/**
 * The home face's high-cost image slots stay responsive.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Three slots on the home face dominated the anonymous page weight
 * (baseline 2026-10-26, home ~4.2 MB):
 *
 * - `ext-pp-hero-perforated-facade` and `our-aspen-villa-garden-screen-hero`
 *   are the full-bleed hero backgrounds. They carried a bare `src` at
 *   `…-2048w.webp` — no `srcset`, no `sizes` — so every viewport, down to
 *   the 320px phone, downloaded and decoded a 2048px-wide file
 *   (749 KB and 760 KB).
 * - `our-park-hyatt-mesh-screen-hero` is the first spec-map card. It had a
 *   full five-tier `srcset` but no `sizes`, so the browser fell back to its
 *   `100vw` default: at 1440@2x it asked for 2880px and took the 2048w
 *   tier (943 KB) for a box that renders 326x204, and at 390 it still took
 *   1536w (550 KB).
 *
 * Every tier these slots now name already existed in the final media set
 * (`data/media-final/site/home/`), so the fix is markup only — no
 * re-encode, no new artwork. These tests pin the markup: a future edit that
 * drops the `srcset` or the `sizes` puts the page back to handing huge
 * files to small viewports, and nothing else in the build would notice.
 *
 * The `sizes` for the card is the measured grid, not a guess: the container
 * is `max-w-[1400px] px-5 md:px-10`, the grid is one column below 768, two
 * at 768–1279 and four at 1280+ (`xl:grid-cols-4`), which renders 328px at
 * 1440, 480px at 1024 and 365px at 390.
 *
 * @coversNothing
 */
final class HomeImageSizingTest extends TestCase {

	/**
	 * The home face's source.
	 */
	private function home(): string {
		return (string) file_get_contents( Templates::path( 'home' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The width tiers the final media pipeline produces for these sources.
	 */
	private const TIERS = array( 480, 768, 1024, 1536, 2048 );

	/**
	 * Every `<img>` tag whose `src` names the given asset stem.
	 *
	 * @param string $stem Asset stem, e.g. `ext-pp-hero-perforated-facade`.
	 *
	 * @return list<array<string, string>> one attribute map per matching tag.
	 */
	private function images_naming( string $stem ): array {
		preg_match_all( '/<img\b[^>]*>/i', $this->home(), $tags );
		$found = array();
		foreach ( $tags[0] as $tag ) {
			if ( ! str_contains( $tag, '/' . $stem . '-' ) ) {
				continue;
			}
			preg_match_all( '/([a-z-]+)\s*=\s*"([^"]*)"/i', $tag, $attributes, PREG_SET_ORDER );
			$entry = array();
			foreach ( $attributes as $attribute ) {
				$entry[ strtolower( $attribute[1] ) ] = $attribute[2];
			}
			$found[] = $entry;
		}
		return $found;
	}

	/**
	 * The srcset candidates of one image, as width => file name.
	 *
	 * @param string $srcset The `srcset` attribute.
	 *
	 * @return array<int, string> width => file name.
	 */
	private function candidates( string $srcset ): array {
		$out = array();
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( ! is_array( $parts ) || count( $parts ) !== 2 ) {
				continue;
			}
			if ( preg_match( '/^(\d+)w$/', $parts[1], $width ) ) {
				$out[ (int) $width[1] ] = basename( $parts[0] );
			}
		}
		return $out;
	}

	/**
	 * The two full-bleed hero backgrounds offer the whole tier ladder and
	 * declare the viewport they actually cover.
	 */
	public function test_the_full_bleed_heroes_offer_a_srcset_and_a_viewport_sized_sizes(): void {
		foreach ( array( 'ext-pp-hero-perforated-facade', 'our-aspen-villa-garden-screen-hero' ) as $stem ) {
			$images = $this->images_naming( $stem );
			$this->assertCount( 1, $images, "the home face renders {$stem} once" );
			$image = $images[0];

			$this->assertArrayHasKey( 'srcset', $image, "{$stem} carries a srcset" );
			$widths = array_keys( $this->candidates( $image['srcset'] ) );
			sort( $widths );
			$this->assertSame( self::TIERS, $widths, "{$stem} offers every final tier" );

			$this->assertArrayHasKey( 'sizes', $image, "{$stem} declares its sizes" );
			$this->assertSame( '100vw', trim( $image['sizes'] ), "{$stem} covers the viewport edge to edge" );

			// The bare src is the no-srcset fallback: it must not be the
			// widest tier, which is the file every small viewport used to get.
			$this->assertMatchesRegularExpression( '/-\d+w\.webp$/', $image['src'] );
			$this->assertStringNotContainsString( '-2048w.webp', $image['src'], "{$stem} src is not the 2048 tier" );
		}
	}

	/**
	 * The park card offers the ladder and a `sizes` that describes the grid
	 * cell, not the viewport.
	 */
	public function test_the_park_card_declares_a_sizes_matching_its_grid_cell(): void {
		$images = $this->images_naming( 'our-park-hyatt-mesh-screen-hero' );
		$this->assertCount( 1, $images, 'the home face renders the park card image once' );
		$image = $images[0];

		$this->assertArrayHasKey( 'srcset', $image, 'the park card carries a srcset' );
		$widths = array_keys( $this->candidates( $image['srcset'] ) );
		sort( $widths );
		$this->assertSame( self::TIERS, $widths, 'the park card offers every final tier' );

		$this->assertArrayHasKey( 'sizes', $image, 'the park card declares its sizes' );
		$sizes = $image['sizes'];

		// The defect was the `100vw` default: with no `sizes` the browser
		// measured the card as the whole viewport. The mobile branch is
		// legitimately viewport-width minus the container padding
		// (`calc(100vw - 40px)`), so what must not appear is a bare
		// `100vw` candidate — the default this attribute exists to replace.
		$candidates = array_map( 'trim', explode( ',', $sizes ) );
		$this->assertNotContains( '100vw', $candidates, 'the park card is a grid cell, not the viewport' );

		// The measured grid: 4 columns at 1280+ (a quarter), 2 columns from
		// 768, and a single column below that.
		$this->assertStringContainsString( 'min-width: 768px', $sizes, 'the sizes names the 2-column breakpoint' );
		$this->assertStringContainsString( 'min-width: 1280px', $sizes, 'the sizes names the 4-column breakpoint' );
		$this->assertStringContainsString( '25vw', $sizes, 'the 4-column cell is a quarter of the viewport' );
		$this->assertStringContainsString( '50vw', $sizes, 'the 2-column cell is half of the viewport' );
	}

	/**
	 * The slots name only widths the final media set actually holds.
	 *
	 * A `srcset` naming a tier the pipeline never wrote renders as a broken
	 * candidate, and no other check would report it — the template is the
	 * only place the width list is spelled out.
	 */
	public function test_the_slots_name_only_widths_the_media_set_holds(): void {
		$manifest = dirname( __DIR__, 6 ) . '/data/media-final/site-manifest.json';
		if ( ! is_file( $manifest ) ) {
			$this->markTestSkipped( 'the final media set is built on this Mac only (data/ is not in the repository)' );
		}
		$decoded = json_decode( (string) file_get_contents( $manifest ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local data file in a test.
		$this->assertIsArray( $decoded );

		$built = array();
		foreach ( $decoded['refs'] as $entries ) {
			foreach ( $entries as $entry ) {
				foreach ( array( 'tiers', 'posters' ) as $key ) {
					foreach ( $entry[ $key ] ?? array() as $tier ) {
						// The sidecar spells a site tier `stem--480w.webp`;
						// the importer writes it to uploads (and the template
						// addresses it) as `stem-480w.webp`.
						$built[ (string) preg_replace( '/--(\d+w\.webp)$/', '-$1', basename( (string) $tier['file'] ) ) ] = true;
					}
				}
			}
		}

		$checked = 0;
		foreach ( array( 'ext-pp-hero-perforated-facade', 'our-aspen-villa-garden-screen-hero', 'our-park-hyatt-mesh-screen-hero' ) as $stem ) {
			foreach ( $this->images_naming( $stem ) as $image ) {
				foreach ( array_keys( $this->candidates( (string) ( $image['srcset'] ?? '' ) ) ) as $width ) {
					$name = "{$stem}-{$width}w.webp";
					$this->assertArrayHasKey( $name, $built, "{$name} is a tier the media pipeline produced" );
					++$checked;
				}
			}
		}
		$this->assertSame( 15, $checked, 'the three slots name five tiers each' );
	}
}
