<?php
/**
 * The catalog faces' noindex rule for internal search and sort URLs.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * The catalog faces (`/shop/`, `/collections/<series>/`) carry the archive
 * tools: a `pattern_search` box and a `sort_by` menu, both plain GET forms
 * that `Catalog::filter_query()` turns into the main query. Every result of
 * those forms is a *filtered view of a page that already has a canonical
 * URL*, so the view itself must not be indexed: a search result like
 * `/shop/?pattern_search=lattice` is an unbounded set of thin duplicates,
 * each canonicalising to `/shop/` — an index-then-canonical-elsewhere pair
 * that Search Console reports as "Duplicate, Google chose different
 * canonical" and that spends crawl budget on URLs nobody links to.
 *
 * The rule here is deliberately narrow, because the alternative (indexing
 * the filtered views) was the shipped behaviour and both halves matter:
 *
 * - **The plain catalog stays indexed.** `/shop/` and every series archive
 *   keep `index, follow`; the rule fires only when a tool parameter is
 *   actually in the request.
 * - **The canonical is untouched.** SEOPress suppresses `<link rel=
 *   canonical>` only for a noindex value of `1`/`yes`
 *   (`wp-seopress/inc/functions/options-titles-metas.php`: the canonical
 *   block is guarded by `! in_array( $noindex, array( '1', 'yes' ), true )`).
 *   Returning the string `noindex` therefore leaves the canonical exactly
 *   as it renders today, which is what makes `noindex, follow` the honest
 *   pair here rather than a weaker signal.
 * - **`follow` survives.** SEOPress prepends `follow` when noindex is set
 *   without nofollow, so the cached `<meta name="robots">` becomes
 *   `noindex, follow` and drops only the snippet directives a non-indexable
 *   page cannot use.
 *
 * The parameters are the two the archive tools actually submit, read
 * through the same request shape `Catalog` reads them: `pattern_search`
 * (the search box) and `sort_by` (the order menu). `sort_by` counts only
 * when it names one of the archive's own accepted values — a stray
 * `sort_by=` or an unrecognised value leaves the query at its default, so
 * the page is the plain catalog and must stay indexable.
 */
final class CatalogRobots {

	/**
	 * The search box's parameter (Catalog::requested_search reads it).
	 */
	public const SEARCH_PARAM = 'pattern_search';

	/**
	 * The order menu's parameter (Catalog::requested_sort reads it).
	 */
	public const SORT_PARAM = 'sort_by';

	/**
	 * The sort values the archive applies; anything else is the default
	 * (the same list `Catalog::SORT_VALUES` validates against).
	 *
	 * @var list<string>
	 */
	private const SORT_VALUES = array( 'created-descending', 'title-ascending', 'title-descending' );

	/**
	 * Adds the filter. One hook: SEOPress's own noindex bypass, the
	 * documented way to extend its robots decision without replacing it.
	 */
	public function register(): void {
		add_filter( 'seopress_titles_noindex_bypass', array( $this, 'noindex' ) );
	}

	/**
	 * Adds `noindex` for a filtered catalog view, and leaves every other
	 * request to SEOPress.
	 *
	 * The incoming value is SEOPress's own decision (it may already be
	 * `noindex` for a 404, a paged archive, a password-protected post).
	 * This method never clears it — a request SEOPress has already
	 * noindexed stays noindexed whatever the catalog parameters say.
	 *
	 * @param mixed $current SEOPress's own noindex decision for this request.
	 * @return mixed `noindex` for a filtered catalog view, `$current` otherwise.
	 */
	public function noindex( $current ) {
		$existing = is_string( $current ) ? $current : '';
		if ( '' !== $existing && '0' !== $existing ) {
			return $current;
		}
		return $this->is_filtered_catalog_request() ? 'noindex' : $current;
	}

	/**
	 * Whether this request is a catalog archive filtered by one of the
	 * archive's own tools.
	 *
	 * @return bool True only on the shop or a product_cat archive carrying an
	 *              accepted `pattern_search` or `sort_by` value.
	 */
	public function is_filtered_catalog_request(): bool {
		if ( ! $this->is_catalog_archive() ) {
			return false;
		}
		return $this->has_search_param() || $this->has_sort_param();
	}

	/**
	 * Whether the request is a catalog archive at all: the shop page or a
	 * `product_cat` term archive, the two faces that render the tools.
	 *
	 * @return bool True on the shop or a series archive.
	 */
	private function is_catalog_archive(): bool {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}
		return function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
	}

	/**
	 * Whether the search box submitted a non-empty term. `Catalog` treats
	 * an empty term as no search at all, so this does too.
	 *
	 * @return bool True when `pattern_search` carries a term.
	 */
	private function has_search_param(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision, no state change.
		$raw = isset( $_GET[ self::SEARCH_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::SEARCH_PARAM ] ) ) : '';
		return '' !== trim( $raw );
	}

	/**
	 * Whether the order menu submitted one of the archive's own values.
	 * An unrecognised value leaves the query ordered by the default, so
	 * the response is the plain catalog and stays indexable.
	 *
	 * @return bool True when `sort_by` names an accepted order.
	 */
	private function has_sort_param(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing decision, no state change.
		$raw = isset( $_GET[ self::SORT_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::SORT_PARAM ] ) ) : '';
		return in_array( $raw, self::SORT_VALUES, true );
	}
}
