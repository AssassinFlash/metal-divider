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
		$GLOBALS['md_mu_test_options']            = array();
		$GLOBALS['md_mu_test_routes']             = array();
		$GLOBALS['md_mu_test_http']               = array();
		$GLOBALS['md_mu_test_http_responder']     = null;
		$GLOBALS['md_mu_test_db_responder']       = null;
		$GLOBALS['md_mu_test_transients']         = array();
		$GLOBALS['md_mu_test_option_writes']      = array();
		$GLOBALS['md_mu_test_update_option_fail'] = false;
		$GLOBALS['wpdb']->queries                 = array();
		$_SERVER['REMOTE_ADDR']                   = '192.0.2.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Puts the request environment back the way the runner had it.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	/**
	 * One wrong-token request from the given remote address.
	 *
	 * @param string $ip The client's REMOTE_ADDR.
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function wrong_bearer_from( string $ip ): array {
		$_SERVER['REMOTE_ADDR'] = $ip;
		return md_kb_endpoint_answer(
			array(
				'header' => 'Bearer ' . str_repeat( 'w', 48 ),
				'body'   => array( 'q' => 'x' ),
			)
		);
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
		self::assertLessThanOrEqual( 45, $args['timeout'] );
		self::assertGreaterThan( 44.9, $args['timeout'] );
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
	 * faked knowledge engine returning the given hits, a faked model reply,
	 * and a knowledge base that holds something for the engine to have come
	 * from.
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

		$GLOBALS['md_mu_test_db_responder']   = static fn (): string => '5';
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
	 * A visitor question the knowledge cannot answer is handed off and
	 * recorded in Rapls's own unanswered-question option: the loop the
	 * operator works from. The reason says no content matched, and the
	 * entry carries the count Rapls's dashboard shows.
	 */
	public function test_a_knowledge_gap_is_written_to_the_unanswered_log(): void {
		$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'Do you install the screens in Iceland?' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( 'transfer_operator', $out['body']['action'] );
		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? null;
		self::assertIsArray( $log );
		self::assertCount( 1, $log );
		self::assertSame( 'Do you install the screens in Iceland?', $log[0]['q'] );
		self::assertSame( 1, $log[0]['n'] );
		self::assertSame( 'no_context', $log[0]['reason'] );
		self::assertIsInt( $log[0]['t'] );
	}

	/**
	 * The model's own reason is what classifies the gap, not a verbatim
	 * copy of the no-knowledge sentence: a paraphrase with the same
	 * reason is still a gap, and must reach the operator's list.
	 */
	public function test_a_paraphrased_knowledge_gap_is_still_written(): void {
		$this->configure_kb( array(), $this->model_reply( "I don't have that in my notes.", true, 'no_knowledge' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'Do you install the screens in Iceland?' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 1, $log );
		self::assertSame( 'Do you install the screens in Iceland?', $log[0]['q'] );
	}

	/**
	 * A visitor who asks for a human is a handoff but not a knowledge gap:
	 * the operator's list must not fill with requests to be transferred.
	 */
	public function test_an_explicit_request_for_a_human_is_not_written(): void {
		$this->configure_kb( array(), $this->model_reply( 'I am connecting you with our team.', true, 'human_request' ) );

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'I want to talk to a human agent.' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( 'transfer_operator', $out['body']['action'] );
		self::assertArrayNotHasKey( 'raplsaich_unanswered_log', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * An ordinary answer records nothing.
	 */
	public function test_an_answered_question_is_not_written(): void {
		$this->configure_kb( array( $this->a_hit() ), $this->model_reply( 'Standard patterns ship in three weeks.', false, 'none' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'What is the lead time?' ),
			)
		);

		self::assertArrayNotHasKey( 'raplsaich_unanswered_log', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * Context that matched something but still could not ground an answer
	 * is recorded as a grounding gap; the reason is the distinct one Rapls's
	 * dashboard labels.
	 */
	public function test_a_gap_with_matched_context_is_written_as_grounding(): void {
		$this->configure_kb( array( $this->a_hit() ), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'What is the lead time?' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 1, $log );
		self::assertSame( 'grounding', $log[0]['reason'] );
	}

	/**
	 * The same question, case included, only raises the count: the
	 * dashboard does not fill with duplicates, and the refreshed entry moves
	 * to the front the way Rapls's own writer does.
	 */
	public function test_the_same_question_only_increments_its_count(): void {
		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = array(
			array(
				'q'      => 'Do You Install In Iceland?',
				't'      => 100,
				'n'      => 1,
				'reason' => 'grounding',
			),
			array(
				'q'      => 'Something else',
				't'      => 90,
				'n'      => 3,
				'reason' => 'no_context',
			),
		);
		$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'do you install in iceland?' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 2, $log );
		self::assertSame( 'Do You Install In Iceland?', $log[0]['q'] );
		self::assertSame( 2, $log[0]['n'] );
		self::assertGreaterThan( 100, $log[0]['t'] );
		self::assertSame( 'no_context', $log[0]['reason'] );
		self::assertSame( 'Something else', $log[1]['q'] );
	}

	/**
	 * The stored question is plain text (Rapls shows it in the dashboard)
	 * and at most 200 characters, matching Rapls's writer.
	 */
	public function test_the_logged_question_is_stripped_and_capped(): void {
		$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => '<b>' . str_repeat( 'x', 260 ) . '</b>' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 1, $log );
		self::assertSame( str_repeat( 'x', 200 ), $log[0]['q'] );
	}

	/**
	 * The option holds fifty questions at most, the newest first: the
	 * dashboard and Rapls's own CLI read the same cap.
	 */
	public function test_the_log_keeps_fifty_questions(): void {
		$existing = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$existing[] = array(
				'q'      => 'Question ' . $i,
				't'      => 100 + $i,
				'n'      => 1,
				'reason' => 'no_context',
			);
		}
		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = $existing;
		$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'A brand new gap' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 50, $log );
		self::assertSame( 'A brand new gap', $log[0]['q'] );
		self::assertSame( 'Question 48', $log[49]['q'] );
	}

	// phpcs:disable WordPress.PHP.IniSet, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- the test that follows points PHP's own error log at a local temp file, reads it back and removes it; it runs outside WordPress, where wp_delete_file does not exist.
	/**
	 * A failed write is reported in the error log and does not swallow the
	 * handoff: the visitor still reaches the team, and the operator's log
	 * names the failure without the visitor's question.
	 */
	public function test_a_failed_log_write_is_reported_and_the_handoff_survives(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'md-kb-endpoint-unanswered-log-' );
		$before   = (string) ini_get( 'error_log' );
		self::assertNotFalse( $log_file );
		try {
			ini_set( 'error_log', $log_file );
			$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true, 'no_knowledge' ) );
			$GLOBALS['md_mu_test_update_option_fail'] = true;

			$out = md_kb_endpoint_answer(
				array(
					'header' => $this->bearer(),
					'body'   => array( 'q' => 'Do you install the screens in Iceland?' ),
				)
			);

			self::assertSame( 200, $out['status'] );
			self::assertSame( 'transfer_operator', $out['body']['action'] );
			self::assertCount( 2, $GLOBALS['md_mu_test_option_writes'] );
			$written = (string) file_get_contents( $log_file );
			self::assertStringContainsString( '[md-kb-endpoint] unanswered log write failed: reason=no_context', $written );
			self::assertStringNotContainsString( 'Iceland', $written );
		} finally {
			ini_set( 'error_log', $before );
			@unlink( $log_file );
		}
	}
	// phpcs:enable WordPress.PHP.IniSet, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

	/**
	 * A reply from the older prompt carries no reason; its exact
	 * no-knowledge sentence still classifies the gap, so a model that has
	 * not picked up the reason field yet does not lose the log entry.
	 */
	public function test_a_reply_without_a_reason_falls_back_to_the_no_knowledge_sentence(): void {
		$this->configure_kb( array(), $this->model_reply( md_kb_endpoint_no_answer_message(), true ) );

		md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'Do you install the screens in Iceland?' ),
			)
		);

		$log = $GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] ?? array();
		self::assertCount( 1, $log );
	}

	/**
	 * The prompt asks the model to name the handoff reason, which is what
	 * keeps a paraphrase of the gap sentence from being missed.
	 */
	public function test_the_prompt_asks_for_the_handoff_reason(): void {
		$prompt = md_kb_endpoint_system_prompt();

		self::assertStringContainsString( 'no_knowledge', $prompt );
		self::assertStringContainsString( 'human_request', $prompt );
	}

	/**
	 * The model reply body for the tests, in the DeepSeek HTTP shape.
	 *
	 * @param string      $answer  The visitor-facing answer.
	 * @param bool        $handoff Whether the reply hands off.
	 * @param string|null $reason  The reply's reason, when it has one.
	 * @return string The HTTP body.
	 */
	private function model_reply( string $answer, bool $handoff, ?string $reason = null ): string {
		$reply = array(
			'answer'  => $answer,
			'handoff' => $handoff,
		);
		if ( null !== $reason ) {
			$reply['reason'] = $reason;
		}
		return (string) wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => (string) wp_json_encode( $reply ),
						),
					),
				),
			)
		);
	}

	/**
	 * One knowledge hit, enough to make the retrieved context non-empty.
	 *
	 * @return array<string, mixed> The hit.
	 */
	private function a_hit(): array {
		return array(
			'type'      => 'knowledge',
			'source_id' => 12,
			'title'     => 'Lead time',
			'content'   => 'Standard patterns ship in 3 weeks.',
			'url'       => null,
			'score'     => 9.5,
		);
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
	 * Ten wrong tokens from one address are ten 401s; the eleventh is the
	 * 429 of the brute-force gate — the contract's error shape, with the
	 * fallback message a visitor can be shown.
	 */
	public function test_the_eleventh_wrong_token_from_one_address_is_rate_limited(): void {
		$this->configure_token();

		for ( $i = 1; $i <= 10; $i++ ) {
			$out = $this->wrong_bearer_from( '192.0.2.10' );
			self::assertSame( 401, $out['status'], "Failure {$i} is still the ordinary refusal." );
		}

		$eleventh = $this->wrong_bearer_from( '192.0.2.10' );

		self::assertSame( 429, $eleventh['status'] );
		self::assertSame( 'rate_limited', $eleventh['body']['error']['code'] );
		self::assertSame( md_kb_endpoint_fallback_message(), $eleventh['body']['msg'] );
	}

	/**
	 * The window is a minute: failures older than it no longer count, so an
	 * address that stopped guessing a minute ago is not still locked out.
	 */
	public function test_failures_older_than_the_window_stop_counting(): void {
		$this->configure_token();

		// One failure now, so the window's store key exists; then age its
		// ten failures past the minute.
		$this->wrong_bearer_from( '192.0.2.10' );
		$keys = array_keys( $GLOBALS['md_mu_test_transients'] );
		self::assertNotEmpty( $keys );
		$GLOBALS['md_mu_test_transients'][ $keys[0] ] = array_fill( 0, 10, time() - 61 );

		$out = $this->wrong_bearer_from( '192.0.2.10' );

		self::assertSame( 401, $out['status'], 'expired failures do not lock the address out.' );
	}

	/**
	 * Requests that carry the right token are never refused for the
	 * frequency of the chat: the gate counts authentication failures, not
	 * conversations, and a long exchange on one address is not an attack.
	 */
	public function test_valid_requests_are_not_rate_limited(): void {
		$this->configure_token();

		for ( $i = 1; $i <= 15; $i++ ) {
			$out = md_kb_endpoint_answer(
				array(
					'header' => $this->bearer(),
					'body'   => array( 'q' => 'question ' . $i ),
				)
			);
			self::assertNotSame( 429, $out['status'], "A valid request {$i} is never the rate limit." );
		}
	}

	/**
	 * The gate is per address, and the address it reads is the one the
	 * transport reports: a forged X-Forwarded-For neither poisons another
	 * address's window nor hides the real one.
	 */
	public function test_the_gate_reads_the_remote_address_only(): void {
		$this->configure_token();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->wrong_bearer_from( '192.0.2.10' );
		}
		$forged_header_still_limited = $this->wrong_bearer_from( '192.0.2.10' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$other_address = $this->wrong_bearer_from( '198.51.100.99' );

		self::assertSame( 429, $forged_header_still_limited['status'] );
		self::assertSame( 401, $other_address['status'], 'another address answers for its own failures only.' );
	}

	/**
	 * No raw address reaches the store: the window's key is keyed by a
	 * digest, so the counter never becomes a log of who asked.
	 */
	public function test_the_failure_window_key_holds_no_address(): void {
		$this->configure_token();

		$this->wrong_bearer_from( '192.0.2.10' );

		$keys = array_keys( $GLOBALS['md_mu_test_transients'] );
		self::assertNotEmpty( $keys );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			self::assertStringNotContainsString( '192.0.2.10', $key );
			self::assertMatchesRegularExpression( '/^md_kb_endpoint_failures_[0-9a-f]{64}$/', $key );
		}
	}

	/**
	 * A knowledge plugin with nothing indexed — neither its crawl index nor
	 * an active knowledge entry — is the same 503 as no plugin at all: the
	 * model would be answering from no knowledge, and the operator is told
	 * which half of the pipeline to fill.
	 */
	public function test_a_site_with_an_empty_knowledge_base_is_unavailable(): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
		$GLOBALS['md_mu_test_db_responder'] = static fn (): string => '0';

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 503, $out['status'] );
		self::assertSame( 'kb_unavailable', $out['body']['error']['code'] );
		self::assertNotSame( '', $out['body']['msg'] );
		self::assertSame( array(), $this->recorded( 'md_mu_test_http' ) );
	}

	/**
	 * A question nothing matches is not an empty knowledge base: the site's
	 * index is there, the search simply found nothing for this question —
	 * and the model is asked anyway, under a prompt that makes it say it
	 * has nothing (and hand off) rather than guess. This is the path the
	 * acceptance matrix's knowledge-out questions ride on.
	 */
	public function test_a_question_with_no_hits_still_reaches_the_model(): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"I don\'t have that in my notes.\\",\\"handoff\\":true}"}}]}' );
		$GLOBALS['md_mu_test_db_responder'] = static fn (): string => '5';

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'something nothing covers' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		self::assertSame( "I don't have that in my notes.", $out['body']['msg'] );
		self::assertSame( 'transfer_operator', $out['body']['action'] );
		self::assertSame( array(), $out['body']['sources'] );

		$http = $this->recorded( 'md_mu_test_http' );
		self::assertCount( 1, $http );
		$payload = json_decode( (string) $http[0][1]['body'], true );
		self::assertStringContainsString( '(no knowledge found)', end( $payload['messages'] )['content'] );
	}

	/**
	 * The failures worth one retry — a network error, a 429, a 5xx — get
	 * exactly one: the second attempt answers, and the call count proves
	 * no third try. The rest of the conversation is untouched.
	 *
	 * @dataProvider retryable_failures
	 *
	 * @param \WP_Error|array<string, mixed> $first  What the first attempt returns.
	 * @param string                         $kind   The failure's kind, for the message.
	 */
	public function test_a_retryable_failure_is_retried_once( \WP_Error|array $first, string $kind ): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Back on the second try.\\",\\"handoff\\":false}"}}]}' );
		$attempt                              = 0;
		$GLOBALS['md_mu_test_http_responder'] = static function () use ( &$attempt, $first ): \WP_Error|array {
			++$attempt;
			return 1 === $attempt ? $first : array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Back on the second try.\\",\\"handoff\\":false}"}}]}',
			);
		};

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 200, $out['status'], $kind );
		self::assertSame( 'Back on the second try.', $out['body']['msg'], $kind );
		self::assertCount( 2, $this->recorded( 'md_mu_test_http' ), $kind . ' earns exactly one retry.' );
	}

	/**
	 * The retryable failures: a transport error, the upstream's busy signal,
	 * and its own errors.
	 *
	 * @return array<string, array{0: \WP_Error|array{response: array{code: int, message: string}, body: string}, 1: string}>
	 */
	public static function retryable_failures(): array {
		return array(
			'network error' => array( new \WP_Error(), 'network error' ),
			'http 429'      => array(
				array(
					'response' => array(
						'code'    => 429,
						'message' => 'Too Many Requests',
					),
					'body'     => '',
				),
				'429',
			),
			'http 5xx'      => array(
				array(
					'response' => array(
						'code'    => 503,
						'message' => 'Service Unavailable',
					),
					'body'     => '',
				),
				'5xx',
			),
		);
	}

	/**
	 * The failures not worth a retry — the upstream answered, and its answer
	 * is not going to change for the asking: a 4xx, and a 200 whose reply
	 * is not the JSON contract. One attempt each, then the 502.
	 *
	 * @dataProvider unretryable_failures
	 *
	 * @param array<string, mixed> $first What the one attempt returns.
	 * @param string               $kind  The failure's kind, for the message.
	 */
	public function test_an_unretryable_failure_is_asked_once( array $first, string $kind ): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
		$GLOBALS['md_mu_test_http_responder'] = static fn (): array => $first;

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 502, $out['status'], $kind );
		self::assertSame( 'upstream_error', $out['body']['error']['code'], $kind );
		self::assertCount( 1, $this->recorded( 'md_mu_test_http' ), $kind . ' is asked once.' );
	}

	/**
	 * The unretryable failures.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function unretryable_failures(): array {
		return array(
			'4xx'      => array(
				array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => '',
				),
				'4xx',
			),
			'bad json' => array(
				array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '{"choices":[{"message":{"content":"just words"}}]}',
				),
				'a reply that is not the contract',
			),
		);
	}

	// phpcs:disable WordPress.PHP.IniSet, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- the test that follows points PHP's own error log at a local temp file, reads it back and removes it; it runs outside WordPress, where wp_remote_* and wp_delete_file do not exist.
	/**
	 * A failure that survives its retry is written to the server's error
	 * log — the site's rotating php.log, where md-error-recorder points
	 * error_log — with the failure's kind and HTTP status only: the line
	 * names neither the key, nor the question, nor the visitor's data.
	 */
	public function test_the_final_failure_is_logged_without_secrets(): void {
		$log    = tempnam( sys_get_temp_dir(), 'md-kb-endpoint-test-log-' );
		$before = (string) ini_get( 'error_log' );
		self::assertNotFalse( $log );
		try {
			ini_set( 'error_log', $log );
			$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
			$GLOBALS['md_mu_test_http_responder'] = static fn (): \WP_Error => new \WP_Error();

			$out = md_kb_endpoint_answer(
				array(
					'header' => $this->bearer(),
					'body'   => array(
						'q'       => 'delivery time',
						'chat_id' => 'chat-77',
					),
				)
			);

			self::assertSame( 502, $out['status'] );
			$written = (string) file_get_contents( $log );
			self::assertStringContainsString( '[md-kb-endpoint] model call failed after 2 attempt(s): network_error http=0', $written );
			self::assertStringNotContainsString( $this->deepseek_key(), $written );
			self::assertStringNotContainsString( 'delivery time', $written );
			self::assertStringNotContainsString( 'chat-77', $written );
		} finally {
			ini_set( 'error_log', $before );
			@unlink( $log );
		}
	}

	// phpcs:enable WordPress.PHP.IniSet, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

	/**
	 * The model call lives inside one 45-second deadline, attempts included:
	 * the first attempt asks for (at most) the whole budget, and a retry
	 * asks for what is left of it — never a second 45 of its own.
	 */
	public function test_the_model_call_asks_within_the_shared_deadline(): void {
		$this->configure_kb(
			array(),
			'{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Fine.\\",\\"handoff\\":false}"}}]}'
		);

		$out = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		$http = $this->recorded( 'md_mu_test_http' );
		self::assertCount( 1, $http );
		self::assertLessThanOrEqual( 45, $http[0][1]['timeout'] );
		self::assertGreaterThan( 44.9, $http[0][1]['timeout'] );
	}

	/**
	 * A retry spends only the remaining budget: the first attempt fails
	 * after measurable time, and the second's timeout is the deadline minus
	 * what the first one already spent — the elapsed time and the retry's
	 * timeout together stay inside the 45 seconds.
	 */
	public function test_a_retry_spends_only_the_remaining_budget(): void {
		$this->configure_kb(
			array(),
			'{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Back on the second try.\\",\\"handoff\\":false}"}}]}'
		);
		$attempt                              = 0;
		$after_first                          = 0.0;
		$GLOBALS['md_mu_test_http_responder'] = static function () use ( &$attempt, &$after_first ): \WP_Error|array {
			++$attempt;
			if ( 1 === $attempt ) {
				usleep( 60000 );
				return new \WP_Error();
			}
			$after_first = microtime( true );
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '{"choices":[{"message":{"role":"assistant","content":"{\\"answer\\":\\"Back on the second try.\\",\\"handoff\\":false}"}}]}',
			);
		};

		$before = microtime( true );
		$out    = md_kb_endpoint_answer(
			array(
				'header' => $this->bearer(),
				'body'   => array( 'q' => 'delivery time' ),
			)
		);

		self::assertSame( 200, $out['status'] );
		$http = $this->recorded( 'md_mu_test_http' );
		self::assertCount( 2, $http );
		$first  = (float) $http[0][1]['timeout'];
		$second = (float) $http[1][1]['timeout'];
		self::assertGreaterThan( 0.0, $after_first );
		self::assertLessThan( $first, $second, 'the retry asks for less than the first attempt did.' );
		$spent = $after_first - $before;
		self::assertLessThanOrEqual( 45.0 + 0.005, $spent + $second, 'the time gone and the retry\'s timeout stay inside the one deadline.' );
	}

	/**
	 * A deadline with nothing left in it buys no second request: when the
	 * first attempt has spent the budget (a deadline already at its end,
	 * passed straight to the call), the failure is final — one request,
	 * the log line, the 502.
	 */
	public function test_no_second_attempt_when_the_deadline_is_spent(): void {
		$this->configure_kb( array(), '{"choices":[{"message":{"content":"{}"}}]}' );
		$GLOBALS['md_mu_test_http_responder'] = static fn (): \WP_Error => new \WP_Error();

		$out = md_kb_endpoint_call_model(
			array(
				array(
					'role'    => 'user',
					'content' => 'delivery time',
				),
			),
			microtime( true ) + 0.05
		);

		self::assertSame( 'upstream_error', $out );
		self::assertCount( 1, $this->recorded( 'md_mu_test_http' ), 'a spent deadline buys no second request.' );
	}

	/**
	 * The fallback message is the reviewed, final customer copy — pinned
	 * exactly, so a rewrite has to go through the copy review again.
	 */
	public function test_the_fallback_message_is_the_approved_copy(): void {
		self::assertSame(
			"Sorry — I can't look that up right now. Please try again in a moment, or reach our team on WhatsApp at +852 6473 1687.",
			md_kb_endpoint_fallback_message()
		);
	}

	/**
	 * The system prompt carries the reviewed customer-facing rules: the
	 * fixed no-knowledge sentence (with the handoff), no mention of the
	 * knowledge machinery to the visitor, and the site's own word —
	 * pattern, not product.
	 */
	public function test_the_system_prompt_carries_the_approved_rules(): void {
		$prompt = md_kb_endpoint_system_prompt();

		self::assertStringContainsString( "I don't have that information — our team can help.", $prompt );
		self::assertStringContainsString( 'Never mention knowledge, documents or context to the visitor', $prompt );
		self::assertStringContainsString( 'Say pattern, not product.', $prompt );
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
		return array() === $results ? '' : self::CONTEXT;
	}
}
