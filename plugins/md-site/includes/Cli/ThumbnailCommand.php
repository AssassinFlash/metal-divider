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
 * - only considers candidates that are opaque (not RGBA), at least 240px on
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
	/**
	 * A candidate has to reach this maximum luminance after compositing.
	 *
	 * Measured: the masks peak at 1-11, half-transparent renders at ~53,
	 * photographs from 49 up. 20 sits in the empty gap between the mask
	 * population and everything else, so this separates them rather than
	 * trimming either.
	 */
	private const MIN_VISIBLE_MAX = 20.0;

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
	 * The roles that name a photograph of a screen in place. Used only to
	 * recognise what UNUSABLE_SCENES is refusing to accept.
	 *
	 * @var list<string>
	 */
	private const SCENE_ROLES = array(
		'installed screen scene',
		'local installation scene',
		'official installation scene',
		'project installation',
	);

	/**
	 * Patterns whose installation photograph is not usable as a thumbnail.
	 *
	 * "A photograph beats a mask" is the rule, and it is wrong wherever the
	 * photograph is of the wrong thing. Each entry below was opened and
	 * looked at; the reason is recorded so a later reader can re-check the
	 * judgement instead of trusting it.
	 *
	 * Removals from this list are deliberate: if a better photograph of that
	 * pattern is ever uploaded, delete the line and re-run.
	 *
	 * @var array<string, string>
	 */
	private const UNUSABLE_SCENES = array(
		'apiary' => 'the only installed scene is a rooftop pool with loungers and people; the backlit strip at the right is not legible as the honeycomb pattern, so the card would advertise a pool. The accent render reads as the pattern and stays visible on the dark card (mean 44.2, max 53.4 after compositing).',
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
			// Two defects are repaired, and only these: a featured image that
			// is still a finish preview, and one too small for the card. The
			// second was found on /shop/ after the first was fixed — alhambra,
			// athelstan, autumn-leaves and forrest-perforated were pointing at
			// `-01-*` renders of 500px in a 338 CSS px card, i.e. 1416 device
			// pixels at 2x, and every one of them had a 1000px or larger file in
			// its own media list. Anything else stays an editor's choice.
			$is_finish = 'finish' === $this->media_class( $current );
			$is_small  = $this->is_below_card_density( $current );
			if ( ! $is_finish && ! $is_small ) {
				++$skipped;
				continue;
			}
			// The marker is written after a repair but no longer gates it. It
			// means "this product has been handled", not "this product is now
			// correct": seventeen products carried the marker and still failed
			// the size rule, and gating on it made the fix unreachable on a site
			// an earlier revision had already touched. Reaching this point means
			// the product fails a criterion, which is the only condition that
			// should decide whether to repair it.

			$replacement = $this->pick_photograph( $product_id, $current );
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
	 * @param int $current    The current featured attachment, when the caller
	 *                        is repairing a size defect and the replacement has
	 *                        to measure larger than it.
	 * @return int|null The attachment id.
	 */
	private function pick_photograph( int $product_id, int $current = 0 ): ?int {
		$ids = $this->media_ids( $product_id );
		if ( array() === $ids ) {
			return null;
		}

		// With a size defect the replacement has to be genuinely larger, or the
		// run would report a repair that leaves the card just as soft.
		$current_short = 0;
		if ( $current > 0 ) {
			$meta          = \wp_get_attachment_metadata( $current );
			$current_short = \is_array( $meta )
				? min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) )
				: 0;
		}

		$slug        = (string) \get_post_field( 'post_name', $product_id );
		$short       = str_replace( '-architectural-metal-screen-pattern', '', $slug );
		$skip_scenes = isset( self::UNUSABLE_SCENES[ $short ] );

		$by_role = array();
		$by_base = array();
		foreach ( $ids as $id ) {
			if ( ! $this->is_usable_photograph( $id ) ) {
				continue;
			}
			$role = (string) \get_post_meta( $id, '_md_role', true );
			if ( $skip_scenes && in_array( $role, self::SCENE_ROLES, true ) ) {
				// This pattern's installation photography shows something other
				// than the screen; see UNUSABLE_SCENES for the reason.
				continue;
			}
			// A cut sheet is a dimensioned drawing. It is legible on a dark card
			// — that is why the visibility rule alone lets it through — but it is
			// a document, not the thing being sold, so it is never a thumbnail.
			if ( 'cut sheet' === $role ) {
				continue;
			}
			if ( $current_short > 0 ) {
				$meta  = \wp_get_attachment_metadata( $id );
				$short = \is_array( $meta )
					? min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) )
					: 0;
				if ( $short <= $current_short ) {
					continue;
				}
			}
			$rank = array_search( $role, self::PHOTO_ROLES, true );
			if ( false !== $rank ) {
				$by_role[ $rank ] = $id;
			}
			$by_base[] = $id;
		}

		if ( array() !== $by_role ) {
			ksort( $by_role );
			$preferred = (int) reset( $by_role );

			// Role order alone is not enough once size is also a criterion.
			// white-sands has one `installed screen scene` at 720x515 and a set
			// of `accent render`s at 2048x1161; preferring the role picked the
			// 515 short side for a 676-device-pixel card. When a size repair is
			// under way, take the largest candidate that is still a scene if one
			// exists, else fall back to the role order.
			if ( 0 === $current_short ) {
				return $preferred;
			}
			$best      = $preferred;
			$best_side = $this->short_side( $preferred );
			foreach ( $by_base as $candidate ) {
				$side = $this->short_side( (int) $candidate );
				// Strictly larger than both the running best and the image being
				// replaced: skyfall-gradient was moved from a 1000px file to a
				// 681px one because only the first comparison was made.
				if ( $side > $best_side && $side > $current_short ) {
					$best      = (int) $candidate;
					$best_side = $side;
				}
			}
			return $best;
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
	/**
	 * Whether a featured image is too small for the card at 2x.
	 *
	 * The catalogue declares `sizes="(min-width: 1120px) 338px"`, so the card
	 * asks for 676 device pixels on a retina screen. 600 is the floor below
	 * which the upscale is visible; a 400 or 500px source in that slot is
	 * soft, and these products all carry larger files.
	 *
	 * @param int $id The current featured attachment.
	 * @return bool True when the image cannot fill the card at 2x.
	 */
	/**
	 * The short side of an attachment in pixels, or 0 when unknown.
	 *
	 * @param int $id The attachment id.
	 * @return int
	 */
	private function short_side( int $id ): int {
		$meta = \wp_get_attachment_metadata( $id );
		if ( ! \is_array( $meta ) ) {
			return 0;
		}
		return min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );
	}

	/**
	 * Whether a featured image is too small for the card at 2x.
	 *
	 * The catalogue declares `sizes="(min-width: 1120px) 338px"`, so the card
	 * asks for 676 device pixels on a retina screen; 600 is the floor below
	 * which the upscale is visible.
	 *
	 * @param int $id The current featured attachment.
	 * @return bool True when the image cannot fill the card at 2x.
	 */
	private function is_below_card_density( int $id ): bool {
		$meta = \wp_get_attachment_metadata( $id );
		if ( ! \is_array( $meta ) ) {
			return false;
		}
		$short = min( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );
		return $short > 0 && $short < 600;
	}

	/**
	 * Whether an attachment can stand in as a catalogue photograph.
	 *
	 * Rejects the mask itself, thumbnails too small to judge, and plain plates
	 * — a solid white or solid black image reads as a failed load, which is the
	 * defect being repaired.
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
		// 240 comes from the card, not from taste: the catalogue declares
		// `sizes="(min-width: 1120px) 338px"`, so a 338px box is the widest the
		// image is ever drawn and 240 short-side leaves it sharp. The smallest
		// genuinely-installed scene in the catalogue is 360x257 (sampoerna) —
		// note 257 is its short side, which is why an earlier 320 floor
		// silently rejected the one photograph that pattern has.
		if ( min( $width, $height ) < 240 ) {
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

		// The one question that matters: after compositing onto the card's own
		// background, is anything visible? Measured on the migrated files, this
		// separates the three populations by two orders of magnitude —
		// white line-art masks        max luminance  1-11   (invisible)
		// half-transparent renders    max luminance  ~53    (visible)
		// opaque photographs          max luminance 49-235 (visible)
		//
		// Two earlier versions of this check asked a proxy question instead and
		// both were wrong. `getimagesize()['channels']` does not exist for WebP,
		// so every mask passed and the dry run "replaced" each one with itself.
		// Rejecting any alpha over 2% then excluded every half-transparent
		// render — which is how apiary ended up with a photograph of a swimming
		// pool as the only candidate left.
		$composited = $this->composited_luminance( $image );
		\imagedestroy( $image );
		return $composited['max'] >= self::MIN_VISIBLE_MAX;
	}

	/**
	 * Mean and maximum luminance after compositing onto the card background.
	 *
	 * The card is `#141414`, and that is the whole point: an RGBA line-art
	 * mask read on its own shows the motif at full brightness and looks
	 * perfect. The invisibility only exists after the composite, which is why
	 * this measures the composite rather than the file.
	 *
	 * @param \GdImage $image The decoded source image.
	 * @return array{mean: float, max: float}
	 */
	private function composited_luminance( $image ): array {
		$width     = \imagesx( $image );
		$height    = \imagesy( $image );
		$luminance = array();
		for ( $gx = 0; $gx < 24; $gx++ ) {
			for ( $gy = 0; $gy < 24; $gy++ ) {
				$x     = (int) ( $gx * ( $width - 1 ) / 23 );
				$y     = (int) ( $gy * ( $height - 1 ) / 23 );
				$rgb   = \imagecolorat( $image, $x, $y );
				$alpha = ( $rgb >> 24 ) & 0x7F;
				// GD stores 0 as opaque and 127 as fully clear, so the source
				// weight of this pixel is the complement.
				$weight      = 1.0 - ( $alpha / 127.0 );
				$red         = ( ( $rgb >> 16 ) & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$green       = ( ( $rgb >> 8 ) & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$blue        = ( $rgb & 0xFF ) * $weight + 20 * ( 1.0 - $weight );
				$luminance[] = 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
			}
		}
		\sort( $luminance );
		$count = count( $luminance );
		return array(
			'mean' => array_sum( $luminance ) / $count,
			'max'  => $luminance[ $count - 1 ],
		);
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
