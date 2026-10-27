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

	if ( ! class_exists( 'RAPLSAICH_Search_Engine' ) || md_kb_endpoint_knowledge_is_empty() ) {
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

	$pattern = md_kb_endpoint_pattern_lookup( $q );
	if ( md_kb_endpoint_asks_commercial( $q ) ) {
		return array(
			'status' => 200,
			'body'   => array(
				'msg'     => md_kb_endpoint_commercial_handoff_message(),
				'action'  => 'transfer_operator',
				'sources' => $pattern['sources'],
			),
		);
	}
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

	list( $results, $context ) = md_kb_endpoint_retrieve( $q );
	if ( '' !== $pattern['context'] ) {
		$context = "PATTERN FACTS (WooCommerce, current publish):\n" . $pattern['context']
			. ( '' !== trim( $context ) ? "\n\nKNOWLEDGE (Rapls):\n" . $context : '' );
	}
	$answer = md_kb_endpoint_call_model( md_kb_endpoint_messages( $q, $context, $history ) );

	if ( is_string( $answer ) ) {
		return array(
			'status' => 502,
			'body'   => array(
				'error' => array( 'code' => $answer ),
				'msg'   => md_kb_endpoint_fallback_message(),
			),
		);
	}

	if ( $answer['handoff'] && md_kb_endpoint_is_knowledge_gap( $answer ) && 0 === $pattern['hits'] ) {
		$trimmed_context = trim( $context );
		$gap_reason      = '' === $trimmed_context ? 'no_context' : 'grounding';
		md_kb_endpoint_log_unanswered( $q, $gap_reason );
	}

	$sources = 0 < $pattern['hits'] ? $pattern['sources'] : md_kb_endpoint_sources( $results );
	return array(
		'status' => 200,
		'body'   => array(
			'msg'     => $answer['answer'],
			'action'  => $answer['handoff'] ? 'transfer_operator' : '',
			'sources' => $sources,
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
	return 'Pricing, lead time, minimum order and samples are confirmed by our team — I am connecting you with them.';
}

/**
 * Whether the question asks for a commercial commitment rather than a
 * pattern fact: price, quote, lead time, MOQ or a sample, in English or
 * Chinese. A hit on the pattern does not change the answer.
 *
 * @param string $q The visitor's question.
 * @return bool Whether the team must answer it.
 */
function md_kb_endpoint_asks_commercial( string $q ): bool {
	return 1 === preg_match(
		'/\b(?:price|pricing|quote|quotation|cost|lead\s*time|delivery\s+(?:time|date)|moq|minimum\s+order|sample)\b|价格|报价|交期|交货|起订|样品/iu',
		$q
	);
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
 * The published products a candidate names: a WooCommerce search, then the
 * exact code or title-prefix matches ahead of the rest, three at most.
 * A non-publish product is never in the query, so a draft cannot be cited.
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
		)
	);
	$posts  = is_array( $query->posts ) ? $query->posts : array();
	$exact  = array();
	$prefix = array();
	$needle = mb_strtolower( $candidate );
	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
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
		$value   = md_kb_endpoint_pattern_field( get_field( $name, $id ) );
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
 * Whether the knowledge base holds anything to answer from: the whole
 * library, not this question's hits — a question nothing matches still
 * reaches the model (which says it has nothing and hands off), while a
 * base with no crawl index row and no active knowledge entry would leave
 * the model answering from nothing at all. The counts go through the
 * knowledge plugin's own whitelist-validated table names (present exactly
 * when the plugin is), and a knowledge table too old for is_active counts
 * its rows unfiltered — the schema's active flag arrived with the
 * knowledge schema v2 (rapls-ai-chatbot 1.19 line).
 *
 * @return bool True when neither knowledge source holds a row.
 */
function md_kb_endpoint_knowledge_is_empty(): bool {
	if ( ! function_exists( 'raplsaich_validated_table' ) ) {
		return true;
	}

	global $wpdb;

	$index     = raplsaich_validated_table( 'raplsaich_index' );
	$knowledge = raplsaich_validated_table( 'raplsaich_knowledge' );
	if ( '' === $index || '' === $knowledge ) {
		return true;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table names come from the knowledge plugin's own whitelist; one count per request, no object cache to bypass.
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
 * The system prompt the model answers under: only the knowledge provided,
 * no inventions, a plain "I don't have that" when the knowledge does not
 * cover it, and the two handoff triggers of the MVP — an explicit ask for a
 * human, and the model's own judgment that it cannot answer.
 *
 * @return string The system prompt.
 */
function md_kb_endpoint_system_prompt(): string {
	$no_answer = md_kb_endpoint_no_answer_message();
	return "You are the support assistant of metal-divider.com, a B2B architectural metal screen pattern inquiry site. Answer the visitor's question ONLY from the knowledge provided in the user message. Pattern facts, when present, are the current published product and outrank any other note about the same pattern. A field marked not confirmed, or an open area of On request, must be said that way — never as zero, and never invented. Do not state a price, a lead time, a minimum order or a sample promise. Do not invent specifications or materials. Never mention knowledge, documents or context to the visitor — the visitor sees only your answer. Say pattern, not product. When the knowledge does not contain the answer, set handoff true, set reason to \"no_knowledge\", and reply exactly: \"{$no_answer}\". When the visitor asks for a human or an agent, set handoff true, set reason to \"human_request\", and reply that you are connecting them with the team — do not use the no-knowledge sentence. Reply with json only: {\"answer\": \"<text for the visitor>\", \"handoff\": true|false, \"reason\": \"no_knowledge\"|\"human_request\"|\"none\"}.";
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

	$attempts = 0;
	while ( true ) {
		++$attempts;
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
						'model'           => 'deepseek-flash',
						'messages'        => $messages,
						'stream'          => false,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$kind      = 'network_error';
			$status    = 0;
			$retryable = true;
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
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
