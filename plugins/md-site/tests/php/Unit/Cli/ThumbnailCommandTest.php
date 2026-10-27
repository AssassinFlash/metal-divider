<?php
/**
 * Tests for the featured-image retarget command's decision rules.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\ThumbnailCommand;
use MetalDivider\Site\Tests\TestCase;
use ReflectionClass;

/**
 * Tests for the two rules in ThumbnailCommand that a wrong implementation
 * would silently break: which media class a role maps to, and which role
 * counts as a photograph to move the featured image onto.
 *
 * ## What is deliberately not tested here
 *
 * `is_usable_photograph()` decides by decoding the file and sampling its
 * alpha and luminance. A unit test can only drive that through either a real
 * image fixture or a faked decoder, and a faked decoder proves nothing about
 * whether a WebP mask is recognised. So the pixel rule is verified on the
 * real catalogue instead, and the numbers are recorded — with the command
 * that produced them — in
 * docs/acceptance/2026-10-28-pattern-thumbnail-legibility.md:
 * 94 patterns measured max luminance 1-11 before, and the same 24 cards
 * measured zero below 20 after.
 *
 * The rules below are the ones that decide WHICH attachment, and they are
 * pure lookups, so they can be pinned exactly.
 *
 * @covers \MetalDivider\Site\Cli\ThumbnailCommand
 */
final class ThumbnailCommandTest extends TestCase {

	/**
	 * The command under test, with its private maps exposed.
	 *
	 * @return array<string, mixed>
	 */
	private function constants(): array {
		$reflection = new ReflectionClass( ThumbnailCommand::class );
		$out        = array();
		foreach ( array( 'PHOTO_ROLES', 'ROLE_CLASSES' ) as $name ) {
			$out[ $name ] = $reflection->getConstant( $name );
		}
		return $out;
	}

	/**
	 * `finish preview` is the defect this command repairs, and nothing else is.
	 *
	 * The command only acts on a featured image whose media class is `finish`.
	 * If `finish preview` stopped mapping to `finish`, the command would go
	 * silently inert — every product would be "skipped" and the run would
	 * report success while changing nothing. That is the failure this pins.
	 */
	public function test_finish_preview_maps_to_the_finish_class(): void {
		$maps = $this->constants();

		$this->assertArrayHasKey( 'finish preview', $maps['ROLE_CLASSES'] );
		$this->assertSame( 'finish', $maps['ROLE_CLASSES']['finish preview'] );
	}

	/**
	 * The four installation-scene phrasings the migrator emits all count as
	 * photographs, and accent renders never do.
	 *
	 * A render is a drawing of the pattern — the same defect in a different
	 * file — so putting one in the featured image would look repaired while
	 * leaving the card just as flat. This test fails if a render is ever added
	 * to the photo list.
	 */
	public function test_every_installation_role_is_a_photograph_and_renders_are_not(): void {
		$roles = $this->constants()['PHOTO_ROLES'];

		foreach (
			array(
				'installed screen scene',
				'local installation scene',
				'official installation scene',
				'project installation',
			) as $scene
		) {
			$this->assertContains( $scene, $roles, $scene . ' names a photograph of a screen in place' );
		}

		foreach ( array( 'accent render', 'pattern render', 'finish preview', 'cut sheet' ) as $not_a_photo ) {
			$this->assertNotContains(
				$not_a_photo,
				$roles,
				$not_a_photo . ' is a drawing or a mask, not a photograph'
			);
		}
	}

	/**
	 * The photo roles are ordered, and the order is the preference.
	 *
	 * `pick_photograph()` takes the lowest rank. An unordered list would make
	 * the choice depend on media order instead of on the migrator's own
	 * vocabulary, which is the whole point of ranking it.
	 */
	public function test_the_photo_roles_are_ordered_most_specific_first(): void {
		$roles = $this->constants()['PHOTO_ROLES'];

		$this->assertSame(
			'installed screen scene',
			$roles[0],
			'a named installed screen is the strongest claim a media item can make'
		);
		$this->assertSame(
			array_values( array_unique( $roles ) ),
			$roles,
			'the list must not repeat a role — a repeat would silently shift every rank after it'
		);
	}
}
