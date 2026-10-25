<?php
/**
 * The `wp md-site windpress-anchors` command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\Presentation\WindPressAnchors;
use WP_CLI;

/**
 * The read-only anchor assertion of the WindPress compile (spec 17 §5.4):
 * the arbitrary-value utilities the templates use and the tokens volume's
 * `--md-*` declarations must all be in the compiled cache.
 *
 * The deploy's `windpress-check` calls it anonymously —
 * `wp md-site windpress-anchors --format=json` — because the check runs after
 * a compile, outside any user context, so there is no capability gate here:
 * the command reads two public files (the templates and the compiled cache)
 * and writes nothing.
 */
final class WindPressAnchorsCommand {

	/**
	 * Reports the anchors the compiled cache does not carry.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : json or table. Default: table. `json` prints one array of the missing
	 * anchors and nothing else on standard output (`[]` is the pass), which is
	 * the form the deploy parses.
	 *
	 * ## EXAMPLES
	 *
	 *     wp md-site windpress-anchors
	 *     wp md-site windpress-anchors --format=json
	 *
	 * @when after_wp_load
	 *
	 * @subcommand windpress-anchors
	 *
	 * @param array<int, string>   $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function windpress_anchors( array $args, array $assoc_args ): void {
		unset( $args );
		$cache = WindPressAnchors::cache_path();
		if ( '' === $cache || ! is_readable( $cache ) ) {
			// Nothing is compiled: the anchors are not "missing" in a way a
			// recompile repairs selectively, the compile itself has not run.
			WP_CLI::error( ( '' === $cache ? 'the uploads directory cannot be resolved' : 'the WindPress cache does not exist: ' . $cache ) . ' (present, then compile)' );
		}
		$check   = new WindPressAnchors();
		$missing = $check->missing();
		$json    = 'json' === ( $assoc_args['format'] ?? '' );
		if ( $json ) {
			// Standard output carries the array and nothing else: the deploy
			// parses this stream, so even the pass case stays silent otherwise.
			// Unescaped slashes and Unicode keep a class like
			// `aspect-[16/10]` or `content-['—']` readable in the deploy's note.
			WP_CLI::log( (string) wp_json_encode( $missing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			if ( array() !== $missing ) {
				WP_CLI::error( sprintf( 'windpress anchors: %d of %d missing from the compiled cache', count( $missing ), count( $check->anchors() ) ) );
			}
			return;
		}
		foreach ( $missing as $anchor ) {
			WP_CLI::log( $anchor );
		}
		if ( array() === $missing ) {
			WP_CLI::success( sprintf( 'windpress anchors: %d present in the compiled cache', count( $check->anchors() ) ) );
			return;
		}
		WP_CLI::error( sprintf( 'windpress anchors: %d of %d missing from the compiled cache (recompile, then rerun)', count( $missing ), count( $check->anchors() ) ) );
	}
}
