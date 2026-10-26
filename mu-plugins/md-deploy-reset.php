<?php
/**
 * Plugin Name: MD deploy cache reset
 * Description: Clears the PHP opcode cache once, when the deploy asks right after its switch.
 *
 * The deploy switches every file in one rename, but PHP keeps the compiled
 * old files: the shared host revalidates them every 2 s
 * (`opcache.validate_timestamps=1`, `opcache.revalidate_freq=2`, read on the
 * platform's other account 2026-10-18), and for those seconds a request can
 * run an old file against new ones and autoload a class the release deleted
 * — the blueprint's first atomic deploy logged two fatals at the switch
 * second. The CLI's opcode cache is another process, so the reset has to run
 * in a web request.
 *
 * Only the deploy can ask: it writes a one-time token into a directory beside
 * the web root on the host, posts it in a header, and this plugin compares,
 * deletes the file and resets. The token never leaves the host, and the
 * directory's location is derived from ABSPATH rather than from an
 * environment variable — see DeployReset::token_file() for why that matters
 * on the production host. A must-use plugin, so it answers even when a
 * regular plugin of the new release fails (docs/specs/23-mu-plugins.md §6.2,
 * the interface this was ported to).
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu;

// phpcs:disable WordPress.WP.AlternativeFunctions -- the token is a plain file outside WordPress, read once and removed.

/**
 * Decides and performs one reset request.
 */
final class DeployReset {

	/**
	 * The request header that carries the token.
	 */
	public const HEADER = 'HTTP_X_MD_OPCACHE_RESET';

	/**
	 * The token's directory, beside the WordPress root and outside it.
	 */
	public const DIRECTORY = '/.md-deploy';

	/**
	 * The token's file name, inside DIRECTORY.
	 */
	public const TOKEN_FILE = '/opcache-reset.token';

	/**
	 * The token file for a WordPress root: `<root>/../.md-deploy/opcache-reset.token`.
	 *
	 * The path is derived from a filesystem location, never from an environment
	 * variable. The previous version asked `getenv( 'HOME' )`, and the web SAPI
	 * of the production host runs `lsphp` with no `HOME` in its environment
	 * (measured on the platform 2026-10-26: `/proc/<lsphp pid>/environ` is
	 * readable and carries ten entries, none of them `HOME`, while the ssh
	 * shell of the same account has it). `handle()` therefore returned before
	 * reading the token, and every deploy logged `opcache-reset=none`: no
	 * attempt was made at all, and the header that would have said `done` was
	 * never emitted.
	 *
	 * `dirname( ABSPATH )` is the domain directory the docroot lives in — not
	 * web-served, writable by the account, and the one location both sides of
	 * the exchange can compute without sharing an environment: the deploy's own
	 * shell derives it from the root it deploys into. It is also per site, so a
	 * staging token can never be presented to production.
	 *
	 * @param string $root The WordPress root (ABSPATH), with or without a trailing slash.
	 * @return string The token file's absolute path.
	 */
	public static function token_file( string $root ): string {
		return dirname( rtrim( $root, '/' ) ) . self::DIRECTORY . self::TOKEN_FILE;
	}

	/**
	 * What a request asks and what happened: null when it is not a valid reset
	 * request (nothing done), otherwise 'done' or 'unavailable'.
	 *
	 * @param array<string, mixed> $server The request's server values.
	 * @param string               $file   The token file.
	 * @param callable(): bool     $reset  The reset itself.
	 * @return string|null
	 */
	public static function decide( array $server, string $file, callable $reset ): ?string {
		$given = $server[ self::HEADER ] ?? '';
		if ( 'POST' !== ( $server['REQUEST_METHOD'] ?? '' ) || ! is_string( $given ) || '' === $given ) {
			return null;
		}
		$expected = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
			return null;
		}
		unlink( $file );
		return $reset() ? 'done' : 'unavailable';
	}

	/**
	 * Answers a reset request and ends it; any other request passes through.
	 */
	public static function handle(): void {
		$root = defined( 'ABSPATH' ) ? (string) ABSPATH : '';
		// A root at the filesystem root has no domain directory to put the
		// token in, and a relative path would point at nothing predictable.
		if ( '' === $root || '/' === rtrim( $root, '/' ) ) {
			return;
		}
		$outcome = self::decide(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- compared with hash_equals only, never output or stored.
			self::token_file( $root ),
			static fn(): bool => function_exists( 'opcache_reset' ) && opcache_reset()
		);
		if ( null === $outcome ) {
			return;
		}
		http_response_code( 204 );
		header( 'X-MD-Opcache-Reset: ' . $outcome );
		exit;
	}
}

if ( 'cli' !== PHP_SAPI ) {
	DeployReset::handle();
}
