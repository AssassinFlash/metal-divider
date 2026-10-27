<?php
/**
 * Tests for the WeCom notification connector.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- this test runs outside WordPress: it drives the connector's handler directly over the option, transient and HTTP stand-ins the bootstrap provides, names the failing case in its own assertion messages (var_export), injects and clears the environment's secret override around one call (putenv), and checks the card against the Basic header's own encoding (base64_encode).

/**
 * The connector's contract, one slice per test: what the LHC event turns
 * into on the two upstream APIs, and what the connector answers.
 *
 * @covers ::md_wecom_notify_handle
 */
final class WecomNotifyTest extends TestCase {

	/**
	 * A clean stand-in world for every test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_options']        = array();
		$GLOBALS['md_mu_test_transients']     = array();
		$GLOBALS['md_mu_test_http']           = array();
		$GLOBALS['md_mu_test_routes']         = array();
		$GLOBALS['md_mu_test_http_responder'] = null;
	}

	/**
	 * The connector's settings, one option the way the deployment would
	 * configure it; nothing secret about these values, they live only in
	 * this test run.
	 */
	private function configure(): void {
		$GLOBALS['md_mu_test_options']['md_wecom_notify'] = array(
			'bearer_token'   => 'inbound-secret',
			'lhc_base_url'   => 'https://lhc.test',
			'lhc_username'   => 'operator',
			'lhc_api_key'    => 'lhc-rest-key',
			'wecom_corp_id'  => 'corp-1',
			'wecom_secret'   => 'wecom-secret-1',
			'wecom_agent_id' => 1000002,
			'wecom_to_user'  => 'chester',
		);
	}

	/**
	 * Answers both upstream APIs the way their contracts say: the autologin
	 * link, the token, the send receipt.
	 *
	 * @param string $autologin_url The link the LHC mock returns.
	 */
	private function upstream_answers( string $autologin_url ): void {
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( $autologin_url ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":' . json_encode( $autologin_url ) . '}',
				);
			}
			if ( str_contains( $url, 'gettoken' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"errmsg":"ok","access_token":"TOK-1","expires_in":7200}',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"errcode":0,"errmsg":"ok"}',
			);
		};
	}

	/**
	 * The outbound calls the last handler run recorded.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>, 2: string}> URL, args and method, in order.
	 */
	private function calls(): array {
		return $GLOBALS['md_mu_test_http'];
	}

	/**
	 * A transfer event the way the LHC webhook's REST action posts it: the
	 * event's own name (a constant the operator writes into the action's
	 * template, one webhook per event), the chat object the dispatcher
	 * passes, and — only for events that carry one — the msg object
	 * (dispatch.tpl.php's argument table; lhgenericbotactionrestapi's
	 * {{args.*}} placeholders).
	 *
	 * @param int|string                $chat_id The chat the event is about; LHC's JSON template emits either representation.
	 * @param string                    $event   The event's name.
	 * @param array<string, mixed>      $chat The chat object's fields.
	 * @param array<string, mixed>|null $msg The msg object, null for none.
	 * @return array<string, mixed> The request: header and body.
	 */
	private function event( int|string $chat_id, string $event = 'chat.genericbot_chat_command_transfer', array $chat = array( 'nick' => 'Jane Architect' ), ?array $msg = array( 'msg' => 'Need a quote for metal screens' ) ): array {
		$body = array(
			'event' => $event,
			'chat'  => array_merge( array( 'id' => $chat_id ), $chat ),
		);
		if ( null !== $msg ) {
			$body['msg'] = $msg;
		}
		return array(
			'header' => 'Bearer inbound-secret',
			'body'   => $body,
		);
	}

	/**
	 * A transfer event reaches the one configured member as a textcard
	 * whose url is exactly the one-time link the LHC returned.
	 */
	public function testDeliversTextcardWithOneTimeLinkToTheConfiguredMember(): void {
		$this->configure();
		$link = 'https://lhc.test/site_admin/user/autologin/HASH/(r)/Y2hhdC8xMjM/(n)/NONCE/(t)/1790000000';
		$this->upstream_answers( $link );

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );

		$calls = $this->calls();
		$this->assertCount( 3, $calls );

		list( $lhc_url, $lhc_args, $lhc_method ) = $calls[0];
		$this->assertSame( 'https://lhc.test/index.php/restapi/generateautologin', $lhc_url );
		$this->assertSame( 'POST', $lhc_method );
		$this->assertSame( 'Basic ' . base64_encode( 'operator:lhc-rest-key' ), $lhc_args['headers']['Authorization'] );
		$lhc_body = json_decode( (string) $lhc_args['body'], true );
		$this->assertSame( 'mdmobile/chat/123', $lhc_body['r'] );
		$this->assertSame( 'operator', $lhc_body['l'] );
		$this->assertIsInt( $lhc_body['t'] );
		$this->assertGreaterThan( 0, $lhc_body['t'] );
		$this->assertLessThanOrEqual( 3600, $lhc_body['t'] );

		list( $token_url, , $token_method ) = $calls[1];
		$this->assertSame( 'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=corp-1&corpsecret=wecom-secret-1', $token_url );
		$this->assertSame( 'GET', $token_method );

		list( $send_url, $send_args, $send_method ) = $calls[2];
		$this->assertSame( 'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=TOK-1', $send_url );
		$this->assertSame( 'POST', $send_method );
		$send_body = json_decode( (string) $send_args['body'], true );
		$this->assertSame( 'chester', $send_body['touser'] );
		$this->assertSame( 1000002, $send_body['agentid'] );
		$this->assertSame( 'textcard', $send_body['msgtype'] );
		$this->assertSame( $link, $send_body['textcard']['url'] );
		$this->assertNotSame( '', (string) ( $send_body['textcard']['btntxt'] ?? '' ) );
		$this->assertStringContainsString( 'Jane Architect', (string) $send_body['textcard']['title'] );
		$this->assertStringContainsString( 'metal screens', (string) $send_body['textcard']['description'] );
	}

	/**
	 * LHC's raw JSON template can encode the numeric chat ID as a string.
	 */
	public function testAcceptsTheLhcNumericStringChatId(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( '123' ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$lhc_body = json_decode( (string) $this->calls()[0][1]['body'], true );
		$this->assertSame( 'mdmobile/chat/123', $lhc_body['r'] );
	}

	/**
	 * The bearer gate refuses what is not the site's own token, and the
	 * refusal is a body-less 401 before any upstream call happens.
	 */
	public function testRefusesEventsWithoutTheBearerToken(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$requests = array(
			'no header at all'  => array(
				'header' => '',
				'body'   => $this->event( 123 )['body'],
			),
			'wrong token'       => array(
				'header' => 'Bearer wrong-token',
				'body'   => $this->event( 123 )['body'],
			),
			'not even a Bearer' => array(
				'header' => 'Basic b3BlcmF0b3I6a2V5',
				'body'   => $this->event( 123 )['body'],
			),
			'token-less site'   => array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 123 )['body'],
			),
		);

		$GLOBALS['md_mu_test_options']['md_wecom_notify']['bearer_token'] = '';
		$result = md_wecom_notify_handle( $requests['token-less site'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( array( 'error' => array( 'code' => 'unauthorized' ) ), $result['body'] );
		$this->assertSame( array(), $this->calls() );
		unset( $GLOBALS['md_mu_test_options']['md_wecom_notify']['bearer_token'] );

		$GLOBALS['md_mu_test_options']['md_wecom_notify']['bearer_token'] = 'inbound-secret';
		foreach ( array_slice( $requests, 0, 3, true ) as $request ) {
			$result = md_wecom_notify_handle( $request );
			$this->assertSame( 401, $result['status'], var_export( $request['header'], true ) );
			$this->assertSame( array( 'error' => array( 'code' => 'unauthorized' ) ), $result['body'] );
		}
		$this->assertSame( array(), $this->calls() );
	}

	/**
	 * An event without the structure the contract names is a 400 before
	 * any upstream call happens: the event's name and the chat it is
	 * about are the notification's whole subject.
	 */
	public function testRefusesEventsWithoutTheNamedStructure(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$bodies = array(
			'no body'            => array(),
			'no event'           => array( 'chat' => array( 'id' => 123 ) ),
			'event not a string' => array(
				'event' => 42,
				'chat'  => array( 'id' => 123 ),
			),
			'no chat'            => array( 'event' => 'chat.genericbot_chat_command_transfer' ),
			'chat not an object' => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => 123,
			),
			'chat without an id' => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => array( 'nick' => 'Jane Architect' ),
			),
			'id not numeric'     => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => array( 'id' => 'not-a-chat-id' ),
			),
			'id zero'            => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => array( 'id' => 0 ),
			),
			'id negative'        => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => array( 'id' => -5 ),
			),
		);
		foreach ( $bodies as $label => $body ) {
			$result = md_wecom_notify_handle(
				array(
					'header' => 'Bearer inbound-secret',
					'body'   => $body,
				)
			);
			$this->assertSame( 400, $result['status'], $label );
			$this->assertSame( array( 'error' => array( 'code' => 'bad_request' ) ), $result['body'], $label );
		}
		$this->assertSame( array(), $this->calls() );
	}

	/**
	 * The webhooks this connector serves are the two transfer events, and
	 * nothing else: another event of the same site — a visitor message, a
	 * chat closed, a mail ticket — is acknowledged and ignored, with not
	 * one upstream call and no chat recorded. One webhook per event is
	 * how the LHC dispatches; the connector does not become an
	 * every-event platform.
	 */
	public function testIgnoresEventsThatAreNotTransfers(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		foreach ( array( 'chat.addmsguser', 'chat.close', 'chat.chat_started', 'chat.web_add_msg_admin', 'mail.conversation_started' ) as $event ) {
			$result = md_wecom_notify_handle( $this->event( 123, $event ) );

			$this->assertSame( 200, $result['status'], $event );
			$this->assertSame(
				array(
					'sent'   => false,
					'reason' => 'ignored',
				),
				$result['body'],
				$event
			);
		}
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * The department transfer notifies too: its event carries no msg
	 * object, so a fixed explanation fills WeCom's required description.
	 */
	public function testNotifiesAChatTransferedEventWithoutAMessage(): void {
		$this->configure();
		$link = 'https://lhc.test/site_admin/user/autologin/HASH/(n)/NONCE/(t)/1790000000';
		$this->upstream_answers( $link );

		$result = md_wecom_notify_handle( $this->event( 321, 'chat.chat_transfered', array( 'nick' => 'Contractor' ), null ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$send_body = json_decode( (string) $this->calls()[2][1]['body'], true );
		$this->assertStringContainsString( 'Contractor', (string) $send_body['textcard']['title'] );
		$this->assertSame( 'Open the live chat conversation and reply to the visitor.', (string) $send_body['textcard']['description'] );
	}

	/**
	 * A site without the connector's settings answers 503, the operator's
	 * missing configuration — not an upstream failure — and makes no call
	 * that would leak a half-configured request.
	 */
	public function testAnswersNotConfiguredWithoutTouchingTheUpstreams(): void {
		$GLOBALS['md_mu_test_options']['md_wecom_notify'] = array( 'bearer_token' => 'inbound-secret' );

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 123 )['body'],
			)
		);

		$this->assertSame( 503, $result['status'] );
		$this->assertSame( array( 'error' => array( 'code' => 'not_configured' ) ), $result['body'] );
		$this->assertSame( array(), $this->calls() );
	}

	/**
	 * A token arrives from the environment as well: the option's absence
	 * is not the site's answer when the deployment injected the secret.
	 */
	public function testReadsTheSecretsFromTheEnvironment(): void {
		$this->configure();
		putenv( 'MD_WECOM_NOTIFY_TOKEN=env-secret' );
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer env-secret',
				'body'   => $this->event( 123 )['body'],
			)
		);

		putenv( 'MD_WECOM_NOTIFY_TOKEN' );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$this->assertCount( 3, $this->calls() );
	}

	/**
	 * A chat notifies once: the second event for the same chat is answered
	 * without a card, and without a single upstream call — the member is
	 * not buzzed for every follow-up message of a chat already handed
	 * over. A different chat still gets its own card.
	 */
	public function testNotifiesAChatOnceAndOnlyOnce(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$event = static fn( int $chat_id ): array => array(
			'header' => 'Bearer inbound-secret',
			'body'   => array(
				'event' => 'chat.genericbot_chat_command_transfer',
				'chat'  => array( 'id' => $chat_id ),
				'msg'   => array( 'msg' => 'message body' ),
			),
		);

		$first = md_wecom_notify_handle( $event( 123 ) );
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( array( 'sent' => true ), $first['body'] );

		$again = md_wecom_notify_handle( $event( 123 ) );
		$this->assertSame( 200, $again['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'duplicate',
			),
			$again['body']
		);
		$this->assertCount( 3, $this->calls() );

		$other = md_wecom_notify_handle( $event( 124 ) );
		$this->assertSame( array( 'sent' => true ), $other['body'] );
		$calls = $this->calls();
		$this->assertCount( 5, $calls );
		$this->assertSame( 'mdmobile/chat/124', json_decode( (string) $calls[3][1]['body'], true )['r'] );
	}

	/**
	 * A chat's record is not kept forever: the notified set is pruned by
	 * its own timestamps, so a chat from a week past can notify again.
	 */
	public function testPrunesTheNotifiedSetByAge(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = array(
			'555' => time() - 8 * 24 * 3600,
			'123' => time(),
		);

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 555 )['body'],
			)
		);

		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$stored = $GLOBALS['md_mu_test_options']['md_wecom_notify_chats'];
		$this->assertArrayHasKey( '555', $stored );
		$this->assertArrayHasKey( '123', $stored );
		$this->assertGreaterThan( time() - 8 * 24 * 3600, $stored['555'] );
	}

	/**
	 * A link that never arrives — the LHC refusing the request, answering
	 * an error, or dying on the network after its one retry — leaves the
	 * chat unrecorded, so the next event can try again, and nothing is
	 * sent to the member: a card without its link is a dead end.
	 */
	public function testFailsClosedWhenTheAutologinLinkIsNotGenerated(): void {
		$this->configure();

		$answers = array(
			'the REST API refuses'        => array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"error":true,"result":"Unauthorized"}',
			),
			'the module answers an error' => array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":true,"result":"Auto login module is disabled!"}',
			),
			'the answer has no link'      => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"error":false,"result":""}',
			),
			'the transport dies'          => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
		);

		foreach ( $answers as $label => $answer ) {
			$GLOBALS['md_mu_test_http']           = array();
			$GLOBALS['md_mu_test_http_responder'] = static function () use ( $answer ): array|\WP_Error {
				return $answer;
			};

			$result = md_wecom_notify_handle(
				array(
					'header' => 'Bearer inbound-secret',
					'body'   => $this->event( 200 + random_int( 1, 1000 ) )['body'],
				)
			);

			$this->assertSame( 502, $result['status'] );
			$this->assertSame( array( 'error' => array( 'code' => 'upstream' ) ), $result['body'] );
			$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );

			$autologin_attempts = 0;
			foreach ( $this->calls() as list( $url ) ) {
				if ( str_contains( $url, 'generateautologin' ) ) {
					++$autologin_attempts;
				}
			}
			$this->assertSame( 'the transport dies' === $label ? 2 : 1, $autologin_attempts, $label );
		}
	}

	/**
	 * A token WeCom refuses to hand out — the API rejecting the
	 * credentials, or the transport dying around it — fails the
	 * notification closed: no card goes out, and the chat stays
	 * unrecorded for the next event.
	 */
	public function testFailsClosedWhenTheAccessTokenIsNotGranted(): void {
		$this->configure();

		$answers = array(
			'the credentials are refused' => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"errcode":40001,"errmsg":"invalid credential"}',
			),
			'the transport dies, twice'   => null,
		);

		foreach ( $answers as $label => $answer ) {
			$GLOBALS['md_mu_test_http']           = array();
			$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( $answer ): array|\WP_Error {
				if ( str_contains( $url, 'generateautologin' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"error":false,"result":"https://lhc.test/site_admin/user/autologin/HASH"}',
					);
				}
				if ( str_contains( $url, 'gettoken' ) ) {
					return null === $answer ? new \WP_Error( 'http_request_failed', 'Connection timed out' ) : $answer;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"errmsg":"ok"}',
				);
			};

			$result = md_wecom_notify_handle(
				array(
					'header' => 'Bearer inbound-secret',
					'body'   => $this->event( 300 + random_int( 1, 1000 ) )['body'],
				)
			);

			$this->assertSame( 502, $result['status'], $label );
			$this->assertSame( array( 'error' => array( 'code' => 'upstream' ) ), $result['body'], $label );
			$token_attempts = 0;
			$sends          = 0;
			foreach ( $this->calls() as list( $url ) ) {
				if ( str_contains( $url, 'gettoken' ) ) {
					++$token_attempts;
				}
				if ( str_contains( $url, 'message/send' ) ) {
					++$sends;
				}
			}
			$this->assertSame( 'the transport dies, twice' === $label ? 2 : 1, $token_attempts, $label );
			$this->assertSame( 0, $sends, $label );
			$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'], $label );
		}
	}

	/**
	 * A send the API refuses — the member, the application, anything but
	 * the token — fails the notification closed after its single attempt:
	 * retrying a rejected card does not make it wanted.
	 */
	public function testFailsClosedWhenTheSendIsRefused(): void {
		$this->configure();
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":"https://lhc.test/site_admin/user/autologin/HASH"}',
				);
			}
			if ( str_contains( $url, 'gettoken' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"access_token":"TOK-1","expires_in":7200}',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"errcode":81013,"errmsg":"user not found"}',
			);
		};

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 777 )['body'],
			)
		);

		$this->assertSame( 502, $result['status'] );
		$this->assertSame( array( 'error' => array( 'code' => 'upstream' ) ), $result['body'] );
		$sends = 0;
		foreach ( $this->calls() as list( $url ) ) {
			if ( str_contains( $url, 'message/send' ) ) {
				++$sends;
			}
		}
		$this->assertSame( 1, $sends );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * A send the token's age refused — the API's 40014 or 42001 — earns
	 * exactly one fresh token and one resend, whatever the cache believed;
	 * the notification still lands.
	 */
	public function testRefreshesAnExpiredTokenAndResendsOnce(): void {
		$this->configure();
		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = array( 1 => time() - 8 * 24 * 3600 );

		$state                                = array(
			'tokens' => 0,
			'sends'  => 0,
		);
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( &$state ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":"https://lhc.test/site_admin/user/autologin/HASH"}',
				);
			}
			if ( str_contains( $url, 'gettoken' ) ) {
				++$state['tokens'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"access_token":"TOK-' . $state['tokens'] . '","expires_in":7200}',
				);
			}
			++$state['sends'];
			if ( 1 === $state['sends'] ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":42001,"errmsg":"access_token expired"}',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"errcode":0,"errmsg":"ok"}',
			);
		};

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 123 )['body'],
			)
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$calls = $this->calls();
		$this->assertCount( 5, $calls );
		$this->assertStringContainsString( 'access_token=TOK-2', (string) $calls[4][0] );
	}

	/**
	 * What leaves the connector carries no long-term credential: the
	 * card's url is the LHC's own one-time link — the autologin path with
	 * its nonce — and neither the card nor any answer ever shows the
	 * REST key, the application secret, the inbound token or the access
	 * token.
	 */
	public function testLeaksNoLongTermCredentialPastTheUpstreamCalls(): void {
		$this->configure();
		$link                                 = 'https://lhc.test/site_admin/user/autologin/HASH/(n)/NONCE/(t)/1790000000';
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( $link ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":' . json_encode( $link ) . '}',
				);
			}
			if ( str_contains( $url, 'gettoken' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"access_token":"TOK-1","expires_in":7200}',
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"errcode":0,"errmsg":"ok"}',
			);
		};

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 999 )['body'],
			)
		);

		$this->assertSame( 200, $result['status'] );
		$card = '';
		foreach ( $this->calls() as list( $url, $args ) ) {
			if ( str_contains( $url, 'message/send' ) ) {
				$card = (string) $args['body'];
			}
		}
		$this->assertNotSame( '', $card );
		$this->assertStringContainsString( '/user/autologin/', $card );
		$this->assertStringContainsString( '(n)/', $card );
		foreach ( array( 'lhc-rest-key', 'wecom-secret-1', 'inbound-secret', 'TOK-1', base64_encode( 'operator:lhc-rest-key' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $card );
			$this->assertStringNotContainsString( $secret, (string) json_encode( $result['body'] ) );
		}
	}

	/**
	 * A send the transport dies on earns its single retry — the network,
	 * not the API, is who is being asked again.
	 */
	public function testRetriesASendTheTransportDiesOn(): void {
		$this->configure();
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ): array|\WP_Error {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":"https://lhc.test/site_admin/user/autologin/HASH"}',
				);
			}
			if ( str_contains( $url, 'gettoken' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"errcode":0,"access_token":"TOK-1","expires_in":7200}',
				);
			}
			return new \WP_Error( 'http_request_failed', 'cURL error 28' );
		};

		$result = md_wecom_notify_handle(
			array(
				'header' => 'Bearer inbound-secret',
				'body'   => $this->event( 432 )['body'],
			)
		);

		$this->assertSame( 502, $result['status'] );
		$sends = 0;
		foreach ( $this->calls() as list( $url ) ) {
			if ( str_contains( $url, 'message/send' ) ) {
				++$sends;
			}
		}
		$this->assertSame( 2, $sends );
	}

	/**
	 * Two first events for one chat race for one card. The connector's
	 * answer is WordPress's own atomic insert — add_option refuses a
	 * name that exists — so the request that wins the insert sends, and
	 * the request that finds the chat already held declines without a
	 * single upstream call: the loser of the race is not a second card.
	 * The occupation here is what the winning request's own add_option
	 * would have left in the options table.
	 */
	public function testDeclinesWhileAnotherRequestHoldsTheChat(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_lock_123'] = time();

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'busy',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * A lock left behind by a request that died mid-flight is not a
	 * silence forever: past its window it is taken over, and the event
	 * notifies as if the lock had never been.
	 */
	public function testTakesOverALockPastItsWindow(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_lock_123'] = time() - 301;

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$this->assertCount( 3, $this->calls() );
	}

	/**
	 * The lock is a hand-off, not a record: whatever the outcome — the
	 * card delivered, or the upstream refusing it — the chat's lock is
	 * released for the next event.
	 */
	public function testReleasesTheLockWhateverTheOutcome(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$delivered = md_wecom_notify_handle( $this->event( 500 ) );
		$this->assertSame( array( 'sent' => true ), $delivered['body'] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_lock_500', $GLOBALS['md_mu_test_options'] );

		$GLOBALS['md_mu_test_http_responder'] = static function (): \WP_Error {
			return new \WP_Error( 'http_request_failed', 'cURL error 28' );
		};
		$refused                              = md_wecom_notify_handle( $this->event( 501 ) );
		$this->assertSame( 502, $refused['status'] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_lock_501', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * WeCom's URL ownership check returns the decrypted echo as a raw body.
	 * The encrypted fixture and signature were generated independently with
	 * Node.js crypto, not by the connector under test.
	 */
	public function testAnswersTheCallbackOwnershipVerification(): void {
		$this->configure();
		$GLOBALS['md_mu_test_options']['md_wecom_notify']['wecom_callback_token']   = 'callback-token';
		$GLOBALS['md_mu_test_options']['md_wecom_notify']['wecom_callback_aes_key'] = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

		$result = md_wecom_notify_callback_handle(
			array(
				'msg_signature' => '84c6ac355253c2f447835d04f4900aabececc642',
				'timestamp'     => '1700000000',
				'nonce'         => 'nonce-1',
				'echostr'       => 'fb2IJy-yWTdpKjuBAEdBdcPrTaOUCIg5eky4udcO2u8j2XOG9sxt2AzKzl8Yamh2',
			)
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'echostr-test', $result['body'] );
		$this->assertSame( array(), $this->calls() );
	}

	/**
	 * The connector attaches itself as one route under the site's own
	 * namespace, with the permission left open so the 401 is the
	 * connector's own answer.
	 */
	public function testRegistersTheNotifyRoute(): void {
		foreach ( $GLOBALS['md_mu_test_hooks']['rest_api_init'] ?? array() as list( $callback ) ) {
			if ( 'md_wecom_notify_register' === $callback ) {
				md_wecom_notify_register();
			}
		}
		$this->assertCount( 2, $GLOBALS['md_mu_test_routes'] );
		list( $namespace, $route, $args ) = $GLOBALS['md_mu_test_routes'][0];
		$this->assertSame( 'md/v1', $namespace );
		$this->assertSame( '/wecom/notify', $route );
		$this->assertSame( 'md_wecom_notify_rest', $args['callback'] );
		$this->assertSame( 'POST', $args['methods'] );
		$this->assertSame( '__return_true', $args['permission_callback'] );
		list( $namespace, $route, $args ) = $GLOBALS['md_mu_test_routes'][1];
		$this->assertSame( 'md/v1', $namespace );
		$this->assertSame( '/wecom/callback', $route );
		$this->assertSame( 'md_wecom_notify_callback_rest', $args['callback'] );
		$this->assertSame( 'GET', $args['methods'] );
		$this->assertSame( '__return_true', $args['permission_callback'] );
	}
}
