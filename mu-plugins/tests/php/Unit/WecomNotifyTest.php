<?php
/**
 * Tests for the WeCom notification connector.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv, WordPress.PHP.DevelopmentFunctions.error_log_var_export, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_system_operations_file, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.IniSet -- this test runs outside WordPress: it drives the connector's handler directly over the option, transient and HTTP stand-ins the bootstrap provides, names the failing case in its own assertion messages (var_export), injects and clears the environment's secret override around one call (putenv), checks the card against the Basic header's own encoding (base64_encode), and points error_log at a throwaway file for one call (ini_set, file, unlink).

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
		$GLOBALS['md_mu_test_transient_ttls'] = array();
		$GLOBALS['md_mu_test_http']           = array();
		$GLOBALS['md_mu_test_routes']         = array();
		$GLOBALS['md_mu_test_http_responder'] = null;
	}

	/**
	 * The skip lines the connector wrote while $callback ran: error_log is
	 * pointed at a throwaway file for that span, then put back.
	 *
	 * @param callable(): void $callback The handler call whose skips are captured.
	 * @return array<int, string> The lines, without their trailing newlines.
	 */
	private function captured_skips( callable $callback ): array {
		$log      = tempnam( sys_get_temp_dir(), 'md-wecom-notify-' );
		$previous = ini_get( 'error_log' );
		$logging  = ini_get( 'log_errors' );
		ini_set( 'log_errors', '1' );
		ini_set( 'error_log', false === $log ? '/dev/null' : $log );
		try {
			$callback();
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
			ini_set( 'log_errors', false === $logging ? '' : $logging );
		}
		if ( false === $log || ! is_file( $log ) ) {
			return array();
		}
		$lines = file( $log, FILE_IGNORE_NEW_LINES );
		unlink( $log );
		return is_array( $lines ) ? $lines : array();
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
	 * An event the way the LHC webhook's REST action posts it: the event's
	 * own name (a constant the operator writes into the action's template,
	 * one webhook per event), the chat object the dispatcher passes, the
	 * msg object for the events that carry one, and the transfer command's
	 * is_online flag. The defaults describe a chat an operator can still
	 * join: active (1), accepted two hours ago — the gate's status and
	 * windows are what a test narrows when it cares (dispatch.tpl.php's
	 * argument table; lhgenericbotactionrestapi's {{args.*}} placeholders).
	 *
	 * @param int|string                $chat_id  The chat the event is about; LHC's JSON template emits either representation.
	 * @param string                    $event    The event's name.
	 * @param array<string, mixed>      $chat     The chat object's fields; these win over the defaults.
	 * @param array<string, mixed>|null $msg      The msg object, null for none.
	 * @param bool|null                 $is_online The transfer command's flag; null omits it, as the other events do.
	 * @return array<string, mixed> The request: header and body.
	 */
	private function event( int|string $chat_id, string $event = 'chat.genericbot_chat_command_transfer', array $chat = array( 'nick' => 'Jane Architect' ), ?array $msg = array( 'msg' => 'Need a quote for metal screens' ), ?bool $is_online = true ): array {
		$body = array(
			'event' => $event,
			'chat'  => array_merge(
				array(
					'id'               => $chat_id,
					'status'           => 1,
					'pnd_time'         => time() - 7200,
					'last_op_msg_time' => time() - 7200,
				),
				$chat
			),
		);
		if ( null !== $msg ) {
			$body['msg'] = $msg;
		}
		if ( 'chat.genericbot_chat_command_transfer' === $event && null !== $is_online ) {
			$body['is_online'] = $is_online;
		}
		return array(
			'header' => 'Bearer inbound-secret',
			'body'   => $body,
		);
	}

	/**
	 * A chat the bot handed over and no operator has accepted: the transfer
	 * command's STATUS_PENDING_CHAT and the moment it started waiting.
	 *
	 * @param int $pending_since The time the chat started waiting.
	 * @return array<string, mixed> The chat fields the gate reads.
	 */
	private function pending_chat( int $pending_since ): array {
		return array(
			'status'   => 0,
			'pnd_time' => $pending_since,
		);
	}

	/**
	 * A chat an operator accepted: STATUS_ACTIVE_CHAT and the time the
	 * operator last spoke.
	 *
	 * @param int $operator_spoke The time the operator last spoke.
	 * @return array<string, mixed> The chat fields the gate reads.
	 */
	private function active_chat( int $operator_spoke ): array {
		return array(
			'status'           => 1,
			'last_op_msg_time' => $operator_spoke,
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
	 * The webhooks this connector serves are the two transfer events and
	 * the visitor message, and nothing else: another event of the same site
	 * — a chat closed, a mail ticket — is acknowledged and ignored, with
	 * not one upstream call and no chat recorded. One webhook per event is
	 * how the LHC dispatches; the connector does not become an
	 * every-event platform.
	 */
	public function testIgnoresEventsThatAreNotTransfers(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		foreach ( array( 'chat.close', 'chat.chat_started', 'chat.web_add_msg_admin', 'mail.conversation_started' ) as $event ) {
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
	 *
	 * A transfer is not the visitor's next message: it has a new
	 * recipient, so the reminder windows do not hold it back (a handoff of
	 * an active chat cards at once, #154's gate docblock).
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
	 * A chat's card starts a cooldown, not a permanent silence: a second
	 * event inside the active chat's three-minute window is answered
	 * without a card or an upstream call, an event after the window sends
	 * a fresh card with its own one-time link, and a different chat has
	 * its own window.
	 */
	public function testNotifiesAChatOncePerCooldownWindow(): void {
		$this->configure();
		$links                                = array(
			'https://lhc.test/site_admin/user/autologin/HASH1',
			'https://lhc.test/site_admin/user/autologin/HASH2',
			'https://lhc.test/site_admin/user/autologin/HASH3',
		);
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( &$links ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":' . json_encode( array_shift( $links ) ) . '}',
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

		$first = md_wecom_notify_handle( $this->event( 123 ) );
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( array( 'sent' => true ), $first['body'] );

		$again = md_wecom_notify_handle( $this->event( 123 ) );
		$this->assertSame( 200, $again['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'cooldown',
			),
			$again['body']
		);
		$this->assertCount( 3, $this->calls() );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'][123] = time() - 181;
		$later = md_wecom_notify_handle( $this->event( 123 ) );
		$this->assertSame( array( 'sent' => true ), $later['body'] );

		$other = md_wecom_notify_handle( $this->event( 124 ) );
		$this->assertSame( array( 'sent' => true ), $other['body'] );
		$calls = $this->calls();
		$this->assertCount( 7, $calls );
		$this->assertSame( 'mdmobile/chat/124', json_decode( (string) $calls[5][1]['body'], true )['r'] );
		$second_123_card = json_decode( (string) $calls[4][1]['body'], true );
		$this->assertSame( 'https://lhc.test/site_admin/user/autologin/HASH2', $second_123_card['textcard']['url'] );
	}

	/**
	 * A chat's cooldown record is not kept forever: the map is pruned by
	 * its own timestamps on every read, so a chat whose card is an hour
	 * past is not carrying a stale window into the next event.
	 */
	public function testPrunesTheCooldownSetByAge(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = array(
			'555' => time() - 8 * 24 * 3600,
			'123' => time() - 3601,
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
		$this->assertArrayNotHasKey( '123', $stored );
		$this->assertGreaterThan( time() - 10, $stored['555'] );
	}

	/**
	 * The transfer command's offline branch: the visitor gets no operator, LHC
	 * leaves the chat's status untouched and dispatches the same event with
	 * is_online false (lhgenericbotactioncommand.php:44-56). Nothing is sent,
	 * no cooldown is started, no rate slot is spent and no lock is left — the
	 * event is an acknowledgement, not a notification.
	 */
	public function testSendsNothingForTheOfflineTransferBranch(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array( 'status' => 5 ), array( 'msg' => 'Anybody there?' ), false ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'offline',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_sends', $GLOBALS['md_mu_test_options'] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_lock_123', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * An online transfer of a chat outside the operator's inbox is the
	 * status, not the flag: status 5 with is_online true is a bot chat the
	 * operator cannot join, so the reason is status_not_notifiable.
	 */
	public function testAnswersStatusNotNotifiableForAnOnlineTransferOutsideTheInbox(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array( 'status' => 5 ), null, true ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'status_not_notifiable',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * The online flag is the transfer command's, and its absence is a broken
	 * payload, not an online event: the connector answers the reason and
	 * leaves every record alone rather than guess the operator was there.
	 */
	public function testSendsNothingForATransferWithoutItsOnlineFlag(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array(), null, null ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'is_online_missing',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * A chat's status is what makes a card useful: without it the connector
	 * does not know whether the visitor is waiting or already served, so it
	 * answers the missing field instead of inventing one.
	 */
	public function testSendsNothingWithoutTheChatsStatus(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array( 'status' => null ) ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'status_missing',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * Only a pending (0) or active (1) chat is an operator's inbox: closed,
	 * chatbox, operators-only and bot chats — and any status LHC adds later —
	 * are answered without a card (erlhcoreclassmodelchat.php:728-733).
	 */
	public function testSendsNothingForChatsOutsideTheOperatorsInbox(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		foreach ( array( 2, 3, 4, 5, '7', 99 ) as $status ) {
			$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array( 'status' => $status ) ) );

			$this->assertSame( 200, $result['status'], (string) $status );
			$this->assertSame(
				array(
					'sent'   => false,
					'reason' => 'status_not_notifiable',
				),
				$result['body'],
				(string) $status
			);
		}
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * The visitor-message windows, at their boundaries: a pending chat whose
	 * card already left is a reminder after ten more minutes without an
	 * acceptance, an active chat after three minutes since the operator last
	 * spoke (research §7; site trade-offs, not LHC's or WeCom's). A pending
	 * chat with no delivered card is not held back — the first card's
	 * failure is retried by the next event. A missing timestamp is answered
	 * as missing, and a numeric string is the same fact LHC's JSON emits.
	 */
	public function testHoldsAVisitorMessageUntilItsReminderWindowOpens(): void {
		$now = 1790000000;

		$pending_closed = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( $now - 600 ), array(), $now, true );
		$this->assertFalse( $pending_closed['send'] );
		$this->assertSame( 'not_due', $pending_closed['reason'] );

		$pending_open = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( $now - 601 ), array(), $now, true );
		$this->assertTrue( $pending_open['send'] );
		$this->assertSame( 600, $pending_open['window'] );

		$pending_retry = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( $now - 10 ), array(), $now );
		$this->assertTrue( $pending_retry['send'] );
		$this->assertSame( 600, $pending_retry['window'] );

		$active_closed = md_wecom_notify_gate( 'chat.addmsguser', $this->active_chat( $now - 180 ), array(), $now );
		$this->assertFalse( $active_closed['send'] );
		$this->assertSame( 'not_due', $active_closed['reason'] );

		$active_open = md_wecom_notify_gate( 'chat.addmsguser', $this->active_chat( $now - 181 ), array(), $now );
		$this->assertTrue( $active_open['send'] );
		$this->assertSame( 180, $active_open['window'] );

		$pending_missing = md_wecom_notify_gate( 'chat.addmsguser', array( 'status' => 0 ), array(), $now );
		$this->assertFalse( $pending_missing['send'] );
		$this->assertSame( 'pnd_time_missing', $pending_missing['reason'] );

		$active_missing = md_wecom_notify_gate( 'chat.addmsguser', array( 'status' => 1 ), array(), $now );
		$this->assertFalse( $active_missing['send'] );
		$this->assertSame( 'last_op_missing', $active_missing['reason'] );

		$numeric_strings_chat = array(
			'status'   => '0',
			'pnd_time' => (string) ( $now - 601 ),
		);
		$numeric_strings      = md_wecom_notify_gate( 'chat.addmsguser', $numeric_strings_chat, array(), $now, true );
		$this->assertTrue( $numeric_strings['send'] );
		$this->assertSame( 600, $numeric_strings['window'] );

		$pending_epoch = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( 0 ), array(), $now );
		$this->assertFalse( $pending_epoch['send'] );
		$this->assertSame( 'pnd_time_missing', $pending_epoch['reason'] );

		$active_epoch = md_wecom_notify_gate( 'chat.addmsguser', $this->active_chat( 0 ), array(), $now );
		$this->assertFalse( $active_epoch['send'] );
		$this->assertSame( 'last_op_missing', $active_epoch['reason'] );
	}

	/**
	 * A pending chat whose wait started at the epoch has no real pending
	 * time: 0 is the missing sentinel, not a chat that has waited since
	 * 1970, so the reminder is not sent.
	 */
	public function testAnswersPndTimeMissingForAPendingVisitorMessageAtEpoch(): void {
		$gate = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( 0 ), array(), 1790000000 );
		$this->assertFalse( $gate['send'] );
		$this->assertSame( 'pnd_time_missing', $gate['reason'] );
	}

	/**
	 * An active chat whose operator last spoke at the epoch has no real
	 * last-spoke time: 0 is the missing sentinel, not a chat silent since
	 * 1970, so the reminder is not sent.
	 */
	public function testAnswersLastOpMissingForAnActiveVisitorMessageAtEpoch(): void {
		$gate = md_wecom_notify_gate( 'chat.addmsguser', $this->active_chat( 0 ), array(), 1790000000 );
		$this->assertFalse( $gate['send'] );
		$this->assertSame( 'last_op_missing', $gate['reason'] );
	}

	/**
	 * A timestamp ahead of the connector's clock is not a closed window:
	 * the reminder is answered timestamp_in_future rather than silently
	 * held as not_due, so a skewed clock is visible in the skip log.
	 */
	public function testAnswersTimestampInFutureForAChatWhoseClockLeads(): void {
		$now = 1790000000;

		$pending = md_wecom_notify_gate( 'chat.addmsguser', $this->pending_chat( $now + 5 ), array(), $now, true );
		$this->assertFalse( $pending['send'] );
		$this->assertSame( 'timestamp_in_future', $pending['reason'] );

		$active = md_wecom_notify_gate( 'chat.addmsguser', $this->active_chat( $now + 5 ), array(), $now );
		$this->assertFalse( $active['send'] );
		$this->assertSame( 'timestamp_in_future', $active['reason'] );
	}

	/**
	 * The bot's closechat command dispatches the transfer event's name while
	 * the chat is still open and about to close (lhgenericbotactioncommand.php:216-267).
	 * The card is refused for that command, not by accident of a missing
	 * is_online flag; the ordinary stopchat handoff still cards.
	 */
	public function testSendsNothingForTheBotsCloseCommand(): void {
		$now  = 1790000000;
		$chat = array(
			'status'   => 0,
			'pnd_time' => $now - 10,
		);

		$closing = md_wecom_notify_gate(
			'chat.genericbot_chat_command_transfer',
			$chat,
			array(
				'is_online' => true,
				'command'   => 'closechat',
			),
			$now
		);
		$this->assertFalse( $closing['send'] );
		$this->assertSame( 'closing', $closing['reason'] );

		$stopchat = md_wecom_notify_gate(
			'chat.genericbot_chat_command_transfer',
			$chat,
			array(
				'is_online' => true,
				'command'   => 'stopchat',
			),
			$now
		);
		$this->assertTrue( $stopchat['send'] );
	}

	/**
	 * A pending chat whose first card never left — the send failed, the
	 * upstream answered 502 — retries on the visitor's next message instead
	 * of waiting out the ten minutes; once a card has left, the same
	 * message is held by the reminder window.
	 */
	public function testRetriesAPendingChatWhoseFirstCardNeverLeft(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$first = md_wecom_notify_handle( $this->event( 123, 'chat.addmsguser', $this->pending_chat( time() - 10 ), array( 'msg' => 'Any update?' ) ) );
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( array( 'sent' => true ), $first['body'] );
		$this->assertCount( 3, $this->calls() );
		$this->assertArrayHasKey( 123, $GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] );

		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'][124] = time() - 30;
		$held = md_wecom_notify_handle( $this->event( 124, 'chat.addmsguser', $this->pending_chat( time() - 10 ) ) );
		$this->assertSame( 200, $held['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'not_due',
			),
			$held['body']
		);
		$this->assertCount( 3, $this->calls() );
	}

	/**
	 * A visitor message on a chat that has waited long enough earns the
	 * reminder card, and the chat's window becomes a cooldown; a message
	 * inside an active chat's three minutes is answered without a card.
	 */
	public function testSendsAReminderForAVisitorMessagePastItsWindow(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.addmsguser', $this->pending_chat( time() - 601 ), array( 'msg' => 'Any update on the quote?' ) ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$this->assertCount( 3, $this->calls() );
		$send_body = json_decode( (string) $this->calls()[2][1]['body'], true );
		$this->assertStringContainsString( 'Any update on the quote?', (string) $send_body['textcard']['description'] );
		$this->assertArrayHasKey( 123, $GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] );
		$this->assertCount( 1, $GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] );

		$fresh = md_wecom_notify_handle( $this->event( 124, 'chat.addmsguser', $this->active_chat( time() - 60 ) ) );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'not_due',
			),
			$fresh['body']
		);
		$this->assertCount( 3, $this->calls() );
	}

	/**
	 * A reminder inside the chat's own cooldown is skipped and does not
	 * touch the record: the card already sent still owns the window, so an
	 * immediate visitor message is not a second buzz.
	 */
	public function testHoldsAReminderInsideTheChatsCooldown(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );
		$sent_at = time() - 60;
		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = array( 123 => $sent_at );

		$result = md_wecom_notify_handle( $this->event( 123, 'chat.addmsguser', $this->active_chat( time() - 181 ) ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'cooldown',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertSame( $sent_at, $GLOBALS['md_mu_test_options']['md_wecom_notify_chats'][123] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_sends', $GLOBALS['md_mu_test_options'] );
	}

	/**
	 * Every card carries a link generated for that card: the one-time
	 * autologin link expires after its 3600 seconds (autologin.php:78-79),
	 * so reusing the previous card's url would hand the operator a dead
	 * link. The second card asks LHC again and carries the second answer.
	 */
	public function testRegeneratesTheLinkForEveryCard(): void {
		$this->configure();
		$links                                = array(
			'https://lhc.test/site_admin/user/autologin/HASH1',
			'https://lhc.test/site_admin/user/autologin/HASH2',
		);
		$GLOBALS['md_mu_test_http_responder'] = static function ( string $url ) use ( &$links ): array {
			if ( str_contains( $url, 'generateautologin' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"error":false,"result":' . json_encode( array_shift( $links ) ) . '}',
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

		$this->assertSame( array( 'sent' => true ), md_wecom_notify_handle( $this->event( 123 ) )['body'] );
		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'][123] = time() - 181;
		$this->assertSame( array( 'sent' => true ), md_wecom_notify_handle( $this->event( 123, 'chat.addmsguser', $this->active_chat( time() - 181 ) ) )['body'] );

		$calls = $this->calls();
		$this->assertCount( 5, $calls );
		$first_autologin  = json_decode( (string) $calls[0][1]['body'], true );
		$second_autologin = json_decode( (string) $calls[3][1]['body'], true );
		$this->assertSame( 3600, $first_autologin['t'] );
		$this->assertSame( 3600, $second_autologin['t'] );
		$this->assertSame( 'mdmobile/chat/123', $first_autologin['r'] );
		$this->assertSame( 'mdmobile/chat/123', $second_autologin['r'] );

		$first_card  = json_decode( (string) $calls[2][1]['body'], true );
		$second_card = json_decode( (string) $calls[4][1]['body'], true );
		$this->assertSame( 'https://lhc.test/site_admin/user/autologin/HASH1', $first_card['textcard']['url'] );
		$this->assertSame( 'https://lhc.test/site_admin/user/autologin/HASH2', $second_card['textcard']['url'] );
		$this->assertNotSame( $first_card['textcard']['url'], $second_card['textcard']['url'] );
	}

	/**
	 * WeCom drops what exceeds 30 messages to one member in a minute
	 * (message/send: "每应用对同一个成员不可超过30次/分钟，1000次/小时，超过部分会被丢弃不下发").
	 * The connector answers the ceiling's reason and spends nothing: no
	 * upstream call, no cooldown, no rate slot, no lock.
	 */
	public function testSkipsWhenTheMinuteCeilingIsReached(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );
		$recent = array_fill( 0, 30, time() - 10 );
		$GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] = $recent;

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'rate_limited_minute',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
		$this->assertArrayNotHasKey( 'md_wecom_notify_lock_123', $GLOBALS['md_mu_test_options'] );
		$this->assertSame( $recent, $GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] );
	}

	/**
	 * The hour's own ceiling: 1000 cards inside the hour (none of them in
	 * the last minute) is the ticket's official limit and still blocks the
	 * next card, with the same accounted skip.
	 */
	public function testSkipsWhenTheHourCeilingIsReached(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );
		$hour = array_fill( 0, 1000, time() - 120 );
		$GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] = $hour;

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'rate_limited_hour',
			),
			$result['body']
		);
		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( 'md_wecom_notify_chats', $GLOBALS['md_mu_test_options'] );
		$this->assertSame( $hour, $GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] );
	}

	/**
	 * A skip is not only an answer: the route adapter turns every 200 into
	 * an empty 204, so the reason is written to the site's error log. The
	 * line names the chat, the event and the reason, and nothing of the
	 * visitor.
	 */
	public function testLogsTheSkipReasonTheRouteCannotCarry(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );

		$result = array();
		$lines  = $this->captured_skips(
			function () use ( &$result ): void {
				$result = md_wecom_notify_handle( $this->event( 123, 'chat.genericbot_chat_command_transfer', array( 'status' => 5 ), array( 'msg' => 'Anybody there?' ), false ) );
			}
		);

		$this->assertSame(
			array(
				'sent'   => false,
				'reason' => 'offline',
			),
			$result['body']
		);
		$this->assertNotSame( array(), array_filter( $lines, static fn( string $line ): bool => str_contains( $line, '[md-wecom-notify] skipped chat #123 event chat.genericbot_chat_command_transfer: offline' ) ) );
	}

	/**
	 * The last slot inside both ceilings is still a send: 29 in the last
	 * minute and 999 in the hour is inside WeCom's allowance, and the card
	 * that uses it is recorded so the next event sees the full window.
	 */
	public function testSendsWhileTheCeilingsHaveRoom(): void {
		$this->configure();
		$this->upstream_answers( 'https://lhc.test/site_admin/user/autologin/HASH' );
		$sends = array_merge( array_fill( 0, 29, time() - 10 ), array_fill( 0, 970, time() - 120 ) );
		$GLOBALS['md_mu_test_options']['md_wecom_notify_sends'] = $sends;

		$result = md_wecom_notify_handle( $this->event( 123 ) );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( array( 'sent' => true ), $result['body'] );
		$this->assertCount( 3, $this->calls() );
		$recorded = $GLOBALS['md_mu_test_options']['md_wecom_notify_sends'];
		$this->assertCount( 1000, $recorded );
		$this->assertGreaterThan( time() - 5, end( $recorded ) );
		$this->assertArrayHasKey( 123, $GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] );
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

		$cached = $GLOBALS['md_mu_test_transients']['md_wecom_notify_token'];
		$this->assertIsArray( $cached );
		$this->assertGreaterThan( time(), (int) $cached['expires'] );
		$ttl = $GLOBALS['md_mu_test_transient_ttls']['md_wecom_notify_token'];
		$this->assertIsInt( $ttl );
		$this->assertLessThan( 7200, $ttl );
		$this->assertGreaterThanOrEqual( 60, $ttl );
		$this->assertEqualsWithDelta( (int) $cached['expires'] - time(), $ttl, 2 );
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
