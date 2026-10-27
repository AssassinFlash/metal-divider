<?php
/**
 * The `wp md-site retarget-thumbnails` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use WP_CLI;

/**
 * Points a pattern's featured image at a photograph instead of a mask.
 *
 * ## Why this exists
 *
 * The migration followed the old storefront's rule — the featured image is
 * `media[0]` — and for 94 of the 146 patterns `media[0]` is a
 * `finish preview`: an RGBA line-art mask whose only RGB values are black and
 * white, with the motif carried in the alpha channel. Composited onto this
 * site's `#141414` card the motif measures mean 1-5 / max 1-11 on a 0-255
 * luminance scale. It is not dark, it is absent; the same rule on the old
 * storefront (`.pattern-card__media`, `rgb(20,20,20)`) hid it there too.
 *
 * 145 of the 146 patterns already carry a real photograph in the same media
 * list — an installed screen scene, a local or official installation scene, a
 * reference image. Those are ordinary opaque images of the same object the
 * other 52 cards show, so pointing the featured image at one keeps the
 * catalogue in one visual language instead of introducing a second.
 *
 * ## Why it is built not to overwrite a decision
 *
 * The featured image is editor-owned data. This command therefore:
 *
 * - only ever acts on a product whose current featured image is still a
 *   `finish` media class — the exact defect it repairs. A product whose
 *   featured image is anything else is left alone, whatever else is true
 *   about it;
 * - writes a per-product revision marker, and skips a marked product, so a
 *   later re-run cannot undo an owner's choice. Removing the marker is the
 *   deliberate act that re-opens the product;
 * - only considers candidates that are opaque (not RGBA), at least 400px on
 *   the short side, and neither blank-white (mean > 200) nor blank-black
 *   (mean < 12) — a plain plate is not a photograph;
 * - prefers the roles that name an installed screen, in the order below, so
 *   the choice is the migrator's own vocabulary rather than a guess;
 * - `--dry-run` reports what it would change and writes nothing, markers
 *   included;
 * - reports every product it could not resolve and invents nothing for it.
 *
 * ## Usage
 *
 *     wp md-site retarget-thumbnails --dry-run
 *     wp md-site retarget-thumbnails
 *     wp md-site retarget-thumbnails --limit=5
 */
final class ThumbnailCommand {

	/**
	 * The revision marker. Bumping it is the only way to re-run over a
	 * product the previous run already handled.
	 */
	private const MARKER = '_md_thumb_retarget_rev';

	/**
	 * The value of MARKER this revision writes.
	 */
	private const REVISION = '1';

	/**
	 * Roles that name a photograph of a screen in place, most specific first.
	 *
	 * `pattern render` and `accent render` are deliberately absent: they are
	 * drawings of the pattern, which is the same defect in a different file.
	 *
	 * @var list<string>
	 */
	private const PHOTO_ROLES = array(
		'installed screen scene',
		'local installation scene',
		'official installation scene',
		'project installation',
		'reference image',
		'detail reference',
		'qc photo',
	);

	/**
	 * The migrator's role vocabulary, copied from Shortcodes::ROLE_CLASSES so
	 * this command reads the same eleven phrases the presentation layer does.
	 *
	 * @var array<string, string>
	 */
	private const ROLE_CLASSES = array(
		'local installation scene'    => 'scene',
		'official installation scene' => 'scene',
		'installed screen scene'      => 'scene',
		'project installation'        => 'scene',
		'detail reference'            => 'detail',
		'finish preview'              => 'finish',
		'accent render'               => 'accent',
		'cut sheet'                   => 'cutsheet',
	);

	/**
	 * Points the featured image of every masked pattern at a photograph.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change; write nothing, markers included.
	 *
	 * [--limit=<n>]
	 * : Stop after n products. 0 means every product.
	 * ---
	 * default: 0
	 * ---
	 *
	 * @param array<string, mixed> $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args The named arguments.
	 * @subcommand retarget-thumbnails
	 */
	public function retarget( array $args, array $assoc_args ): void {
		unset( $args );
		$dry   = isset( $assoc_args['dry-run'] );
		$limit = (int) ( $assoc_args['limit'] ?? 0 );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- `edit_products` is WooCommerce's own capability, registered at runtime; WPCS cannot see it.
		if ( ! \current_user_can( 'edit_products' ) ) {
			WP_CLI::error( 'Run as a user who can edit products: wp md-site retarget-thumbnails --user=<id>.' );
		}

		$products = \get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$changed    = 0;
		$skipped    = 0;
		$unresolved = array();
		$examined   = 0;

		foreach ( $products as $product_id ) {
			$product_id = (int) $product_id;
			if ( $limit > 0 && $examined >= $limit ) {
				break;
			}
			++$examined;

			$current = (int) \get_post_thumbnail_id( $product_id );
			if ( $current <= 0 ) {
				++$skipped;
				continue;
			}
			// Only the defect being repaired: a featured image that is still a
			// finish preview. Everything else is an editor's choice.
			if ( 'finish' !== $this->media_class( $current ) ) {
				++$skipped;
				continue;
			}
			if ( self::REVISION === (string) \get_post_meta( $product_id, self::MARKER, true ) ) {
				++$skipped;
				continue;
			}

			$replacement = $this->pick_photograph( $product_id );
			if ( null === $replacement ) {
				$unresolved[] = \get_post_field( 'post_name', $product_id );
				continue;
			}

			$code = (string) \get_post_meta( $product_id, '_md_pattern_code', true );
			if ( '' === $code ) {
				$code = (string) \get_post_field( 'post_title', $product_id );
			}
			$note = sprintf(
				'%s: %s -> %s',
				'' === $code ? '#' . $product_id : $code,
				basename( (string) \get_attached_file( $current ) ),
				basename( (string) \get_attached_file( $replacement ) )
			);

			if ( $dry ) {
				WP_CLI::log( 'would set ' . $note );
				++$changed;
				continue;
			}

			\set_post_thumbnail( $product_id, $replacement );
			\update_post_meta( $product_id, self::MARKER, self::REVISION );
			WP_CLI::log( 'set ' . $note );
			++$changed;
		}

		$verb = $dry ? 'would write' : 'wrote';
		WP_CLI::success(
			sprintf(
				'%d examined, %s %d, %d skipped, %d unresolved',
				$examined,
				$verb,
				$changed,
				$skipped,
				count( $unresolved )
			)
		);
		if ( array() !== $unresolved ) {
			WP_CLI::log( 'unresolved (no usable photograph): ' . implode( ', ', $unresolved ) );
		}
	}

	/**
	 * The best photograph in this product's media list, or null.
	 *
	 * "Best" is decided by the migrator's own role vocabulary first and by
	 * opaque-and-not-blank second, so the answer is a photograph of a screen
	 * rather than whatever happened to be second in the list.
	 *
	 * @param int $product_id The product id.
	 * @return int|null The attachment id.
	 */
	private function pick_photograph( int $product_id ): ?int {
		$ids = $this->media_ids( $product_id );
		if ( array() === $ids ) {
			return null;
		}

		$by_role = array();
		$by_base = array();
		foreach ( $ids as $id ) {
			if ( ! $this->is_usable_photograph( $id ) ) {
				continue;
			}
			$role = (string) \get_post_meta( $id, '_md_role', true );
			$rank = array_search( $role, self::PHOTO_ROLES, true );
			if ( false !== $rank ) {
				$by_role[ $rank ] = $id;
			}
			$by_base[] = $id;
		}

		if ( array() !== $by_role ) {
			ksort( $by_role );
			return (int) reset( $by_role );
		}

		// No role named a photograph — fall back to any usable opaque image,
		// which still beats a mask. The report names these products only when
		// even this finds nothing.
		return array() === $by_base ? null : (int) $by_base[0];
	}

	/**
	 * Whether an attachment can stand in as a catalogue photograph.
	 *
	 * Rejects the mask itself (RGBA), thumbnails too small to judge, and
	 * plain plates — a solid white or solid black image reads as a failed
	 * load, which is the defect being repaired.
	 *
	 * @param int $id The attachment id.
	 * @return bool
	 */
	private function is_usable_photograph( int $id ): bool {
		$file = (string) \get_attached_file( $id );
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}
		$meta = \wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		if ( min( $width, $height ) < 400 ) {
			return false;
		}

		if ( ! \function_exists( 'imagecreatefromstring' ) ) {
			return false;
		}
		$blob = @\file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- a local attachment, and a broken file is a "no".
		if ( false === $blob ) {
			return false;
		}
		$image = @\imagecreatefromstring( $blob ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( false === $image ) {
			return false;
		}

		// A mask is a line drawing carried in the alpha channel; a photograph
		// is opaque. `getimagesize()['channels']` cannot answer this for WebP
		// (it reports nothing at all — the first version of this check trusted
		// it and "replaced" every mask with itself), so sample the alpha the
		// decoder actually produced. Measured on the migrated masks: 23.6% of
		// sampled pixels are transparent. A photograph measures 0%.
		$width  = \imagesx( $image );
		$height = \imagesy( $image );
		$total  = 0;
		$clear  = 0;
		for ( $gx = 0; $gx < 16; $gx++ ) {
			for ( $gy = 0; $gy < 16; $gy++ ) {
				$x     = (int) ( $gx * ( $width - 1 ) / 15 );
				$y     = (int) ( $gy * ( $height - 1 ) / 15 );
				$rgb   = \imagecolorat( $image, $x, $y );
				$alpha = ( $rgb >> 24 ) & 0x7F;
				if ( $alpha > 0 ) {
					++$clear;
				}
				++$total;
			}
		}
		\imagedestroy( $image );
		// The grid is fixed at 16x16, so the denominator is never zero; the
		// guard that used to sit here was dead code PHPStan was right to flag.
		if ( ( $clear / $total ) > 0.02 ) {
			return false;
		}

		$mean = $this->mean_luminance( $file );
		if ( null === $mean ) {
			return false;
		}
		return $mean > 12.0 && $mean < 200.0;
	}

	/**
	 * The mean Rec.709 luminance of an image, sampled on a small grid.
	 *
	 * Sampling rather than reading every pixel: this runs over 146 products
	 * on a shared host, and a 16x16 grid already separates a photograph from
	 * a flat plate by two orders of magnitude.
	 *
	 * @param string $file The image path.
	 * @return float|null Null when the file cannot be read as an image.
	 */
	private function mean_luminance( string $file ): ?float {
		if ( ! \function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$blob = @\file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- a local attachment, and a broken file is a "no".
		if ( false === $blob ) {
			return null;
		}
		$image = @\imagecreatefromstring( $blob ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( false === $image ) {
			return null;
		}
		$width  = \imagesx( $image );
		$height = \imagesy( $image );
		$total  = 0.0;
		$count  = 0;
		for ( $gx = 0; $gx < 16; $gx++ ) {
			for ( $gy = 0; $gy < 16; $gy++ ) {
				$x      = (int) ( $gx * ( $width - 1 ) / 15 );
				$y      = (int) ( $gy * ( $height - 1 ) / 15 );
				$rgb    = \imagecolorat( $image, $x, $y );
				$red    = ( $rgb >> 16 ) & 0xFF;
				$green  = ( $rgb >> 8 ) & 0xFF;
				$blue   = $rgb & 0xFF;
				$total += 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
				++$count;
			}
		}
		\imagedestroy( $image );
		// Same fixed 16x16 grid as above: the count is always 256.
		return $total / $count;
	}

	/**
	 * The product's media attachment ids: featured image then gallery.
	 *
	 * @param int $product_id The product id.
	 * @return int[]
	 */
	private function media_ids( int $product_id ): array {
		$ids       = array();
		$thumbnail = (int) \get_post_thumbnail_id( $product_id );
		if ( $thumbnail > 0 ) {
			$ids[] = $thumbnail;
		}
		if ( \function_exists( 'wc_get_product' ) ) {
			$product = \wc_get_product( $product_id );
			if ( \is_object( $product ) && \method_exists( $product, 'get_gallery_image_ids' ) ) {
				foreach ( $product->get_gallery_image_ids() as $id ) {
					$id = (int) $id;
					if ( $id > 0 && ! \in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
			}
		}
		return $ids;
	}

	/**
	 * The migrator's media class for one attachment.
	 *
	 * @param int $id The attachment id.
	 * @return string scene|detail|finish|accent|cutsheet|other.
	 */
	private function media_class( int $id ): string {
		$role = (string) \get_post_meta( $id, '_md_role', true );
		if ( isset( self::ROLE_CLASSES[ $role ] ) ) {
			return self::ROLE_CLASSES[ $role ];
		}
		return 'other';
	}
}
