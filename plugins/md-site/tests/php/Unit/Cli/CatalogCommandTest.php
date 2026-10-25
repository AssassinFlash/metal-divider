<?php
/**
 * Tests for the one-time series seed command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\CatalogCommand;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for CatalogCommand::seed_series — the administrator gate, the
 * empty-field-only write (an administrator's edit survives a rerun), and
 * the honest report.
 *
 * @covers \MetalDivider\Site\Cli\CatalogCommand
 */
final class CatalogCommandTest extends TestCase {

	/**
	 * Translation, escaping and WordPress's error predicate.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plain_text();
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
	}

	/**
	 * Removes the per-test fixtures.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['md_seed_no_image'], $GLOBALS['md_seed_no_hero_meta'] );
		parent::tearDown();
	}

	/**
	 * A non-administrator is refused before any write.
	 */
	public function test_seed_series_refuses_a_non_administrator(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );

		$this->expectException( ExitException::class );
		( new CatalogCommand() )->seed_series( array(), array() );
	}

	/**
	 * A first run fills both empty fields, image first.
	 */
	public function test_seed_series_fills_the_empty_fields(): void {
		$state = array();
		$this->mock_wordpress( $state );

		$output = $this->run_command();

		$this->assertStringContainsString( 'images 1 seeded / 0 kept', $output );
		$this->assertSame( 1502, $state[18]['thumbnail_id'] ?? null, 'the thumbnail is the resolved attachment id' );
		$this->assertSame( $this->seed_copy(), $state[18]['description'] ?? null );
		$this->assertSame( $this->seed_blurb(), $state[18]['style_blurb'] ?? null, 'the old PDP lead, seeded onto the term' );
	}

	/**
	 * The style blurb is the PDP's first paragraph, so seeding it is what
	 * lets every pattern of a series render the sentence the old page
	 * showed. It obeys the same blank-only rule as the other two fields.
	 */
	public function test_seed_series_leaves_a_blurb_the_administrator_wrote(): void {
		$state = array(
			18 => array(
				'description' => 'Copy.',
				'style_blurb' => 'Editorial the administrator rewrote.',
			),
		);
		$this->mock_wordpress( $state );

		$output = $this->run_command();

		$this->assertStringContainsString( 'style blurbs 0 seeded / 1 kept', $output );
		$this->assertSame( 'Editorial the administrator rewrote.', $state[18]['style_blurb'] );
	}

	/**
	 * The rule the owner ruling demands: a term whose fields already hold
	 * values keeps them, so an administrator's edit — including one they
	 * chose to write differently from the seed — is never reverted.
	 */
	public function test_seed_series_leaves_a_filled_field_alone(): void {
		$state = array(
			18 => array(
				'thumbnail_id' => 1701,
				'description'  => 'Copy the administrator wrote.',
			),
		);
		$this->mock_wordpress( $state );

		$output = $this->run_command();

		$this->assertStringContainsString( 'images 0 seeded / 1 kept', $output );
		$this->assertStringContainsString( 'descriptions 0 seeded / 1 kept', $output );
		$this->assertSame( 1701, $state[18]['thumbnail_id'], 'the administrator’s image stays' );
		$this->assertSame( 'Copy the administrator wrote.', $state[18]['description'], 'the administrator’s copy stays' );
	}

	/**
	 * A rerun after a successful first run writes nothing: the second run
	 * reports both fields kept and the state is byte-identical.
	 */
	public function test_seed_series_is_a_no_op_after_the_first_run(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$this->run_command();
		$after_first = $state;

		$output = $this->run_command();

		$this->assertStringContainsString( '1 already seeded', $output, 'the second run finds the terms marked' );
		$this->assertSame( $after_first, $state, 'a rerun leaves the fields exactly as the first run set them' );
	}

	/**
	 * The seed marks each term it handles, and a marked term is never
	 * touched again — so an administrator who deliberately CLEARS a
	 * card's image or its copy does not find it back on the next run.
	 * Empty-field-only would not be enough here: clearing leaves the
	 * field empty, which is exactly what the seed fills.
	 */
	public function test_seed_series_does_not_refill_a_field_the_administrator_cleared(): void {
		$state = array(
			18 => array(
				'description'       => 'Copy the administrator wrote.',
				// _md_series_seeded: this term was seeded before.
				'_md_series_seeded' => '1',
			),
		);
		$this->mock_wordpress( $state );

		// The administrator now empties both fields to drop the card's
		// image and copy deliberately.
		unset( $state[18]['thumbnail_id'] );
		$state[18]['description'] = '';

		$output = $this->run_command();

		$this->assertStringContainsString( '1 already seeded', $output );
		$this->assertArrayNotHasKey( 'thumbnail_id', $state[18], 'the cleared image stays cleared' );
		$this->assertSame( '', $state[18]['description'], 'the cleared copy stays cleared' );
	}

	/**
	 * The image's fallback is explicit and site-owned: with no
	 * `hero_image` residue to resolve, the seed takes the featured image of
	 * the term's newest published pattern.
	 *
	 * This is the chain that replaced the deleted hardcoded filename table
	 * (`SERIES_HERO_SOURCES`): every step reads the site's own data, and the
	 * order is stated here so a future change has to change this test too.
	 */
	public function test_seed_series_falls_back_to_the_newest_published_pattern_image(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$GLOBALS['md_seed_no_hero_meta'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the test fixture's global, named after the plugin prefix.

		$this->run_command();

		$this->assertSame( 1600, $state[18]['thumbnail_id'] ?? null, 'the newest published pattern’s featured image' );
	}

	/**
	 * A term whose image cannot be resolved is warned about and left with
	 * an empty thumbnail — never pointed at a made-up attachment — while
	 * its description is still seeded.
	 */
	public function test_seed_series_reports_a_term_it_could_not_fill(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$GLOBALS['md_seed_no_image'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the test fixture's global, named after the plugin prefix.

		$output = $this->run_command();

		$this->assertStringContainsString( 'pattern-abstract has no image to seed', $output );
		$this->assertArrayNotHasKey( 'thumbnail_id', $state[18], 'no attachment is invented' );
		$this->assertSame( $this->seed_copy(), $state[18]['description'] ?? null, 'the copy still lands' );
	}

	/**
	 * `--dry-run` writes nothing but reports what it would do.
	 */
	public function test_seed_series_dry_run_writes_nothing(): void {
		$state = array();
		$this->mock_wordpress( $state );

		$output = $this->run_command( array( 'dry-run' => true ) );

		$this->assertStringContainsString( 'would seed pattern-abstract image → attachment 1502', $output );
		$this->assertStringContainsString( 'would seed pattern-abstract description', $output );
		$this->assertSame( array(), $state, 'a dry run writes nothing' );
	}

	/**
	 * Runs the command and returns everything it printed.
	 *
	 * @param array<string, mixed> $assoc_args The command arguments.
	 * @return string The printed output.
	 */
	private function run_command( array $assoc_args = array() ): string {
		ob_start();
		( new CatalogCommand() )->seed_series( array(), $assoc_args );
		return (string) ob_get_clean();
	}

	/**
	 * The description the checked-in seed copy carries for
	 * pattern-abstract, so the assertion reads the file's own text.
	 *
	 * @return string
	 */
	private function seed_copy(): string {
		$path = dirname( __DIR__, 4 ) . '/content/copy/series-descriptions.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$data = json_decode( (string) file_get_contents( $path ), true );
		return (string) ( $data['pattern-abstract'] ?? '' );
	}

	/**
	 * The style blurb the checked-in seed copy carries for
	 * pattern-abstract — the old PDP's design-intent first paragraph.
	 *
	 * @return string
	 */
	private function seed_blurb(): string {
		$path = dirname( __DIR__, 4 ) . '/content/copy/series-style-blurbs.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$data = json_decode( (string) file_get_contents( $path ), true );
		return (string) ( $data['pattern-abstract'] ?? '' );
	}

	/**
	 * The WordPress surface the command drives: one series term whose two
	 * fields start at `$state`, the migrator's `hero_image` URL resolving
	 * to attachment 1502, and the term-meta store the writes land in.
	 *
	 * @param array<int, array<string, mixed>> $state The term fields, by term id.
	 */
	private function mock_wordpress( array &$state ): void {
		WP_Mock::userFunction( 'current_user_can' )->zeroOrMoreTimes()->andReturn( true );
		$term              = new \WP_Term( (object) array() );
		$term->term_id     = 18;
		$term->slug        = 'pattern-abstract';
		$term->name        = 'Abstract';
		$term->description = (string) ( $state[18]['description'] ?? '' );

		WP_Mock::userFunction( 'get_terms' )->zeroOrMoreTimes()->andReturn( array( $term ) );
		WP_Mock::userFunction( 'get_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $key ) use ( &$state ) {
				if ( 'hero_image' === $key ) {
					return empty( $GLOBALS['md_seed_no_hero_meta'] )
						? 'https://site.test/wp-content/uploads/aurum-2048w.webp'
						: '';
				}
				return $state[ $term_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $key, $value ) use ( &$state ) {
				$state[ $term_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'attachment_url_to_postid' )->zeroOrMoreTimes()->andReturnUsing(
			static function (): int {
				return empty( $GLOBALS['md_seed_no_image'] ) ? 1502 : 0;
			}
		);
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function (): array {
				return empty( $GLOBALS['md_seed_no_image'] ) ? array( 7 ) : array();
			}
		);
		WP_Mock::userFunction( 'get_post_thumbnail_id' )->zeroOrMoreTimes()->andReturn( 1600 );
		WP_Mock::userFunction( 'wp_update_term' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $taxonomy, array $args ) use ( &$state ) {
				$state[ $term_id ]['description'] = $args['description'];
				return array();
			}
		);
	}
}
