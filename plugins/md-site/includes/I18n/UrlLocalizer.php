<?php
/**
 * Localizes internal URLs in HTML markup and attributes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Rewrites internal links to language-prefixed directory routes while skipping
 * static assets, system endpoints, external links, and anchor hashes.
 */
final class UrlLocalizer {

	/**
	 * URL attribute patterns to rewrite in HTML.
	 */
	private const ATTR_REGEX = '/\b(href|action|data-psd-url|data-ppr-cta-url)\s*=\s*("([^"]*)"|\'([^\']*)\')/i';

	/**
	 * System and static paths that must never be prefixed with a language code.
	 */
	private const STATIC_PREFIXES = array(
		'/wp-content/',
		'/wp-includes/',
		'/wp-admin/',
		'/wp-json/',
		'/favicon',
		'/robots.txt',
		'/sitemaps.xml',
		'/sitemap',
	);

	/**
	 * Rewrites internal URLs in an HTML fragment.
	 *
	 * @param string $html Target HTML.
	 * @param string $lang Canonical language code.
	 */
	public static function rewrite_html( string $html, string $lang ): string {
		if ( '' === $html || Languages::SOURCE === $lang ) {
			return $html;
		}

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );
		$out       = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TAG !== $token['type'] || $token['closing'] ) {
				$out .= $token['raw'];
				continue;
			}

			$tag_raw = $token['raw'];
			if (
				null !== HtmlTokenizer::attribute( $tag_raw, 'hreflang' ) ||
				null !== HtmlTokenizer::attribute( $tag_raw, 'data-no-localize' )
			) {
				$out .= $tag_raw;
				continue;
			}

			$out .= (string) preg_replace_callback(
				self::ATTR_REGEX,
				static function ( array $matches ) use ( $lang ): string {
					$attr  = $matches[1];
					$quote = '"' === $matches[2][0] ? '"' : "'";
					$raw   = isset( $matches[4] ) && '' !== $matches[4] ? $matches[4] : ( $matches[3] ?? '' );

					$rewritten = self::rewrite_url( $raw, $lang );
					return $attr . '=' . $quote . htmlspecialchars( $rewritten, ENT_QUOTES | ENT_HTML5, 'UTF-8', false ) . $quote;
				},
				$tag_raw
			);
		}

		return $out;
	}

	/**
	 * Rewrites a single URL to include the language code prefix.
	 *
	 * @param string $url  Raw URL.
	 * @param string $lang Canonical language code.
	 */
	public static function rewrite_url( string $url, string $lang ): string {
		if ( '' === $url || Languages::SOURCE === $lang ) {
			return $url;
		}

		// Fast skip for non-HTTP / fragment / mailto links.
		if (
			str_starts_with( $url, '#' ) ||
			str_starts_with( $url, 'mailto:' ) ||
			str_starts_with( $url, 'tel:' ) ||
			str_starts_with( $url, 'javascript:' ) ||
			str_starts_with( $url, '//' )
		) {
			return $url;
		}

		// Check external vs internal domain.
		$path  = $url;
		$host  = '';
		$proto = '';

		if ( str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$parsed = \function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url ) : parse_url( $url );
			if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
				return $url;
			}

			$site_host = self::site_host();
			if ( strtolower( (string) $parsed['host'] ) !== $site_host ) {
				return $url;
			}

			$port  = isset( $parsed['port'] ) ? ':' . (int) $parsed['port'] : '';
			$proto = ( $parsed['scheme'] ?? 'https' ) . '://' . $parsed['host'] . $port;
			$path  = $parsed['path'] ?? '/';
			if ( isset( $parsed['query'] ) ) {
				$path .= '?' . $parsed['query'];
			}
			if ( isset( $parsed['fragment'] ) ) {
				$path .= '#' . $parsed['fragment'];
			}
		}

		if ( ! str_starts_with( $path, '/' ) ) {
			return $url;
		}

		// Skip static and system paths.
		foreach ( self::STATIC_PREFIXES as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return $url;
			}
		}

		// Separate query and hash.
		$clean_path = $path;
		$suffix     = '';
		$q_pos      = strpos( $clean_path, '?' );
		$h_pos      = strpos( $clean_path, '#' );

		$split_at = false;
		if ( false !== $q_pos && false !== $h_pos ) {
			$split_at = min( $q_pos, $h_pos );
		} elseif ( false !== $q_pos ) {
			$split_at = $q_pos;
		} elseif ( false !== $h_pos ) {
			$split_at = $h_pos;
		}

		if ( false !== $split_at ) {
			$suffix     = substr( $clean_path, $split_at );
			$clean_path = substr( $clean_path, 0, $split_at );
		}

		// Skip if already prefixed with any active language code.
		foreach ( Languages::ALL as $code ) {
			if ( '/' . $code === $clean_path || '/' . $code . '/' === $clean_path || str_starts_with( $clean_path, '/' . $code . '/' ) ) {
				return $url;
			}
		}

		// Check for term translation on /pattern-category/<term-slug>/.
		if ( str_starts_with( $clean_path, '/pattern-category/' ) ) {
			$trimmed = trim( substr( $clean_path, strlen( '/pattern-category/' ) ), '/' );
			if ( '' !== $trimmed && ! str_contains( $trimmed, '/' ) ) {
				$term = \function_exists( 'get_term_by' ) ? \get_term_by( 'slug', $trimmed, 'product_cat' ) : null;
				if ( $term instanceof \WP_Term ) {
					$tr_id = Languages::term_translation( (int) $term->term_id, 'product_cat', $lang );
					if ( $tr_id > 0 ) {
						$tr_term = \function_exists( 'get_term' ) ? \get_term( $tr_id, 'product_cat' ) : null;
						if ( $tr_term instanceof \WP_Term && ! empty( $tr_term->slug ) ) {
							$clean_path = '/pattern-category/' . $tr_term->slug . '/';
						}
					}
				}
			}
		}

		// Prefix with /<lang>.
		$localized_path = '/' . $lang . ( '/' === $clean_path ? '/' : $clean_path );
		$result         = '' !== $proto ? $proto . $localized_path . $suffix : $localized_path . $suffix;

		return $result;
	}

	/**
	 * Detects canonical host for internal link matching.
	 */
	private static function site_host(): string {
		$home = Languages::unfiltered_home_url();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$host = \function_exists( 'wp_parse_url' ) ? \wp_parse_url( $home, PHP_URL_HOST ) : parse_url( $home, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : 'metal-divider.com';
	}
}
