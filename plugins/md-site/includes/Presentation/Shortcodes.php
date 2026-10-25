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
 * - ``[md_pdp_image slot=…]`` — the old theme's media slots (hero = the
 *   first scene, plate = the first detail else finish, square = the second
 *   scene else accent, wide = the first accent when unused); LiveCanvas has
 *   ``lc_the_thumbnail_url`` for the featured image and no tag for the
 *   alt-role algorithm the old PDP derived its slots from.
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
	 * The migrator's eleven `_md_role` phrases collapsed to the old theme's
	 * six media classes (docs/research/2026-10-25-image-slot-map.md §3.1).
	 * Anything outside this table is "other" and only fills the first
	 * position — except that its alt text still gets the old substring
	 * classification below, because the eleven-phrase vocabulary does not
	 * cover every alt the old theme recognised (the sixty
	 * "pattern installation scene visualization" images are the measured
	 * case: `_md_role` unknown, old alt class scene).
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
	 * The old theme's alt substrings, in its first-match order
	 * (product-pattern-cinematic.liquid lines 79–111): cut sheets are
	 * excluded first, finish precedes scene, and the rest fall through to
	 * "other". The order is load-bearing — an image whose alt carries both
	 * a finish and a scene phrase is a finish preview.
	 */
	private const ALT_CLASSES = array(
		'cutsheet' => array( 'cut sheet', 'cad', 'drawing', 'scale preview' ),
		'finish'   => array( 'finish preview', 'powder coat', 'anod', 'coating' ),
		'scene'    => array(
			'application scene',
			'installation scene',
			'installed screen scene',
			'installed facade',
			'facade screen',
			'interior divider',
			'privacy screen',
			'room divider',
			'project scene',
		),
		'detail'   => array( 'pattern detail', 'detail reference', 'texture detail' ),
		'accent'   => array( 'accent render' ),
	);

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
	 * The slot's image element. The old PDP did not take media by position:
	 * it classified every image by its alt role and then derived the slots
	 * (product-pattern-cinematic.liquid lines 79–191, reproduced in
	 * docs/research/2026-10-25-image-slot-map.md §2.1):
	 *
	 * - hero background: the first scene, else nothing (the old template's
	 *   gold-grid fallback, which this template does not draw);
	 * - hero plate: the first detail that is not the hero, else the first
	 *   finish that is not the hero, else nothing;
	 * - identity square: the second scene, else the first accent that is
	 *   not the plate, else nothing;
	 * - wide: the first accent, only when no other slot took it.
	 *
	 * No two slots share an image, and a slot with no qualifying image
	 * returns '' — the template hides it, so no ``src=""`` ever reaches the
	 * browser (the five two-image patterns' square). The element carries the
	 * selected attachment's real width and height (omitted when the
	 * metadata has none) and its own alt; the hero is the template's
	 * decorative background and keeps the empty alt the old theme gave it.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: slot = hero-bg|plate|square|wide.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The img element, empty when the slot has no image.
	 */
	public function image( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$slot = is_array( $atts ) && isset( $atts['slot'] ) ? (string) $atts['slot'] : '';
		if ( ! in_array( $slot, array( 'hero-bg', 'plate', 'square', 'wide' ), true ) ) {
			return '';
		}
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return '';
		}
		$id = $this->slot_image_id( $slot, (int) $post->ID );
		if ( null === $id ) {
			return '';
		}
		return $this->image_element( $id, 'hero-bg' === $slot, (string) get_the_title( $post ) );
	}

	/**
	 * The attachment id a slot resolves to, or null when it has none.
	 *
	 * @param string $slot    hero-bg|plate|square|wide.
	 * @param int    $post_id The product id.
	 * @return int|null
	 */
	private function slot_image_id( string $slot, int $post_id ): ?int {
		$ids = $this->media_ids( $post_id );

		$by_class = array();
		foreach ( $ids as $id ) {
			$by_class[ $this->media_class( $id ) ][] = $id;
		}

		$hero = isset( $by_class['scene'][0] ) ? (int) $by_class['scene'][0] : null;
		if ( 'hero-bg' === $slot ) {
			return $hero;
		}

		if ( null !== $hero ) {
			$plate = $this->first_unused( $by_class['detail'] ?? array(), array( $hero ) );
			if ( null === $plate ) {
				$plate = $this->first_unused( $by_class['finish'] ?? array(), array( $hero ) );
			}
		} else {
			$plate = $this->first_non_cutsheet( $ids );
		}
		if ( 'plate' === $slot ) {
			return $plate;
		}

		$square = null;
		if ( null !== $hero ) {
			$scene_rest = array_slice( $by_class['scene'], 1 );
			$used       = null === $plate ? array( $hero ) : array( $hero, $plate );
			$square     = $this->first_unused( $scene_rest, $used );
			if ( null === $square ) {
				$square = $this->first_unused( $by_class['accent'] ?? array(), $used );
			}
		} elseif ( null !== $plate ) {
			$square = $this->first_unused( $by_class['detail'] ?? array(), array( $plate ) );
			if ( null === $square ) {
				$square = $this->first_unused( $by_class['finish'] ?? array(), array( $plate ) );
			}
		}
		if ( 'square' === $slot ) {
			return $square;
		}

		// The old template offers the first accent only, and only when no
		// other slot already took it (context_media, liquid lines 154–169).
		$accent = isset( $by_class['accent'][0] ) ? (int) $by_class['accent'][0] : null;
		if ( null === $accent ) {
			return null;
		}
		$used = array_filter( array( $hero, $plate, $square ), static fn( $id ) => null !== $id );
		return in_array( $accent, $used, true ) ? null : $accent;
	}

	/**
	 * The product's media attachment ids in the migrated order: the
	 * featured image (old media[0]) then the gallery (old media[1:]), with
	 * a repeat dropped so no attachment can fill two slots.
	 *
	 * @param int $post_id The product id.
	 * @return int[]
	 */
	private function media_ids( int $post_id ): array {
		$ids       = array();
		$thumbnail = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail > 0 ) {
			$ids[] = $thumbnail;
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( is_object( $product ) && method_exists( $product, 'get_gallery_image_ids' ) ) {
				foreach ( $product->get_gallery_image_ids() as $id ) {
					$id = (int) $id;
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
			}
		}
		return $ids;
	}

	/**
	 * The old theme's media class for one attachment: the migrator's role
	 * when the eleven-phrase vocabulary recognises it, else the old
	 * substring classification of the attachment's alt text.
	 *
	 * @param int $id The attachment id.
	 * @return string scene|detail|finish|accent|cutsheet|other.
	 */
	private function media_class( int $id ): string {
		$role = (string) get_post_meta( $id, '_md_role', true );
		if ( isset( self::ROLE_CLASSES[ $role ] ) ) {
			return self::ROLE_CLASSES[ $role ];
		}
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return $this->classify_alt( $alt );
	}

	/**
	 * The old theme's alt classification, first match in its own order
	 * (see ALT_CLASSES).
	 *
	 * @param string $alt The attachment alt text.
	 * @return string scene|detail|finish|accent|cutsheet|other.
	 */
	private function classify_alt( string $alt ): string {
		$alt = strtolower( $alt );
		foreach ( self::ALT_CLASSES as $class => $phrases ) {
			foreach ( $phrases as $phrase ) {
				if ( str_contains( $alt, $phrase ) ) {
					return $class;
				}
			}
		}
		return 'other';
	}

	/**
	 * The first id not already used.
	 *
	 * @param int[] $ids  Candidate ids in media order.
	 * @param int[] $used Ids another slot already holds.
	 * @return int|null
	 */
	private function first_unused( array $ids, array $used ): ?int {
		foreach ( $ids as $id ) {
			if ( ! in_array( (int) $id, $used, true ) ) {
				return (int) $id;
			}
		}
		return null;
	}

	/**
	 * The first media item that is not a cut sheet (the old pos0).
	 *
	 * @param int[] $ids The media ids in order.
	 * @return int|null
	 */
	private function first_non_cutsheet( array $ids ): ?int {
		foreach ( $ids as $id ) {
			if ( 'cutsheet' !== $this->media_class( (int) $id ) ) {
				return (int) $id;
			}
		}
		return null;
	}

	/**
	 * The img element for a selected attachment: its URL, its own alt (the
	 * product title when the attachment carries none, as the old template
	 * fell back), the eager/lazy loading the slot had, and its real
	 * dimensions when the attachment metadata has them.
	 *
	 * @param int    $id         The attachment id.
	 * @param bool   $decorative Whether the slot is the decorative hero background.
	 * @param string $fallback   The product title for a missing alt.
	 * @return string
	 */
	private function image_element( int $id, bool $decorative, string $fallback ): string {
		$url = (string) wp_get_attachment_image_url( $id, 'full' );
		if ( '' === $url ) {
			return '';
		}
		$alt = $decorative ? '' : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' === $alt && ! $decorative ) {
			$alt = $fallback;
		}
		$metadata = wp_get_attachment_metadata( $id );
		$width    = is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0;
		$height   = is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0;

		$element  = sprintf( '<img src="%s" alt="%s"', esc_url( $url ), esc_attr( $alt ) );
		$element .= $decorative ? ' loading="eager" fetchpriority="high"' : ' loading="lazy"';
		if ( $width > 0 && $height > 0 ) {
			$element .= sprintf( ' width="%d" height="%d"', $width, $height );
		}
		return $element . '>';
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
