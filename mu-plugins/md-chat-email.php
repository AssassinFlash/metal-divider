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
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			404
		);
	}

	if ( ! md_chat_email_hash_ok( $hash, (string) $row->hash ) ) {
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
