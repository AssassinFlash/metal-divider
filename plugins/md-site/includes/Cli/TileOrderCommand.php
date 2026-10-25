<?php
/**
 * The `wp md-site seed-tiles` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use WP_CLI;

/**
 * Seeds the custom-screens pattern library's tile order, once.
 *
 * The page's curation is WooCommerce's own `menu_order` (see
 * Catalog::tiles()), and this command is the one-time seed that gives the
 * eighteen originally curated patterns their positions. It is built so it
 * cannot become a second source of truth (owner ruling 2026-10-26:
 * published WooCommerce data is the only daily maintenance source; a
 * migration may seed, it may not overwrite):
 *
 * - it marks every product it has handled with a per-product revision
 *   marker, and a marked product is skipped outright on every later run.
 *   That is what makes a deliberate REMOVAL stick: a tile is removed by
 *   clearing its `menu_order` back to 0, which is indistinguishable from
 *   "never seeded" by the field alone, so the field cannot be the guard —
 *   the marker is;
 * - it additionally never moves a product that already carries a position,
 *   so an order the owner set before the first seeded run survives too;
 * - `--dry-run` reports what it would change and writes nothing, markers
 *   included;
 * - a missing product is reported, never invented.
 *
 * ## Why a code can resolve through two identifiers
 *
 * The migration's identity key is the **normalized** pattern code:
 * `sku == drop_material_words(source_code)` (18-migrate.py's build_products_plan).
 * For three patterns the two differ, because the old code carried a superseded
 * material word:
 *
 *     Aztec Corten                              → Aztec
 *     Geo Criss Cross Corten                    → Geo Criss Cross
 *     Indian Nights Laser Cut Corten Screens    → Indian Nights Laser Cut Screens
 *
 * The table below holds the normalized codes, so on a migrated site the plain
 * SKU lookup finds them. A target that has not run the migration yet still
 * carries the old code as the SKU — that is exactly the state the first
 * production deploy failed in, after dev had been migrated and the tests
 * passed — so a code is resolved by trying, in order: the normalized SKU;
 * the source code from this table as a SKU; and either of those recorded as
 * `_md_source_code` provenance. Resolution never creates or renames anything:
 * the SKU is the migration's to write, and this command only gives the
 * product it found its tile position.
 *
 * After the first run the field is the owner's: reordering, adding or
 * removing a tile is a product edit in the WooCommerce admin, and this
 * command reports "0 written" on every later run. Bumping SEED_REVISION is
 * the only way to seed again — the deliberate act the migration path needs
 * when a future copy revision changes the initial set.
 */
final class TileOrderCommand {

	/**
	 * The post meta that records "this command has seeded this product".
	 * Its value is the seed revision, so a deliberate future revision can
	 * bump SEED_REVISION and seed again.
	 */
	private const MARKER = '_md_tiles_seeded';

	/**
	 * The current seed revision, written into MARKER.
	 */
	private const SEED_REVISION = '1';

	/**
	 * The eighteen tiles the custom-screens page carried, by pattern code,
	 * in the page's own panel and left-to-right order.
	 *
	 * Recovered from the page as it stood before the WooCommerce conversion
	 * (the hand-written tile markup's `data-pattern-name`, per panel). The
	 * old markup's nineteenth tile, "Kasbah", has no product in the catalog
	 * and is therefore not here; "Atherton Leaves" is the catalog's Atherton.
	 * The codes are the products' SKUs **as the migration normalizes them**
	 * (`drop_material_words(pattern_code)`), so a rename of the display title
	 * does not break this table.
	 *
	 * @var array<string, list<string>>
	 */
	private const SEED = array(
		'pattern-geometric'       => array( 'Aztec', 'Athelstan', 'The Davinci', 'Geo Criss Cross', 'Painted Daisy', 'Indian Nights Laser Cut Screens' ),
		'pattern-moroccan-arabic' => array( 'Alhambra', 'Lace Circles Laser Cut', 'Moroccan Love' ),
		'pattern-organic'         => array( 'Autumn Leaves', 'Bamboo', 'Leaf Vines Laser Cut', 'Leaf Skeleton Laser Cut', 'Atherton', 'Chase Blossom Decorative Screens' ),
		'pattern-vintage-retro'   => array( 'Breeze Blocks', 'Funk Divider', 'Long Island' ),
	);

	/**
	 * The normalized code → the source code the migration renames it from.
	 *
	 * Exactly the patterns whose old pattern code carried a superseded material
	 * word, i.e. the three where `sku !== source_code` in the migration's plan;
	 * the invariant `sku === drop_material_words(source_code)` is what makes
	 * this table complete, and a test pins it against the real export so it
	 * cannot drift from the migration.
	 *
	 * This is not a second copy of the material vocabulary: it names three
	 * concrete codes, so the command never has to know which words the
	 * migration drops. The vocabulary's one home stays 18-migrate.py, and a new
	 * superseded word would show up as a failing test rather than as a silent
	 * seed miss on an un-migrated target.
	 *
	 * @var array<string, string>
	 */
	private const SOURCE_ALIASES = array(
		'Aztec'                           => 'Aztec Corten',
		'Geo Criss Cross'                 => 'Geo Criss Cross Corten',
		'Indian Nights Laser Cut Screens' => 'Indian Nights Laser Cut Corten Screens',
	);

	/**
	 * Writes the eighteen curated tiles' `menu_order`.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report the writes without performing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp md-site seed-tiles --dry-run
	 *     wp md-site seed-tiles
	 *
	 * @subcommand seed-tiles
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Options: dry-run.
	 */
	public function seed_tiles( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site seed-tiles --user=<admin>.' );
		}
		$dry_run = isset( $assoc_args['dry-run'] );

		$written   = 0;
		$unchanged = 0;
		$already   = 0;
		$missing   = array();
		$report    = array();
		$to_mark   = array();

		foreach ( self::SEED as $slug => $codes ) {
			$position = 0;
			foreach ( $codes as $code ) {
				++$position;
				$resolved = $this->resolve( $code );
				if ( null === $resolved ) {
					$missing[] = "{$slug}: {$code}";
					continue;
				}
				$id      = $resolved['id'];
				$product = \get_post( $id );
				if ( null === $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
					$missing[] = "{$slug}: {$code} (not a published product)";
					continue;
				}
				// An alias hit is logged: on an un-migrated target this line is
				// the only trace that the seed matched the old identifier, and
				// a deploy log that said nothing would leave the operator
				// guessing which product the position landed on.
				if ( 'sku' !== $resolved['via'] ) {
					$report[] = sprintf( '%d (%s) resolved by %s', $id, $code, $resolved['via'] );
				}

				// A marked product belongs to WooCommerce. This is the guard
				// that makes a deliberate removal stick: a tile is removed by
				// clearing its menu_order, which the field alone cannot tell
				// from "never seeded", so the marker — not the value — is
				// what ends this command's interest in the product.
				if ( self::SEED_REVISION === (string) \get_post_meta( $id, self::MARKER, true ) ) {
					++$already;
					continue;
				}

				// Everything below is a real write on a real run, so the mark
				// is queued here rather than for every handled product: a
				// second run must issue no write at all, and rewriting an
				// unchanged marker would be one.
				$to_mark[] = $id;

				// The owner's edits win even before the mark lands: a product
				// already carrying a position is never moved by the seed.
				if ( (int) $product->menu_order > 0 ) {
					++$unchanged;
					$report[] = sprintf( '%d already ordered %d, marking it', $id, (int) $product->menu_order );
					continue;
				}

				$report[] = sprintf( 'set %d menu_order to %d', $id, $position );
				++$written;
				if ( ! $dry_run ) {
					\wp_update_post(
						array(
							'ID'         => $id,
							'menu_order' => $position,
						)
					);
				}
			}
		}

		// The mark goes on last and only on a real run: a product this
		// command has seeded is WooCommerce's from here on, even when it
		// needed nothing (its order was already set).
		if ( ! $dry_run ) {
			foreach ( $to_mark as $id ) {
				\update_post_meta( $id, self::MARKER, self::SEED_REVISION );
			}
		}

		WP_CLI::log(
			sprintf(
				'seed-tiles%s: %d %s, %d already ordered, %d already seeded, %d missing',
				$dry_run ? ' (dry run)' : '',
				$written,
				$dry_run ? 'to write' : 'written',
				$unchanged,
				$already,
				count( $missing )
			)
		);
		foreach ( $report as $line ) {
			WP_CLI::log( '  ' . $line );
		}

		if ( array() !== $missing ) {
			WP_CLI::error( 'seed-tiles: missing ' . implode( ', ', $missing ) );
		}
		WP_CLI::success( $dry_run ? 'Nothing written (dry run).' : 'Tile order seeded.' );
	}

	/**
	 * The product a seed code names, and which identifier resolved it.
	 *
	 * Tried in order, first hit wins, nothing is written:
	 *
	 * 1. `sku` — the normalized code as a WooCommerce SKU. On a migrated
	 *    target this is the only route that ever runs;
	 * 2. `alias-sku` — the source code as a SKU, for a target that has not run
	 *    the SKU rename yet. Production's first deploy failed here: it carried
	 *    `Aztec Corten` as the SKU while the seed asked for `Aztec`;
	 * 3. `alias-meta` — either code recorded as `_md_source_code` provenance,
	 *    the state a target is in between the rename and a later plan change.
	 *
	 * Only codes that have a source alias (SOURCE_ALIASES) can match 2 and 3;
	 * every other code is a single exact lookup, so this cannot make an
	 * unrelated product answer for a code.
	 *
	 * Not one route renames, creates or updates a SKU. The SKU belongs to the
	 * migration (18-migrate.py's build_products_plan); a seed that wrote it
	 * would be a second source of truth for product identity, and a seed that
	 * created a row would produce the duplicate this resolution exists to
	 * avoid.
	 *
	 * @param string $code The normalized pattern code from SEED.
	 * @return array{id: int, via: string}|null The id and the route, null when absent.
	 */
	private function resolve( string $code ): ?array {
		$normalized = $this->product_id_by_sku( $code );
		if ( null !== $normalized ) {
			return array(
				'id'  => $normalized,
				'via' => 'sku',
			);
		}
		$alias = self::SOURCE_ALIASES[ $code ] ?? '';
		if ( '' === $alias ) {
			return null;
		}
		$by_alias = $this->product_id_by_sku( $alias );
		if ( null !== $by_alias ) {
			return array(
				'id'  => $by_alias,
				'via' => 'alias-sku',
			);
		}
		foreach ( array( $code, $alias ) as $source ) {
			$by_meta = $this->product_id_by_source_code( $source );
			if ( null !== $by_meta ) {
				return array(
					'id'  => $by_meta,
					'via' => 'alias-meta',
				);
			}
		}
		return null;
	}

	/**
	 * A published product's id from its SKU.
	 *
	 * WooCommerce's own lookup is used when it is available; the direct meta
	 * read is the fallback so the command still reports correctly rather than
	 * treating every code as missing.
	 *
	 * @param string $sku The pattern code.
	 * @return int|null The product id, null when absent.
	 */
	private function product_id_by_sku( string $sku ): ?int {
		if ( \function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) \wc_get_product_id_by_sku( $sku );
			return $id > 0 ? $id : null;
		}
		return $this->product_id_by_meta( '_sku', $sku );
	}

	/**
	 * A published product's id from the migration's source-code provenance.
	 *
	 * @param string $source_code The old pattern code.
	 * @return int|null The product id, null when absent.
	 */
	private function product_id_by_source_code( string $source_code ): ?int {
		return $this->product_id_by_meta( '_md_source_code', $source_code );
	}

	/**
	 * A published product's id from one meta pair.
	 *
	 * @param string $key   The meta key (`_sku`, `_md_source_code`).
	 * @param string $value The value to match.
	 * @return int|null The product id, null when absent.
	 */
	private function product_id_by_meta( string $key, string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}
		$found = \get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per code, in a manual command.
					array(
						'key'   => $key,
						'value' => $value,
					),
				),
			)
		);
		return array() !== $found ? (int) $found[0] : null;
	}
}
