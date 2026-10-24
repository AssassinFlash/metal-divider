<?php
/**
 * The `wp md-site present` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\Presentation\LiveCanvasOptions;
use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Presentation\Tokens;
use MetalDivider\Site\Presentation\WindPressVolume;
use WP_CLI;

/**
 * Installs the presentation layer from this plugin's files: the
 * WindPress volume (tokens first, then every template and component
 * volume), the LiveCanvas templates (page-type keys reclaimed, per-page
 * faces created and their pages pointed at them, the chrome partials
 * registered with LiveCanvas's own header/footer assignment — spec 19
 * §3/§4), and the two idempotent tokens guards (spec 17 §4.4). Every
 * step is idempotent, so a second run writes nothing.
 */
final class PresentCommand {

	/**
	 * Installs the presentation files into the current site.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Read-only drift check instead of an install: the DB against the
	 * files, exit 1 with one line per drift row.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function present( array $args, array $assoc_args ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site present --user=<admin>.' );
		}
		if ( isset( $assoc_args['check'] ) ) {
			$this->check();
			return;
		}

		$removed = ( new Tokens() )->uninstall();
		WP_CLI::log( $removed ? 'tokens: removed the Additional CSS copy (the WindPress volume serves content/windpress/tokens.css)' : 'tokens: no Additional CSS copy to remove' );

		$stripped = ( new Tokens() )->detach_from_partials();
		WP_CLI::log( sprintf( 'partials: tokens style blocks stripped from %d', $stripped ) );

		( new WindPressVolume() )->install();
		WP_CLI::log( 'windpress volume: ' . implode( ', ', array_map( 'basename', WindPressVolume::files() ) ) );

		$enabled = ( new LiveCanvasOptions() )->enable();
		WP_CLI::log( $enabled ? 'livecanvas: dynamic templating + chrome partials enabled' : 'livecanvas: already enabled' );

		$report = ( new Templates() )->sync();
		WP_CLI::log(
			sprintf(
				'templates: %d created, %d updated, %d unchanged; partials: %d created, %d updated; pages assigned: %d',
				$report['created'],
				$report['updated'],
				$report['unchanged'],
				$report['partials_created'],
				$report['partials_updated'],
				$report['pages_assigned']
			)
		);
		if ( array() !== $report['pages_missing'] ) {
			WP_CLI::warning( 'pages not installed (the migrator creates them, ticket #31): ' . implode( ', ', $report['pages_missing'] ) );
		}

		WP_CLI::success( 'Presentation installed.' );
	}

	/**
	 * The read-only drift check (spec 19 §8 G1): every face's DB
	 * post_content against its file, every page assignment against its
	 * template. Zero rows is the pass.
	 */
	private function check(): void {
		$rows = ( new Templates() )->drift();
		if ( array() === $rows ) {
			WP_CLI::success( 'present drift: 0 rows — the site matches the files.' );
			return;
		}
		foreach ( $rows as $row ) {
			WP_CLI::log( sprintf( '%s · %s · %s', $row['surface'], $row['target'], $row['detail'] ) );
		}
		WP_CLI::error( sprintf( 'present drift: %d rows (run wp md-site present to repair)', count( $rows ) ) );
	}
}
