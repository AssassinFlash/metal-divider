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
		return md_kb_endpoint_refuse_auth_failure();
	}

	$q = $body['q'] ?? null;
	if ( ! is_string( $q ) || '' === trim( $q ) || mb_strlen( $q ) > 4000 ) {
		return array(
			'status' => 400,
			'body'   => array( 'error' => array( 'code' => 'bad_request' ) ),
		);
	}

	if ( md_kb_endpoint_knowledge_is_empty() ) {
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

	$chat_id = isset( $body['chat_id'] ) && is_scalar( $body['chat_id'] ) && ctype_digit( (string) $body['chat_id'] )
		? (int) $body['chat_id']
		: 0;

	$history = $body['history'] ?? array();
	// Each turn is capped where it is accepted: only the last six turns
	// reach the model, but every accepted byte reached the server and was
	// held first, so an unbounded turn is an unbounded request payload
	// from a caller the bearer only vouches for so much. The cap mirrors
	// the question's own limit in kind — characters, not bytes.
	$history = is_array( $history )
		? array_map(
			static fn ( string $turn ): string => mb_substr( $turn, 0, 2000 ),
			array_values( array_filter( $history, 'is_string' ) )
		)
		: array();
	if ( array() === $history && $chat_id > 0 ) {
		$history = md_kb_endpoint_chat_history( $chat_id, $q );
	}

	$direct_pattern = md_kb_endpoint_pattern_lookup( $q );
	$direct_p_hits  = $direct_pattern['hits'];
	$pattern        = $direct_pattern;
	if ( 0 === $pattern['hits'] && array() !== $history && ! md_kb_endpoint_asks_recommendation( $q ) ) {
		$pattern = md_kb_endpoint_history_pattern_lookup( $history );
	}
	$page = 0 === $pattern['hits'] ? md_kb_endpoint_page_context( $body['page_url'] ?? null ) : array( 'context' => '' );
	if ( 0 === $pattern['hits'] && '' === $page['context'] && $chat_id > 0 ) {
		$page = md_kb_endpoint_page_context( md_kb_endpoint_chat_referrer( $chat_id ) );
	}
	$resolved = '' !== $pattern['context'] ? $pattern['context'] : $page['context'];
	// The source the answer actually used: the named pattern, else the page
	// the visitor has open, else the knowledge rows. A page-resolved answer
	// never cites knowledge rows the retrieval dragged in alongside it.
	$resolved_sources = 0 < $pattern['hits'] ? $pattern['sources'] : ( isset( $page['sources'] ) ? $page['sources'] : array() );
	if ( md_kb_endpoint_asks_prompt_injection( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_injection_handoff_message(),
				'action'  => 'transfer_operator',
				'sources' => array(),
			),
		);
	}
	if ( md_kb_endpoint_asks_shipping( $q ) ) {
		$shipping_sources = '' !== md_kb_endpoint_partial_fact_message( $q, $resolved )
			? array_merge( $resolved_sources, array( 'Packing and shipping' ) )
			: array( 'Packing and shipping' );
		return array(
			'status' => 200,
			'body'   => array(
				'msg'           => md_kb_endpoint_shipping_reply( $q, $resolved, $shipping_sources ),
				'action'        => md_kb_endpoint_wants_human( $q ) ? 'transfer_operator' : '',
				'sources'       => $shipping_sources,
				'source_labels' => md_kb_endpoint_source_labels( $shipping_sources ),
			),
		);
	}
	if ( md_kb_endpoint_asks_pattern_check_prompt( $q ) ) {
		return md_kb_endpoint_pattern_check_prompt_reply( $resolved, $resolved_sources );
	}
	// A confirmed fact the question asked for stays. Neither a commercial
	// commitment nor an unpublished project fact is invented by the model.
	if ( md_kb_endpoint_asks_unverified_fact( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_unverified_fact_reply( $q, $resolved ),
				'action'  => 'transfer_operator',
				'sources' => $resolved_sources,
			),
		);
	}
	if ( md_kb_endpoint_asks_commercial( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_commercial_reply( $q, $resolved ),
				'action'  => md_kb_endpoint_wants_human( $q ) ? 'transfer_operator' : '',
				'sources' => $resolved_sources,
			),
		);
	}

	if ( md_kb_endpoint_asks_showroom( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'           => md_kb_endpoint_showroom_reply(),
				'action'        => '',
				'sources'       => array( 'Showroom visits' ),
				'source_labels' => array( 'Showroom visits' ),
			),
		);
	}
	if ( md_kb_endpoint_asks_dimensions( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'           => md_kb_endpoint_dimensions_reply( $q, $resolved ),
				'action'        => '',
				'sources'       => array( 'Thickness and weight' ),
				'source_labels' => array( 'Thickness and weight' ),
			),
		);
	}

	if ( md_kb_endpoint_asks_recommendation( $q ) ) {
		if ( 0 === $pattern['hits'] ) {
			$pattern = md_kb_endpoint_pattern_recommend( $q );
		} else {
			$exclude_ids = md_kb_endpoint_pattern_source_ids( $pattern['sources'] );
			$recommend_q = md_kb_endpoint_augment_recommendation_query( $q, $exclude_ids );
			$recommended = md_kb_endpoint_pattern_recommend( $recommend_q, $exclude_ids );
			if ( 0 < $recommended['hits'] ) {
				$pattern['context'] = $pattern['context'] . "\n\n" . $recommended['context'];
				$pattern['sources'] = array_values( array_unique( array_merge( $pattern['sources'], $recommended['sources'] ) ) );
				$pattern['hits']    = count( $pattern['sources'] );
			}
		}
		$resolved         = '' !== $pattern['context'] ? $pattern['context'] : $page['context'];
		$resolved_sources = 0 < $pattern['hits'] ? $pattern['sources'] : ( isset( $page['sources'] ) ? $page['sources'] : array() );
	}

	list( $results, $context ) = md_kb_endpoint_retrieve( $q );
	if ( '' !== $pattern['context'] ) {
		$context = "PATTERN FACTS (WooCommerce, current publish):\n" . $pattern['context']
			. ( '' !== trim( $context ) ? "\n\nKNOWLEDGE (Rapls):\n" . $context : '' );
	} elseif ( '' !== $page['context'] ) {
		$context = "CURRENT PATTERN (the page the visitor opened the chat on):\n" . $page['context']
			. ( '' !== trim( $context ) ? "\n\nKNOWLEDGE (Rapls):\n" . $context : '' );
	}
	$answer = md_kb_endpoint_call_model( md_kb_endpoint_messages( $q, $context, $history ) );

	if ( is_string( $answer ) ) {
		$fallback_answer = md_kb_endpoint_pattern_upstream_fallback( $q, $resolved, $pattern, $results );
		if ( null === $fallback_answer ) {
			return array(
				'status' => 502,
				'body'   => array(
					'error' => array( 'code' => $answer ),
					'msg'   => md_kb_endpoint_fallback_message(),
				),
			);
		}
		$answer = $fallback_answer;
	}

	if (
		md_kb_endpoint_asks_recommendation( $q )
		&& 0 < $pattern['hits']
		&& $answer['handoff']
		&& 'human_request' !== $answer['reason']
		&& ! md_kb_endpoint_wants_human( $q )
		&& md_kb_endpoint_no_answer_message() !== $answer['answer']
	) {
		$answer['handoff'] = false;
		$answer['reason']  = 'none';
		$answer['answer']  = trim( (string) preg_replace( '/\s+[^.?!]*\bbe(?:ing)?\s+checked\s+with\s+the\s+team\b[^.?!]*[.?!]?$/iu', '', $answer['answer'] ) );
	}

	$answer = md_kb_endpoint_preserve_known_facts( $q, $resolved, $answer );

	// A question that kept a published fact is not an unanswered gap, even when the rest transfers.
	if ( $answer['handoff'] && md_kb_endpoint_is_knowledge_gap( $answer ) && 0 === $pattern['hits'] && '' === md_kb_endpoint_partial_fact_message( $q, $resolved ) ) {
		$trimmed_context = trim( $context );
		$gap_reason      = '' === $trimmed_context ? 'no_context' : 'grounding';
		md_kb_endpoint_log_unanswered( $q, $gap_reason );
	}

	$kb_sources = md_kb_endpoint_sources( $results );
	$use_kb     = 0 === $direct_p_hits
		&& ! md_kb_endpoint_asks_recommendation( $q )
		&& array() !== $kb_sources
		&& ! md_kb_endpoint_answer_uses_resolved_pattern( $q, $resolved, $answer['answer'] );
	$sources    = ( array() !== $resolved_sources && ! $use_kb ) ? $resolved_sources : $kb_sources;
	$labels     = md_kb_endpoint_source_labels( $sources );
	$msg        = md_kb_endpoint_normalize_links( $answer['answer'] );
	if ( ! $answer['handoff'] && array() !== $labels ) {
		$msg .= md_kb_endpoint_source_footer( $sources );
	}
	return array(
		'status' => 200,
		'body'   => array(
			'msg'           => $msg,
			'action'        => $answer['handoff'] ? 'transfer_operator' : '',
			'sources'       => $sources,
			'source_labels' => $labels,
		),
	);
}

/**
 * The material every pattern is made of: the site's single constant
 * (owner ruling 2026-10-24 revision 6), not a product field.
 */
const MD_KB_ENDPOINT_MATERIAL = 'Stainless Steel (304 / 316)';

/**
 * The open-area wording for a pattern whose value has no source. A missing
 * value is never reported as zero (owner ruling 2026-10-24 revision 4).
 */
const MD_KB_ENDPOINT_OPEN_AREA_ON_REQUEST = 'On request';

/**
 * How many pattern candidates one question may cite.
 */
const MD_KB_ENDPOINT_PATTERN_LIMIT = 3;

/**
 * The visitor-facing sentence when a question asks for a price, a lead
 * time, an MOQ or a sample promise. Those are not product facts, and the
 * model is not asked to invent them.
 */
function md_kb_endpoint_commercial_handoff_message(): string {
	return 'Every screen is custom-fabricated in 304 or 316 stainless steel. Pricing, lead time, minimum order, and finish samples are confirmed by our team for your panel dimensions, thickness (0.8-3.0 mm), PVD finish, and quantity — use Request Quote or Chat on WhatsApp above for a formal quote within 24 hours.';
}

/**
 * Whether the visitor explicitly asked to speak with a human agent in the
 * same message. Commercial and shipping questions keep the AI assistant
 * active unless the visitor also requests a human transfer.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the visitor explicitly requested a human agent.
 */
function md_kb_endpoint_wants_human( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:talk\s+to\s+(?:a\s+)?(?:human|person|agent|someone|representative|team|operator)|speak\s+(?:to|with)\s+(?:a\s+)?(?:human|person|agent|someone|representative|operator)|connect\s+me\s+(?:with|to)|real\s+person|human\s+agent|live\s+agent)\b|转人工|人工客服|找人工|真人客服/iu',
		$q
	);
}

/**
 * Whether the question asks for a commercial commitment rather than only a
 * pattern fact: price, quote, lead time, a ship date, MOQ or a sample, in
 * English or Chinese. A published fact the question also asked for is kept
 * beside the handoff; the commitment itself is never turned into a number.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the team must answer the commitment.
 */
function md_kb_endpoint_asks_commercial( string $q ): bool {
	if ( md_kb_endpoint_asks_ship_date( $q ) ) {
		return true;
	}
	return 1 === preg_match(
		'/\b(?:price|pricing|quote|quotation|cost\b(?!\s*-?\s*effective)|how\s+much|lead[\s-]*time|delivery\s+(?:time|date)|moq|minimum\s+order|samples?)\b|价格|报价|交期|交货|起订|样品|多少钱|单价|费用|运费|发货(?!到)/iu',
		$q
	);
}

/**
 * Whether the question asks when something would ship, rather than only
 * where it can be sent. "Do you deliver to Dubai?" is a destination, not
 * a date, and still reaches the model.
 *
 * @param string $q The visitor's question.
 * @return bool Whether a ship date was asked for.
 */
function md_kb_endpoint_asks_ship_date( string $q ): bool {
	return 1 === preg_match(
		'/\bwhen\s+(?:could|can|will|would)\s+(?:you|we|it|this|that)\s+ship\b|\bhow\s+soon\b|\bship(?:ping)?\s+date\b|\bwhen\s+(?:could|can|will)\s+it\s+(?:arrive|be\s+delivered)\b|什么时候.{0,8}发货|何时发货|发货时间/iu',
		$q
	);
}

/**
 * Whether the visitor asks about shipping destinations, methods or terms
 * (rather than a ship date, freight price or on-site installation).
 *
 * @param string $q The visitor's question.
 * @return bool Whether the question is a shipping capability question.
 */
function md_kb_endpoint_asks_shipping( string $q ): bool {
	if ( md_kb_endpoint_asks_ship_date( $q ) || md_kb_endpoint_asks_unverified_fact( $q ) || md_kb_endpoint_asks_commercial( $q ) ) {
		return false;
	}
	return 1 === preg_match(
		'/\b(?:do\s+you\s+(?:ship|deliver)|can\s+you\s+(?:ship|deliver)|ship\s+to|deliver\s+to|ship\s+(?:internationally|worldwide)|deliver\s+(?:internationally|worldwide)|shipping\s+(?:to|options|methods|terms)|freight)\b|发货到|送到|运到|发到|能发.*吗|海运|空运/iu',
		$q
	);
}

/**
 * The visitor-facing sentence for packing and worldwide shipping options.
 *
 * @return string The shipping guidance copy.
 */
function md_kb_endpoint_shipping_message(): string {
	return 'We pack custom stainless steel panels in export-grade wooden crates and ship worldwide by sea freight or air freight under EXW, FOB, CIF, or DDP terms. Use Request Quote or Chat on WhatsApp above with your destination and panel sizes so our team can confirm the freight option.';
}

/**
 * The direct reply for packing and shipping, keeping any confirmed pattern
 * facts the question also asked for.
 *
 * @param string             $q       The visitor's question.
 * @param string             $context The resolved pattern facts, '' when none.
 * @param array<int, string> $sources The sources to cite in the footer.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_shipping_reply( string $q, string $context, array $sources = array( 'Packing and shipping' ) ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$parts[] = md_kb_endpoint_shipping_message();
	return implode( ' ', $parts ) . md_kb_endpoint_source_footer( $sources );
}

/**
 * The commercial handoff, with any confirmed fact the question asked for
 * kept in front of it. A price, a date and a sample promise are not added.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_commercial_reply( string $q, string $context ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	if ( md_kb_endpoint_unverified_cad_must_transfer( $q, $context ) ) {
		$parts[] = md_kb_endpoint_drawing_check_message();
	}
	$parts[] = md_kb_endpoint_commercial_handoff_message();
	return implode( ' ', $parts );
}

/**
 * Whether the question asks for a project fact the public site does not
 * publish consistently: on-site installation. Shipping, showroom visits and
 * the published thickness/weight guidance are answered directly, with the
 * project-specific value still left to the team.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the team must answer it.
 */
function md_kb_endpoint_asks_unverified_fact( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:install(?:ation)?\s+(?:in|at|for|service)|do\s+you\s+install|can\s+you\s+install)\b|安装/iu',
		$q
	);
}

/**
 * Whether the visitor asks about visiting the team in person.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the question is a visit question.
 */
function md_kb_endpoint_asks_showroom( string $q ): bool {
	return 1 === preg_match( '/\b(?:showroom|show\s+room|visit\s+(?:the\s+)?(?:factory|office|showroom)|visit\s+you|come\s+to\s+your)\b|展厅|参观/iu', $q );
}

/**
 * Whether the visitor asks for thickness or weight. The published guidance
 * is shown; the exact value is still confirmed per project.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the question is a thickness or weight question.
 */
function md_kb_endpoint_asks_dimensions( string $q ): bool {
	return 1 === preg_match( '/\b(?:thickness|how\s+thick|weight|weigh(?:s|t)?|how\s+heavy|kg\s*\/\s*m)\b|厚度|多厚|重量|多重/iu', $q );
}

/**
 * Whether the visitor sent the Start screen's "Ask about a pattern" starter
 * prompt ("I have a pattern in mind. Can you help me check it?") without
 * adding a specific pattern name or question.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the question is the starter prompt.
 */
function md_kb_endpoint_asks_pattern_check_prompt( string $q ): bool {
	return 1 === preg_match( '/^\s*i\s+have\s+a\s+pattern\s+in\s+mind[.!]?\s+can\s+you\s+help\s+me\s+check\s+it\??\s*$/iu', $q );
}

/**
 * Deterministic response for the "Ask about a pattern" starter prompt: if
 * the visitor is already on a pattern page, summarizes that pattern's
 * published facts and invites follow-up questions; otherwise asks them to
 * name the pattern or open its page.
 *
 * @param string             $resolved         The resolved pattern context.
 * @param array<int, string> $resolved_sources The resolved pattern sources.
 * @return array{status: int, body: array<string, mixed>} The endpoint reply.
 */
function md_kb_endpoint_pattern_check_prompt_reply( string $resolved, array $resolved_sources ): array {
	if ( '' !== $resolved ) {
		$blocks = md_kb_endpoint_pattern_fact_blocks( $resolved );
		if ( isset( $blocks[0] ) ) {
			$block = $blocks[0];
			$name  = md_kb_endpoint_pattern_display_name( $block );
			$oa    = trim( (string) ( $block['open_area'] ?? '' ) );
			$fit   = str_replace( array( '-', '_' ), ' ', trim( (string) ( $block['best_fit'] ?? '' ) ) );
			$parts = array( 'You are viewing ' . $name . ' (' . MD_KB_ENDPOINT_MATERIAL . ')' );
			if ( '' !== $oa ) {
				$parts[] = 'open area: ' . $oa;
			}
			if ( '' !== $fit ) {
				$parts[] = 'best fit: ' . $fit;
			}
			$msg    = implode( ', ', $parts ) . '. Tell me what you would like to check — or share another pattern name or code.';
			$labels = md_kb_endpoint_source_labels( $resolved_sources );
			if ( array() !== $labels ) {
				$msg .= md_kb_endpoint_source_footer( $resolved_sources );
			}
			return array(
				'status' => 200,
				'body'   => array(
					'msg'           => $msg,
					'action'        => '',
					'sources'       => $resolved_sources,
					'source_labels' => $labels,
				),
			);
		}
	}
	return array(
		'status' => 200,
		'body'   => array(
			'msg'           => 'Of course — share the pattern name or code (or open the pattern page) and tell me what you would like to check, such as open area, visual density, repeat behavior, series, or application.',
			'action'        => '',
			'sources'       => array(),
			'source_labels' => array(),
		),
	);
}

/**
 * Normalizes markdown links in model replies so bold markers inside link
 * labels (`[**Name**](url)`) and spaces between `]` and `(` (`[Name] (url)`)
 * render cleanly as clickable links in the visitor widget.
 *
 * @param string $text The reply text.
 * @return string The normalized reply text.
 */
function md_kb_endpoint_normalize_links( string $text ): string {
	$text = (string) preg_replace_callback(
		'/\[([^\]]+)\]\s*\((https?:\/\/[^\s)]+|\/[a-z0-9][a-z0-9-\/]*)\)/iu',
		static function ( array $m ): string {
			$label = trim( str_replace( '**', '', $m[1] ) );
			return '[' . $label . '](' . $m[2] . ')';
		},
		$text
	);
	return $text;
}

/**
 * The direct reply for a showroom visit. Owner decided visits are welcome
 * (2026-10-27); no street address is invented, and the team confirms time.
 *
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_showroom_reply(): string {
	return 'You are welcome to visit our Foshan, Guangdong location by arrangement, Monday to Saturday 9:00-18:00 GMT+8. Contact our team with a preferred date and we will confirm the address and time.'
		. md_kb_endpoint_source_footer( array( 'Showroom visits' ) );
}

/**
 * The direct reply for thickness and weight: the published decorative-panel
 * guidance and one qualified weight reference, never an exact project value.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_dimensions_reply( string $q, string $context ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$parts[] = 'Decorative stainless steel panels are commonly 0.8-3.0 mm thick. As a reference, 1.0 mm stainless steel weighs about 8 kg/m2. The exact thickness and weight are confirmed for each project from the pattern, fixing and span.';
	return implode( ' ', $parts ) . md_kb_endpoint_source_footer( array( 'Thickness and weight' ) );
}

/**
 * The showroom, destination, installation, thickness or weight handoff.
 * No number or promise is stated; the team confirms the project.
 *
 * @return string The visitor-facing handoff sentence.
 */
function md_kb_endpoint_unverified_fact_handoff_message(): string {
	return 'That project detail is confirmed by our team — I am connecting you with them.';
}

/**
 * The unverified-fact handoff, with any confirmed pattern fact the question
 * also asked for kept in front of it.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_unverified_fact_reply( string $q, string $context ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$parts[] = md_kb_endpoint_unverified_fact_handoff_message();
	return implode( ' ', $parts );
}

/**
 * The sentence used when a drawing was asked for and the published CAD
 * status does not confirm one. It does not say the drawing is available.
 *
 * @return string The check sentence.
 */
function md_kb_endpoint_drawing_check_message(): string {
	return 'A drawing has not been confirmed — our team is checking that.';
}

/**
 * The sentence used when a published fact was kept and the rest of the
 * question still has to be checked. It does not deny the fact just stated.
 *
 * @return string The remainder sentence.
 */
function md_kb_endpoint_remainder_message(): string {
	return 'The rest of that is being checked with our team.';
}

/**
 * Puts confirmed facts in front of a handoff. The exact no-answer sentence
 * is replaced, because that sentence denies information the reply now has.
 *
 * @param string $facts The confirmed sentences, '' when none.
 * @param string $reply The reply so far.
 * @return string The reply to send.
 */
function md_kb_endpoint_with_kept_facts( string $facts, string $reply ): string {
	$reply = trim( $reply );
	if ( '' === $facts ) {
		return $reply;
	}
	if ( md_kb_endpoint_no_answer_message() === $reply ) {
		$reply = md_kb_endpoint_remainder_message();
	}
	return '' === $reply ? $facts : $facts . ' ' . $reply;
}

/**
 * Drops sentences that promise a drawing. The other sentences stay, so a
 * known fact or a knowledge answer is not thrown away with the promise.
 *
 * @param string $message The visitor-facing reply.
 * @return string The reply without a drawing promise.
 */
function md_kb_endpoint_without_drawing_promise( string $message ): string {
	$parts = preg_split( '/(?<=[.!?])\s+/u', trim( $message ) );
	if ( ! is_array( $parts ) ) {
		return '';
	}
	$kept = array();
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part || md_kb_endpoint_promises_drawing( $part ) ) {
			continue;
		}
		$kept[] = $part;
	}
	return implode( ' ', $kept );
}

/**
 * Keeps a confirmed pattern fact when the model hands the whole question
 * off, and refuses an unverified drawing promise.
 *
 * @param string                                               $q       The visitor's question.
 * @param string                                               $context The resolved pattern facts, '' when none.
 * @param array{answer: string, handoff: bool, reason: string} $answer The model's reply.
 * @return array{answer: string, handoff: bool, reason: string} The reply to send.
 */
function md_kb_endpoint_preserve_known_facts( string $q, string $context, array $answer ): array {
	if ( $answer['handoff'] ) {
		$missing = md_kb_endpoint_missing_fact_message( $q, $context, $answer['answer'] );
		if ( '' !== $missing ) {
			$answer['answer'] = md_kb_endpoint_with_kept_facts( $missing, $answer['answer'] );
			if ( '' === $answer['reason'] || 'none' === $answer['reason'] ) {
				$answer['reason'] = 'no_knowledge';
			}
		}
	}
	return md_kb_endpoint_guard_unverified_cad( $q, $context, $answer );
}

/**
 * Forces a transfer when a drawing was asked for and no published CAD
 * status confirms one, and removes a promise the facts do not support.
 *
 * @param string                                               $q       The visitor's question.
 * @param string                                               $context The resolved pattern facts, '' when none.
 * @param array{answer: string, handoff: bool, reason: string} $answer The reply so far.
 * @return array{answer: string, handoff: bool, reason: string} The reply to send.
 */
function md_kb_endpoint_guard_unverified_cad( string $q, string $context, array $answer ): array {
	if ( ! md_kb_endpoint_unverified_cad_must_transfer( $q, $context ) ) {
		return $answer;
	}
	$was_handoff       = $answer['handoff'];
	$answer['handoff'] = true;
	if ( $was_handoff && 'human_request' !== $answer['reason'] && ( '' === $answer['reason'] || 'none' === $answer['reason'] ) ) {
		$answer['reason'] = 'no_knowledge';
	}
	$reply   = md_kb_endpoint_without_drawing_promise( $answer['answer'] );
	$missing = md_kb_endpoint_missing_fact_message( $q, $context, $reply );
	$reply   = md_kb_endpoint_with_kept_facts( $missing, $reply );
	$handed  = str_contains( $reply, md_kb_endpoint_drawing_check_message() )
		|| str_contains( $reply, md_kb_endpoint_remainder_message() )
		|| str_contains( $reply, md_kb_endpoint_no_answer_message() );
	if ( ! $handed ) {
		$reply = '' !== $reply
			? rtrim( $reply ) . ' ' . md_kb_endpoint_drawing_check_message()
			: md_kb_endpoint_drawing_check_message();
	}
	$answer['answer'] = $reply;
	return $answer;
}

/**
 * Whether the question asks if a drawing can be sent, not only what the
 * published CAD status is.
 *
 * @param string $q The visitor's question.
 * @return bool Whether availability was asked.
 */
function md_kb_endpoint_asks_cad_availability( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:have|has|got|get|provide|send|share|request|available|availability)\b.{0,40}\b(?:cad|dxf|drawings?)\b|\bcad\s+status\b|\b(?:cad|dxf|drawings?)\b.{0,40}\b(?:available|availability|status|provided?|sent?|shared?)\b|(?:有|发|给|提供|分享|要|需要|查|看).{0,12}(?:图纸|CAD|DXF)|(?:图纸|CAD|DXF).{0,12}(?:有|发|给|提供|分享|可以|能)/iu',
		$q
	);
}

/**
 * Whether a published CAD status is one of the values that confirm a
 * drawing can be sent. Anything else, including gated, is not a promise.
 *
 * @param string $status The published status.
 * @return bool Whether the status confirms a drawing.
 */
function md_kb_endpoint_cad_status_confirms_drawing( string $status ): bool {
	return 1 === preg_match( '/^(?:available|ready|published|yes)$/iu', trim( $status ) );
}

/**
 * The customer-facing wording of a published CAD status. Gated and missing
 * are internal statuses, not words to put in front of a visitor.
 *
 * @param string $status The published status.
 * @return string The wording.
 */
function md_kb_endpoint_cad_status_wording( string $status ): string {
	return md_kb_endpoint_cad_status_confirms_drawing( $status ) ? $status : '(not confirmed)';
}

/**
 * Whether an asked-for drawing is not confirmed by the resolved facts.
 * A question that asks for a file must transfer unless every resolved
 * status confirms one; absence of a status line is not confirmation.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return bool Whether the availability question must transfer.
 */
function md_kb_endpoint_unverified_cad_must_transfer( string $q, string $context ): bool {
	if ( ! md_kb_endpoint_asks_cad_availability( $q ) ) {
		return false;
	}
	$saw         = false;
	$all_confirm = true;
	foreach ( md_kb_endpoint_pattern_fact_blocks( $context ) as $block ) {
		if ( ! array_key_exists( 'cad_status', $block ) ) {
			continue;
		}
		$saw = true;
		if ( ! md_kb_endpoint_cad_status_confirms_drawing( $block['cad_status'] ) ) {
			$all_confirm = false;
		}
	}
	return ! $saw || ! $all_confirm;
}

/**
 * Whether a reply promises a drawing the facts may not support.
 *
 * @param string $message The visitor-facing reply.
 * @return bool Whether it promises a drawing.
 */
function md_kb_endpoint_promises_drawing( string $message ): bool {
	return 1 === preg_match(
		'/\b(?:drawings?|cad(?:\s+files?)?|dxf)\s+(?:are|is)\s+(?:available|ready|attached)\b|\b(?:drawing|dxf|cad)\s+available\b|\b(?:can|will|are\s+able\s+to)\s+(?:send|provide|email|share)\b.{0,40}\b(?:drawing|cad|dxf)\b|\bhave\s+(?:a|the)?\s*(?:drawing|cad(?:\s+files?)?|dxf)\b/iu',
		$message
	);
}

/**
 * The confirmed facts the question asked for, as visitor sentences.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return string The sentences, '' when nothing asked is confirmed.
 */
function md_kb_endpoint_partial_fact_message( string $q, string $context ): string {
	$sentences = array();
	foreach ( md_kb_endpoint_partial_fact_pieces( $q, $context ) as $piece ) {
		$sentences[] = $piece['sentence'];
	}
	return implode( ' ', $sentences );
}

/**
 * The asked facts whose published value is not already in the reply.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @param string $reply   The reply so far.
 * @return string The missing sentences, '' when none are missing.
 */
function md_kb_endpoint_missing_fact_message( string $q, string $context, string $reply ): string {
	$missing = array();
	foreach ( md_kb_endpoint_partial_fact_pieces( $q, $context ) as $piece ) {
		if ( ! md_kb_endpoint_reply_has_value( $reply, $piece['value'] ) ) {
			$missing[] = $piece['sentence'];
		}
	}
	return implode( ' ', $missing );
}

/**
 * Whether an answer in a conversation that carried ambient pattern context
 * (from page_url or earlier history turns) actually asked about or answered
 * about that pattern, rather than a general knowledge-base topic.
 *
 * @param string $q        The visitor's question.
 * @param string $resolved The resolved pattern context.
 * @param string $reply    The visitor-facing answer.
 * @return bool Whether the resolved pattern should be cited.
 */
function md_kb_endpoint_answer_uses_resolved_pattern( string $q, string $resolved, string $reply ): bool {
	if ( '' === $resolved ) {
		return false;
	}
	if ( '' !== md_kb_endpoint_partial_fact_message( $q, $resolved ) || md_kb_endpoint_asks_cad_availability( $q ) ) {
		return true;
	}
	if ( 1 === preg_match( '/\b(?:this|that|it|its|current|open\s*area|density|repeat|motif|series|best\s*fit|cad|dxf|sku)\b|这个|这款|它|它的|镂空率/iu', $q ) ) {
		return true;
	}
	foreach ( md_kb_endpoint_pattern_fact_blocks( $resolved ) as $block ) {
		$name = md_kb_endpoint_pattern_display_name( $block );
		$code = trim( (string) ( $block['code'] ?? '' ) );
		if ( '' !== $name && false !== mb_stripos( $reply, $name ) ) {
			return true;
		}
		if ( '' !== $code && false !== mb_stripos( $reply, $code ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Deterministic fallback from published WooCommerce pattern facts or top
 * Rapls knowledge entries when the upstream model returns a 5xx/timeout,
 * so a visitor asking about a known pattern, asking for catalog
 * recommendations, or asking a covered FAQ question still receives a
 * grounded answer instead of a 502 chat handoff.
 *
 * @param string                                                         $q        The visitor's question.
 * @param string                                                         $resolved The resolved pattern context.
 * @param array{context: string, sources: array<int, string>, hits: int} $pattern  The pattern lookup or recommendation result.
 * @param array<int, mixed>                                              $results  The retrieved knowledge hits.
 * @return array{answer: string, handoff: bool, reason: string}|null The deterministic reply, or null when no published fact covers the question.
 */
function md_kb_endpoint_pattern_upstream_fallback( string $q, string $resolved, array $pattern, array $results = array() ): ?array {
	if ( md_kb_endpoint_wants_human( $q ) ) {
		return array(
			'answer'  => 'I am connecting you with our team — someone will follow up with you shortly.',
			'handoff' => true,
			'reason'  => 'human_request',
		);
	}
	if ( md_kb_endpoint_asks_unverified_fact( $q ) || md_kb_endpoint_asks_commercial( $q ) || md_kb_endpoint_asks_shipping( $q ) ) {
		return null;
	}
	if ( md_kb_endpoint_asks_recommendation( $q ) && 0 < $pattern['hits'] ) {
		$items = array();
		foreach ( md_kb_endpoint_pattern_fact_blocks( $pattern['context'] ) as $block ) {
			$name    = md_kb_endpoint_pattern_display_name( $block );
			$url     = trim( (string) ( $block['permalink'] ?? '' ) );
			$link    = '' !== $url ? '[' . $name . '](' . $url . ')' : $name;
			$details = array();
			if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['series'] ?? '' ) ) ) {
				$details[] = trim( (string) $block['series'] ) . ' series';
			}
			if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['open_area'] ?? '' ) ) ) {
				$details[] = trim( (string) $block['open_area'] ) . ' open area';
			}
			if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['best_fit'] ?? '' ) ) ) {
				$details[] = 'best fit: ' . str_replace( array( '-', '_' ), ' ', trim( (string) $block['best_fit'] ) );
			}
			$items[] = $link . ( array() !== $details ? ' (' . implode( ', ', $details ) . ')' : '' );
		}
		if ( array() !== $items ) {
			return array(
				'answer'  => 'Here are the closest matching patterns from our catalog: ' . implode( '; ', $items ) . '.',
				'handoff' => false,
				'reason'  => 'none',
			);
		}
	}
	if ( '' !== $resolved ) {
		$partial = md_kb_endpoint_partial_fact_message( $q, $resolved );
		if ( '' !== $partial ) {
			return array(
				'answer'  => $partial,
				'handoff' => false,
				'reason'  => 'none',
			);
		}
	}
	foreach ( $results as $hit ) {
		if ( ! is_array( $hit ) || 'knowledge' !== ( $hit['type'] ?? '' ) ) {
			continue;
		}
		$content = trim( (string) ( $hit['content'] ?? '' ) );
		if ( '' === $content ) {
			continue;
		}
		$paragraphs = preg_split( '/\n\s*\n/u', $content );
		$summary    = is_array( $paragraphs ) ? trim( $paragraphs[0] ) : $content;
		if ( '' !== $summary ) {
			return array(
				'answer'  => $summary,
				'handoff' => false,
				'reason'  => 'none',
			);
		}
	}
	return null;
}

/**
 * The asked facts that have a published value worth saying.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @return array<int, array{value: string, sentence: string}> The asked, confirmed facts.
 */
function md_kb_endpoint_partial_fact_pieces( string $q, string $context ): array {
	$fields = md_kb_endpoint_asked_pattern_fields( $q );
	if ( array() === $fields ) {
		return array();
	}
	$pieces = array();
	foreach ( md_kb_endpoint_pattern_fact_blocks( $context ) as $block ) {
		$name = md_kb_endpoint_pattern_display_name( $block );
		foreach ( $fields as $field ) {
			$value = trim( (string) ( $block[ $field ] ?? '' ) );
			if ( ! md_kb_endpoint_pattern_value_is_answerable( $value ) ) {
				continue;
			}
			$sentence = md_kb_endpoint_pattern_fact_sentence( $name, $field, $value );
			if ( '' === $sentence ) {
				continue;
			}
			$pieces[] = array(
				'value'    => $value,
				'sentence' => $sentence,
			);
		}
	}
	return $pieces;
}

/**
 * Whether the reply already states a published value, including the spaced
 * form of a hyphenated fit.
 *
 * @param string $reply The reply so far.
 * @param string $value The published value.
 * @return bool Whether the value is already said.
 */
function md_kb_endpoint_reply_has_value( string $reply, string $value ): bool {
	if ( '' === $value ) {
		return false;
	}
	if ( false !== mb_stripos( $reply, $value ) ) {
		return true;
	}
	$spaced = str_replace( array( '-', '_' ), ' ', $value );
	return $spaced !== $value && false !== mb_stripos( $reply, $spaced );
}

/**
 * The pattern fields the question asks about. A price or a ship date is
 * not a field, so a pure commercial question does not dump the product.
 *
 * @param string $q The visitor's question.
 * @return array<int, string> Field names, in sentence order.
 */
function md_kb_endpoint_asked_pattern_fields( string $q ): array {
	$wanted = array(
		'open_area'      => '/\bopen[\s-]*area\b/iu',
		'best_fit'       => '/\b(?:good|best)\s+fit\b|\bsuitable\b|\bsuitability\b|\bfit\s+for\b|\bused\s+for\b|\bapplications?\b/iu',
		'material'       => '/\b(?:what|which)\s+(?:material|grade)\b|\bmade\s+of\b|\bmaterial\s+is\b|\bgrade\s+is\b/iu',
		'visual_density' => '/\bvisual\s+density\b|\bdensity\s+of\b|\bhow\s+dense\b/iu',
		'cad_status'     => '/\bcad\b|\bdxf\b|\bdrawings?\b/iu',
		'code'           => '/\bpattern\s+code\b|\bsku\b|\bwhat(?:\'s| is) the code\b/iu',
		'permalink'      => '/\bpermalink\b|\bpattern\s+link\b|\bwhere\s+can\s+i\s+(?:see|find)\b/iu',
	);
	$fields = array();
	foreach ( $wanted as $field => $pattern ) {
		if ( 1 === preg_match( $pattern, $q ) ) {
			$fields[] = $field;
		}
	}
	return $fields;
}

/**
 * Splits pattern or page fact text into one field map per pattern.
 *
 * @param string $context The fact text.
 * @return array<int, array<string, string>> The blocks that name a pattern.
 */
function md_kb_endpoint_pattern_fact_blocks( string $context ): array {
	$context = trim( $context );
	if ( '' === $context ) {
		return array();
	}
	$chunks = preg_split( "/\n\s*\n/", $context );
	if ( ! is_array( $chunks ) ) {
		return array();
	}
	$blocks = array();
	foreach ( $chunks as $chunk ) {
		$fields = array();
		foreach ( explode( "\n", $chunk ) as $line ) {
			if ( 1 !== preg_match( '/^([a-z_]+): (.*)$/', $line, $match ) ) {
				continue;
			}
			$fields[ $match[1] ] = $match[2];
		}
		if ( isset( $fields['code'] ) || isset( $fields['title'] ) ) {
			$blocks[] = $fields;
		}
	}
	return $blocks;
}

/**
 * The visitor-facing name of one pattern block: the title ahead of the
 * catalogue suffix, otherwise the code.
 *
 * @param array<string, string> $block One pattern's fields.
 * @return string The name.
 */
function md_kb_endpoint_pattern_display_name( array $block ): string {
	$title = trim( (string) ( $block['title'] ?? '' ) );
	if ( '' !== $title ) {
		$parts = explode( '|', $title, 2 );
		$name  = trim( $parts[0] );
		if ( '' !== $name ) {
			return $name;
		}
	}
	$code = trim( (string) ( $block['code'] ?? '' ) );
	return '' !== $code ? $code : 'This pattern';
}

/**
 * Whether a published value may be said. An unconfirmed field and a zero
 * open area are not answers.
 *
 * @param string $value The published value.
 * @return bool Whether it may be said.
 */
function md_kb_endpoint_pattern_value_is_answerable( string $value ): bool {
	$value = trim( $value );
	if ( '' === $value || '(not confirmed)' === strtolower( $value ) ) {
		return false;
	}
	return 1 !== preg_match( '/^0(?:\.0+)?%?$/', $value );
}

/**
 * One visitor sentence for a confirmed field. Empty when that field has
 * no sentence. The value is used as published; a fit's hyphens are spaces.
 *
 * @param string $name  The pattern's display name.
 * @param string $field The field name.
 * @param string $value The published value.
 * @return string The sentence, '' when the field is not spoken.
 */
function md_kb_endpoint_pattern_fact_sentence( string $name, string $field, string $value ): string {
	if ( 'open_area' === $field ) {
		return $name . "'s open area is " . $value . '.';
	}
	if ( 'best_fit' === $field ) {
		$fit = trim( str_replace( array( '-', '_' ), ' ', $value ) );
		return $name . "'s published fit is " . $fit . '.';
	}
	if ( 'material' === $field ) {
		return $name . "'s material is " . $value . '.';
	}
	if ( 'visual_density' === $field ) {
		return $name . "'s visual density is " . $value . '.';
	}
	if ( 'cad_status' === $field ) {
		return $name . "'s CAD status is " . $value . '.';
	}
	if ( 'code' === $field ) {
		return $name . "'s pattern code is " . $value . '.';
	}
	if ( 'permalink' === $field ) {
		return $name . "'s page is " . $value . '.';
	}
	return '';
}

/**
 * The visitor-facing sentence when a question tries to override the
 * instructions. The model is not asked, and the sentence does not quote
 * the instructions or the attempt.
 *
 * @return string The handoff copy.
 */
function md_kb_endpoint_injection_handoff_message(): string {
	return 'I can only help with questions about our architectural metal screens — I am connecting you with the team.';
}

/**
 * Whether the question tries to override, reveal or role-play the
 * instructions. A matching pattern or knowledge row does not change the
 * answer: the model is not called, so it cannot leak the prompt.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the team must take the conversation.
 */
function md_kb_endpoint_asks_prompt_injection( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:ignore\s+(?:all\s+|your\s+|the\s+)?(?:previous|prior|above)\s+instructions|system\s+prompt|reveal\s+(?:your|the)\s+prompt|act\s+as)\b/iu',
		$q
	);
}

/**
 * Words that are never a pattern name, so a question's grammar does not
 * become a product search.
 *
 * @return array<int, string> Lower-case stopwords.
 */
function md_kb_endpoint_pattern_stopwords(): array {
	return array(
		'what',
		'when',
		'where',
		'which',
		'who',
		'why',
		'how',
		'does',
		'do',
		'did',
		'is',
		'are',
		'was',
		'were',
		'the',
		'and',
		'for',
		'with',
		'about',
		'from',
		'this',
		'that',
		'these',
		'those',
		'your',
		'you',
		'can',
		'could',
		'would',
		'should',
		'have',
		'has',
		'had',
		'please',
		'tell',
		'show',
		'give',
		'need',
		'want',
		'looking',
		'pattern',
		'patterns',
		'product',
		'products',
		'metal',
		'screen',
		'screens',
		'divider',
		'panel',
		'panels',
		'open',
		'area',
		'size',
		'price',
		'cost',
		'quote',
		'lead',
		'time',
		'delivery',
		'sample',
		'samples',
	);
}

/**
 * The pattern codes and name candidates a question names. A code is a
 * token shaped like MD-101. A name is a quoted word or a word that is not
 * itself a code — the lookup treats each as a search.
 *
 * @param string $q The visitor's question.
 * @return array<int, string> The candidates, codes first, at most a handful.
 */
function md_kb_endpoint_pattern_candidates( string $q ): array {
	$candidates = array();
	if ( preg_match_all( '/\b[A-Z]{1,6}-\d{2,6}\b/i', $q, $codes ) ) {
		foreach ( $codes[0] as $code ) {
			$candidates[] = $code;
		}
	}
	if ( preg_match_all( "/[\"'\x{201c}\x{201d}]([^\"'\x{201c}\x{201d}]{2,40})[\"'\x{201c}\x{201d}]/u", $q, $quoted ) ) {
		foreach ( $quoted[1] as $name ) {
			$candidates[] = trim( $name );
		}
	}
	// Lower-case names matter: visitors type "agave", not "Agave". A
	// multi-word run is its own candidate so "Long Island" can prefix-match
	// without letting the single word "long" do the same. A lone word is
	// still a candidate, but the query only keeps it on an exact SKU, slug
	// or full title.
	if ( preg_match_all( '/\b[A-Za-z][A-Za-z0-9]{2,}\b/', $q, $names ) ) {
		$words = array();
		foreach ( $names[0] as $name ) {
			if ( 1 === preg_match( '/^[A-Z]{1,6}-\d{2,6}$/i', $name ) ) {
				continue;
			}
			if ( in_array( mb_strtolower( $name ), md_kb_endpoint_pattern_stopwords(), true ) ) {
				continue;
			}
			$words[] = $name;
		}
		$count = count( $words );
		for ( $length = min( 6, $count ); $length >= 2; $length-- ) {
			for ( $start = 0; $start <= $count - $length; $start++ ) {
				$candidates[] = implode( ' ', array_slice( $words, $start, $length ) );
			}
		}
		foreach ( $words as $name ) {
			$candidates[] = $name;
		}
	}
	$unique = array();
	foreach ( $candidates as $candidate ) {
		$key = mb_strtolower( $candidate );
		if ( '' === $key || isset( $unique[ $key ] ) ) {
			continue;
		}
		$unique[ $key ] = $candidate;
	}
	return array_values( $unique );
}

/**
 * The `product_visibility` term ids WooCommerce's catalog excludes, matching
 * Catalog::catalog_excluded_term_ids().
 *
 * @return array<int, int> The excluded term taxonomy ids.
 */
function md_kb_endpoint_catalog_excluded_term_ids(): array {
	if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
		return array();
	}
	$ids = wc_get_product_visibility_term_ids();
	if ( ! is_array( $ids ) || empty( $ids['exclude-from-catalog'] ) ) {
		return array();
	}
	return array( (int) $ids['exclude-from-catalog'] );
}

/**
 * The tax_query clause that excludes products hidden from the WooCommerce
 * catalog (`exclude-from-catalog`), matching Catalog.php:658-667.
 *
 * @return array<int, array<string, mixed>> The tax_query array.
 */
function md_kb_endpoint_catalog_tax_query(): array {
	$excluded = md_kb_endpoint_catalog_excluded_term_ids();
	if ( array() !== $excluded ) {
		return array(
			array(
				'taxonomy'         => 'product_visibility',
				'field'            => 'term_taxonomy_id',
				'terms'            => $excluded,
				'operator'         => 'NOT IN',
				'include_children' => false,
			),
		);
	}
	return array(
		array(
			'taxonomy'         => 'product_visibility',
			'field'            => 'name',
			'terms'            => array( 'exclude-from-catalog' ),
			'operator'         => 'NOT IN',
			'include_children' => false,
		),
	);
}

/**
 * Whether a product carries the `exclude-from-catalog` visibility term.
 *
 * @param int $post_id The product ID.
 * @return bool True when excluded from the catalog.
 */
function md_kb_endpoint_is_catalog_excluded( int $post_id ): bool {
	$terms = get_the_terms( $post_id, 'product_visibility' );
	if ( ! is_array( $terms ) ) {
		return false;
	}
	foreach ( $terms as $term ) {
		if ( ! is_object( $term ) ) {
			continue;
		}
		$slug = strtolower( (string) ( $term->slug ?? '' ) );
		$name = strtolower( (string) ( $term->name ?? '' ) );
		if ( 'exclude-from-catalog' === $slug || 'exclude-from-catalog' === $name ) {
			return true;
		}
	}
	return false;
}

/**
 * The published products a candidate names: a WooCommerce search, then the
 * exact code or title-prefix matches ahead of the rest, three at most.
 * A non-publish or catalog-excluded product is never in the query, so a
 * draft or hidden product cannot be cited.
 *
 * @param string $candidate The code or name.
 * @return array<int, \WP_Post> The matches, exact ones first.
 */
function md_kb_endpoint_pattern_query( string $candidate ): array {
	$query  = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $candidate,
			'posts_per_page' => 10,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- excludes products hidden from the WooCommerce catalog.
			'tax_query'      => md_kb_endpoint_catalog_tax_query(),
		)
	);
	$posts  = is_array( $query->posts ) ? $query->posts : array();
	$exact  = array();
	$prefix = array();
	$needle = mb_strtolower( $candidate );
	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post || md_kb_endpoint_is_catalog_excluded( (int) $post->ID ) ) {
			continue;
		}
		$title = mb_strtolower( $post->post_title );
		$sku   = mb_strtolower( md_kb_endpoint_product_sku( (int) $post->ID ) );
		$slug  = mb_strtolower( $post->post_name );
		if ( $sku === $needle || $slug === $needle || $title === $needle ) {
			$exact[] = $post;
		} elseif ( md_kb_endpoint_pattern_title_prefix_allowed( $candidate ) && str_starts_with( $title, $needle ) ) {
			$prefix[] = $post;
		}
	}
	// An exact SKU, slug or full title is the pattern. A title prefix is
	// only a name match when the candidate itself is more than one word:
	// a single token such as "long" or "square" is ordinary English, not a
	// pattern name, even when a title starts with it.
	$ranked = array() !== $exact ? $exact : $prefix;
	return array_slice( $ranked, 0, MD_KB_ENDPOINT_PATTERN_LIMIT );
}

/**
 * Whether a candidate may match a product by title prefix. A single token
 * may only match an exact SKU, an exact slug or the full title. Two or
 * more words can still name the pattern the title starts with.
 *
 * @param string $candidate The code or name.
 * @return bool Whether a title-prefix hit is a clear identification.
 */
function md_kb_endpoint_pattern_title_prefix_allowed( string $candidate ): bool {
	return 1 === preg_match( '/\S+\s+\S/u', trim( $candidate ) );
}

/**
 * A product's SKU. WooCommerce stores it as post meta; when the product
 * object is loaded, its getter is preferred.
 *
 * @param int $post_id The product.
 * @return string The SKU, '' when the product has none.
 */
function md_kb_endpoint_product_sku( int $post_id ): string {
	if ( function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $post_id );
		if ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
			return (string) $product->get_sku();
		}
	}
	return (string) get_post_meta( $post_id, '_sku', true );
}

/**
 * One field's display value: a list becomes a comma-separated line, an
 * empty value stays empty so the caller can say it cannot be confirmed.
 *
 * @param mixed $value The field's raw value.
 * @return string The display value, '' when empty.
 */
function md_kb_endpoint_pattern_field( mixed $value ): string {
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $part ) {
			if ( is_scalar( $part ) && '' !== trim( (string) $part ) ) {
				$parts[] = trim( (string) $part );
			}
		}
		return implode( ', ', $parts );
	}
	if ( is_scalar( $value ) ) {
		return trim( (string) $value );
	}
	return '';
}

/**
 * The open area as the site shows it: a number with a percent sign, or
 * "On request" when the field is empty or not a number. Never "0".
 *
 * @param mixed $value The field's raw value.
 * @return string The wording.
 */
function md_kb_endpoint_pattern_open_area( mixed $value ): string {
	$text = md_kb_endpoint_pattern_field( $value );
	if ( '' === $text || ! is_numeric( $text ) ) {
		return MD_KB_ENDPOINT_OPEN_AREA_ON_REQUEST;
	}
	$number = (float) $text;
	$digits = floor( $number ) === $number ? 0 : 2;
	return number_format( $number, $digits ) . '%';
}

/**
 * The facts of one published product, as the model may cite them. Empty
 * fields are named as unconfirmed rather than filled in. Price, lead time,
 * MOQ and sample promises are not read.
 *
 * @param \WP_Post $post The product.
 * @return array{id: int, code: string, title: string, context: string, source: string} The facts and their source line.
 */
function md_kb_endpoint_pattern_facts( WP_Post $post ): array {
	$id       = (int) $post->ID;
	$sku      = md_kb_endpoint_product_sku( $id );
	$code     = '' !== $sku ? $sku : $post->post_name;
	$lines    = array(
		'code: ' . $code,
		'title: ' . $post->post_title,
		'material: ' . MD_KB_ENDPOINT_MATERIAL,
		'open_area: ' . md_kb_endpoint_pattern_open_area( get_field( 'open_area', $id ) ),
	);
	$optional = array(
		'visual_density'  => 'visual_density',
		'repeat_behavior' => 'repeat_behavior',
		'motifs'          => 'motifs',
		'best_fit'        => 'best_fit',
		'cad_status'      => 'cad_status',
		'summary'         => 'summary',
	);
	foreach ( $optional as $name => $label ) {
		$value = md_kb_endpoint_pattern_field( get_field( $name, $id ) );
		if ( 'cad_status' === $name ) {
			$value = md_kb_endpoint_cad_status_wording( $value );
		}
		$lines[] = '' !== $value ? $label . ': ' . $value : $label . ': (not confirmed)';
	}
	$terms = get_the_terms( $id, 'product_cat' );
	$names = array();
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			$name = is_object( $term ) ? ( $term->name ?? '' ) : '';
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
			}
		}
	}
	$lines[] = array() !== $names ? 'series: ' . implode( ', ', $names ) : 'series: (not confirmed)';
	$url     = get_permalink( $id );
	$image   = get_the_post_thumbnail_url( $id, 'full' );
	if ( is_string( $url ) && '' !== $url ) {
		$lines[] = 'permalink: ' . $url;
	}
	if ( is_string( $image ) && '' !== $image ) {
		$lines[] = 'image: ' . $image;
	}
	return array(
		'id'      => $id,
		'code'    => $code,
		'title'   => $post->post_title,
		'context' => implode( "\n", $lines ),
		'source'  => trim( 'pattern#' . $id . ' ' . $code ),
	);
}

/**
 * Hosts whose pattern pages may be cited as the page the visitor has open.
 * A client-supplied URL on any other host is ignored.
 */
const MD_KB_ENDPOINT_PAGE_HOSTS = array(
	'metal-divider.com',
	'www.metal-divider.com',
	'staging.metal-divider.com',
);

/**
 * The published pattern at the page the visitor opened the chat on, when
 * that page is one of this site's pattern detail pages. Anything else —
 * a missing field, a foreign host, a non-pattern path, a slug hiding in
 * the query or the fragment, a draft — is no page context. The explicit
 * pattern lookup wins, so the caller does not ask this when the question
 * already named a pattern.
 *
 * @param mixed $page_url The request's page_url, untrusted.
 * @return array{context: string, sources?: array<string>} The compact facts
 *         and the source the answer cites, or an empty context and no source.
 *         `sources` is present only when a published pattern resolved, which
 *         is why the caller tests it with isset() rather than reading it.
 */
function md_kb_endpoint_page_context( mixed $page_url ): array {
	$empty = array( 'context' => '' );
	$slug  = md_kb_endpoint_page_slug( $page_url );
	if ( '' === $slug ) {
		return $empty;
	}
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post instanceof WP_Post ) {
		return $empty;
	}
	if ( 'publish' !== $post->post_status || 'product' !== $post->post_type || md_kb_endpoint_is_catalog_excluded( (int) $post->ID ) ) {
		return $empty;
	}
	$sku  = md_kb_endpoint_product_sku( (int) $post->ID );
	$code = '' !== $sku ? $sku : $post->post_name;
	return array(
		'context' => md_kb_endpoint_page_facts( $post ),
		'sources' => array( 'pattern#' . (int) $post->ID . ' ' . $code ),
	);
}

/**
 * The pattern slug of a page URL this site may trust: an http or https URL
 * (or the protocol-relative form Live Helper Chat stores) on an allowlisted
 * host, whose path is a single-product permalink base — /pattern/<slug>/ (the
 * final base) or /product/<slug>/ (the base staging serves today) — and
 * nothing else. The query and the fragment are never a slug.
 *
 * @param mixed $page_url The request's page_url, untrusted.
 * @return string The slug, or '' when the URL is not a pattern page.
 */
function md_kb_endpoint_page_slug( mixed $page_url ): string {
	if ( ! is_string( $page_url ) ) {
		return '';
	}
	$url = trim( $page_url );
	if ( '' === $url || strlen( $url ) > 2000 ) {
		return '';
	}
	if ( str_starts_with( $url, '//' ) ) {
		$url = 'https:' . $url;
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return '';
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	if ( ! in_array( $host, MD_KB_ENDPOINT_PAGE_HOSTS, true ) ) {
		return '';
	}
	$path = rawurldecode( (string) ( $parts['path'] ?? '' ) );
	if ( ! preg_match( '#^/(?:pattern|product)/([^/]+)/?$#', $path, $match ) ) {
		return '';
	}
	$slug = strtolower( $match[1] );
	if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,190}$/', $slug ) ) {
		return '';
	}
	return $slug;
}

/**
 * The compact facts of the pattern page the visitor has open. The same
 * field reads the explicit lookup uses, minus permalink, series and image,
 * and minus anything commercial. CAD status stays, including when it is
 * unconfirmed, so a drawing question on this page cannot be invented.
 *
 * @param \WP_Post $post The published product.
 * @return string The context block, one fact per line.
 */
function md_kb_endpoint_page_facts( WP_Post $post ): string {
	$id    = (int) $post->ID;
	$sku   = md_kb_endpoint_product_sku( $id );
	$code  = '' !== $sku ? $sku : $post->post_name;
	$lines = array(
		'code: ' . $code,
		'title: ' . $post->post_title,
		'material: ' . MD_KB_ENDPOINT_MATERIAL,
		'open_area: ' . md_kb_endpoint_pattern_open_area( get_field( 'open_area', $id ) ),
	);
	foreach ( array( 'visual_density', 'repeat_behavior', 'motifs', 'best_fit' ) as $name ) {
		$value = md_kb_endpoint_pattern_field( get_field( $name, $id ) );
		if ( '' !== $value ) {
			$lines[] = $name . ': ' . $value;
		}
	}
	// CAD status is included even when empty, so an unverified drawing
	// question on the open page transfers instead of being invented.
	$cad       = md_kb_endpoint_cad_status_wording( md_kb_endpoint_pattern_field( get_field( 'cad_status', $id ) ) );
	$lines[]   = 'cad_status: ' . $cad;
	$permalink = get_permalink( $id );
	if ( is_string( $permalink ) && '' !== $permalink ) {
		$lines[] = 'URL: ' . $permalink;
	}
	return implode( "\n", $lines );
}

/**
 * The published patterns a question names, as context for the model and
 * as the audit-trail source lines. No candidate, or no published match,
 * is an empty lookup — the caller then answers from Rapls as before.
 *
 * @param string $q The visitor's question.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern context.
 */
function md_kb_endpoint_pattern_lookup( string $q ): array {
	$seen    = array();
	$blocks  = array();
	$sources = array();
	foreach ( md_kb_endpoint_pattern_candidates( $q ) as $candidate ) {
		foreach ( md_kb_endpoint_pattern_query( $candidate ) as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$facts             = md_kb_endpoint_pattern_facts( $post );
			$blocks[]          = $facts['context'];
			$sources[]         = $facts['source'];
			if ( count( $blocks ) >= MD_KB_ENDPOINT_PATTERN_LIMIT ) {
				break 2;
			}
		}
	}
	return array(
		'context' => implode( "\n\n", $blocks ),
		'sources' => $sources,
		'hits'    => count( $blocks ),
	);
}

/**
 * Recent conversation turns from Live Helper Chat's lh_msg table when the
 * caller sent chat_id without an explicit history array. Status payloads,
 * the opening greeting, the trailing "Based on:" footer, and a duplicate of
 * the current visitor message are stripped before returning up to six turns
 * in chronological order.
 *
 * @param int    $chat_id The Live Helper Chat id.
 * @param string $q       The current visitor question.
 * @return array<int, string> Chronological history turns, at most six.
 */
function md_kb_endpoint_chat_history( int $chat_id, string $q ): array {
	if ( $chat_id <= 0 ) {
		return array();
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live Helper Chat's message table lives in the same database without a WordPress API wrapper; one bounded read per bot turn.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT user_id, msg FROM lh_msg WHERE chat_id = %d AND (user_id = 0 OR user_id = -2 OR user_id > 0) ORDER BY id DESC LIMIT 8',
			$chat_id
		)
	);
	if ( ! is_array( $rows ) || array() === $rows ) {
		return array();
	}

	$turns          = array();
	$checked_newest = false;
	$needle_q       = mb_strtolower( trim( $q ) );

	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}
		$user_id = isset( $row->user_id ) && is_scalar( $row->user_id ) ? (int) $row->user_id : 0;
		if ( -1 === $user_id ) {
			continue;
		}
		$raw_msg = isset( $row->msg ) && is_scalar( $row->msg ) ? trim( (string) $row->msg ) : '';
		if ( '' === $raw_msg || str_starts_with( $raw_msg, '{' ) ) {
			continue;
		}
		if ( 1 === preg_match( '/^Hi\s*[—–-]\s*I[\'’]?m\s+Metal Divider[\'’]?s AI assistant\b/iu', $raw_msg ) ) {
			continue;
		}
		$clean_msg = trim( (string) preg_replace( '/\n\nBased on:.*$/s', '', $raw_msg ) );
		if ( '' === $clean_msg ) {
			continue;
		}
		if ( 0 === $user_id && ! $checked_newest ) {
			$checked_newest = true;
			if ( mb_strtolower( $clean_msg ) === $needle_q ) {
				continue;
			}
		}
		$turn    = 0 !== $user_id ? 'Assistant: ' . $raw_msg : $clean_msg;
		$turns[] = mb_substr( $turn, 0, 2000 );
	}

	$turns = array_reverse( $turns );
	return array_slice( $turns, -6 );
}

/**
 * The live referrer URL stored on Live Helper Chat's lh_chat row, used as a
 * fallback when the webhook's initial page_url has no pattern slug.
 *
 * @param int $chat_id The Live Helper Chat id.
 * @return string The stored referrer URL, '' when absent.
 */
function md_kb_endpoint_chat_referrer( int $chat_id ): string {
	if ( $chat_id <= 0 ) {
		return '';
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live Helper Chat's chat table lives in the same database without a WordPress API wrapper; one scalar read when page_url has no pattern slug.
	$referrer = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT referrer FROM lh_chat WHERE id = %d',
			$chat_id
		)
	);
	return is_string( $referrer ) ? trim( $referrer ) : '';
}

/**
 * Resolves a published pattern named in an earlier conversation turn (from
 * newest to oldest) when the current follow-up question uses pronouns such
 * as "What is its open area?" instead of repeating the pattern name.
 *
 * For assistant turns, direct references (`/pattern/<slug>/`, `Based on:
 * Pattern <Name>`, or SKU codes) are extracted first so long assistant
 * paragraphs never trigger a 1-6 word n-gram query storm.
 *
 * @param array<int, string> $history Chronological history turns.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern lookup from history.
 */
function md_kb_endpoint_history_pattern_lookup( array $history ): array {
	foreach ( array_reverse( $history ) as $turn ) {
		if ( 1 === preg_match( '/^Assistant:\s*(.*)$/isu', $turn, $assistant_match ) ) {
			$lookup = md_kb_endpoint_assistant_turn_pattern_lookup( $turn, trim( $assistant_match[1] ) );
			if ( 0 < $lookup['hits'] ) {
				return $lookup;
			}
			continue;
		}
		$cleaned = trim( $turn );
		if ( '' === $cleaned ) {
			continue;
		}
		$lookup = md_kb_endpoint_pattern_lookup( $cleaned );
		if ( 0 < $lookup['hits'] ) {
			return $lookup;
		}
	}
	return array(
		'context' => '',
		'sources' => array(),
		'hits'    => 0,
	);
}

/**
 * Extracts pattern candidates from an assistant turn without running
 * multi-word n-grams across long prose paragraphs.
 *
 * @param string $raw_turn     The raw turn string (may include Based on: footer).
 * @param string $cleaned_turn The turn with the Assistant: prefix stripped.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern lookup result.
 */
function md_kb_endpoint_assistant_turn_pattern_lookup( string $raw_turn, string $cleaned_turn ): array {
	$candidates = array();
	if ( preg_match_all( '~/pattern/([a-z0-9-]+)~i', $raw_turn, $slug_matches ) ) {
		foreach ( $slug_matches[1] as $slug ) {
			$candidates[] = strtolower( $slug );
		}
	}
	if ( preg_match_all( '/Based on:\s*([^\n]+)/iu', $raw_turn, $footer_matches ) ) {
		foreach ( $footer_matches[1] as $footer_line ) {
			foreach ( explode( ';', $footer_line ) as $part ) {
				if ( 1 === preg_match( '/^Pattern\s+(.+)$/iu', trim( $part ), $pat_match ) ) {
					$candidates[] = trim( $pat_match[1] );
				}
			}
		}
	}
	if ( preg_match_all( '/\b[A-Z]{1,6}-\d{2,6}\b/i', $cleaned_turn, $code_matches ) ) {
		foreach ( $code_matches[0] as $code ) {
			$candidates[] = $code;
		}
	}
	// When no explicit URL, Based on: footer, or SKU code is present, check
	// capitalized proper-noun tokens (up to 8) without running 1-6 word n-grams.
	if ( array() === $candidates ) {
		if ( preg_match_all( '/\b[A-Z][A-Za-z0-9]{2,}\b/', $cleaned_turn, $cap_matches ) ) {
			$stopwords = md_kb_endpoint_pattern_stopwords();
			foreach ( $cap_matches[0] as $word ) {
				if ( ! in_array( mb_strtolower( $word ), $stopwords, true ) ) {
					$candidates[] = $word;
					if ( count( $candidates ) >= 8 ) {
						break;
					}
				}
			}
		}
	}

	$seen    = array();
	$blocks  = array();
	$sources = array();
	$unique  = array();
	foreach ( $candidates as $candidate ) {
		$key = mb_strtolower( trim( $candidate ) );
		if ( '' !== $key && ! isset( $unique[ $key ] ) ) {
			$unique[ $key ] = trim( $candidate );
		}
	}
	foreach ( $unique as $candidate ) {
		foreach ( md_kb_endpoint_pattern_query( $candidate ) as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$facts             = md_kb_endpoint_pattern_facts( $post );
			$blocks[]          = $facts['context'];
			$sources[]         = $facts['source'];
			if ( count( $blocks ) >= MD_KB_ENDPOINT_PATTERN_LIMIT ) {
				break 2;
			}
		}
	}
	return array(
		'context' => implode( "\n\n", $blocks ),
		'sources' => $sources,
		'hits'    => count( $blocks ),
	);
}

/**
 * Extracts numeric product post IDs from pattern source strings (`pattern#<id> ...`).
 *
 * @param array<int, string> $sources The pattern sources.
 * @return array<int, int> The product post IDs.
 */
function md_kb_endpoint_pattern_source_ids( array $sources ): array {
	$ids = array();
	foreach ( $sources as $source ) {
		if ( 1 === preg_match( '/^pattern#(\d+)\b/', $source, $match ) ) {
			$ids[] = (int) $match[1];
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Augments a recommendation query with the series, motifs, open area and
 * best fit of already-matched reference patterns (e.g., "similar to Apiary").
 *
 * @param string          $q           The visitor's question.
 * @param array<int, int> $matched_ids The matched product IDs.
 * @return string The augmented query string for scoring alternatives.
 */
function md_kb_endpoint_augment_recommendation_query( string $q, array $matched_ids ): string {
	$parts = array( $q );
	foreach ( $matched_ids as $id ) {
		$terms = get_the_terms( $id, 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( is_object( $term ) ) {
					$parts[] = (string) ( $term->slug ?? '' );
					$parts[] = (string) ( $term->name ?? '' );
				}
			}
		}
		$motifs = md_kb_endpoint_pattern_field( get_field( 'motifs', $id ) );
		if ( '' !== $motifs ) {
			$parts[] = $motifs;
		}
		$raw_oa = md_kb_endpoint_pattern_field( get_field( 'open_area', $id ) );
		if ( '' !== $raw_oa && is_numeric( $raw_oa ) && (float) $raw_oa > 0 ) {
			$parts[] = $raw_oa . '%';
		}
		$best_fit = md_kb_endpoint_pattern_field( get_field( 'best_fit', $id ) );
		if ( '' !== $best_fit ) {
			$parts[] = str_replace( '-', ' ', $best_fit );
		}
		$density = md_kb_endpoint_pattern_field( get_field( 'visual_density', $id ) );
		if ( '' !== $density ) {
			$parts[] = $density . ' density';
		}
	}
	return implode( ' ', array_filter( $parts ) );
}

/**
 * Whether the visitor is asking for pattern recommendations or filtering
 * patterns by series, application, visual density or open area range.
 *
 * @param string $q The visitor's question.
 * @return bool Whether to run the catalog recommender.
 */
function md_kb_endpoint_asks_recommendation( string $q ): bool {
	$explicit_recommend = 1 === preg_match(
		'/\b(?:recommend(?:ation|s)?|suggest(?:ion|ions)?|which\s+patterns?|what\s+patterns?|any\s+patterns?|show\s+(?:me\s+)?patterns?|looking\s+for\s+(?:a\s+)?patterns?|patterns?\s+(?:for|with|that|suitable|in|like|similar)|similar\s+(?:patterns?\s+)?to|alternatives?\s+to)\b|推荐|哪(?:款|些|种)图案|什么图案|类似/iu',
		$q
	);
	if ( ! $explicit_recommend && 1 === preg_match( '/\bthis\s+(?:pattern|screen)\b/iu', $q ) ) {
		return false;
	}
	if ( $explicit_recommend ) {
		return true;
	}
	if ( 1 === preg_match( '/\b(?:grade|grades|304|316|316l|material|materials|finish|finishes|pvd|clean|cleaning|maintenance|rust|corrosion|warranty|showroom|contact|whatsapp|email)\b|材质|不锈钢|表面|清洁|保养|展厅|联系/iu', $q ) ) {
		return false;
	}
	return 1 === preg_match(
		'/\b(?:geometric|organic|abstract|moroccan(?:[\s-]+arabic)?|arabic|perforated|gradient|landscape|asian|chinese|vintage(?:[\s-]+retro)?|retro|botanical|floral|mural|emt|expanded\s+metal|glass[\s-]+hybrid|laminated\s+glass|repeat(?:ing)?\s+patterns?|(?:low|medium|balanced|high|open|custom)\s+density|dense\s+patterns?)\b|\b\d{1,2}(?:\.\d+)?\s*(?:%|percent)\b.*\bopen[\s-]*area\b|\bopen[\s-]*area\b.*\b\d{1,2}(?:\.\d+)?\s*(?:%|percent)\b|\b(?:low|high|medium|balanced)\s+open[\s-]*area\b|几何|有机|抽象|摩洛哥|阿拉伯|穿孔|渐变|山水|中式|复古|植物|花卉|壁画|扩张网|夹胶玻璃|低密度|中密度|均衡密度|高密度|定制密度|\d{1,2}\s*%.*镂空|镂空.*\d{1,2}\s*%/iu',
		$q
	) || (
		1 === preg_match( '/\b(?:outdoor|exterior|facade|privacy|partition|divider|interior|indoor|room\s+divider|balustrade|railing|cladding|sunshade|weather|hotel|lobby|architectural[\s-]+screening|decorative[\s-]+screening|repeat)\b|室外|户外|外墙|幕墙|隐私|隔断|屏风|室内|护栏|栏杆|遮阳|酒店|大堂|循环/iu', $q )
		&& 1 === preg_match( '/\b(?:patterns?|designs?|styles?|options?|suitable|fit)\b|图案|款式|适合/iu', $q )
	);
}

/**
 * Recommends up to three published patterns from the live WooCommerce
 * catalog scored against the series, open area percentage, application/best
 * fit, motifs, and visual density criteria in the visitor's question.
 *
 * @param string          $q           The visitor's question.
 * @param array<int, int> $exclude_ids Product IDs to exclude (e.g. a named reference pattern).
 * @return array{context: string, sources: array<int, string>, hits: int} The top matching pattern facts.
 */
function md_kb_endpoint_pattern_recommend( string $q, array $exclude_ids = array() ): array {
	$query = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the full published pattern catalog is 146 items and must be scored in memory.
			'posts_per_page' => 200,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- excludes products hidden from the WooCommerce catalog.
			'tax_query'      => md_kb_endpoint_catalog_tax_query(),
		)
	);
	$posts = is_array( $query->posts ) ? $query->posts : array();
	if ( array() === $posts ) {
		return array(
			'context' => '',
			'sources' => array(),
			'hits'    => 0,
		);
	}

	$scored = array();
	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			continue;
		}
		$id = (int) $post->ID;
		if ( in_array( $id, $exclude_ids, true ) || md_kb_endpoint_is_catalog_excluded( $id ) ) {
			continue;
		}
		$score = md_kb_endpoint_pattern_score( $post, $q );
		if ( $score > 0 ) {
			$scored[] = array(
				'post'  => $post,
				'score' => $score,
				'id'    => $id,
			);
		}
	}

	$is_generic_fallback = false;
	if ( array() === $scored && 1 === preg_match( '/\b(?:recommend(?:ation|s)?|suggest(?:ion|ions)?|which\s+patterns?|what\s+patterns?|any\s+patterns?|show\s+(?:me\s+)?patterns?|looking\s+for\s+(?:a\s+)?patterns?)\b|推荐|哪(?:款|些|种)图案|什么图案/iu', $q ) ) {
		$is_generic_fallback = true;
		$candidates          = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}
			$id = (int) $post->ID;
			if ( in_array( $id, $exclude_ids, true ) || md_kb_endpoint_is_catalog_excluded( $id ) ) {
				continue;
			}
			$raw_oa   = md_kb_endpoint_pattern_field( get_field( 'open_area', $id ) );
			$best_fit = md_kb_endpoint_pattern_field( get_field( 'best_fit', $id ) );
			if ( '' !== $raw_oa && is_numeric( $raw_oa ) && (float) $raw_oa > 0 && '' !== $best_fit ) {
				$candidates[] = array(
					'post'   => $post,
					'score'  => 1,
					'id'     => $id,
					'series' => md_kb_endpoint_primary_series_key( $id ),
				);
			}
		}
		usort(
			$candidates,
			static fn ( array $a, array $b ): int => $a['id'] <=> $b['id']
		);
		$used_series = array();
		$picked_ids  = array();
		foreach ( $candidates as $candidate ) {
			$series = $candidate['series'];
			if ( '' !== $series && ! isset( $used_series[ $series ] ) ) {
				$used_series[ $series ]         = true;
				$picked_ids[ $candidate['id'] ] = true;
				$scored[]                       = $candidate;
				if ( count( $scored ) >= MD_KB_ENDPOINT_PATTERN_LIMIT ) {
					break;
				}
			}
		}
		if ( count( $scored ) < MD_KB_ENDPOINT_PATTERN_LIMIT ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $picked_ids[ $candidate['id'] ] ) ) {
					continue;
				}
				$scored[] = $candidate;
				if ( count( $scored ) >= MD_KB_ENDPOINT_PATTERN_LIMIT ) {
					break;
				}
			}
		}
	} else {
		usort(
			$scored,
			static function ( array $a, array $b ): int {
				if ( $b['score'] !== $a['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				return $a['id'] <=> $b['id'];
			}
		);
	}

	$blocks  = array();
	$sources = array();
	foreach ( array_slice( $scored, 0, MD_KB_ENDPOINT_PATTERN_LIMIT ) as $item ) {
		$facts     = md_kb_endpoint_pattern_facts( $item['post'] );
		$blocks[]  = $facts['context'];
		$sources[] = $facts['source'];
	}

	$context = implode( "\n\n", $blocks );
	if ( $is_generic_fallback && '' !== $context ) {
		$context = "Visitor asked a general recommendation question without specifying interior/exterior or open area. Present these representative examples across series and ask whether their project is interior or exterior and what open-area/privacy level they need:\n\n" . $context;
	}

	return array(
		'context' => $context,
		'sources' => $sources,
		'hits'    => count( $blocks ),
	);
}

/**
 * Primary product_cat series key for diversifying generic fallback recommendations.
 *
 * @param int $post_id The product ID.
 * @return string Lowercase series slug or name, '' when none.
 */
function md_kb_endpoint_primary_series_key( int $post_id ): string {
	$terms = get_the_terms( $post_id, 'product_cat' );
	if ( ! is_array( $terms ) ) {
		return '';
	}
	foreach ( $terms as $term ) {
		if ( ! is_object( $term ) ) {
			continue;
		}
		$slug = mb_strtolower( trim( (string) ( $term->slug ?? '' ) ) );
		if ( '' !== $slug ) {
			return $slug;
		}
		$name = mb_strtolower( trim( (string) ( $term->name ?? '' ) ) );
		if ( '' !== $name ) {
			return $name;
		}
	}
	return '';
}

/**
 * Scores a single published pattern against the series, open area %,
 * application/best fit, motif, and visual density criteria in $q.
 *
 * @param \WP_Post $post The published product.
 * @param string   $q    The visitor's question.
 * @return int The match score (0 when no criterion matched).
 */
function md_kb_endpoint_pattern_score( WP_Post $post, string $q ): int {
	$id      = (int) $post->ID;
	$score   = 0;
	$lower_q = mb_strtolower( $q );

	$terms       = get_the_terms( $id, 'product_cat' );
	$series_text = '';
	if ( is_array( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( is_object( $term ) ) {
				$series_text .= ' ' . mb_strtolower( (string) ( $term->name ?? '' ) . ' ' . (string) ( $term->slug ?? '' ) );
			}
		}
	}

	$motifs    = mb_strtolower( md_kb_endpoint_pattern_field( get_field( 'motifs', $id ) ) );
	$best_fit  = mb_strtolower( md_kb_endpoint_pattern_field( get_field( 'best_fit', $id ) ) );
	$summary   = mb_strtolower( md_kb_endpoint_pattern_field( get_field( 'summary', $id ) ) );
	$density   = mb_strtolower( md_kb_endpoint_pattern_field( get_field( 'visual_density', $id ) ) );
	$repeat    = mb_strtolower( md_kb_endpoint_pattern_field( get_field( 'repeat_behavior', $id ) ) );
	$title     = mb_strtolower( $post->post_title );
	$full_text = str_replace( array( '-', '_' ), ' ', $series_text . ' ' . $motifs . ' ' . $best_fit . ' ' . $summary . ' ' . $repeat . ' ' . $title );
	// Series/motif matching rules.
	$series_rules     = array(
		array(
			'regex'   => '/\bgeometric\b|几何/iu',
			'needles' => array( 'geometric' ),
		),
		array(
			'regex'   => '/\borganic\b|有机/iu',
			'needles' => array( 'organic' ),
		),
		array(
			'regex'   => '/\babstract\b|抽象/iu',
			'needles' => array( 'abstract' ),
		),
		array(
			'regex'   => '/\b(?:moroccan(?:[\s-]+arabic)?|arabic|mashrabiya)\b|摩洛哥|阿拉伯/iu',
			'needles' => array( 'moroccan-arabic', 'moroccan', 'arabic' ),
		),
		array(
			'regex'   => '/\bperforated\b|穿孔/iu',
			'needles' => array( 'perforated' ),
		),
		array(
			'regex'   => '/\bgradient\b|渐变/iu',
			'needles' => array( 'gradient' ),
		),
		array(
			'regex'   => '/\blandscape\b|山水|风景/iu',
			'needles' => array( 'landscape' ),
		),
		array(
			'regex'   => '/\b(?:asian|chinese)\b|中式|亚洲/iu',
			'needles' => array( 'asian', 'chinese' ),
		),
		array(
			'regex'   => '/\b(?:vintage(?:[\s-]+retro)?|retro)\b|复古/iu',
			'needles' => array( 'vintage-retro', 'vintage', 'retro' ),
		),
		array(
			'regex'   => '/\b(?:botanical|floral|flower|leaf|leaves|plant|vine|garden|bamboo)\b|植物|花卉|树叶|竹/iu',
			'needles' => array( 'leaf', 'floral', 'vine', 'garden', 'bamboo', 'botanical', 'organic' ),
		),
		array(
			'regex'   => '/\bmural\b|壁画/iu',
			'needles' => array( 'mural', 'landscape', 'flowing' ),
		),
		array(
			'regex'   => '/\b(?:emt|expanded\s+metal)\b|扩张网|拉伸网/iu',
			'needles' => array( 'emt', 'expanded metal', 'geometric', 'perforated' ),
		),
		array(
			'regex'   => '/\b(?:repeat(?:ing)?)\b|循环|重复/iu',
			'needles' => array( 'repeating', 'repeat', 'tile' ),
		),
		array(
			'regex'   => '/\b(?:glass[\s-]+hybrid|laminated\s+glass)\b|夹胶玻璃|玻璃复合/iu',
			'needles' => array( 'glass-hybrid', 'laminated glass', 'glass', 'decorative-screening' ),
		),
	);
	$structured_text  = $series_text . ' ' . $motifs . ' ' . $repeat . ' ' . $best_fit;
	$requested_series = false;
	$matched_series   = false;
	foreach ( $series_rules as $rule ) {
		if ( 1 !== preg_match( $rule['regex'], $q ) ) {
			continue;
		}
		$requested_series = true;
		foreach ( $rule['needles'] as $term_needle ) {
			if ( str_contains( $structured_text, $term_needle ) ) {
				$score         += 20;
				$matched_series = true;
				break 2;
			}
		}
	}
	if ( $requested_series && ! $matched_series ) {
		$score -= 12;
	}

	$raw_oa = md_kb_endpoint_pattern_field( get_field( 'open_area', $id ) );
	if ( '' !== $raw_oa && is_numeric( $raw_oa ) && (float) $raw_oa > 0 ) {
		$oa = (float) $raw_oa;
		if ( 1 === preg_match( '/(\d{1,2}(?:\.\d+)?)\s*(?:%|percent)?\s*(?:-|to|–|~|到|至)\s*(\d{1,2}(?:\.\d+)?)\s*(?:%|percent|镂空)/iu', $q, $range_match ) ) {
			$min_oa = min( (float) $range_match[1], (float) $range_match[2] );
			$max_oa = max( (float) $range_match[1], (float) $range_match[2] );
			if ( $oa >= $min_oa && $oa <= $max_oa ) {
				$score += 12;
			} elseif ( $oa >= ( $min_oa - 5.0 ) && $oa <= ( $max_oa + 5.0 ) ) {
				$score += 8;
			}
		} elseif ( 1 === preg_match( '/(?:under|below|less\s+than|up\s+to|max(?:imum)?|at\s+most|<=?)\s*(\d{1,2}(?:\.\d+)?)\s*(?:%|percent|镂空)|\b(\d{1,2}(?:\.\d+)?)\s*(?:%|percent)\s*(?:or\s+less|and\s+under|以下|以内)/iu', $q, $upper_match ) ) {
			$bound = '' !== $upper_match[1] ? (float) $upper_match[1] : (float) $upper_match[2];
			if ( $oa <= $bound ) {
				$score += 12;
			} elseif ( $oa <= ( $bound + 3.0 ) ) {
				$score += 6;
			}
		} elseif ( 1 === preg_match( '/(?:over|above|more\s+than|at\s+least|min(?:imum)?|>=?)\s*(\d{1,2}(?:\.\d+)?)\s*(?:%|percent|镂空)|\b(\d{1,2}(?:\.\d+)?)\s*(?:%|percent)\s*(?:or\s+more|and\s+above|以上)/iu', $q, $lower_match ) ) {
			$bound = '' !== $lower_match[1] ? (float) $lower_match[1] : (float) $lower_match[2];
			if ( $oa >= $bound ) {
				$score += 12;
			} elseif ( $oa >= ( $bound - 3.0 ) ) {
				$score += 6;
			}
		} elseif ( 1 === preg_match( '/(\d{1,2}(?:\.\d+)?)\s*(?:%|percent)/iu', $q, $single_match ) ) {
			$target_oa = (float) $single_match[1];
			$diff      = abs( $oa - $target_oa );
			if ( $diff <= 5.0 ) {
				$score += 12;
			} elseif ( $diff <= 12.0 ) {
				$score += 8;
			}
		}
	}

	$asks_outdoor = 1 === preg_match(
		'/\b(?:outdoor|exterior|facade|cladding|sunshade|balustrade|railing|weather)\b|室外|户外|外墙|幕墙|遮阳|护栏|栏杆/iu',
		$q
	);
	$asks_indoor  = 1 === preg_match(
		'/\b(?:indoor|interior|room\s+divider|partition|divider|lobby)\b|室内|隔断|屏风|大堂/iu',
		$q
	);
	if ( $asks_outdoor ) {
		if ( 'architectural-screening' === $best_fit ) {
			$score += 8;
		} elseif ( 'decorative-screening' === $best_fit ) {
			$score -= 5;
		}
	} elseif ( $asks_indoor && 'decorative-screening' === $best_fit ) {
		$score += 5;
	}

	$keyword_map = array(
		'privacy'       => array( 'privacy', 'private', '隐私' ),
		'facade'        => array( 'facade', 'exterior', 'outdoor', 'cladding', 'sunshade', 'architectural', '室外', '户外', '外墙', '幕墙', '遮阳' ),
		'architectural' => array( 'architectural', 'facade', 'exterior', 'outdoor', 'cladding', 'sunshade', 'balustrade', 'railing', '室外', '户外', '外墙', '幕墙', '护栏', '栏杆' ),
		'decorative'    => array( 'decorative', 'interior', 'partition', 'divider', 'hotel', 'lobby', '室内', '隔断', '屏风', '酒店', '大堂' ),
		'interior'      => array( 'interior', 'partition', 'divider', 'hotel', 'lobby', '室内', '隔断', '屏风', '酒店', '大堂' ),
		'partition'     => array( 'partition', 'divider', '隔断', '屏风' ),
		'divider'       => array( 'divider', 'partition', '隔断', '屏风' ),
		'botanical'     => array( 'botanical', 'floral', 'leaf', 'plant', '植物', '花卉' ),
		'floral'        => array( 'floral', 'flower', 'blossom', '花卉' ),
		'leaf'          => array( 'leaf', 'leaves', 'botanical', '植物' ),
		'wave'          => array( 'wave', 'ripple', 'ocean', '波浪' ),
		'lattice'       => array( 'lattice', 'grid', '格栅' ),
		'arch'          => array( 'arch', 'arched', '拱' ),
		'geometric'     => array( 'geometric', '几何' ),
		'organic'       => array( 'organic', '有机' ),
		'modern'        => array( 'modern', '现代' ),
		'classic'       => array( 'classic', 'traditional', '经典', '传统' ),
	);
	foreach ( $keyword_map as $product_token => $query_triggers ) {
		if ( ! str_contains( $full_text, $product_token ) ) {
			continue;
		}
		foreach ( $query_triggers as $trigger ) {
			if ( str_contains( $lower_q, $trigger ) ) {
				$score += 3;
				break;
			}
		}
	}

	$density_map = array(
		'low'      => '/\b(?:low|open)\s+density\b|低密度/iu',
		'open'     => '/\b(?:low|open)\s+density\b|低密度/iu',
		'medium'   => '/\b(?:medium|balanced)\s+density\b|中密度|均衡密度/iu',
		'balanced' => '/\b(?:medium|balanced)\s+density\b|中密度|均衡密度/iu',
		'high'     => '/\b(?:high\s+density|dense)\b|高密度/iu',
		'dense'    => '/\b(?:high\s+density|dense)\b|高密度/iu',
		'custom'   => '/\bcustom\s+density\b|定制密度/iu',
	);
	if ( '' !== $density && isset( $density_map[ $density ] ) && 1 === preg_match( $density_map[ $density ], $q ) ) {
		$score += 2;
	}

	return max( 0, $score );
}

/**
 * Whitelist-validated table name for the knowledge tables, using the
 * plugin's own validator when active and the identical prefix whitelist
 * when the plugin is deactivated on production.
 *
 * @param string $suffix The table suffix without prefix.
 * @return string The backtick-quoted table name, '' when not whitelisted.
 */
function md_kb_endpoint_validated_table( string $suffix ): string {
	if ( function_exists( 'raplsaich_validated_table' ) ) {
		return raplsaich_validated_table( $suffix );
	}
	$allowed = array( 'raplsaich_index', 'raplsaich_knowledge' );
	if ( ! in_array( $suffix, $allowed, true ) ) {
		return '';
	}
	global $wpdb;
	if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) ) {
		return '';
	}
	return '`' . $wpdb->prefix . $suffix . '`';
}

/**
 * Search the knowledge for the question: the knowledge plugin's own engine
 * when active, or direct query against the knowledge table when the plugin
 * is deactivated on production, returning up to five hits and the context.
 *
 * @param string $q The visitor's question.
 * @return array{0: array<int, array<string, mixed>>, 1: string} The search results, and the built context.
 */
function md_kb_endpoint_retrieve( string $q ): array {
	if ( ! class_exists( 'RAPLSAICH_Search_Engine' ) ) {
		return md_kb_endpoint_retrieve_from_db( $q );
	}
	$engine = new RAPLSAICH_Search_Engine();
	$engine->set_bot_filters( array(), true, true );
	$results = $engine->search( $q, 5 );
	// Pattern facts are answered by the live WooCommerce lookup above. The
	// knowledge plugin also crawls product pages into its own index; letting
	// those rows through would recreate the second source #102 rejected, so
	// the endpoint never feeds product results to the model.
	$results = array_values(
		array_filter(
			$results,
			static function ( array $result ): bool {
				return 'product' !== ( $result['type'] ?? '' ) && 'product' !== ( $result['post_type'] ?? '' );
			}
		)
	);
	$context = $engine->build_context( $results, 20000, $q );
	return array( $results, $context );
}

/**
 * Direct retrieval from the knowledge table when RAPLSAICH_Search_Engine is
 * not loaded at runtime (production keeps the plugin deactivated so its
 * duplicate frontend widget never renders).
 *
 * @param string $q The visitor's question.
 * @return array{0: array<int, array<string, mixed>>, 1: string} The search results, and the built context.
 */
function md_kb_endpoint_retrieve_from_db( string $q ): array {
	global $wpdb;
	$knowledge = md_kb_endpoint_validated_table( 'raplsaich_knowledge' );
	if ( '' === $knowledge || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return array( array(), '' );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name comes from md_kb_endpoint_validated_table whitelist.
	$rows = $wpdb->get_results(
		"SELECT id, title, content FROM {$knowledge} WHERE is_active = 1 ORDER BY id ASC LIMIT 50"
	);
	// phpcs:enable
	if ( ! is_array( $rows ) || array() === $rows ) {
		return array( array(), '' );
	}

	$raw_parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $q ) );
	$tokens    = array_values(
		array_unique(
			array_filter(
				is_array( $raw_parts ) ? $raw_parts : array(),
				static fn ( string $token ): bool => mb_strlen( $token ) >= 2
			)
		)
	);
	$stopwords = array(
		'the',
		'and',
		'for',
		'are',
		'you',
		'what',
		'how',
		'can',
		'with',
		'this',
		'that',
		'have',
		'from',
		'your',
		'our',
		'about',
		'does',
		'will',
		'would',
		'could',
		'please',
		'tell',
		'do',
		'is',
		'in',
		'to',
		'of',
		'on',
		'at',
		'by',
		'or',
		'be',
		'as',
		'it',
		'we',
		'my',
		'me',
		'an',
		'so',
		'up',
		'if',
		'no',
		'us',
	);
	$tokens    = array_values( array_diff( $tokens, $stopwords ) );

	$scored = array();
	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}
		$id      = (int) ( $row->id ?? 0 );
		$title   = trim( (string) ( $row->title ?? '' ) );
		$content = trim( (string) ( $row->content ?? '' ) );
		if ( 0 >= $id || ( '' === $title && '' === $content ) ) {
			continue;
		}
		$score          = 0.0;
		$matched_tokens = 0;
		foreach ( $tokens as $token ) {
			$token_hit = false;
			if ( '' !== $title && false !== mb_stripos( $title, $token ) ) {
				$score    += 6.0;
				$token_hit = true;
			}
			if ( '' !== $content && false !== mb_stripos( $content, $token ) ) {
				$score    += 2.0;
				$token_hit = true;
			}
			if ( $token_hit ) {
				++$matched_tokens;
			}
		}
		if ( $matched_tokens >= 2 ) {
			$score += 4.0 * ( $matched_tokens - 1 );
		}
		if ( $score > 0.0 ) {
			$scored[] = array(
				'score' => $score,
				'id'    => $id,
				'hit'   => array(
					'type'      => 'knowledge',
					'source_id' => $id,
					'title'     => $title,
					'content'   => $content,
					'url'       => null,
					'score'     => $score,
				),
			);
		}
	}

	usort(
		$scored,
		static function ( array $a, array $b ): int {
			if ( $b['score'] !== $a['score'] ) {
				return $b['score'] <=> $a['score'];
			}
			return $a['id'] <=> $b['id'];
		}
	);

	$results   = array();
	$blocks    = array();
	$top_score = isset( $scored[0]['score'] ) ? (float) $scored[0]['score'] : 0.0;
	foreach ( array_slice( $scored, 0, 5 ) as $entry ) {
		if ( $top_score > 0.0 && $entry['score'] < $top_score * 0.6 ) {
			continue;
		}
		$hit       = $entry['hit'];
		$results[] = $hit;
		$blocks[]  = '### ' . $hit['title'] . "\n" . $hit['content'];
	}

	$context = mb_substr( implode( "\n\n", $blocks ), 0, 20000 );
	return array( $results, $context );
}

/**
 * Whether the knowledge base holds anything to answer from: the whole
 * library, not this question's hits — a question nothing matches still
 * reaches the model (which says it has nothing and hands off), while a
 * base with no crawl index row and no active knowledge entry would leave
 * the model answering from nothing at all. The counts go through the
 * whitelist-validated table names, and a knowledge table too old for
 * is_active counts its rows unfiltered.
 *
 * @return bool True when neither knowledge source holds a row.
 */
function md_kb_endpoint_knowledge_is_empty(): bool {
	global $wpdb;

	$index     = md_kb_endpoint_validated_table( 'raplsaich_index' );
	$knowledge = md_kb_endpoint_validated_table( 'raplsaich_knowledge' );
	if ( '' === $index || '' === $knowledge ) {
		return true;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table names come from md_kb_endpoint_validated_table's whitelist; one count per request, no object cache to bypass.
	if ( 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" ) ) {
		return false;
	}

	$active = $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge} WHERE is_active = 1" );
	if ( null === $active ) {
		// A table older than the active flag: every row is offered, which
		// is how the plugin itself searches that schema.
		$active = $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge}" );
	}
	// phpcs:enable
	return 0 === (int) $active;
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
 * The visitor-safe labels of the sources an answer used: the internal
 * knowledge/pattern/post id is stripped, patterns are named as patterns,
 * and repeats collapse. No internal id reaches the visitor.
 *
 * @param array<int, string> $sources The internal source lines.
 * @return array<int, string> The visitor-facing labels, at most three.
 */
function md_kb_endpoint_source_labels( array $sources ): array {
	$labels = array();
	foreach ( $sources as $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			continue;
		}
		$label = $source;
		if ( 1 === preg_match( '/^([a-z_]+)#\d+\s+(.+)$/iu', $source, $match ) ) {
			$label = trim( $match[2] );
			if ( 'pattern' === strtolower( $match[1] ) ) {
				$label = 'Pattern ' . $label;
			}
		}
		$label = trim( (string) preg_replace( '/[a-z_]+#\d+/iu', '', $label ) );
		$label = trim( (string) preg_replace( '/\s+/u', ' ', $label ) );
		if ( '' === $label ) {
			continue;
		}
		$labels[ mb_strtolower( $label ) ] = $label;
	}
	return array_slice( array_values( $labels ), 0, 3 );
}

/**
 * The "Based on:" footer the visitor sees. The internal source ids stay in
 * the response's sources field for acceptance; the chat shows only titles.
 *
 * @param array<int, string> $sources The internal source lines or plain labels.
 * @return string The footer, '' when there is no label.
 */
function md_kb_endpoint_source_footer( array $sources ): string {
	$labels = md_kb_endpoint_source_labels( $sources );
	if ( array() === $labels ) {
		return '';
	}
	return "\n\nBased on: " . implode( '; ', $labels );
}

/**
 * The system prompt the model answers under: only the knowledge provided,
 * no inventions, the known part kept when the rest must be handed off, and
 * the two handoff triggers of the MVP — an explicit ask for a human, and
 * the model's own judgment that it cannot answer.
 *
 * @return string The system prompt.
 */
function md_kb_endpoint_system_prompt(): string {
	$no_answer = md_kb_endpoint_no_answer_message();
	return 'You are the support assistant of metal-divider.com, a B2B architectural metal screen pattern inquiry site. '
		. "Answer the visitor's question ONLY from the knowledge provided in the user message. "
		. 'Pattern facts, when present, are the current published product and outrank any other note about the same pattern. '
		. 'A field marked not confirmed, or an open area of On request, must be said that way — never as zero, and never invented. '
		. 'Do not state a price, a lead time, a minimum order or a sample promise. '
		. 'Do not invent specifications or materials. '
		. 'Never mention knowledge, documents or context to the visitor — the visitor sees only your answer. '
		. 'Say pattern, not product. '
		. 'Mention only the facts the visitor asked about; do not list the rest of the pattern record. '
		. 'When PATTERN FACTS are provided for a recommendation question, present all provided candidate patterns (including close open-area or application matches, noting each pattern\'s published open area and best fit so the visitor can compare) with their short pattern name (strip any trailing " | Architectural Metal Screen Pattern" suffix) linked as [Pattern Name](permalink) (when permalink is present in the facts) without inventing any extra attributes, and keep handoff false unless the visitor also asks for a human or asks for unconfirmed specifics like price, lead time, shipping, or CAD drawings. '
		. 'Never quote an internal CAD status such as gated or missing — say the drawing is not confirmed. '
		. 'When part of the question is covered by the knowledge and part is not, answer the covered part and then say the remaining part is being checked with the team. '
		. 'Set handoff true and reason to "no_knowledge". Do not discard the known fact, and do not invent the missing part. '
		. 'A ship date and whether an unverified CAD drawing can be sent are never facts you may invent. '
		. 'Do not say a CAD drawing is available or ready unless the pattern facts say the CAD status is available, ready, published, or yes. '
		. 'When none of the question is covered, set handoff true, set reason to "no_knowledge", and reply exactly: "' . $no_answer . '". '
		. 'When the visitor asks for a human or an agent, set handoff true, set reason to "human_request", and reply that you are connecting them with the team — do not use the no-knowledge sentence. '
		. 'Reply with json only: {"answer": "<text for the visitor>", "handoff": true|false, "reason": "no_knowledge"|"human_request"|"none"}.';
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
		if ( 1 === preg_match( '/^Assistant:\s*(.*)$/isu', $turn, $assistant_match ) ) {
			$clean_assistant = trim( (string) preg_replace( '/\n\nBased on:.*$/s', '', $assistant_match[1] ) );
			if ( '' !== $clean_assistant ) {
				$messages[] = array(
					'role'    => 'assistant',
					'content' => $clean_assistant,
				);
			}
		} else {
			$messages[] = array(
				'role'    => 'user',
				'content' => $turn,
			);
		}
	}
	$messages[] = array(
		'role'    => 'user',
		'content' => 'KNOWLEDGE:' . "\n" . ( '' !== $context ? $context : '(no knowledge found)' ) . "\n\n" . 'QUESTION: ' . $q,
	);
	return $messages;
}

/**
 * Ask DeepSeek's official chat completions for the answer, without
 * streaming, inside one shared deadline: the whole model call — attempts
 * included — gets the 45 seconds the research budgeted (the search and
 * the generation together, against the LHC bot's 60-second execution
 * limit), so a retry asks for what the first attempt left of the budget,
 * and a deadline with nothing left in it buys no second request. The
 * failures a second sample can fix — a transport error, a 429, a 5xx —
 * get exactly one retry; the upstream's considered answers (any other
 * 4xx, a 200 that is not the contract) are asked once. A failure that
 * survives is written to the server's log without the key, the question
 * or the visitor's data, and the caller gets the error code for the 502
 * instead of the answer.
 *
 * @param array<int, array{role: string, content: string}> $messages The conversation.
 * @param float                                            $deadline The Unix microtime the call must end by; 0.0 starts a 45-second one now.
 * @return array{answer: string, handoff: bool, reason: string}|string The parsed reply, or the error code.
 */
function md_kb_endpoint_call_model( array $messages, float $deadline = 0.0 ): array|string {
	if ( $deadline <= 0.0 ) {
		$deadline = microtime( true ) + 45.0;
	}

	$attempts    = 0;
	$last_status = 0;
	while ( true ) {
		++$attempts;
		$model    = 1 < $attempts && 503 === $last_status ? 'deepseek-v4-pro' : 'deepseek-flash';
		$response = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'timeout' => md_kb_endpoint_attempt_timeout( $deadline, microtime( true ) ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . md_kb_endpoint_setting( 'deepseek_api_key' ),
				),
				'body'    => (string) wp_json_encode(
					array(
						'model'           => $model,
						'messages'        => $messages,
						'stream'          => false,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$kind        = 'network_error';
			$status      = 0;
			$last_status = 0;
			$retryable   = true;
		} else {
			$status      = (int) wp_remote_retrieve_response_code( $response );
			$last_status = $status;
			if ( 200 === $status ) {
				$body    = json_decode( wp_remote_retrieve_body( $response ), true );
				$content = $body['choices'][0]['message']['content'] ?? null;
				if ( is_string( $content ) ) {
					$reply = md_kb_endpoint_parse_model_json( $content );
					if ( null !== $reply ) {
						return $reply;
					}
				}
				// A reply that is not the contract is not retried: the upstream
				// answered, and a second sample of a wrong-shaped answer buys
				// nothing but another visitor-facing wait.
				$kind      = 'bad_reply';
				$retryable = false;
			} else {
				$kind      = 'http_error';
				$retryable = 429 === $status || $status >= 500;
			}
		}

		if ( $retryable && 1 === $attempts && md_kb_endpoint_time_for_another_attempt( $deadline, microtime( true ) ) ) {
			continue;
		}
		md_kb_endpoint_log_model_failure( $kind, $status, $attempts );
		return 'upstream_error';
	}
}

/**
 * The timeout one attempt may ask for: the deadline's remainder, in whole
 * milliseconds — never more than the shared budget, and never a number so
 * small the HTTP layer would treat it as none.
 *
 * @param float $deadline The Unix microtime the call must end by.
 * @param float $now      The Unix microtime it is.
 * @return float The timeout in seconds, at least a millisecond.
 */
function md_kb_endpoint_attempt_timeout( float $deadline, float $now ): float {
	return round( max( 0.001, $deadline - $now ), 3 );
}

/**
 * Whether the deadline still holds enough time for another attempt: a
 * second request needs a second to be worth sending at all — a model
 * roundtrip does not fit in the budget's last sliver.
 *
 * @param float $deadline The Unix microtime the call must end by.
 * @param float $now      The Unix microtime it is.
 * @return bool True when another attempt fits.
 */
function md_kb_endpoint_time_for_another_attempt( float $deadline, float $now ): bool {
	return ( $deadline - $now ) >= 1.0;
}

/**
 * Write a model-call failure to the server's error log: the site's rotating
 * php.log, where md-error-recorder points error_log. The line carries the
 * failure's kind and HTTP status only — it structurally cannot name the
 * key, the question or the visitor's data, which this function is never
 * told.
 *
 * @param string $kind     The failure's kind: network_error, http_error or bad_reply.
 * @param int    $status   The HTTP status, 0 for a transport error.
 * @param int    $attempts How many attempts were made.
 * @return void
 */
function md_kb_endpoint_log_model_failure( string $kind, int $status, int $attempts ): void {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the site's own error log channel, recorded the way md-error-recorder configures it; see the docblock for what the line deliberately leaves out.
	error_log( md_kb_endpoint_failure_line( $kind, $status, $attempts ) );
}

/**
 * The log line of one model-call failure: kind, HTTP status, attempts —
 * and nothing else.
 *
 * @param string $kind     The failure's kind.
 * @param int    $status   The HTTP status, 0 for a transport error.
 * @param int    $attempts How many attempts were made.
 * @return string The line.
 */
function md_kb_endpoint_failure_line( string $kind, int $status, int $attempts ): string {
	return sprintf( '[md-kb-endpoint] model call failed after %d attempt(s): %s http=%d', $attempts, $kind, $status );
}

/**
 * The model's reply, from its content to the endpoint's contract: the JSON
 * object is decoded wherever it sits in the text — a fence or a sentence
 * around it is tolerated, the object itself is required, with a non-empty
 * answer and a boolean handoff.
 *
 * @param string $content The reply's content.
 * @return array{answer: string, handoff: bool, reason: string}|null The reply, or null when it is not one.
 */
function md_kb_endpoint_parse_model_json( string $content ): ?array {
	$decoded = json_decode( $content, true );
	if ( ! is_array( $decoded ) && 1 === preg_match( '/\{.*\}/s', $content, $matches ) ) {
		$decoded = json_decode( $matches[0], true );
	}
	if ( ! is_array( $decoded ) || ! is_string( $decoded['answer'] ?? null ) || '' === trim( $decoded['answer'] ) ) {
		return null;
	}
	$reason = is_string( $decoded['reason'] ?? null ) ? strtolower( trim( $decoded['reason'] ) ) : '';
	if ( ! in_array( $reason, array( 'no_knowledge', 'human_request', 'none' ), true ) ) {
		$reason = '';
	}
	return array(
		'answer'  => $decoded['answer'],
		'handoff' => (bool) ( $decoded['handoff'] ?? false ),
		'reason'  => $reason,
	);
}

/**
 * The sentence the model answers with when the knowledge cannot cover the
 * question. It is also the promised handoff copy, so the endpoint and the
 * prompt read it from one place.
 *
 * @return string The no-knowledge sentence.
 */
function md_kb_endpoint_no_answer_message(): string {
	return "I don't have that information — our team can help.";
}

/**
 * Whether a handed-off answer is a knowledge gap rather than an explicit
 * request for a human. The model names its reason in the reply; a reply
 * from an older prompt (no reason) falls back to the exact no-knowledge
 * sentence, which the prompt reserves for this case. A paraphrase of that
 * sentence is why the reason field exists: knowledge gaps must not depend
 * on the model reproducing the copy verbatim.
 *
 * @param array{answer: string, handoff: bool, reason: string} $answer The model's reply.
 * @return bool Whether the visitor asked something the knowledge cannot answer.
 */
function md_kb_endpoint_is_knowledge_gap( array $answer ): bool {
	if ( 'no_knowledge' === $answer['reason'] ) {
		return true;
	}
	if ( '' !== $answer['reason'] ) {
		return false;
	}
	return trim( $answer['answer'] ) === md_kb_endpoint_no_answer_message();
}

/**
 * Record a question the knowledge could not answer in Rapls's own
 * unanswered-question option — the same shape, merge and cap as the
 * plugin's private writer (includes/api/class-rest-controller.php:3769):
 * tags stripped, trimmed, 200 characters at most, a case-insensitive
 * merge that increments the count and moves the entry to the front, and
 * fifty entries at most. The endpoint cannot call that private method,
 * and does not change Rapls.
 *
 * A write failure is logged (the question itself never is) and returns
 * false; the caller's handoff is unaffected, so the visitor still reaches
 * the team.
 *
 * @param string $question The visitor's question.
 * @param string $reason   Why it was unanswered: no_context or grounding.
 * @return bool True when the entry is stored (or the filter disabled the log), false on failure.
 */
function md_kb_endpoint_log_unanswered( string $question, string $reason ): bool {
	$question = trim( wp_strip_all_tags( $question ) );
	if ( '' === $question ) {
		return false;
	}
	if ( mb_strlen( $question ) > 200 ) {
		$question = mb_substr( $question, 0, 200 );
	}

	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rapls's own filter name, kept so its existing opt-out keeps working.
	/**
	 * Filters whether the question is written to Rapls's unanswered log.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $log      Whether to log the question.
	 * @param string $question The visitor's question.
	 * @param string $reason   The gap reason.
	 */
	if ( ! apply_filters( 'raplsaich_log_unanswered', true, $question, $reason ) ) {
		return true;
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

	$log = get_option( 'raplsaich_unanswered_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$needle = mb_strtolower( $question );
	$index  = null;
	foreach ( $log as $i => $entry ) {
		if ( is_array( $entry ) && mb_strtolower( (string) ( $entry['q'] ?? '' ) ) === $needle ) {
			$index = $i;
			break;
		}
	}

	if ( null !== $index ) {
		$entry = $log[ $index ];
		unset( $log[ $index ] );
		$entry['n']      = (int) ( $entry['n'] ?? 1 ) + 1;
		$entry['t']      = time();
		$entry['reason'] = $reason;
		array_unshift( $log, $entry );
	} else {
		array_unshift(
			$log,
			array(
				'q'      => $question,
				't'      => time(),
				'n'      => 1,
				'reason' => $reason,
			)
		);
	}

	$log     = array_slice( array_values( $log ), 0, 50 );
	$written = update_option( 'raplsaich_unanswered_log', $log, false );
	if ( false === $written ) {
		// update_option() returns false both for a failed write and for a
		// value that did not change; only the first is a failure. The
		// second attempt covers a transient database error, the site's
		// retry-and-report rule for a write the visitor's handoff depends on.
		if ( get_option( 'raplsaich_unanswered_log', array() ) !== $log ) {
			$written = update_option( 'raplsaich_unanswered_log', $log, false );
		} else {
			$written = true;
		}
	}
	if ( false === $written ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the site's own error log channel; the line carries the reason only, never the visitor's question.
		error_log( sprintf( '[md-kb-endpoint] unanswered log write failed: reason=%s', $reason ) );
		return false;
	}
	return true;
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
	return "Sorry — I can't look that up right now. Please try again in a moment, or reach our team on WhatsApp at +852 6473 1687.";
}

/**
 * The client's address, as the transport reports it: REMOTE_ADDR, never a
 * forwarded header — those are visitor-supplied, and a rate limiter that
 * read them would let an attacker choose whose window to fill (or dodge
 * its own).
 *
 * @return string The address, '' when the transport reports none.
 */
function md_kb_endpoint_client_ip(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR has no slashes to strip, and FILTER_VALIDATE_IP is the whole validation: an address that fails it is not an address.
	$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
}

/**
 * The store key of one address's failure window: a digest of the address
 * keyed by the site's bearer token, so the counter never becomes a log of
 * who asked — an attacker reading the store learns neither the addresses
 * nor anything that verifies across token rotations.
 *
 * @return string The transient name.
 */
function md_kb_endpoint_failure_key(): string {
	$ip     = md_kb_endpoint_client_ip();
	$secret = md_kb_endpoint_setting( 'bearer_token' );
	$digest = '' !== $secret ? hash_hmac( 'sha256', $ip, $secret ) : hash( 'sha256', $ip );
	return 'md_kb_endpoint_failures_' . $digest;
}

/**
 * Refuse an unauthenticated request, through the brute-force gate: the
 * address's authentication failures of the last minute are counted in a
 * sliding window, ten of them and the eleventh is answered without another
 * hash comparison — the 429 the contract's shape carries, with the
 * fallback message a visitor can be shown. Valid requests never touch this
 * path: the gate counts failures to guess the token, not the conversation
 * the token serves.
 *
 * @return array{status: int, body: array<string, mixed>} The response.
 */
function md_kb_endpoint_refuse_auth_failure(): array {
	$key      = md_kb_endpoint_failure_key();
	$now      = time();
	$failures = array_values(
		array_filter(
			(array) get_transient( $key ),
			static fn ( mixed $at ): bool => is_int( $at ) && $at > $now - 60
		)
	);

	if ( count( $failures ) >= 10 ) {
		return array(
			'status' => 429,
			'body'   => array(
				'error' => array( 'code' => 'rate_limited' ),
				'msg'   => md_kb_endpoint_fallback_message(),
			),
		);
	}

	$failures[] = $now;
	set_transient( $key, $failures, 60 );

	return array(
		'status' => 401,
		'body'   => array( 'error' => array( 'code' => 'unauthorized' ) ),
	);
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
 * Tunes cURL options for the upstream DeepSeek completion call so a stalled
 * edge socket fails fast within 15s and leaves budget for the retry attempt.
 *
 * @param mixed                $handle      The cURL handle.
 * @param array<string, mixed> $parsed_args The HTTP request arguments.
 * @param string               $url         The request URL.
 * @return void
 */
function md_kb_endpoint_tune_curl( mixed $handle, array $parsed_args, string $url ): void {
	unset( $parsed_args );
	if ( 'https://api.deepseek.com/chat/completions' !== $url || ! function_exists( 'curl_setopt' ) ) {
		return;
	}
	if ( ! $handle instanceof \CurlHandle ) {
		return;
	}
	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress's own http_api_curl hook passes the active cURL handle for transport tuning.
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 );
	curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 1 );
	curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, 15 );
	if ( defined( 'CURL_IPRESOLVE_V4' ) ) {
		curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
	}
	// phpcs:enable
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
	global $wpdb;
	if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
		// Hostinger shared MySQL defaults wait_timeout to 20s; raise the session
		// timeout so the connection survives a slow upstream model call.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- session variable adjustment for the current connection.
		$wpdb->query( 'SET SESSION wait_timeout = 120, SESSION interactive_timeout = 120' );
	}
	if ( function_exists( 'add_action' ) ) {
		add_action( 'http_api_curl', 'md_kb_endpoint_tune_curl', 10, 3 );
	}
	$out = md_kb_endpoint_answer(
		array(
			'header' => (string) $request->get_header( 'Authorization' ),
			'body'   => (array) $request->get_json_params(),
		)
	);
	return new \WP_REST_Response( $out['body'], $out['status'] );
}
