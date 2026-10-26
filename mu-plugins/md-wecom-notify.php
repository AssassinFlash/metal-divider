<?php
/**
 * Plugin Name: MD WeCom notify
 * Description: The LHC transfer connector: POST /wp-json/md/v1/wecom/notify — a bearer-authenticated transfer-to-human event becomes a one-time LHC autologin link and a WeCom textcard to the one configured member (#138, research/lhc-wechat-channel §6's notification tier).
 *
 * A must-use plugin: the connector is a property of the site itself, and it
 * must keep answering while a regular plugin is being activated, updated or
 * broken.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

add_action( 'rest_api_init', 'md_wecom_notify_register' );

/**
 * The connector's settings, one option: the inbound bearer token the LHC
 * webhook presents, the LHC REST credentials the link is generated through,
 * and the WeCom application the card is sent by. The secret values never
 * live in the repository; the environment variables override the option,
 * for local runs and the deployment's secret injection.
 *
 * @param string $key The setting's name: bearer_token, lhc_base_url, lhc_username, lhc_api_key, wecom_corp_id, wecom_secret, wecom_agent_id or wecom_to_user.
 * @return string The setting's value, '' when unset.
 */
function md_wecom_notify_setting( string $key ): string {
	$env_names = array(
		'bearer_token'           => 'MD_WECOM_NOTIFY_TOKEN',
		'lhc_api_key'            => 'MD_WECOM_LHC_API_KEY',
		'wecom_corp_id'          => 'MD_WECOM_CORP_ID',
		'wecom_secret'           => 'MD_WECOM_SECRET',
		'wecom_agent_id'         => 'MD_WECOM_AGENT_ID',
		'wecom_callback_token'   => 'MD_WECOM_CALLBACK_TOKEN',
		'wecom_callback_aes_key' => 'MD_WECOM_CALLBACK_AES_KEY',
	);
	$env       = getenv( $env_names[ $key ] ?? '' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	$options = get_option( 'md_wecom_notify', array() );
	if ( ! is_array( $options ) ) {
		return '';
	}
	$value = $options[ $key ] ?? '';
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Whether the request's Authorization header carries the connector's bearer
 * token: the constant-time comparison, so a token's prefix leaks nothing,
 * and a non-Bearer scheme or a token-less site refuses everything.
 *
 * @param string $header The Authorization header's value, '' when absent.
 * @return bool Whether the request may ask for a notification.
 */
function md_wecom_notify_bearer_ok( string $header ): bool {
	$expected = md_wecom_notify_setting( 'bearer_token' );
	if ( '' === $expected || 1 !== preg_match( '/^Bearer (\S++)$/', $header, $matches ) ) {
		return false;
	}
	return hash_equals( $expected, $matches[1] );
}

/**
 * The notification request, end to end: the bearer gate, the event's
 * validation, the one-time link, the card. The input is what the route
 * adapter receives (the Authorization header and the decoded JSON body)
 * and the output is the status and body it sends — the HTTP contract one
 * WordPress object layer down.
 *
 * @param array<string, mixed> $input The request: 'header' the Authorization header, 'body' the decoded JSON body.
 * @return array{status: int, body: array<string, mixed>} The response's status and body.
 */
function md_wecom_notify_handle( array $input ): array {
	$header = (string) ( $input['header'] ?? '' );
	$body   = is_array( $input['body'] ?? null ) ? $input['body'] : array();

	if ( ! md_wecom_notify_bearer_ok( $header ) ) {
		return array(
			'status' => 401,
			'body'   => array( 'error' => array( 'code' => 'unauthorized' ) ),
		);
	}

	$event_name = $body['event'] ?? null;
	$chat       = $body['chat'] ?? null;
	$chat_id    = is_array( $chat ) ? ( $chat['id'] ?? null ) : null;
	if ( ! is_string( $event_name ) || '' === $event_name || ! is_array( $chat ) || ! is_int( $chat_id ) || $chat_id <= 0 ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	if ( ! in_array( $event_name, array( 'chat.genericbot_chat_command_transfer', 'chat.chat_transfered' ), true ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'sent'   => false,
				'reason' => 'ignored',
			),
		);
	}

	$required = array( 'lhc_base_url', 'lhc_username', 'lhc_api_key', 'wecom_corp_id', 'wecom_secret', 'wecom_agent_id', 'wecom_to_user' );
	foreach ( $required as $key ) {
		if ( '' === md_wecom_notify_setting( $key ) ) {
			return array(
				'status' => 503,
				'body'   => array( 'error' => array( 'code' => 'not_configured' ) ),
			);
		}
	}

	if ( ! md_wecom_notify_lock( $chat_id ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'sent'   => false,
				'reason' => 'busy',
			),
		);
	}

	try {
		$notified = md_wecom_notify_notified_chats();
		if ( array_key_exists( $chat_id, $notified ) ) {
			return array(
				'status' => 200,
				'body'   => array(
					'sent'   => false,
					'reason' => 'duplicate',
				),
			);
		}

		$link = md_wecom_notify_autologin_link( $chat_id );
		if ( null === $link ) {
			return array(
				'status' => 502,
				'body'   => array( 'error' => array( 'code' => 'upstream' ) ),
			);
		}

		$msg_obj = is_array( $body['msg'] ?? null ) ? $body['msg'] : array();

		$sent = md_wecom_notify_send_card( $chat_id, $link, $chat, $msg_obj );
		if ( ! $sent ) {
			return array(
				'status' => 502,
				'body'   => array( 'error' => array( 'code' => 'upstream' ) ),
			);
		}

		md_wecom_notify_remember_chat( $chat_id );
	} finally {
		md_wecom_notify_unlock( $chat_id );
	}

	return array(
		'status' => 200,
		'body'   => array( 'sent' => true ),
	);
}

/**
 * The chat's notification lock, WordPress's own atomic mechanism: an
 * add_option whose name is taken is refused, so of two concurrent first
 * events for one chat only the winner reaches the upstreams — the loser
 * is answered busy, not with a second card. A lock held past its window
 * (a request that died mid-flight) is taken over, the way WordPress's own
 * upgrader lock is.
 *
 * @param int $chat_id The chat to notify.
 * @return bool Whether this request holds the chat's lock.
 */
function md_wecom_notify_lock( int $chat_id ): bool {
	$name = 'md_wecom_notify_lock_' . $chat_id;
	$now  = time();
	if ( add_option( $name, $now, '', false ) ) {
		return true;
	}
	$held = get_option( $name );
	if ( is_numeric( $held ) && (int) $held < $now - 300 ) {
		delete_option( $name );
		return (bool) add_option( $name, $now, '', false );
	}
	return false;
}

/**
 * Releases the chat's lock: it is a hand-off, not a record.
 *
 * @param int $chat_id The chat whose lock is released.
 */
function md_wecom_notify_unlock( int $chat_id ): void {
	delete_option( 'md_wecom_notify_lock_' . $chat_id );
}

/**
 * The chats this site has already notified, pruned to the last seven days
 * in the same read: a record's age, not a cron, ends a chat's silence.
 *
 * @return array<int, int> The chat IDs still inside the window, each with the time it notified.
 */
function md_wecom_notify_notified_chats(): array {
	$stored = get_option( 'md_wecom_notify_chats', array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}
	$horizon = time() - 7 * 24 * 3600;
	$chats   = array();
	foreach ( $stored as $id => $when ) {
		if ( is_numeric( $id ) && is_int( $when ) && $when > $horizon ) {
			$chats[ (int) $id ] = $when;
		}
	}
	return $chats;
}

/**
 * Records a chat as notified, pruning the expired records the same write.
 *
 * @param int $chat_id The chat that just notified.
 */
function md_wecom_notify_remember_chat( int $chat_id ): void {
	$chats             = md_wecom_notify_notified_chats();
	$chats[ $chat_id ] = time();
	update_option( 'md_wecom_notify_chats', $chats );
}

/**
 * The route into the chat's back-office view, the operator screen
 * adminchat: it locks the chat row and takes the operator into the
 * conversation, a bot chat included (modules/lhchat/adminchat.php).
 *
 * @param int $chat_id The chat the link opens.
 * @return string The route the autologin link carries.
 */
function md_wecom_notify_chat_route( int $chat_id ): string {
	return 'chat/adminchat/' . $chat_id;
}

/**
 * The one-time link into the chat's back-office view, from LHC's
 * generateautologin: the route pins the chat, the TTL is the hour the
 * research allows (the API caps it at 3600), and the nonce is the LHC's
 * own — the connector holds no long-term credential in what it sends on.
 * A transport error earns one retry; an answer that is not a link is
 * final on the first attempt.
 *
 * @param int $chat_id The chat the link opens.
 * @return string|null The link, or null when the LHC did not return one.
 */
function md_wecom_notify_autologin_link( int $chat_id ): ?string {
	$base = rtrim( md_wecom_notify_setting( 'lhc_base_url' ), '/' );
	$user = md_wecom_notify_setting( 'lhc_username' );
	if ( '' === $base || '' === $user ) {
		return null;
	}

	$attempts = 0;
	$response = null;
	while ( $attempts < 2 ) {
		++$attempts;
		$response = wp_remote_post(
			$base . '/index.php/restapi/generateautologin',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'  => 'application/json',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth's own encoding of the LHC REST credentials, not obfuscation: it is what erLhcoreClassRestAPIHandler::validateRequest() reads.
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . md_wecom_notify_setting( 'lhc_api_key' ) ),
				),
				'body'    => (string) wp_json_encode(
					array(
						'r' => md_wecom_notify_chat_route( $chat_id ),
						'l' => $user,
						't' => 3600,
					)
				),
			)
		);
		if ( ! is_wp_error( $response ) ) {
			break;
		}
		md_wecom_notify_log_failure( 'lhc_autologin', 'network_error', 0, $attempts );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status ) {
		md_wecom_notify_log_failure( 'lhc_autologin', 'http_error', $status, $attempts );
		return null;
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) || false !== ( $decoded['error'] ?? true ) || ! is_string( $decoded['result'] ?? null ) || '' === $decoded['result'] ) {
		md_wecom_notify_log_failure( 'lhc_autologin', 'bad_reply', $status, $attempts );
		return null;
	}
	return $decoded['result'];
}

/**
 * The log line of one upstream failure: which side, what kind, which HTTP
 * status, how many attempts — and nothing else. No URL, no body, no
 * credential: the WeCom token query carries the secret, and the LHC
 * answer is a visitor's message.
 *
 * @param string $side     The upstream that failed: lhc_autologin, wecom_token or wecom_send.
 * @param string $kind     The failure's kind: network_error, http_error, bad_reply or api_error.
 * @param int    $status   The HTTP status, 0 for a transport error.
 * @param int    $attempts How many attempts were made.
 * @return void
 */
function md_wecom_notify_log_failure( string $side, string $kind, int $status, int $attempts ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the site's own error log channel, recorded the way md-error-recorder configures it; see the docblock for what the line deliberately leaves out.
	error_log( sprintf( '[md-wecom-notify] %s failed after %d attempt(s): %s http=%d', $side, $attempts, $kind, $status ) );
}

/**
 * The textcard to the one configured member, through WeCom's message/send.
 * The send runs at most twice: once on the cached-or-fresh token, and once
 * more — no further — when the API names the token's age (40014, 42001) as
 * the reason, with a fresh token for that second attempt alone. A
 * transport error earns the same single second attempt; an API refusal
 * that is not the token's age is final.
 *
 * @param int                  $chat_id The chat the card's link opens.
 * @param string               $link    The one-time autologin link.
 * @param array<string, mixed> $chat    The event's chat object: nick builds the card's title.
 * @param array<string, mixed> $msg     The event's msg object, empty when the event carries none.
 * @return bool Whether the card was delivered.
 */
function md_wecom_notify_send_card( int $chat_id, string $link, array $chat, array $msg ): bool {
	$to_user = md_wecom_notify_setting( 'wecom_to_user' );
	if ( '' === $to_user ) {
		return false;
	}

	$nick = is_string( $chat['nick'] ?? null ) && '' !== trim( (string) $chat['nick'] ) ? trim( (string) $chat['nick'] ) : 'Visitor';
	$text = is_string( $msg['msg'] ?? null ) && '' !== trim( (string) $msg['msg'] ) ? trim( (string) $msg['msg'] ) : '';

	$payload = array(
		'touser'   => $to_user,
		'msgtype'  => 'textcard',
		'agentid'  => (int) md_wecom_notify_setting( 'wecom_agent_id' ),
		'textcard' => array(
			'title'       => sprintf( 'Live chat #%d — %s', $chat_id, $nick ),
			'description' => mb_substr( $text, 0, 120 ),
			'url'         => $link,
			'btntxt'      => 'Open chat',
		),
	);

	$attempts    = 0;
	$token_stale = false;
	while ( $attempts < 2 ) {
		++$attempts;
		$token = md_wecom_notify_access_token( $token_stale );
		if ( null === $token ) {
			return false;
		}

		$transport_tries = 0;
		$response        = null;
		while ( $transport_tries < 2 ) {
			++$transport_tries;
			$response = wp_remote_post(
				'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . rawurlencode( $token ),
				array(
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => (string) wp_json_encode( $payload ),
				)
			);
			if ( ! is_wp_error( $response ) ) {
				break;
			}
			md_wecom_notify_log_failure( 'wecom_send', 'network_error', 0, $transport_tries );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			md_wecom_notify_log_failure( 'wecom_send', 'http_error', $status, $attempts );
			return false;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			md_wecom_notify_log_failure( 'wecom_send', 'bad_reply', $status, $attempts );
			return false;
		}
		$errcode = (int) ( $decoded['errcode'] ?? 1 );
		if ( 0 === $errcode ) {
			return true;
		}
		if ( in_array( $errcode, array( 40014, 42001 ), true ) && ! $token_stale ) {
			$token_stale = true;
			continue;
		}
		md_wecom_notify_log_failure( 'wecom_send', 'api_error', $status, $attempts );
		return false;
	}

	return false;
}

/**
 * The application's access_token, cached for the hour WeCom grants it: the
 * transient holds the token with its expiry, and a missing, expired or
 * forcibly invalidated cache is refreshed from gettoken, whose own
 * transport error earns one retry.
 *
 * @param bool $fresh Whether the cache is to be ignored, as a 40014/42001 send makes it.
 * @return string|null The token, or null when WeCom did not return one.
 */
function md_wecom_notify_access_token( bool $fresh = false ): ?string {
	if ( ! $fresh ) {
		$cache = get_transient( 'md_wecom_notify_token' );
		if ( is_array( $cache ) && is_string( $cache['token'] ?? null ) && '' !== $cache['token'] && (int) ( $cache['expires'] ?? 0 ) > time() ) {
			return $cache['token'];
		}
	}

	$corp_id = md_wecom_notify_setting( 'wecom_corp_id' );
	$secret  = md_wecom_notify_setting( 'wecom_secret' );
	if ( '' === $corp_id || '' === $secret ) {
		return null;
	}

	$attempts = 0;
	$response = null;
	while ( $attempts < 2 ) {
		++$attempts;
		$response = wp_remote_get(
			'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=' . rawurlencode( $corp_id ) . '&corpsecret=' . rawurlencode( $secret ),
			array( 'timeout' => 10 )
		);
		if ( ! is_wp_error( $response ) ) {
			break;
		}
		md_wecom_notify_log_failure( 'wecom_token', 'network_error', 0, $attempts );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status ) {
		md_wecom_notify_log_failure( 'wecom_token', 'http_error', $status, $attempts );
		return null;
	}
	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) || 0 !== (int) ( $decoded['errcode'] ?? 1 ) || ! is_string( $decoded['access_token'] ?? null ) || '' === $decoded['access_token'] ) {
		md_wecom_notify_log_failure( 'wecom_token', 'api_error', $status, $attempts );
		return null;
	}

	$token   = $decoded['access_token'];
	$expires = time() + max( 60, (int) ( $decoded['expires_in'] ?? 7200 ) - 300 );
	set_transient(
		'md_wecom_notify_token',
		array(
			'token'   => $token,
			'expires' => $expires,
		),
		$expires
	);

	return $token;
}

/**
 * WeCom's URL ownership verification, without receiving any messages.
 *
 * @param array<string, mixed> $query The verification query parameters.
 * @return array{status: int, body: string|array<string, mixed>} The raw echo or an error.
 */
function md_wecom_notify_callback_handle( array $query ): array {
	$token   = md_wecom_notify_setting( 'wecom_callback_token' );
	$aes_key = md_wecom_notify_setting( 'wecom_callback_aes_key' );
	$corp_id = md_wecom_notify_setting( 'wecom_corp_id' );
	if ( '' === $token || '' === $aes_key || '' === $corp_id ) {
		return array(
			'status' => 503,
			'body'   => array( 'error' => array( 'code' => 'not_configured' ) ),
		);
	}

	$fields = array( 'msg_signature', 'timestamp', 'nonce', 'echostr' );
	foreach ( $fields as $field ) {
		if ( ! is_scalar( $query[ $field ] ?? null ) || '' === (string) $query[ $field ] ) {
			return array(
				'status' => 400,
				'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
			);
		}
	}

	$signature = (string) $query['msg_signature'];
	$echostr   = (string) $query['echostr'];
	$parts     = array( $token, (string) $query['timestamp'], (string) $query['nonce'], $echostr );
	sort( $parts, SORT_STRING );
	if ( ! hash_equals( hash( 'sha1', implode( '', $parts ) ), $signature ) ) {
		return array(
			'status' => 401,
			'body'   => array( 'error' => array( 'code' => 'unauthorized' ) ),
		);
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WeCom's documented EncodingAESKey is base64, not code.
	$key = base64_decode( $aes_key, true );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- This decodes WeCom's verification payload, not code.
	$ciphertext = base64_decode( strtr( $echostr, '-_', '+/' ), true );
	if ( false === $key || 32 !== strlen( $key ) || false === $ciphertext ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $key, 0, 16 ) );
	if ( false === $plain || strlen( $plain ) < 36 ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	$unpacked = unpack( 'Nlength', substr( $plain, 16, 4 ) );
	$length   = is_array( $unpacked ) ? (int) $unpacked['length'] : 0;
	$message  = substr( $plain, 20, $length );
	if ( 0 === $length || strlen( $plain ) !== 20 + $length + strlen( $corp_id ) || ! hash_equals( $corp_id, substr( $plain, 20 + $length ) ) ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	return array(
		'status' => 200,
		'body'   => $message,
	);
}

/**
 * The route adapter: what WordPress's REST objects become for the handler —
 * the Authorization header and the decoded JSON body in, the status and
 * body out as a REST response.
 *
 * @param \WP_REST_Request $request The request the webhook sent.
 * @return \WP_REST_Response The handler's answer, as REST shapes it.
 */
function md_wecom_notify_rest( $request ) {
	$result = md_wecom_notify_handle(
		array(
			'header' => (string) $request->get_header( 'Authorization' ),
			'body'   => $request->get_json_params(),
		)
	);
	return new \WP_REST_Response( $result['body'], $result['status'] );
}

/**
 * Answers WeCom's GET ownership check with the raw echo it expects.
 *
 * @param \WP_REST_Request $request The verification request.
 */
function md_wecom_notify_callback_rest( $request ): void {
	$result = md_wecom_notify_callback_handle( $request->get_query_params() );
	if ( ! is_string( $result['body'] ) ) {
		wp_send_json( $result['body'], $result['status'] );
	}
	status_header( $result['status'] );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo esc_html( $result['body'] );
	exit;
}

/**
 * The route itself: the LHC webhook posts its transfer events here. The
 * permission is the bearer token inside the handler — the route's own gate
 * stays open so the 401 is the connector's answer, not REST's.
 */
function md_wecom_notify_register(): void {
	register_rest_route(
		'md/v1',
		'/wecom/notify',
		array(
			'methods'             => 'POST',
			'callback'            => 'md_wecom_notify_rest',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'md/v1',
		'/wecom/callback',
		array(
			'methods'             => 'GET',
			'callback'            => 'md_wecom_notify_callback_rest',
			'permission_callback' => '__return_true',
		)
	);
}
