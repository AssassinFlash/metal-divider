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
 * Only the deploy can ask: it writes a one-time token into a file outside the
 * web root on the host, posts it in a header, and this plugin compares,
 * deletes the file and resets. The token never leaves the host. A must-use
 * plugin, so it answers even when a regular plugin of the new release fails
 * (docs/specs/23-mu-plugins.md §6.2, the interface this was ported to).
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
	 * The token file, under the private configuration directory of this
	 * project's tooling ($HOME is set in wp-config for web PHP; the same
	 * directory holds this machine's other non-secret tool state).
	 */
	public const TOKEN = '/.config/metal-divider/opcache-reset.token';

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
		$home = getenv( 'HOME' );
		if ( ! is_string( $home ) || '' === $home ) {
			return;
		}
		$outcome = self::decide(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- compared with hash_equals only, never output or stored.
			$home . self::TOKEN,
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
