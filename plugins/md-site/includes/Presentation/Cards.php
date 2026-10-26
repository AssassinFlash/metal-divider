<?php
/**
 * The pattern card renderers: data in, partials out.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * Renders the three pattern cards from data, through their partials.
 *
 * This is the seam between the two halves of the AGENTS §5 rule. A caller
 * (today `Catalog`'s shortcodes) decides *which* products a face shows — the
 * query, the limit, the WooCommerce featured flag — and calls one method
 * here; the markup a visitor reads comes from
 * `content/livecanvas/partials/*.html`, which is the file WindPress compiles
 * against and the file `wp md-site present` deploys. Neither half reaches
 * into the other: no Tailwind utility, gradient or SVG path is written in PHP,
 * and no query, no `get_post_meta()` and no ordering decision is written in a
 * template.
 *
 * The card's values travel as the scope of one render (`PartialFields`), so a
 * partial names what it prints (`[md_pattern_code]`) and never how to find it.
 * Two of them are the call site's rather than the product's — the home card's
 * eyebrow and the tile's panel label — which is why they arrive as context and
 * not as WooCommerce reads: the section owns its wording.
 *
 * Every card is skipped, never half-drawn, when the product has no image to
 * show: `PatternFacts::image()` resolves to an empty URL and the method
 * returns '' for that product, so no `<img src="">` ever reaches a browser.
 * The rule is the same for all three faces — `editorial()`, `catalog()` and
 * `tile()` — because a card without its image is an empty frame whatever grid
 * it sits in.
 */
final class Cards {

	/**
	 * The home page's pattern-direction card.
	 */
	public const EDITORIAL = 'card-editorial';

	/**
	 * The catalog card (shop and series archives).
	 */
	public const CATALOG = 'card-catalog';

	/**
	 * The pattern library's tile and its lightbox.
	 */
	public const TILE = 'pattern-tile';

	/**
	 * One PDP related-pattern card.
	 */
	public const RELATED = 'pdp-related-card';

	/**
	 * One hub series card.
	 */
	public const SERIES = 'hub-series-card';

	/**
	 * One PDP gallery-slot image.
	 */
	public const SLOT_IMAGE = 'pdp-image';

	/**
	 * The partial renderer.
	 *
	 * @var Partials
	 */
	private readonly Partials $partials;

	/**
	 * Takes the renderer the site's shortcodes are registered on.
	 *
	 * @param Partials|null $partials The renderer, `Partials::shared()` by default.
	 */
	public function __construct( ?Partials $partials = null ) {
		$this->partials = $partials ?? Partials::shared();
	}

	/**
	 * One home-page pattern-direction card.
	 *
	 * @param int    $post_id The product id.
	 * @param string $eyebrow The section's family wording ("Mashrabiya pattern").
	 * @return string The card, '' when the product has no image.
	 */
	public function editorial( int $post_id, string $eyebrow ): string {
		if ( ! $this->is_product( $post_id ) ) {
			return '';
		}
		$image = PatternFacts::image( $post_id );
		if ( '' === $image['url'] ) {
			return '';
		}
		return $this->partials->render_post(
			self::EDITORIAL,
			$post_id,
			array( 'eyebrow' => $eyebrow )
		);
	}

	/**
	 * One catalog card.
	 *
	 * Skips an imageless product for the same reason the other two faces do:
	 * the card leads with its image, and a product without one would render
	 * an empty `.pattern-card__media` box that reads as a broken card in the
	 * grid.
	 *
	 * @param int $post_id The product id.
	 * @return string The card, '' when the post is not a product or has no image.
	 */
	public function catalog( int $post_id ): string {
		if ( ! $this->is_product( $post_id ) ) {
			return '';
		}
		$image = PatternFacts::image( $post_id );
		if ( '' === $image['url'] ) {
			return '';
		}
		return $this->partials->render_post( self::CATALOG, $post_id );
	}

	/**
	 * One pattern-library tile and its lightbox.
	 *
	 * The two lists the tile walks come from the product's own image data:
	 * `thumbs` is the two images the two-slot frame carries, and `strip` is the
	 * main image followed by every gallery image. `PatternFacts` memoises the
	 * gallery per request, so the two slices cost one read of the product's
	 * gallery meta and its attachment metadata, not two.
	 *
	 * Which strip button reads as selected is the interaction layer's to say —
	 * it sets `active` the moment the lightbox opens — so no selection state
	 * travels here and the button's class list stays entirely the template's.
	 *
	 * @param int    $post_id The product id.
	 * @param string $panel   The panel's label (the tile's category and the lightbox eyebrow).
	 * @return string The tile, '' when the product has no image.
	 */
	public function tile( int $post_id, string $panel ): string {
		if ( ! $this->is_product( $post_id ) ) {
			return '';
		}
		$image = PatternFacts::image( $post_id );
		if ( '' === $image['url'] ) {
			return '';
		}
		$name    = PatternFacts::code( $post_id );
		$gallery = PatternFacts::gallery( $post_id );
		$context = sprintf( 'Project context for %s', $name );
		$thumbs  = array();
		foreach ( array_slice( $gallery, 0, 2 ) as $size ) {
			$thumbs[] = $this->item( $size, $context );
		}
		$strip = array( $this->item( $image, $name ) );
		foreach ( $gallery as $size ) {
			$strip[] = $this->item( $size, $context );
		}
		return $this->partials->render_post(
			self::TILE,
			$post_id,
			array(
				'panel'       => $panel,
				'thumbs'      => $thumbs,
				'strip'       => $strip,
				'first_url'   => $image['url'],
				'first_alt'   => $name,
				'image_count' => count( $strip ),
			)
		);
	}

	/**
	 * Whether the id is a product: the pattern faces only ever draw one, and
	 * a stray id must render nothing rather than a card of empty values.
	 *
	 * @param int $post_id The post id.
	 */
	private function is_product( int $post_id ): bool {
		$post = \get_post( $post_id );
		return $post instanceof \WP_Post && 'product' === $post->post_type;
	}

	/**
	 * One image as a fragment renders it.
	 *
	 * @param array{id?: int, url: string, width: int, height: int} $size The attachment's size.
	 * @param string                                                $alt  The alt text.
	 * @return array{id: int, url: string, width: int, height: int, alt: string}
	 */
	private function item( array $size, string $alt ): array {
		return array(
			// The id travels so a fragment can ask for *this* attachment's
			// `srcset`; without it the only ladder available would be the
			// product's featured image, which is a different picture.
			'id'     => (int) ( $size['id'] ?? 0 ),
			'url'    => $size['url'],
			'width'  => $size['width'],
			'height' => $size['height'],
			'alt'    => $alt,
		);
	}

	/**
	 * The `sizes` of each PDP gallery slot: the box the slot is drawn in.
	 *
	 * A `srcset` without an honest `sizes` is worse than none — the browser
	 * assumes the viewport (100vw) and picks a candidate for a box ten times
	 * the real one, which is how the plate and the square were fetching 2048w
	 * files to fill 460px and 569px. These are not estimates: each was
	 * measured on a real product page in Chrome (`getBoundingClientRect` of
	 * the slot element) at twenty viewports from 320 to 1600 CSS px, and the
	 * formulas below reproduce every measurement with no underestimate and at
	 * most 12px of overestimate (at 990px, where the hero grid is still
	 * two-column). The breakpoints are the stylesheet's own:
	 *
	 * - the hero section is `100vw` at every width;
	 * - `.pdp-hero-plate` is `max-width: 460px` inside a `2fr` column, and
	 *   `380px` from 989px down (the stylesheet's `max-width: 989px` block),
	 *   then `width: 100%` of the container's padded column below 420px;
	 * - `.pdp-square` fills the `3fr` column above 989px, the full split
	 *   column from 989px down, and loses the container's padding below 750px;
	 * - `.pdp-wide` is the content column, capped by the 1400px container.
	 *
	 * A slot not named here gets `''` — no `sizes`, and therefore no `srcset`
	 * either (see `slot_image()`), which is the honest answer for a box whose
	 * width this class cannot vouch for.
	 *
	 * @var array<string, string>
	 */
	private const SLOT_SIZES = array(
		'hero-bg' => '100vw',
		'plate'   => '(min-width: 1440px) 460px, (min-width: 990px) 35vw, (min-width: 420px) 380px, calc(100vw - 40px)',
		'square'  => '(min-width: 1440px) 569px, (min-width: 990px) 40vw, (min-width: 750px) calc(100vw - 80px), calc(100vw - 40px)',
		'wide'    => 'min(100vw, 1400px)',
	);

	/**
	 * One PDP gallery-slot image, drawn from the `pdp-image` partial.
	 *
	 * The slot's *identity* — which attachment each slot resolves to, and the
	 * alt-role algorithm behind it — stays in `Shortcodes::slot_attachment()`
	 * and is handed in here; what moved is the element.
	 *
	 * The slot also decides the `sizes` its ladder is declared against: the
	 * same photograph is drawn in a 460px plate and a 569px square, so one
	 * product-level `sizes` would be wrong for both. `SLOT_SIZES` carries the
	 * measured value per slot; a slot outside it gets no `srcset` at all
	 * rather than a ladder hinting at the viewport.
	 *
	 * The two attributes that are not plain values travel as suffixes, so the
	 * partial never decides a design outcome: `priority` is the loading tier
	 * (`eager` only for the hero background, which is the page's first paint)
	 * and the dimensions are emitted only when the attachment metadata has
	 * them. The hero is the template's decorative background and keeps the
	 * empty alt the old theme gave it — that is the caller's decision too, so
	 * it arrives as the alt string it is.
	 *
	 * @param int    $attachment_id The resolved attachment.
	 * @param string $alt           The alt text ('' for the decorative hero).
	 * @param bool   $eager         Whether the image is the page's first paint.
	 * @param string $slot          The slot name (hero-bg|plate|square|wide).
	 * @return string The img element, '' when the attachment has no URL.
	 */
	public function slot_image( int $attachment_id, string $alt, bool $eager, string $slot = '' ): string {
		$url = (string) \wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( '' === $url ) {
			return '';
		}
		$metadata = \wp_get_attachment_metadata( $attachment_id );
		$sizes    = self::SLOT_SIZES[ $slot ] ?? '';
		// A ladder is only declared when this class can vouch for the box: a
		// width-descriptor `srcset` with no `sizes` makes the browser assume
		// 100vw, which is the very mistake `SLOT_SIZES` exists to avoid. An
		// unknown slot therefore gets `src` alone.
		$pair = '' === $sizes
			? array(
				'srcset' => '',
				'sizes'  => '',
			)
			// The ladder is the attachment's; `PatternFacts` memoises it, so
			// the plate, the square and the hero cost one read each rather than
			// one per candidate.
			: PatternFacts::attachment_srcset( $attachment_id, $sizes );
		return $this->partials->render(
			self::SLOT_IMAGE,
			array(
				'url'      => $url,
				'alt'      => $alt,
				'priority' => $eager ? 'eager' : 'lazy',
				'width'    => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
				'height'   => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
				'sizes'    => $sizes,
				'srcset'   => $pair,
			)
		);
	}

	/**
	 * The PDP's related-pattern cards: the WooCommerce related products, one
	 * partial render each.
	 *
	 * The selection stays WooCommerce's — `wc_get_related_products()` is the
	 * same call the previous inline renderer made, with the same count and the
	 * same guard — so which patterns are related, and whether any are, is
	 * unchanged. What moved is the markup, which is now the repository
	 * partial's.
	 *
	 * The card's alt is the product's full title (not its code) and its
	 * heading is the code, exactly as before; the link and the heading are the
	 * *related* product's, which is why they travel in the item scope rather
	 * than through `[md_pattern_permalink]`/`[md_pattern_code]` (those read the
	 * post being rendered, and the PDP renders one post and lists others).
	 *
	 * An empty `$related` renders nothing because the loop runs zero times —
	 * there is deliberately no separate empty-list branch to keep in step with
	 * it.
	 *
	 * @param int   $post_id The product whose related patterns to list.
	 * @param int[] $related The related product ids, in WooCommerce's order.
	 * @return string The cards, empty when there are none.
	 */
	public function related( int $post_id, array $related ): string {
		if ( ! $this->is_product( $post_id ) ) {
			return '';
		}
		$out = '';
		foreach ( $related as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$out .= $this->partials->render(
				self::RELATED,
				array(
					'link' => (string) \get_permalink( $id ),
					'url'  => (string) \get_the_post_thumbnail_url( $id, 'large' ),
					'alt'  => (string) \get_the_title( $id ),
					'name' => PatternFacts::code( $id ),
				)
			);
		}
		return $out;
	}

	/**
	 * The hub's series cards: one partial render per series.
	 *
	 * The membership decision stays the caller's — `Catalog::series_visible_counts()`
	 * is what decides a series has visible patterns, and it is passed in so
	 * this renderer does no querying of its own. What moved is the markup.
	 *
	 * The image is the term's `thumbnail_id` (the field WooCommerce's own
	 * category screen writes), drawn at its full size with the attachment's own
	 * metadata, and its alt falls back to the term name; a term without a
	 * thumbnail carries an empty `image` list, so the partial prints the media
	 * box without an `<img>` — the case the card CSS styles and the previous
	 * renderer produced.
	 *
	 * @param array{term_id: int, name: string, description: string, link: string} $series The series to draw.
	 * @return string The card.
	 */
	public function series_card( array $series ): string {
		$term_id = (int) $series['term_id'];
		$name    = (string) $series['name'];
		if ( $term_id <= 0 || '' === $name ) {
			return '';
		}
		$thumbnail = (int) \get_term_meta( $term_id, 'thumbnail_id', true );
		$image     = array();
		if ( $thumbnail > 0 ) {
			$url = (string) \wp_get_attachment_image_url( $thumbnail, 'full' );
			if ( '' !== $url ) {
				$metadata = \wp_get_attachment_metadata( $thumbnail );
				$alt      = (string) \get_post_meta( $thumbnail, '_wp_attachment_image_alt', true );
				$image[]  = array(
					'url'    => $url,
					'width'  => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
					'height' => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
					'alt'    => '' === $alt ? $name : $alt,
				);
			}
		}
		return $this->partials->render(
			self::SERIES,
			array(
				'link'  => (string) $series['link'],
				'name'  => $name,
				'text'  => (string) $series['description'],
				'image' => $image,
			)
		);
	}
}
