<?php
/**
 * Keeps the shop page out of the product sitemap it lands in twice.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Drops the archive link from the product sitemap only, so `/patterns/` is listed once.
 *
 * The shop page is a real `page` (ID 6, `publish`) that WooCommerce also adopts
 * as its shop page: `woocommerce_shop_page_id` is 6. SEOPress builds the
 * product sitemap from the `product` post type and, because WooCommerce hands
 * that type the shop page as its archive, writes the same URL as an
 * "archive link" at the top of `product-sitemap1.xml`. `page-sitemap1.xml`
 * lists it too, as a page. Measured on production 2026-10-29:
 * `product-sitemap1.xml` held **147** entries for **146** patterns — 146
 * `/pattern/…` plus `/shop/` — and `/shop/` was in `page-sitemap1.xml` as well.
 * (The page is now `/patterns/`; that measurement predates the owner's
 * 2026-10-30 URL ruling and the two entries it counted are the same page.)
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
	 * Hooks the archive-link filter and the sitemap index.
	 */
	public function register(): void {
		add_filter( 'seopress_sitemaps_no_archive_link', array( $this, 'drop_archive_link' ), 10, 2 );
		add_filter( 'seopress_sitemaps_xml_index', array( $this, 'drop_empty_type_entries' ) );
	}

	/**
	 * Keeps an empty post type's sitemap out of the index.
	 *
	 * Dropping every archive link (§ above) leaves `post-sitemap1.xml` with no
	 * entries at all, because a post's sitemap holds its archive link at the
	 * top and this site has no published posts. Measured on production
	 * 2026-10-29 after that change: the file answered 200 with **zero `<loc>`**,
	 * while `sitemaps.xml` still listed it — trading one duplicate URL for one
	 * dead entry.
	 *
	 * SEOPress's index loop computes `$count_posts` but only uses it to size
	 * pagination: a zero count leaves `$max_loop = 1` and the entry is printed
	 * anyway. There is no filter that turns a zero count into "skip", so this
	 * filters the assembled index instead and removes the `<sitemap>` element
	 * for any type that has no published posts.
	 *
	 * The rule is current-state, not a permanent exclusion: once the blog has a
	 * published post, this type stops being empty and its entry comes back on
	 * the next render. `GOAL.md:104` and `AGENTS.md` both record that the blog
	 * has no content source yet, which is why it is empty today.
	 *
	 * @param string $xml The assembled `<sitemapindex>` document.
	 * @return string The document without entries for empty post types.
	 */
	public function drop_empty_type_entries( string $xml ): string {
		$empties = array();

		foreach ( array( 'post', 'product', 'page' ) as $type ) {
			if ( ! \post_type_exists( $type ) ) {
				continue;
			}
			$counts = \wp_count_posts( $type );
			if ( isset( $counts->publish ) && (int) $counts->publish > 0 ) {
				continue;
			}
			$empties[] = $type;
		}

		// Taxonomies need the same treatment, and the first version of this
		// method forgot them: measured on production 2026-10-29,
		// `category-sitemap1.xml` answered with zero `<loc>` (the only category
		// is `Uncategorized`, count 0) and `sitemaps.xml` still listed it. A
		// post type is not the only container a sitemap can be built from.
		foreach ( array( 'category', 'product_cat', 'post_tag' ) as $taxonomy ) {
			if ( ! \taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = \get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! \is_array( $terms ) ) {
				continue;
			}
			$posts = 0;
			foreach ( $terms as $term ) {
				$posts += (int) $term->count;
			}
			if ( $posts > 0 ) {
				continue;
			}
			$empties[] = $taxonomy;
		}

		foreach ( $empties as $slug ) {
			$xml = (string) \preg_replace(
				'#<sitemap>\s*<loc>[^<]*/' . \preg_quote( $slug, '#' ) . '-sitemap\d*\.xml</loc>.*?</sitemap>#s',
				'',
				$xml
			);
		}

		return $xml;
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
