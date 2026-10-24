<?php
/**
 * The runtime doubles of the WordPress functions 18-wordpress.php touches on
 * the media/write path: every function records into MD_Migrate_Fakes
 * (tests/php/Support/fakes.php) and the postmeta pair is a real store, so a
 * test can both assert the calls and read back what the driver wrote.
 * Functions are defined only for the media/write code path on purpose: a
 * call to anything not defined here is an Error that fails the test, which
 * is the guard that keeps the remote branch from reaching for WordPress's
 * local-import machinery.
 *
 * PHPStan never loads this file (phpstan.neon.dist excludes it): it knows
 * these names from the WordPress stubs.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- WordPress stand-ins with WordPress's own names.

// The output-mode constant the driver's read path passes wpdb.
define( 'ARRAY_A', 'ARRAY_A' );

// The runtime base of the product double: the driver guards its product
// with instanceof WC_Product, so the double must sit under that name (at
// analysis time the real shape comes from woocommerce-stubs.php).
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {}
}

/**
 * Records the filter hooking (the driver pins its two no-subsize filters at
 * load time).
 *
 * @param string                $hook     The hook's name.
 * @param string|callable|array $callback What runs on it (the driver passes
 *                                        function-name strings like
 *                                        __return_empty_array).
 * @param int                   $priority Where in the queue.
 * @param int                   $args     How many arguments the callback takes.
 * @return true
 */
function add_filter( string $hook, string|callable|array $callback, int $priority = 10, int $args = 1 ): bool {
	MD_Migrate_Fakes::called( 'add_filter', $hook, $priority );
	return true;
}

/**
 * The escaping $fail wraps its message with.
 *
 * @param string $text The raw message.
 * @return string The escaped message.
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

/**
 * The uploads directory of this test.
 *
 * @return array<string, mixed> The basedir/path shape WordPress answers with.
 */
function wp_upload_dir(): array {
	return array(
		'basedir' => MD_Migrate_Fakes::$upload_basedir,
		'baseurl' => 'https://example.test/uploads',
		'path'    => MD_Migrate_Fakes::$upload_basedir . '/2026/10',
		'url'     => 'https://example.test/uploads/2026/10',
		'subdir'  => '/2026/10',
		'error'   => false,
	);
}

/**
 * The type check before wp_insert_attachment, by file extension.
 *
 * @param string            $filename The file whose type is asked about.
 * @param array<int, mixed> $mimes   Unused: the doubles only see this driver's extensions.
 * @return array<string, string> The ext/type pair.
 */
function wp_check_filetype( string $filename, array $mimes = array() ): array {
	$extension = pathinfo( $filename, PATHINFO_EXTENSION );
	$types     = array(
		'webp' => 'image/webp',
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'mp4'  => 'video/mp4',
	);
	return array(
		'ext'  => $extension,
		'type' => $types[ $extension ] ?? 'application/octet-stream',
	);
}

/**
 * The attachment insert, recorded; the doubles hand out the configured id.
 *
 * @param array<string, mixed> $args     The attachment post arguments.
 * @param string               $file     The file it binds.
 * @param int                  $parent   The parent post.
 * @param bool                 $wp_error Unused here: the double never errors.
 * @return int The configured attachment id.
 */
function wp_insert_attachment( array $args, string $file, int $parent = 0, bool $wp_error = false ): int {
	MD_Migrate_Fakes::called( 'wp_insert_attachment', $args, $file );
	return MD_Migrate_Fakes::$attachment_id;
}

/**
 * No error object is ever handed out.
 *
 * @param mixed $value The value in question.
 * @return bool Always false.
 */
function is_wp_error( mixed $value ): bool {
	return false;
}

/**
 * The metadata generation, recorded only.
 *
 * @param int    $post_id The attachment id.
 * @param string $file    The file it binds.
 * @return array<string, mixed> A minimal metadata array.
 */
function wp_generate_attachment_metadata( int $post_id, string $file ): array {
	MD_Migrate_Fakes::called( 'wp_generate_attachment_metadata', $post_id, $file );
	return array( 'file' => wp_basename( $file ), 'sizes' => array() );
}

/**
 * The metadata write, recorded only.
 *
 * @param int                  $post_id  The attachment id.
 * @param array<string, mixed> $metadata The generated metadata.
 * @return bool Always true.
 */
function wp_update_attachment_metadata( int $post_id, array $metadata ): bool {
	MD_Migrate_Fakes::called( 'wp_update_attachment_metadata', $post_id );
	return true;
}

/**
 * The name sanitizer: the fixture names are already sane, so it passes them
 * through unchanged.
 *
 * @param string $name The raw name.
 * @return string The name as-is.
 */
function sanitize_file_name( string $name ): string {
	return $name;
}

/**
 * WordPress's basename: the segment after the last slash of either flavour.
 *
 * @param string $path   The path.
 * @param string $suffix A suffix to strip (unused by this driver).
 * @return string The basename.
 */
function wp_basename( string $path, string $suffix = '' ): string {
	$path     = str_replace( '\\', '/', $path );
	$position = strrpos( $path, '/' );
	$base     = false === $position ? $path : substr( $path, $position + 1 );
	return '' === $suffix ? $base : (string) preg_replace( '/' . preg_quote( $suffix, '/' ) . '$/', '', $base );
}

/**
 * The slashing wrapper: the store keeps values exactly as given.
 *
 * @param mixed $value The value.
 * @return mixed The value unchanged.
 */
function wp_slash( mixed $value ): mixed {
	return $value;
}

/**
 * The JSON encoder of the marker answer and the variants meta.
 *
 * @param mixed $value The value to encode.
 * @return string The JSON, '' on failure.
 */
function wp_json_encode( mixed $value ): string {
	$encoded = json_encode( $value );
	return false === $encoded ? '' : $encoded;
}

/**
 * The postmeta write, into the store.
 *
 * @param int    $post_id The post id.
 * @param string $key     The meta key.
 * @param mixed  $value   The value.
 * @return bool Always true.
 */
function update_post_meta( int $post_id, string $key, mixed $value ): bool {
	MD_Migrate_Fakes::$meta[ $post_id ][ $key ] = is_string( $value ) ? $value : (string) $value;
	return true;
}

/**
 * The postmeta read, from the store.
 *
 * @param int    $post_id The post id.
 * @param string $key     The meta key.
 * @param bool   $single  Unused: the driver always asks single.
 * @return string The stored value, '' when absent.
 */
function get_post_meta( int $post_id, string $key, bool $single = false ): string {
	return MD_Migrate_Fakes::meta( $post_id, $key );
}

/**
 * The directory maker of the local branch: recorded, the test pre-creates
 * the directory (a remote-branch call would surface here and fail asserts).
 *
 * @param string $dir The directory.
 * @return bool Always true.
 */
function wp_mkdir_p( string $dir ): bool {
	MD_Migrate_Fakes::called( 'wp_mkdir_p', $dir );
	return true;
}

/**
 * The collision uniquifier of the local branch: recorded, fixture names
 * never collide so the name passes through.
 *
 * @param string $dir  The directory.
 * @param string $name The wanted name.
 * @return string The name unchanged.
 */
function wp_unique_filename( string $dir, string $name ): string {
	MD_Migrate_Fakes::called( 'wp_unique_filename', $dir, $name );
	return $name;
}

/**
 * The SKU lookup, from the configured map.
 *
 * @param string $sku The SKU.
 * @return int The product id, 0 when absent.
 */
function wc_get_product_id_by_sku( string $sku ): int {
	return MD_Migrate_Fakes::$product_ids[ $sku ] ?? 0;
}

/**
 * The product getter: the recording product.
 *
 * @param int $id The product id.
 * @return MD_Migrate_Fake_Product The double.
 */
function wc_get_product( int $id ): MD_Migrate_Fake_Product {
	return new MD_Migrate_Fake_Product();
}
