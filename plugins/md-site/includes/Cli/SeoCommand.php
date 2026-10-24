<?php
/**
 * The `wp md-site seo` command.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\Seo\Config;
use WP_CLI;

/**
 * Installs the SEOPress configuration plane (spec 22, ticket #34):
 * the title/description templates, the noindex faces, the sitemap,
 * the robots.txt virtual file, the Knowledge Graph, the structured
 * data builder posts, the /pages redirect and the SEO-owned pages'
 * metas — every write idempotent, `--check` the read-only drift
 * report.
 */
final class SeoCommand {

	/**
	 * Installs the SEO configuration into the current site.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Read-only drift check instead of an install: the site against
	 * this plugin's target, exit 1 with one line per drift row.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function seo( array $args, array $assoc_args ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site seo --user=<admin>.' );
		}
		$config = new Config();
		if ( isset( $assoc_args['check'] ) ) {
			$rows = $config->check();
			if ( array() === $rows ) {
				WP_CLI::success( 'seo drift: 0 rows — the site matches the target.' );
				return;
			}
			foreach ( $rows as $row ) {
				WP_CLI::log( $row );
			}
			WP_CLI::error( sprintf( 'seo drift: %d rows (run wp md-site seo to repair)', count( $rows ) ) );
		}
		foreach ( $config->apply() as $line ) {
			WP_CLI::log( $line );
		}
		WP_CLI::success( 'SEO configuration installed.' );
	}
}
