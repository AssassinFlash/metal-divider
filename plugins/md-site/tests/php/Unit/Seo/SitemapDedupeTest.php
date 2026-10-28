<?php
/**
 * The shop page is listed once, in the sitemap that owns it.
 *
 * @package MetalDivider\Site\Seo
 */

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\SitemapDedupe;
use MetalDivider\Site\Tests\TestCase;

/**
 * Guards the sitemap dedupe for the WooCommerce shop page.
 *
 * Measured on production 2026-10-29: `product-sitemap1.xml` listed 147 URLs for
 * 146 patterns — the extra one was `/shop/`, which `page-sitemap1.xml` also
 * lists. The two generators are the page list and the product post type's
 * archive link.
 *
 * The narrowness matters more than the exclusion: suppressing the archive link
 * for every post type would take `/blog/` out of its sitemap too, and nothing
 * would fail until someone noticed a missing page in Search Console.
 */
final class SitemapDedupeTest extends TestCase {

	/**
	 * The product archive link is removed.
	 */
	public function test_the_product_archive_link_is_dropped(): void {
		$markup = "<url>\n<loc>https://metal-divider.com/shop/</loc>\n</url>";

		$this->assertSame(
			'',
			( new SitemapDedupe() )->drop_shop_archive( $markup, 'product' ),
			'the shop page is written by the page generator already; the product archive entry is the duplicate'
		);
	}

	/**
	 * Every other post type keeps its archive link.
	 *
	 * `/blog/` is the archive of `post` and is the only page in
	 * `post-sitemap1.xml`; suppressing its archive link would empty that file.
	 */
	public function test_every_other_archive_link_survives(): void {
		$markup = "<url>\n<loc>https://metal-divider.com/blog/</loc>\n</url>";

		foreach ( array( 'post', 'page', 'md_inquiry', '' ) as $path ) {
			$this->assertSame(
				$markup,
				( new SitemapDedupe() )->drop_shop_archive( $markup, $path ),
				'only the product archive is the duplicate; ' . $path . ' keeps its link'
			);
		}
	}

	/**
	 * The rule is hooking the archive-link filter, not the per-entry one.
	 *
	 * `seopress_sitemaps_url` fires for every `<url>` in every sitemap,
	 * including the page sitemap's own `/shop/` entry and every `/pattern/…`
	 * entry. Filtering that one would remove the URL from both sitemaps, which
	 * is the opposite of the fix.
	 */
	public function test_the_rule_is_registered_on_the_archive_link_filter(): void {
		$source = (string) file_get_contents(
			(string) ( new \ReflectionClass( SitemapDedupe::class ) )->getFileName()
		);
		$source = (string) preg_replace( '/\/\*.*?\*\//s', '', $source );

		$this->assertStringContainsString(
			'seopress_sitemaps_no_archive_link',
			$source,
			'the archive-link hook is the one that scopes to the product sitemap'
		);
		$this->assertStringNotContainsString(
			"'seopress_sitemaps_url'",
			$source,
			'the per-entry hook fires in every sitemap, including the page one that must keep /shop/'
		);
	}
}
