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
 * Runs a callback inside WPML's 'en' language context, restoring the
 * previous language on exit even if an exception occurs.
 *
 * @template T
 * @param callable(): T $callback The operation to run.
 * @return T The callback result.
 */
function md_kb_endpoint_with_en_language( callable $callback ): mixed {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard filter.
	$prev_lang = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_current_language', null ) : null;
	if ( function_exists( 'do_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard action.
		do_action( 'wpml_switch_language', 'en' );
	}
	try {
		return $callback();
	} finally {
		if ( null !== $prev_lang && function_exists( 'do_action' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard action.
			do_action( 'wpml_switch_language', $prev_lang );
		}
	}
}

/**
 * Resolves a product post ID to its canonical 'en' product ID.
 *
 * @param int $post_id The product post ID.
 * @return int The canonical EN product ID.
 */
function md_kb_endpoint_canonical_product_id( int $post_id ): int {
	if ( $post_id <= 0 ) {
		return 0;
	}
	if ( function_exists( 'apply_filters' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard filter.
		$en_id = (int) apply_filters( 'wpml_object_id', $post_id, 'product', true, 'en' );
		if ( $en_id > 0 ) {
			return $en_id;
		}
	}
	return $post_id;
}

/**
 * Detect the canonical locale code ('en', 'zh', 'es', 'pt-br', 'de', 'fr',
 * 'ar', 'id', 'vi') from visitor message text first (native script or strong
 * language markers), falling back to the page URL path prefix, default 'en'.
 *
 * @param string $page_url  The page URL or referrer where the chat is open.
 * @param string $user_text The visitor's message text.
 * @return string The canonical locale code (default 'en').
 */
function md_kb_endpoint_detect_locale( string $page_url, string $user_text ): string {
	return md_kb_endpoint_detect_language( $user_text, $page_url, '' );
}

/**
 * Detect the visitor's canonical language code ('en', 'zh', 'es', 'pt-br',
 * 'de', 'fr', 'ar', 'id', 'vi') from question content, explicit parameter,
 * or page URL path prefix.
 *
 * @param string $question      The visitor's question text.
 * @param string $page_url      The page URL the question was asked on.
 * @param string $explicit_lang Optional client-supplied language code.
 * @return string The canonical locale code (default 'en').
 */
function md_kb_endpoint_detect_language( string $question, string $page_url = '', string $explicit_lang = '' ): string {
	$normalized_explicit = '';
	if ( '' !== trim( $explicit_lang ) ) {
		$raw = strtolower( trim( $explicit_lang ) );
		if ( str_starts_with( $raw, 'zh' ) ) {
			$normalized_explicit = 'zh';
		} elseif ( str_starts_with( $raw, 'pt' ) ) {
			$normalized_explicit = 'pt-br';
		} elseif ( in_array( $raw, array( 'en', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' ), true ) ) {
			$normalized_explicit = $raw;
		}
	}

	$url_locale = '';
	$clean_url  = trim( $page_url );
	if ( '' !== $clean_url ) {
		if ( 1 === preg_match( '#(?:^|//[^/]+)/(zh|es|pt-br|de|fr|ar|id|vi)(?:/|$)#i', $clean_url, $m ) ) {
			$url_locale = strtolower( $m[1] );
		} else {
			$path = (string) wp_parse_url( $clean_url, PHP_URL_PATH );
			if ( 1 === preg_match( '#^/(zh|es|pt-br|de|fr|ar|id|vi)(?:/|$)#i', $path, $m ) ) {
				$url_locale = strtolower( $m[1] );
			}
		}
	}

	$trimmed_q = trim( $question );
	if ( '' !== $trimmed_q ) {
		// 1. Script checks.
		if ( 1 === preg_match( '/\p{Arabic}/u', $trimmed_q ) ) {
			return 'ar';
		}
		if ( 1 === preg_match( '/\p{Han}/u', $trimmed_q ) ) {
			return 'zh';
		}
		if ( 1 === preg_match( '/[đĐơƠưƯ]|[ảẩẳẻểỉỏổởủửỷạặậẹệịọộợụựỵằắẵẳặầấẫẩậềếễểệồốỗổộờớỡởợừứữửự]/u', $trimmed_q ) ) {
			return 'vi';
		}

		// 2. Explicit language parameter overrides non-script questions.
		if ( '' !== $normalized_explicit ) {
			return $normalized_explicit;
		}

		// 3. Strong language function words and markers.
		if ( 1 === preg_match( '/\b(?:xin\s+chào|báo\s+giá|vách\s+ngăn|thép\s+không\s+gỉ|hoa\s+văn|giao\s+hàng)\b/iu', $trimmed_q ) ) {
			return 'vi';
		}
		if ( 1 === preg_match( '/\b(?:hallo|guten\s+tag|danke|bitte|wie\s+viel|was\s+kostet|können\s+sie|mitarbeiter|versand|lieferung|lieferzeit|edelstahl|innenbereich|außenbereich|öffnungsanteil|angebot|katalog|abmessungen|stärke)\b/iu', $trimmed_q ) ) {
			return 'de';
		}
		if ( 1 === preg_match( '/\b(?:bonjour|merci|s[\'’]il\s+vous\s+pla[iî]t|pouvez-vous|livrez-vous|combien|prix|devis|[eé]chantillon|livraison|exp[eé]dition|d[eé]lai|acier\s+inoxydable|ext[eé]rieur|int[eé]rieur|catalogue|[eé]paisseur)\b/iu', $trimmed_q ) ) {
			return 'fr';
		}
		if ( 1 === preg_match( '/\b(?:hola|buenos\s+d[ií]as|gracias|por\s+favor|cu[aá]nto|cu[aá]l\s+es|realizan\s+env[ií]os|precio|cotizaci[oó]n|presupuesto|muestra|env[ií]o|plazo|acero\s+inoxidable|cat[aá]logo|patr[oó]n|patrones|espesor|grosor)\b/iu', $trimmed_q ) ) {
			return 'es';
		}
		if ( 1 === preg_match( '/\b(?:ol[aá]|bom\s+dia|obrigad[oa]|voc[êe]s|gostaria|quanto\s+custa|pre[çc]o|or[çc]amento|amostra|frete|prazo|a[çc]o\s+inoxid[aá]vel|padr[aã]o|padr[oõ]es|espessura)\b/iu', $trimmed_q ) ) {
			return 'pt-br';
		}
		if ( 1 === preg_match( '/\b(?:halo|selamat\s+pagi|selamat\s+siang|terima\s+kasih|tolong|bisakah\s+anda|apakah\s+anda|berapa|harga|penawaran|sampel|pengiriman|ongkir|baja\s+tahan\s+karat|pola|ketebalan|rekomendasi|merekomendasikan)\b/iu', $trimmed_q ) ) {
			return 'id';
		}

		// 4. Fallback to URL path prefix before generic English keywords when ambiguous.
		if ( '' !== $url_locale ) {
			return $url_locale;
		}

		if ( 1 === preg_match( '/\b(?:hello|hi|please|thank\s+you|thanks|how\s+much|price|quote|cost|shipping|lead\s+time|sample|stainless\s+steel|pattern|screen|can\s+you|what\s+is|where|do\s+you|recommend)\b/iu', $trimmed_q ) ) {
			return 'en';
		}
	}

	// 5. Explicit language parameter when question was empty.
	if ( '' !== $normalized_explicit ) {
		return $normalized_explicit;
	}

	// 6. Fallback to URL path prefix.
	if ( '' !== $url_locale ) {
		return $url_locale;
	}

	return 'en';
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

	$chat_id = isset( $body['chat_id'] ) && is_scalar( $body['chat_id'] ) && ctype_digit( (string) $body['chat_id'] )
		? (int) $body['chat_id']
		: 0;

	$explicit_lang = isset( $body['lang'] ) && is_string( $body['lang'] ) ? trim( $body['lang'] ) : '';
	$raw_page_url  = isset( $body['page_url'] ) && is_string( $body['page_url'] ) ? trim( $body['page_url'] ) : '';
	$visitor_lang  = md_kb_endpoint_detect_language( $q, $raw_page_url, $explicit_lang );
	if ( 'en' === $visitor_lang && '' === $raw_page_url && $chat_id > 0 ) {
		$chat_ref     = md_kb_endpoint_chat_referrer( $chat_id );
		$visitor_lang = md_kb_endpoint_detect_language( $q, $chat_ref, $explicit_lang );
	}

	if ( md_kb_endpoint_knowledge_is_empty() ) {
		return array(
			'status' => 503,
			'body'   => array(
				'error' => array( 'code' => 'kb_unavailable' ),
				'msg'   => md_kb_endpoint_fallback_message( $visitor_lang ),
			),
		);
	}

	if ( '' === md_kb_endpoint_setting( 'deepseek_api_key' ) ) {
		return array(
			'status' => 503,
			'body'   => array(
				'error' => array( 'code' => 'not_configured' ),
				'msg'   => md_kb_endpoint_fallback_message( $visitor_lang ),
			),
		);
	}

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

	$direct_pattern = md_kb_endpoint_pattern_lookup( $q, $visitor_lang );
	$direct_p_hits  = $direct_pattern['hits'];
	$pattern        = $direct_pattern;
	if ( 0 === $pattern['hits'] && array() !== $history && ! md_kb_endpoint_asks_recommendation( $q ) ) {
		$pattern = md_kb_endpoint_history_pattern_lookup( $history, $visitor_lang );
	}
	$page = 0 === $pattern['hits'] ? md_kb_endpoint_page_context( $body['page_url'] ?? null, $visitor_lang ) : array( 'context' => '' );
	if ( 0 === $pattern['hits'] && '' === $page['context'] && $chat_id > 0 ) {
		$page = md_kb_endpoint_page_context( md_kb_endpoint_chat_referrer( $chat_id ), $visitor_lang );
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
				'msg'     => md_kb_endpoint_injection_handoff_message( $visitor_lang ),
				'action'  => 'transfer_operator',
				'sources' => array(),
			),
		);
	}
	if ( md_kb_endpoint_asks_shipping( $q ) ) {
		$shipping_sources = '' !== md_kb_endpoint_partial_fact_message( $q, $resolved, $visitor_lang )
			? array_merge( $resolved_sources, array( 'Packing and shipping' ) )
			: array( 'Packing and shipping' );
		return array(
			'status' => 200,
			'body'   => array(
				'msg'           => md_kb_endpoint_shipping_reply( $q, $resolved, $shipping_sources, $visitor_lang ),
				'action'        => md_kb_endpoint_wants_human( $q ) ? 'transfer_operator' : '',
				'sources'       => $shipping_sources,
				'source_labels' => md_kb_endpoint_source_labels( $shipping_sources ),
			),
		);
	}
	if ( md_kb_endpoint_asks_pattern_check_prompt( $q ) ) {
		return md_kb_endpoint_pattern_check_prompt_reply( $resolved, $resolved_sources, $visitor_lang );
	}
	// A confirmed fact the question asked for stays. Neither a commercial
	// commitment nor an unpublished project fact is invented by the model.
	if ( md_kb_endpoint_asks_unverified_fact( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_unverified_fact_reply( $q, $resolved, $visitor_lang ),
				'action'  => 'transfer_operator',
				'sources' => $resolved_sources,
			),
		);
	}
	if ( md_kb_endpoint_asks_commercial( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_commercial_reply( $q, $resolved, $visitor_lang ),
				'action'  => md_kb_endpoint_wants_human( $q ) ? 'transfer_operator' : '',
				'sources' => $resolved_sources,
			),
		);
	}

	if ( md_kb_endpoint_asks_showroom( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'           => md_kb_endpoint_showroom_reply( $visitor_lang ),
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
				'msg'           => md_kb_endpoint_dimensions_reply( $q, $resolved, $visitor_lang ),
				'action'        => '',
				'sources'       => array( 'Thickness and weight' ),
				'source_labels' => array( 'Thickness and weight' ),
			),
		);
	}

	if ( md_kb_endpoint_asks_recommendation( $q ) ) {
		if ( 0 === $pattern['hits'] ) {
			$pattern = md_kb_endpoint_pattern_recommend( $q, array(), $visitor_lang );
		} else {
			$exclude_ids = md_kb_endpoint_pattern_source_ids( $pattern['sources'] );
			$recommend_q = md_kb_endpoint_augment_recommendation_query( $q, $exclude_ids );
			$recommended = md_kb_endpoint_pattern_recommend( $recommend_q, $exclude_ids, $visitor_lang );
			if ( 0 < $recommended['hits'] ) {
				$pattern['context'] = $pattern['context'] . "\n\n" . $recommended['context'];
				$pattern['sources'] = array_values( array_unique( array_merge( $pattern['sources'], $recommended['sources'] ) ) );
				$pattern['hits']    = count( $pattern['sources'] );
			}
		}
		$resolved         = '' !== $pattern['context'] ? $pattern['context'] : $page['context'];
		$resolved_sources = 0 < $pattern['hits'] ? $pattern['sources'] : ( isset( $page['sources'] ) ? $page['sources'] : array() );
	}

	list( $results, $context ) = md_kb_endpoint_retrieve( $q, $visitor_lang );
	if ( '' !== $pattern['context'] ) {
		$context = "PATTERN FACTS (WooCommerce, current publish):\n" . $pattern['context']
			. ( '' !== trim( $context ) ? "\n\nKNOWLEDGE (Rapls):\n" . $context : '' );
	} elseif ( '' !== $page['context'] ) {
		$context = "CURRENT PATTERN (the page the visitor opened the chat on):\n" . $page['context']
			. ( '' !== trim( $context ) ? "\n\nKNOWLEDGE (Rapls):\n" . $context : '' );
	}
	$answer = md_kb_endpoint_call_model( md_kb_endpoint_messages( $q, $context, $history, $visitor_lang ) );

	if ( is_string( $answer ) ) {
		$fallback_answer = md_kb_endpoint_pattern_upstream_fallback( $q, $resolved, $pattern, $results, $visitor_lang );
		if ( null === $fallback_answer ) {
			return array(
				'status' => 502,
				'body'   => array(
					'error' => array( 'code' => $answer ),
					'msg'   => md_kb_endpoint_fallback_message( $visitor_lang ),
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
		&& md_kb_endpoint_no_answer_message( $visitor_lang ) !== $answer['answer']
	) {
		$answer['handoff'] = false;
		$answer['reason']  = 'none';
		$answer['answer']  = trim( (string) preg_replace( '/\s+[^.?!]*\bbe(?:ing)?\s+checked\s+with\s+the\s+team\b[^.?!]*[.?!]?$/iu', '', $answer['answer'] ) );
	}

	$answer = md_kb_endpoint_preserve_known_facts( $q, $resolved, $answer, $visitor_lang );

	// A question that kept a published fact is not an unanswered gap, even when the rest transfers.
	if ( $answer['handoff'] && md_kb_endpoint_is_knowledge_gap( $answer, $visitor_lang ) && 0 === $pattern['hits'] && '' === md_kb_endpoint_partial_fact_message( $q, $resolved, $visitor_lang ) ) {
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
		$msg .= md_kb_endpoint_source_footer( $sources, $visitor_lang );
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
 *
 * @param string $lang The canonical language code.
 * @return string The handoff message.
 */
function md_kb_endpoint_commercial_handoff_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'Every screen is custom-fabricated in 304 or 316 stainless steel. Pricing, lead time, minimum order, and finish samples are confirmed by our team for your panel dimensions, thickness (0.8-3.0 mm), PVD finish, and quantity — use Request Quote or Chat on WhatsApp above for a formal quote within 24 hours.',
		'zh'    => '每款屏风均采用 304 或 316 不锈钢定制加工。价格、交期、起订量及表面样品由我们的团队根据您的面板尺寸、厚度（0.8-3.0 mm）、PVD 表面处理和数量为您核算——请使用上方的“索取报价”或“WhatsApp 咨询”，我们将在 24 小时内提供正式报价。',
		'es'    => 'Cada celosía se fabrica a medida en acero inoxidable 304 o 316. El precio, plazo de entrega, pedido mínimo y muestras de acabado los confirma nuestro equipo según las dimensiones de panel, grosor (0,8-3,0 mm), acabado PVD y cantidad; utilice Solicitar cotización o WhatsApp arriba para una cotización formal en 24 horas.',
		'pt-br' => 'Cada painel é fabricado sob medida em aço inoxidável 304 ou 316. Preço, prazo de entrega, pedido mínimo e amostras de acabamento são confirmados por nossa equipe com base nas dimensões do painel, espessura (0,8-3,0 mm), acabamento PVD e quantidade — use Solicitar orçamento ou WhatsApp acima para um orçamento formal em 24 horas.',
		'de'    => 'Jede Blende wird individuell aus Edelstahl 304 oder 316 maßgefertigt. Preis, Lieferzeit, Mindestbestellmenge und Oberflächenmuster bestätigt unser Team anhand Ihrer Paneelmaße, Stärke (0,8–3,0 mm), PVD-Oberfläche und Menge — nutzen Sie Angebot anfordern oder WhatsApp oben für ein formelles Angebot innerhalb von 24 Stunden.',
		'fr'    => 'Chaque panneau est fabriqué sur mesure en acier inoxydable 304 ou 316. Le prix, le délai de livraison, la commande minimale et les échantillons de finition sont confirmés par notre équipe selon les dimensions, l\'épaisseur (0,8-3,0 mm), la finition PVD et la quantité — utilisez Demander un devis ou WhatsApp ci-dessus pour un devis sous 24 heures.',
		'ar'    => 'يتم تصنيع كل قاطع معدني حسب الطلب من الفولاذ المقاوم للصدأ 304 أو 316. يتم تأكيد السعر ومدة التسليم والحد الأدنى للطلب وعينات التشطيب من قِبل فريقنا وفقاً لأبعاد الألواح والسمك (0.8-3.0 مم) وتشطيب PVD والكمية — استخدم "طلب عرض سعر" أو دردشة واتساب أعلاه للحصول على عرض سعر رسمي خلال 24 ساعة.',
		'id'    => 'Setiap sekat dibuat khusus dari baja tahan karat 304 atau 316. Harga, waktu pengerjaan, pesanan minimum, dan sampel finishing dikonfirmasi oleh tim kami sesuai dimensi panel, ketebalan (0,8-3,0 mm), finishing PVD, dan jumlah — gunakan Minta Penawaran atau WhatsApp di atas untuk penawaran resmi dalam 24 jam.',
		'vi'    => 'Mỗi vách ngăn được gia công theo yêu cầu bằng thép không gỉ 304 hoặc 316. Giá cả, thời gian giao hàng, số lượng đặt tối thiểu và mẫu hoàn thiện được đội ngũ của chúng tôi xác nhận theo kích thước tấm, độ dày (0,8-3,0 mm), lớp hoàn thiện PVD và số lượng — hãy sử dụng Yêu cầu báo giá hoặc WhatsApp ở trên để nhận báo giá chính thức trong 24 giờ.',
	);
	return $messages[ $lang ] ?? $messages['en'];
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
		'/\b(?:talk\s+to\s+(?:a\s+)?(?:human|person|agent|someone|representative|team|operator)|speak\s+(?:to|with)\s+(?:a\s+)?(?:human|person|agent|someone|representative|operator)|connect\s+me\s+(?:with|to)|real\s+person|human\s+agent|live\s+agent|hablar\s+con\s+(?:un[ao]?\s+)?(?:humano|agente|persona|asesor|operador|representante)|contactar\s+(?:a\s+|con\s+)?(?:un[ao]?\s+)?(?:humano|persona|agente|asesor|ventas)|p[aá]same\s+con\s+(?:un[ao]?\s+)?(?:humano|persona|agente|asesor)|agente\s+humano|persona\s+real|asesor\s+(?:humano|comercial|de\s+ventas)|falar\s+com\s+(?:um[a]?\s+)?(?:humano|atendente|agente|pessoa|consultor|operador|vendedor)|atendente\s+humano|pessoa\s+real|consultor\s+de\s+vendas|mit\s+(?:einem\s+|einer\s+)?(?:menschen|mitarbeiter(?:in)?|berater(?:in)?|operator|kundenberater|vertrieb)\s+(?:sprechen|reden|verbinden)|verbinden\s+sie\s+mich|echter?\s+mensch|menschliche[rn]?\s+(?:mitarbeiter|berater)|parler\s+[àa]\s+(?:un[e]?\s+)?(?:humain|agent|conseiller|op[eé]rateur|personne|commercial)|contacter\s+(?:un[e]?\s+)?(?:humain|conseiller|commercial)|agent\s+humain|personne\s+r[eé]elle|conseiller\s+commercial|(?:bicara|berbicara|hubungkan(?:\s+saya)?)\s+dengan\s+(?:manusia|agen|petugas|konsultan|operator|staf|tim\s+sales)|agen\s+manusia|(?:nói\s+chuyện|trao\s+đổi|gặp|kết\s+nối)\s+(?:với\s+)?(?:người(?:\s+thật)?|nhân\s+viên|chuyên\s+viên|tư\s+vấn(?:\s+viên)?)|người\s+thật)\b|^\s*(?:人工|真人)\s*[？?!！。.]?\s*$|转人工|人工客服|人工顾问|客服代表|转接人工|找人沟通|找人工|真人|联系客服|联系销售|人工服务|转接客服|التحدث\s+(?:مع|إلى)\s+(?:إنسان|شخص|موظف|بشري|مختص|ممثل\s+مبيعات|خدمة\s+العملاء)|تواصل\s+مع\s+(?:موظف|بشري|مختص|ممثل\s+مبيعات)|تحويل\s+(?:لموظف|إلى\s+موظف)|خدمة\s+العملاء|وكيل\s+بشري|موظف\s+بشري|ممثل\s+مبيعات/iu',
		$q
	);
}

/**
 * Whether the question asks for a commercial commitment rather than only a
 * pattern fact: price, quote, lead time, a ship date, MOQ or a sample, in
 * English, Chinese, or supported languages. A published fact the question also asked for is kept
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
		'/\b(?:price|pricing|quote|quotation|cost\b(?!\s*-?\s*effective)|how\s+much|lead[\s-]*time|delivery\s+(?:time|date)|moq|minimum\s+order|samples?|swatches?|precio|precios|cotizaci[oó]n|cotizaciones|presupuesto|cu[aá]nto\s+(?:cuesta|vale|tarda)|plazo\s+de\s+(?:entrega|fabricaci[oó]n|producci[oó]n)|tiempo\s+de\s+(?:entrega|fabricaci[oó]n|producci[oó]n)|pedido\s+m[ií]nimo|cantidad\s+m[ií]nima|muestras?|muestrarios?|pre[çc]o|pre[çc]os|or[çc]amento|or[çc]amentos|quanto\s+custa|prazo\s+(?:m[eé]dio\s+)?de\s+(?:entrega|produ[çc][aã]o|fabrica[çc][aã]o)|tempo\s+de\s+(?:entrega|produ[çc][aã]o)|quantidade\s+m[ií]nima|amostras?|mostru[aá]rios?|preis|preise|kostet?|angebot|kostenvoranschlag|lieferzeit|lieferfrist|produktionszeit|fertigungszeit|mindestbestellmenge|mindestmenge|farbmuster|oberfl[aä]chenmuster|materialmuster|handmuster|musterst[uü]ck|probest[uü]ck|prix|co[uû]t|devis|combien\s+(?:co[uû]te\w*|ça\s+co[uû]te|de\s+temps|faut-il)|d[eé]lai\s+(?:de\s+(?:livraison|fabrication|production)|habituel)|commande\s+minimale|quantit[eé]\s+minimale|[eé]chantillons?|nuanciers?|harga|biaya|penawaran|berapa\s+(?:harga\w*|biaya\w*|lama|minimum|moq|ongkir\w*)|waktu\s+(?:pengerjaan|produksi|pengiriman)|pesanan\s+minimum|sampel|giá|báo\s+giá|chi\s+phí|bao\s+nhiêu|bao\s+lâu|thời\s+gian\s+(?:gia\s+công|sản\s+xuất|giao\s+hàng|làm\s+hàng)|số\s+lượng\s+(?:đặt\s+)?tối\s+thiểu|mẫu\s+(?:thử|vật\s+liệu|bề\s+mặt|màu|inox)|gửi\s+mẫu|xin\s+mẫu|bảng\s+màu)\b|价格|报价|交期|工期|交货期|交货|起订|起订量|最小起订|样品|小样|色板|多少钱|单价|费用|运费(?:多少|价格|报价|怎么算|贵|标准)|多少运费|سعر|أسعار|عرض\s+سعر|كم\s+(?:السعر|التكلفة|يكلف)|تكلفة|مدة\s+(?:التسليم|التصنيع|التنفيذ|التجهيز)|وقت\s+التسليم|الحد\s+الأدنى\s+للطلب|أقل\s+كمية|عينة|عينات/iu',
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
		'/\b(?:when\s+(?:could|can|will|would)\s+(?:you|we|it|this|that)\s+ship|how\s+soon|ship(?:ping)?\s+date|when\s+(?:could|can|will)\s+it\s+(?:arrive|be\s+delivered)|cu[aá]ndo\s+(?:se\s+env[ií]a|podr[ií]a\s+enviar|llega)|fecha\s+de\s+env[ií]o|quando\s+(?:ser[aá]\s+enviado|pode\s+enviar|chega)|data\s+de\s+envio|wann\s+(?:wird|kann)\s+(?:versendet|geliefert)|versanddatum|lieferdatum|quand\s+(?:peut-on|sera-t-il)\s+(?:exp[eé]di[eé]|livr[eé])|date\s+d[\'’]exp[eé]dition|kapan\s+(?:bisa\s+dikirim|sampai)|tanggal\s+pengiriman|khi\s+nào\s+(?:giao\s+hàng|nhận\s+được)|ngày\s+giao\s+hàng)\b|什么时候.{0,8}发货|何时发货|发货时间|متى\s+(?:يتم\s+الشحن|يمكن\s+الشحن|يصل)|تاريخ\s+الشحن/iu',
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
		'/\b(?:do\s+you\s+(?:ship|deliver)|can\s+you\s+(?:ship|deliver)|ship\s+to|deliver\s+to|ship\s+(?:internationally|worldwide)|deliver\s+(?:internationally|worldwide)|shipping\s+(?:to|options|methods|terms)|freight|wooden\s+crates?|customs\s+clearance|env[ií]os?\s+(?:a|internacionales)|realizan\s+env[ií]os|hacen\s+env[ií]os|env[ií]an\s+a|pueden\s+enviar\s+a|entrega\s+(?:en|internacional)|transporte\s+(?:mar[ií]timo|a[eé]reo|internacional)|despacho\s+aduanero|aduanas?|cajas?\s+de\s+madera|exportaci[oó]n|envios?\s+(?:para|internacionais)|envio\s+internacional|enviam\s+para|entregam\s+em|frete\s+(?:mar[ií]timo|a[eé]reo|internacional)|desembara[çc]o|alf[aâ]ndega|caixas?\s+de\s+madeira|exporta[çc][aã]o|(?:versand|versenden\s+sie|liefern\s+sie)(?:\s+international)?(?:\s+(?:nach|in))?|international(?:er)?\s+versand|weltweiter?\s+versand|seefracht|luftfracht|verzollung|zollabfertigung|holzkisten?|exportverpackung|exp[eé]dition\s+(?:vers|internationale)|livraison\s+internationale|livrez-vous|exp[eé]diez-vous|fret\s+(?:maritime|a[eé]rien)|d[eé]douanement|douane|caisses?\s+en\s+bois|kirim\s+ke|pengiriman\s+(?:ke|internasional)|melayani\s+pengiriman|bisa\s+kirim\s+ke|ongkir|kargo\s+(?:laut|udara)|ekspor|bea\s+cukai|kepabeanan|peti\s+kayu|vận\s+chuyển(?:\s+quốc\s+tế|\s+đến)?|giao\s+hàng\s+(?:quốc\s+tế|đến)|có\s+(?:thể\s+)?giao\s+(?:hàng\s+)?đến|đường\s+biển|đường\s+hàng\s+không|xuất\s+khẩu|thông\s+quan|hải\s+quan|kiện\s+gỗ)\b|发货|发往|运输|海运|空运|出口|送到|运到|发到|能发.*吗|清关|报关|木箱|国际物流|运费|شحن\s+(?:إلى|دولي)|الشحن\s+(?:الدولي|البحري|الجوي)|(?:هل\s+)?تشحنون|توصيل\s+دولي|تخليص\s+جمركي|جمارك|صناديق\s+خشبية|تصدير/iu',
		$q
	);
}

/**
 * The visitor-facing sentence for packing and worldwide shipping options.
 *
 * @param string $lang The visitor language.
 * @return string The shipping guidance copy.
 */
function md_kb_endpoint_shipping_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'We pack custom stainless steel panels in export-grade wooden crates and ship worldwide by sea freight or air freight under EXW, FOB, CIF, or DDP terms. Use Request Quote or Chat on WhatsApp above with your destination and panel sizes so our team can confirm the freight option.',
		'zh'    => '我们采用出口级木箱包装定制不锈钢板，并通过海运或空运以 EXW、FOB、CIF 或 DDP 条款发往全球。请使用上方的“索取报价”或“WhatsApp 咨询”告知您的目的地和面板尺寸，以便我们的团队确认运输方案。',
		'es'    => 'Embalamos los paneles de acero inoxidable a medida en cajas de madera aptas para exportación y realizamos envíos a todo el mundo por transporte marítimo o aéreo bajo términos EXW, FOB, CIF o DDP. Utilice Solicitar cotización o WhatsApp arriba indicando su destino y medidas para confirmar la opción de transporte.',
		'pt-br' => 'Embalamos os painéis personalizados de aço inoxidável em caixas de madeira para exportação e enviamos para todo o mundo por frete marítimo ou aéreo sob termos EXW, FOB, CIF ou DDP. Use Solicitar orçamento ou WhatsApp acima com seu destino e dimensões para confirmar a opção de frete.',
		'de'    => 'Wir verpacken maßgefertigte Edelstahlpaneele in exportgerechten Holzkisten und versenden weltweit per See- oder Luftfracht unter EXW-, FOB-, CIF- oder DDP-Bedingungen. Nutzen Sie Angebot anfordern oder WhatsApp oben mit Ihrem Zielort und den Paneelmaßen, damit unser Team die Frachtoption bestätigen kann.',
		'fr'    => 'Nous emballons les panneaux en acier inoxydable sur mesure dans des caisses en bois conformes à l\'exportation et expédions dans le monde entier par fret maritime ou aérien selon les conditions EXW, FOB, CIF ou DDP. Utilisez Demander un devis ou WhatsApp ci-dessus avec votre destination et vos dimensions pour confirmer l\'option de fret.',
		'ar'    => 'نقوم بتعبئة ألواح الفولاذ المقاوم للصدأ المخصصة في صناديق خشبية مخصصة للتصدير ونشحن إلى جميع أنحاء العالم عن طريق الشحن البحري أو الجوي بموجب شروط EXW أو FOB أو CIF أو DDP. استخدم "طلب عرض سعر" أو واتساب أعلاه مع تحديد وجهتك ومقاسات الألواح ليؤكد فريقنا خيار الشحن.',
		'id'    => 'Kami mengemas panel baja tahan karat khusus dalam peti kayu standar ekspor dan mengirimkannya ke seluruh dunia melalui kargo laut atau udara dengan ketentuan EXW, FOB, CIF, atau DDP. Gunakan Minta Penawaran atau WhatsApp di atas beserta tujuan dan ukuran panel Anda agar tim kami dapat mengonfirmasi opsi pengiriman.',
		'vi'    => 'Chúng tôi đóng gói các tấm thép không gỉ gia công theo yêu cầu trong các kiện gỗ tiêu chuẩn xuất khẩu và vận chuyển toàn cầu bằng đường biển hoặc đường hàng không theo điều kiện EXW, FOB, CIF hoặc DDP. Hãy sử dụng Yêu cầu báo giá hoặc WhatsApp ở trên cùng địa điểm và kích thước tấm của bạn để đội ngũ của chúng tôi xác nhận phương án vận chuyển.',
	);
	return $messages[ $lang ] ?? $messages['en'];
}

/**
 * The direct reply for packing and shipping, keeping any confirmed pattern
 * facts the question also asked for.
 *
 * @param string             $q       The visitor's question.
 * @param string             $context The resolved pattern facts, '' when none.
 * @param array<int, string> $sources The sources to cite in the footer.
 * @param string             $lang    The visitor language.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_shipping_reply( string $q, string $context, array $sources = array( 'Packing and shipping' ), string $lang = 'en' ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context, $lang );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$parts[] = md_kb_endpoint_shipping_message( $lang );
	return implode( ' ', $parts ) . md_kb_endpoint_source_footer( $sources, $lang );
}

/**
 * The commercial handoff, with any confirmed fact the question asked for
 * kept in front of it. A price, a date and a sample promise are not added.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @param string $lang    The visitor language.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_commercial_reply( string $q, string $context, string $lang = 'en' ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context, $lang );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	if ( md_kb_endpoint_unverified_cad_must_transfer( $q, $context ) ) {
		$parts[] = md_kb_endpoint_drawing_check_message( $lang );
	}
	$parts[] = md_kb_endpoint_commercial_handoff_message( $lang );
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
	return 1 === preg_match(
		'/\b(?:showroom|show\s+room|visit\s+(?:the\s+)?(?:factory|office|showroom)|visit\s+you|come\s+to\s+your|visitar\s+(?:la\s+f[aá]brica|el\s+showroom|sus\s+instalaciones)|sala\s+de\s+exposici[oó]n|ausstellungsraum|fabrik\s+besuchen|salle\s+d[\'’]exposition|kunjungi\s+(?:pabrik|showroom)|ruang\s+pamer|thăm\s+(?:nhà\s+máy|showroom)|phòng\s+trưng\s+bày)\b|展厅|参观|معرض|صالة\s+عرض|زيارة\s+(?:المصنع|المعرض)/iu',
		$q
	);
}

/**
 * Whether the visitor asks for thickness or weight. The published guidance
 * is shown; the exact value is still confirmed per project.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the question is a thickness or weight question.
 */
function md_kb_endpoint_asks_dimensions( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:thickness|how\s+thick|weight|weigh(?:s|t)?|how\s+heavy|kg\s*\/\s*m|grosor|espesor|cu[aá]n\s+grueso|peso|cu[aá]nto\s+pesa|espessura|quanto\s+pesa|st[aä]rke|dicke|wie\s+dick|gewicht|wie\s+schwer|[eé]paisseur|combien\s+[eé]pais|poids|ketebalan|berapa\s+tebal|berat|berapa\s+berat|độ\s+dày|dày\s+bao\s+nhiêu|trọng\s+lượng|cân\s+nặng)\b|厚度|多厚|重量|多重|سمك|سماكة|كم\s+السمك|وزن|كم\s+الوزن/iu',
		$q
	);
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
	return 1 === preg_match(
		'/^\s*(?:i\s+have\s+a\s+pattern\s+in\s+mind[.!]?\s+can\s+you\s+help\s+me\s+check\s+it\??|我有心仪的图案[，,.]?\s*能帮我核对一下吗[？?]?|tengo\s+un\s+patr[oó]n\s+en\s+mente[.!]?\s*¿?pueden\s+ayudarme\s+a\s+revisarlo\??|tenho\s+um\s+padr[aã]o\s+em\s+mente[.!]?\s*podem\s+me\s+ajudar\s+a\s+conferir\??|ich\s+habe\s+ein\s+bestimmtes\s+muster\s+im\s+sinn[.!]?\s*k[oö]nnen\s+sie\s+es\s+f[uü]r\s+mich\s+pr[uü]fen\??|j[\'’]ai\s+un\s+motif\s+pr[eé]cis\s+en\s+t[êe]te[.!]?\s*pouvez-vous\s+m[\'’]aider\s+[àa]\s+le\s+v[eé]rifier\s*\??|لدي\s+نمط\s+معين\s+في\s+ذهني[.!]?\s*هل\s+يمكنك\s+مساعدتي\s+في\s+التحقق\s+منه[؟?]?|saya\s+sudah\s+memiliki\s+pilihan\s+pola[.!]?\s*bisa\s+bantu\s+memeriksanya\??|tôi\s+đã\s+chọn\s+được\s+một\s+mẫu\s+hoa\s+văn[.!]?\s*bạn\s+có\s+thể\s+giúp\s+tôi\s+kiểm\s+tra\s+không\??)\s*$/iu',
		$q
	);
}

/**
 * Deterministic response for the "Ask about a pattern" starter prompt: if
 * the visitor is already on a pattern page, summarizes that pattern's
 * published facts and invites follow-up questions; otherwise asks them to
 * name the pattern or open its page.
 *
 * @param string             $resolved         The resolved pattern context.
 * @param array<int, string> $resolved_sources The resolved pattern sources.
 * @param string             $lang             The visitor language.
 * @return array{status: int, body: array<string, mixed>} The endpoint reply.
 */
function md_kb_endpoint_pattern_check_prompt_reply( string $resolved, array $resolved_sources, string $lang = 'en' ): array {
	if ( '' !== $resolved ) {
		$blocks = md_kb_endpoint_pattern_fact_blocks( $resolved );
		if ( isset( $blocks[0] ) ) {
			$block = $blocks[0];
			$name  = md_kb_endpoint_pattern_display_name( $block );
			$oa    = trim( (string) ( $block['open_area'] ?? '' ) );
			$fit   = str_replace( array( '-', '_' ), ' ', trim( (string) ( $block['best_fit'] ?? '' ) ) );
			if ( 'en' === $lang ) {
				$parts = array( 'You are viewing ' . $name . ' (' . MD_KB_ENDPOINT_MATERIAL . ')' );
				if ( '' !== $oa ) {
					$parts[] = 'open area: ' . $oa;
				}
				if ( '' !== $fit ) {
					$parts[] = 'best fit: ' . $fit;
				}
				$msg = implode( ', ', $parts ) . '. Tell me what you would like to check — or share another pattern name or code.';
			} else {
				$viewing_templates = array(
					'zh'    => '您当前正在浏览图案 %1$s（%2$s）%3$s。请告诉我您想核对的细节，或发送其他图案名称或编号。',
					'es'    => 'Está viendo el patrón %1$s (%2$s)%3$s. Indíqueme qué desea consultar o comparta otro nombre o código de patrón.',
					'pt-br' => 'Você está visualizando o padrão %1$s (%2$s)%3$s. Diga o que gostaria de conferir ou informe outro nome ou código de padrão.',
					'de'    => 'Sie sehen gerade das Muster %1$s (%2$s)%3$s. Sagen Sie mir gerne, was Sie prüfen möchten, oder nennen Sie einen anderen Musternamen oder Code.',
					'fr'    => 'Vous consultez le motif %1$s (%2$s)%3$s. Indiquez-moi ce que vous souhaitez vérifier ou partagez un autre nom ou code de motif.',
					'ar'    => 'أنت تستعرض الآن النمط %1$s (%2$s)%3$s. أخبرني بما تود التحقق منه — أو شارك اسم أو رمز نمط آخر.',
					'id'    => 'Anda sedang melihat pola %1$s (%2$s)%3$s. Beri tahu saya detail yang ingin Anda periksa, atau sebutkan nama maupun kode pola lainnya.',
					'vi'    => 'Bạn đang xem mẫu hoa văn %1$s (%2$s)%3$s. Hãy cho tôi biết bạn muốn kiểm tra thông tin gì — hoặc gửi tên hay mã hoa văn khác.',
				);
				$oa_labels         = array(
					'zh'    => '镂空率：',
					'es'    => 'área abierta: ',
					'pt-br' => 'área aberta: ',
					'de'    => 'Öffnungsanteil: ',
					'fr'    => 'ouverture : ',
					'ar'    => 'نسبة الفتحات: ',
					'id'    => 'area terbuka: ',
					'vi'    => 'tỷ lệ lỗ thoáng: ',
				);
				$fit_labels        = array(
					'zh'    => '适用场景：',
					'es'    => 'aplicación: ',
					'pt-br' => 'aplicação: ',
					'de'    => 'Einsatzbereich: ',
					'fr'    => 'usage : ',
					'ar'    => 'الاستخدام الأنسب: ',
					'id'    => 'aplikasi: ',
					'vi'    => 'ứng dụng: ',
				);
				$extra             = array();
				if ( '' !== $oa ) {
					$extra[] = ( $oa_labels[ $lang ] ?? 'open area: ' ) . $oa;
				}
				if ( '' !== $fit ) {
					$extra[] = ( $fit_labels[ $lang ] ?? 'best fit: ' ) . $fit;
				}
				$sep    = 'zh' === $lang ? '，' : ', ';
				$suffix = array() !== $extra ? $sep . implode( $sep, $extra ) : '';
				$tpl    = $viewing_templates[ $lang ] ?? 'You are viewing %1$s (%2$s)%3$s.';
				$msg    = sprintf( $tpl, $name, MD_KB_ENDPOINT_MATERIAL, $suffix );
			}
			$labels = md_kb_endpoint_source_labels( $resolved_sources );
			if ( array() !== $labels ) {
				$msg .= md_kb_endpoint_source_footer( $resolved_sources, $lang );
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
	$prompts = array(
		'en'    => 'Of course — share the pattern name or code (or open the pattern page) and tell me what you would like to check, such as open area, visual density, repeat behavior, series, or application.',
		'zh'    => '当然可以——请发送图案名称或编号（或直接打开对应图案页面），并告诉我您想核对的信息，例如镂空率、视觉密度、拼接循环方式、所属系列或适用场景。',
		'es'    => 'Por supuesto: comparta el nombre o código del patrón (o abra su página) e indíqueme qué desea revisar, como el área abierta, densidad visual, repetición, serie o aplicación.',
		'pt-br' => 'Claro — informe o nome ou código do padrão (ou abra a página do padrão) e diga o que gostaria de conferir, como área aberta, densidade visual, repetição, série ou aplicação.',
		'de'    => 'Sehr gerne — nennen Sie mir den Musternamen oder Code (oder öffnen Sie die Musterseite) und sagen Sie mir, was Sie prüfen möchten, z. B. Öffnungsanteil, optische Dichte, Rapport, Serie oder Einsatzbereich.',
		'fr'    => 'Bien sûr — indiquez le nom ou le code du motif (ou ouvrez sa page) et précisez ce que vous souhaitez vérifier : taux d’ouverture, densité visuelle, répétition, série ou application.',
		'ar'    => 'بكل سرور — شارك اسم النمط أو رمزه (أو افتح صفحة النمط) وأخبرني بما تود التحقق منه، مثل نسبة الفتحات أو الكثافة البصرية أو نظام التكرار أو السلسلة أو مجال الاستخدام.',
		'id'    => 'Tentu — sebutkan nama atau kode pola (atau buka halaman polanya) dan beri tahu apa yang ingin Anda periksa, seperti area terbuka, kepadatan visual, pengulangan motif, seri, atau aplikasi.',
		'vi'    => 'Tất nhiên — vui lòng gửi tên hoặc mã hoa văn (hoặc mở trang hoa văn đó) và cho tôi biết bạn muốn kiểm tra thông tin gì, chẳng hạn như tỷ lệ lỗ thoáng, mật độ thị giác, quy luật lặp, bộ sưu tập hay ứng dụng.',
	);
	return array(
		'status' => 200,
		'body'   => array(
			'msg'           => $prompts[ $lang ] ?? $prompts['en'],
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
 * @param string $lang The visitor language.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_showroom_reply( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'You are welcome to visit our Foshan, Guangdong location by arrangement, Monday to Saturday 9:00-18:00 GMT+8. Contact our team with a preferred date and we will confirm the address and time.',
		'zh'    => '欢迎您预约参观我们位于广东佛山的展厅与工厂，接待时间为周一至周六 9:00-18:00 (GMT+8)。请联系我们的团队告知您的意向日期，我们将为您确认具体地址与时间。',
		'es'    => 'Le invitamos a visitar nuestras instalaciones en Foshan, Guangdong previa cita, de lunes a sábado de 9:00 a 18:00 GMT+8. Póngase en contacto con nuestro equipo indicando su fecha preferida y le confirmaremos dirección y hora.',
		'pt-br' => 'Você é bem-vindo para visitar nossa fábrica e showroom em Foshan, Guangdong mediante agendamento, de segunda a sábado das 9:00 às 18:00 GMT+8. Entre em contato com nossa equipe com sua data de preferência para confirmarmos o endereço e horário.',
		'de'    => 'Sie sind herzlich eingeladen, unseren Standort in Foshan, Guangdong nach Vereinbarung von Montag bis Samstag 9:00–18:00 GMT+8 zu besuchen. Kontaktieren Sie unser Team mit einem Terminwunsch; wir bestätigen Adresse und Uhrzeit.',
		'fr'    => 'Vous êtes invités à visiter nos installations à Foshan, Guangdong sur rendez-vous, du lundi au samedi de 9h00 à 18h00 GMT+8. Contactez notre équipe avec la date souhaitée et nous confirmerons l\'adresse et l\'heure.',
		'ar'    => 'يسعدنا استقبالكم في موقعنا بمدينة فوشان، مقاطعة غوانغدونغ بموجب موعد مسبق، من الاثنين إلى السبت 9:00-18:00 بتوقيت GMT+8. تواصل مع فريقنا وحدد التاريخ المناسب وسنؤكد لكم العنوان والموعد.',
		'id'    => 'Anda dipersilakan mengunjungi lokasi kami di Foshan, Guangdong berdasarkan perjanjian, Senin sampai Sabtu 09.00-18.00 GMT+8. Hubungi tim kami dengan tanggal yang diinginkan dan kami akan mengonfirmasi alamat serta waktu.',
		'vi'    => 'Chúng tôi rất hoan nghênh bạn đến thăm cơ sở của chúng tôi tại Phật Sơn, Quảng Đông theo lịch hẹn trước, từ Thứ Hai đến Thứ Bảy 9:00-18:00 GMT+8. Vui lòng liên hệ với đội ngũ của chúng tôi với ngày dự kiến để chúng tôi xác nhận địa chỉ và thời gian.',
	);
	$body     = $messages[ $lang ] ?? $messages['en'];
	return $body . md_kb_endpoint_source_footer( array( 'Showroom visits' ), $lang );
}

/**
 * The direct reply for thickness and weight: the published decorative-panel
 * guidance and one qualified weight reference, never an exact project value.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @param string $lang    The visitor language.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_dimensions_reply( string $q, string $context, string $lang = 'en' ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context, $lang );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$messages = array(
		'en'    => 'Decorative stainless steel panels are commonly 0.8-3.0 mm thick. As a reference, 1.0 mm stainless steel weighs about 8 kg/m2. The exact thickness and weight are confirmed for each project from the pattern, fixing and span.',
		'zh'    => '装饰不锈钢板常见厚度为 0.8-3.0 mm。作为参考，1.0 mm 厚不锈钢板约重 8 kg/m²。确切的厚度和重量将根据项目所选图案、固定方式及跨度由团队最终核定。',
		'es'    => 'Los paneles decorativos de acero inoxidable suelen tener entre 0,8 y 3,0 mm de grosor. Como referencia, el acero inoxidable de 1,0 mm pesa unos 8 kg/m². El grosor y peso exactos se confirman para cada proyecto según el patrón, fijación y vano.',
		'pt-br' => 'Os painéis decorativos de aço inoxidável geralmente têm de 0,8 a 3,0 mm de espessura. Como referência, o aço inoxidável de 1,0 mm pesa cerca de 8 kg/m². A espessura e o peso exatos são confirmados para cada projeto com base no padrão, fixação e vão.',
		'de'    => 'Dekorative Edelstahlpaneele sind üblicherweise 0,8–3,0 mm stark. Als Referenz: 1,0 mm Edelstahl wiegt ca. 8 kg/m². Die genaue Stärke und das Gewicht werden für jedes Projekt anhand von Muster, Befestigung und Spannweite bestätigt.',
		'fr'    => 'Les panneaux décoratifs en acier inoxydable ont couramment une épaisseur de 0,8 à 3,0 mm. À titre de référence, 1,0 mm d\'acier inoxydable pèse environ 8 kg/m². L\'épaisseur et le poids exacts sont confirmés pour chaque projet selon le motif, la fixation et la portée.',
		'ar'    => 'يبلغ سمك ألواح الفولاذ المقاوم للصدأ الزخرفية عادةً 0.8-3.0 مم. كمرجع، يزن الفولاذ المقاوم للصدأ بسمك 1.0 مم حوالي 8 كجم/متر مربع. يتم تأكيد السمك والوزن الدقيقين لكل مشروع وفقاً للنمط وطريقة التثبيت والمسافة بين الركائز.',
		'id'    => 'Panel baja tahan karat dekoratif umumnya memiliki ketebalan 0,8-3,0 mm. Sebagai referensi, baja tahan karat 1,0 mm berbobot sekitar 8 kg/m². Ketebalan dan berat pastinya dikonfirmasi untuk setiap proyek berdasarkan pola, sistem pemasangan, dan bentang.',
		'vi'    => 'Các tấm thép không gỉ trang trí thường có độ dày từ 0,8 đến 3,0 mm. Để tham khảo, thép không gỉ 1,0 mm nặng khoảng 8 kg/m². Độ dày và trọng lượng chính xác được xác nhận cho từng dự án dựa trên hoa văn, cách cố định và khẩu độ.',
	);
	$parts[]  = $messages[ $lang ] ?? $messages['en'];
	return implode( ' ', $parts ) . md_kb_endpoint_source_footer( array( 'Thickness and weight' ), $lang );
}

/**
 * The showroom, destination, installation, thickness or weight handoff.
 * No number or promise is stated; the team confirms the project.
 *
 * @param string $lang The visitor language.
 * @return string The visitor-facing handoff sentence.
 */
function md_kb_endpoint_unverified_fact_handoff_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'That project detail is confirmed by our team — I am connecting you with them.',
		'zh'    => '该项目具体细节需要由我们的团队为您确认——我正在为您转接人工团队。',
		'es'    => 'Ese detalle del proyecto debe confirmarlo nuestro equipo; le estoy comunicando con ellos.',
		'pt-br' => 'Esse detalhe do projeto é confirmado por nossa equipe — estou conectando você com eles.',
		'de'    => 'Dieses Projektdetail wird von unserem Team bestätigt — ich verbinde Sie gerne weiter.',
		'fr'    => 'Ce détail de projet est confirmé par notre équipe — je vous mets en relation avec eux.',
		'ar'    => 'يتم تأكيد تفاصيل هذا المشروع من قِبل فريقنا — أقوم الآن بتوصيلك بهم.',
		'id'    => 'Detail proyek tersebut dikonfirmasi oleh tim kami — saya menghubungkan Anda dengan mereka.',
		'vi'    => 'Chi tiết dự án đó được đội ngũ của chúng tôi xác nhận — tôi đang kết nối bạn với họ.',
	);
	return $messages[ $lang ] ?? $messages['en'];
}

/**
 * The unverified-fact handoff, with any confirmed pattern fact the question
 * also asked for kept in front of it.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @param string $lang    The visitor language.
 * @return string The visitor-facing reply.
 */
function md_kb_endpoint_unverified_fact_reply( string $q, string $context, string $lang = 'en' ): string {
	$parts = array();
	$facts = md_kb_endpoint_partial_fact_message( $q, $context, $lang );
	if ( '' !== $facts ) {
		$parts[] = $facts;
	}
	$parts[] = md_kb_endpoint_unverified_fact_handoff_message( $lang );
	return implode( ' ', $parts );
}

/**
 * The sentence used when a drawing was asked for and the published CAD
 * status does not confirm one. It does not say the drawing is available.
 *
 * @param string $lang The visitor language.
 * @return string The check sentence.
 */
function md_kb_endpoint_drawing_check_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'A drawing has not been confirmed — our team is checking that.',
		'zh'    => '图纸尚未确认——我们的团队正在核实。',
		'es'    => 'El plano no ha sido confirmado; nuestro equipo lo está comprobando.',
		'pt-br' => 'O desenho ainda não foi confirmado — nossa equipe está verificando.',
		'de'    => 'Eine Zeichnung ist noch nicht bestätigt — unser Team prüft das.',
		'fr'    => 'Le plan n\'a pas été confirmé — notre équipe vérifie ce point.',
		'ar'    => 'لم يتم تأكيد الرسم بعد — يقوم فريقنا بالتحقق من ذلك.',
		'id'    => 'Gambar belum dikonfirmasi — tim kami sedang memeriksanya.',
		'vi'    => 'Bản vẽ chưa được xác nhận — đội ngũ của chúng tôi đang kiểm tra.',
	);
	return $messages[ $lang ] ?? $messages['en'];
}

/**
 * The sentence used when a published fact was kept and the rest of the
 * question still has to be checked. It does not deny the fact just stated.
 *
 * @param string $lang The visitor language.
 * @return string The remainder sentence.
 */
function md_kb_endpoint_remainder_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'The rest of that is being checked with our team.',
		'zh'    => '其余部分正在与我们的团队核实。',
		'es'    => 'El resto lo está consultando nuestro equipo.',
		'pt-br' => 'O restante está sendo verificado com nossa equipe.',
		'de'    => 'Den übrigen Teil klären wir mit unserem Team.',
		'fr'    => 'Le reste est en cours de vérification auprès de notre équipe.',
		'ar'    => 'تتم مراجعة الجزء المتبقي مع فريقنا.',
		'id'    => 'Selebihnya sedang kami pastikan bersama tim kami.',
		'vi'    => 'Phần còn lại đang được xác minh với đội ngũ của chúng tôi.',
	);
	return $messages[ $lang ] ?? $messages['en'];
}

/**
 * Puts confirmed facts in front of a handoff. The exact no-answer sentence
 * is replaced, because that sentence denies information the reply now has.
 *
 * @param string $facts The confirmed sentences, '' when none.
 * @param string $reply The reply so far.
 * @param string $lang  The visitor language.
 * @return string The reply to send.
 */
function md_kb_endpoint_with_kept_facts( string $facts, string $reply, string $lang = 'en' ): string {
	$reply = trim( $reply );
	if ( '' === $facts ) {
		return $reply;
	}
	if ( md_kb_endpoint_no_answer_message() === $reply || md_kb_endpoint_no_answer_message( $lang ) === $reply ) {
		$reply = md_kb_endpoint_remainder_message( $lang );
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
 * @param array{answer: string, handoff: bool, reason: string} $answer  The model's reply.
 * @param string                                               $lang    The visitor language.
 * @return array{answer: string, handoff: bool, reason: string} The reply to send.
 */
function md_kb_endpoint_preserve_known_facts( string $q, string $context, array $answer, string $lang = 'en' ): array {
	if ( $answer['handoff'] ) {
		$missing = md_kb_endpoint_missing_fact_message( $q, $context, $answer['answer'], $lang );
		if ( '' !== $missing ) {
			$answer['answer'] = md_kb_endpoint_with_kept_facts( $missing, $answer['answer'], $lang );
			if ( '' === $answer['reason'] || 'none' === $answer['reason'] ) {
				$answer['reason'] = 'no_knowledge';
			}
		}
	}
	return md_kb_endpoint_guard_unverified_cad( $q, $context, $answer, $lang );
}

/**
 * Forces a transfer when a drawing was asked for and no published CAD
 * status confirms one, and removes a promise the facts do not support.
 *
 * @param string                                               $q       The visitor's question.
 * @param string                                               $context The resolved pattern facts, '' when none.
 * @param array{answer: string, handoff: bool, reason: string} $answer  The reply so far.
 * @param string                                               $lang    The visitor language.
 * @return array{answer: string, handoff: bool, reason: string} The reply to send.
 */
function md_kb_endpoint_guard_unverified_cad( string $q, string $context, array $answer, string $lang = 'en' ): array {
	if ( ! md_kb_endpoint_unverified_cad_must_transfer( $q, $context ) ) {
		return $answer;
	}
	$was_handoff       = $answer['handoff'];
	$answer['handoff'] = true;
	if ( $was_handoff && 'human_request' !== $answer['reason'] && ( '' === $answer['reason'] || 'none' === $answer['reason'] ) ) {
		$answer['reason'] = 'no_knowledge';
	}
	$reply   = md_kb_endpoint_without_drawing_promise( $answer['answer'] );
	$missing = md_kb_endpoint_missing_fact_message( $q, $context, $reply, $lang );
	$reply   = md_kb_endpoint_with_kept_facts( $missing, $reply, $lang );
	$handed  = str_contains( $reply, md_kb_endpoint_drawing_check_message() )
		|| str_contains( $reply, md_kb_endpoint_drawing_check_message( $lang ) )
		|| str_contains( $reply, md_kb_endpoint_remainder_message() )
		|| str_contains( $reply, md_kb_endpoint_remainder_message( $lang ) )
		|| str_contains( $reply, md_kb_endpoint_no_answer_message() )
		|| str_contains( $reply, md_kb_endpoint_no_answer_message( $lang ) );
	if ( ! $handed ) {
		$check = md_kb_endpoint_drawing_check_message( $lang );
		$reply = '' !== $reply
			? rtrim( $reply ) . ' ' . $check
			: $check;
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
		'/\b(?:have|has|got|get|provide|send|share|request|available|availability)\b.{0,40}\b(?:cad|dxf|drawings?)\b|\bcad\s+status\b|\b(?:cad|dxf|drawings?)\b.{0,40}\b(?:available|availability|status|provided?|sent?|shared?)\b|\b(?:enviar|proporcionar|facilitar|fornecer|disponibilizar|envoyer|fournir|partager|kirim\w*|mengirim\w*|menyediakan|gửi|cung\s+cấp)\b.{0,35}\b(?:cad|cao|dxf)\b|\b(?:cad|cao|dxf)(?:-dateien)?\b.{0,45}\b(?:bereitstellen|senden|schicken|verf[uü]gbar|disponibles?|dispon[ií]vel|tersedia)\b|(?:有|发|给|提供|分享|要|需要|查|看).{0,12}(?:图纸|CAD|DXF)|(?:图纸|CAD|DXF).{0,12}(?:有|发|给|提供|分享|可以|能)|(?:إرسال|تزويد\w*|توفير).{0,25}(?:CAD|DXF)/iu',
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
 * @param string $lang    The canonical visitor language code.
 * @return string The sentences, '' when nothing asked is confirmed.
 */
function md_kb_endpoint_partial_fact_message( string $q, string $context, string $lang = 'en' ): string {
	$sentences = array();
	foreach ( md_kb_endpoint_partial_fact_pieces( $q, $context, $lang ) as $piece ) {
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
 * @param string $lang    The visitor language.
 * @return string The missing sentences, '' when none are missing.
 */
function md_kb_endpoint_missing_fact_message( string $q, string $context, string $reply, string $lang = 'en' ): string {
	$missing = array();
	foreach ( md_kb_endpoint_partial_fact_pieces( $q, $context, $lang ) as $piece ) {
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
	if ( 1 === preg_match( '/\b(?:this|that|it|its|current|open\s*area|density|repeat|motif|series|best\s*fit|cad|dxf|sku)\b|这个|这款|它|它的|镂空率|开孔率/iu', $q ) ) {
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
 * @param string                                                         $lang     The visitor language.
 * @return array{answer: string, handoff: bool, reason: string}|null The deterministic reply, or null when no published fact covers the question.
 */
function md_kb_endpoint_pattern_upstream_fallback( string $q, string $resolved, array $pattern, array $results = array(), string $lang = 'en' ): ?array {
	if ( md_kb_endpoint_wants_human( $q ) ) {
		$human_replies = array(
			'en'    => 'I am connecting you with our team — someone will follow up with you shortly.',
			'zh'    => '正在为您转接我们的团队——顾问将很快与您联系。',
			'es'    => 'Le estoy comunicando con nuestro equipo; alguien le atenderá en breve.',
			'pt-br' => 'Estou conectando você com nossa equipe — alguém entrará em contato em breve.',
			'de'    => 'Ich verbinde Sie mit unserem Team — ein Mitarbeiter meldet sich in Kürze bei Ihnen.',
			'fr'    => 'Je vous mets en relation avec notre équipe — un conseiller vous répondra sous peu.',
			'ar'    => 'أقوم الآن بتوصيلك بفريقنا — سيتابع معك أحد المختصين قريباً.',
			'id'    => 'Saya sedang menghubungkan Anda dengan tim kami — petugas kami akan segera menindaklanjuti.',
			'vi'    => 'Tôi đang kết nối bạn với đội ngũ của chúng tôi — chuyên viên sẽ phản hồi bạn ngay.',
		);
		return array(
			'answer'  => $human_replies[ $lang ] ?? $human_replies['en'],
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
			if ( 'en' === $lang ) {
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
			} else {
				if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['series'] ?? '' ) ) ) {
					$details[] = trim( (string) $block['series'] );
				}
				if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['open_area'] ?? '' ) ) ) {
					$oa_label  = array(
						'zh'    => '镂空率 ',
						'es'    => 'área abierta ',
						'pt-br' => 'área aberta ',
						'de'    => 'Öffnungsanteil ',
						'fr'    => 'ouverture ',
						'ar'    => 'نسبة الفتحات ',
						'id'    => 'area terbuka ',
						'vi'    => 'lỗ thoáng ',
					);
					$details[] = ( $oa_label[ $lang ] ?? '' ) . trim( (string) $block['open_area'] );
				}
				if ( md_kb_endpoint_pattern_value_is_answerable( (string) ( $block['best_fit'] ?? '' ) ) ) {
					$details[] = str_replace( array( '-', '_' ), ' ', trim( (string) $block['best_fit'] ) );
				}
				$items[] = $link . ( array() !== $details ? ' (' . implode( ', ', $details ) . ')' : '' );
			}
		}
		if ( array() !== $items ) {
			$intros = array(
				'en'    => 'Here are the closest matching patterns from our catalog: ',
				'zh'    => '以下是我们图库中最匹配的图案推荐：',
				'es'    => 'Aquí tiene los patrones más adecuados de nuestro catálogo: ',
				'pt-br' => 'Aqui estão os padrões mais compatíveis do nosso catálogo: ',
				'de'    => 'Hier sind die am besten passenden Muster aus unserem Katalog: ',
				'fr'    => 'Voici les motifs les plus adaptés de notre catalogue : ',
				'ar'    => 'إليك أقرب الأنماط المطابقة من كتالوجنا: ',
				'id'    => 'Berikut pola yang paling sesuai dari katalog kami: ',
				'vi'    => 'Dưới đây là các mẫu hoa văn phù hợp nhất từ danh mục của chúng tôi: ',
			);
			$sep    = 'zh' === $lang ? '；' : '; ';
			$end    = 'zh' === $lang ? '。' : '.';
			return array(
				'answer'  => ( $intros[ $lang ] ?? $intros['en'] ) . implode( $sep, $items ) . $end,
				'handoff' => false,
				'reason'  => 'none',
			);
		}
	}
	if ( '' !== $resolved ) {
		$partial = md_kb_endpoint_partial_fact_message( $q, $resolved, $lang );
		if ( '' !== $partial ) {
			return array(
				'answer'  => $partial,
				'handoff' => false,
				'reason'  => 'none',
			);
		}
	}
	if ( 'en' === $lang ) {
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
	}
	return null;
}

/**
 * The asked facts that have a published value worth saying.
 *
 * @param string $q       The visitor's question.
 * @param string $context The resolved pattern facts, '' when none.
 * @param string $lang    The visitor language.
 * @return array<int, array{value: string, sentence: string}> The asked, confirmed facts.
 */
function md_kb_endpoint_partial_fact_pieces( string $q, string $context, string $lang = 'en' ): array {
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
			$sentence = md_kb_endpoint_pattern_fact_sentence( $name, $field, $value, $lang );
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
		'open_area'      => '/\bopen[\s-]*area\b|\b[aá]rea\s+(?:abierta|aberta|vazada)\b|\b[oö]ffnungsanteil\b|\bfreie\s+fl[aä]che\b|\btaux\s+d[\'’]ouverture\b|\barea\s+terbuka\b|\btỷ\s+lệ\s+lỗ\s+thoáng\b|\bđộ\s+thoáng\b|镂空率|开孔率|穿孔率|نسبة\s+الفتحات|نسبة\s+فتحات/iu',
		'best_fit'       => '/\b(?:good|best)\s+fit\b|\bsuitable\b|\bsuitability\b|\bfit\s+for\b|\bused\s+for\b|\bapplications?\b|\badecuad[oa]\b|\baplicaci[oó]n\b|\baplicac[aã]o\b|\beinsatzbereich\b|\bgeeignet\b|\badapt[eé]\b|\bcocok\s+untuk\b|\bphù\s+hợp\b|适用场景|适合用|مناسب\s+لـ|مجال\s+الاستخدام/iu',
		'material'       => '/\b(?:what|which)\s+(?:material|grade)\b|\bmade\s+of\b|\bmaterial\s+is\b|\bgrade\s+is\b|\bqu[eé]\s+material\b|\bde\s+qu[eé]\s+material\b|\bwelches\s+material\b|\bquel\s+mat[eé]riau\b|\bbahan\s+apa\b|\bvật\s+liệu\s+gì\b|什么材质|哪种材质|材质是什么|ما\s+هي\s+المادة|نوع\s+المعدن/iu',
		'visual_density' => '/\bvisual\s+density\b|\bdensity\s+of\b|\bhow\s+dense\b|\bdensidad\s+visual\b|\bdensidade\s+visual\b|\boptische\s+dichte\b|\bdensit[eé]\s+visuelle\b|\bkepadatan\s+visual\b|\bmật\s+độ\s+thị\s+giác\b|视觉密度|الكثافة\s+البصرية/iu',
		'cad_status'     => '/\bcad\b|\bdxf\b|\bdrawings?\b|图纸|مخطط\s+cad/iu',
		'code'           => '/\bpattern\s+code\b|\bsku\b|\bwhat(?:\'s| is) the code\b|\bc[oó]digo\s+del?\s+(?:patr[oó]n|padr[aã]o)\b|\bmustercode\b|\bcode\s+du\s+motif\b|\bkode\s+pola\b|\bmã\s+hoa\s+văn\b|图案编号|编号是多少|رمز\s+النمط/iu',
		'permalink'      => '/\bpermalink\b|\bpattern\s+link\b|\bwhere\s+can\s+i\s+(?:see|find)\b|\benlace\s+del\s+patr[oó]n\b|\blink\s+do\s+padr[aã]o\b|\bmusterlink\b|\blien\s+du\s+motif\b|\btautan\s+pola\b|\blink\s+hoa\s+văn\b|图案链接|页面链接|رابط\s+النمط/iu',
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
 * @param string $lang  The visitor language.
 * @return string The sentence, '' when the field is not spoken.
 */
function md_kb_endpoint_pattern_fact_sentence( string $name, string $field, string $value, string $lang = 'en' ): string {
	if ( 'en' === $lang ) {
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

	$display_val = 'best_fit' === $field ? trim( str_replace( array( '-', '_' ), ' ', $value ) ) : $value;
	$templates   = array(
		'open_area'      => array(
			'zh'    => '%1$s 的镂空率为 %2$s。',
			'es'    => 'El área abierta de %1$s es %2$s.',
			'pt-br' => 'A área aberta de %1$s é %2$s.',
			'de'    => 'Der Öffnungsanteil von %1$s beträgt %2$s.',
			'fr'    => 'Le taux d’ouverture de %1$s est de %2$s.',
			'ar'    => 'نسبة الفتحات لنمط %1$s هي %2$s.',
			'id'    => 'Area terbuka %1$s adalah %2$s.',
			'vi'    => 'Tỷ lệ lỗ thoáng của %1$s là %2$s.',
		),
		'best_fit'       => array(
			'zh'    => '%1$s 的推荐适用场景为 %2$s。',
			'es'    => 'El uso recomendado de %1$s es %2$s.',
			'pt-br' => 'O uso recomendado de %1$s é %2$s.',
			'de'    => 'Der empfohlene Einsatzbereich von %1$s ist %2$s.',
			'fr'    => 'L’usage recommandé de %1$s est %2$s.',
			'ar'    => 'مجال الاستخدام الموصى به لنمط %1$s هو %2$s.',
			'id'    => 'Penggunaan yang direkomendasikan untuk %1$s adalah %2$s.',
			'vi'    => 'Ứng dụng phù hợp của %1$s là %2$s.',
		),
		'material'       => array(
			'zh'    => '%1$s 的材质为 %2$s。',
			'es'    => 'El material de %1$s es %2$s.',
			'pt-br' => 'O material de %1$s é %2$s.',
			'de'    => 'Das Material von %1$s ist %2$s.',
			'fr'    => 'Le matériau de %1$s est %2$s.',
			'ar'    => 'مادة نمط %1$s هي %2$s.',
			'id'    => 'Material %1$s adalah %2$s.',
			'vi'    => 'Vật liệu của %1$s là %2$s.',
		),
		'visual_density' => array(
			'zh'    => '%1$s 的视觉密度为 %2$s。',
			'es'    => 'La densidad visual de %1$s es %2$s.',
			'pt-br' => 'A densidade visual de %1$s é %2$s.',
			'de'    => 'Die optische Dichte von %1$s ist %2$s.',
			'fr'    => 'La densité visuelle de %1$s est %2$s.',
			'ar'    => 'الكثافة البصرية لنمط %1$s هي %2$s.',
			'id'    => 'Kepadatan visual %1$s adalah %2$s.',
			'vi'    => 'Mật độ thị giác của %1$s là %2$s.',
		),
		'cad_status'     => array(
			'zh'    => '%1$s 的 CAD 状态为 %2$s。',
			'es'    => 'El estado CAD de %1$s es %2$s.',
			'pt-br' => 'O status CAD de %1$s é %2$s.',
			'de'    => 'Der CAD-Status von %1$s ist %2$s.',
			'fr'    => 'Le statut CAO/CAD de %1$s est %2$s.',
			'ar'    => 'حالة ملف CAD لنمط %1$s هي %2$s.',
			'id'    => 'Status CAD %1$s adalah %2$s.',
			'vi'    => 'Trạng thái CAD của %1$s là %2$s.',
		),
		'code'           => array(
			'zh'    => '%1$s 的图案编号为 %2$s。',
			'es'    => 'El código de patrón de %1$s es %2$s.',
			'pt-br' => 'O código do padrão %1$s é %2$s.',
			'de'    => 'Der Mustercode von %1$s lautet %2$s.',
			'fr'    => 'Le code du motif %1$s est %2$s.',
			'ar'    => 'رمز النمط %1$s هو %2$s.',
			'id'    => 'Kode pola %1$s adalah %2$s.',
			'vi'    => 'Mã hoa văn của %1$s là %2$s.',
		),
		'permalink'      => array(
			'zh'    => '%1$s 的详情页为 %2$s。',
			'es'    => 'La página de %1$s es %2$s.',
			'pt-br' => 'A página de %1$s é %2$s.',
			'de'    => 'Die Seite von %1$s ist %2$s.',
			'fr'    => 'La page de %1$s est %2$s.',
			'ar'    => 'صفحة نمط %1$s هي %2$s.',
			'id'    => 'Halaman %1$s adalah %2$s.',
			'vi'    => 'Trang chi tiết của %1$s là %2$s.',
		),
	);
	if ( isset( $templates[ $field ][ $lang ] ) ) {
		return sprintf( $templates[ $field ][ $lang ], $name, $display_val );
	}
	return md_kb_endpoint_pattern_fact_sentence( $name, $field, $value, 'en' );
}

/**
 * The visitor-facing sentence when a question tries to override the
 * instructions. The model is not asked, and the sentence does not quote
 * the instructions or the attempt.
 *
 * @param string $lang The visitor language.
 * @return string The handoff copy.
 */
function md_kb_endpoint_injection_handoff_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => 'I can only help with questions about our architectural metal screens — I am connecting you with the team.',
		'zh'    => '我仅能解答关于建筑金属屏风图案的咨询——正在为您转接团队顾问。',
		'es'    => 'Solo puedo ayudar con consultas sobre nuestras celosías metálicas arquitectónicas; le estoy comunicando con el equipo.',
		'pt-br' => 'Só posso ajudar com dúvidas sobre nossos painéis metálicos arquitetônicos — estou conectando você com a equipe.',
		'de'    => 'Ich kann nur Fragen zu unseren architektonischen Metallblenden beantworten — ich verbinde Sie mit dem Team.',
		'fr'    => 'Je peux uniquement répondre aux questions sur nos panneaux métalliques architecturaux — je vous mets en relation avec l’équipe.',
		'ar'    => 'يمكنني المساعدة فقط في الاستفسارات المتعلقة بالقواطع المعدنية المعمارية — أقوم بتوصيلك مع الفريق.',
		'id'    => 'Saya hanya dapat membantu pertanyaan seputar sekat logam arsitektural kami — saya menghubungkan Anda dengan tim.',
		'vi'    => 'Tôi chỉ có thể hỗ trợ các câu hỏi về vách ngăn kim loại kiến trúc — tôi đang kết nối bạn với đội ngũ.',
	);
	return $messages[ $lang ] ?? $messages['en'];
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
	return md_kb_endpoint_with_en_language(
		static function () use ( $candidate ): array {
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
			$seen   = array();
			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post || md_kb_endpoint_is_catalog_excluded( (int) $post->ID ) ) {
					continue;
				}
				$canon_id = md_kb_endpoint_canonical_product_id( (int) $post->ID );
				if ( isset( $seen[ $canon_id ] ) ) {
					continue;
				}
				$seen[ $canon_id ] = true;
				if ( $canon_id !== (int) $post->ID ) {
					$canon_post = get_post( $canon_id );
					if ( $canon_post instanceof WP_Post ) {
						$post = $canon_post;
					}
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
	);
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
 * Localizes a permalink URL for the visitor's canonical language code.
 *
 * @param string $url          The canonical English permalink.
 * @param string $visitor_lang The visitor language code ('en', 'zh', etc.).
 * @return string The localized URL.
 */
function md_kb_endpoint_localize_url( string $url, string $visitor_lang = 'en' ): string {
	if ( '' === $url || 'en' === $visitor_lang ) {
		return $url;
	}
	$wpml_lang = 'zh' === $visitor_lang ? 'zh-hans' : $visitor_lang;
	if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wpml_permalink' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WPML standard filter.
		$filtered = apply_filters( 'wpml_permalink', $url, $wpml_lang );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}
	}
	if ( 1 === preg_match( '#^(https?://[^/]+)(/.*)?$#i', $url, $matches ) ) {
		$origin = $matches[1];
		$path   = $matches[2] ?? '/';
		$clean  = (string) preg_replace( '#^/(?:zh|es|pt-br|de|fr|ar|id|vi)(?=/|$)#i', '', $path );
		return $origin . '/' . $visitor_lang . ( str_starts_with( $clean, '/' ) ? $clean : '/' . $clean );
	}
	$clean = (string) preg_replace( '#^/(?:zh|es|pt-br|de|fr|ar|id|vi)(?=/|$)#i', '', $url );
	return '/' . $visitor_lang . ( str_starts_with( $clean, '/' ) ? $clean : '/' . $clean );
}

/**
 * The facts of one published product, as the model may cite them. Empty
 * fields are named as unconfirmed rather than filled in. Price, lead time,
 * MOQ and sample promises are not read.
 *
 * @param \WP_Post $post         The product.
 * @param string   $visitor_lang The visitor language code.
 * @return array{id: int, code: string, title: string, context: string, source: string} The facts and their source line.
 */
function md_kb_endpoint_pattern_facts( WP_Post $post, string $visitor_lang = 'en' ): array {
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
		$lines[] = 'permalink: ' . md_kb_endpoint_localize_url( $url, $visitor_lang );
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
 * @param mixed  $page_url     The request's page_url, untrusted.
 * @param string $visitor_lang The visitor language code.
 * @return array{context: string, sources?: array<string>} The compact facts
 *         and the source the answer cites, or an empty context and no source.
 *         `sources` is present only when a published pattern resolved, which
 *         is why the caller tests it with isset() rather than reading it.
 */
function md_kb_endpoint_page_context( mixed $page_url, string $visitor_lang = 'en' ): array {
	$empty = array( 'context' => '' );
	$slug  = md_kb_endpoint_page_slug( $page_url );
	if ( '' === $slug ) {
		return $empty;
	}
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post instanceof WP_Post ) {
		return $empty;
	}
	$canonical_id = md_kb_endpoint_canonical_product_id( (int) $post->ID );
	if ( $canonical_id !== (int) $post->ID ) {
		$en_post = get_post( $canonical_id );
		if ( $en_post instanceof WP_Post ) {
			$post = $en_post;
		}
	}
	if ( 'publish' !== $post->post_status || 'product' !== $post->post_type || md_kb_endpoint_is_catalog_excluded( (int) $post->ID ) ) {
		return $empty;
	}
	$sku  = md_kb_endpoint_product_sku( (int) $post->ID );
	$code = '' !== $sku ? $sku : $post->post_name;
	return array(
		'context' => md_kb_endpoint_page_facts( $post, $visitor_lang ),
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
	if ( ! preg_match( '#^/(?:(?:zh|es|pt-br|de|fr|ar|id|vi)/)?(?:pattern|product)/([^/]+)/?$#i', $path, $match ) ) {
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
 * @param \WP_Post $post         The published product.
 * @param string   $visitor_lang The visitor language code.
 * @return string The context block, one fact per line.
 */
function md_kb_endpoint_page_facts( WP_Post $post, string $visitor_lang = 'en' ): string {
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
		$lines[] = 'URL: ' . md_kb_endpoint_localize_url( $permalink, $visitor_lang );
	}
	return implode( "\n", $lines );
}

/**
 * The published patterns a question names, as context for the model and
 * as the audit-trail source lines. No candidate, or no published match,
 * is an empty lookup — the caller then answers from Rapls as before.
 *
 * @param string $q            The visitor's question.
 * @param string $visitor_lang The visitor language code.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern context.
 */
function md_kb_endpoint_pattern_lookup( string $q, string $visitor_lang = 'en' ): array {
	$seen    = array();
	$blocks  = array();
	$sources = array();
	foreach ( md_kb_endpoint_pattern_candidates( $q ) as $candidate ) {
		foreach ( md_kb_endpoint_pattern_query( $candidate ) as $post ) {
			if ( isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$facts             = md_kb_endpoint_pattern_facts( $post, $visitor_lang );
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
		$clean_msg = trim( (string) preg_replace( '/\n\n(?:Based on:|参考来源：|Basado en:|Com base em:|Basierend auf:|Basé sur\s*:|بناءً على:|Berdasarkan:|Dựa trên:).*$/su', '', $raw_msg ) );
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
 * @param array<int, string> $history      Chronological history turns.
 * @param string             $visitor_lang The visitor language code.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern lookup from history.
 */
function md_kb_endpoint_history_pattern_lookup( array $history, string $visitor_lang = 'en' ): array {
	foreach ( array_reverse( $history ) as $turn ) {
		if ( 1 === preg_match( '/^Assistant:\s*(.*)$/isu', $turn, $assistant_match ) ) {
			$lookup = md_kb_endpoint_assistant_turn_pattern_lookup( $turn, trim( $assistant_match[1] ), $visitor_lang );
			if ( 0 < $lookup['hits'] ) {
				return $lookup;
			}
			continue;
		}
		$cleaned = trim( $turn );
		if ( '' === $cleaned ) {
			continue;
		}
		$lookup = md_kb_endpoint_pattern_lookup( $cleaned, $visitor_lang );
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
 * @param string $visitor_lang The visitor language code.
 * @return array{context: string, sources: array<int, string>, hits: int} The pattern lookup result.
 */
function md_kb_endpoint_assistant_turn_pattern_lookup( string $raw_turn, string $cleaned_turn, string $visitor_lang = 'en' ): array {
	$candidates = array();
	if ( preg_match_all( '~(?:/(?:zh|es|pt-br|de|fr|ar|id|vi))?/pattern/([a-z0-9-]+)~i', $raw_turn, $slug_matches ) ) {
		foreach ( $slug_matches[1] as $slug ) {
			$candidates[] = strtolower( $slug );
		}
	}
	if ( preg_match_all( '/(?:Based on:|参考来源：|Basado en:|Com base em:|Basierend auf:|Basé sur\s*:|بناءً على:|Berdasarkan:|Dựa trên:)\s*([^\n]+)/iu', $raw_turn, $footer_matches ) ) {
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
			$facts             = md_kb_endpoint_pattern_facts( $post, $visitor_lang );
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
		'/\b(?:recommend(?:ation|s)?|suggest(?:ion|ions)?|which\s+patterns?|what\s+patterns?|any\s+patterns?|show\s+(?:me\s+)?patterns?|looking\s+for\s+(?:a\s+)?patterns?|patterns?\s+(?:for|with|that|suitable|in|like|similar)|similar\s+(?:patterns?\s+)?to|alternatives?\s+to)\b|推荐|图案推荐|哪(?:款|些|种)(?:图案|屏风)?|选哪(?:个|款)|什么图案|建议.{0,8}图案|类似|\b(?:recomiend[aoesn]?|recomendar|recomendaci[oó]n|sugier[aen]?|sugerir|sugerencia|qu[eé]\s+patrones?|cu[aá]les\s+patrones?|recomenda[çc][aã]o|recomenda[rm]?|quais\s+padr[oõ]es|empfehlen|empfehlung|welche\s+muster|recommander|recommandation|sugg[eé]rer|suggestion|quels\s+motifs?|menyarankan|sarankan|pola\s+apa|merekomendasikan|rekomendasikan|rekomendasi|gợi\s+ý|khuyến\s+nghị|mẫu\s+(?:hoa\s+văn\s+)?nào|đề\s+xuất)\b|توصية|اقتراح|أي\s+نمط|ترشيح|توصي|تقترح|رشح/iu',
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
		'/\b(?:geometric|organic|abstract|moroccan(?:[\s-]+arabic)?|arabic|perforated|gradient|landscape|asian|chinese|vintage(?:[\s-]+retro)?|retro|botanical|floral|mural|emt|expanded\s+metal|glass[\s-]+hybrid|laminated\s+glass|repeat(?:ing)?\s+patterns?|(?:low|medium|balanced|high|open|custom)\s+density|dense\s+patterns?)\b|\b\d{1,2}(?:\.\d+)?\s*(?:%|percent)\b.*\b(?:open[\s-]*area|[aá]rea\s+(?:abierta|aberta)|[oö]ffnungsanteil|ouverture|area\s+terbuka|lỗ\s+thoáng)\b|\b(?:open[\s-]*area|[aá]rea\s+(?:abierta|aberta)|[oö]ffnungsanteil|ouverture|area\s+terbuka|lỗ\s+thoáng)\b.*\b\d{1,2}(?:\.\d+)?\s*(?:%|percent)\b|\b(?:low|high|medium|balanced)\s+open[\s-]*area\b|几何|有机|自然|抽象|摩洛哥|阿拉伯|穿孔|冲孔|渐变|山水|景观|东方|中式|复古|植物|花卉|壁画|扩张网|夹胶玻璃|低密度|中密度|均衡密度|高密度|定制密度|\d{1,2}\s*%.*(?:镂空|开孔)|(?:镂空|开孔).*\d{1,2}\s*%|\b(?:geom[eé]trico|geometrisch|g[eé]om[eé]trique|geometris|org[aá]nico|organisch|organique|organik|abstracto|abstrato|abstrakt|abstrait|abstrak|marroqu[ií]|marokkanisch|marocain|gelocht|perforado|perfor[eé]|berlubang|paisaje|paisagem|landschaft|paysage|pemandangan|asi[aá]tico|chino|chin[êe]s|asiatisch|asiatique|bot[aá]nico|flor|hoja|folha|mural|metal\s+expandido|streckmetall|vidrio\s+laminado|vidro\s+laminado)\b|هندسي|عضوي|مغربي|مشربية|مثقب|طبيعي|جدارية|معدن\s+ممدد|نسبة\s+فتحات.*\d{1,2}\s*%/iu',
		$q
	) || (
		1 === preg_match( '/\b(?:outdoor|exterior|facade|privacy|partition|divider|interior|indoor|room\s+divider|balustrade|railing|cladding|sunshade|weather|hotel|lobby|architectural[\s-]+screening|decorative[\s-]+screening|repeat)\b|室外|户外|外墙|立面|庭院|幕墙|隐私|隔断|屏风|室内|护栏|栏杆|遮阳|酒店|大堂|循环|\b(?:außen(?:bereich)?|innen(?:bereich)?|ext[eé]rieur[es]?|int[eé]rieur[es]?|extern[oa]s?|intern[oa]s?|s[eé]parateur|garde-corps|luar\s+ruangan|dalam\s+ruangan|ngoài\s+trời|trong\s+nhà|vách\s+ngăn)\b|خارجي(?:ة)?|داخلي(?:ة)?|واجهة|قاطع/iu', $q )
		&& 1 === preg_match( '/\b(?:patterns?|designs?|styles?|options?|suitable|fit)\b|图案|款式|适合|\b(?:patrones?|padr[oõ]es|muster|motifs?|pola|mẫu)\b|نمط|أنماط|تصميم/iu', $q )
	);
}

/**
 * Recommends up to three published patterns from the live WooCommerce
 * catalog scored against the series, open area percentage, application/best
 * fit, motifs, and visual density criteria in the visitor's question.
 *
 * @param string          $q            The visitor's question.
 * @param array<int, int> $exclude_ids  Product IDs to exclude (e.g. a named reference pattern).
 * @param string          $visitor_lang The visitor language code.
 * @return array{context: string, sources: array<int, string>, hits: int} The top matching pattern facts.
 */
function md_kb_endpoint_pattern_recommend( string $q, array $exclude_ids = array(), string $visitor_lang = 'en' ): array {
	return md_kb_endpoint_with_en_language(
		static function () use ( $q, $exclude_ids, $visitor_lang ): array {
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

			$scored        = array();
			$seen_ids      = array();
			$exclude_canon = array_map( 'md_kb_endpoint_canonical_product_id', $exclude_ids );
			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
					continue;
				}
				$id       = (int) $post->ID;
				$canon_id = md_kb_endpoint_canonical_product_id( $id );
				if ( isset( $seen_ids[ $canon_id ] ) || in_array( $id, $exclude_ids, true ) || in_array( $canon_id, $exclude_canon, true ) || md_kb_endpoint_is_catalog_excluded( $id ) ) {
					continue;
				}
				$seen_ids[ $canon_id ] = true;
				if ( $canon_id !== $id ) {
					$canon_post = get_post( $canon_id );
					if ( $canon_post instanceof WP_Post ) {
						$post = $canon_post;
					}
				}
				$score = md_kb_endpoint_pattern_score( $post, $q );
				if ( $score > 0 ) {
					$scored[] = array(
						'post'  => $post,
						'score' => $score,
						'id'    => $canon_id,
					);
				}
			}

			$is_generic_fallback = false;
			if ( array() === $scored && md_kb_endpoint_asks_recommendation( $q ) ) {
				$is_generic_fallback = true;
				$candidates          = array();
				$seen_generic        = array();
				foreach ( $posts as $post ) {
					if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
						continue;
					}
					$id       = (int) $post->ID;
					$canon_id = md_kb_endpoint_canonical_product_id( $id );
					if ( isset( $seen_generic[ $canon_id ] ) || in_array( $id, $exclude_ids, true ) || in_array( $canon_id, $exclude_canon, true ) || md_kb_endpoint_is_catalog_excluded( $id ) ) {
						continue;
					}
					$seen_generic[ $canon_id ] = true;
					if ( $canon_id !== $id ) {
						$canon_post = get_post( $canon_id );
						if ( $canon_post instanceof WP_Post ) {
							$post = $canon_post;
						}
					}
					$raw_oa   = md_kb_endpoint_pattern_field( get_field( 'open_area', $canon_id ) );
					$best_fit = md_kb_endpoint_pattern_field( get_field( 'best_fit', $canon_id ) );
					if ( '' !== $raw_oa && is_numeric( $raw_oa ) && (float) $raw_oa > 0 && '' !== $best_fit ) {
						$candidates[] = array(
							'post'   => $post,
							'score'  => 1,
							'id'     => $canon_id,
							'series' => md_kb_endpoint_primary_series_key( $canon_id ),
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
				$facts     = md_kb_endpoint_pattern_facts( $item['post'], $visitor_lang );
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
			'regex'   => '/\b(?:geometric|geom[eé]trico|geometrisch|g[eé]om[eé]trique|geometris)\b|几何|هندسي|hình\s+học/iu',
			'needles' => array( 'geometric' ),
		),
		array(
			'regex'   => '/\b(?:organic|org[aá]nico|org[aâ]nico|organisch|organique|organik)\b|有机|自然|عضوي|hữu\s+cơ|tự\s+nhiên/iu',
			'needles' => array( 'organic' ),
		),
		array(
			'regex'   => '/\b(?:abstract|abstracto|abstrato|abstrakt|abstrait|abstrak)\b|抽象|مُجرّد|مجرد|trừu\s+tượng/iu',
			'needles' => array( 'abstract' ),
		),
		array(
			'regex'   => '/\b(?:moroccan(?:[\s-]+arabic)?|arabic|mashrabiya|marroqu[ií]|marroquin[oa]|marokkanisch|marocain|maroko)\b|摩洛哥|阿拉伯|مغربي|عربي|مشربية|ma-rốc/iu',
			'needles' => array( 'moroccan-arabic', 'moroccan', 'arabic' ),
		),
		array(
			'regex'   => '/\b(?:perforated|perforado|perfurado|gelocht|perfor[eé]|berlubang)\b|穿孔|冲孔|打孔|مثقب|đục\s+lỗ/iu',
			'needles' => array( 'perforated' ),
		),
		array(
			'regex'   => '/\b(?:gradient|degradado|gradiente|verlauf|d[eé]grad[eé]|gradien)\b|渐变|تدرج|chuyển\s+sắc/iu',
			'needles' => array( 'gradient' ),
		),
		array(
			'regex'   => '/\b(?:landscape|paisaje|paisagem|landschaft|paysage|pemandangan|lanskap)\b|山水|景观|风景|طبيعي|مشهد\s+طبيعي|phong\s+cảnh|cảnh\s+quan/iu',
			'needles' => array( 'landscape' ),
		),
		array(
			'regex'   => '/\b(?:asian|chinese|asi[aá]tico|chino|chin[êe]s|asiatisch|chinesisch|asiatique|chinois|asia|cina)\b|东方|新中式|中式|亚洲|آسيوي|شرقي|صيني|á\s+đông|châu\s+á|trung\s+quốc/iu',
			'needles' => array( 'asian', 'chinese' ),
		),
		array(
			'regex'   => '/\b(?:vintage(?:[\s-]+retro)?|retro|cl[aá]sico|r[eé]tro|klasik)\b|复古|قديم|cổ\s+điển/iu',
			'needles' => array( 'vintage-retro', 'vintage', 'retro' ),
		),
		array(
			'regex'   => '/\b(?:botanical|floral|flower|leaf|leaves|plant|vine|garden|bamboo|bot[aá]nico|flor|blume|pflanze|blatt|feuille|bunga|tanaman)\b|植物|花卉|树叶|竹|زهور|نبات|cây|hoa|lá/iu',
			'needles' => array( 'leaf', 'floral', 'vine', 'garden', 'bamboo', 'botanical', 'organic' ),
		),
		array(
			'regex'   => '/\b(?:mural|wandbild)\b|壁画|جدارية|tranh\s+tường/iu',
			'needles' => array( 'mural', 'landscape', 'flowing' ),
		),
		array(
			'regex'   => '/\b(?:emt|expanded\s+metal|metal\s+expandido|streckmetall|m[eé]tal\s+d[eé]ploy[eé]|logam\s+bentang)\b|扩张网|拉伸网|معدن\s+ممدد|lưới\s+kéo\s+giãn/iu',
			'needles' => array( 'emt', 'expanded metal', 'geometric', 'perforated' ),
		),
		array(
			'regex'   => '/\b(?:repeat(?:ing)?|repetici[oó]n|repetitivo|wiederholend|r[eé]p[eé]titif|berulang)\b|循环|重复|متكرر|lặp\s+lại/iu',
			'needles' => array( 'repeating', 'repeat', 'tile' ),
		),
		array(
			'regex'   => '/\b(?:glass[\s-]+hybrid|laminated\s+glass|vidrio\s+laminado|vidro\s+laminado|verbundglas|verre\s+feuillet[eé]|kaca\s+laminasi)\b|夹胶玻璃|玻璃复合|زجاج\s+مصفح|kính\s+ghép/iu',
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
		if ( 1 === preg_match( '/(\d{1,2}(?:\.\d+)?)\s*(?:%|percent)?\s*(?:-|to|–|~|到|至)\s*(\d{1,2}(?:\.\d+)?)\s*(?:%|percent|por\s+ciento|porcento|prozent|pour\s+cent|بالمائة|persen|phần\s+trăm|镂空)/iu', $q, $range_match ) ) {
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
		'/\b(?:outdoor|exterior|extern[oa]s?|ext[eé]rieur[es]?|facade|fachada|fassade|façade|brise-vue|sichtschutz|cladding|sunshade|balustrade|railing|weather|außen(?:bereich)?|garde-corps|luar\s+ruangan|eksterior|fasad|ngoài\s+trời|mặt\s+tiền)\b|室外|户外|外墙|立面|庭院|幕墙|遮阳|护栏|栏杆|خارجي(?:ة)?|واجهة|فناء/iu',
		$q
	);
	$asks_indoor  = 1 === preg_match(
		'/\b(?:indoor|interior|intern[oa]s?|int[eé]rieur[es]?|room\s+divider|partition|divider|lobby|innen(?:bereich)?|s[eé]parateur|dalam\s+ruangan|trong\s+nhà|vách\s+ngăn)\b|室内|隔断|屏风|大堂|داخلي(?:ة)?|قاطع/iu',
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
		'privacy'       => array( 'privacy', 'private', '隐私', 'privacidad', 'privacidade', 'privatsphäre', 'intimité', 'خصوصية', 'privasi', 'riêng tư' ),
		'facade'        => array( 'facade', 'exterior', 'outdoor', 'cladding', 'sunshade', 'architectural', '室外', '户外', '外墙', '幕墙', '遮阳', 'fachada', 'fassade', 'façade', 'واجهة' ),
		'architectural' => array( 'architectural', 'facade', 'exterior', 'outdoor', 'cladding', 'sunshade', 'balustrade', 'railing', '室外', '户外', '外墙', '幕墙', '护栏', '栏杆', 'arquitectónico', 'arquitetônico', 'architektonisch', 'architectural', 'معماري' ),
		'decorative'    => array( 'decorative', 'interior', 'partition', 'divider', 'hotel', 'lobby', '室内', '隔断', '屏风', '酒店', '大堂', 'decorativo', 'décoratif', 'dekorativ', 'زخرفي' ),
		'interior'      => array( 'interior', 'partition', 'divider', 'hotel', 'lobby', '室内', '隔断', '屏风', '酒店', '大堂', 'innen', 'intérieur', 'داخلي' ),
		'partition'     => array( 'partition', 'divider', '隔断', '屏风', 'divisoria', 'divisória', 'trennwand', 'séparation', 'sekat', 'vách ngăn', 'قاطع' ),
		'divider'       => array( 'divider', 'partition', '隔断', '屏风', 'divisoria', 'divisória', 'trennwand', 'séparation', 'sekat', 'vách ngăn', 'قاطع' ),
		'botanical'     => array( 'botanical', 'floral', 'leaf', 'plant', '植物', '花卉', 'botánico', 'botânico', 'botanisch', 'botanique', 'نباتي' ),
		'floral'        => array( 'floral', 'flower', 'blossom', '花卉', 'flor', 'fleur', 'blume', 'bunga', 'hoa' ),
		'leaf'          => array( 'leaf', 'leaves', 'botanical', '植物', 'hoja', 'folha', 'blatt', 'feuille', 'daun', 'lá' ),
		'wave'          => array( 'wave', 'ripple', 'ocean', '波浪', 'ola', 'onda', 'welle', 'vague', 'gelombang', 'sóng' ),
		'lattice'       => array( 'lattice', 'grid', '格栅', 'celosía', 'treliça', 'gitter', 'treillis', 'kisi-kisi' ),
		'arch'          => array( 'arch', 'arched', '拱', 'arco', 'bogen', 'arche', 'قوس' ),
		'geometric'     => array( 'geometric', '几何', 'geométrico', 'geometrisch', 'géométrique', 'هندسي' ),
		'organic'       => array( 'organic', '有机', 'orgánico', 'orgânico', 'organisch', 'organique', 'عضوي' ),
		'modern'        => array( 'modern', '现代', 'moderno', 'moderne', 'حديث' ),
		'classic'       => array( 'classic', 'traditional', '经典', '传统', 'clásico', 'clássico', 'klassisch', 'classique', 'تقليدي' ),
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
 * @param string $q            The visitor's question.
 * @param string $visitor_lang The visitor language code.
 * @return array{0: array<int, array<string, mixed>>, 1: string} The search results, and the built context.
 */
function md_kb_endpoint_retrieve( string $q, string $visitor_lang = 'en' ): array {
	if ( ! class_exists( 'RAPLSAICH_Search_Engine' ) ) {
		return md_kb_endpoint_retrieve_from_db( $q, $visitor_lang );
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
 * @param string $q            The visitor's question.
 * @param string $visitor_lang The visitor language code.
 * @return array{0: array<int, array<string, mixed>>, 1: string} The search results, and the built context.
 */
function md_kb_endpoint_retrieve_from_db( string $q, string $visitor_lang = 'en' ): array {
	global $wpdb;
	$knowledge = md_kb_endpoint_validated_table( 'raplsaich_knowledge' );
	if ( '' === $knowledge || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
		return array( array(), '' );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name comes from md_kb_endpoint_validated_table whitelist.
	$prev_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
	try {
		$rows = $wpdb->get_results(
			"SELECT id, title, content FROM {$knowledge} WHERE is_active = 1 ORDER BY id ASC LIMIT 50"
		);
	} finally {
		if ( method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( (bool) $prev_suppress );
		}
	}
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

	if ( ( 'en' !== $visitor_lang || 1 === preg_match( '/[^\x00-\x7F]/', $q ) ) && count( $results ) < 2 ) {
		$fallback_results = array();
		$fallback_blocks  = array();
		$count            = 0;
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
			$fallback_results[] = array(
				'type'      => 'knowledge',
				'source_id' => $id,
				'title'     => $title,
				'content'   => $content,
				'url'       => null,
				'score'     => 1.0,
			);
			$fallback_blocks[]  = '### ' . $title . "\n" . $content;
			++$count;
			if ( $count >= 12 ) {
				break;
			}
		}
		if ( array() !== $fallback_results ) {
			$results = $fallback_results;
			$blocks  = $fallback_blocks;
		}
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
	$prev_suppress = is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : false;
	try {
		if ( 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" ) ) {
			return false;
		}

		$active = $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge} WHERE is_active = 1" );
		if ( null === $active ) {
			// A table older than the active flag: every row is offered, which
			// is how the plugin itself searches that schema.
			$active = $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge}" );
		}
	} finally {
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( (bool) $prev_suppress );
		}
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
 * @param string             $lang    The canonical visitor language code.
 * @return string The footer, '' when there is no label.
 */
function md_kb_endpoint_source_footer( array $sources, string $lang = 'en' ): string {
	$labels = md_kb_endpoint_source_labels( $sources );
	if ( array() === $labels ) {
		return '';
	}
	$headers = array(
		'en'    => 'Based on: ',
		'zh'    => '参考来源：',
		'es'    => 'Basado en: ',
		'pt-br' => 'Com base em: ',
		'de'    => 'Basierend auf: ',
		'fr'    => 'Basé sur : ',
		'ar'    => 'بناءً على: ',
		'id'    => 'Berdasarkan: ',
		'vi'    => 'Dựa trên: ',
	);
	$prefix  = $headers[ $lang ] ?? $headers['en'];
	return "\n\n" . $prefix . implode( '; ', $labels );
}

/**
 * The system prompt the model answers under: only the knowledge provided,
 * no inventions, the known part kept when the rest must be handed off, and
 * the two handoff triggers of the MVP — an explicit ask for a human, and
 * the model's own judgment that it cannot answer.
 *
 * @param string $visitor_lang The canonical visitor language code.
 * @return string The system prompt.
 */
function md_kb_endpoint_system_prompt( string $visitor_lang = 'en' ): string {
	$no_answer = md_kb_endpoint_no_answer_message( $visitor_lang );
	$base      = 'You are the support assistant of metal-divider.com, a B2B architectural metal screen pattern inquiry site. '
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

	if ( 'en' === $visitor_lang ) {
		return $base;
	}

	$lang_names  = array(
		'zh'    => 'Simplified Chinese (简体中文)',
		'es'    => 'Spanish (Español)',
		'pt-br' => 'Brazilian Portuguese (Português do Brasil)',
		'de'    => 'German (Deutsch)',
		'fr'    => 'French (Français)',
		'ar'    => 'Modern Standard Arabic (العربية)',
		'id'    => 'Indonesian (Bahasa Indonesia)',
		'vi'    => 'Vietnamese (Tiếng Việt)',
	);
	$target_lang = $lang_names[ $visitor_lang ] ?? $visitor_lang;
	$zh_glossary = 'zh' === $visitor_lang
		? 'Simplified Chinese terminology rule: strictly use "图案", "屏风", "询盘", and "报价"; NEVER use "产品", "订单", "款式", or "花型". '
		: '';

	return $base . ' '
		. 'IMPORTANT MULTILINGUAL INSTRUCTIONS: '
		. 'Reply naturally and professionally in the language used by the visitor\'s question (or in ' . $target_lang . ' when the question has no clear language of its own). '
		. $zh_glossary
		. 'The only permitted material is strictly Stainless Steel (304 / 316); never state aluminum, corten steel, copper, brass, or mild steel. '
		. 'All pattern links MUST preserve the language prefix /' . $visitor_lang . '/pattern/<slug>/ in their URLs verbatim. '
		. 'When none of the question is covered, return reason "no_knowledge" and reply exactly: "' . $no_answer . '".';
}

/**
 * The messages of one model call: the system prompt, the last turns of
 * history the caller passed (visitor messages, at most six), and the
 * question with the knowledge under it — the empty knowledge included, so
 * the model sees there is none to answer from rather than guessing.
 *
 * @param string        $q            The visitor's question.
 * @param string        $context      The knowledge context, '' when nothing was found.
 * @param array<string> $history      The earlier visitor messages.
 * @param string        $visitor_lang The canonical visitor language code.
 * @return array<int, array{role: string, content: string}> The messages.
 */
function md_kb_endpoint_messages( string $q, string $context, array $history, string $visitor_lang = 'en' ): array {
	$messages = array(
		array(
			'role'    => 'system',
			'content' => md_kb_endpoint_system_prompt( $visitor_lang ),
		),
	);
	foreach ( array_slice( $history, -6 ) as $turn ) {
		if ( 1 === preg_match( '/^Assistant:\s*(.*)$/isu', $turn, $assistant_match ) ) {
			$clean_assistant = trim( (string) preg_replace( '/\n\n(?:Based on:|参考来源：|Basado en:|Com base em:|Basierend auf:|Basé sur\s*:|بناءً على:|Berdasarkan:|Dựa trên:).*$/su', '', $assistant_match[1] ) );
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
 * @param string $lang The canonical language code.
 * @return string The no-knowledge sentence.
 */
function md_kb_endpoint_no_answer_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => "I don't have that information — our team can help.",
		'zh'    => '我目前没有该信息——我们的团队可以为您提供帮助。',
		'es'    => 'No dispongo de esa información; nuestro equipo puede ayudarle.',
		'pt-br' => 'Não tenho essa informação — nossa equipe pode ajudar.',
		'de'    => 'Diese Information liegt mir nicht vor — unser Team hilft Ihnen gerne weiter.',
		'fr'    => 'Je ne dispose pas de cette information — notre équipe peut vous aider.',
		'ar'    => 'لا تتوفر لدي هذه المعلومات حاليًا — يمكن لفريقنا مساعدتك.',
		'id'    => 'Saya tidak memiliki informasi tersebut — tim kami dapat membantu Anda.',
		'vi'    => 'Tôi không có thông tin này — đội ngũ của chúng tôi có thể hỗ trợ bạn.',
	);
	return $messages[ $lang ] ?? $messages['en'];
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
 * @param string                                               $lang   The canonical language code.
 * @return bool Whether the visitor asked something the knowledge cannot answer.
 */
function md_kb_endpoint_is_knowledge_gap( array $answer, string $lang = 'en' ): bool {
	if ( 'no_knowledge' === $answer['reason'] ) {
		return true;
	}
	if ( '' !== $answer['reason'] ) {
		return false;
	}
	$trimmed = trim( $answer['answer'] );
	return md_kb_endpoint_no_answer_message( 'en' ) === $trimmed || ( 'en' !== $lang && md_kb_endpoint_no_answer_message( $lang ) === $trimmed );
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
 * @param string $lang The canonical language code.
 * @return string The fallback message.
 */
function md_kb_endpoint_fallback_message( string $lang = 'en' ): string {
	$messages = array(
		'en'    => "Sorry — I can't look that up right now. Please try again in a moment, or reach our team on WhatsApp at +852 6473 1687.",
		'zh'    => '抱歉，我目前无法查询该信息。请稍后再试，或通过 WhatsApp 联系我们团队：+852 6473 1687。',
		'es'    => 'Disculpe, no puedo consultar eso en este momento. Por favor, inténtelo de nuevo en un momento o contacte a nuestro equipo por WhatsApp al +852 6473 1687.',
		'pt-br' => 'Desculpe, não consigo verificar isso no momento. Por favor, tente novamente em instantes ou fale com nossa equipe pelo WhatsApp em +852 6473 1687.',
		'de'    => 'Entschuldigung, das kann ich gerade nicht nachschlagen. Bitte versuchen Sie es gleich noch einmal oder kontaktieren Sie unser Team per WhatsApp unter +852 6473 1687.',
		'fr'    => 'Désolé, je ne peux pas vérifier cela pour le moment. Veuillez réessayer dans un instant ou contacter notre équipe sur WhatsApp au +852 6473 1687.',
		'ar'    => 'عذرًا، لا يمكنني التحقق من ذلك حاليًا. يُرجى المحاولة مرة أخرى بعد لحظات، أو التواصل مع فريقنا عبر واتساب على الرقم ‎+852 6473 1687.',
		'id'    => 'Maaf, saya tidak dapat memeriksa informasi tersebut saat ini. Silakan coba lagi beberapa saat lagi, atau hubungi tim kami melalui WhatsApp di +852 6473 1687.',
		'vi'    => 'Xin lỗi, hiện tại tôi không thể tra cứu thông tin này. Vui lòng thử lại sau giây lát hoặc liên hệ với đội ngũ của chúng tôi qua WhatsApp tại +852 6473 1687.',
	);
	return $messages[ $lang ] ?? $messages['en'];
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
