<?php
/**
 * Keeps the shop page out of the product sitemap it lands in twice.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Drops the archive link from the product sitemap only, so `/shop/` is listed once.
 *
 * The shop page is a real `page` (ID 6, `publish`) that WooCommerce also adopts
 * as its shop page: `woocommerce_shop_page_id` is 6. SEOPress builds the
 * product sitemap from the `product` post type and, because WooCommerce hands
 * that type the shop page as its archive, writes the same URL as an
 * "archive link" at the top of `product-sitemap1.xml`. `page-sitemap1.xml`
 * lists it too, as a page. Measured on production 2026-10-29:
 * `product-sitemap1.xml` held **147** entries for **146** patterns — 146
 * `/pattern/…` plus `/shop/` — and `/shop/` was in `page-sitemap1.xml` as well.
 *
 * A URL in two sitemaps is the "Duplicate, Google chose different canonical"
 * shape: two crawl entries for one page, with no way for either to know which
 * one the other engines trust.
 *
 * The page sitemap keeps it, because a page is what it is; the product sitemap
 * is the one that gained it by accident.
 *
 * SEOPress's own comment in `templates/sitemap/single.php` offers two ways to
 * suppress an archive link: check the page's per-page noindex box, or filter
 * `seopress_sitemaps_no_archive_link`. The first is unusable here — the shop
 * page must stay indexable — so this uses the second, which fires only for the
 * archive link and leaves both the per-page entries and the page sitemap
 * untouched.
 */
final class SitemapDedupe {

	/**
	 * The post type whose sitemap carries the duplicate.
	 */
	private const DUPLICATE_TYPE = 'product';

	/**
	 * Hooks the archive-link filter.
	 */
	public function register(): void {
		add_filter( 'seopress_sitemaps_no_archive_link', array( $this, 'drop_shop_archive' ), 10, 2 );
	}

	/**
	 * Empties the archive link in the product sitemap, and nothing else.
	 *
	 * @param string $markup The `<url>…</url>` block for the archive link.
	 * @param string $path   The post type whose sitemap is being rendered.
	 * @return string The untouched markup, or '' when this is the product archive.
	 */
	public function drop_shop_archive( string $markup, string $path ): string {
		return self::DUPLICATE_TYPE === $path ? '' : $markup;
	}
}
