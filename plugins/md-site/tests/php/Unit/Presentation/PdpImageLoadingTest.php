<?php
/**
 * The two above-the-fold gallery slots must not be lazy.
 *
 * `/pattern/<slug>/` is 146 pages rendered from one template, and its first
 * screen is the pattern preview. Measured on production 2026-10-29: the plate
 * image carried `loading="lazy"`, so the right-hand panel of the hero stayed
 * blank until the visitor scrolled — a black box roughly 690×760 on every
 * pattern page.
 *
 * The cause was a parameter passed for the wrong thing. `Shortcodes::image()`
 * computed `$decorative = 'hero-bg' === $slot` (is this image decorative, so its
 * alt should be empty) and then passed that boolean into `slot_image()`'s
 * `$eager` parameter. `$decorative` and `$eager` are unrelated facts, and the
 * coincidence that the hero background happens to be decorative hid the error:
 * the hero came out eager for the wrong reason, and the plate — decorative or
 * not — came out lazy.
 *
 * These tests are about the relationship, not the pixels: which slots are above
 * the fold decides the tier, and the alt is decided by whether the image is
 * decorative. A guard that only asserted "there is a loading attribute" would
 * pass the broken version.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Cards;
use MetalDivider\Site\Tests\TestCase;

/**
 * Above-the-fold slots load eagerly; decorative images get an empty alt.
 *
 * @coversNothing
 */
final class PdpImageLoadingTest extends TestCase {

	/**
	 * The shortcode derives the tier from the slot, not from the alt decision.
	 *
	 * This reads the source rather than the output because the bug lived in the
	 * wiring between two correct pieces: `slot_image()` did what it was told, and
	 * `image()` told it the wrong thing. A behavioural test alone would need a
	 * live product; this pins the line that carries the meaning.
	 */
	public function test_the_shortcode_decides_the_tier_by_slot_position(): void {
		$source = (string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			dirname( (string) ( new \ReflectionClass( \MetalDivider\Site\Presentation\Shortcodes::class ) )->getFileName() ) . '/Shortcodes.php'
		);

		$this->assertMatchesRegularExpression(
			'/in_array\(\s*\$slot\s*,\s*array\(\s*[\'"]hero-bg[\'"]\s*,\s*[\'"]plate[\'"]\s*\)/',
			$source,
			'the eager tier is decided by which slots sit above the fold'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/slot_image\(\s*\$id\s*,\s*\$alt\s*,\s*\$decorative\s*,/',
			$source,
			'$decorative decides the alt only; passing it as $eager is the defect'
		);
	}
}
