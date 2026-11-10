<?php
/**
 * Persistent storage and retrieval of pre-rendered translated LiveCanvas templates.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Manages localized template storage in wp-content/uploads/md-i18n/ with
 * SHA-256 drift detection and non-autoloaded option fallbacks.
 */
final class TemplateStore {

	/**
	 * Per-request memo of loaded language stores.
	 *
	 * @var array<string, array<string, array{src_hash: string, html: string}>>
	 */
	private static array $stores = array();

	/**
	 * Clears per-request memo for tests.
	 */
	public static function forget(): void {
		self::$stores = array();
	}

	/**
	 * Retrieves localized template content.
	 *
	 * @param string      $file_key      Template key (e.g. 'home', 'pdp').
	 * @param string      $fallback_html English source content.
	 * @param string|null $lang          Canonical language code.
	 */
	public static function get_template( string $file_key, string $fallback_html, ?string $lang = null ): string {
		$target = null !== $lang ? Languages::from_wpml( $lang ) : Languages::current();
		if ( Languages::SOURCE === $target ) {
			return $fallback_html;
		}

		$store = self::load( $target );
		$entry = $store[ 'tpl:' . $file_key ] ?? null;

		$src_hash = hash( 'sha256', rtrim( $fallback_html, "\n" ) );
		if ( null !== $entry && $entry['src_hash'] === $src_hash && ! empty( $entry['html'] ) ) {
			return UrlLocalizer::rewrite_html( $entry['html'], $target );
		}

		return UrlLocalizer::rewrite_html( $fallback_html, $target );
	}

	/**
	 * Retrieves localized partial content.
	 *
	 * @param string      $slug          Partial slug (e.g. 'md-pdp-faq', 'header').
	 * @param string      $fallback_html English source content.
	 * @param string|null $lang          Canonical language code.
	 */
	public static function get_partial( string $slug, string $fallback_html, ?string $lang = null ): string {
		$target = null !== $lang ? Languages::from_wpml( $lang ) : Languages::current();
		if ( Languages::SOURCE === $target ) {
			return $fallback_html;
		}

		$store = self::load( $target );
		$entry = $store[ 'part:' . $slug ] ?? null;

		$src_hash = hash( 'sha256', rtrim( $fallback_html, "\n" ) );
		if ( null !== $entry && $entry['src_hash'] === $src_hash && ! empty( $entry['html'] ) ) {
			return UrlLocalizer::rewrite_html( $entry['html'], $target );
		}

		return UrlLocalizer::rewrite_html( $fallback_html, $target );
	}

	/**
	 * Stores a localized template translation.
	 *
	 * @param string $file_key        Template file key.
	 * @param string $en_html         English source content.
	 * @param string $translated_html Translated HTML content.
	 * @param string $lang            Canonical language code.
	 */
	public static function put_template( string $file_key, string $en_html, string $translated_html, string $lang ): void {
		$target                      = Languages::from_wpml( $lang );
		$store                       = self::load( $target );
		$store[ 'tpl:' . $file_key ] = array(
			'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			'html'     => $translated_html,
		);
		self::save( $target, $store );
	}

	/**
	 * Stores a localized partial translation.
	 *
	 * @param string $slug            Partial slug.
	 * @param string $en_html         English source content.
	 * @param string $translated_html Translated HTML content.
	 * @param string $lang            Canonical language code.
	 */
	public static function put_partial( string $slug, string $en_html, string $translated_html, string $lang ): void {
		$target                   = Languages::from_wpml( $lang );
		$store                    = self::load( $target );
		$store[ 'part:' . $slug ] = array(
			'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			'html'     => $translated_html,
		);
		self::save( $target, $store );
	}

	/**
	 * Loads storage map for a language.
	 *
	 * @param string $lang Canonical language code.
	 * @return array<string, array{src_hash: string, html: string}>
	 */
	public static function load( string $lang ): array {
		if ( isset( self::$stores[ $lang ] ) ) {
			return self::$stores[ $lang ];
		}

		$file = self::storage_file( $lang );
		if ( null !== $file && is_readable( $file ) ) {
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $bytes ) {
				$data = json_decode( $bytes, true );
				if ( is_array( $data ) ) {
					/* @var array<string, array{src_hash: string, html: string}> $data */
					self::$stores[ $lang ] = $data;
					return self::$stores[ $lang ];
				}
			}
		}

		// Fallback to non-autoloaded WP option.
		if ( \function_exists( 'get_option' ) ) {
			$opt = \get_option( 'md_i18n_templates_' . $lang, array() );
			if ( is_array( $opt ) ) {
				/* @var array<string, array{src_hash: string, html: string}> $opt */
				self::$stores[ $lang ] = $opt;
				return self::$stores[ $lang ];
			}
		}

		self::$stores[ $lang ] = array();
		return self::$stores[ $lang ];
	}

	/**
	 * Persists storage map for a language.
	 *
	 * @param string                                               $lang  Canonical language code.
	 * @param array<string, array{src_hash: string, html: string}> $store Store data.
	 */
	private static function save( string $lang, array $store ): void {
		self::$stores[ $lang ] = $store;

		$file = self::storage_file( $lang );
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		try {
			$json = \function_exists( 'wp_json_encode' )
				? (string) \wp_json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: (string) json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable ) {
			$json = (string) json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$written = false;
		if ( null !== $file ) {
			$dir = dirname( $file );
			if ( ! is_dir( $dir ) && \function_exists( 'wp_mkdir_p' ) ) {
				\wp_mkdir_p( $dir );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$res = @file_put_contents( $file, $json, LOCK_EX );
			if ( false !== $res ) {
				$written = true;
			}
		}

		// Also sync to options fallback with autoload = false.
		if ( \function_exists( 'update_option' ) ) {
			\update_option( 'md_i18n_templates_' . $lang, $store, false );
		}
	}

	/**
	 * Resolves filesystem path for a language's cache file.
	 *
	 * @param string $lang Canonical language code.
	 */
	private static function storage_file( string $lang ): ?string {
		if ( \function_exists( 'wp_upload_dir' ) ) {
			$uploads = \wp_upload_dir();
			if ( ! empty( $uploads['basedir'] ) ) {
				return (string) $uploads['basedir'] . '/md-i18n/templates-' . $lang . '.json';
			}
		}
		return null;
	}
}
