<?php
/**
 * The `wp md-site seed-series` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use WP_CLI;

/**
 * Seeds the empty series fields once, then gets out of the way.
 *
 * The hub's series cards read two WooCommerce-owned fields: the
 * product_cat term's `thumbnail_id` (the image picker on Products →
 * Categories) and its `description` (the category description box).
 * Both are empty on a freshly migrated site — the migrator writes the
 * term name only, and the old theme's card images were theme assets,
 * not term data — so the hub would render nine blank cards.
 *
 * This command is the one-time seed that closes that gap, and it is
 * built so it can never become a second source (owner ruling
 * 2026-10-26: published WooCommerce data is the only daily maintenance
 * source; a migration may seed, it may not overwrite):
 *
 * - it writes **only an empty field** — a term whose field carries any
 *   value is reported unchanged and left exactly as the administrator
 *   set it;
 * - it marks each term it has handled (`_md_series_seeded`, this
 *   command's version) and never touches a marked term again. Without
 *   that mark, "only when empty" would still revert a deliberate
 *   clearing: an administrator who removes a card's image or its copy
 *   would find it back on the next run. With it, the seed runs at most
 *   once per term and WooCommerce owns the fields from then on;
 * - the image comes from the site's own data: the `hero_image` URL the
 *   earlier migration run recorded on the term, resolved to its
 *   attachment, else the featured image of the term's newest published
 *   pattern — never a table of filenames in this repository;
 * - the description comes from the checked-in copy of the old site's
 *   collection descriptions, blank terms only. It is seed material, not
 *   a live source: the runtime reads the term's own description, and
 *   nothing here reads the file again for a marked term;
 * - the style blurb — the PDP's design-intent lead, one sentence per
 *   family — likewise comes from a checked-in copy of the old rendering,
 *   and is likewise seed material only.
 */
final class CatalogCommand {

	/**
	 * The term meta that records "this command has seeded this term".
	 * Its value is the seed revision, so a deliberate future copy
	 * revision can bump SEED_REVISION and seed again.
	 */
	private const MARKER = '_md_series_seeded';

	/**
	 * The current seed revision, written into MARKER.
	 */
	private const SEED_REVISION = '1';

	/**
	 * The old collections' descriptions, checked in as migration seed
	 * material (content/copy/series-descriptions.json).
	 */
	private const DESCRIPTIONS_PATH = 'content/copy/series-descriptions.json';

	/**
	 * The old PDP's series-level style blurbs, checked in as seed material
	 * (content/copy/series-style-blurbs.json). They are the design-intent
	 * block's first paragraph on the old site: one sentence per family,
	 * identical for every member of the series.
	 */
	private const BLURBS_PATH = 'content/copy/series-style-blurbs.json';

	/**
	 * Seeds every series term's empty `thumbnail_id` and description.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be written, write nothing.
	 *
	 * [--repoint-masked]
	 * : Also replace an existing term thumbnail when it is a line-art mask
	 * that composites to nothing on the card. A thumbnail that is merely
	 * "already set" is left alone; only one that a visitor cannot see is
	 * replaced, and the replacement is this term's first product's featured
	 * image — the same source the empty case uses.
	 *
	 * ## EXAMPLES
	 *
	 *     wp md-site seed-series --dry-run
	 *     wp md-site seed-series
	 *     wp md-site seed-series --repoint-masked --dry-run
	 *
	 * @subcommand seed-series
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Options: dry-run.
	 */
	public function seed_series( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site seed-series --user=<admin>.' );
		}
		$dry_run = isset( $assoc_args['dry-run'] );
		$repoint = isset( $assoc_args['repoint-masked'] );

		$terms = \get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( \is_wp_error( $terms ) ) {
			WP_CLI::error( 'seed-series: ' . $terms->get_error_message() );
			return;
		}
		if ( array() === $terms ) {
			WP_CLI::error( 'seed-series: no product_cat terms on this site' );
			return;
		}
		$descriptions = $this->descriptions();
		$blurbs       = $this->copy( self::BLURBS_PATH );

		$images_seeded    = 0;
		$images_present   = 0;
		$copy_seeded      = 0;
		$copy_present     = 0;
		$copy_unavailable = array();
		$blurb_seeded     = 0;
		$blurb_present    = 0;
		$already_seeded   = 0;

		foreach ( $terms as $term ) {
			$term_id = (int) $term->term_id;

			// A marked term belongs to WooCommerce: an administrator who
			// cleared a field meant it, and a rerun must not put it back.
			if ( self::SEED_REVISION === (string) \get_term_meta( $term_id, self::MARKER, true ) ) {
				++$already_seeded;
				continue;
			}

			$thumbnail = (int) \get_term_meta( $term_id, 'thumbnail_id', true );
			$masked    = $thumbnail > 0 && $repoint && $this->is_invisible_on_card( $thumbnail );
			if ( $thumbnail > 0 && ! $masked ) {
				++$images_present;
			} else {
				if ( $masked ) {
					WP_CLI::log( sprintf( 'masked %s: %s composites to nothing on the card', $term->slug, basename( (string) \get_attached_file( $thumbnail ) ) ) );
				}
				$attachment = $this->resolve_attachment( $term );
				if ( null === $attachment ) {
					WP_CLI::warning( sprintf( 'seed-series: %s has no image to seed', $term->slug ) );
				} else {
					if ( $dry_run ) {
						WP_CLI::log( sprintf( 'would seed %s image → attachment %d', $term->slug, $attachment ) );
					} else {
						\update_term_meta( $term_id, 'thumbnail_id', $attachment );
					}
					++$images_seeded;
				}
			}

			if ( '' === trim( (string) $term->description ) ) {
				$text = $descriptions[ (string) $term->slug ] ?? '';
				if ( '' === $text ) {
					$copy_unavailable[] = (string) $term->slug;
				} elseif ( $dry_run ) {
					WP_CLI::log( sprintf( 'would seed %s description', $term->slug ) );
					++$copy_seeded;
				} else {
					$updated = \wp_update_term( $term_id, 'product_cat', array( 'description' => $text ) );
					if ( \is_wp_error( $updated ) ) {
						WP_CLI::error( sprintf( 'seed-series: %s description failed: %s', $term->slug, $updated->get_error_message() ) );
					}
					++$copy_seeded;
				}
			} else {
				++$copy_present;
			}

			// The style blurb: the PDP's design-intent lead, one sentence
			// per family. Same rule as the copy — blank fields only, and a
			// marked term is never revisited, so clearing it sticks.
			if ( '' === trim( (string) \get_term_meta( $term_id, 'style_blurb', true ) ) ) {
				$blurb = $blurbs[ (string) $term->slug ] ?? '';
				if ( '' !== $blurb ) {
					if ( $dry_run ) {
						WP_CLI::log( sprintf( 'would seed %s style blurb', $term->slug ) );
					} else {
						\update_term_meta( $term_id, 'style_blurb', $blurb );
					}
					++$blurb_seeded;
				}
			} else {
				++$blurb_present;
			}

			// The mark goes on last and only on a real run: a term this
			// command has looked at is WooCommerce's from here on, even
			// when it needed nothing (its fields were already filled).
			if ( ! $dry_run ) {
				\update_term_meta( $term_id, self::MARKER, self::SEED_REVISION );
			}
		}

		WP_CLI::success(
			sprintf(
				'seed-series%s: images %d %s / %d kept; descriptions %d %s / %d kept; style blurbs %d %s / %d kept; %d already seeded%s',
				$dry_run ? ' (dry run)' : '',
				$images_seeded,
				$dry_run ? 'to seed' : 'seeded',
				$images_present,
				$copy_seeded,
				$dry_run ? 'to seed' : 'seeded',
				$copy_present,
				$blurb_seeded,
				$dry_run ? 'to seed' : 'seeded',
				$blurb_present,
				$already_seeded,
				array() === $copy_unavailable ? '' : '; no seed copy for ' . implode( ', ', $copy_unavailable )
			)
		);
	}

	/**
	 * The old collections' descriptions by term slug, from the checked-in
	 * seed copy; an empty map when the file is absent (the command then
	 * seeds images only, and says which terms it could not fill).
	 *
	 * @return array<string, string>
	 */
	private function descriptions(): array {
		return $this->copy( self::DESCRIPTIONS_PATH );
	}

	/**
	 * One checked-in seed copy, decoded to slug → text; an empty map when
	 * the file is absent or unreadable.
	 *
	 * @param string $relative The path inside this plugin.
	 * @return array<string, string>
	 */
	private function copy( string $relative ): array {
		$path = \dirname( __DIR__, 2 ) . '/' . $relative;
		if ( ! is_readable( $path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository seed file, read by a manual command.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $slug => $text ) {
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$out[ (string) $slug ] = trim( $text );
			}
		}
		return $out;
	}

	/**
	 * The attachment a term's thumbnail should point at: the migrator's
	 * `hero_image` URL when the term carries one, else the featured image
	 * of the term's newest published pattern. Both answers come from the
	 * site's own data, and a term with neither stays empty.
	 *
	 * @param \WP_Term $term The product_cat term.
	 * @return int|null The attachment id, null when the term has no image.
	 */
	/**
	 * Whether an attachment composites to nothing on the card background.
	 *
	 * The same question ThumbnailCommand asks of a product's featured image:
	 * a `finish preview` is a line-art mask whose motif lives in the alpha
	 * channel, so on `#141414` it measures a max luminance of 1-16 and reads
	 * as an empty tile. Measured here rather than assumed from the role,
	 * because the role is only a label and the pixels are the fact.
	 *
	 * @param int $attachment_id The attachment to test.
	 * @return bool True when the image is effectively invisible on the card.
	 */
	private function is_invisible_on_card( int $attachment_id ): bool {
		$file = (string) \get_attached_file( $attachment_id );
		if ( '' === $file || ! \is_readable( $file ) || ! \function_exists( 'imagecreatefromstring' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- local attachment; a broken file is a "no".
		$blob = @\file_get_contents( $file );
		if ( false === $blob ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		$image = @\imagecreatefromstring( $blob );
		if ( false === $image ) {
			return false;
		}
		$width  = \imagesx( $image );
		$height = \imagesy( $image );
		$max    = 0.0;
		for ( $gx = 0; $gx < 24; $gx++ ) {
			for ( $gy = 0; $gy < 24; $gy++ ) {
				$x     = (int) ( $gx * ( $width - 1 ) / 23 );
				$y     = (int) ( $gy * ( $height - 1 ) / 23 );
				$rgb   = \imagecolorat( $image, $x, $y );
				$alpha = ( $rgb >> 24 ) & 0x7F;
				// Composite onto #141414: GD stores 0 as opaque, 127 as clear.
				$weight = 1.0 - ( $alpha / 127.0 );
				$red    = ( ( $rgb >> 16 ) & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$green  = ( ( $rgb >> 8 ) & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$blue   = ( $rgb & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$max    = max( $max, 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue );
			}
		}
		\imagedestroy( $image );
		return $max < 20.0;
	}

	/**
	 * The attachment a term's card should show: its hero image when that
	 * resolves, else its first product's featured image.
	 *
	 * @param \WP_Term $term The series term.
	 * @return int|null The attachment id, or null when there is nothing to use.
	 */
	private function resolve_attachment( \WP_Term $term ): ?int {
		$url = (string) \get_term_meta( (int) $term->term_id, 'hero_image', true );
		if ( '' !== $url ) {
			$id = (int) \attachment_url_to_postid( $url );
			if ( $id > 0 ) {
				return $id;
			}
		}

		$products = \get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one lookup per series term, in a manual command.
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => array( (int) $term->term_id ),
					),
				),
			)
		);
		if ( array() === $products ) {
			return null;
		}
		$id = (int) \get_post_thumbnail_id( (int) $products[0] );
		return $id > 0 ? $id : null;
	}
}
