<?php
/**
 * Tests for the knowledge endpoint.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound, Generic.Files.OneObjectStructurePerFile.MultipleFound -- the hook names under test are WordPress's own, and the file's fake knowledge engine rides along with its test.

/**
 * The knowledge endpoint's HTTP contract, outside WordPress: the route it
 * registers, the bearer gate, the request validation, the knowledge
 * retrieval, the model call it makes and the answer shape it returns. The
 * input is the request the route adapter receives (the Authorization header
 * and the decoded JSON body) and the output is the status and body the
 * adapter sends — the same seam POST /wp-json/md/v1/kb/answer is, one
 * WordPress object layer down.
 *
 * @covers ::md_kb_endpoint_register
 * @covers ::md_kb_endpoint_answer
 */
final class KbEndpointTest extends TestCase {

	/**
	 * Resets the bootstrap's stand-in state, so no test sees another's
	 * options or HTTP traffic.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_options']        = array();
		$GLOBALS['md_mu_test_routes']         = array();
		$GLOBALS['md_mu_test_http']           = array();
		$GLOBALS['md_mu_test_http_responder'] = null;
	}

	/**
	 * The site's bearer token, as the endpoint reads it: an option, and for
	 * these tests always set — the token never lives in the repository.
	 *
	 * @return string The token the endpoint will require.
	 */
	private function configure_token(): string {
		$token = str_repeat( 'a', 32 ) . '-kb-endpoint-test';
		self::assertGreaterThanOrEqual( 32, strlen( $token ) );
		$GLOBALS['md_mu_test_options']['md_kb_endpoint'] = array(
			'bearer_token' => $token,
		);
		return $token;
	}

	/**
	 * The Authorization header value for a configured token.
	 *
	 * @return string The header, as the caller under test sends it.
	 */
	private function bearer(): string {
		$token = $GLOBALS['md_mu_test_options']['md_kb_endpoint']['bearer_token'] ?? '';
		self::assertNotSame( '', $token );
		return 'Bearer ' . $token;
	}

	/**
	 * A request without the Authorization header never reaches the knowledge:
	 * 401, in the error shape the LHC output combinations read.
	 */
	public function test_a_request_without_a_bearer_token_is_refused(): void {
		$this->configure_token();

		$out = md_kb_endpoint_answer(
			array(
				'header' => '',
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 401, $out['status'] );
		self::assertSame( array( 'error' => array( 'code' => 'unauthorized' ) ), $out['body'] );
	}

	/**
	 * A wrong token — and a non-Bearer scheme with it — is refused the same
	 * way, without a hint of how close it was.
	 */
	public function test_a_wrong_bearer_token_is_refused(): void {
		$token = $this->configure_token();

		$wrong  = md_kb_endpoint_answer(
			array(
				'header' => 'Bearer ' . str_repeat( 'b', strlen( $token ) ),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);
		$scheme = md_kb_endpoint_answer(
			array(
				'header' => 'Basic ' . $token,
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 401, $wrong['status'] );
		self::assertSame( array( 'error' => array( 'code' => 'unauthorized' ) ), $wrong['body'] );
		self::assertSame( 401, $scheme['status'] );
	}

	/**
	 * With no token configured at all the endpoint refuses everything: an
	 * unconfigured site answers nothing rather than everything.
	 */
	public function test_an_unconfigured_endpoint_refuses_everything(): void {
		$out = md_kb_endpoint_answer(
			array(
				'header' => 'Bearer anything-at-all',
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 401, $out['status'] );
		self::assertSame( array( 'error' => array( 'code' => 'unauthorized' ) ), $out['body'] );
	}

	/**
	 * The question is the one required parameter: absent, empty, not a
	 * string, or longer than the 4000 characters a visitor message can hold,
	 * the request never spends a knowledge search or a model call on it.
	 */
	public function test_a_request_without_a_usable_q_is_refused(): void {
		$this->configure_token();
		$cases = array(
			'absent'          => array(),
			'empty'           => array( 'q' => '' ),
			'not a string'    => array( 'q' => 42 ),
			'whitespace only' => array( 'q' => '  ' ),
			'too long'        => array( 'q' => str_repeat( 'x', 4001 ) ),
		);

		foreach ( $cases as $label => $body ) {
			$out = md_kb_endpoint_answer(
				array(
					'header' => $this->bearer(),
					'body'   => $body,
				)
			);

			self::assertSame( 400, $out['status'], "A {$label} q is not a usable question." );
			self::assertSame( array( 'error' => array( 'code' => 'bad_request' ) ), $out['body'] );
		}
	}

	/**
	 * The boundary itself passes: exactly 4000 characters is a question, and
	 * what comes back is any later stage's answer, not the validation's 400.
	 */
	public function test_a_4000_character_q_is_a_question(): void {
		$this->configure_token();

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => str_repeat( 'x', 4000 ) ),
			)
		);

		self::assertNotSame( 400, $out['status'] );
	}

	/**
	 * Without the knowledge plugin there is nothing to answer from, and the
	 * endpoint says so instead of letting the model answer from no
	 * knowledge: 503, with the fallback message the LHC failure combination
	 * can show the visitor.
	 */
	public function test_a_site_without_the_knowledge_engine_is_unavailable(): void {
		$this->configure_token();
		self::assertFalse( class_exists( 'RAPLSAICH_Search_Engine' ) );

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 503, $out['status'] );
		self::assertSame( 'kb_unavailable', $out['body']['error']['code'] );
		self::assertNotSame( '', $out['body']['msg'] );
	}

	/**
	 * The happy path, end to end: the knowledge engine returns two hits (a
	 * knowledge entry and a crawled page), DeepSeek answers in the requested
	 * JSON shape, and the response is the contract the LHC output
	 * combinations parse — the answer, the empty action, and the audit trail
	 * of which knowledge was used. The model call itself is asserted too:
	 * the official address, the 45-second budget, deepseek-flash without
	 * streaming, the site's key, and the knowledge riding along to it.
	 */
	public function test_a_question_is_answered_from_the_knowledge(): void {
		$this->configure_kb(
			array(
				array(
					'type'      => 'knowledge',
					'source_id' => 12,
					'title'     => 'Lead time',
					'content'   => 'Standard patterns ship in 3 weeks.',
					'url'       => null,
					'score'     => 9.5,
				),
				array(
					'type'      => 'index',
					'post_id'   => 146,
					'post_type' => 'product',
					'title'     => 'Geometric Weave',
					'content'   => 'Pattern page content.',
					'url'       => '/pattern/geometric-weave',
					'score'     => 8.1,
				),
			),
			'{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Standard patterns ship in 3 weeks.\\",\\"handoff\\":false}"}}]}'
		);

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array(
					'q'       => 'delivery time',
					'chat_id' => '77',
					'history' => array( 'do you ship to the EU?' ),
				),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( 'Standard patterns ship in 3 weeks.', $out['body']['msg'] );
		self::assertSame( '', $out['body']['action'] );
		self::assertSame( array( 'knowledge#12 Lead time', 'product#146 Geometric Weave' ), $out['body']['sources'] );

		$engine_calls = \Md\Mu\Tests\Unit\FakeRaplsEngine::$calls;
		self::assertContains( array( 'set_bot_filters', array(), true, true ), $engine_calls );
		self::assertContains( array( 'search', 'delivery time', 5 ), $engine_calls );

		$http = $this->recorded( 'md_mu_test_http' );
		self::assertCount( 1, $http );
		list( $url, $args ) = $http[0];
		self::assertSame( 'https://api.deepseek.com/chat/completions', $url );
		self::assertSame( 45, $args['timeout'] );
		self::assertSame(
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->deepseek_key(),
			),
			$args['headers']
		);
		$payload = json_decode( (string) $args['body'], true );
		self::assertSame( 'deepseek-flash', $payload['model'] );
		self::assertFalse( $payload['stream'] );
		self::assertSame( array( 'type' => 'json_object' ), $payload['response_format'] );
		self::assertSame( 'do you ship to the EU?', $payload['messages'][1]['content'] );
		self::assertStringContainsString( 'delivery time', end( $payload['messages'] )['content'] );
		self::assertStringContainsString( FakeRaplsEngine::CONTEXT, end( $payload['messages'] )['content'] );
	}

	/**
	 * The full knowledge-side configuration: the token, the DeepSeek key, a
	 * faked knowledge engine returning the given hits, and a faked model
	 * reply.
	 *
	 * @param array<int, array<string, mixed>> $hits        The search results the engine returns.
	 * @param string                           $model_reply The DeepSeek HTTP body.
	 * @return void
	 */
	private function configure_kb( array $hits, string $model_reply ): void {
		$this->configure_token();
		$GLOBALS['md_mu_test_options']['md_kb_endpoint']['deepseek_api_key'] = $this->deepseek_key();

		if ( ! class_exists( 'RAPLSAICH_Search_Engine', false ) ) {
			class_alias( FakeRaplsEngine::class, 'RAPLSAICH_Search_Engine' );
		}
		FakeRaplsEngine::$hits = $hits;

		$GLOBALS['md_mu_test_http_responder'] = static fn (): array => array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => $model_reply,
		);
	}

	/**
	 * The DeepSeek key of the test configuration, never a real one.
	 *
	 * @return string The key.
	 */
	private function deepseek_key(): string {
		return 'test-deepseek-key';
	}

	/**
	 * The model's own judgment is one of the two handoff triggers: when it
	 * says handoff, the action names the transfer the LHC output combination
	 * matches on, and the answer still goes to the visitor with its audit
	 * trail.
	 */
	public function test_a_model_handoff_transfers_to_the_operator(): void {
		$this->configure_kb(
			array(),
			'{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"I will get a colleague for you.\\",\\"handoff\\":true}"}}]}'
		);

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'let me talk to a human' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( 'I will get a colleague for you.', $out['body']['msg'] );
		self::assertSame( 'transfer_operator', $out['body']['action'] );
		self::assertSame( array(), $out['body']['sources'] );
	}

	/**
	 * A site without a model key is configured to answer nothing, and says
	 * so distinctly from the missing knowledge: the two 503s tell the
	 * operator which half of the pipeline to fix.
	 */
	public function test_a_site_without_a_model_key_is_not_configured(): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
		unset( $GLOBALS['md_mu_test_options']['md_kb_endpoint']['deepseek_api_key'] );

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 503, $out['status'] );
		self::assertSame( 'not_configured', $out['body']['error']['code'] );
		self::assertNotSame( '', $out['body']['msg'] );
		self::assertSame( array(), $this->recorded( 'md_mu_test_http' ) );
	}

	/**
	 * Every way the model call can fail — a timeout, an upstream error
	 * status, a reply that is not the JSON contract — is one 502 with the
	 * fallback message: the visitor is never shown a bare error, and the
	 * LHC failure combination has a body to fall back to.
	 */
	public function test_a_failed_model_call_returns_the_fallback(): void {
		$replies = array(
			'timeout'    => static fn (): \WP_Error => new \WP_Error(),
			'bad status' => static fn (): array => array(
				'response' => array(
					'code'    => 429,
					'message' => 'Too Many Requests',
				),
				'body'     => '{"error":{"message":"rate limited"}}',
			),
			'not json'   => static fn (): array => array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '{"choices":[{"message":{"content":"I cannot help with that."}}]}',
			),
			'no answer'  => static fn (): array => array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '{"choices":[{"message":{"content":"{\\"handoff\\":false}"}}]}',
			),
		);

		foreach ( $replies as $case => $responder ) {
			$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
			$GLOBALS['md_mu_test_http_responder'] = $responder;

			$out = md_kb_endpoint_answer(
				array(
					'header' => $this->bearer(),
					'body'   => array( 'q' => 'delivery time' ),
				)
			);

			self::assertSame( 502, $out['status'], "Case {$case} must be the upstream 502." );
			self::assertSame( 'upstream_error', $out['body']['error']['code'], "Case {$case} must name the upstream." );
			self::assertSame( md_kb_endpoint_fallback_message(), $out['body']['msg'], "Case {$case} must carry the fallback message." );
		}
	}

	/**
	 * A reply wrapped in a code fence is still the contract: the JSON mode
	 * asks for the object, but a fence around it is tolerated rather than
	 * turned into a visitor-facing failure.
	 */
	public function test_a_fenced_model_reply_is_still_the_answer(): void {
		$this->configure_kb(
			array(),
			'{"choices":[{"message":{"role":"assistant","content":"```json\n{\\"answer\\":\\"Fenced but fine.\\",\\"handoff\\":false}\n```"}}]}'
		);

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( 'Fenced but fine.', $out['body']['msg'] );
		self::assertSame( '', $out['body']['action'] );
	}

	/**
	 * The endpoint registers itself on rest_api_init, and the route it
	 * registers is the one the LHC bot is configured against: the md/v1
	 * namespace, /kb/answer, POST.
	 */
	public function test_the_file_registers_the_answer_route(): void {
		$hooks = $this->recorded( 'md_mu_test_hooks' );

		self::assertContains( array( 'md_kb_endpoint_register', 10, 1 ), $hooks['rest_api_init'] ?? array() );

		md_kb_endpoint_register();

		$routes = $this->recorded( 'md_mu_test_routes' );
		self::assertContains(
			array(
				'md/v1',
				'/kb/answer',
				array(
					'methods'             => 'POST',
					'callback'            => 'md_kb_endpoint_respond',
					'permission_callback' => '__return_true',
				),
			),
			$routes
		);
	}

	/**
	 * A recorded bootstrap global, reset between the tests.
	 *
	 * @param string $name The global's name.
	 * @return array<int|string, mixed> The global's value, or an empty array.
	 */
	private function recorded( string $name ): array {
		return $this->isArray( $GLOBALS[ $name ] ?? array() );
	}

	/**
	 * PHPUnit's type narrowing for a mixed global.
	 *
	 * @param mixed $value What the global holds.
	 * @return array<int|string, mixed> The same value as an array.
	 */
	private function isArray( mixed $value ): array {
		self::assertIsArray( $value );
		return $value;
	}
}

/**
 * The knowledge engine the happy-path tests stand in for: the calls the
 * endpoint makes land in $calls, the configured $hits come back from
 * search(), and build_context() returns a fixed marker the model-request
 * assertion looks for inside the user message.
 */
final class FakeRaplsEngine {

	public const CONTEXT = 'FAKE KNOWLEDGE CONTEXT';

	/**
	 * The hits search() returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $hits = array();

	/**
	 * The calls the endpoint made, in order.
	 *
	 * @var array<int, mixed>
	 */
	public static array $calls = array();

	/**
	 * Records the filter call.
	 *
	 * @param string[] $categories   The categories asked for.
	 * @param bool     $use_crawl    Whether the crawl index is in.
	 * @param bool     $use_knowledge Whether the knowledge base is in.
	 * @return void
	 */
	public function set_bot_filters( array $categories = array(), bool $use_crawl = true, bool $use_knowledge = true ): void {
		self::$calls[] = array( 'set_bot_filters', $categories, $use_crawl, $use_knowledge );
	}

	/**
	 * Records the search and returns the configured hits.
	 *
	 * @param string $query The question.
	 * @param int    $limit How many hits were asked for.
	 * @return array<int, array<string, mixed>> The configured hits.
	 */
	public function search( string $query, int $limit = 3 ): array {
		self::$calls[] = array( 'search', $query, $limit );
		return self::$hits;
	}

	/**
	 * Records the context build and returns the marker.
	 *
	 * @param array<int, array<string, mixed>> $results    The hits.
	 * @param int                              $max_length The context budget.
	 * @param string                           $query      The question.
	 * @return string The marker.
	 */
	public function build_context( array $results, int $max_length = 50000, string $query = '' ): string {
		self::$calls[] = array( 'build_context', $results, $max_length, $query );
		return self::CONTEXT;
	}
}
