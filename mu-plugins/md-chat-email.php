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
 * Validate and normalise a visitor page URL from the chat widget.
 *
 * Only http/https URLs on the site's own hosts (or local loopback during
 * development) are accepted. Protocol-relative URLs stored by LHC are
 * promoted to https. Anything else answers ''.
 *
 * @param mixed $value The submitted URL candidate.
 * @return string The validated URL, '' when rejected.
 */
function md_chat_email_sanitize_page_url( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$candidate = trim( $value );
	if ( str_starts_with( $candidate, '//' ) ) {
		$candidate = 'https:' . $candidate;
	}
	if ( '' === $candidate || strlen( $candidate ) > 500 || 1 === preg_match( '/[\r\n\0]/', $candidate ) ) {
		return '';
	}
	$parts = wp_parse_url( $candidate );
	if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}
	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return '';
	}
	$host    = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$allowed = array(
		'metal-divider.com',
		'www.metal-divider.com',
		'staging.metal-divider.com',
		'127.0.0.1',
		'localhost',
	);
	if ( ! in_array( $host, $allowed, true ) ) {
		return '';
	}
	return $candidate;
}

/**
 * Extract the pattern slug when a URL points at a pattern detail page.
 *
 * @param string $url The validated page URL.
 * @return string The pattern slug, '' when the URL is not a pattern page.
 */
function md_chat_email_pattern_slug( string $url ): string {
	if ( '' === $url ) {
		return '';
	}
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! is_string( $path ) || 1 !== preg_match( '~^/(?:pattern|product)/([a-z0-9-]+)(?:/|$)~i', $path, $matches ) ) {
		return '';
	}
	return strtolower( $matches[1] );
}

/**
 * Fetch a compact plain-text transcript of the chat's messages from lh_msg.
 *
 * @param int $chat_id The chat id.
 * @return string The formatted transcript, '' when no messages exist.
 */
function md_chat_email_transcript( int $chat_id ): string {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT user_id, msg FROM lh_msg WHERE chat_id = %d ORDER BY id ASC LIMIT 20',
			$chat_id
		)
	);
	if ( ! is_array( $rows ) ) {
		return '';
	}
	$lines = array();
	foreach ( $rows as $item ) {
		if ( ! is_object( $item ) || ! isset( $item->msg ) || ! is_string( $item->msg ) ) {
			continue;
		}
		$text = trim( wp_strip_all_tags( $item->msg, true ) );
		if ( '' === $text ) {
			continue;
		}
		$user_id = isset( $item->user_id ) ? (int) $item->user_id : 0;
		if ( -1 === $user_id ) {
			continue;
		}
		$speaker = 0 === $user_id ? 'Visitor' : ( -2 === $user_id ? 'Assistant' : 'Operator' );
		$lines[] = $speaker . ': ' . $text;
	}
	return implode( "\n", $lines );
}

/**
 * Extract the latest visitor question from a formatted chat transcript.
 *
 * @param string $transcript The transcript from md_chat_email_transcript().
 * @return string The latest visitor line without its prefix, '' when none.
 */
function md_chat_email_latest_question( string $transcript ): string {
	if ( '' === $transcript ) {
		return '';
	}
	$lines = array_reverse( explode( "\n", $transcript ) );
	foreach ( $lines as $line ) {
		if ( str_starts_with( $line, 'Visitor: ' ) ) {
			return trim( substr( $line, strlen( 'Visitor: ' ) ) );
		}
	}
	return '';
}

/**
 * Build a fully qualified class name inside the MetalDivider\Inquiry plugin.
 *
 * @param string $suffix Class name relative to MetalDivider\Inquiry.
 * @return string
 */
function md_chat_email_inquiry_class( string $suffix ): string {
	return 'MetalDivider\\Inquiry\\' . $suffix;
}

/**
 * Record the chat email lead as an md_inquiry post and enqueue its mails
 * when the md-inquiry plugin is active on this WordPress instance.
 *
 * @param int    $chat_id    The LHC chat ID.
 * @param string $email      The validated visitor email.
 * @param object $row        The lh_chat row.
 * @param string $page_url   The validated page URL from the request, if any.
 * @param string $transcript The formatted chat transcript.
 * @return int|null The created md_inquiry post ID, or null when skipped/failed.
 */
function md_chat_email_record_inquiry( int $chat_id, string $email, object $row, string $page_url, string $transcript ): ?int {
	$post_type_class = md_chat_email_inquiry_class( 'PostType' );
	if ( ! class_exists( $post_type_class ) ) {
		return null;
	}

	$stored_referrer = isset( $row->referrer ) && is_string( $row->referrer ) ? md_chat_email_sanitize_page_url( $row->referrer ) : '';
	$effective_url   = '' !== $page_url ? $page_url : $stored_referrer;
	$pattern_slug    = md_chat_email_pattern_slug( $effective_url );
	$pattern         = null;

	$pattern_class = md_chat_email_inquiry_class( 'Pattern' );
	if ( '' !== $pattern_slug && class_exists( $pattern_class ) ) {
		$resolver   = new $pattern_class();
		$resolve_fn = array( $resolver, 'resolve' );
		if ( is_callable( $resolve_fn ) ) {
			$resolved = $resolve_fn( $pattern_slug );
			if ( is_array( $resolved ) ) {
				$pattern = $resolved;
			}
		}
	}

	$submitted_time = gmdate( 'Y-m-d H:i:s' );
	$post_title     = $email . ' — AI Chat #' . $chat_id . ' — ' . gmdate( 'Y-m-d' );

	global $wpdb;
	$wpdb_vars      = get_object_vars( $wpdb );
	$posts_table    = isset( $wpdb_vars['posts'] ) && is_string( $wpdb_vars['posts'] ) ? $wpdb_vars['posts'] : $wpdb->prefix . 'posts';
	$postmeta_table = isset( $wpdb_vars['postmeta'] ) && is_string( $wpdb_vars['postmeta'] ) ? $wpdb_vars['postmeta'] : $wpdb->prefix . 'postmeta';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb; lookup keeps inquiry creation idempotent per chat_id.
	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$posts_table} p INNER JOIN {$postmeta_table} pm ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = '_md_chat_id' AND pm.meta_value = %s LIMIT 1",
			'md_inquiry',
			(string) $chat_id
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$is_existing = $existing_id > 0;
	if ( $is_existing ) {
		$post_id = $existing_id;
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $post_title,
			)
		);
	} else {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'md_inquiry',
				'post_status' => 'md_new',
				'post_title'  => $post_title,
			),
			true
		);
		if ( is_wp_error( $post_id ) || $post_id < 1 ) {
			return null;
		}
		update_post_meta( $post_id, '_md_chat_id', (string) $chat_id );
	}

	$nick  = isset( $row->nick ) && is_string( $row->nick ) ? trim( $row->nick ) : '';
	$phone = isset( $row->phone ) && is_string( $row->phone ) ? trim( $row->phone ) : '';
	$name  = '' !== $nick && 'Visitor' !== $nick ? $nick : $email;

	$fields = array(
		'submitted_time'   => $submitted_time,
		'customer_name'    => $name,
		'customer_email'   => $email,
		'customer_company' => '',
		'customer_phone'   => $phone,
		'customer_country' => '',
		'project_scope'    => 'AI Chat Follow-up',
		'inquiry_route'    => 'AI Chat',
		'project_quantity' => '',
		'message'          => $transcript,
		'pattern_product'  => is_array( $pattern ) ? ( $pattern['post_id'] ?? '' ) : '',
		'pattern_name'     => is_array( $pattern ) ? (string) ( $pattern['name'] ?? '' ) : '',
		'pattern_code'     => is_array( $pattern ) ? (string) ( $pattern['code'] ?? '' ) : '',
		'pattern_slug'     => is_array( $pattern ) ? $pattern_slug : '',
		'source_page'      => $effective_url,
		'source_module'    => 'mdvisitor',
		'source_button'    => 'chat-email',
		'ip_address'       => md_chat_email_client_ip(),
		'user_agent'       => '',
		'turnstile_status' => 'chat_capability',
	);

	$field_keys   = array();
	$fields_class = md_chat_email_inquiry_class( 'Fields\\FieldGroup' );
	$keys_fn      = array( $fields_class, 'field_keys' );
	if ( class_exists( $fields_class ) && is_callable( $keys_fn ) ) {
		$resolved_keys = $keys_fn();
		if ( is_array( $resolved_keys ) ) {
			$field_keys = $resolved_keys;
		}
	}

	foreach ( $fields as $meta_key => $meta_value ) {
		update_post_meta( $post_id, $meta_key, $meta_value );
		if ( isset( $field_keys[ $meta_key ] ) && is_string( $field_keys[ $meta_key ] ) ) {
			update_post_meta( $post_id, '_' . $meta_key, $field_keys[ $meta_key ] );
		}
	}

	if ( $is_existing ) {
		return $post_id;
	}

	$config_class = md_chat_email_inquiry_class( 'Config' );
	$emails_class = md_chat_email_inquiry_class( 'Mail\\Emails' );
	$queue_class  = md_chat_email_inquiry_class( 'Mail\\Queue' );
	if ( class_exists( $config_class ) && class_exists( $emails_class ) && class_exists( $queue_class ) ) {
		$edit_url    = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$mail_record = array(
			'name'           => $name,
			'email'          => $email,
			'company'        => '',
			'phone'          => $phone,
			'country'        => '',
			'scope'          => 'AI Chat Follow-up',
			'route'          => 'AI Chat',
			'quantity'       => '',
			'message'        => $transcript,
			'pattern_name'   => is_array( $pattern ) ? (string) ( $pattern['name'] ?? '' ) : '',
			'pattern_code'   => is_array( $pattern ) ? (string) ( $pattern['code'] ?? '' ) : '',
			'pattern_url'    => is_array( $pattern ) ? (string) ( $pattern['url'] ?? '' ) : '',
			'source_page'    => $effective_url,
			'source_module'  => 'mdvisitor',
			'source_button'  => 'chat-email',
			'verification'   => 'chat_capability',
			'submitted_time' => $submitted_time,
			'edit_url'       => $edit_url,
		);

		$config      = new $config_class();
		$emails      = new $emails_class( $config );
		$queue       = new $queue_class( $config );
		$internal_fn = array( $emails, 'internal' );
		$confirm_fn  = array( $emails, 'confirmation' );
		$enqueue_fn  = array( $queue, 'enqueue' );
		if ( is_callable( $internal_fn ) && is_callable( $confirm_fn ) && is_callable( $enqueue_fn ) ) {
			$enqueue_fn( $post_id, 'internal_notification', $internal_fn( $mail_record ) );
			$enqueue_fn( $post_id, 'customer_confirmation', $confirm_fn( $mail_record ) );
		}
	}

	return $post_id;
}

/**
 * Register the guest email and context routes.
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
	register_rest_route(
		'md/v1',
		'/chat/context',
		array(
			'methods'             => 'POST',
			'callback'            => 'md_chat_context_respond',
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
	$body     = (array) $request->get_json_params();
	$chat_id  = isset( $body['chat_id'] ) ? (int) $body['chat_id'] : 0;
	$hash     = isset( $body['hash'] ) ? $body['hash'] : '';
	$email    = md_chat_email_normalize( $body['email'] ?? '' );
	$page_url = md_chat_email_sanitize_page_url( $body['page_url'] ?? '' );

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
			'SELECT id, hash, status, anonymized, referrer, nick, phone FROM lh_chat WHERE id = %d',
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

	$data    = '' !== $page_url ? array(
		'email'    => $email,
		'referrer' => $page_url,
	) : array( 'email' => $email );
	$format  = '' !== $page_url ? array( '%s', '%s' ) : array( '%s' );
	$updated = $wpdb->update(
		'lh_chat',
		$data,
		array( 'id' => $chat_id ),
		$format,
		array( '%d' )
	);

	if ( false === $updated ) {
		return new \WP_REST_Response(
			array( 'message' => 'Could not save the email right now. Please try again.' ),
			500
		);
	}

	set_transient( $rate_key, 1, 60 );

	$transcript      = md_chat_email_transcript( $chat_id );
	$stored_referrer = isset( $row->referrer ) && is_string( $row->referrer ) ? md_chat_email_sanitize_page_url( $row->referrer ) : '';
	$effective_url   = '' !== $page_url ? $page_url : $stored_referrer;

	md_chat_email_record_inquiry( $chat_id, $email, $row, $page_url, $transcript );

	if ( function_exists( 'md_wecom_notify_visitor_email' ) ) {
		md_wecom_notify_visitor_email( $chat_id, $email, $effective_url, md_chat_email_latest_question( $transcript ) );
	}

	return new \WP_REST_Response(
		array(
			'ok'          => true,
			'email_saved' => true,
		),
		200
	);
}

/**
 * Update the current page URL (referrer) on the chat that owns the submitted hash.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response
 */
function md_chat_context_respond( $request ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the route callback name matches the /chat/context REST path.
	$body     = (array) $request->get_json_params();
	$chat_id  = isset( $body['chat_id'] ) ? (int) $body['chat_id'] : 0;
	$hash     = isset( $body['hash'] ) ? $body['hash'] : '';
	$page_url = md_chat_email_sanitize_page_url( $body['page_url'] ?? '' );

	if ( $chat_id < 1 ) {
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			400
		);
	}

	if ( '' === $page_url ) {
		return new \WP_REST_Response(
			array( 'message' => 'Please provide a valid page URL.' ),
			400
		);
	}

	if ( md_chat_email_rate_limited() ) {
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
		array( 'referrer' => $page_url ),
		array( 'id' => $chat_id ),
		array( '%s' ),
		array( '%d' )
	);

	if ( false === $updated ) {
		return new \WP_REST_Response(
			array( 'message' => 'Could not update the chat context right now. Please try again.' ),
			500
		);
	}

	return new \WP_REST_Response(
		array(
			'ok'             => true,
			'referrer_saved' => true,
		),
		200
	);
}
