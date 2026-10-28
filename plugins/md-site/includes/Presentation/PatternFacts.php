<?php
/**
 * The pattern facts the partials read, and nothing else.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

use MetalDivider\Site\Media\Sizes;

/**
 * One place that answers "what does WooCommerce hold about this pattern",
 * for the templates' card partials.
 *
 * Every method takes a product id and returns a value: a word, a number, a
 * URL. None of them returns markup and none of them decides anything a
 * stylesheet could decide (AGENTS.md §5) — that is the partials' half of
 * the same rule (`Partials`). The sources are WooCommerce's and ACF's own
 * carriers, so an administrator's edit on the product screen is what the
 * card shows on the next request, with no repository file, no command and
 * no list in between:
 *
 * - the featured image and the `_product_image_gallery` order, with the
 *   attachment's real dimensions (the migration imported finished files
 *   with no intermediate sizes, so a size constraint would return the full
 *   file with numbers that do not describe the bytes on the wire);
 * - the `best_fit` and `open_area` fields, rendered as their display words
 *   including the sixteen-gap "On request" rule (spec 08 D2: no invented
 *   values);
 * - the `product_cat` term: its name for the card's Family row and its
 *   slug's tail for the series badge.
 *
 * The wording "On request" and the best-fit word table are the site's own
 * constants (owner ruling 2026-10-24 revision 6 for the material, spec 08
 * D2 for the fit words), so they live here rather than in a template that
 * would then need a conditional to choose between them.
 */
final class PatternFacts {

	/**
	 * The open-area wording for the patterns whose value has no source.
	 */
	private const OPEN_AREA_ON_REQUEST = 'On request';

	/**
	 * The best-fit key → display word table (the field stores the key).
	 *
	 * @var array<string, string>
	 */
	private const BEST_FIT_WORDS = array(
		'architectural-screening' => 'Architectural screening',
		'decorative-screening'    => 'Decorative screening',
	);

	/**
	 * The per-request memo: attachment sizes by attachment id.
	 *
	 * A card face asks the same question about the same product repeatedly —
	 * the tile draws one product's image as its main frame *and* walks the
	 * same product's gallery for the thumb frame and the lightbox strip, and
	 * every image it draws asks for a size — so without a memo the page
	 * resolves the same attachment metadata several times per card. A static
	 * array lives exactly as long as the request, and WooCommerce's data
	 * cannot change inside one, so nothing can go stale; `forget()` clears it
	 * for the tests.
	 *
	 * `null` is a stored answer here (an attachment that cannot be drawn), so
	 * the lookup is `array_key_exists()` rather than `isset()`.
	 *
	 * @var array<int, array{url: string, width: int, height: int}|null>
	 */
	private static array $sizes = array();

	/**
	 * The per-request memo of gallery lists, by product id.
	 *
	 * @var array<int, list<array{id: int, url: string, width: int, height: int}>>
	 */
	private static array $galleries = array();

	/**
	 * The per-request memo of `srcset` lists, by attachment id.
	 *
	 * @var array<int, string>
	 */
	private static array $srcsets = array();

	/**
	 * Forgets the request's memo (tests only: one case's fixture must not
	 * answer the next case's question).
	 */
	public static function forget(): void {
		self::$sizes     = array();
		self::$galleries = array();
		self::$srcsets   = array();
	}

	/**
	 * The pattern's short code: the product title before the "|" suffix
	 * ("Apiary | Architectural Metal Screen Pattern" → "Apiary"), which is
	 * the name every card shows and every alt text is built from.
	 *
	 * @param int $post_id The product id.
	 * @return string The code, '' when the title cannot be read.
	 */
	public static function code( int $post_id ): string {
		$title = (string) \get_the_title( $post_id );
		if ( '' === $title ) {
			return '';
		}
		$parts = explode( '|', $title );
		return trim( $parts[0] );
	}

	/**
	 * The pattern's family: the name of its first `product_cat` term, the
	 * one WooCommerce orders first — the word the catalog card's Family row
	 * carries.
	 *
	 * The name is `series_name()` and not `family()` because
	 * PHPCompatibility's removed-extension sniff reads a call whose name
	 * starts with `fam` as the long-deleted FAM extension, static calls
	 * included, and the site's lint gate runs that sniff.
	 *
	 * @param int $post_id The product id.
	 * @return string The family name, '' without a term.
	 */
	public static function series_name( int $post_id ): string {
		$term = self::series_term( $post_id );
		return null === $term ? '' : (string) $term->name;
	}

	/**
	 * The pattern's series word: the term slug's tail
	 * (`pattern-abstract` → `abstract`), the badge the home cards carry.
	 *
	 * @param int $post_id The product id.
	 * @return string The series word, '' without a term.
	 */
	public static function series( int $post_id ): string {
		$term = self::series_term( $post_id );
		if ( null === $term ) {
			return '';
		}
		$slug = (string) $term->slug;
		return str_starts_with( $slug, 'pattern-' ) ? substr( $slug, strlen( 'pattern-' ) ) : $slug;
	}

	/**
	 * The best-fit field as its display word.
	 *
	 * @param int $post_id The product id.
	 * @return string The word, '' when the field is empty.
	 */
	public static function best_fit( int $post_id ): string {
		$key = self::meta( $post_id, 'best_fit' );
		if ( '' === $key ) {
			return '';
		}
		return self::BEST_FIT_WORDS[ $key ] ?? ucwords( str_replace( '-', ' ', $key ) );
	}

	/**
	 * The open-area field as "NN%", or the honest "On request" for a
	 * pattern with no sourced value (a missing value is never rendered as
	 * a zero).
	 *
	 * @param int $post_id The product id.
	 * @return string The wording.
	 */
	public static function open_area( int $post_id ): string {
		$value = self::meta( $post_id, 'open_area' );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return self::OPEN_AREA_ON_REQUEST;
		}
		$number = (float) $value;
		$digits = floor( $number ) === $number ? 0 : 2;
		return number_format( $number, $digits ) . '%';
	}

	/**
	 * The card's second badge: the open area when the pattern has one, and
	 * the fit word — labelled "Effect" — when it does not, so a card never
	 * shows an empty pill or a "0%". A pattern with neither a sourced open
	 * area nor a best-fit word says "On request": "Effect: " with nothing
	 * after the colon is the empty pill this method exists to avoid.
	 *
	 * @param int $post_id The product id.
	 * @return string The badge text.
	 */
	public static function badge( int $post_id ): string {
		$cell = self::open_area( $post_id );
		if ( self::OPEN_AREA_ON_REQUEST !== $cell ) {
			return 'Open: ' . $cell;
		}
		$fit = self::best_fit( $post_id );
		return '' === $fit ? self::OPEN_AREA_ON_REQUEST : 'Effect: ' . $fit;
	}

	/**
	 * The product's card image: the featured image at the card size.
	 *
	 * @param int $post_id The product id.
	 * @return array{id: int, url: string, width: int, height: int} The image; id and url are 0/'' when the product carries none.
	 */
	public static function image( int $post_id ): array {
		$thumbnail = (int) \get_post_thumbnail_id( $post_id );
		if ( $thumbnail <= 0 ) {
			return self::no_image();
		}
		$size = self::size( $thumbnail );
		if ( null === $size ) {
			return self::no_image();
		}
		return array(
			'id'     => $thumbnail,
			'url'    => $size['url'],
			'width'  => $size['width'],
			'height' => $size['height'],
		);
	}

	/**
	 * The product's gallery images, in WooCommerce's own order, with the
	 * featured image dropped so no attachment fills two places.
	 *
	 * Memoised per product: the tile asks for the first two (its thumb frame)
	 * and for all of them (its lightbox strip) in the same render, and both
	 * answers come from one read.
	 *
	 * @param int $post_id The product id.
	 * @return list<array{id: int, url: string, width: int, height: int}> The images, empty when the gallery is empty.
	 */
	public static function gallery( int $post_id ): array {
		if ( isset( self::$galleries[ $post_id ] ) ) {
			return self::$galleries[ $post_id ];
		}
		$raw = \get_post_meta( $post_id, '_product_image_gallery', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			self::$galleries[ $post_id ] = array();
			return array();
		}
		$thumbnail = (int) \get_post_thumbnail_id( $post_id );
		$images    = array();
		foreach ( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) as $id ) {
			if ( $id === $thumbnail ) {
				continue;
			}
			$size = self::size( $id );
			if ( null !== $size ) {
				$images[] = array(
					'id'     => $id,
					'url'    => $size['url'],
					'width'  => $size['width'],
					'height' => $size['height'],
				);
			}
		}
		self::$galleries[ $post_id ] = $images;
		return $images;
	}

	/**
	 * An attachment's URL and real dimensions at the card size.
	 *
	 * Memoised per attachment: the same attachment is asked for by the
	 * product's main image and again wherever it appears in a list.
	 *
	 * @param int $attachment_id The attachment id.
	 * @return array{url: string, width: int, height: int}|null The size, null when the attachment cannot be drawn.
	 */
	public static function size( int $attachment_id ): ?array {
		// array_key_exists, not isset: a cached null (an attachment that
		// cannot be drawn) is an answer, and asking again must not re-read.
		if ( array_key_exists( $attachment_id, self::$sizes ) ) {
			return self::$sizes[ $attachment_id ];
		}
		$src                           = \wp_get_attachment_image_src( $attachment_id, 'large' );
		$out                           = is_array( $src ) && '' !== (string) $src[0]
			? array(
				'url'    => (string) $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
			)
			: null;
		self::$sizes[ $attachment_id ] = $out;
		return $out;
	}

	/**
	 * The card image's `srcset` list and the `sizes` value that describes
	 * the card's layout box.
	 *
	 * The pair is a fact about the *product's* featured image, so the post id
	 * is the argument, as it is for every other method here: the caller asks
	 * "this pattern's image", not "this attachment's".
	 *
	 * WordPress's own `wp_get_attachment_image_srcset()` answers first, and
	 * for most sites it is the whole story: the uploader registers each
	 * sub-size and the function lists them.
	 *
	 * This site's media pipeline works differently on purpose (AGENTS §2: the
	 * files are sized before upload and the server does no runtime
	 * conversion), so the migrator wrote each width as its own file and
	 * registered only one of them as an attachment. WordPress therefore finds
	 * no `sizes` metadata and returns false, even though the sibling widths
	 * are sitting next to the chosen file. The fallback reads that
	 * convention — `<stem>-<width>w.<ext>`, the names the pipeline produced —
	 * and lists only the siblings that actually exist on disk, so the browser
	 * still gets real alternatives and the attribute never names a missing
	 * file. Both paths produce a list; neither invents a width.
	 *
	 * @param int    $post_id The product id.
	 * @param string $sizes   The card's layout width, '' for none.
	 * @return array{srcset: string, sizes: string} The pair, both '' when the featured image has no alternatives.
	 */
	public static function srcset( int $post_id, string $sizes = '' ): array {
		$empty     = array(
			'srcset' => '',
			'sizes'  => '',
		);
		$thumbnail = (int) \get_post_thumbnail_id( $post_id );
		if ( $thumbnail <= 0 ) {
			return $empty;
		}
		$list = self::srcset_list( $thumbnail );
		if ( '' === $list ) {
			return $empty;
		}
		return array(
			'srcset' => $list,
			'sizes'  => $sizes,
		);
	}

	/**
	 * The featured image's *square* `srcset`, for a 1:1 card box.
	 *
	 * `srcset()` above lists the attachment's same-aspect ladder, which for a
	 * portrait pattern is a 480x960 portrait: a card whose box is
	 * `aspect-ratio: 1/1` then covers more than half of every download away.
	 * The card asks for this instead, and falls back to `srcset()` when no
	 * square side exists yet — a corpus that has not been regenerated still
	 * renders, just at the old weight.
	 *
	 * Only sides that actually exist are listed, so the attribute never names
	 * a file that is not on disk. `srcset()`'s own rule applies: neither path
	 * invents a width.
	 *
	 * @param int    $post_id The product id.
	 * @param string $sizes   The card's layout width, '' for none.
	 * @return array{srcset: string, sizes: string} The pair, both '' when there is nothing to offer.
	 */
	public static function square_srcset( int $post_id, string $sizes = '' ): array {
		$empty     = array(
			'srcset' => '',
			'sizes'  => '',
		);
		$thumbnail = (int) \get_post_thumbnail_id( $post_id );
		if ( $thumbnail <= 0 ) {
			return $empty;
		}
		// Guarded: `wp_get_attachment_metadata()` is a wp-admin include, so a
		// front-end render that asks before it loads (and any unit test
		// without the stub) would fatal instead of falling back.
		$meta = \function_exists( 'wp_get_attachment_metadata' ) ? \wp_get_attachment_metadata( $thumbnail ) : null;
		if ( ! \is_array( $meta ) || empty( $meta['sizes'] ) || ! \is_array( $meta['sizes'] ) ) {  // @phpstan-ignore booleanNot.alwaysFalse
			return self::srcset( $post_id, $sizes );
		}
		$has_card = isset( $meta['sizes'][ Sizes::CARD ]['file'] );
		$has_2x   = isset( $meta['sizes'][ Sizes::CARD_2X ]['file'] );
		if ( ! $has_card && ! $has_2x ) {
			return self::srcset( $post_id, $sizes );
		}
		$dir   = \dirname( (string) \get_attached_file( $thumbnail ) );
		$parts = array();
		foreach ( array( Sizes::CARD, Sizes::CARD_2X ) as $name ) {
			if ( ! isset( $meta['sizes'][ $name ]['file'] ) ) {
				continue;
			}
			$file = (string) $meta['sizes'][ $name ]['file'];
			if ( ! \is_readable( $dir . '/' . $file ) ) {
				continue;
			}
			$url     = \wp_get_attachment_image_url( $thumbnail, $name );
			$parts[] = $url . ' ' . (int) $meta['sizes'][ $name ]['width'] . 'w';
		}
		if ( array() === $parts ) {
			return self::srcset( $post_id, $sizes );
		}
		return array(
			'srcset' => \implode( ', ', $parts ),
			'sizes'  => $sizes,
		);
	}

	/**
	 * One attachment's `srcset` list and the `sizes` that describe its box.
	 *
	 * `srcset()` above answers for the *product's featured image*, which is
	 * what a card's hero needs. A tile's grid, its thumb frame and the
	 * lightbox strip draw *gallery* attachments — a different image each — so
	 * they ask per attachment instead.
	 *
	 * The list is the same computation either way (WordPress's own metadata,
	 * else the media pipeline's sibling files), and it is memoised per
	 * attachment, so the featured image asked for here and by `srcset()`
	 * costs one read.
	 *
	 * @param int    $attachment_id The attachment id.
	 * @param string $sizes         The `sizes` value of the box it is drawn in.
	 * @return array{srcset: string, sizes: string} The pair, both '' when the attachment has no alternatives.
	 */
	public static function attachment_srcset( int $attachment_id, string $sizes = '' ): array {
		$list = $attachment_id > 0 ? self::srcset_list( $attachment_id ) : '';
		if ( '' === $list ) {
			return array(
				'srcset' => '',
				'sizes'  => '',
			);
		}
		return array(
			'srcset' => $list,
			'sizes'  => $sizes,
		);
	}

	/**
	 * The `srcset` list for an attachment: WordPress's own, else the
	 * pipeline's sibling files.
	 *
	 * Memoised per attachment, because the list depends on a directory glob
	 * and the same featured image is asked for by every card that draws it.
	 *
	 * @param int $attachment_id The attachment id.
	 * @return string The list, '' when the attachment has no alternatives.
	 */
	private static function srcset_list( int $attachment_id ): string {
		if ( isset( self::$srcsets[ $attachment_id ] ) ) {
			return self::$srcsets[ $attachment_id ];
		}
		$list                            = self::compute_srcset( $attachment_id );
		self::$srcsets[ $attachment_id ] = $list;
		return $list;
	}

	/**
	 * The `srcset` list for an attachment, computed.
	 *
	 * @param int $attachment_id The attachment id.
	 * @return string The list, '' when the attachment has no alternatives.
	 */
	private static function compute_srcset( int $attachment_id ): string {
		$native = \wp_get_attachment_image_srcset( $attachment_id, 'large' );
		if ( is_string( $native ) && '' !== $native ) {
			return $native;
		}

		$file = (string) \get_attached_file( $attachment_id );
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		// `<stem>-<width>w.<ext>`: the stem is everything before the width
		// token, so its siblings sort together under one glob.
		if ( 1 !== preg_match( '/^(.*)-(\d+)w(\.[A-Za-z0-9]+)$/', $file, $parts ) ) {
			return '';
		}
		$stem = $parts[1];
		$ext  = $parts[3];

		$candidates = \glob( $stem . '-*w' . $ext );
		if ( ! is_array( $candidates ) ) {
			return '';
		}

		$uploads = \wp_get_upload_dir();
		$base    = (string) $uploads['baseurl'];
		$dir     = (string) $uploads['basedir'];
		if ( '' === $base || '' === $dir ) {
			return '';
		}
		// The mapping below is "strip the uploads directory prefix, append the
		// rest to the uploads URL", and it is only correct when the file really
		// is under that directory. A file kept outside it (a CDN copy, a
		// symlinked uploads dir, an attachment whose `_wp_attached_file` is
		// absolute and elsewhere) would otherwise lose an arbitrary prefix and
		// produce a URL naming a file that does not exist — so the prefix is
		// checked and the whole list is dropped when it does not hold.
		$dir_prefix = rtrim( $dir, '/' ) . '/';
		if ( ! str_starts_with( $file, $dir_prefix ) ) {
			return '';
		}

		$entries  = array();
		$own_url  = (string) \wp_get_attachment_url( $attachment_id );
		$own_file = basename( $file );
		foreach ( $candidates as $candidate ) {
			if ( 1 !== preg_match( '/-(\d+)w' . preg_quote( $ext, '/' ) . '$/', $candidate, $found ) ) {
				continue;
			}
			$width = (int) $found[1];
			if ( $width <= 0 ) {
				continue;
			}
			$name = basename( $candidate );
			$url  = $own_url;
			if ( $name !== $own_file ) {
				// A sibling has no attachment row, so its URL is assembled
				// from the uploads base and its position under it. The
				// candidate is globbed from the attachment's own directory, so
				// it shares the checked prefix; the guard is repeated here
				// because the glob result is filesystem input, not something
				// this file controls.
				if ( ! str_starts_with( $candidate, $dir_prefix ) ) {
					continue;
				}
				$relative = substr( $candidate, strlen( $dir_prefix ) );
				if ( '' === $relative ) {
					continue;
				}
				$url = $base . '/' . $relative;
			}
			if ( '' === $url ) {
				continue;
			}
			$entries[ $width ] = $url . ' ' . $width . 'w';
		}

		if ( array() === $entries ) {
			return '';
		}
		ksort( $entries );
		return implode( ', ', $entries );
	}

	/**
	 * An empty card image.
	 *
	 * @return array{id: int, url: string, width: int, height: int}
	 */
	private static function no_image(): array {
		return array(
			'id'     => 0,
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * The product's series term: its first `product_cat`.
	 *
	 * @param int $post_id The product id.
	 * @return \WP_Term|null The term, null without one.
	 */
	private static function series_term( int $post_id ): ?\WP_Term {
		$terms = \get_the_terms( $post_id, 'product_cat' );
		// get_the_terms() answers with WP_Error or false as well as with a
		// list, which is what the is_array() guard is for.
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		return $terms[0];
	}

	/**
	 * One ACF field's value (the free version stores fields under their own
	 * names, so the meta read is the native carrier — spec 24).
	 *
	 * @param int    $post_id The product id.
	 * @param string $key     The field name.
	 * @return string The value, '' when absent.
	 */
	private static function meta( int $post_id, string $key ): string {
		$value = \get_post_meta( $post_id, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
