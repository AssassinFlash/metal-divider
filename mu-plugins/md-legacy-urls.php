<?php
/**
 * Plugin Name: MD legacy URLs
 * Description: 301s the old Shopify URL semantics — /shop/ and /collections/<series> — to the site's own /patterns/ and /pattern-category/<series>.
 *
 * The owner ruled on 2026-10-30 that Shopify's information architecture is
 * not the site's (GOAL.md, 2026-10-30 深夜 2): a customer browsing this site
 * sees `/patterns/` for the catalogue and `/pattern-category/<series>/` for a
 * series, not the storefront words `/shop/` and `/collections/`. The
 * templates, the deploy's entry points and the guards all carry the new
 * paths; this file is the other half — the addresses the old site published,
 * and the ones this site published until the ruling, keep working.
 *
 * A must-use plugin, because an alias layer is a property of the site's URL
 * space: it must hold while a regular plugin is being activated, updated or
 * broken, and it has to be able to answer before any theme or plugin decides
 * a request is a 404.
 *
 * **It fires on `template_redirect` at priority 1.** That is after WordPress
 * has parsed the request, so the same rule answers whether the legacy path
 * still resolves (the shop page, a series archive) or already 404s (the
 * moment the permalinks move) — and before `redirect_canonical` (priority 10)
 * and every other redirect plane, so a legacy URL reaches its new home in one
 * hop instead of being bounced through an intermediate page first.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

/**
 * The site's own catalogue path.
 */
const MD_LEGACY_URLS_CATALOGUE = '/patterns/';

/**
 * The site's own series base, without a trailing slash.
 */
const MD_LEGACY_URLS_SERIES_BASE = '/pattern-category';

/**
 * The old catalogue path, without a trailing slash.
 */
const MD_LEGACY_URLS_OLD_CATALOGUE = '/shop';

/**
 * The old series base, without a trailing slash.
 */
const MD_LEGACY_URLS_OLD_SERIES_BASE = '/collections';

/**
 * Whether the path sits at the base or below it.
 *
 * A prefix test alone would claim `/shopkeeper` and `/collections-x`, which
 * are nobody's legacy URL; the base only matches as a whole segment.
 *
 * @param string $path The request path.
 * @param string $base The base, without a trailing slash.
 * @return bool Whether the path is the base or one of its descendants.
 */
function md_legacy_urls_is_under( string $path, string $base ): bool {
	return $path === $base || str_starts_with( $path, $base . '/' );
}

/**
 * The rest of a path below its base, `''` for the base itself.
 *
 * @param string $path The request path.
 * @param string $base The base, without a trailing slash.
 * @return string The remainder, starting with a slash when it is not empty.
 */
function md_legacy_urls_rest( string $path, string $base ): string {
	return substr( $path, strlen( $base ) );
}

/**
 * The path a legacy URL moves to, `''` when the path is not one.
 *
 * The mapping is the owner's ruling, path for path: `/shop/` becomes
 * `/patterns/`, `/collections/<series>` becomes `/pattern-category/<series>`.
 * Everything after the base travels with it — the pager's page number, a
 * series' `#product-catalog` fragment is a browser concern and never reaches
 * this function — and the query string is the caller's to carry.
 *
 * The old site's two Shopify handles are the exception, and they are why the
 * series branch asks whether the series exists. `/collections/all` was the
 * whole catalogue (it 301s to `/shop/` on the old site), and the redirect map
 * exported from Shopify sends its four deleted test collections to the shop
 * as well: one collection's target on the old site was itself a deleted
 * collection, so its redirect points at a page that does not exist there. A
 * series that is not a `product_cat` term here has no `/pattern-category/`
 * page to land on, so it lands on the catalogue, which is the destination
 * the migration plan already chose for it (tools/migrate/18-migrate.py,
 * `/collections/all equity`).
 *
 * @param string        $path   The request path, leading slash included.
 * @param callable|null $series Tells whether a `product_cat` slug is live;
 *                              null uses WordPress's own lookup.
 * @return string The destination path, `''` when the path is not a legacy one.
 */
function md_legacy_urls_target( string $path, ?callable $series = null ): string {
	$path = '/' . ltrim( $path, '/' );

	if ( md_legacy_urls_is_under( $path, MD_LEGACY_URLS_OLD_CATALOGUE ) ) {
		$rest = md_legacy_urls_rest( $path, MD_LEGACY_URLS_OLD_CATALOGUE );
		return rtrim( MD_LEGACY_URLS_CATALOGUE, '/' ) . ( '' === $rest ? '/' : $rest );
	}

	if ( ! md_legacy_urls_is_under( $path, MD_LEGACY_URLS_OLD_SERIES_BASE ) ) {
		return '';
	}

	$rest  = md_legacy_urls_rest( $path, MD_LEGACY_URLS_OLD_SERIES_BASE );
	$parts = explode( '/', ltrim( $rest, '/' ) );
	$slug  = $parts[0];
	if ( '' === $slug ) {
		return MD_LEGACY_URLS_SERIES_BASE . '/';
	}

	$exists = $series ?? 'md_legacy_urls_series_exists';
	if ( ! $exists( $slug ) ) {
		return MD_LEGACY_URLS_CATALOGUE;
	}
	return MD_LEGACY_URLS_SERIES_BASE . $rest;
}

/**
 * Whether a `product_cat` term carries this slug.
 *
 * One indexed lookup, only on a path under the old series base — a URL
 * nobody links to any more once the new paths are in the templates.
 *
 * @param string $slug The term slug.
 * @return bool Whether a published series answers at it.
 */
function md_legacy_urls_series_exists( string $slug ): bool {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	return $term instanceof WP_Term;
}

/**
 * The destination for a whole request URI, query string included.
 *
 * The query string travels because the old addresses were used with one: the
 * catalogue's own tools submit `?pattern_search=` and `?sort_by=`, so a
 * bookmark or an indexed result of `/shop/?pattern_search=lattice` must
 * arrive as `/patterns/?pattern_search=lattice` rather than as a bare
 * `/patterns/` that silently drops what the visitor asked for.
 *
 * @param string        $uri    The request URI, path first.
 * @param callable|null $series The series lookup `md_legacy_urls_target` takes.
 * @return string The destination path with its query, `''` when not legacy.
 */
function md_legacy_urls_url( string $uri, ?callable $series = null ): string {
	$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$target = md_legacy_urls_target( $path, $series );
	if ( '' === $target ) {
		return '';
	}
	$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	return '' === $query ? $target : $target . '?' . $query;
}

/**
 * The 301 itself.
 *
 * Admin screens, the REST API and AJAX are left alone: none of them is a
 * customer following an old link, and a redirect there would break the
 * machinery that keeps the site running. The request is read from
 * `REQUEST_URI`, which is the raw one — `wp_parse_url` on it keeps the query
 * string a search or a sort arrived with.
 *
 * The stop is conditional on the redirect having been issued. `wp_redirect()`
 * answers false when it could not send one — headers already written, or a
 * filter short-circuiting it — and stopping then would hand the visitor a
 * blank page where the 404 they were on was still a usable one.
 *
 * @return void
 */
function md_legacy_urls_redirect(): void {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$url = md_legacy_urls_url( $uri );
	if ( '' === $url ) {
		return;
	}

	if ( wp_safe_redirect( home_url( $url ), 301 ) ) {
		exit;
	}
}

add_action( 'template_redirect', 'md_legacy_urls_redirect', 1 );
