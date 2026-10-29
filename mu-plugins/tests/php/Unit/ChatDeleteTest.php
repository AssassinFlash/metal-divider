<?php
/**
 * Tests for the visitor self-service conversation deletion endpoint.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The delete set and the two counter recomputes are pure SQL builders, so
 * their contracts are asserted here: which tables go, and that the mirror
 * of updateActiveChats()/updateDepartmentStats() keeps the userdep and
 * department variants of the inactive set distinct. The deletion itself is
 * exercised against staging with a probe chat.
 *
 * @covers ::md_chat_delete_sweep_sql
 * @covers ::md_chat_delete_hash_ok
 * @covers ::md_chat_delete_userdep_sql
 * @covers ::md_chat_delete_department_sql
 * @covers ::md_chat_delete_forget_wecom_card
 * @covers ::md_chat_delete_register
 */
final class ChatDeleteTest extends TestCase {

	/**
	 * The generic sweep is the chat-domain satellite tables enumerated from
	 * information_schema: unique, lh_-prefixed DELETE statements with one
	 * %d each, no mail id space. The three tables keyed differently
	 * (lh_group_msg, lh_group_chat, lh_transfer) travel through their own
	 * statements, not this list.
	 */
	public function test_sweep_is_the_chat_domain_set(): void {
		$sweeps = md_chat_delete_sweep_sql();

		self::assertCount( 25, $sweeps );
		self::assertSame( $sweeps, array_values( array_unique( $sweeps ) ) );
		foreach ( $sweeps as $sweep ) {
			self::assertMatchesRegularExpression( '/^DELETE FROM lh_[a-z0-9_]+ WHERE chat_id = %d$/', $sweep );
			self::assertStringNotContainsString( 'mailconv', $sweep );
		}
		self::assertContains( 'DELETE FROM lh_msg WHERE chat_id = %d', $sweeps );
		self::assertContains( 'DELETE FROM lh_chat_online_user WHERE chat_id = %d', $sweeps );
		self::assertContains( 'DELETE FROM lh_generic_bot_chat_event WHERE chat_id = %d', $sweeps );
		self::assertContains( 'DELETE FROM lh_abstract_form_collected WHERE chat_id = %d', $sweeps );
		self::assertNotContains( 'lh_group_msg', $sweeps );
		self::assertNotContains( 'lh_group_chat', $sweeps );
		self::assertNotContains( 'lh_transfer', $sweeps );
	}

	/**
	 * Group cleanup mirrors erLhcoreClassModelGroupChat::afterRemove():
	 * messages and members are keyed by the GROUP id, the group rows by the
	 * chat id. A bare chat_id sweep would delete the wrong rows.
	 */
	public function test_group_cleanup_keys_messages_by_group_id(): void {
		$sql = md_chat_delete_group_cleanup_sql();

		self::assertSame( 'SELECT id FROM lh_group_chat WHERE chat_id = %d', $sql['group_ids'] );
		self::assertSame( 'DELETE FROM lh_group_msg WHERE chat_id = %d', $sql['group_msgs'] );
		self::assertSame( 'DELETE FROM lh_group_chat_member WHERE group_id = %d', $sql['members'] );
		self::assertSame( 'DELETE FROM lh_group_chat WHERE chat_id = %d', $sql['groups'] );
	}

	/**
	 * The transfer delete keeps mail transfers: chat_id is a shared id
	 * space, transfer_scope 0 is the chat side (upstream beforeRemove).
	 */
	public function test_transfer_delete_keeps_mail_transfers(): void {
		self::assertSame(
			'DELETE FROM lh_transfer WHERE chat_id = %d AND transfer_scope = 0',
			md_chat_delete_transfer_sql()
		);
	}

	/**
	 * File targets resolve under the LHC root only: normal var/storage rows
	 * resolve, traversal and outside-root paths are dropped, rows without a
	 * file or name are ignored.
	 */
	public function test_file_targets_stay_inside_the_lhc_root(): void {
		$root = '/srv/site/chat/';
		$rows = array(
			(object) array(
				'file_path' => 'var/storage/6b/',
				'name'      => 'a.png',
			),
			array(
				'file_path' => 'var/storage/zz/',
				'name'      => 'b.pdf',
			),
			(object) array(
				'file_path' => '../../../etc/',
				'name'      => 'passwd',
			),
			(object) array(
				'file_path' => '/etc/passwd',
				'name'      => 'x',
			),
			(object) array(
				'file_path' => 'var/storage/../storage/',
				'name'      => 'dotdot.png',
			),
			(object) array(
				'file_path' => '',
				'name'      => 'no-path.png',
			),
		);

		$targets = md_chat_delete_file_targets( $rows, $root );

		self::assertSame(
			array( '/srv/site/chat/var/storage/6b/a.png', '/srv/site/chat/var/storage/zz/b.pdf' ),
			$targets
		);
	}

	/**
	 * Requires the stored 40-character chat hash exactly.
	 */
	public function test_accepts_only_the_stored_chat_hash(): void {
		$stored = 'dryymNnc0foLr9Y9OWbTI5oqH5nX3NROttOCfyUQ';

		self::assertTrue( md_chat_delete_hash_ok( $stored, $stored ) );
		self::assertFalse( md_chat_delete_hash_ok( strtolower( $stored ), $stored ) );
		self::assertFalse( md_chat_delete_hash_ok( 'short', $stored ) );
		self::assertFalse( md_chat_delete_hash_ok( str_repeat( 'x', 40 ), $stored ) );
		self::assertFalse( md_chat_delete_hash_ok( null, $stored ) );
	}

	/**
	 * The operator recompute mirrors updateActiveChats(): five counters over
	 * the user's rows, inactive without CONTACT_FORM (no 2 in the sub set).
	 * The statement is a template — every operator reference is a %d, so no
	 * id can ever reach the database interpolated.
	 */
	public function test_userdep_recompute_mirrors_update_active_chats(): void {
		$sql = md_chat_delete_userdep_sql();

		self::assertStringStartsWith( 'UPDATE lh_userdep ud', $sql );
		self::assertSame( 6, substr_count( $sql, '%d' ) );
		self::assertStringContainsString( 'ud.active_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = %d AND c.status = 1)', $sql );
		self::assertStringContainsString( 'c.status IN (0,1) AND c.status_sub IN (9,3,5)', $sql );
		self::assertStringContainsString( 'FROM lhc_mailconv_conversation m WHERE m.user_id = %d AND m.status = 1', $sql );
		self::assertStringContainsString( 'FROM lhc_mailconv_conversation m WHERE m.user_id = %d AND m.status = 0', $sql );
		self::assertStringNotContainsString( '2,9,3,5', $sql );
		self::assertStringEndsWith( 'WHERE ud.user_id = %d', $sql );
	}

	/**
	 * The department recompute mirrors updateDepartmentStats(): the four chat
	 * counters over dep_id, inactive including CONTACT_FORM (2). The id is a
	 * %d placeholder, never an interpolated literal.
	 */
	public function test_department_recompute_mirrors_update_department_stats(): void {
		$sql = md_chat_delete_department_sql();

		self::assertStringStartsWith( 'UPDATE lh_departament d', $sql );
		self::assertSame( 1, substr_count( $sql, '%d' ) );
		self::assertStringContainsString( 'c.dep_id = d.id AND c.status = 1', $sql );
		self::assertStringContainsString( 'c.dep_id = d.id AND c.status = 5', $sql );
		self::assertStringContainsString( 'c.status IN (0,1) AND c.status_sub IN (2,9,3,5)', $sql );
		self::assertStringNotContainsString( 'c.department', $sql );
		self::assertStringNotContainsString( 'max_load', $sql );
		self::assertStringEndsWith( 'WHERE d.id = %d', $sql );
	}

	/**
	 * The counter recomputes reaches the database only through
	 * md_chat_delete_query(), and the group-id read only through
	 * md_chat_delete_get_col(): the templates' %d come back bound to the
	 * integers, so the logged statements carry ints, never a template.
	 */
	public function test_counter_templates_bind_their_ids_through_prepare(): void {
		md_chat_delete_query( md_chat_delete_userdep_sql(), 7, 7, 7, 7, 7, 7 );
		md_chat_delete_query( md_chat_delete_department_sql(), 3 );
		md_chat_delete_get_col( md_chat_delete_group_cleanup_sql()['group_ids'], 12 );

		$statements = implode( "\n", $GLOBALS['wpdb']->queries );

		self::assertStringContainsString( 'UPDATE lh_userdep ud', $statements );
		self::assertStringContainsString(
			'ud.active_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = 7 AND c.status = 1)',
			$statements
		);
		self::assertStringContainsString( 'WHERE ud.user_id = 7', $statements );
		self::assertStringContainsString( 'WHERE d.id = 3', $statements );
		self::assertStringContainsString( 'SELECT id FROM lh_group_chat WHERE chat_id = 12', $statements );
		self::assertStringNotContainsString( '%d', $statements );
	}

	/**
	 * The cooldown map loses only the deleted chat; an absent chat or a
	 * non-map option leaves the store untouched.
	 */
	public function test_forget_wecom_card_drops_only_the_deleted_chat(): void {
		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = array(
			9  => 1793121891,
			11 => 555,
		);

		md_chat_delete_forget_wecom_card( 9 );

		self::assertSame( array( 11 => 555 ), get_option( 'md_wecom_notify_chats', array() ) );

		md_chat_delete_forget_wecom_card( 404 );

		// PHPStan sees through the test-double option store, but this assertion must still catch a regression in which forget_wecom_card() touches chats it must not.
		self::assertSame( array( 11 => 555 ), get_option( 'md_wecom_notify_chats', array() ) ); // @phpstan-ignore staticMethod.alreadyNarrowedType

		$GLOBALS['md_mu_test_options']['md_wecom_notify_chats'] = 'not-a-map';

		md_chat_delete_forget_wecom_card( 11 );

		self::assertSame( 'not-a-map', get_option( 'md_wecom_notify_chats', array() ) );
	}

	/**
	 * Registers the md/v1 /chat/delete POST route with the same public
	 * permission contract as the other visitor endpoints.
	 */
	public function test_registers_the_delete_route(): void {
		$hooks = $GLOBALS['md_mu_test_hooks'] ?? array();
		self::assertContains( array( 'md_chat_delete_register', 10, 1 ), $hooks['rest_api_init'] ?? array() );

		md_chat_delete_register();

		self::assertContains(
			array(
				'md/v1',
				'/chat/delete',
				array(
					'methods'             => 'POST',
					'callback'            => 'md_chat_delete_respond',
					'permission_callback' => '__return_true',
				),
			),
			$GLOBALS['md_mu_test_routes'] ?? array()
		);
	}

	/**
	 * Resets the bootstrap's stand-in state for the respond-path tests, so
	 * no test sees another's transients or database answers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_transients']   = array();
		$GLOBALS['md_mu_test_db_responder'] = null;
		$GLOBALS['wpdb']->queries           = array();
		$_SERVER['REMOTE_ADDR']             = '192.0.2.30';
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
	 * One request to the route, with the body the browser sends.
	 *
	 * @param int    $chat_id The chat's id.
	 * @param string $hash The chat's hash.
	 * @return \WP_REST_Response The response.
	 */
	private function delete_request( int $chat_id, string $hash ): \WP_REST_Response {
		// The test double's constructor takes the JSON-params map directly; the production stub signature expects $method.
		return md_chat_delete_respond(
			new \WP_REST_Request(
				array( // @phpstan-ignore argument.type
					'chat_id' => $chat_id,
					'hash'    => $hash,
				)
			)
		);
	}

	/**
	 * Answers every row query with the one chat row given; null leaves the
	 * chat missing.
	 *
	 * @param array<string, mixed>|null $row The chat row, null for no chat.
	 * @return void
	 */
	private function answer_row_with( ?array $row ): void {
		$GLOBALS['md_mu_test_db_responder'] = static fn (): ?array => $row;
	}

	/**
	 * The stored hash shape the capability check accepts.
	 *
	 * @return string A 40-character hash.
	 */
	private function stored_hash(): string {
		return 'dryymNnc0foLr9Y9OWbTI5oqH5nX3NROttOCfyUQ';
	}

	/**
	 * Ten unknown chat ids from one address lock the eleventh request out
	 * before the chat row is even read — the database sees ten queries for
	 * eleven requests.
	 */
	public function test_locks_the_address_out_after_ten_unknown_chat_ids(): void {
		$this->answer_row_with( null );

		for ( $i = 0; $i < 10; ++$i ) {
			self::assertSame( 404, $this->delete_request( 100 + $i, $this->stored_hash() )->status );
		}

		$queries_before = count( $GLOBALS['wpdb']->queries );
		$eleventh       = $this->delete_request( 999, $this->stored_hash() );
		self::assertSame( 429, $eleventh->status );
		self::assertSame( 'Too many attempts. Please try again in a minute.', $eleventh->data['message'] );
		self::assertCount( $queries_before, $GLOBALS['wpdb']->queries );
	}

	/**
	 * A wrong hash is a guess at the capability: it answers 403 and leaves
	 * a failure in the address's window.
	 */
	public function test_a_wrong_hash_is_recorded_as_a_guess(): void {
		$this->answer_row_with(
			array(
				'id'      => 7,
				'hash'    => $this->stored_hash(),
				'user_id' => 0,
				'dep_id'  => 0,
			)
		);

		self::assertSame( 403, $this->delete_request( 7, str_repeat( 'x', 40 ) )->status );

		$failures = $GLOBALS['md_mu_test_transients'][ md_chat_delete_failure_key() ] ?? null;
		self::assertIsArray( $failures );
		self::assertCount( 1, $failures );
	}

	/**
	 * The failure window is per address: ten failures from one address do
	 * not lock a second one out, while the first stays locked.
	 */
	public function test_the_failure_window_is_per_address(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->answer_row_with( null );
		for ( $i = 0; $i < 10; ++$i ) {
			self::assertSame( 404, $this->delete_request( 200 + $i, $this->stored_hash() )->status );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->answer_row_with( null );
		self::assertSame( 404, $this->delete_request( 7, $this->stored_hash() )->status );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		self::assertSame( 429, $this->delete_request( 7, $this->stored_hash() )->status );
	}

	/**
	 * The window's key is a digest, not the address: reading the store
	 * cannot learn who asked.
	 */
	public function test_the_failure_key_holds_no_address(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		self::assertSame( 1, preg_match( '/^md_chat_delete_failures_[0-9a-f]{64}$/', md_chat_delete_failure_key() ) );
		self::assertStringNotContainsString( '203.0.113.9', md_chat_delete_failure_key() );
	}
}
