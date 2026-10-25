<?php
/**
 * Tests for the checkbox-noindex canonical rule.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\MetaNoindex;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for MetaNoindex: a page noindexed by SEOPress's checkbox keeps a
 * self-referencing canonical, because the checkbox's `yes` is one of the
 * two values that suppress SEOPress's canonical block
 * (`wp-seopress/inc/functions/options-titles-metas.php`), while the string
 * `noindex` is not.
 *
 * The negative cases carry the weight: the shipped behaviour paired
 * noindex with no canonical, so a test asserting only the positive case
 * would pass against the old code. The `1`, `noindex` and unticked-page
 * cases pin the rule's scope — widening it would change paged archives and
 * 404s that nobody asked to touch.
 *
 * @covers \MetalDivider\Site\Seo\MetaNoindex
 */
final class MetaNoindexTest extends TestCase {

	/**
	 * A page whose own checkbox is ticked gets the canonical-safe value, so
	 * it stays noindex *and* keeps its canonical.
	 */
	public function test_a_ticked_page_returns_the_canonical_safe_value(): void {
		$this->given_page( 'yes' );
		$value = ( new MetaNoindex() )->noindex( 'yes' );

		$this->assertSame( 'noindex', $value );
		$this->assertNotContains( $value, array( '1', 'yes' ), 'SEOPress suppresses the canonical only for 1/yes' );
	}

	/**
	 * The value is `noindex`, never `1`, which would be the wrong half of
	 * the pair: `1` also suppresses the canonical.
	 */
	public function test_the_rule_never_returns_the_suppressing_values(): void {
		$this->given_page( 'yes' );
		$this->assertSame( 'noindex', ( new MetaNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * A singular request whose page is not ticked keeps SEOPress's `yes`
	 * untouched — the rule may only rewrite the source it can prove.
	 */
	public function test_an_unticked_page_keeps_the_incoming_value(): void {
		$this->given_page( '' );
		$this->assertSame( 'yes', ( new MetaNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * SEOPress's `1` (paged archives, the global switch) is never touched:
	 * its canonical suppression is established behaviour outside this rule.
	 */
	public function test_the_paged_value_is_never_touched(): void {
		$this->given_page( 'yes' );
		$this->assertSame( '1', ( new MetaNoindex() )->noindex( '1' ) );
	}

	/**
	 * A `noindex` value (404s, password-protected posts) passes straight
	 * through.
	 */
	public function test_a_noindex_value_passes_through(): void {
		$this->given_page( 'yes' );
		$this->assertSame( 'noindex', ( new MetaNoindex() )->noindex( 'noindex' ) );
	}

	/**
	 * An empty decision stays empty on a ticking page: nothing to rewrite
	 * means nothing is added, so the rule cannot noindex a page by itself.
	 */
	public function test_an_empty_decision_is_not_promoted(): void {
		$this->given_page( 'yes' );
		$this->assertSame( '', ( new MetaNoindex() )->noindex( '' ) );
	}

	/**
	 * A request that is not singular (an archive, the shop) is untouchable
	 * even if some page elsewhere is ticked.
	 */
	public function test_a_non_singular_request_is_untouched(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( false );
		WP_Mock::userFunction( 'get_queried_object_id' )->never();
		WP_Mock::userFunction( 'get_post_meta' )->never();

		$this->assertSame( 'yes', ( new MetaNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * A missing queried id is not a page: nothing to read, nothing changed.
	 */
	public function test_a_zero_queried_id_is_untouched(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_post_meta' )->never();

		$this->assertSame( 'yes', ( new MetaNoindex() )->noindex( 'yes' ) );
	}

	/**
	 * The rule registers on SEOPress's bypass filter at priority 20, after
	 * PRO's WooCommerce callback (default 10) and the catalog rule, so it
	 * sees the final decision.
	 */
	public function test_it_registers_after_the_other_bypass_callbacks(): void {
		WP_Mock::expectFilterAdded( 'seopress_titles_noindex_bypass', array( new MetaNoindex(), 'noindex' ), 20 );
		( new MetaNoindex() )->register();
		$this->assertConditionsMet();
	}

	/**
	 * The fixture: a singular request whose page carries $meta.
	 *
	 * @param string $meta The `_seopress_robots_index` value on the page.
	 */
	private function given_page( string $meta ): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		WP_Mock::userFunction( 'get_queried_object_id' )->andReturn( 1573 );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static fn( int $id, string $key ): string => MetaNoindex::META === $key ? $meta : ''
		);
	}
}
