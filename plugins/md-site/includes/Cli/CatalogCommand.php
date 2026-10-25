<?php
/**
 * The `wp md-site series-images` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\Catalog;
use WP_CLI;

/**
 * Writes the series cards' hero images. The old site's hub cards carried
 * hand-picked theme images; the converted hub reads term meta
 * `hero_image` and nothing ever wrote it (ticket #63 M5, ticket #68). The
 * nine source images are already imported attachments, so this is a data
 * write, not a media import: Catalog::apply_series_hero_images resolves
 * each term's source image by the migrator's `_md_source_url` and stores
 * the attachment URL. A rerun writes nothing and says so.
 */
final class CatalogCommand {

	/**
	 * Writes the nine series hero images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp md-site series-images
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Options (unused).
	 */
	public function series_images( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site series-images --user=<admin>.' );
		}
		$report = ( new Catalog() )->apply_series_hero_images();
		if ( array() !== $report['missing'] ) {
			WP_CLI::error( 'series hero images: missing ' . implode( ', ', $report['missing'] ) );
		}
		WP_CLI::success(
			sprintf(
				'series hero images: %d written, %d unchanged',
				$report['written'],
				$report['unchanged']
			)
		);
	}
}
