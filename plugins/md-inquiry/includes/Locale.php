<?php
/**
 * Locale helpers and normalization for the inquiry chain.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Handles the 9 supported locales: canonical codes, WPML mappings, WordPress
 * locale representations, and Turnstile language tags.
 */
final class Locale {

	/**
	 * Default fallback locale.
	 */
	public const DEFAULT = 'en';

	/**
	 * Canonical public locale codes.
	 *
	 * @var list<string>
	 */
	public const ALL = array(
		'en',
		'zh',
		'es',
		'pt-br',
		'de',
		'fr',
		'ar',
		'id',
		'vi',
	);

	/**
	 * Normalizes a raw language or locale string to our canonical 9-language code.
	 *
	 * Handles WPML codes (e.g. 'zh-hans'), WordPress locales (e.g. 'zh_CN',
	 * 'pt_BR'), Turnstile codes (e.g. 'zh-cn'), and standard ISO codes.
	 *
	 * @param string $raw The raw language or locale string.
	 * @return string The canonical code or '' if unsupported.
	 */
	public static function normalize( string $raw ): string {
		$clean = \strtolower( \trim( $raw ) );
		if ( '' === $clean ) {
			return '';
		}

		$clean = \str_replace( '_', '-', $clean );

		if ( 'zh' === $clean || \str_starts_with( $clean, 'zh-' ) ) {
			return 'zh';
		}

		if ( 'pt' === $clean || 'pt-br' === $clean || \str_starts_with( $clean, 'pt-' ) ) {
			return 'pt-br';
		}

		if ( \in_array( $clean, self::ALL, true ) ) {
			return $clean;
		}

		$two = \substr( $clean, 0, 2 );
		if ( 'pt' !== $two && \in_array( $two, self::ALL, true ) ) {
			return $two;
		}

		return '';
	}

	/**
	 * Whether a locale is one of the supported 9 canonical codes.
	 *
	 * @param string $locale The locale string.
	 * @return bool
	 */
	public static function is_valid( string $locale ): bool {
		return \in_array( self::normalize( $locale ), self::ALL, true );
	}

	/**
	 * Resolves the current request's canonical locale.
	 *
	 * Checks WPML's current language filter, then WordPress's determine_locale()
	 * and get_locale(), normalizing to our canonical code and defaulting to 'en'.
	 *
	 * @return string
	 */
	public static function current(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML public filter.
		$wpml = \apply_filters( 'wpml_current_language', null );
		if ( \is_string( $wpml ) && '' !== $wpml ) {
			$norm = self::normalize( $wpml );
			if ( '' !== $norm ) {
				return $norm;
			}
		}

		if ( \function_exists( 'determine_locale' ) ) {
			$norm = self::normalize( (string) \determine_locale() );
			if ( '' !== $norm ) {
				return $norm;
			}
		}

		if ( \function_exists( 'get_locale' ) ) {
			$norm = self::normalize( (string) \get_locale() );
			if ( '' !== $norm ) {
				return $norm;
			}
		}

		return self::DEFAULT;
	}

	/**
	 * Converts a canonical locale to WPML's internal language code.
	 *
	 * WPML internal code for Simplified Chinese is 'zh-hans' (display_code 'zh').
	 *
	 * @param string $locale Canonical or raw locale.
	 * @return string
	 */
	public static function to_wpml( string $locale ): string {
		$norm = self::normalize( $locale );
		if ( 'zh' === $norm ) {
			return 'zh-hans';
		}
		return '' !== $norm ? $norm : self::DEFAULT;
	}

	/**
	 * Converts a canonical locale to WordPress's full locale string.
	 *
	 * @param string $locale Canonical or raw locale.
	 * @return string
	 */
	public static function to_wp_locale( string $locale ): string {
		$norm = self::normalize( $locale );
		return match ( $norm ) {
			'zh'    => 'zh_CN',
			'es'    => 'es_ES',
			'pt-br' => 'pt_BR',
			'de'    => 'de_DE',
			'fr'    => 'fr_FR',
			'ar'    => 'ar',
			'id'    => 'id_ID',
			'vi'    => 'vi',
			default => 'en_US',
		};
	}

	/**
	 * Converts a canonical locale to Cloudflare Turnstile's language code.
	 *
	 * @param string $locale Canonical or raw locale.
	 * @return string
	 */
	public static function to_turnstile( string $locale ): string {
		$norm = self::normalize( $locale );
		return match ( $norm ) {
			'zh'    => 'zh-cn',
			'pt-br' => 'pt-br',
			'es'    => 'es',
			'de'    => 'de',
			'fr'    => 'fr',
			'ar'    => 'ar',
			'id'    => 'id',
			'vi'    => 'vi',
			default => 'en',
		};
	}

	/**
	 * Whether a locale is right-to-left.
	 *
	 * @param string $locale Canonical or raw locale.
	 * @return bool
	 */
	public static function is_rtl( string $locale ): bool {
		return 'ar' === self::normalize( $locale );
	}
}
