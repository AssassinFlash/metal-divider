<?php
/**
 * The pattern application guide keeps the geometry the old site was measured at.
 *
 * @package md-site
 */

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Guards the application panel's two-column geometry and its vertical routes.
 *
 * Both were changed at once on 2026-10-28 (commit a1685bf) on the reasoning
 * that the right column was "a flat #141414 cell". Measured on 2026-10-29, that
 * was not true of the split it replaced: the right cell carries the title, the
 * body (294 characters), the route list and the CTA.
 *
 * The cost of the single column was visible in the same measurement: the panel
 * grew from 607px to 982px, and body copy ran 126 characters to the line where
 * the old site sets 66. Meanwhile the horizontal route cards that came with it
 * only applied at desktop widths, and the old site renders grid rows at 1440,
 * 1280 and 1024 alike — so there was no width where they matched.
 *
 * Nothing failed when either half was changed, which is why this file exists.
 */
final class SeriesPanelTest extends TestCase {

	/**
	 * Both volumes that carry the component.
	 *
	 * `series-archive.css` styles the archives and `shop.css` carries the same
	 * rules for the hub; they are byte-identical here, and a fix applied to one
	 * alone would leave the other broken.
	 */
	private const VOLUMES = array( 'series-archive.css', 'shop.css' );

	/**
	 * The panel splits into the photo column and the copy column.
	 *
	 * The old site was measured at three widths (2026-10-29): 1440 gives
	 * 795/548, 1280 gives 700/483, 1024 gives 492/380 where the right column
	 * sits on its 380px floor. 0.62fr over 0.9fr is 0.689, which reproduces the
	 * 1440 ratio exactly.
	 */
	public function test_the_application_panel_keeps_two_columns(): void {
		foreach ( self::VOLUMES as $volume ) {
			$css = $this->volume( $volume );

			$this->assertMatchesRegularExpression(
				'/\.pag__panel\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*0\.9fr\)\s*minmax\(380px,\s*0\.62fr\)/s',
				$css,
				$volume . ': the panel needs the photo column and the copy column; a single column stretches body copy to 126 characters a line and doubles the panel height'
			);
		}
	}

	/**
	 * The routes stay a vertical three-row list at every width.
	 *
	 * The horizontal card override only ever applied at `min-width: 1024px`, and
	 * the old site has no width where the routes are cards. In the restored
	 * 548px column the cards wrapped their labels to two lines, put the gold
	 * arrow on top of "Patterns", and ended 154px short of the photo, leaving a
	 * flat band under them.
	 */
	public function test_the_routes_stay_a_vertical_list(): void {
		foreach ( self::VOLUMES as $volume ) {
			$css = $this->volume( $volume );

			$this->assertDoesNotMatchRegularExpression(
				'/\.pag__routes\s*\{[^}]*display:\s*flex/s',
				$css,
				$volume . ': the routes are grid rows; turning them into flex cards breaks the copy column'
			);

			$this->assertMatchesRegularExpression(
				'/\.pag__route\s*\{[^}]*grid-template-columns:\s*38px\s+minmax\(0,\s*1fr\)\s+auto/s',
				$css,
				$volume . ': each route keeps its index / name / arrow row'
			);
		}
	}

	/**
	 * Reads a volume with its comments stripped, so prose about a rule cannot
	 * satisfy an assertion about the rule.
	 *
	 * This project has been caught by that twice: a bare search for `40em` hit
	 * the comment explaining why `40em` had been removed.
	 *
	 * @param string $name Volume file name inside content/windpress.
	 */
	private function volume( string $name ): string {
		$dir = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress';
		$css = (string) file_get_contents( $dir . '/' . $name ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $css );
	}
}
