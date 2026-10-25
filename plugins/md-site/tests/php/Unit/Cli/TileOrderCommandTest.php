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
	 * How many products the run created. The command must never create one.
	 *
	 * @var int
	 */
	private static int $created = 0;

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
	 * The three patterns whose old code carried a superseded material word, and
	 * the normalized code the migration renames them to.
	 *
	 * Held here independently of the command's own table: this is what the
	 * export actually says (18-migrate.py's build_products_plan over
	 * data/shopify-admin-export/products.json yields exactly these three
	 * `sku != source_code` pairs), so a test comparing the two is comparing the
	 * command against the migration rather than against itself.
	 */
	private const OLD_CODES = array(
		'Aztec'                           => 'Aztec Corten',
		'Geo Criss Cross'                 => 'Geo Criss Cross Corten',
		'Indian Nights Laser Cut Screens' => 'Indian Nights Laser Cut Corten Screens',
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
		self::$skus    = null;
		self::$created = 0;
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
	 * The production failure this closes: a target that has NOT run the SKU
	 * rename still carries the old Corten code as its SKU.
	 *
	 * Production deployed with `Aztec Corten`, `Geo Criss Cross Corten` and
	 * `Indian Nights Laser Cut Corten Screens`, the seed asked for the
	 * normalized codes, and the run failed "missing" on all three — while dev
	 * had been migrated, so no test saw it. The seed must find the three by
	 * their old identifiers and place them at their panel positions, writing no
	 * SKU and creating no product.
	 */
	public function test_an_unmigrated_target_resolves_the_old_corten_skus(): void {
		$state = array();
		// Exactly production's state: only those three still carry old codes.
		$skus = self::IDS;
		foreach ( self::OLD_CODES as $normalized => $old ) {
			unset( $skus[ $normalized ] );
			$skus[ $old ] = self::IDS[ $normalized ];
		}
		$this->mock_wordpress( $state, $skus );

		$output = $this->run_command();

		// The positions the page's own order gives them, per panel.
		$this->assertSame( 1, $state[ self::IDS['Aztec'] ]['menu_order'] ?? null, 'Aztec is first in geometric' );
		$this->assertSame( 4, $state[ self::IDS['Geo Criss Cross'] ]['menu_order'] ?? null, 'Geo Criss Cross is fourth' );
		$this->assertSame( 6, $state[ self::IDS['Indian Nights Laser Cut Screens'] ]['menu_order'] ?? null, 'Indian Nights is sixth' );
		$this->assertStringContainsString( '18 written', $output, 'all eighteen seeded, none missing' );
		$this->assertStringContainsString( '0 missing', $output );

		// Found by the old code, and the log says so — an alias hit is the one
		// thing a deploy log has to name, or the operator cannot tell which
		// product a position landed on.
		foreach ( self::OLD_CODES as $normalized => $old ) {
			$this->assertStringContainsString(
				sprintf( '%d (%s) resolved by alias-sku', self::IDS[ $normalized ], $normalized ),
				$output,
				"{$normalized} was resolved through {$old}"
			);
		}

		// Nothing was renamed, created or deleted: the seed writes menu_order and
		// its marker, nothing else. The SKU stays the migration's.
		$this->assertSame( 0, self::$created, 'no duplicate product was created for an old code' );
		foreach ( self::OLD_CODES as $normalized => $old ) {
			$id = self::IDS[ $normalized ];
			$this->assertArrayNotHasKey( '_sku', $state[ $id ], 'the seed never writes a SKU' );
			$this->assertSame( '1', $state[ $id ]['_md_tiles_seeded'] ?? null, 'the marker still lands' );
		}
		$this->assertSame( array(), array_diff( array_keys( $state ), self::IDS ), 'no product was created' );
	}

	/**
	 * An existing old-code product and a later normalized SKU are the same
	 * product, and the seed must treat them as one at every stage: it seeds
	 * once (through whichever identifier exists), and after the migration it
	 * still recognises the product it already seeded rather than writing a
	 * second position.
	 *
	 * This is the idempotency the deploy depends on, stated over the exact
	 * transition production is about to make.
	 */
	public function test_an_old_sku_then_the_normalized_sku_stays_one_product(): void {
		$state = array();
		$skus  = self::IDS;
		unset( $skus['Aztec'] );
		$skus['Aztec Corten'] = self::IDS['Aztec'];
		$this->mock_wordpress( $state, $skus );

		// Before the migration: seeded through the old code.
		( new TileOrderCommand() )->seed_tiles( array(), array() );
		$before = $state;
		$this->assertSame( 1, $before[ self::IDS['Aztec'] ]['menu_order'] ?? null );

		// The migration renames the SKU in place; the product id does not move.
		self::$skus   = self::IDS;
		self::$writes = 0;

		( new TileOrderCommand() )->seed_tiles( array(), array() );

		$this->assertSame( $before[ self::IDS['Aztec'] ]['menu_order'], 1, 'the position is unchanged' );
		$this->assertSame( 0, self::$writes, 'no second write after the rename' );
		$this->assertSame( '1', $state[ self::IDS['Aztec'] ]['_md_tiles_seeded'] ?? null );
	}

	/**
	 * Between the rename and a later plan change a target records provenance
	 * instead of the old SKU: `_md_source_code` holds the old code while the
	 * SKU is already normalized. The seed resolves through the provenance too,
	 * so it does not report the product missing in that window.
	 */
	public function test_provenance_resolves_a_code_whose_sku_is_not_yet_normalized(): void {
		$state = array();
		$skus  = self::IDS;
		// The SKU lookup cannot answer for this one at all.
		unset( $skus['Geo Criss Cross'] );
		$meta = array( self::IDS['Geo Criss Cross'] => array( '_md_source_code' => 'Geo Criss Cross Corten' ) );
		$this->mock_wordpress( $state, $skus, $meta );

		try {
			$output = $this->run_command();
		} finally {
			// Asserted even when the command reports a miss: an implementation
			// that invented the product would otherwise hide behind the
			// exception the miss raises.
			$this->assertSame( 0, self::$created, 'a code it cannot resolve is reported, never invented' );
		}

		$this->assertSame( 4, $state[ self::IDS['Geo Criss Cross'] ]['menu_order'] ?? null, 'found through _md_source_code' );
		$this->assertStringContainsString(
			sprintf( '%d (Geo Criss Cross) resolved by alias-meta', self::IDS['Geo Criss Cross'] ),
			$output
		);
		$this->assertStringContainsString( '0 missing', $output );
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
	 *
	 * The missing product is one with no alias either, so this cannot be
	 * satisfied by the alias route: Atherton's code is the same before and
	 * after the migration.
	 */
	public function test_a_missing_product_fails_the_command(): void {
		$state = array();
		$this->mock_wordpress( $state, array_diff_key( self::IDS, array( 'Atherton' => true ) ) );

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
	 * The WordPress surface the command drives.
	 *
	 * The SKU map and any pre-existing provenance live beside `$state` rather
	 * than in it, so `$state` stays exactly "what this command wrote" — which is
	 * what the dry-run assertion (`array() === $state`) needs to mean anything.
	 *
	 * That separation is also what makes an un-migrated target expressible: a
	 * test passes `$skus` with the old Corten code and the fixture answers
	 * exactly as production did — `wc_get_product_id_by_sku('Aztec')` finds
	 * nothing, `wc_get_product_id_by_sku('Aztec Corten')` finds 51.
	 *
	 * @param array<int, array<string, mixed>> $state The write log, by product id.
	 * @param array<string, int>|null          $skus  SKU → id, the full normalized map by default.
	 * @param array<int, array<string, mixed>> $meta  Pre-existing post meta, by product id.
	 */
	private function mock_wordpress( array &$state, ?array $skus = null, array $meta = array() ): void {
		self::$writes = 0;
		self::$skus   = $skus ?? self::IDS;

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
			static function ( int $id, string $key ) use ( &$state, $meta ) {
				if ( isset( $state[ $id ][ $key ] ) ) {
					return $state[ $id ][ $key ];
				}
				return $meta[ $id ][ $key ] ?? '';
			}
		);

		// The meta-query fallback, answering the way WP_Query answers a
		// single-key meta_query against the fixture: one product or nothing.
		// It reads the same two sources the meta getter does, so a test cannot
		// set provenance one route sees and the other misses.
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( array $args ) use ( &$state, $meta ) {
				$clause = $args['meta_query'][0] ?? array();
				$key    = (string) ( $clause['key'] ?? '' );
				$value  = (string) ( $clause['value'] ?? '' );
				$ids    = array();
				foreach ( self::IDS as $id ) {
					$held = $state[ $id ][ $key ] ?? ( $meta[ $id ][ $key ] ?? '' );
					if ( '' !== $value && (string) $held === $value ) {
						$ids[] = $id;
					}
				}
				return array_slice( $ids, 0, 1 );
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

		// Creating a product is the one thing this command must never do, so the
		// fixture makes it visible instead of leaving it to fail as a missing
		// mock: a test asserts self::$created stays 0, and an implementation
		// that inserted a duplicate would show up as a count, not as an error.
		self::$created = 0;
		WP_Mock::userFunction( 'wp_insert_post' )->zeroOrMoreTimes()->andReturnUsing(
			static function (): int {
				++self::$created;
				return 900000 + self::$created;
			}
		);
	}
}
