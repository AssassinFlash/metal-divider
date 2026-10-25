<?php
/**
 * Tests for the one-time tile-order seed command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\TileOrderCommand;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for TileOrderCommand::seed_tiles.
 *
 * The command exists to give a clean target the custom-screens page's
 * eighteen curated tiles, whose curation is WooCommerce's own `menu_order`.
 * The three behaviours below are the whole argument for it being safe to run
 * from a deploy, and each fails under a plausible wrong implementation:
 *
 * - a clean target gets all eighteen, in the page's own order (fails if the
 *   seed set or the per-panel positions are wrong);
 * - a second run writes nothing at all (fails if idempotency is value-based
 *   rather than marker-based);
 * - a tile the administrator REMOVED stays removed (fails if the command
 *   keys on `menu_order > 0`, which is indistinguishable from never seeded —
 *   the exact defect this marker was added to close).
 *
 * @covers \MetalDivider\Site\Cli\TileOrderCommand
 */
final class TileOrderCommandTest extends TestCase {

	/**
	 * The number of write batches the command has issued.
	 *
	 * @var int
	 */
	private static int $writes = 0;

	/**
	 * The SKU map installed into the WooCommerce double, or null for none.
	 *
	 * @var array<string, int>|null
	 */
	public static ?array $skus = null;

	/**
	 * The eighteen seeded products, by SKU, mapped to the id the fixture
	 * assigns them.
	 */
	private const IDS = array(
		'Aztec'                            => 51,
		'Athelstan'                        => 48,
		'The Davinci'                      => 58,
		'Geo Criss Cross'                  => 62,
		'Painted Daisy'                    => 116,
		'Indian Nights Laser Cut Screens'  => 65,
		'Alhambra'                         => 47,
		'Lace Circles Laser Cut'           => 69,
		'Moroccan Love'                    => 77,
		'Autumn Leaves'                    => 50,
		'Bamboo'                           => 53,
		'Leaf Vines Laser Cut'             => 71,
		'Leaf Skeleton Laser Cut'          => 70,
		'Atherton'                         => 49,
		'Chase Blossom Decorative Screens' => 56,
		'Breeze Blocks'                    => 55,
		'Funk Divider'                     => 61,
		'Long Island'                      => 75,
	);

	/**
	 * Clears the shared WooCommerce double between tests.
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
		self::$skus = null;
		parent::tearDown();
	}

	/**
	 * A non-administrator is refused before any write.
	 */
	public function test_seed_tiles_refuses_a_non_administrator(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );

		$this->expectException( ExitException::class );
		( new TileOrderCommand() )->seed_tiles( array(), array() );
	}

	/**
	 * A clean target gets all eighteen tiles, each at its position in the
	 * page's own panel order, and every one is marked.
	 */
	public function test_a_clean_target_gets_the_eighteen_tiles_in_order(): void {
		$state = array();
		$this->mock_wordpress( $state );

		$this->run_command();

		$this->assertCount( 18, $state, 'all eighteen curated products were written' );

		// The page's order, per panel: 1..6 geometric, 1..3 moroccan,
		// 1..6 organic, 1..3 vintage. menu_order restarts per panel because
		// each panel renders its own series and only reads its own members.
		$expected = array(
			'Aztec'                            => 1,
			'Athelstan'                        => 2,
			'The Davinci'                      => 3,
			'Geo Criss Cross'                  => 4,
			'Painted Daisy'                    => 5,
			'Indian Nights Laser Cut Screens'  => 6,
			'Alhambra'                         => 1,
			'Lace Circles Laser Cut'           => 2,
			'Moroccan Love'                    => 3,
			'Autumn Leaves'                    => 1,
			'Bamboo'                           => 2,
			'Leaf Vines Laser Cut'             => 3,
			'Leaf Skeleton Laser Cut'          => 4,
			'Atherton'                         => 5,
			'Chase Blossom Decorative Screens' => 6,
			'Breeze Blocks'                    => 1,
			'Funk Divider'                     => 2,
			'Long Island'                      => 3,
		);
		foreach ( $expected as $sku => $position ) {
			$id = self::IDS[ $sku ];
			$this->assertSame( $position, $state[ $id ]['menu_order'] ?? null, "{$sku} position" );
			$this->assertSame( '1', $state[ $id ]['_md_tiles_seeded'] ?? null, "{$sku} marked" );
		}
	}

	/**
	 * The eighteen are exactly the page's curated set — no Kasbah, and
	 * Atherton rather than "Atherton Leaves".
	 */
	public function test_the_seed_set_is_the_pages_curated_set(): void {
		$state = array();
		$this->mock_wordpress( $state );

		$this->run_command();

		$this->assertArrayNotHasKey( 'Kasbah', self::IDS, 'the phantom tile is not seeded' );
		$this->assertArrayHasKey( 'Atherton', self::IDS, 'the real product is seeded' );
		$this->assertArrayNotHasKey( 'Atherton Leaves', self::IDS );
	}

	/**
	 * A second run writes nothing: every product already carries the current
	 * revision, so the command is a no-op — which is what lets a deploy run
	 * it on every batch.
	 */
	public function test_a_second_run_writes_nothing(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$command = new TileOrderCommand();

		$command->seed_tiles( array(), array() );
		$after_first  = $state;
		self::$writes = 0;

		$command->seed_tiles( array(), array() );

		$this->assertSame( $after_first, $state, 'not one menu_order or marker moved' );
		$this->assertSame( 0, self::$writes, 'no write was issued at all' );
	}

	/**
	 * The defect this marker closes: a tile the administrator removed — by
	 * clearing its menu_order back to 0 — is NOT restored by a later seed.
	 *
	 * Keying on `menu_order > 0` alone cannot tell "removed" from "never
	 * seeded", so this test fails under that implementation and passes only
	 * with the revision marker.
	 */
	public function test_an_intentionally_removed_tile_is_not_restored(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$command = new TileOrderCommand();

		$command->seed_tiles( array(), array() );

		// The operator removes two tiles: one from the middle of a panel and
		// one at its end, the two positions most likely to shift neighbours.
		$removed = array( 'Bamboo', 'Long Island' );
		foreach ( $removed as $sku ) {
			$state[ self::IDS[ $sku ] ]['menu_order'] = 0;
		}
		self::$writes = 0;

		$command->seed_tiles( array(), array() );

		foreach ( $removed as $sku ) {
			$this->assertSame(
				0,
				$state[ self::IDS[ $sku ] ]['menu_order'],
				"{$sku} stays removed: the marker, not the value, ends the seed's interest"
			);
		}
		$this->assertSame( 0, self::$writes, 'the removed tiles were not written' );
	}

	/**
	 * A tile the administrator reordered keeps the order they chose.
	 */
	public function test_a_reordered_tile_keeps_the_administrators_order(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$command = new TileOrderCommand();

		$command->seed_tiles( array(), array() );
		$state[ self::IDS['Long Island'] ]['menu_order'] = 99;

		$command->seed_tiles( array(), array() );

		$this->assertSame( 99, $state[ self::IDS['Long Island'] ]['menu_order'] );
	}

	/**
	 * A product the owner had already ordered before the first seeded run is
	 * not moved, and is marked so later runs leave it alone too.
	 */
	public function test_a_pre_ordered_product_is_not_moved(): void {
		$state = array();
		$this->mock_wordpress( $state );
		$state[ self::IDS['Aztec'] ]['menu_order'] = 42;

		$this->run_command();

		$this->assertSame( 42, $state[ self::IDS['Aztec'] ]['menu_order'], 'the owner set this first' );
		$this->assertSame( '1', $state[ self::IDS['Aztec'] ]['_md_tiles_seeded'] ?? null, 'and it is marked' );
	}

	/**
	 * A dry run writes nothing — no menu_order, and no markers either, so a
	 * dry run cannot silently claim a product for the seed.
	 */
	public function test_a_dry_run_writes_nothing(): void {
		$state = array();
		$this->mock_wordpress( $state );

		$output = $this->run_command( array( 'dry-run' => true ) );

		$this->assertSame( array(), $state, 'no menu_order and no marker' );
		$this->assertSame( 0, self::$writes );
		$this->assertStringContainsString( '18 to write', $output );
	}

	/**
	 * A missing product is reported rather than invented, and the command
	 * fails so the deploy cannot read a half-seeded target as a pass.
	 */
	public function test_a_missing_product_fails_the_command(): void {
		$state      = array();
		self::$skus = self::IDS;
		unset( self::$skus['Atherton'] );
		$this->mock_wordpress( $state, false );

		$this->expectException( ExitException::class );
		( new TileOrderCommand() )->seed_tiles( array(), array() );
	}

	/**
	 * Runs the command and returns everything it logged.
	 *
	 * @param array<string, mixed> $options The command's associative arguments.
	 */
	private function run_command( array $options = array() ): string {
		ob_start();
		( new TileOrderCommand() )->seed_tiles( array(), $options );
		return (string) ob_get_clean();
	}

	/**
	 * The WordPress surface the command drives: the SKU lookup resolves the
	 * eighteen codes, `get_post` answers a published product whose
	 * menu_order and marker come from `$state`, and every write lands back
	 * in `$state` and is counted in self::$writes.
	 *
	 * @param array<int, array<string, mixed>> $state      The product store, by id.
	 * @param bool                             $install_skus Whether to install the full SKU map.
	 */
	private function mock_wordpress( array &$state, bool $install_skus = true ): void {
		self::$writes = 0;
		if ( $install_skus && null === self::$skus ) {
			self::$skus = self::IDS;
		}

		WP_Mock::userFunction( 'current_user_can' )->zeroOrMoreTimes()->andReturn( true );

		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id ) use ( &$state ) {
				if ( ! in_array( $id, self::IDS, true ) ) {
					return null;
				}
				$post              = new \WP_Post( (object) array( 'ID' => $id ) );
				$post->ID          = $id;
				$post->post_type   = 'product';
				$post->post_title  = (string) array_search( $id, self::IDS, true );
				$post->post_status = 'publish';
				$post->menu_order  = (int) ( $state[ $id ]['menu_order'] ?? 0 );
				return $post;
			}
		);

		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( &$state ) {
				return $state[ $id ][ $key ] ?? '';
			}
		);

		WP_Mock::userFunction( 'update_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key, $value ) use ( &$state ) {
				$state[ $id ][ $key ] = $value;
				++self::$writes;
				return true;
			}
		);

		WP_Mock::userFunction( 'wp_update_post' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( array $args ) use ( &$state ) {
				$state[ (int) $args['ID'] ]['menu_order'] = (int) $args['menu_order'];
				++self::$writes;
				return (int) $args['ID'];
			}
		);
	}
}
