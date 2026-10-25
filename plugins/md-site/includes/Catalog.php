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
	 * The best-fit display words (the same mapping the PDP uses).
	 */
	private const BEST_FIT_WORDS = array(
		'architectural-screening' => 'Architectural screening',
		'decorative-screening'    => 'Decorative screening',
	);

	/**
	 * The open-area wording for patterns without a sourced value.
	 */
	private const OPEN_AREA_ON_REQUEST = 'On request';

	/**
	 * The nine series cards' hero images: product_cat term slug → the
	 * old site's source file name, from the image-slot map's §4.7 table
	 * (docs/research/2026-10-25-image-slot-map.md). The migrator recorded
	 * that source name on the imported attachment's `_md_source_url`, so
	 * the write resolves the attachment by provenance, not by uploads
	 * path. Gradient is the one series the old site had no card for: its
	 * card carries the wide installed scene of its own Skyfall pattern
	 * (`...Privacy-Screen-3`, the 1000×565 tier) — the new series has no
	 * old-site hero, and this is its own already-imported image.
	 */
	private const SERIES_HERO_SOURCES = array(
		'pattern-abstract'        => 'our-aurum-suite-circle-screen-hero.webp',
		'pattern-asian'           => 'our-shanghai-lattice-dining-hero.webp',
		'pattern-geometric'       => 'ext-bronze-grid-panel-workshop.webp',
		'pattern-gradient'        => 'dsdlocal__Skyfall-Gradient-Privacy-Screen-3.jpg',
		'pattern-landscape'       => 'ext-cn-mountain-profile-screen.webp',
		'pattern-moroccan-arabic' => 'Moroccan_Love_Scene.webp',
		'pattern-organic'         => 'Leaf_Vines.webp',
		'pattern-perforated'      => 'ext-pp-hero-perforated-facade.webp',
		'pattern-vintage-retro'   => 'our-xiamen-bronze-fret-hero.webp',
	);

	/**
	 * Registers the shortcodes and the query filter.
	 */
	public function register(): void {
		\add_shortcode( self::CARD, array( $this, 'card' ) );
		\add_shortcode( self::PAGINATION, array( $this, 'pagination' ) );
		\add_shortcode( self::SUMMARY, array( $this, 'summary' ) );
		\add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
	}

	/**
	 * Writes the nine series cards' `hero_image` term meta from the
	 * attachments the migrator imported, keyed by the source file each
	 * series card used on the old site (SERIES_HERO_SOURCES). The meta
	 * stores the attachment URL, which is what `[md_series_grid]` renders
	 * and what the term API returns.
	 *
	 * Idempotent by value: a second run finds every term already holding
	 * its URL and writes nothing, so the command reports 0 written / 9
	 * unchanged. A missing term or a missing attachment is reported, never
	 * invented (the callers turn the report into a non-zero exit).
	 *
	 * @return array{written: int, unchanged: int, missing: list<string>}
	 */
	public function apply_series_hero_images(): array {
		$report = array(
			'written'   => 0,
			'unchanged' => 0,
			'missing'   => array(),
		);
		foreach ( self::SERIES_HERO_SOURCES as $slug => $source ) {
			$term = \get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term instanceof \WP_Term ) {
				$report['missing'][] = "term {$slug}";
				continue;
			}
			$attachments = \get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'fields'      => 'ids',
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one lookup per series, nine in a manual command.
						array(
							'key'     => '_md_source_url',
							'value'   => $source,
							'compare' => 'LIKE',
						),
					),
				)
			);
			if ( array() === $attachments ) {
				$report['missing'][] = "image {$source}";
				continue;
			}
			$url = (string) \wp_get_attachment_url( (int) $attachments[0] );
			if ( '' === $url ) {
				$report['missing'][] = "url {$source}";
				continue;
			}
			$term_id = (int) $term->term_id;
			if ( (string) \get_term_meta( $term_id, 'hero_image', true ) === $url ) {
				++$report['unchanged'];
				continue;
			}
			\update_term_meta( $term_id, 'hero_image', $url );
			++$report['written'];
		}
		return $report;
	}

	/**
	 * Writes the nine series cards' descriptions from the copy file
	 * (content/copy/series-descriptions.json, the old collections' own
	 * descriptionHtml). The hub's card copy was empty because the migrated
	 * terms carried no description; this restores the old copy without
	 * inventing any. Idempotent by value.
	 *
	 * @return array{written: int, unchanged: int, missing: list<string>}
	 */
	public function apply_series_descriptions(): array {
		$report = array(
			'written'   => 0,
			'unchanged' => 0,
			'missing'   => array(),
		);
		$path   = \dirname( __DIR__ ) . '/content/copy/series-descriptions.json';
		if ( ! is_readable( $path ) ) {
			$report['missing'][] = 'copy/series-descriptions.json';
			return $report;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read by a manual command.
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			$report['missing'][] = 'copy/series-descriptions.json (invalid JSON)';
			return $report;
		}
		foreach ( $data as $slug => $text ) {
			$term = \get_term_by( 'slug', (string) $slug, 'product_cat' );
			if ( ! $term instanceof \WP_Term ) {
				$report['missing'][] = "term {$slug}";
				continue;
			}
			if ( trim( (string) $term->description ) === trim( (string) $text ) ) {
				++$report['unchanged'];
				continue;
			}
			$updated = \wp_update_term( (int) $term->term_id, 'product_cat', array( 'description' => (string) $text ) );
			if ( \is_wp_error( $updated ) ) {
				$report['missing'][] = "term {$slug} (update failed)";
				continue;
			}
			++$report['written'];
		}
		return $report;
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

		// The old collection rendered 21 patterns per page (146 -> 7 pages);
		// WordPress/Woo defaults to 16 (10 pages), which changes both the grid
		// rhythm and the pager. Keep the old page size.
		$query->set( 'posts_per_page', 21 );

		$search = isset( $_GET['pattern_search'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['pattern_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the query the filter already read.
		if ( '' !== $search ) {
			$query->set( 's', $search );
		}

		$sort = isset( $_GET['sort_by'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['sort_by'] ) ) : 'title-ascending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only ordering choice, no state change.
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
	 * One pattern card for the product the loop is on.
	 *
	 * @return string The card markup, empty outside a product.
	 */
	public function card(): string {
		$post = \get_post();
		if ( null === $post || 'product' !== $post->post_type ) {
			return '';
		}
		$title    = $this->code_of_title( (string) \get_the_title( $post ) );
		$family   = $this->family_name( (int) $post->ID );
		$best_fit = $this->best_fit_word( (int) $post->ID );
		$open     = $this->open_area_text( (int) $post->ID );
		$thumb    = (string) \get_the_post_thumbnail_url( $post, 'large' );

		return sprintf(
			'<article class="pattern-card"><a class="pattern-card__link" href="%s" aria-label="%s"><div class="pattern-card__media"><img src="%s" alt="%s" loading="lazy" width="400" height="800"></div><div class="pattern-card__body"><div class="pattern-card__specs" aria-label="%s"><div class="pattern-card__row"><span class="pattern-card__key">Pattern</span><span class="pattern-card__value pattern-card__value--title">%s</span></div><div class="pattern-card__row"><span class="pattern-card__key">Family</span><span class="pattern-card__value">%s</span></div><div class="pattern-card__row"><span class="pattern-card__key">Best fit</span><span class="pattern-card__value">%s</span></div><div class="pattern-card__row"><span class="pattern-card__key">Open</span><span class="pattern-card__value">%s</span></div></div><span class="pattern-card__action">View specification <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path><path d="m13 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path></svg></span></div></a></article>',
			\esc_url( (string) \get_permalink( $post ) ),
			\esc_attr( sprintf( 'View %s specification', $title ) ),
			\esc_url( $thumb ),
			\esc_attr( sprintf( '%s | Architectural Metal Screen Pattern finish preview', $title ) ),
			\esc_attr( sprintf( '%s pattern summary', $title ) ),
			\esc_html( $title ),
			\esc_html( $family ),
			\esc_html( $best_fit ),
			\esc_html( $open )
		);
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
		$search = isset( $_GET['pattern_search'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['pattern_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the query the filter already read.
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
	 * The pattern's family (the first product_cat term's name).
	 *
	 * @param int $post_id The product ID.
	 * @return string The family name, empty without a term.
	 */
	private function family_name( int $post_id ): string {
		$terms = \get_the_terms( $post_id, 'product_cat' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}
		return (string) $terms[0]->name;
	}

	/**
	 * The best-fit display word from the product's meta.
	 *
	 * @param int $post_id The product ID.
	 * @return string The word, empty when the meta is absent.
	 */
	private function best_fit_word( int $post_id ): string {
		$key = \get_post_meta( $post_id, 'best_fit', true );
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}
		return self::BEST_FIT_WORDS[ $key ] ?? ucwords( str_replace( '-', ' ', $key ) );
	}

	/**
	 * The open-area cell: "NN%" or the honest "On request".
	 *
	 * @param int $post_id The product ID.
	 * @return string The text.
	 */
	private function open_area_text( int $post_id ): string {
		$value = \get_post_meta( $post_id, 'open_area', true );
		if ( ! is_numeric( $value ) ) {
			return self::OPEN_AREA_ON_REQUEST;
		}
		$number = (float) $value;
		$digits = floor( $number ) === $number ? 0 : 2;
		return number_format( $number, $digits ) . '%';
	}

	/**
	 * The pattern's short code from its full title.
	 *
	 * @param string $title The product title.
	 * @return string The code.
	 */
	private function code_of_title( string $title ): string {
		$parts = explode( '|', $title );
		return trim( $parts[0] );
	}
}
