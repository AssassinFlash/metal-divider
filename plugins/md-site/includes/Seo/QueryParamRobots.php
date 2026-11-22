<?php
/**
 * Robots noindex rule for non-canonical tracking and gallery-state query parameters.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Prevents URLs carrying internal tracking or legacy gallery-state query
 * parameters (`source_page`, `source_module`, `source_button`, `category`,
 * `project`, `image`) from being indexed while preserving the clean canonical
 * tag (`<link rel="canonical">`) and `follow` directive.
 *
 * Marketing and utility parameters (`utm_*`, `gclid`, `fbclid`, `mdaudit`,
 * `preview`, etc.) are deliberately ignored.
 */
final class QueryParamRobots {

	/**
	 * Query parameters that never define an independently indexable document.
	 *
	 * @var list<string>
	 */
	public const NON_CANONICAL_PARAMS = array(
		'source_page',
		'source_module',
		'source_button',
		'category',
		'project',
		'image',
	);

	/**
	 * Hooks into SEOPress's noindex bypass filter.
	 */
	public function register(): void {
		\add_filter( 'seopress_titles_noindex_bypass', array( $this, 'noindex' ), 10 );
	}

	/**
	 * Returns canonical-safe `'noindex'` when a non-canonical query parameter
	 * is active, while preserving any existing SEOPress noindex decision.
	 *
	 * Never returns `'1'` or `'yes'`, because SEOPress suppresses
	 * `<link rel="canonical">` for those two values.
	 *
	 * @param mixed $current SEOPress's own noindex decision for this request.
	 * @return mixed `'noindex'` when triggered, otherwise `$current`.
	 */
	public function noindex( $current ) {
		$existing = is_string( $current ) ? $current : '';
		if ( '' !== $existing && '0' !== $existing ) {
			return $current;
		}
		return self::has_non_canonical_param() ? 'noindex' : $current;
	}

	/**
	 * Checks whether the given query map (or `$_GET` by default) contains a
	 * non-empty value for any parameter in `NON_CANONICAL_PARAMS`.
	 *
	 * @param array<string, mixed>|null $query Optional query map (defaults to `$_GET`).
	 */
	public static function has_non_canonical_param( ?array $query = null ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only robots/hreflang decision, no state change.
		$source = null !== $query ? $query : $_GET;

		foreach ( self::NON_CANONICAL_PARAMS as $param ) {
			if ( ! array_key_exists( $param, $source ) ) {
				continue;
			}
			$raw = $source[ $param ];
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$value = (string) $raw;
			if ( \function_exists( 'wp_unslash' ) ) {
				$value = (string) \wp_unslash( $value );
			}
			if ( \function_exists( 'sanitize_text_field' ) ) {
				$value = \sanitize_text_field( $value );
			}
			if ( '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}
}
