<?php
/**
 * Keeps a self-referencing canonical on pages noindexed by the meta checkbox.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * A page an editor marked noindex by ticking SEOPress's "noindex" box
 * (`_seopress_robots_index` = `yes`) should stay out of the index while
 * still naming itself as the canonical URL. SEOPress ships the opposite
 * pairing for that checkbox: a noindex value of `yes` suppresses the whole
 * canonical block (`wp-seopress/inc/functions/options-titles-metas.php`,
 * the canonical block is guarded by
 * `! in_array( $noindex, array( '1', 'yes' ), true )`), so the page
 * renders `noindex` with no canonical at all.
 *
 * That pairing is wrong for this site's two such pages. `/inquiry/thank-you/`
 * and `/sample-page/` are real, single URLs that should not be indexed but
 * do have a canonical address; without one, a crawler that reaches them
 * (from an old link, a referrer, a form redirect) has no statement of which
 * URL the page is, and any duplicate shape of the same path is
 * unconstrained. `noindex` and a self-referencing canonical are not in
 * conflict: the directive handles indexing, the canonical consolidates the
 * URL. Combined with `follow`, the page remains traversable.
 *
 * The fix is the same mechanism the catalog rules use — return the *string*
 * `noindex` from SEOPress's bypass filter, which is not one of the two
 * values that suppress the canonical. The meta itself must keep `yes`:
 * `seopress_titles_noindex_post_option()` returns a value only for
 * `=== 'yes'`, so storing `noindex` there drops the noindex entirely
 * (measured on the dev instance: the page became `index, follow`).
 *
 * Scope is deliberately narrow, because widening it would change surfaces
 * nobody asked about:
 *
 * - The rule fires only when the incoming value is exactly `yes` **and**
 *   the current request is a singular page whose own meta carries `yes`.
 *   The value `1` (paged archives, global switches) and `noindex` (404s,
 *   password-protected posts) pass straight through, so their established
 *   behaviour is untouched.
 * - A page that is not explicitly ticked keeps whatever SEOPress decided.
 */
final class MetaNoindex {

	/**
	 * The post meta SEOPress's noindex checkbox writes.
	 */
	public const META = '_seopress_robots_index';

	/**
	 * The value the checkbox stores, and the only one SEOPress reads as
	 * "noindex this post".
	 */
	public const META_VALUE = 'yes';

	/**
	 * Adds the filter. Priority 20: after SEOPress's own bypass callbacks
	 * (PRO's WooCommerce one sits at the default 10) and after the catalog
	 * rule, so the value arriving here is the final decision.
	 */
	public function register(): void {
		add_filter( 'seopress_titles_noindex_bypass', array( $this, 'noindex' ), 20 );
	}

	/**
	 * Replaces the checkbox's `yes` with the canonical-safe `noindex`, and
	 * leaves every other decision alone.
	 *
	 * @param mixed $current SEOPress's noindex decision for this request.
	 * @return mixed `noindex` when the page's own checkbox decided it, `$current` otherwise.
	 */
	public function noindex( $current ) {
		if ( self::META_VALUE !== $current ) {
			return $current;
		}
		return $this->page_ticked_noindex() ? 'noindex' : $current;
	}

	/**
	 * Whether the request is a singular page whose own meta carries the
	 * checkbox value — the only source this rule may rewrite.
	 *
	 * @return bool True when `_seopress_robots_index` is `yes` on this page.
	 */
	private function page_ticked_noindex(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}
		if ( ! function_exists( 'get_queried_object_id' ) || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return false;
		}
		return self::META_VALUE === (string) get_post_meta( $id, self::META, true );
	}
}
