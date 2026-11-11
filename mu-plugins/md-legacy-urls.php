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
 * The old Shopify products base, without a trailing slash.
 */
const MD_LEGACY_URLS_OLD_PRODUCTS_BASE = '/products';

/**
 * The site's own pattern base, without a trailing slash: a product pattern's
 * permalink is `/pattern/<slug>/` (owner ruling 2026-10-24, "URL 基座改
 * /pattern/").
 */
const MD_LEGACY_URLS_PATTERN_BASE = '/pattern';

/**
 * Old addresses that changed name rather than base, one destination each.
 *
 * Keys are request paths without the trailing slash; values are the paths the
 * pages actually answer at today. `pattern-arabic` is the old site's name for
 * the series this site publishes as `pattern-moroccan-arabic`, and it arrived
 * in two shapes: the Shopify collection and the `/pattern-category/` path the
 * site itself published between the URL ruling and the rename. The two family
 * pages were linked on the old site under longer names.
 */
const MD_LEGACY_URLS_ALIASES = array(
	'/collections/pattern-arabic'              => '/pattern-category/pattern-moroccan-arabic/',
	'/pattern-category/pattern-arabic'         => '/pattern-category/pattern-moroccan-arabic/',
	'/perforated-metal-panels'                 => '/perforated-panels/',
	'/outdoor-privacy-screens'                 => '/outdoor-privacy-landscape-screens/',
	'/pages'                                   => '/',
	'/pages/about-us'                          => '/about-us/',
	'/pages/chinese-style-artistic-screens'    => '/chinese-style-artistic-screens/',
	'/pages/custom-metal-divider'              => '/custom-metal-divider-screens/',
	'/pages/custom-metal-divider-screens'      => '/custom-metal-divider-screens/',
	'/pages/expanded-metal-panels'             => '/expanded-metal-panels/',
	'/pages/faqs'                              => '/faqs/',
	'/pages/glass-hybrid-screens'              => '/glass-hybrid-screens/',
	'/pages/laser-cut-geometric-metal-screens' => '/laser-cut-geometric-metal-screens/',
	'/pages/laser-cut-panels'                  => '/laser-cut-panels/',
	'/pages/manufacturing-process'             => '/manufacturing-process/',
	'/pages/outdoor-privacy-landscape-screens' => '/outdoor-privacy-landscape-screens/',
	'/pages/outdoor-privacy-screens'           => '/outdoor-privacy-landscape-screens/',
	'/pages/perforated-metal-panels'           => '/perforated-panels/',
	'/pages/perforated-panels'                 => '/perforated-panels/',
	'/pages/project-gallery'                   => '/project-gallery/',
	'/pages/stainless-steel-screens'           => '/stainless-steel-screens/',
);

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
 * The one exception inside the catalogue branch is `/shop/<slug>`, which was
 * a product on the old storefront: it moves to `/pattern/<slug>/`, the PDP the
 * site actually publishes. Sending it to `/patterns/<slug>/` invented a 404 —
 * the catalogue has no per-pattern child path. The pager (`/shop/page/N/`) is
 * not a product and keeps the catalogue mapping.
 *
 * `MD_LEGACY_URLS_ALIASES` holds the addresses that changed name rather than
 * base; they are answered before any of the rules above.
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
 * @param string        $path    The request path, leading slash included.
 * @param callable|null $series  Tells whether a `product_cat` slug is live;
 *                               null uses WordPress's own lookup.
 * @param callable|null $product Resolves an old product slug to the slug this
 *                               site publishes; null uses the product lookup.
 * @return string The destination path, `''` when the path is not a legacy one.
 */
function md_legacy_urls_target( string $path, ?callable $series = null, ?callable $product = null ): string {
	$path = '/' . ltrim( $path, '/' );

	$lang_prefix = '';
	$subpath     = $path;
	if ( preg_match( '#^/(zh|es|pt-br|de|fr|ar|id|vi)(?:/(.*))?$#i', $path, $matches ) ) {
		$lang_prefix = '/' . strtolower( $matches[1] );
		$subpath     = isset( $matches[2] ) && '' !== $matches[2] ? '/' . $matches[2] : '/';
	}

	$alias = rtrim( $subpath, '/' );
	if ( isset( MD_LEGACY_URLS_ALIASES[ $alias ] ) ) {
		return md_legacy_urls_localize_series_target( $lang_prefix, MD_LEGACY_URLS_ALIASES[ $alias ] );
	}

	if ( md_legacy_urls_is_under( $subpath, MD_LEGACY_URLS_OLD_PRODUCTS_BASE ) ) {
		$rest = md_legacy_urls_rest( $subpath, MD_LEGACY_URLS_OLD_PRODUCTS_BASE );
		if ( '' === trim( $rest, '/' ) ) {
			return $lang_prefix . MD_LEGACY_URLS_CATALOGUE;
		}
		$parts = explode( '/', trim( $rest, '/' ) );
		if ( 1 === count( $parts ) && '' !== $parts[0] ) {
			$resolver     = $product ?? 'md_legacy_urls_resolve_product_slug';
			$resolved     = (string) $resolver( $parts[0] );
			$product_slug = '' === $resolved ? $parts[0] : $resolved;
			return $lang_prefix . MD_LEGACY_URLS_PATTERN_BASE . '/' . $product_slug . '/';
		}
		return $lang_prefix . MD_LEGACY_URLS_CATALOGUE;
	}

	if ( md_legacy_urls_is_under( $subpath, MD_LEGACY_URLS_OLD_CATALOGUE ) ) {
		$rest = md_legacy_urls_rest( $subpath, MD_LEGACY_URLS_OLD_CATALOGUE );
		if ( '' === $rest || '/' === $rest ) {
			return $lang_prefix . MD_LEGACY_URLS_CATALOGUE;
		}

		$parts = explode( '/', trim( $rest, '/' ) );
		if ( 1 === count( $parts ) && 'page' !== $parts[0] && '' !== $parts[0] ) {
			$resolver     = $product ?? 'md_legacy_urls_resolve_product_slug';
			$resolved     = (string) $resolver( $parts[0] );
			$product_slug = '' === $resolved ? $parts[0] : $resolved;
			return $lang_prefix . MD_LEGACY_URLS_PATTERN_BASE . '/' . $product_slug . '/';
		}

		return $lang_prefix . rtrim( MD_LEGACY_URLS_CATALOGUE, '/' ) . $rest;
	}

	if ( ! md_legacy_urls_is_under( $subpath, MD_LEGACY_URLS_OLD_SERIES_BASE ) ) {
		return '';
	}

	$rest  = md_legacy_urls_rest( $subpath, MD_LEGACY_URLS_OLD_SERIES_BASE );
	$parts = explode( '/', ltrim( $rest, '/' ) );
	$slug  = $parts[0];
	if ( '' === $slug ) {
		return $lang_prefix . MD_LEGACY_URLS_SERIES_BASE . '/';
	}

	$exists = $series ?? 'md_legacy_urls_series_exists';
	if ( ! $exists( $slug ) ) {
		return $lang_prefix . MD_LEGACY_URLS_CATALOGUE;
	}
	return md_legacy_urls_localize_series_target( $lang_prefix, MD_LEGACY_URLS_SERIES_BASE . $rest );
}

/**
 * Rewrites `/pattern-category/<en-slug>` to the localized series slug when WPML
 * is active and a non-default language prefix is present.
 *
 * @param string $lang_prefix The language prefix (e.g. `/zh` or `''`).
 * @param string $target      The unprefixed destination path.
 * @return string The prefixed and localized destination path.
 */
function md_legacy_urls_localize_series_target( string $lang_prefix, string $target ): string {
	if ( '' === $lang_prefix || ! str_starts_with( $target, MD_LEGACY_URLS_SERIES_BASE . '/' ) ) {
		return $lang_prefix . $target;
	}
	if ( ! function_exists( 'get_term_by' ) || ! function_exists( 'apply_filters' ) || ! function_exists( 'has_filter' ) || ! has_filter( 'wpml_object_id' ) ) {
		return $lang_prefix . $target;
	}
	$rest  = substr( $target, strlen( MD_LEGACY_URLS_SERIES_BASE . '/' ) );
	$parts = explode( '/', $rest, 2 );
	$slug  = $parts[0];
	$tail  = isset( $parts[1] ) ? '/' . $parts[1] : '';
	if ( '' === $slug ) {
		return $lang_prefix . $target;
	}
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! ( $term instanceof WP_Term ) ) {
		return $lang_prefix . $target;
	}
	$lang_code = ltrim( $lang_prefix, '/' );
	$wpml_code = 'zh' === $lang_code ? 'zh-hans' : $lang_code;
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard filter.
	$tr_id = (int) apply_filters( 'wpml_object_id', (int) $term->term_id, 'product_cat', false, $wpml_code );
	if ( $tr_id > 0 && function_exists( 'get_term' ) ) {
		$tr_term = get_term( $tr_id, 'product_cat' );
		if ( $tr_term instanceof WP_Term && '' !== $tr_term->slug ) {
			return $lang_prefix . MD_LEGACY_URLS_SERIES_BASE . '/' . $tr_term->slug . $tail;
		}
	}
	return $lang_prefix . $target;
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
 * The product slug this site publishes for an old storefront slug.
 *
 * The old storefront handles are shorter than the product slugs this site
 * publishes: '/shop/agave/' answers at
 * '/pattern/agave-architectural-metal-screen-pattern/'. Redirecting first to
 * '/pattern/agave/' costs a second 301 through WordPress's own canonical
 * redirect, so this looks the suffixed product up directly.
 *
 * A slug no product carries comes back unchanged: '/pattern/<slug>/' is still
 * the right first hop for a URL that may exist, and the fallback keeps this
 * plugin harmless when it runs without a database or outside WordPress.
 *
 * @param string $slug The old storefront slug.
 * @return string The product slug, or the original when no product matches.
 */
function md_legacy_urls_resolve_product_slug( string $slug ): string {
	if ( ! function_exists( 'get_page_by_path' ) ) {
		return $slug;
	}
	if ( null !== get_page_by_path( $slug, OBJECT, 'product' ) ) {
		return $slug;
	}
	$suffixed = $slug . '-architectural-metal-screen-pattern';
	if ( null !== get_page_by_path( $suffixed, OBJECT, 'product' ) ) {
		return $suffixed;
	}
	return $slug;
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
 * @param string        $uri     The request URI, path first.
 * @param callable|null $series  The series lookup `md_legacy_urls_target` takes.
 * @param callable|null $product The product lookup `md_legacy_urls_target` takes.
 * @return string The destination path with its query, `''` when not legacy.
 */
function md_legacy_urls_url( string $uri, ?callable $series = null, ?callable $product = null ): string {
	$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$target = md_legacy_urls_target( $path, $series, $product );
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

	$home        = (string) get_option( 'home' );
	$destination = '' !== $home ? untrailingslashit( $home ) . $url : home_url( $url );

	if ( wp_safe_redirect( $destination, 301 ) ) {
		exit;
	}
}

add_action( 'template_redirect', 'md_legacy_urls_redirect', 1 );
