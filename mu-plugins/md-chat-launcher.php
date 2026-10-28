<?php
/**
 * Site launcher for the visitor chat.
 *
 * Enqueues one script on staging site pages; the script itself renders the
 * Ask Metal Divider launcher and loads the widget card from the chat
 * extension assets. The plugin adds no HTML beyond that tag and no CSS to
 * site pages — every visual belongs to the chat surface, served from
 * /chat/extension/mdvisitor/, exactly like the widget iframe it opens.
 *
 * Staging only: the host gate keeps production clean until its own cutover
 * ticket switches the chat base on.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

const MD_CHAT_LAUNCHER_VERSION = '20261028e';

add_action( 'wp_enqueue_scripts', 'md_chat_launcher_enqueue' );

/**
 * Whether the launcher tag belongs on this response.
 *
 * @param string $host The request host, lowercased.
 * @param string $path The request path, starting with a slash.
 * @param bool   $is_admin Whether the request is an admin screen.
 * @param bool   $is_feed Whether the request is a feed.
 * @return bool True when the tag should print.
 */
function md_chat_launcher_should_print( string $host, string $path, bool $is_admin, bool $is_feed ): bool {
	if ( $is_admin || $is_feed ) {
		return false;
	}
	if ( 0 === strpos( $path, '/chat/' ) ) {
		return false;
	}
	if ( 0 === strpos( $path, '/wp-login.php' ) || 0 === strpos( $path, '/wp-admin/' ) ) {
		return false;
	}

	/**
	 * The hosts whose front end carries the launcher. Production stays out
	 * until its cutover ticket opts in.
	 *
	 * @since 1.0.0
	 * @param string[] $hosts Allowlisted hostnames.
	 */
	$hosts = apply_filters(
		'md_chat_launcher_hosts',
		array( 'staging.metal-divider.com', 'localhost' )
	);
	$bare  = strtolower( $host );
	$port  = strrpos( $bare, ':' );
	if ( false !== $port ) {
		$bare = substr( $bare, 0, $port );
	}
	return in_array( $bare, array_map( 'strtolower', $hosts ), true );
}

/**
 * The launcher script path under the chat extension assets.
 *
 * @return string The same-origin path wp_enqueue_script accepts.
 */
function md_chat_launcher_script_url(): string {
	return '/chat/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-launcher.js';
}

/**
 * Enqueue the launcher script on front-end pages that carry it.
 *
 * @return void
 */
function md_chat_launcher_enqueue(): void {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	if ( '' === $path ) {
		$path = '/';
	}
	if ( ! md_chat_launcher_should_print( $host, $path, is_admin(), is_feed() ) ) {
		return;
	}
	wp_enqueue_script(
		'md-chat-launcher',
		md_chat_launcher_script_url(),
		array(),
		MD_CHAT_LAUNCHER_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
