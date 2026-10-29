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
 * context, returns the value or the render it was asked for, and carries
 * zero inline styles and zero design decisions (AGENTS.md §5: any visual
 * fix lands in a template or a WindPress volume, never here).
 *
 * Two of them no longer write markup at all: ``[md_pdp_related]`` and
 * ``[md_series_grid]`` hand their items to `Cards`, which renders the
 * repository partials ``pdp-related-card`` and ``hub-series-card``. What
 * stays here is the selection — WooCommerce's `wc_get_related_products()`
 * for the PDP, `Catalog::series_visible_counts()` for the hub, and the same
 * guards and ordering as before — because that is data, and the markup is
 * the templates'.
 *
 * The order the spec rules by is native first, and every shortcode here
 * exists because the native layer was exhausted for its value:
 *
 * - ``[md_pdp_material]`` — the spec bar's Material cell is a constant
 *   (owner ruling 2026-10-24 revision 6), and WooCommerce has no
 *   "global default with no per-product carrier" mechanism to render a
 *   constant cell; the template layer renders it, and this assembly is
 *   the template layer's data arm.
 * - ``[md_pdp_design_intent slot=…]`` — the design-intent prose resolved
 *   from the product's own Woo/ACF fields (``summary``, then the
 *   product excerpt) and, when the pattern carries neither, from its
 *   series ``product_cat`` description; a pattern with no prose anywhere
 *   in Woo renders nothing rather than an invented sentence. The hero
 *   sentence is the site's one wording with the product's best-fit word
 *   substituted (the MATERIAL cell's situation: a site-wide constant,
 *   not per-product data). Nothing here reads a repository file at
 *   render time (owner ruling 2026-10-26: Woo is the only source daily
 *   maintenance edits, so a copy file in the checkout can never be the
 *   thing a visitor reads).
 * - ``[md_pdp_open_area]`` / ``[md_pdp_best_fit]`` — ACF numbers and
 *   keys rendered as their display words, including the sixteen-gap
 *   "On request" rule (spec 08 D2: no invented values); LiveCanvas's
 *   ``lc_get_cf`` prints the raw meta, an empty string when the value
 *   is absent, and has no conditional fallback of its own.
 * - ``[md_pdp_image slot=…]`` — the old theme's media slots (hero = the
 *   first scene, plate = the first detail, else finish, else the second
 *   accent, else a scene, square = the second scene else accent, wide = the
 *   first accent when unused); LiveCanvas has ``lc_the_thumbnail_url`` for
 *   the featured image and no tag for the alt-role algorithm the old PDP
 *   derived its slots from.
 * - ``[md_pdp_related]`` — the adjacent-patterns cards;
 *   ``lc_wc_related`` and Woo's own shortcodes render the WooCommerce
 *   card template, not the replica's card, so the selection is read here
 *   and drawn by `Cards` from the `pdp-related-card` partial.
 * - ``[md_series_grid]`` — the hub's series cards; LiveCanvas's
 *   ``lc_get_terms`` renders badge lists, and Woo's product shortcodes
 *   list products, not series. Its two content fields are WooCommerce's
 *   own: the card image is the term's `thumbnail_id` (the field the
 *   Products → Categories screen writes) and the card copy is the
 *   term's description. The card markup is the `hub-series-card` partial.
 *
 * ``md_pdp_style()`` is a plain function (called through LiveCanvas's
 * native ``[lc_wp_func]``): the series word from the product's
 * product_cat term — the eyebrow and the spec bar's Style cell both
 * carry it, and no native tag renders a term slug's tail.
 */
final class Shortcodes {

	/**
	 * The card renderer: the related-pattern and series cards' markup is the
	 * templates' (AGENTS §5), so this class selects and hands over.
	 *
	 * @var Cards
	 */
	private readonly Cards $cards;

	/**
	 * Takes the card renderer.
	 *
	 * @param Cards|null $cards The renderer, the site's own by default.
	 */
	public function __construct( ?Cards $cards = null ) {
		$this->cards = $cards ?? new Cards();
	}

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
	 * The slots md_pdp_image resolves.
	 */
	public const SLOTS = array( 'hero-bg', 'plate', 'square', 'wide' );

	/**
	 * The most adjacent-pattern cards a PDP draws.
	 *
	 * The old site's samples carried three and four side by side, so this is
	 * the ceiling `wc_get_related_products()` is asked for and not a count to
	 * pad up to: a pattern with fewer neighbours draws fewer cards.
	 */
	public const RELATED_LIMIT = 4;

	/**
	 * Each product's resolved slots, memoized for the request.
	 *
	 * The template asks for a slot's existence and then for its element —
	 * `[lc_if condition="md_pdp_slot_has_image" param="plate"]` around
	 * `[md_pdp_image slot="plate"]` — and the two run on different
	 * instances, because the guard reaches the assembly through a plain
	 * function. Resolving twice meant replaying the whole derivation (the
	 * gallery read, and a role/alt meta read per attachment) twice per
	 * slot, eight times over a full PDP for a four-slot page.
	 *
	 * Keyed by product id, then by slot, so two products rendered in one
	 * request can never answer for each other; the value is the slot's
	 * attachment id, or null for a slot with no drawable image. A static
	 * lives for the PHP request that filled it, which is the scope
	 * wanted: a product edited afterwards is a new request.
	 *
	 * @var array<int, array<string, int|null>>
	 */
	private static array $slots = array();

	/**
	 * The adjacent-patterns cards.
	 */
	public const RELATED = 'md_pdp_related';

	/**
	 * The hub's series cards.
	 */
	public const SERIES_GRID = 'md_series_grid';

	/**
	 * The site-wide hero sentence, with the product's best-fit word (spec
	 * 08 D2: every series shares the sentence, only the word varies) and
	 * the same sentence without it, for a pattern whose `best_fit` field
	 * is empty — a sentence that still reads, not a dangling "in .".
	 */
	private const HERO_SENTENCE     = 'Built for light behavior, privacy, and surface impact.';
	private const HERO_SENTENCE_FIT = 'Built for light behavior, privacy, and surface impact in %s.';

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
	 * The Design intent assembly (spec 08 D2): the PDP's prose, resolved
	 * from fields WooCommerce already holds. The old PDP rendered two
	 * paragraphs here — a style blurb and the pattern's notable effect —
	 * and both survive as product data:
	 *
	 * - hero: the site-wide sentence with this pattern's best-fit word.
	 * - lead: the pattern's own copy — the ACF `summary` (the
	 *   forty-three carried-over summaries, spec 08 D2: an asset,
	 *   rewritten not recreated), else the product's short description
	 *   (the same value in WooCommerce's own carrier, editable on the
	 *   product screen), else its series' term description.
	 * - supplemental: the ACF `notable_effect` — the old site's second
	 *   paragraph, carried 146/146 (migration restore) — with the series
	 *   term description as its fallback, and nothing when the lead
	 *   already printed that sentence.
	 *
	 * A pattern with none of those renders an empty string: the honest
	 * answer for a product whose prose nobody has written yet, never an
	 * invented sentence. The template asks has_design_intent() first, so
	 * the empty paragraph is left out of the DOM rather than printed.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes: slot = hero|lead|supplemental.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The assembled text, empty when the pattern holds no prose.
	 */
	public function design_intent( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$slot = is_array( $atts ) && isset( $atts['slot'] ) ? (string) $atts['slot'] : 'lead';
		if ( 'hero' === $slot ) {
			return esc_html( $this->hero_sentence() );
		}
		if ( 'supplemental' === $slot ) {
			return esc_html( $this->supplemental_text() );
		}
		return esc_html( $this->lead_text() );
	}

	/**
	 * Whether a design-intent slot resolves to text — the predicate the
	 * template's ``[lc_if]`` asks before it draws the slot's paragraph.
	 * It asks design_intent() itself, so a paragraph and its content can
	 * never disagree.
	 *
	 * @param string $slot hero|lead|supplemental.
	 * @return bool
	 */
	public function has_design_intent( string $slot ): bool {
		return '' !== $this->design_intent( array( 'slot' => $slot ) );
	}

	/**
	 * The hero sentence: the site-wide wording with the pattern's own
	 * best-fit word, or the wording alone when the field is empty.
	 *
	 * @return string
	 */
	private function hero_sentence(): string {
		$word = $this->best_fit_word();
		return '' === $word ? self::HERO_SENTENCE : sprintf( self::HERO_SENTENCE_FIT, $word );
	}

	/**
	 * The lead paragraph: the series' style blurb — the sentence the old
	 * PDP rendered first, one per family — then the pattern's own copy.
	 *
	 * The old site's design-intent block was two lines: a series-level
	 * style blurb and the pattern's notable effect. The blurb leads here
	 * because that is the order the old page used, and because it is what
	 * gives the block its series context; the pattern-specific fields are
	 * the fallbacks, not the other way round.
	 *
	 * @return string The text, '' when nothing in WooCommerce holds prose.
	 */
	private function lead_text(): string {
		$blurb = $this->series_style_blurb();
		if ( '' !== $blurb ) {
			return $blurb;
		}
		$summary = trim( $this->pattern_meta( 'summary' ) );
		if ( '' !== $summary ) {
			return $summary;
		}
		$post = get_post();
		$body = null === $post ? '' : trim( (string) $post->post_excerpt );
		if ( '' !== $body ) {
			return $body;
		}
		return $this->series_description();
	}

	/**
	 * The current series' style blurb (ACF `style_blurb` on the
	 * product_cat term, edited on Products → Categories).
	 *
	 * @return string The sentence, '' without a series or without a blurb.
	 */
	private function series_style_blurb(): string {
		$term = $this->series_term();
		if ( null === $term ) {
			return '';
		}
		$value = get_term_meta( (int) $term->term_id, 'style_blurb', true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The supplemental paragraph: the pattern's own notable effect — the
	 * old PDP's design-intent second line ("Filtered light and pattern
	 * shadow", "Decorative screening"), carried as ACF product data.
	 *
	 * The series description is the fallback only when the product holds
	 * no effect of its own, and never when the lead already printed the
	 * same sentence: the block must read as one pattern's copy, with the
	 * family's line as its last resort rather than its default.
	 *
	 * @return string The text, '' when there is none to add.
	 */
	private function supplemental_text(): string {
		$lead   = $this->lead_text();
		$effect = trim( $this->pattern_meta( 'notable_effect' ) );
		if ( '' !== $effect && $effect !== $lead ) {
			return $effect;
		}
		$series = $this->series_description();
		return $series === $lead ? '' : $series;
	}

	/**
	 * The current pattern's series term description (the family's own
	 * copy, edited on Products → Categories).
	 *
	 * @return string The text, '' without a series or without a description.
	 */
	private function series_description(): string {
		$term = $this->series_term();
		return null === $term ? '' : trim( (string) $term->description );
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
		return esc_html( $this->open_area_text() );
	}

	/**
	 * The open-area cell's unescaped text, so the cell and any composed
	 * line share one resolution.
	 *
	 * @return string "NN%" or "On request".
	 */
	private function open_area_text(): string {
		$value = $this->pattern_meta( 'open_area' );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return self::OPEN_AREA_ON_REQUEST;
		}
		$number = (float) $value;
		$digits = floor( $number ) === $number ? 0 : 2;
		return number_format( $number, $digits ) . '%';
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
		return esc_html( $this->best_fit_word() );
	}

	/**
	 * The best-fit key as its display word.
	 *
	 * @return string The word, '' when the field is absent.
	 */
	private function best_fit_word(): string {
		$key = $this->pattern_meta( 'best_fit' );
		if ( '' === $key ) {
			return '';
		}
		return self::BEST_FIT_WORDS[ $key ] ?? ucwords( str_replace( '-', ' ', $key ) );
	}

	/**
	 * The slot's image element. The old PDP did not take media by position:
	 * it classified every image by its alt role and then derived the slots
	 * (product-pattern-cinematic.liquid lines 79–191, reproduced in
	 * docs/research/2026-10-25-image-slot-map.md §2.1):
	 *
	 * - hero background: the first scene, else nothing (the old template's
	 *   gold-grid fallback, which this template does not draw);
	 * - hero plate: the first detail, else the first finish, else the second
	 *   accent, else a scene (never a cut sheet and never the unnamed
	 *   residual — the order and its measurements are in slot_map());
	 * - identity square: the second scene, else the first accent that is
	 *   not the plate, else nothing;
	 * - wide: the first accent, only when no other slot took it.
	 *
	 * No two slots share an image, and a slot with no qualifying image
	 * returns '' and has_image() is false, so the template draws neither the
	 * element nor its frame — no ``src=""`` ever reaches the browser (the
	 * five two-image patterns' square). The element carries the
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
		$id   = $this->slot_attachment( $slot );
		if ( null === $id ) {
			return '';
		}
		$decorative = 'hero-bg' === $slot;
		$post       = get_post();
		$fallback   = null === $post ? '' : (string) get_the_title( $post );
		$alt        = $decorative ? '' : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' === $alt && ! $decorative ) {
			$alt = $fallback;
		}
		return $this->cards->slot_image( $id, $alt, $decorative, $slot );
	}

	/**
	 * Whether a slot resolves to an image — the predicate the template's
	 * ``[lc_if]`` asks before it draws the slot's frame (ticket #68 kept the
	 * empty frames in the DOM, and ``:has(.pdp-hero-plate)`` then matched
	 * whether or not the slot carried an image, so the old theme's solo
	 * layouts never applied). The guards are image()'s own: one predicate,
	 * so a frame and its img can never disagree.
	 *
	 * @param string $slot hero-bg|plate|square|wide.
	 * @return bool
	 */
	public function has_image( string $slot ): bool {
		return null !== $this->slot_attachment( $slot );
	}

	/**
	 * The attachment id a slot resolves to and can be drawn — validated
	 * slot, product context, and a resolvable full-size URL.
	 *
	 * The product's four slots are resolved together on first ask and
	 * memoized, so the template's guard and its element read one answer
	 * from one pass (see self::$slots).
	 *
	 * @param string $slot hero-bg|plate|square|wide.
	 * @return int|null
	 */
	private function slot_attachment( string $slot ): ?int {
		if ( ! in_array( $slot, self::SLOTS, true ) ) {
			return null;
		}
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return null;
		}
		$post_id = (int) $post->ID;
		if ( ! isset( self::$slots[ $post_id ] ) ) {
			self::$slots[ $post_id ] = $this->slot_map( $post_id );
		}
		return self::$slots[ $post_id ][ $slot ] ?? null;
	}

	/**
	 * Forgets every memoized slot resolution.
	 *
	 * The memo is per request in production: a static lives for the PHP
	 * request that filled it and is gone with it. A test process runs many
	 * requests in one process, so the suite resets between tests; no live
	 * path calls this.
	 */
	public static function flush(): void {
		self::$slots = array();
	}

	/**
	 * One product's four slots, resolved in a single pass.
	 *
	 * The derivations are not independent — plate and square each avoid
	 * the ids the earlier slots took, and wide only exists when the first
	 * accent is still free — so computing them together is both what the
	 * "no two slots share an image" rule already required and what makes
	 * one resolution answer every slot.
	 *
	 * A slot with no qualifying image maps to null, and so does a slot
	 * whose attachment has no resolvable full-size URL: the guard and the
	 * element read the same value, so a frame can never be drawn around a
	 * missing image.
	 *
	 * @param int $post_id The product id.
	 * @return array<string, int|null> The slot map, one entry per slot.
	 */
	private function slot_map( int $post_id ): array {
		$ids = $this->media_ids( $post_id );

		$by_class = array();
		foreach ( $ids as $id ) {
			$by_class[ $this->media_class( $id ) ][] = $id;
		}

		$hero = isset( $by_class['scene'][0] ) ? (int) $by_class['scene'][0] : null;

		if ( null !== $hero ) {
			// The plate is the hero's companion frame — a 3:4 portrait box —
			// so the old theme's order (a detail close-up, then a finish) is
			// kept first and continued into the classes this catalogue really
			// carries. Measured on the dev instance 2026-10-29 over its 151
			// published patterns: `detail` sits in 3 of their galleries and
			// `finish` in 2, so the two-class rule resolved a plate on 5
			// products and left the other 141 heroes without one. Nothing
			// reported that: `.pdp-hero-plate:empty` hides the frame and
			// `.pdp-hero-grid-cols:not(:has(.pdp-hero-plate))` collapses the
			// hero to one column, so every page looked deliberate.
			//
			// The order that follows resolves 136 of the 151 (90%), against 5
			// (3%) before. What is left over is the pattern whose media holds
			// no portrait-friendly picture at all — the five `apiary-smoke`
			// products carry no image, and ten patterns show only scenes plus
			// a flat render.
			//
			// `accent` (96 galleries) comes next, from its SECOND image: the
			// first accent is the wide slot's, the one image the old template
			// reserved for it, and every accent is a landscape pattern render
			// (479 of 479, median 1.76) — a uniform field that survives a
			// portrait crop, unlike a composed photograph.
			//
			// `scene` (146 galleries) follows. It is usually a single image
			// and the hero already took it, so this pool needs a second scene
			// to give the plate anything at all.
			//
			// The chain stops there. `other` is the migrator's residual — the
			// `pattern render` and `reference image` roles, plus `unknown` and
			// a `qc photo`, which are workshop photographs and not the pattern
			// — and ROLE_CLASSES documents that residual as filling the first
			// position only. A cut sheet and an unnamed workshop photo are
			// both the wrong picture for a 3:4 hero frame.
			$plate = $this->first_in_order(
				array(
					$by_class['detail'] ?? array(),
					$by_class['finish'] ?? array(),
					array_slice( $by_class['accent'] ?? array(), 1 ),
					$by_class['scene'],
				),
				array( $hero )
			);

			// Last resort, and only for the flat pattern sample.
			//
			// Four patterns own no photograph the hero has not already taken
			// and no accent at all: poseidon, pebble-creek, funk and alhambra.
			// Their galleries hold one landscape scene plus one `pattern
			// render` — a two-tone sample of the pattern itself. The chain
			// above leaves them solo, which is what the old site did too
			// (measured 2026-10-29: its alhambra page carries the
			// `pdp-hero-plate` rules and `pdp-hero-solo`, no plate element),
			// so filling them is an upgrade the owner asked for, not a
			// replica gap.
			//
			// A sample is the right picture for the slot and a repeated
			// photograph is not: the hero background is the same scene at
			// `object-fit: cover` under a scrim that still leaves 58% of it
			// visible at the right edge, and the plate sits at that edge
			// (`justify-self: end`) — the two would read as one image shown
			// twice. A sample reads as a pattern swatch: measured on all four
			// (their 3:4 centre crop keeps only repeating pattern), no
			// perspective, no board thickness, no ground, so it cannot be
			// mistaken for an installed project.
			//
			// `reference image` and `qc photo` stay out: those are the
			// bedroom-wide shot with the screen as background, and a workshop
			// photograph. Only the role that names a pattern sample enters.
			if ( null === $plate ) {
				$plate = $this->first_unused( $this->ids_with_role( $ids, 'pattern render' ), array( $hero ) );
			}
		} else {
			$plate = $this->first_non_cutsheet( $ids );
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

		// The old template offers the first accent only, and only when no
		// other slot already took it (context_media, liquid lines 154–169).
		//
		// Measured on the dev instance 2026-10-29: this resolves on the four
		// patterns whose square has a scene of its own to take (winter-branches,
		// white-sands, shattered, quiet-wood — the same four the old page drew
		// the 21:9 band for), and on no others. Where the product has no second
		// scene the square falls back to `accent[0]`, which is the definition
		// working: one image never fills two slots. The plate taking
		// `accent[1:]` above is what keeps the band alive here — taking
		// `accent[0]` for the plate emptied it on all 96 accent-carrying
		// patterns instead of these four.
		$accent = isset( $by_class['accent'][0] ) ? (int) $by_class['accent'][0] : null;
		$wide   = null;
		if ( null !== $accent ) {
			$used = array_filter( array( $hero, $plate, $square ), static fn( $id ) => null !== $id );
			$wide = in_array( $accent, $used, true ) ? null : $accent;
		}

		$map = array(
			'hero-bg' => $hero,
			'plate'   => $plate,
			'square'  => $square,
			'wide'    => $wide,
		);
		foreach ( $map as $slot => $id ) {
			if ( null !== $id && '' === (string) wp_get_attachment_image_url( $id, 'full' ) ) {
				$map[ $slot ] = null;
			}
		}
		return $map;
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
	 * The media ids whose migrator role is exactly one phrase.
	 *
	 * The class vocabulary collapses eleven roles into six, and the plate's
	 * last resort needs one of them back: `pattern render` shares the `other`
	 * bucket with `reference image`, `unknown` and a `qc photo`, and only the
	 * first of those is a flat sample of the pattern. Reading the role here
	 * keeps that distinction without adding a seventh media class the rest of
	 * the resolution has no use for.
	 *
	 * @param int[]  $ids  The product's media ids.
	 * @param string $role The `_md_role` value to keep.
	 * @return int[] The matching ids, in the order given.
	 */
	private function ids_with_role( array $ids, string $role ): array {
		$kept = array();
		foreach ( $ids as $id ) {
			if ( (string) get_post_meta( (int) $id, '_md_role', true ) === $role ) {
				$kept[] = (int) $id;
			}
		}
		return $kept;
	}

	/**
	 * The first id in a class order, skipping the ids another slot holds.
	 *
	 * The plate is chosen across several media classes rather than from one
	 * (the order and its measurements are in slot_map()), so this walks the
	 * classes in order and returns the first candidate the earlier slots did
	 * not take. Each class keeps its own media order, which is what makes the
	 * pick stable across requests.
	 *
	 * @param array<int, int[]> $pools The candidate ids per class, in priority order.
	 * @param int[]             $used  Ids another slot already holds.
	 * @return int|null
	 */
	private function first_in_order( array $pools, array $used ): ?int {
		foreach ( $pools as $ids ) {
			$id = $this->first_unused( $ids, $used );
			if ( null !== $id ) {
				return $id;
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
	 * The adjacent-patterns cards: the WooCommerce related products of
	 * the current pattern, in the replica's card markup (the exhaustion
	 * proof is the class docblock — the native related mechanisms render
	 * Woo's own card template, not this one).
	 *
	 * Four is a ceiling, not a quota: `wc_get_related_products()` is asked for
	 * RELATED_LIMIT and returns however many the catalogue has for this
	 * pattern — the old site's own samples ran 3 and 4 side by side, and the
	 * grid draws one track per card (see `pdp.css`), so a pattern with three
	 * neighbours shows three cards in a four-track row rather than three
	 * cards stretched across it. No product is padded in or invented to reach
	 * four, and `Cards::related()` still drops any card whose product has no
	 * thumbnail, which is the one case where the count can fall below what
	 * WooCommerce returned.
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
		return $this->cards->related( (int) $post->ID, wc_get_related_products( $post->ID, self::RELATED_LIMIT ) );
	}

	/**
	 * The hub's series cards: the product_cat terms, each with its
	 * term-name title, its description, its hero image and the card call.
	 *
	 * Both content fields are WooCommerce's own, so an administrator
	 * edits them on Products → Categories with no repository file and no
	 * command in the path:
	 *
	 * - the image is the term's `thumbnail_id` — the field WooCommerce's
	 *   own category screen writes (`class-wc-admin-taxonomies.php`) and
	 *   its `woocommerce_subcategory_thumbnail()` renders;
	 * - the copy is the term's `description`, the field WordPress and
	 *   WooCommerce both edit.
	 *
	 * The term list is filtered by Catalog::series_visible_counts(), the same
	 * catalog-visible membership the catalog grids render, and not by
	 * `hide_empty` on the term. Those two disagree on this stack: WooCommerce's
	 * `get_terms` filter replaces `$term->count` with the cached
	 * `product_count_<taxonomy>` meta (0 when the recount never ran) while
	 * `hide_empty` is decided in SQL against the `wp_term_taxonomy.count`
	 * column, so a series can be dropped while it has visible patterns, or
	 * kept while it has none. A series whose patterns are all drafts, or all
	 * hidden from the catalog, therefore carries no card and links to no
	 * empty archive; unpublishing or hiding a series' last pattern removes it
	 * from the hub on the next request, with no command and no list to
	 * maintain.
	 *
	 * A term with no `thumbnail_id` renders no `<img>` — no placeholder
	 * image, no invented picture. The card's media box still carries the
	 * series-name fallback its CSS already styles, hidden when an image
	 * is present.
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
		$counts = \MetalDivider\Site\Catalog::series_visible_counts();
		$out    = '';
		foreach ( $terms as $term ) {
			if ( ( $counts[ $term->slug ] ?? 0 ) < 1 ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$out .= $this->cards->series_card(
				array(
					'term_id'     => (int) $term->term_id,
					'name'        => (string) $term->name,
					'description' => (string) $term->description,
					'link'        => (string) $link,
				)
			);
		}
		// The card partial hands back its own `[md_each …]`, and WordPress
		// expands a shortcode's output only when something asks again —
		// `do_shortcode()` does not recurse into the string it just produced.
		// Without this pass the hub rendered nine cards whose every image was
		// the literal text `[md_media_url]`: nine broken images on the page
		// that introduces the whole catalog (measured 2026-10-27, dev and
		// production). The inner fields are the same `md_*` shortcodes this
		// class registers, so one more pass resolves them.
		return \do_shortcode( $out );
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
		$shortcodes = new self();
		$term       = $shortcodes->series_term();
		if ( null === $term ) {
			return '';
		}
		$slug = (string) $term->slug;
		return str_starts_with( $slug, 'pattern-' ) ? substr( $slug, strlen( 'pattern-' ) ) : $slug;
	}

	/**
	 * The current pattern's series term (its first product_cat), the term
	 * whose description is the lead paragraph's last resort.
	 *
	 * @return \WP_Term|null The term, null outside a product or without a series.
	 */
	private function series_term(): ?\WP_Term {
		$post = get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return null;
		}
		$terms = get_the_terms( $post->ID, 'product_cat' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		return $terms[0];
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
}
