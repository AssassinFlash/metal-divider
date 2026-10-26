<?php
/**
 * The site's page-level noindex decisions and their canonical pairing.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Two kinds of page on this site must stay out of the index while still
 * naming themselves as the canonical URL:
 *
 * 1. **A page an editor ticked noindex** in SEOPress's box
 *    (`_seopress_robots_index` = `yes`) — `/inquiry/thank-you/` is the one
 *    such page in the site's information architecture.
 * 2. **The WordPress sample page**, which is not part of the information
 *    architecture at all. It is not a config target: demanding a page the
 *    site does not publish made `seo --check` report it missing on every
 *    correct run (production carries no such page), so the protection
 *    lives here, at render time, where it costs nothing when the page is
 *    absent and still covers it if it ever appears.
 *
 * Both need the same correction. SEOPress pairs `noindex` with **no
 * canonical** whenever the bypass value is `1` or `yes`
 * (`wp-seopress/inc/functions/options-titles-metas.php`: the canonical
 * block is guarded by `! in_array( $noindex, array( '1', 'yes' ), true )`),
 * so such a page renders `noindex` with no canonical at all. `noindex` and
 * a self-referencing canonical are not in conflict — the directive handles
 * indexing, the canonical consolidates the URL — and without one, any
 * duplicate shape of the same path is unconstrained.
 *
 * The correction is the value returned here: the *string* `noindex`, which
 * is neither of the two values that suppress the canonical. The meta
 * itself must keep `yes`, because `seopress_titles_noindex_post_option()`
 * returns a value only for `=== 'yes'` — storing `noindex` in the meta
 * drops the noindex entirely (measured on the dev instance: the page
 * became `index, follow`).
 *
 * Scope is deliberately narrow, because widening it would change surfaces
 * nobody asked about:
 *
 * - The checkbox branch fires only when the incoming value is exactly
 *   `yes` **and** the current request is a singular page whose own meta
 *   carries `yes`.
 * - The sample-page branch fires only on that one slug, and only when
 *   SEOPress has not already decided (`1` for paged archives, `noindex`
 *   for 404s and password-protected posts) — those pass straight through,
 *   so their established behaviour is untouched.
 */
final class PageNoindex {

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
	 * The WordPress install's sample page: never part of this site's
	 * information architecture, and never created to satisfy config.
	 */
	public const SAMPLE_SLUG = 'sample-page';

	/**
	 * Adds the filter. Priority 20: after SEOPress's own bypass callbacks
	 * (PRO's WooCommerce one sits at the default 10) and after the catalog
	 * rule, so the value arriving here is the final decision.
	 */
	public function register(): void {
		add_filter( 'seopress_titles_noindex_bypass', array( $this, 'noindex' ), 20 );
	}

	/**
	 * Returns the canonical-safe `noindex` for a page this class owns, and
	 * leaves every other decision alone.
	 *
	 * @param mixed $current SEOPress's noindex decision for this request.
	 * @return mixed `noindex` for a ticked page or the sample page, `$current` otherwise.
	 */
	public function noindex( $current ) {
		if ( self::META_VALUE === $current ) {
			return $this->page_ticked_noindex() ? 'noindex' : $current;
		}
		if ( $this->seopress_has_not_decided( $current ) && $this->is_sample_page() ) {
			return 'noindex';
		}
		return $current;
	}

	/**
	 * Whether SEOPress reached no decision of its own: an empty value, or
	 * the string `0` its service layer may hand back. Anything else — `1`
	 * for paged archives, `noindex` for 404s — is a decision this class
	 * must not override.
	 *
	 * @param mixed $current The incoming value.
	 * @return bool True when the value carries no noindex instruction.
	 */
	private function seopress_has_not_decided( $current ): bool {
		return '' === $current || '0' === $current;
	}

	/**
	 * Whether the request is the install's sample page.
	 *
	 * @return bool True only on that one slug.
	 */
	private function is_sample_page(): bool {
		return function_exists( 'is_page' ) && is_page( self::SAMPLE_SLUG );
	}

	/**
	 * Whether the request is a singular page whose own meta carries the
	 * checkbox value — the only source the checkbox branch may rewrite.
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
