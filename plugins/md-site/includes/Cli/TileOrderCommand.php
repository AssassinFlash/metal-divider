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
	 * The codes are the products' `_sku` values, which the migrator wrote from
	 * the old titles, so a rename of the display title does not break this
	 * table.
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
				$id = $this->product_id_by_sku( $code );
				if ( null === $id ) {
					$missing[] = "{$slug}: {$code}";
					continue;
				}
				$product = \get_post( $id );
				if ( null === $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
					$missing[] = "{$slug}: {$code} (not a published product)";
					continue;
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
		$found = \get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per code, in a manual command.
					array(
						'key'   => '_sku',
						'value' => $sku,
					),
				),
			)
		);
		return array() !== $found ? (int) $found[0] : null;
	}
}
