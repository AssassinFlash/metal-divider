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
	 * Both archive links that name a page are removed.
	 *
	 * Two shapes exist today and both are pages, which is why one rule covers
	 * them: the archive of `product` is the WooCommerce shop page (ID 6,
	 * `woocommerce_shop_page_id` = 6) and the archive of `post` is the static
	 * Posts page (ID 1542, `page_for_posts` = 1542). Measured on production
	 * 2026-10-29, both URLs were listed by the page sitemap as well.
	 *
	 * The first version of this rule scoped to `product` alone and left the
	 * blog page duplicated — this test would have caught that.
	 */
	public function test_every_archive_link_that_names_a_page_is_dropped(): void {
		$rule = new SitemapDedupe();

		foreach ( array(
			'product' => '/shop/',
			'post'    => '/blog/',
		) as $type => $path ) {
			$this->assertSame(
				'',
				$rule->drop_archive_link( "<url>\n<loc>https://metal-divider.com" . $path . "</loc>\n</url>", $type ),
				'the ' . $type . ' archive is ' . $path . ', which the page generator already lists'
			);
		}
	}

	/**
	 * The rule never leaves a half-written `<url>` block behind.
	 *
	 * It returns the empty string rather than a stripped-out block, because
	 * SEOPress concatenates whatever it gets into the `<urlset>`. Returning
	 * markup with the `<loc>` removed would produce invalid XML that a crawler
	 * rejects for the whole file, not just that entry.
	 */
	public function test_the_removed_entry_is_an_empty_string_not_a_broken_block(): void {
		$rule   = new SitemapDedupe();
		$result = $rule->drop_archive_link( "<url>\n<loc>https://metal-divider.com/blog/</loc>\n</url>", 'post' );

		$this->assertSame( '', $result, 'the whole block must go; a partial block breaks the XML document' );
		$this->assertStringNotContainsString( '<url>', $result );
		$this->assertStringNotContainsString( '<loc>', $result );
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
