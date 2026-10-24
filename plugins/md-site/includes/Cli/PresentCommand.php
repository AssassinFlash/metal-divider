<?php
/**
 * The `wp md-site present` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\Presentation\Tokens;
use MetalDivider\Site\Presentation\WindPressVolume;
use WP_CLI;

/**
 * Installs the presentation layer from this plugin's files. The first
 * edition (ticket #27): the design tokens into the WindPress volume, and the
 * two idempotent guards that remove a tokens copy left in Additional CSS or
 * a LiveCanvas partial (spec 17 §4.4). The LiveCanvas templates join with
 * the page-template tickets; every step is idempotent, so a second run
 * writes nothing.
 */
final class PresentCommand {

	/**
	 * Installs the presentation files into the current site.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function present( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run as an administrator: wp md-site present --user=<admin>.' );
		}

		$removed = ( new Tokens() )->uninstall();
		WP_CLI::log( $removed ? 'tokens: removed the Additional CSS copy (the WindPress volume serves content/windpress/tokens.css)' : 'tokens: no Additional CSS copy to remove' );

		$stripped = ( new Tokens() )->detach_from_partials();
		WP_CLI::log( sprintf( 'partials: tokens style blocks stripped from %d', $stripped ) );

		( new WindPressVolume() )->install();
		WP_CLI::log( 'windpress volume: ' . implode( ', ', array_map( 'basename', WindPressVolume::files() ) ) );

		WP_CLI::success( 'Presentation installed.' );
	}
}
