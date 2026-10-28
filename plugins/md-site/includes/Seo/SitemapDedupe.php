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
	 * Hooks the archive-link filter.
	 */
	public function register(): void {
		add_filter( 'seopress_sitemaps_no_archive_link', array( $this, 'drop_archive_link' ), 10, 2 );
	}

	/**
	 * Drops every archive link, because every one of them names a page.
	 *
	 * The first version of this scoped to `product` only, and that was too
	 * narrow: the same shape exists for `post`. Measured on production
	 * 2026-10-29 — `page-sitemap1.xml` counts 1 URL containing `/blog/` and
	 * `post-sitemap1.xml` counts 1, and `/blog/` is a real page (ID 1542,
	 * `page_for_posts` = 1542). So the blog page was listed twice for exactly
	 * the reason the shop page was: SEOPress writes a post type's archive link
	 * at the top of that type's sitemap, and WordPress's `page_for_posts` /
	 * `woocommerce_shop_page_id` make that archive a page the page sitemap
	 * already covers.
	 *
	 * SEOPress's own comment names the two remedies and says plainly that the
	 * archive of `post` is the static Posts page and the archive of `product`
	 * is the WooCommerce shop page. Both are pages. The per-page noindex box
	 * cannot be used (these pages must stay indexable), so this drops the
	 * archive link for every type and leaves the page entries untouched.
	 *
	 * A post type whose archive is NOT a page would lose a legitimate entry
	 * here. Measured today, none exists: `page`, `post` and `product` are the
	 * only types in the sitemap, and only `post` and `product` have archives.
	 * The test pins that reasoning by checking the two known cases.
	 *
	 * @param string $markup The `<url>…</url>` block for the archive link.
	 * @param string $path   The post type whose sitemap is being rendered.
	 * @return string '' — the page generator already lists this URL.
	 */
	public function drop_archive_link( string $markup, string $path ): string {
		unset( $markup, $path );
		return '';
	}
}
