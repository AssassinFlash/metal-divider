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
	return false;
}

chdir( $md_local_root );
require $md_local_root . '/index.php';
