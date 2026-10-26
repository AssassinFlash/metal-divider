<?php
/**
 * Plugin Name: MD knowledge endpoint
 * Description: The single server-side interface of the AI support MVP: POST /wp-json/md/v1/kb/answer — bearer-authenticated, answered from the Rapls knowledge base through DeepSeek's official non-streaming chat completions, with a explicit transfer-to-operator handoff (docs/research/2026-10-26-lhc-mvp-knowledge.md §4, branch research/lhc-mvp-knowledge).
 *
 * A must-use plugin: the endpoint is a property of the site itself, and it
 * must stay available while a regular plugin is being activated, updated or
 * broken — the knowledge plugin included.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

add_action( 'rest_api_init', 'md_kb_endpoint_register' );

/**
 * The endpoint's settings, one option: the bearer token the LHC bot
 * presents, and the DeepSeek API key it answers through. Neither value ever
 * lives in the repository; the two environment variables override the
 * option, for local runs and the deployment's secret injection.
 *
 * @param string $key The setting's name: bearer_token or deepseek_api_key.
 * @return string The setting's value, '' when unset.
 */
function md_kb_endpoint_setting( string $key ): string {
	$env_names = array(
		'bearer_token'     => 'MD_KB_ENDPOINT_TOKEN',
		'deepseek_api_key' => 'MD_KB_DEEPSEEK_KEY',
	);
	$env       = getenv( $env_names[ $key ] ?? '' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	$options = get_option( 'md_kb_endpoint', array() );
	if ( ! is_array( $options ) ) {
		return '';
	}
	$value = $options[ $key ] ?? '';
	return is_string( $value ) ? $value : '';
}

/**
 * Whether the request's Authorization header carries the site's bearer
 * token: the constant-time comparison, so a token's prefix leaks nothing,
 * and a non-Bearer scheme or a token-less site refuses everything.
 *
 * @param string $header The Authorization header's value, '' when absent.
 * @return bool Whether the request may ask for an answer.
 */
function md_kb_endpoint_bearer_ok( string $header ): bool {
	$expected = md_kb_endpoint_setting( 'bearer_token' );
	if ( '' === $expected || 1 !== preg_match( '/^Bearer (\S++)$/', $header, $matches ) ) {
		return false;
	}
	return hash_equals( $expected, $matches[1] );
}

/**
 * The answer request, end to end: the bearer gate, then the request
 * validation, the knowledge retrieval and the model call. The input is what
 * the route adapter receives (the Authorization header and the decoded JSON
 * body) and the output is the status and body it sends — the HTTP contract
 * one WordPress object layer down.
 *
 * @param array<string, mixed> $input The request: 'header' the Authorization header, 'body' the decoded JSON body.
 * @return array{status: int, body: array<string, mixed>} The response's status and body.
 */
function md_kb_endpoint_answer( array $input ): array {
	$header = (string) ( $input['header'] ?? '' );
	$body   = is_array( $input['body'] ?? null ) ? $input['body'] : array();

	if ( ! md_kb_endpoint_bearer_ok( $header ) ) {
		return array(
			'status' => 401,
			'body'   => array( 'error' => array( 'code' => 'unauthorized' ) ),
		);
	}

	$q = $body['q'] ?? null;
	if ( ! is_string( $q ) || '' === trim( $q ) || mb_strlen( $q ) > 4000 ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	if ( ! class_exists( 'RAPLSAICH_Search_Engine' ) ) {
		return array(
			'status' => 503,
			'body'   => array(
				'error' => array( 'code' => 'kb_unavailable' ),
				'msg'   => md_kb_endpoint_fallback_message(),
			),
		);
	}

	if ( '' === md_kb_endpoint_setting( 'deepseek_api_key' ) ) {
		return array(
			'status' => 503,
			'body'   => array(
				'error' => array( 'code' => 'not_configured' ),
				'msg'   => md_kb_endpoint_fallback_message(),
			),
		);
	}

	$history = $body['history'] ?? array();
	$history = is_array( $history ) ? array_values( array_filter( $history, 'is_string' ) ) : array();

	list( $results, $context ) = md_kb_endpoint_retrieve( $q );
	$answer                    = md_kb_endpoint_call_model( md_kb_endpoint_messages( $q, $context, $history ) );

	if ( is_string( $answer ) ) {
		return array(
			'status' => 502,
			'body'   => array(
				'error' => array( 'code' => $answer ),
				'msg'   => md_kb_endpoint_fallback_message(),
			),
		);
	}

	return array(
		'status' => 200,
		'body'   => array(
			'msg'     => $answer['answer'],
			'action'  => $answer['handoff'] ? 'transfer_operator' : '',
			'sources' => md_kb_endpoint_sources( $results ),
		),
	);
}

/**
 * Search the knowledge for the question: the knowledge plugin's own engine,
 * both of its sources (the knowledge base and the site crawl) with no
 * category filter — the endpoint serves the one MVP bot, not a bot fleet —
 * and the five hits the research budgeted, as the context the model answers
 * from.
 *
 * @param string $q The visitor's question.
 * @return array{0: array<int, array<string, mixed>>, 1: string} The search results, and the built context.
 */
function md_kb_endpoint_retrieve( string $q ): array {
	$engine = new RAPLSAICH_Search_Engine();
	$engine->set_bot_filters( array(), true, true );
	$results = $engine->search( $q, 5 );
	$context = $engine->build_context( $results, 20000, $q );
	return array( $results, $context );
}

/**
 * The audit trail of which knowledge an answer used: one line per hit, the
 * knowledge entries by their id, the crawled pages by their post — never
 * shown to the visitor, read from the response by the acceptance matrix.
 *
 * @param array<int, mixed> $results The search results.
 * @return array<int, string> The source lines.
 */
function md_kb_endpoint_sources( array $results ): array {
	$sources = array();
	foreach ( $results as $hit ) {
		if ( ! is_array( $hit ) ) {
			continue;
		}
		$type  = (string) ( $hit['type'] ?? '' );
		$title = trim( (string) ( $hit['title'] ?? '' ) );
		if ( 'knowledge' === $type ) {
			$sources[] = trim( 'knowledge#' . (int) ( $hit['source_id'] ?? 0 ) . ' ' . $title );
		} else {
			$post_type = (string) ( $hit['post_type'] ?? '' );
			$sources[] = trim( ( '' !== $post_type ? $post_type : 'index' ) . '#' . (int) ( $hit['post_id'] ?? 0 ) . ' ' . $title );
		}
	}
	return $sources;
}

/**
 * The system prompt the model answers under: only the knowledge provided,
 * no inventions, a plain "I don't have that" when the knowledge does not
 * cover it, and the two handoff triggers of the MVP — an explicit ask for a
 * human, and the model's own judgment that it cannot answer.
 *
 * @return string The system prompt.
 */
function md_kb_endpoint_system_prompt(): string {
	return 'You are the support assistant of metal-divider.com, a B2B architectural metal divider (pattern) inquiry site. Answer the visitor\'s question ONLY from the KNOWLEDGE provided in the user message. Do not invent specifications, prices or materials. If the KNOWLEDGE does not contain the answer, say plainly that you do not have it. Reply with json only: {"answer": "<text for the visitor>", "handoff": true|false}. Set handoff true when the visitor asks for a human or an agent, or when you cannot answer from the KNOWLEDGE.';
}

/**
 * The messages of one model call: the system prompt, the last turns of
 * history the caller passed (visitor messages, at most six), and the
 * question with the knowledge under it — the empty knowledge included, so
 * the model sees there is none to answer from rather than guessing.
 *
 * @param string        $q       The visitor's question.
 * @param string        $context The knowledge context, '' when nothing was found.
 * @param array<string> $history The earlier visitor messages.
 * @return array<int, array{role: string, content: string}> The messages.
 */
function md_kb_endpoint_messages( string $q, string $context, array $history ): array {
	$messages = array(
		array(
			'role'    => 'system',
			'content' => md_kb_endpoint_system_prompt(),
		),
	);
	foreach ( array_slice( $history, -6 ) as $turn ) {
		$messages[] = array(
			'role'    => 'user',
			'content' => $turn,
		);
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => 'KNOWLEDGE:' . "\n" . ( '' !== $context ? $context : '(no knowledge found)' ) . "\n\n" . 'QUESTION: ' . $q,
	);
	return $messages;
}

/**
 * Ask DeepSeek's official chat completions for the answer, without
 * streaming: the whole call — the search and the generation together — fits
 * the 45-second HTTP budget, and the JSON response format is requested so
 * the {"answer","handoff"} reply is the model's own contract. A failure
 * returns the error code for the 502 instead of the answer.
 *
 * @param array<int, array{role: string, content: string}> $messages The conversation.
 * @return array{answer: string, handoff: bool}|string The parsed reply, or the error code.
 */
function md_kb_endpoint_call_model( array $messages ): array|string {
	$response = wp_remote_post(
		'https://api.deepseek.com/chat/completions',
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . md_kb_endpoint_setting( 'deepseek_api_key' ),
			),
			'body'    => (string) wp_json_encode(
				array(
					'model'           => 'deepseek-flash',
					'messages'        => $messages,
					'stream'          => false,
					'response_format' => array( 'type' => 'json_object' ),
				)
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return 'upstream_error';
	}

	$body    = json_decode( wp_remote_retrieve_body( $response ), true );
	$content = $body['choices'][0]['message']['content'] ?? null;
	if ( ! is_string( $content ) ) {
		return 'upstream_error';
	}

	$reply = md_kb_endpoint_parse_model_json( $content );
	return null === $reply ? 'upstream_error' : $reply;
}

/**
 * The model's reply, from its content to the endpoint's contract: the JSON
 * object is decoded wherever it sits in the text — a fence or a sentence
 * around it is tolerated, the object itself is required, with a non-empty
 * answer and a boolean handoff.
 *
 * @param string $content The reply's content.
 * @return array{answer: string, handoff: bool}|null The reply, or null when it is not one.
 */
function md_kb_endpoint_parse_model_json( string $content ): ?array {
	$decoded = json_decode( $content, true );
	if ( ! is_array( $decoded ) && 1 === preg_match( '/\{.*\}/s', $content, $matches ) ) {
		$decoded = json_decode( $matches[0], true );
	}
	if ( ! is_array( $decoded ) || ! is_string( $decoded['answer'] ?? null ) || '' === trim( $decoded['answer'] ) ) {
		return null;
	}
	return array(
		'answer'  => $decoded['answer'],
		'handoff' => (bool) ( $decoded['handoff'] ?? false ),
	);
}

/**
 * The message a visitor gets when the endpoint cannot produce an answer —
 * the knowledge layer is missing, or the model upstream failed or timed out.
 * The endpoint never shows a visitor a bare error: this is what the LHC
 * failure combination can display instead (the copy is the endpoint's
 * working draft, for the site's copy review).
 *
 * @return string The fallback message.
 */
function md_kb_endpoint_fallback_message(): string {
	return 'Sorry — I cannot look that up right now. Please ask again in a moment, or message us on WhatsApp (+852 6473 1687) and our team will take it from there.';
}

/**
 * Register the answer route: the one address the LHC bot is configured
 * against (the seam ticket #118 pins).
 *
 * @return void
 */
function md_kb_endpoint_register(): void {
	register_rest_route(
		'md/v1',
		'/kb/answer',
		array(
			'methods'             => 'POST',
			'callback'            => 'md_kb_endpoint_respond',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * The route's adapter, the one WordPress-object layer between the HTTP
 * request and md_kb_endpoint_answer(): the bearer gate is the endpoint's
 * own (a permission_callback would run before the body could be read, and
 * would answer WordPress's error shape instead of the contract's).
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response The status and body the contract specifies.
 */
function md_kb_endpoint_respond( $request ) {
	$out = md_kb_endpoint_answer(
		array(
			'header' => (string) $request->get_header( 'Authorization' ),
			'body'   => (array) $request->get_json_params(),
		)
	);
	return new \WP_REST_Response( $out['body'], $out['status'] );
}
