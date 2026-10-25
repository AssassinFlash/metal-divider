<?php
/**
 * The pattern catalog's data plane: the card, the pagination, the result
 * count, and the archive query's search/sort inputs.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site;

/**
 * The shop archive and the series archives render the same card. The
 * converted templates carried one card's literal text inside the loop
 * (every card would repeat "Agave"), so the card is assembled here from
 * the current product — semantic markup and class names only, no design
 * decisions (AGENTS §5). The pagination and the result summary are here
 * for the same reason: WordPress's native mechanisms need a place to
 * print, and LiveCanvas's loop has no counter or pager tag.
 *
 * The search and sort controls post back to the archive itself; the
 * pre_get_posts filter turns their GET parameters into the main query.
 * This is the server-side replacement for the old theme's Shopify
 * predictive-search JavaScript (stripped from the templates, spec 19 T3).
 */
final class Catalog {

	/**
	 * The card renderer: the markup is the templates' (AGENTS §5), so this
	 * class assembles data and hands it over.
	 *
	 * @var \MetalDivider\Site\Presentation\Cards
	 */
	private readonly \MetalDivider\Site\Presentation\Cards $cards;

	/**
	 * Takes the card renderer.
	 *
	 * @param \MetalDivider\Site\Presentation\Cards|null $cards The renderer, the site's own by default.
	 */
	public function __construct( ?\MetalDivider\Site\Presentation\Cards $cards = null ) {
		$this->cards = $cards ?? new \MetalDivider\Site\Presentation\Cards();
	}

	/**
	 * The card shortcode.
	 */
	public const CARD = 'md_pattern_card';

	/**
	 * The pager shortcode.
	 */
	public const PAGINATION = 'md_pattern_pagination';

	/**
	 * The result-count shortcode.
	 */
	public const SUMMARY = 'md_pattern_results_summary';

	/**
	 * The archive sidebar's series navigation.
	 */
	public const SERIES_NAV = 'md_series_nav';

	/**
	 * The current series archive's own URL.
	 */
	public const SERIES_CURRENT_URL = 'md_series_current_url';

	/**
	 * The current series archive's link label.
	 */
	public const SERIES_CURRENT_LABEL = 'md_series_current_label';

	/**
	 * The custom-screens pattern library's tiles, one panel at a time.
	 */
	public const TILES = 'md_pattern_tiles';

	/**
	 * The home page's pattern-direction cards.
	 */
	public const CARDS = 'md_pattern_cards';

	/**
	 * The published catalog's size, for the sidebar's "All Patterns" link.
	 */
	public const TOTAL = 'md_pattern_total';

	/**
	 * The count of one editorial card group's resolvable cards.
	 */
	public const GROUP_COUNT = 'md_pattern_group_count';

	/**
	 * The archive tools' form state: the hidden field that carries the other
	 * control's value through a submission, and the `selected` marker the
	 * sort menu needs so it shows the order actually in force.
	 */
	public const PRESERVE = 'md_preserve_param';

	/**
	 * The sort menu's selected option marker.
	 */
	public const SORT_SELECTED = 'md_sort_selected';

	/**
	 * The search box's current term, so a submitted search stays in the box.
	 */
	public const SEARCH_VALUE = 'md_search_value';

	/**
	 * The archive tools' form action: the archive's own page-one URL.
	 */
	public const ARCHIVE_ACTION = 'md_archive_form_action';

	/**
	 * The catalog's empty state, when the main query found no pattern.
	 */
	public const EMPTY_STATE = 'md_catalog_empty';

	/**
	 * The sort values the archive accepts (filter_query's switch cases, plus
	 * the default). Anything else is treated as the default, so the menu
	 * never shows a selection the query did not apply.
	 *
	 * @var list<string>
	 */
	private const SORT_VALUES = array( 'created-descending', 'title-ascending', 'title-descending' );

	/**
	 * The sort the archive applies when the request names none.
	 */
	private const SORT_DEFAULT = 'title-ascending';

	/**
	 * The sidebar's series order: the old sidebar's sequence with the
	 * gradient series inserted after geometric, the series it was carved
	 * out of (issue #25 moved La Pente, Nara and Skyfall there). The old
	 * list had no gradient entry at all because the Shopify collection was
	 * empty, which is also why the old counts are stale against the
	 * migration (geometric 15 → 12, gradient 0 → 3). A term outside this
	 * list is rendered after these, ordered by name, so a series can never
	 * disappear from the navigation by being forgotten here.
	 *
	 * @var list<string>
	 */
	private const SERIES_ORDER = array(
		'pattern-perforated',
		'pattern-geometric',
		'pattern-gradient',
		'pattern-abstract',
		'pattern-organic',
		'pattern-landscape',
		'pattern-vintage-retro',
		'pattern-asian',
		'pattern-moroccan-arabic',
	);

	/*
	 * The best-fit word table and the "On request" wording moved to
	 * Presentation\PatternFacts, which is where the cards read them. They are
	 * not repeated here: one rule with two copies is a rule that drifts, and
	 * the copy that stops being read is the one nobody notices is wrong.
	 */


	/**
	 * Registers the shortcodes and the query filter.
	 */
	public function register(): void {
		\add_shortcode( self::CARD, array( $this, 'card' ) );
		\add_shortcode( self::PAGINATION, array( $this, 'pagination' ) );
		\add_shortcode( self::SUMMARY, array( $this, 'summary' ) );
		\add_shortcode( self::SERIES_NAV, array( $this, 'series_nav' ) );
		\add_shortcode( self::SERIES_CURRENT_URL, array( $this, 'series_current_url' ) );
		\add_shortcode( self::SERIES_CURRENT_LABEL, array( $this, 'series_current_label' ) );
		\add_shortcode( self::TILES, array( $this, 'tiles' ) );
		\add_shortcode( self::CARDS, array( $this, 'cards' ) );
		\add_shortcode( self::TOTAL, array( $this, 'total' ) );
		\add_shortcode( self::GROUP_COUNT, array( $this, 'group_count' ) );
		\add_shortcode( self::PRESERVE, array( $this, 'preserve_param' ) );
		\add_shortcode( self::SORT_SELECTED, array( $this, 'sort_selected' ) );
		\add_shortcode( self::SEARCH_VALUE, array( $this, 'search_value' ) );
		\add_shortcode( self::ARCHIVE_ACTION, array( $this, 'archive_form_action' ) );
		\add_shortcode( self::EMPTY_STATE, array( $this, 'empty_state' ) );
		\add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
	}

	/**
	 * The catalog sidebar's series links, with the current series marked.
	 *
	 * Both catalog faces (the shop and the series archive) carried the
	 * same hand-written list, with one `is-active` on a literal link: the
	 * archive named Organic, so every series showed Organic as the
	 * visitor's position. This renders the list from the site's own terms
	 * instead — the label, count and URL come from the `product_cat` term,
	 * and `is-active` from the queried object, the term WordPress resolved
	 * for this request. Nothing here names a series, so a new series needs
	 * no template edit, and the gradient series (which the old list
	 * omitted entirely, its Shopify collection being empty) is rendered
	 * with its three patterns.
	 *
	 * The face is declared by the template, not inferred: `face="shop"`
	 * (the shop's own sidebar, which marks its "All Patterns" link itself)
	 * renders the list unmarked, and no attribute means "mark the queried
	 * term". Inferring it from `is_shop()` would put a WooCommerce
	 * conditional in the data arm and leave the branch untestable off a
	 * live store; the template already knows which face it is.
	 *
	 * Only series that hold at least one catalog-visible published pattern
	 * are listed: a series whose last pattern was unpublished, or hidden
	 * from the catalog, has an archive page with nothing on it, so offering
	 * it in the sidebar sends the visitor to an empty grid. That is the same
	 * coupling the grids have — the term is the curation, and a visible
	 * published product is what makes it real. The count is
	 * series_visible_counts(), the same membership the grids render, never
	 * `$term->count` (that value is context-dependent on this stack).
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: face = shop.
	 * @return string The nav's links, empty when there is no series to list.
	 */
	public function series_nav( $atts = array() ): string {
		$atts    = \shortcode_atts( array( 'face' => '' ), (array) $atts, self::SERIES_NAV );
		$face    = (string) $atts['face'];
		$current = \get_queried_object();
		if ( ! $current instanceof \WP_Term || 'product_cat' !== $current->taxonomy ) {
			$current = null;
			if ( 'shop' !== $face ) {
				return '';
			}
		}
		$terms = \get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( \is_wp_error( $terms ) || array() === $terms ) {
			return '';
		}
		// Membership is the same catalog-visible set the grids render, not
		// the term's own count: `hide_empty` decides on the raw column and
		// `$term->count` is a cached meta value, so the term alone can list a
		// series that has no visible pattern and omit one that has
		// (series_visible_counts() carries the measurements).
		$counts = self::series_visible_counts();

		$by_slug = array();
		foreach ( $terms as $term ) {
			if ( ( $counts[ $term->slug ] ?? 0 ) > 0 ) {
				$by_slug[ $term->slug ] = $term;
			}
		}
		if ( array() === $by_slug ) {
			return '';
		}
		$ordered = array();
		foreach ( self::SERIES_ORDER as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}
		usort( $by_slug, static fn( \WP_Term $a, \WP_Term $b ): int => strcasecmp( $a->name, $b->name ) );
		$ordered = array_merge( $ordered, $by_slug );

		$out = '';
		foreach ( $ordered as $term ) {
			$link = \get_term_link( $term );
			if ( \is_wp_error( $link ) ) {
				continue;
			}
			$is_current = null !== $current && (int) $term->term_id === (int) $current->term_id;
			$out       .= sprintf(
				'<a href="%s" class="pattern-catalog__nav-link%s"%s><span>%s</span><span class="pattern-catalog__count">%d</span></a>',
				\esc_url( $link ),
				$is_current ? ' is-active' : '',
				$is_current ? ' aria-current="page"' : '',
				\esc_html( self::series_label( $term ) ),
				(int) ( $counts[ $term->slug ] ?? 0 )
			);
		}
		return $out;
	}

	/**
	 * The current series archive's own URL, for the breadcrumb's
	 * `aria-current` item.
	 *
	 * @return string The term archive link, empty outside a series archive.
	 */
	public function series_current_url(): string {
		$current = \get_queried_object();
		if ( ! $current instanceof \WP_Term || 'product_cat' !== $current->taxonomy ) {
			return '';
		}
		$link = \get_term_link( $current );
		return \is_wp_error( $link ) ? '' : \esc_url( $link );
	}

	/**
	 * The series link's display label: the term name plus the noun the
	 * old sidebar and breadcrumb carried ("Organic Patterns" for the term
	 * "Organic"). The term name is the family name the migrator wrote
	 * (build_terms_plan stores the collection's `family` metafield), so a
	 * name that already ends in the plural noun is left alone rather than
	 * doubled.
	 *
	 * @param \WP_Term $term The series term.
	 * @return string The label text.
	 */
	private static function series_label( \WP_Term $term ): string {
		$name = (string) $term->name;
		return str_ends_with( $name, 'Patterns' ) ? $name : $name . ' Patterns';
	}

	/**
	 * The current series archive's label, for the breadcrumb's
	 * `aria-current` item — the same wording the sidebar's own link
	 * carries, so the two never disagree.
	 *
	 * @return string The label, empty outside a series archive.
	 */
	public function series_current_label(): string {
		$current = \get_queried_object();
		if ( ! $current instanceof \WP_Term || 'product_cat' !== $current->taxonomy ) {
			return '';
		}
		return \esc_html( self::series_label( $current ) );
	}

	/**
	 * The custom-screens pattern library's tiles for one series.
	 *
	 * The page used to carry nineteen hand-written tiles: a literal name, a
	 * literal image path and a literal count per tile, plus one name with no
	 * product behind it (Kasbah never existed in the catalog). Nothing tied a
	 * tile to a product, so a renamed or unpublished pattern kept its card
	 * and a new one never appeared.
	 *
	 * The tiles are the series' curated members, and the curation is
	 * WooCommerce's own `menu_order`: the column behind the Products list's
	 * Order field and behind WooCommerce's built-in "Default sorting (custom
	 * ordering + name)" catalog order. A product with `menu_order` 0 is not
	 * curated and does not appear on this page; a product with a positive
	 * `menu_order` does, and the values decide both membership and sequence.
	 * That is one WooCommerce field the owner already edits in the admin, so
	 * the page needs no handle list at render time and no sync step: set the
	 * order and the panel follows.
	 *
	 * The series term still decides which panel a product can appear on, and
	 * WooCommerce's catalog visibility still decides whether it appears at
	 * all — an unpublished product, or one hidden from the catalog, drops out
	 * of the panel exactly as it drops out of the shop archive.
	 *
	 * The markup is the converted page's own: the same `.pattern-item` /
	 * `.pattern-main-card` / `.pattern-thumbs` / `.pattern-meta` structure
	 * and the same per-pattern lightbox with its strip, so the WindPress
	 * volume and any interaction layer bind to exactly what they bound to
	 * before. The only addition is the canonical pattern link on the name
	 * (the old tiles were not links at all, and pointed nowhere).
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: term (required), limit, panel.
	 * @return string The tiles and their lightboxes, empty without a series.
	 */
	public function tiles( $atts = array() ): string {
		$atts  = \shortcode_atts(
			array(
				'term'  => '',
				'limit' => '0',
				'panel' => '',
			),
			(array) $atts,
			self::TILES
		);
		$slug  = \sanitize_title( (string) $atts['term'] );
		$ids   = $this->tile_ids( $slug, (int) $atts['limit'] );
		$panel = (string) $atts['panel'];

		$out = '';
		foreach ( $ids as $id ) {
			$out .= $this->tile( $id, $panel );
		}
		return $out;
	}

	/**
	 * The home page's pattern-direction cards for one series.
	 *
	 * The section shipped nine hand-written cards in three panes, each with
	 * a literal eyebrow, title, best-fit word, series slug and open area —
	 * five facts about a product, none of them read from the product. Two
	 * had already drifted from the catalog (the Winderoo card advertised a
	 * best-fit word the product does not carry, and the eyebrow said
	 * "Mashrabiya pattern" for a Moroccan & Arabic series). The cards are
	 * now read from the products at request time.
	 *
	 * The home page curates differently from the custom-screens panels, and
	 * it uses a different WooCommerce field for it: the featured flag
	 * (`product_visibility` → `featured`, the checkbox beside a product's
	 * Publish box). A series with nothing featured still fills its slots
	 * from its visible members, so the section never empties because nobody
	 * ticked a box. Two faces, two native fields, no list in either
	 * template.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: term (required), limit, eyebrow, featured.
	 * @return string The cards, empty without a series.
	 */
	public function cards( $atts = array() ): string {
		$atts     = \shortcode_atts(
			array(
				'term'     => '',
				'limit'    => '3',
				'eyebrow'  => '',
				'featured' => '1',
			),
			(array) $atts,
			self::CARDS
		);
		$slug     = \sanitize_title( (string) $atts['term'] );
		$featured = '0' !== (string) $atts['featured'];
		$ids      = $this->card_ids( $slug, (int) $atts['limit'], $featured );
		$eyebrow  = (string) $atts['eyebrow'];

		$out = '';
		foreach ( $ids as $id ) {
			$out .= $this->card_of( $id, $eyebrow );
		}
		return $out;
	}

	/**
	 * The curated tiles of one series, in WooCommerce's custom order.
	 *
	 * `menu_order` is the curation: 0 means "not on this page", and the
	 * positive values sequence the tiles. The seed command writes the initial
	 * values once; after that the field is the owner's, so adding, removing
	 * or reordering a tile is a product edit in the admin and nothing else.
	 *
	 * @param string $slug  The `product_cat` term slug.
	 * @param int    $limit Maximum tiles, 0 for every curated member.
	 * @return int[] The product ids, empty for an uncurated or unknown series.
	 */
	private function tile_ids( string $slug, int $limit ): array {
		$ids = array();
		foreach ( $this->series_member_ids( $slug ) as $id ) {
			if ( $this->menu_order_of( $id ) <= 0 ) {
				continue;
			}
			$ids[] = $id;
			if ( $limit > 0 && count( $ids ) >= $limit ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * A product's WooCommerce `menu_order`.
	 *
	 * @param int $post_id The product id.
	 * @return int The order, 0 when unset.
	 */
	private function menu_order_of( int $post_id ): int {
		$post = \get_post( $post_id );
		return null === $post ? 0 : (int) $post->menu_order;
	}

	/**
	 * The home page's cards for one series: the featured patterns first, then
	 * the rest of the series' visible members for any slots left over.
	 *
	 * @param string $slug     The `product_cat` term slug.
	 * @param int    $limit    Maximum cards, 0 for every member.
	 * @param bool   $featured Whether the featured flag leads the order.
	 * @return int[] The product ids.
	 */
	private function card_ids( string $slug, int $limit, bool $featured ): array {
		$members = $this->series_member_ids( $slug );
		if ( array() === $members ) {
			return array();
		}
		$ordered = array();
		if ( $featured ) {
			foreach ( $members as $id ) {
				if ( self::is_featured( $id ) ) {
					$ordered[] = $id;
				}
			}
		}
		foreach ( $members as $id ) {
			if ( $limit > 0 && count( $ordered ) >= $limit ) {
				break;
			}
			if ( ! in_array( $id, $ordered, true ) ) {
				$ordered[] = $id;
			}
		}
		return $limit > 0 ? array_slice( $ordered, 0, $limit ) : $ordered;
	}

	/**
	 * The per-request tally of series_visible_counts().
	 *
	 * A property rather than a function-static so tests can reset it: the
	 * suite runs every case in one process, and a function-static would
	 * carry the first case's catalog into all the rest.
	 *
	 * @var array<string, int>|null
	 */
	private static ?array $visible_counts = null;

	/**
	 * How many catalog-visible published patterns each series holds.
	 *
	 * This is the number the catalog faces must agree on, and it cannot come
	 * from the term: `$term->count` is not one value on this stack.
	 * WooCommerce's `wc_change_term_counts` filter (hooked to `get_terms`)
	 * replaces it with the `product_count_<taxonomy>` term meta on the front
	 * end, with the raw `wp_term_taxonomy.count` column in admin and AJAX,
	 * and with **0** when that meta was never written — while `hide_empty`
	 * is applied in SQL against the column, before the filter runs. One
	 * `WP_Term` therefore carries two different counts, and neither is a
	 * live catalog-visible count:
	 *
	 * - a series whose products are all hidden from the catalog has
	 *   `hide_empty` keep it (column 1) while its badge reads 0, so an empty
	 *   series sits in the navigation and links to an archive listing
	 *   nothing;
	 * - a series whose recount never ran reads 0 and is dropped by
	 *   `hide_empty`, so a non-empty series disappears.
	 *
	 * The count is computed here instead, from the same membership the grids
	 * use: published, catalog-visible products, tallied by their
	 * `product_cat` term. It costs two bounded queries for the whole page —
	 * one id projection, one term lookup — no matter how many series exist,
	 * and the tally is memoized because the navigation, the hub grid and the
	 * custom-screens badges all ask for it in the same request.
	 *
	 * @return array<string, int> Count by term slug, absent for an empty series.
	 */
	public static function series_visible_counts(): array {
		$counts = self::$visible_counts;
		if ( null !== $counts ) {
			return $counts;
		}
		$counts   = array();
		$excluded = ( new self() )->catalog_excluded_term_ids();
		$tax      = array();
		if ( array() !== $excluded ) {
			$tax[] = array(
				'taxonomy'         => 'product_visibility',
				'field'            => 'term_taxonomy_id',
				'terms'            => $excluded,
				'operator'         => 'NOT IN',
				'include_children' => false,
			);
		}
		$ids = \get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'none',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one id projection for the whole page.
			)
		);
		if ( array() === $ids ) {
			self::$visible_counts = $counts;
			return $counts;
		}
		$terms = \wp_get_object_terms(
			array_map( 'intval', $ids ),
			'product_cat',
			array(
				'fields' => 'all_with_object_id',
			)
		);
		if ( \is_wp_error( $terms ) ) {
			self::$visible_counts = $counts;
			return $counts;
		}
		foreach ( $terms as $term ) {
			$slug = (string) $term->slug;
			if ( '' === $slug ) {
				continue;
			}
			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
		}
		self::$visible_counts = $counts;
		return $counts;
	}

	/**
	 * Forgets the memoized tally. For tests, which share one process; a real
	 * request builds it once and exits.
	 */
	public static function reset_visible_counts(): void {
		self::$visible_counts = null;
	}

	/**
	 * Whether the owner marked a product featured in WooCommerce.
	 *
	 * @param int $post_id The product id.
	 * @return bool
	 */
	private static function is_featured( int $post_id ): bool {
		$terms = \get_the_terms( $post_id, 'product_visibility' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term ) {
			if ( 'featured' === $term->slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A series' published, catalog-visible patterns in WooCommerce's custom
	 * order (`menu_order`, then title).
	 *
	 * Catalog visibility is WooCommerce's, not this file's: a product the
	 * owner hid from the catalog carries `exclude-from-catalog`, and the shop
	 * archive leaves it out, so these editorial faces must leave it out too —
	 * otherwise the same product is on the custom-screens page and absent
	 * from the catalog it advertises. `wc_get_product_visibility_term_ids()`
	 * is the function WooCommerce's own query uses, so the exclusion cannot
	 * drift from it.
	 *
	 * The series' members are read as ids; `menu_order` is a column, not
	 * meta, so the curation needs one ordered read rather than a lookup per
	 * product. The same project assigns the menu_order and the visibility
	 * clause, so neither can drift from the other.
	 *
	 * @param string $slug The `product_cat` term slug.
	 * @return int[] The product ids in custom order, empty for an unknown series.
	 */
	private function series_member_ids( string $slug ): array {
		if ( '' === $slug ) {
			return array();
		}
		$tax_query = array(
			array(
				'taxonomy'         => 'product_cat',
				'field'            => 'slug',
				'terms'            => $slug,
				'include_children' => false,
			),
		);
		$excluded  = $this->catalog_excluded_term_ids();
		if ( array() !== $excluded ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_visibility',
				'field'            => 'term_taxonomy_id',
				'terms'            => $excluded,
				'operator'         => 'NOT IN',
				'include_children' => false,
			);
		}
		$ids = \get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the editorial faces' only source of membership.
			)
		);
		return array_map( 'intval', $ids );
	}
	/**
	 * The `product_visibility` term ids WooCommerce's catalog excludes.
	 *
	 * Empty when WooCommerce is absent (the face then renders the term's
	 * members unfiltered rather than nothing) and empty when the taxonomy has
	 * no `exclude-from-catalog` term, which is WooCommerce's own answer.
	 *
	 * @return int[] The excluded term taxonomy ids.
	 */
	private function catalog_excluded_term_ids(): array {
		if ( ! \function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			return array();
		}
		$ids = \wc_get_product_visibility_term_ids();
		if ( ! is_array( $ids ) || empty( $ids['exclude-from-catalog'] ) ) {
			return array();
		}
		return array( (int) $ids['exclude-from-catalog'] );
	}

	/**
	 * One pattern tile and its lightbox, for the pattern library.
	 *
	 * @param int    $post_id The product id.
	 * @param string $panel   The panel's label (the tile's category and the lightbox eyebrow).
	 * @return string The tile, empty when the product cannot be drawn.
	 */
	private function tile( int $post_id, string $panel ): string {
		return $this->cards->tile( $post_id, $panel );
	}

	/**
	 * One pattern-direction card, for the home page.
	 *
	 * @param int    $post_id The product id.
	 * @param string $eyebrow The family wording ("Mashrabiya pattern").
	 * @return string The card, empty when the product cannot be drawn.
	 */
	private function card_of( int $post_id, string $eyebrow ): string {
		return $this->cards->editorial( $post_id, $eyebrow );
	}

	/**
	 * How many catalog-visible patterns the site carries — the sidebar's "All
	 * Patterns" count, which the two catalog faces had written as a literal
	 * 146 (right once, stale the first time a pattern was unpublished).
	 *
	 * The number has to be the same set the shop archive paginates, so it
	 * applies WooCommerce's catalog visibility (a product hidden from the
	 * catalog is not on the archive and must not be in its total) and not
	 * `wp_count_posts()`, which counts every published product regardless.
	 *
	 * @return string The count.
	 */
	public function total(): string {
		$tax_query = array();
		$excluded  = $this->catalog_excluded_term_ids();
		if ( array() !== $excluded ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_visibility',
				'field'            => 'term_taxonomy_id',
				'terms'            => $excluded,
				'operator'         => 'NOT IN',
				'include_children' => false,
			);
		}
		$ids = \get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'none',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one bounded id projection, memoized per request.
			)
		);
		return (string) count( $ids );
	}

	/**
	 * How many tiles one custom-screens panel renders: the same query and the
	 * same curation the tiles use, so a panel's badge cannot disagree with
	 * its grid.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: term (required), limit.
	 * @return string The count.
	 */
	public function group_count( $atts = array() ): string {
		$atts = \shortcode_atts(
			array(
				'term'  => '',
				'limit' => '0',
			),
			(array) $atts,
			self::GROUP_COUNT
		);
		return (string) count(
			$this->tile_ids(
				\sanitize_title( (string) $atts['term'] ),
				(int) $atts['limit']
			)
		);
	}

	/**
	 * The main archive query's search and sort inputs.
	 *
	 * @param \WP_Query $query The query being prepared.
	 */
	public function filter_query( $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( 'product' ) && ! $query->is_tax( 'product_cat' ) ) {
			return;
		}

		// The old collection rendered 24 patterns per page — the live
		// /collections/pattern-abstract shows 24 .pattern-card items and a
		// "1 2 3 4 Next" pager, /collections/all the same (measured in real
		// Chrome 2026-10-26). 21 put one row fewer on every page and added
		// two pages to the series pager.
		$query->set( 'posts_per_page', 24 );

		$search = $this->requested_search();
		if ( '' !== $search ) {
			$query->set( 's', $search );
		}

		$sort = $this->requested_sort();
		switch ( $sort ) {
			case 'title-descending':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'DESC' );
				break;
			case 'created-descending':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			default:
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
				break;
		}
	}

	/**
	 * The order this request asked for, as one of SORT_VALUES.
	 *
	 * The tools' two forms each submit alone, so the sort menu must know
	 * which option the archive is actually ordered by, and anything the
	 * request does not name — or names unrecognisably — is the default. One
	 * reader for both the query and the menu, so the selected option can
	 * never claim an order the archive did not apply.
	 *
	 * @return string One of SORT_VALUES.
	 */
	private function requested_sort(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only ordering choice, no state change.
		$raw = isset( $_GET['sort_by'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['sort_by'] ) ) : '';
		return in_array( $raw, self::SORT_VALUES, true ) ? $raw : self::SORT_DEFAULT;
	}

	/**
	 * The order the request named, '' when it named none or named one the
	 * archive does not apply.
	 *
	 * Distinct from requested_sort() on purpose: that one answers "what do I
	 * order by" and falls back to the default, this one answers "did the
	 * visitor choose an order" and has to stay empty when they did not — an
	 * order the request never named must not be written into a hidden field
	 * or a link, or the default would be pinned into every later submission.
	 *
	 * @return string One of SORT_VALUES, or ''.
	 */
	private function named_sort(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only ordering choice, no state change.
		$raw = isset( $_GET['sort_by'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['sort_by'] ) ) : '';
		return in_array( $raw, self::SORT_VALUES, true ) ? $raw : '';
	}

	/**
	 * The search this request asked for.
	 *
	 * @return string The sanitized term, '' when the request names none.
	 */
	private function requested_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the query the filter already read.
		return isset( $_GET['pattern_search'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['pattern_search'] ) ) : '';
	}

	/**
	 * A hidden field carrying one value of the *other* tool's form through a
	 * submission.
	 *
	 * The search box and the sort menu are two separate GET forms on the same
	 * archive. Each used to submit only its own control, so typing a search
	 * discarded the chosen order and changing the order discarded the search
	 * — and because the sort menu's own `selected` attribute was a literal in
	 * the template, it kept showing "A-Z" whatever order the archive was
	 * really using. `[md_preserve_param name="sort_by"]` prints the current
	 * order into the search form and vice versa, so either submission
	 * carries the other's state.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: name (required), except.
	 * @return string The hidden input, empty when there is nothing to carry.
	 */
	public function preserve_param( $atts = array() ): string {
		$atts = \shortcode_atts(
			array(
				'name'   => '',
				'except' => '',
			),
			(array) $atts,
			self::PRESERVE
		);
		$name = (string) $atts['name'];
		if ( '' === $name || $name === (string) $atts['except'] ) {
			return '';
		}
		$value = '';
		if ( 'pattern_search' === $name ) {
			$value = $this->requested_search();
		} elseif ( 'sort_by' === $name ) {
			// Only carry an order the request actually named: printing the
			// default would pin the default into every later submission.
			$value = $this->named_sort();
		}
		if ( '' === $value ) {
			return '';
		}
		return sprintf(
			'<input type="hidden" name="%s" value="%s">',
			\esc_attr( $name ),
			\esc_attr( $value )
		);
	}

	/**
	 * The search box's current term.
	 *
	 * @return string The escaped term, '' when the request names none.
	 */
	public function search_value(): string {
		return \esc_attr( $this->requested_search() );
	}

	/**
	 * The address the archive's two tools submit to: this face's page one.
	 *
	 * Both forms carried `action=""`, which a browser resolves to the address
	 * it is on — page 2 included. Submitting a search from `/shop/page/2/`
	 * therefore asked for `/shop/page/2/?pattern_search=…`, and a search that
	 * matched one pattern has no page 2: WordPress resolves the page number
	 * against the narrowed result set and the visitor gets a 404 instead of
	 * the pattern they searched for. The same held on every series archive
	 * wide enough to paginate.
	 *
	 * The action is the face's own base address instead, so a submission
	 * always starts at page one and the pager's own links carry the page
	 * number: the series term's link on an archive (the series the request
	 * actually resolved to, never a literal), and the product archive's base
	 * on the shop — WooCommerce points that base at its shop page, so the
	 * shop's own address is what it answers with, one function and no branch
	 * on the shop id. The GET parameters are unaffected — the search box, the
	 * sort menu and `preserve_param()`'s hidden field still carry the state,
	 * and the action carries none of it, so a submission cannot resurrect a
	 * stale page number or drop a parameter the other control set.
	 *
	 * Neither answer carries a page suffix, so a submission from page 2
	 * resolves to page one without a suffix to strip here.
	 *
	 * @return string The escaped action URL, '' when this face has no base.
	 */
	public function archive_form_action(): string {
		$current = \get_queried_object();
		if ( $current instanceof \WP_Term && 'product_cat' === $current->taxonomy ) {
			$link = \get_term_link( $current );
			return \is_string( $link ) ? \esc_url( $link ) : '';
		}
		return $this->shop_base();
	}

	/**
	 * The shop's own base address, escaped — the product archive's link, which
	 * WooCommerce points at its shop page. '' when the post type carries no
	 * archive, the one case `get_post_type_archive_link()` answers `false` in.
	 *
	 * @return string The escaped URL, '' when there is none.
	 */
	private function shop_base(): string {
		$link = \get_post_type_archive_link( 'product' );
		return \is_string( $link ) ? \esc_url( $link ) : '';
	}

	/**
	 * The sort menu's `selected` attribute for one option.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes: value (required).
	 * @return string ` selected` for the order in force, empty otherwise.
	 */
	public function sort_selected( $atts = array() ): string {
		$atts  = \shortcode_atts(
			array( 'value' => '' ),
			(array) $atts,
			self::SORT_SELECTED
		);
		$value = (string) $atts['value'];
		if ( '' === $value || ! in_array( $value, self::SORT_VALUES, true ) ) {
			return '';
		}
		return $value === $this->requested_sort() ? ' selected' : '';
	}

	/**
	 * One pattern card for the product the loop is on.
	 *
	 * @return string The card markup, empty outside a product.
	 */
	public function card(): string {
		$post = \get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return '';
		}
		return $this->cards->catalog( (int) $post->ID );
	}

	/**
	 * The archive's pager, from the main query.
	 *
	 * @return string The nav, empty when there is one page or no query.
	 */
	public function pagination(): string {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return '';
		}
		$total = (int) $query->max_num_pages;
		if ( $total < 2 ) {
			return '';
		}
		$links = \paginate_links(
			array(
				'total'     => $total,
				'current'   => max( 1, (int) \get_query_var( 'paged' ) ),
				'type'      => 'array',
				'prev_text' => 'Previous',
				'next_text' => 'Next',
			)
		);
		if ( array() === $links ) {
			return '';
		}
		return '<nav class="pattern-catalog__pagination" aria-label="Pattern pages">' . implode( '', $links ) . '</nav>';
	}

	/**
	 * The results bar's count and page position, from the main query.
	 *
	 * @return string The summary text.
	 */
	public function summary(): string {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query ) {
			return '';
		}
		$found  = (int) $query->found_posts;
		$pages  = max( 1, (int) $query->max_num_pages );
		$page   = max( 1, (int) \get_query_var( 'paged' ) );
		$search = $this->requested_search();
		if ( '' !== $search ) {
			return sprintf(
				'Search results for "%s" — <strong>%d</strong> %s',
				\esc_html( $search ),
				$found,
				1 === $found ? 'pattern' : 'patterns'
			);
		}
		return sprintf(
			'Showing <strong>%d</strong> %s &middot; Page %d of %d',
			$found,
			1 === $found ? 'pattern' : 'patterns',
			$page,
			$pages
		);
	}

	/**
	 * The catalog's empty state, from the main query.
	 *
	 * Both faces rendered nothing at all when the query found no pattern: the
	 * results bar said "… 0 patterns" over an empty grid, and the pager did not
	 * print (`pagination()` needs two pages), so a visitor who searched for
	 * something the catalog does not carry got a heading, a zero, and the rest
	 * of the page — with no way back except the browser's own back button and
	 * no statement that the search had failed. The styling for this state was
	 * already in `pattern-grid.css` (`.pattern-catalog__empty`: the card
	 * surface, the Playfair heading, and the grid-span rule for sitting inside
	 * `.pattern-catalog__products`), so the markup that goes into it is what
	 * was missing.
	 *
	 * Two ways to arrive at nothing, and they need different wording:
	 *
	 * - a search that matched nothing — the visitor typed something, so the
	 *   state names the term and offers the way out: a link to this face's own
	 *   base address (`archive_form_action()`), which drops the search and the
	 *   order and returns to the full catalog;
	 * - a series with nothing in it, which the shop cannot reach (its series
	 *   links are the series with visible patterns) but a hand-typed archive
	 *   URL can. There is no term to clear, so the state says so and links the
	 *   shop, where every series that does carry patterns is listed.
	 *
	 * The condition is the query WordPress already ran, not a second query:
	 * `found_posts` is what the loop and the pager read, so the state can never
	 * appear beside a grid that has cards, or stay hidden beside an empty one.
	 * Rendering nothing when the query cannot be read keeps a template unit
	 * test (no `$wp_query`) from printing a state the visitor would never see.
	 *
	 * @return string The empty state, '' when the query found patterns.
	 */
	public function empty_state(): string {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( ! $query instanceof \WP_Query || (int) $query->found_posts > 0 ) {
			return '';
		}
		$search = $this->requested_search();
		if ( '' !== $search ) {
			return sprintf(
				'<div class="pattern-catalog__empty" data-pattern-empty><h3>No patterns match &ldquo;%s&rdquo;</h3><p>Try a pattern name or a family, or <a href="%s">clear the search</a> to see everything this page lists.</p></div>',
				\esc_html( $search ),
				\esc_url( $this->archive_form_action() )
			);
		}
		return sprintf(
			'<div class="pattern-catalog__empty" data-pattern-empty><h3>No patterns here yet</h3><p>This series carries no published patterns at the moment. <a href="%s">Browse all patterns</a> to find the families that do.</p></div>',
			$this->shop_base()
		);
	}
}
