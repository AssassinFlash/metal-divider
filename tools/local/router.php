<?php
/**
 * Router of PHP's built-in web server for a local instance
 * (`php -S 127.0.0.1:<port> -t <docroot> router.php`, started by md-local).
 *
 * It does what the rewrite rules of the production server do: an existing
 * file is served as it is (a PHP file is run), a directory is addressed with a
 * trailing slash, and every other path goes to WordPress's index.php.
 *
 * @package Md\Local
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- the router only maps the request path to a file; WordPress is not loaded yet.
$md_local_root = (string) $_SERVER['DOCUMENT_ROOT'];
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress (wp_parse_url) is not loaded yet.
$md_local_path = rawurldecode( (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$md_local_file = $md_local_root . $md_local_path;

// A path that climbs out of the document root is not a WordPress URL.
if ( str_contains( $md_local_path, '/..' ) ) {
	http_response_code( 400 );
	return true;
}

// As Apache's mod_dir does: /wp-admin becomes /wp-admin/.
if ( is_dir( $md_local_file ) && ! str_ends_with( $md_local_path, '/' ) ) {
	$md_local_query = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
	header( 'Location: ' . $md_local_path . '/' . ( '' === $md_local_query ? '' : '?' . $md_local_query ), true, 301 );
	return true;
}
// phpcs:enable

if ( is_file( $md_local_file ) || is_file( $md_local_file . 'index.php' ) ) {
	// The built-in server ignores `Range:`: it answers 200 with the whole
	// file. Production (LiteSpeed) and every real web server answer 206,
	// and a browser fetching video by byte range aborts the 200 it cannot
	// seek — the local `<video>` then never plays, a difference no real
	// target has. Serve the range here so dev matches the targets.
	if ( is_file( $md_local_file ) && isset( $_SERVER['HTTP_RANGE'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a request header, parsed by the handler below; WordPress is not loaded yet.
		if ( md_local_serve_range( $md_local_file, (string) $_SERVER['HTTP_RANGE'] ) ) {
			return true;
		}
	}
	return false;
}

chdir( $md_local_root );
require $md_local_root . '/index.php';

/**
 * Answer a single-range request as the production servers do.
 *
 * Only the `bytes=N-M` forms are honoured (no multipart ranges — nothing
 * this stack serves asks for them); a malformed or unsatisfiable range
 * falls back to the built-in server's plain response.
 *
 * @param string $file  Absolute path of an existing file.
 * @param string $range The `Range` request header.
 *
 * @return bool True when the request was answered here; false leaves it to
 *              the built-in server (an unsupported or malformed range).
 */
function md_local_serve_range( string $file, string $range ): bool {
	if ( ! preg_match( '/^bytes=\s*(\d*)\s*-\s*(\d*)\s*$/', trim( $range ), $matched ) ) {
		return false;
	}
	[ , $first, $last ] = $matched;
	if ( '' === $first && '' === $last ) {
		return false;
	}
	$size = (int) filesize( $file );
	if ( '' === $first ) {
		// `bytes=-N`: the last N bytes.
		$start = max( 0, $size - (int) $last );
		$end   = $size - 1;
	} else {
		$start = (int) $first;
		$end   = '' === $last ? $size - 1 : min( (int) $last, $size - 1 );
	}
	if ( $start > $end || $start >= $size ) {
		header( 'Content-Range: bytes */' . $size, true, 416 );
		return true;
	}
	$length = $end - $start + 1;
	$type   = mime_content_type( $file );
	header( 'Content-Type: ' . ( false === $type ? 'application/octet-stream' : $type ) );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	header( 'Content-Length: ' . $length );
	http_response_code( 206 );
	$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a static file, not a WordPress filesystem operation.
	if ( false === $handle ) {
		// The headers already went out; nothing more can be done cleanly.
		return true;
	}
	fseek( $handle, $start ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- streaming a static file.
	while ( $length > 0 && ! feof( $handle ) ) {
		$chunk = (int) min( 8192, $length );
		echo fread( $handle, $chunk ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- raw bytes of a static file, streamed.
		$length -= $chunk;
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a static file.
	return true;
}
