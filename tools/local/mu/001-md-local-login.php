<?php
/**
 * Plugin Name: MD local login
 * Description: One-time login links for a local instance: md_local_login_url() makes one (`md-local login` calls it through `wp eval`), wp-login.php?action=md-local-login uses it up.
 *
 * Copied into wp-content/mu-plugins by md-local (tools/local); never deployed.
 *
 * @package Md\Local
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * A link is good for ten minutes and one use: NIST SP 800-63B-4, "Out-of-Band
 * Verifiers" ("invalid unless completed within 10 minutes", a secret "valid
 * only once").
 */
const MD_LOCAL_LOGIN_TTL = 10 * MINUTE_IN_SECONDS;

add_action( 'login_form_md-local-login', 'md_local_login' );

/**
 * A one-time login link for a user.
 *
 * @param string $login Login name of the user the link signs in.
 * @return string The link, or '' when there is no such user.
 */
function md_local_login_url( string $login ): string {
	$user = get_user_by( 'login', $login );
	if ( false === $user ) {
		return '';
	}
	// 256 random bits, as in WordPress's own session tokens (WP_Session_Tokens::create(),
	// 43 characters of 62); only their hash is stored.
	$token = bin2hex( random_bytes( 32 ) );
	set_transient( md_local_login_key( $token ), $user->ID, MD_LOCAL_LOGIN_TTL );
	return add_query_arg(
		array(
			'action' => 'md-local-login',
			'token'  => $token,
		),
		wp_login_url()
	);
}

/**
 * Signs in the user of a valid link and sends them to the dashboard.
 */
function md_local_login(): void {
	// The token is the one-time secret; there is no session yet for a nonce to belong to.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$token   = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
	$key     = md_local_login_key( $token );
	$user_id = (int) get_transient( $key );
	// Of two requests with the same link only one deletes it, so a link signs in once.
	if ( '' === $token || $user_id <= 0 || ! delete_transient( $key ) ) {
		wp_die( 'This login link is invalid, expired or already used.', 'Login link', array( 'response' => 403 ) );
	}
	wp_set_auth_cookie( $user_id );
	wp_safe_redirect( admin_url() );
	exit;
}

/**
 * Transient name of a token: its SHA-256, so the database never holds a usable token.
 *
 * @param string $token Token from the link.
 * @return string
 */
function md_local_login_key( string $token ): string {
	return 'md_local_login_' . hash( 'sha256', $token );
}
