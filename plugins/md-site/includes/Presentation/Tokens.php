<?php
/**
 * The design-token stylesheet and its legacy-copy guards.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The design tokens. The single source is the WindPress volume export
 * `content/windpress/tokens.css`: `present` publishes that directory into
 * the volume the compiler reads, and `Tokens::path()` points at the same
 * file so a run cannot require one copy and publish another (spec 17 §4.4).
 * Additional CSS and the LiveCanvas partials are not a second home for the
 * tokens — the two guards below only remove copies an earlier presentation
 * run or a GUI edit left there, and are idempotent: a clean site's present
 * finds nothing and writes nothing.
 */
final class Tokens {

	/**
	 * Marks the managed block inside custom CSS so a second install replaces it.
	 */
	public const MARKER = '/* md-site-tokens */';

	/**
	 * End of the managed block.
	 */
	private const END = '/* /md-site-tokens */';

	/**
	 * Absolute path of the exported stylesheet.
	 *
	 * This is the only copy. WindPressVolume publishes `content/windpress/*.css`,
	 * so the tokens file lives in that directory rather than beside it.
	 */
	public static function path(): string {
		return dirname( __DIR__, 2 ) . '/content/windpress/tokens.css';
	}

	/**
	 * The exported stylesheet.
	 */
	public static function css(): string {
		return (string) self::read( self::path() );
	}

	/**
	 * Removes the marked tokens block from Additional CSS. The site does not
	 * serve tokens from Additional CSS: the WindPress volume's tokens.css is
	 * the copy the compiler reads. This only takes out a block an earlier
	 * presentation run or a customizer edit wrote there.
	 *
	 * @return bool Whether Additional CSS changed.
	 */
	public function uninstall(): bool {
		$current = (string) wp_get_custom_css();
		if ( ! str_contains( $current, self::MARKER ) ) {
			return false;
		}
		$stripped = (string) preg_replace(
			'/' . preg_quote( self::MARKER, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '/s',
			'',
			$current
		);
		$stripped = trim( $stripped );
		if ( $stripped === $current ) {
			return false;
		}
		wp_update_custom_css_post( $stripped );
		return true;
	}

	/**
	 * Removes the inlined tokens.css style block from LiveCanvas partials.
	 *
	 * @return int Partials rewritten.
	 */
	public function detach_from_partials(): int {
		$partials = get_posts(
			array(
				'post_type'      => 'lc_partial',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$changed  = 0;
		foreach ( $partials as $partial ) {
			$stripped = (string) preg_replace( '/<style[^>]*data-site-css="tokens\.css"[^>]*>.*?<\/style>/s', '', $partial->post_content );
			if ( $stripped === $partial->post_content ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'           => $partial->ID,
					'post_content' => wp_slash( $stripped ),
				)
			);
			++$changed;
		}
		return $changed;
	}

	/**
	 * Local file contents; empty when the file cannot be read.
	 *
	 * @param string $path Absolute path.
	 */
	public static function read( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- plugin file on disk, not a remote URL.
		if ( false === $handle ) {
			return '';
		}
		$contents = stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pair of fopen above.
		return false === $contents ? '' : $contents;
	}
}
