<?php
/**
 * The payload runner: evaluates 18-wordpress.php outside WordPress the same
 * way `wp eval-file -` evaluates it inside — the request JSON, base64, in
 * place of the file's payload literal — and returns the decoded answer from
 * the marker line.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

const MD_MIGRATE_PAYLOAD_LITERAL = "'md-migrate-payload'";
const MD_MIGRATE_MARKER          = '@@md-migrate@@';

/**
 * Runs one request against the driver and returns its answer.
 *
 * @param array<string, mixed> $request The stage/action request, marker included.
 * @return array<string, mixed> The decoded answer.
 * @throws RuntimeException When the placeholder is missing or no marker line answers.
 */
function md_migrate_run_request( array $request ): array {
	$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/18-wordpress.php' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the same payload channel the driver itself uses: any request JSON in a single-quoted literal.
	$encoded = base64_encode( (string) wp_json_encode( $request ) );
	$patched = str_replace( MD_MIGRATE_PAYLOAD_LITERAL, "'" . $encoded . "'", $source, $count );
	if ( 1 !== $count ) {
		throw new RuntimeException( 'the payload placeholder appears ' . $count . ' times, expected exactly once' );
	}
	$temporary = (string) tempnam( sys_get_temp_dir(), 'md-migrate-' );
	file_put_contents( $temporary . '.php', $patched );

	ob_start();
	try {
		require $temporary . '.php';
		$output = (string) ob_get_clean();
	} finally {
		@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of the throwaway copy.
		@unlink( $temporary . '.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.
	}

	foreach ( explode( "\n", $output ) as $line ) {
		if ( str_starts_with( $line, MD_MIGRATE_MARKER ) ) {
			$answer = json_decode( substr( $line, strlen( MD_MIGRATE_MARKER ) ), true );
			if ( is_array( $answer ) ) {
				return $answer;
			}
		}
	}
	throw new RuntimeException( 'no marker line in the output: ' . $output );
}

/**
 * One media/write request carrying a single import entry.
 *
 * @param array<string, mixed> $entry The import entry as the plan holds it.
 * @param string               $source The _md_source_url of the entry.
 * @return array<string, mixed> The decoded answer.
 */
function md_migrate_import( array $entry, string $source ): array {
	return md_migrate_run_request(
		array(
			'stage'   => 'media',
			'action'  => 'write',
			'marker'  => MD_MIGRATE_MARKER,
			'product' => array(
				'sku'    => 'MD-TEST',
				'handle' => 'test-pattern',
				'media'  => array( array( 'master_src' => $source ) ),
				'import' => array( $entry ),
				'fill'   => array(),
				'bind'   => array(),
			),
		)
	);
}

/**
 * The remote-mode entry shape: attach_uploads and the variant names the dev
 * import recorded (attach_uploads_variants), with the local attach paths
 * deliberately absent/bogus — the remote branch must never touch them.
 *
 * @param string $relative  The uploads-relative attachment file.
 * @param string $name      The basename the tiers and files share.
 * @param string $extension The file extension.
 * @return array<string, mixed> The entry.
 */
function md_migrate_remote_entry( string $relative, string $name, string $extension = 'webp' ): array {
	return array(
		'master_src'              => 'https://cdn.example/' . $name . '.' . $extension,
		'attach_uploads'          => $relative,
		'role'                    => 'accent render',
		'alt'                     => 'Test | Architectural Metal Screen Pattern',
		'sha256'                  => 'sha-' . $name,
		'variants'                => array(
			array(
				'w'    => 480,
				'file' => $name . '--480w.' . $extension,
				'path' => '/no/such/local/path/' . $name . '--480w.' . $extension,
			),
			array(
				'w'    => 1024,
				'file' => $name . '--1024w.' . $extension,
				'path' => '/no/such/local/path/' . $name . '--1024w.' . $extension,
			),
		),
		'attach_uploads_variants' => array(
			array(
				'w' => 480,
				'f' => $name . '-480w.' . $extension,
			),
			array(
				'w' => 1024,
				'f' => $name . '-1024w.' . $extension,
			),
		),
	);
}
