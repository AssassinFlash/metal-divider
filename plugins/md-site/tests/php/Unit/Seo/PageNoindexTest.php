<?php
/**
 * Tests for the page-level noindex and canonical rules.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\PageNoindex;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for PageNoindex: a page noindexed either by SEOPress's checkbox or
 * by being the install's sample page keeps a self-referencing canonical,
 * because `yes`/`1` are the two values that suppress SEOPress's canonical
 * block (`wp-seopress/inc/functions/options-titles-metas.php`) while the
 * string `noindex` is not.
 *
 * The negative cases carry the weight. The shipped behaviour paired
 * noindex with no canonical, so a test asserting only the positive case
 * would pass against the old code; and the `1`, `noindex` and non-sample
 * cases pin the scope, because widening it would change paged archives,
 * 404s and every other page that nobody asked to touch.
 *
 * @covers \MetalDivider\Site\Seo\PageNoindex
 */
final class PageNoindexTest extends TestCase {

	/**
	 * A page whose own checkbox is ticked gets the canonical-safe value, so
	 * it stays noindex *and* keeps its canonical.
	 */
	public function test_a_ticked_page_returns_the_canonical_safe_value(): void {
		$this->given_ticked_page( 'yes' );
		$value = ( new PageNoindex() )->noindex( 'yes' );

		$this->assertSame( 'noindex', $value );
		$this->assertNotContains( $value, array( '1', 'yes' ), 'SEOPress suppresses the canonical only for 1/yes' );
	}

	/**
	 * A singular request whose page is not ticked keeps SEOPress's `yes`
	 * untouched — the rule may only rewrite the source it can prove.
	 */
	public function test_an_unticked_page_keeps_the_incoming_value(): void {
		$this->given_ticked_page( '' );
		$this->assertSame( 'yes', ( new PageNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * SEOPress's `1` (paged archives, the global switch) is never touched:
	 * its canonical suppression is established behaviour outside this rule.
	 */
	public function test_the_paged_value_is_never_touched(): void {
		$this->given_ticked_page( 'yes' );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		$this->assertSame( '1', ( new PageNoindex() )->noindex( '1' ) );
	}

	/**
	 * A `noindex` value (404s, password-protected posts) passes straight
	 * through, even on the sample page: SEOPress already decided.
	 */
	public function test_a_noindex_value_passes_through(): void {
		$this->given_ticked_page( 'yes' );
		WP_Mock::userFunction( 'is_page' )->andReturn( true );
		$this->assertSame( 'noindex', ( new PageNoindex() )->noindex( 'noindex' ) );
	}

	/**
	 * An empty decision stays empty on a ticking page: nothing to rewrite
	 * means nothing is added, so the checkbox branch cannot noindex a page
	 * by itself.
	 */
	public function test_an_empty_decision_is_not_promoted_on_a_ticked_page(): void {
		$this->given_ticked_page( 'yes' );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		$this->assertSame( '', ( new PageNoindex() )->noindex( '' ) );
	}

	/**
	 * A request that is not singular (an archive, the shop) is untouchable
	 * even if some page elsewhere is ticked.
	 */
	public function test_a_non_singular_request_is_untouched(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( false );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		WP_Mock::userFunction( 'get_queried_object_id' )->never();
		WP_Mock::userFunction( 'get_post_meta' )->never();

		$this->assertSame( 'yes', ( new PageNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * A missing queried id is not a page: nothing to read, nothing changed.
	 */
	public function test_a_zero_queried_id_is_untouched(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_post_meta' )->never();

		$this->assertSame( 'yes', ( new PageNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * The sample page is noindexed at render time, because it is not a
	 * config target: the site never publishes it, so demanding it only
	 * produced a permanent drift row. It keeps a canonical for the same
	 * reason every other noindexed page does.
	 */
	public function test_the_sample_page_is_noindexed_with_a_canonical(): void {
		$this->given_sample_page();
		$this->assertSame( 'noindex', ( new PageNoindex() )->noindex( '' ) );
		$this->assertSame( 'noindex', ( new PageNoindex() )->noindex( '0' ) );
	}

	/**
	 * The sample-page rule respects SEOPress's own decisions rather than
	 * overriding them.
	 */
	public function test_the_sample_page_rule_does_not_override_seopress(): void {
		$this->given_sample_page();
		$this->assertSame( '1', ( new PageNoindex() )->noindex( '1' ) );
		$this->assertSame( 'noindex', ( new PageNoindex() )->noindex( 'noindex' ) );
	}

	/**
	 * Any other page is untouched by the sample-page branch.
	 */
	public function test_another_page_is_not_noindexed_by_the_sample_rule(): void {
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		$this->assertSame( '', ( new PageNoindex() )->noindex( '' ) );
	}

	/**
	 * The rule registers on SEOPress's bypass filter at priority 20, after
	 * PRO's WooCommerce callback (default 10) and the catalog rule, so it
	 * sees the final decision.
	 */
	public function test_it_registers_after_the_other_bypass_callbacks(): void {
		WP_Mock::expectFilterAdded( 'seopress_titles_noindex_bypass', array( new PageNoindex(), 'noindex' ), 20 );
		( new PageNoindex() )->register();
		$this->assertConditionsMet();
	}

	/**
	 * The fixture: a singular request whose page carries $meta.
	 *
	 * @param string $meta The `_seopress_robots_index` value on the page.
	 */
	private function given_ticked_page( string $meta ): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 1573 );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static fn( int $id, string $key ): string => PageNoindex::META === $key ? $meta : ''
		);
	}

	/**
	 * The fixture: a request for the sample page, with no checkbox meta.
	 */
	private function given_sample_page(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'is_page' )->andReturnUsing(
			static fn( string $slug = '' ): bool => PageNoindex::SAMPLE_SLUG === $slug
		);
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 2 );
		WP_Mock::userFunction( 'get_post_meta' )->andReturn( '' );
	}
}
