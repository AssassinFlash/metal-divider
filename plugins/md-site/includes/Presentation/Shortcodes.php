<?php
/**
 * The PDP data shortcodes and template data functions.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The template's data assemblies (spec 19 §6.2): each takes the product
 * context, returns semantic markup with class names, and carries zero
 * inline styles and zero design decisions (AGENTS.md §5: any visual fix
 * lands in a template or a WindPress volume, never here).
 *
 * The order the spec rules by is native first, and every shortcode here
 * exists because the native layer was exhausted for its value:
 *
 * - ``[md_pdp_material]`` — the spec bar's Material cell is a constant
 *   (owner ruling 2026-10-24 revision 6), and WooCommerce has no
 *   "global default with no per-product carrier" mechanism to render a
 *   constant cell; the template layer renders it, and this assembly is
 *   the template layer's data arm.
 * - ``[md_pdp_design_intent slot=…]`` — the phrase groups under
 *   docs/copy/pdp-series-phrases.json (approved copy, not code; spec 08
 *   D2) with the pattern's real attribute slots substituted; no native
 *   tag assembles text from a copy file.
 * - ``[md_pdp_open_area]`` / ``[md_pdp_best_fit]`` — ACF numbers and
 *   keys rendered as their display words, including the sixteen-gap
 *   "On request" rule (spec 08 D2: no invented values); LiveCanvas's
 *   ``lc_get_cf`` prints the raw meta, an empty string when the value
 *   is absent, and has no conditional fallback of its own.
 * - ``[md_pdp_image slot=…]`` — the gallery's images by position;
 *   LiveCanvas has ``lc_the_thumbnail_url`` for the featured image and
 *   no tag for gallery position N.
 * - ``[md_pdp_related]`` — the adjacent-patterns cards;
 *   ``lc_wc_related`` and Woo's own shortcodes render the WooCommerce
 *   card template, not this markup, and the card recipe is the
 *   replica's.
 * - ``[md_series_grid]`` — the hub's series cards; LiveCanvas's
 *   ``lc_get_terms`` renders badge lists, and Woo's product shortcodes
 *   list products, not series.
 *
 * ``md_pdp_style()`` is a plain function (called through LiveCanvas's
 * native ``[lc_wp_func]``): the series word from the product's
 * product_cat term — the eyebrow and the spec bar's Style cell both
 * carry it, and no native tag renders a term slug's tail.
 */
final class Shortcodes {

	/**
	 * The spec bar's Material cell.
	 */
	public const MATERIAL = 'md_pdp_material';

	/**
	 * The Design intent block's assembly.
	 */
	public const DESIGN_INTENT = 'md_pdp_design_intent';

	/**
	 * The spec bar's Open area cell.
	 */
	public const OPEN_AREA = 'md_pdp_open_area';

	/**
	 * The spec bar's Best fit cell.
	 */
	public const BEST_FIT = 'md_pdp_best_fit';

	/**
	 * A gallery image by slot.
	 */
	public const IMAGE = 'md_pdp_image';

	/**
	 * The adjacent-patterns cards.
	 */
	public const RELATED = 'md_pdp_related';

	/**
	 * The hub's series cards.
	 */
	public const SERIES_GRID = 'md_series_grid';

	/**
	 * The phrase groups' file, relative to the repository root (spec 08
	 * D2: the groups are approved copy under docs/copy/, not code; the
	 * assembly reads them, it does not embed them).
	 */
	private const PHRASES_PATH = 'docs/copy/pdp-series-phrases.json';

	/**
	 * The site-wide material wording (owner ruling 2026-10-24 revision
	 * 6: Stainless Steel (304 / 316) is the one wording the site
	 * carries; the old five-material lists are bad data, not migrated).
	 */
	private const MATERIAL_WORDING = 'Stainless Steel (304 / 316)';

	/**
	 * The Best fit key → display word table (the ACF field stores the
	 * key; the spec bar shows the word, spec 06 §2.3).
	 */
	private const BEST_FIT_WORDS = array(
		'architectural-screening' => 'Architectural screening',
		'decorative-screening'    => 'Decorative screening',
	);

	/**
	 * The open-area wording for the sixteen patterns whose value has no
	 * source (spec 08 O1: "On request", never an invented number).
	 */
	private const OPEN_AREA_ON_REQUEST = 'On request';

	/**
	 * Adds the shortcodes.
	 */
	public function register(): void {
		add_shortcode( self::MATERIAL, array( $this, 'material' ) );
		add_shortcode( self::DESIGN_INTENT, array( $this, 'design_intent' ) );
		add_shortcode( self::OPEN_AREA, array( $this, 'open_area' ) );
		add_shortcode( self::BEST_FIT, array( $this, 'best_fit' ) );
		add_shortcode( self::IMAGE, array( $this, 'image' ) );
		add_shortcode( self::RELATED, array( $this, 'related' ) );
		add_shortcode( self::SERIES_GRID, array( $this, 'series_grid' ) );
	}

	/**
	 * The spec bar's Material cell: the site-wide constant as plain
	 * text — the template wraps it in the cell's own markup, the value
	 * carries nothing of its own.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The value text.
	 */
	public function material( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return esc_html( self::MATERIAL_WORDING );
	}

	/**
	 * The Design intent assembly (spec 08 D2): the phrase groups with
	 * the pattern's real attribute slots substituted. The lead slot
	 * prefers the pattern's own summary (the forty-three carried-over
	 * summaries, spec 08 D2: an asset, rewritten not recreated) and
	 * falls back to the series group; the hero and supplemental slots
	 * are the groups' own.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: slot = hero|lead|supplemental.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The assembled text, empty when the groups file is absent.
	 */
	public function design_intent( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$slot = is_array( $atts ) && isset( $atts['slot'] ) ? (string) $atts['slot'] : 'lead';
		if ( ! in_array( $slot, array( 'hero', 'lead', 'supplemental' ), true ) ) {
			$slot = 'lead';
		}
		$groups = $this->phrase_groups();
		if ( null === $groups ) {
			return '';
		}
		if ( 'hero' === $slot ) {
			// The hero phrase is the site-wide sentence (every series
			// shares it; only the best-fit word varies), so it needs no
			// series resolution.
			return esc_html( $this->fill_slots( (string) ( $groups['hero'] ?? '' ) ) );
		}
		if ( 'lead' === $slot ) {
			$summary = $this->pattern_meta( 'summary' );
			if ( '' !== $summary ) {
				return esc_html( $summary );
			}
		}
		$series = $this->series_word();
		if ( '' === $series || ! isset( $groups['series'][ $series ] ) ) {
			return '';
		}
		$phrase = (string) ( $groups['series'][ $series ][ $slot ] ?? '' );
		return esc_html( $this->fill_slots( $phrase ) );
	}

	/**
	 * The spec bar's Open area cell: the ACF number as "NN%", or "On
	 * request" for the patterns with no sourced value (spec 08 O1).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The value text.
	 */
	public function open_area( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		$value = $this->pattern_meta( 'open_area' );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return esc_html( self::OPEN_AREA_ON_REQUEST );
		}
		$number = (float) $value;
		$digits = floor( $number ) === $number ? 0 : 2;
		return esc_html( number_format( $number, $digits ) . '%' );
	}

	/**
	 * The spec bar's Best fit cell: the ACF key as its display word.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The value text.
	 */
	public function best_fit( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		$key = $this->pattern_meta( 'best_fit' );
		if ( '' === $key ) {
			return '';
		}
		$word = self::BEST_FIT_WORDS[ $key ] ?? ucwords( str_replace( '-', ' ', $key ) );
		return esc_html( $word );
	}

	/**
	 * A gallery image by slot: the hero background (the featured
	 * image), the hero plate (the first gallery image) or the identity
	 * square (the second). Returns the URL alone — the template's img
	 * element is the replica's own markup.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: slot = hero-bg|plate|square.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The image URL, empty when the slot has no image.
	 */
	public function image( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$slot = is_array( $atts ) && isset( $atts['slot'] ) ? (string) $atts['slot'] : '';
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return '';
		}
		if ( 'hero-bg' === $slot ) {
			$url = (string) get_the_post_thumbnail_url( $post->ID, 'full' );
			return esc_url( $url );
		}
		$offset = 'plate' === $slot ? 0 : 1;
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $post->ID );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_gallery_image_ids' ) ) {
			return '';
		}
		$ids = $product->get_gallery_image_ids();
		if ( ! isset( $ids[ $offset ] ) ) {
			return '';
		}
		$url = (string) wp_get_attachment_image_url( (int) $ids[ $offset ], 'full' );
		return esc_url( $url );
	}

	/**
	 * The adjacent-patterns cards: the WooCommerce related products of
	 * the current pattern, in the replica's card markup (the exhaustion
	 * proof is the class docblock — the native related mechanisms render
	 * Woo's own card template, not this one).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The cards, empty when Woo is absent or has no related products.
	 */
	public function related( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type || ! function_exists( 'wc_get_related_products' ) ) {
			return '';
		}
		$related = wc_get_related_products( $post->ID, 3 );
		if ( array() === $related ) {
			return '';
		}
		$out = '';
		foreach ( $related as $id ) {
			$out .= sprintf(
				'<a href="%s" class="pdp-related-card gs-reveal"><figure><img src="%s" alt="%s" loading="lazy"></figure><h3>%s</h3><span>Compare density and rhythm</span></a>',
				esc_url( (string) get_permalink( $id ) ),
				esc_url( (string) get_the_post_thumbnail_url( $id, 'large' ) ),
				esc_attr( get_the_title( $id ) ),
				esc_html( $this->code_of_title( (string) get_the_title( $id ) ) )
			);
		}
		return $out;
	}

	/**
	 * The hub's series cards: the product_cat terms (the eight series;
	 * the empty gradient series carries no term, spec 06 §3, so it
	 * cannot appear), each with its term-name title, its description,
	 * its hero image when the term carries one, and the card call.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The cards.
	 */
	public function series_grid( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) || array() === $terms ) {
			return '';
		}
		$out = '';
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$image = (string) get_term_meta( $term->term_id, 'hero_image', true );
			$out  .= sprintf(
				'<li><a class="cph__card" href="%s"><div class="cph__media">%s</div><div class="cph__body"><h3 class="cph__card-title">%s</h3><p class="cph__card-copy">%s</p><span class="cph__card-cta">Explore</span></div></a></li>',
				esc_url( $link ),
				'' !== $image ? sprintf( '<img src="%s" alt="%s" loading="lazy" class="cph__media-image">', esc_url( $image ), esc_attr( $term->name ) ) : '',
				esc_html( $term->name ),
				esc_html( $term->description )
			);
		}
		return $out;
	}

	/**
	 * The pattern's series word: the product_cat term slug's tail —
	 * pattern-abstract → abstract — for the eyebrow and the spec bar's
	 * Style cell (called through [lc_wp_func func="md_pdp_style"], a
	 * plain function because lc_wp_func calls one by name).
	 *
	 * @return string The series word, empty outside a product or without a series.
	 */
	public static function style(): string {
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return '';
		}
		$terms = get_the_terms( $post->ID, 'product_cat' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		$slug = $terms[0]->slug;
		return str_starts_with( $slug, 'pattern-' ) ? substr( $slug, strlen( 'pattern-' ) ) : $slug;
	}

	/**
	 * The phrase groups, decoded; null when the copy file is absent
	 * (the assembly answers nothing rather than rendering a stub — the
	 * groups are copy under review, their absence is a state, not an
	 * error).
	 *
	 * @return array<string, mixed>|null
	 */
	private function phrase_groups() {
		static $groups = null;
		if ( null !== $groups ) {
			return $groups;
		}
		$path = dirname( __DIR__, 4 ) . '/' . self::PHRASES_PATH;
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read at render time.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$groups = $decoded;
		return $groups;
	}

	/**
	 * The current pattern's ACF field value (ACF's free version stores
	 * fields under their own names, so the meta read is the native
	 * carrier — spec 24's free-version test proved the dual-path write).
	 *
	 * @param string $key The field name.
	 * @return string The value, '' when absent.
	 */
	private function pattern_meta( string $key ): string {
		$post = get_post();
		if ( null === $post ) {
			return '';
		}
		$value = get_post_meta( $post->ID, $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The current pattern's series word (see style()).
	 *
	 * @return string
	 */
	private function series_word(): string {
		return self::style();
	}

	/**
	 * Substitutes a phrase's attribute slots with the pattern's real
	 * values (the slots are data, spec 08 §4.1 T2).
	 *
	 * @param string $phrase The phrase with {slot} markers.
	 * @return string The filled phrase.
	 */
	private function fill_slots( string $phrase ): string {
		$sku = $this->pattern_meta( '_sku' );
		$map = array(
			'{code}'           => '' !== $sku ? $sku : (string) get_the_title(),
			'{open_area}'      => $this->open_area(),
			'{best_fit}'       => $this->best_fit(),
			'{visual_density}' => $this->pattern_meta( 'visual_density' ),
			'{repeat}'         => $this->pattern_meta( 'repeat_behavior' ),
		);
		return strtr( $phrase, $map );
	}

	/**
	 * The pattern code from a title ("Apiary | Architectural Metal
	 * Screen Pattern" → "Apiary"); the card's h3 carries the code, as
	 * the replica's cards do.
	 *
	 * @param string $title The product title.
	 * @return string The code.
	 */
	private function code_of_title( string $title ): string {
		$parts = explode( '|', $title );
		return trim( $parts[0] );
	}
}
