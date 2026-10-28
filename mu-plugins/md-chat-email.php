<?php
/**
 * Optional guest email for the visitor chat.
 *
 * The inquiry site has no accounts. A visitor can leave one email address
 * after the assistant has answered, and the desk sees it on the chat. The
 * chat hash is the capability: only the browser that owns the chat can set
 * the address.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

add_action( 'rest_api_init', 'md_chat_email_register' );

/**
 * Normalise an email candidate without deciding whether it is valid.
 *
 * @param mixed $value The submitted value.
 * @return string The trimmed string, '' when the value is not a string.
 */
function md_chat_email_normalize( $value ): string {
	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Whether the value is one address the site is willing to store.
 *
 * @param mixed $email The normalised value.
 * @return bool
 */
function md_chat_email_valid( $email ): bool {
	if ( ! is_string( $email ) || '' === $email || strlen( $email ) > 100 ) {
		return false;
	}
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

/**
 * Whether a submitted chat hash is the stored chat hash.
 *
 * @param mixed $submitted The hash from the request.
 * @param mixed $stored The hash in lh_chat.
 * @return bool
 */
function md_chat_email_hash_ok( $submitted, $stored ): bool {
	if ( ! is_string( $submitted ) || ! is_string( $stored ) ) {
		return false;
	}
	if ( strlen( $stored ) !== 40 || 1 !== preg_match( '/^[A-Za-z0-9]{40}$/', $submitted ) ) {
		return false;
	}
	return hash_equals( $stored, $submitted );
}

/**
 * The visitor's address, as the transport reports it: REMOTE_ADDR, never a
 * forwarded header — the same rule and the same reason as the kb endpoint's
 * gate (md_kb_endpoint_client_ip): a rate limiter that read a
 * visitor-supplied header would let an attacker choose whose window to
 * fill, or dodge their own.
 *
 * @return string The address, '' when the transport reports none.
 */
function md_chat_email_client_ip(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR has no slashes to strip, and FILTER_VALIDATE_IP is the whole validation: an address that fails it is not an address.
	$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
}

/**
 * The store key of one address's failure window: a digest of the address
 * keyed by the site's salt, so the counter never becomes a log of who
 * asked — the same shape as md_kb_endpoint_failure_key, keyed by the site
 * secret instead of a bearer this endpoint has none of.
 *
 * @return string The transient name.
 */
function md_chat_email_failure_key(): string {
	return 'md_chat_email_failures_' . hash_hmac( 'sha256', md_chat_email_client_ip(), wp_salt( 'auth' ) );
}

/**
 * The address's failed guesses still inside the sliding minute: ints of
 * the timestamps they happened at, older ones dropped — the same window
 * md_kb_endpoint_refuse_auth_failure prunes by.
 *
 * @return array<int, int> The failure timestamps, ascending.
 */
function md_chat_email_recent_failures(): array {
	$now = time();
	return array_values(
		array_filter(
			(array) get_transient( md_chat_email_failure_key() ),
			static fn ( mixed $at ): bool => is_int( $at ) && $at > $now - 60
		)
	);
}

/**
 * Whether the address has spent its ten guesses for this minute: a wrong
 * chat id or a wrong hash is a guess at the capability, and ten of them
 * lock the eleventh out before the chat row is even read.
 *
 * @return bool True when the address is locked out.
 */
function md_chat_email_rate_limited(): bool {
	return count( md_chat_email_recent_failures() ) >= 10;
}

/**
 * Record one failed guess in the address's sliding minute.
 *
 * @return void
 */
function md_chat_email_record_failure(): void {
	$failures   = md_chat_email_recent_failures();
	$failures[] = time();
	set_transient( md_chat_email_failure_key(), $failures, 60 );
}

/**
 * Register the guest email route.
 *
 * @return void
 */
function md_chat_email_register(): void {
	register_rest_route(
		'md/v1',
		'/chat/email',
		array(
			'methods'             => 'POST',
			'callback'            => 'md_chat_email_respond',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Store an optional email on the chat that owns the submitted hash.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response
 */
function md_chat_email_respond( $request ) {
	$body    = (array) $request->get_json_params();
	$chat_id = isset( $body['chat_id'] ) ? (int) $body['chat_id'] : 0;
	$hash    = isset( $body['hash'] ) ? $body['hash'] : '';
	$email   = md_chat_email_normalize( $body['email'] ?? '' );

	if ( $chat_id < 1 ) {
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			400
		);
	}

	if ( ! md_chat_email_valid( $email ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'Please enter a valid email address.' ),
			400
		);
	}

	// The brute-force gate comes before any chat row is read: a wrong id
	// and a wrong hash are both guesses at the capability, and this
	// endpoint's 404 answers them without a hash comparison.
	if ( md_chat_email_rate_limited() ) {
		return new \WP_REST_Response(
			array( 'message' => 'Too many attempts. Please try again in a minute.' ),
			429
		);
	}

	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key    = 'md_chat_email_' . md5( $remote_addr . '|' . $chat_id );
	if ( false !== get_transient( $rate_key ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'Too many attempts. Please try again in a minute.' ),
			429
		);
	}

	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, hash, status, anonymized FROM lh_chat WHERE id = %d',
			$chat_id
		)
	);

	if ( ! $row ) {
		md_chat_email_record_failure();
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			404
		);
	}

	if ( ! md_chat_email_hash_ok( $hash, (string) $row->hash ) ) {
		md_chat_email_record_failure();
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			403
		);
	}

	if ( 2 === (int) $row->status || 1 === (int) $row->anonymized ) {
		return new \WP_REST_Response(
			array( 'message' => 'This conversation is closed and can no longer be updated.' ),
			409
		);
	}

	$updated = $wpdb->update(
		'lh_chat',
		array( 'email' => $email ),
		array( 'id' => $chat_id ),
		array( '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		return new \WP_REST_Response(
			array( 'message' => 'Could not save the email right now. Please try again.' ),
			500
		);
	}

	set_transient( $rate_key, 1, 60 );

	return new \WP_REST_Response(
		array(
			'ok'          => true,
			'email_saved' => true,
		),
		200
	);
}
