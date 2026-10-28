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
 * @covers ::md_chat_delete_tables
 * @covers ::md_chat_delete_hash_ok
 * @covers ::md_chat_delete_userdep_sql
 * @covers ::md_chat_delete_department_sql
 * @covers ::md_chat_delete_forget_wecom_card
 * @covers ::md_chat_delete_register
 */
final class ChatDeleteTest extends TestCase {

	/**
	 * The generic sweep is the chat-domain satellite tables enumerated from
	 * information_schema: unique, lh_-prefixed, no mail id space. The three
	 * tables keyed differently (lh_group_msg, lh_group_chat, lh_transfer)
	 * travel through their own statements, not this list.
	 */
	public function test_tables_are_the_chat_domain_set(): void {
		$tables = md_chat_delete_tables();

		self::assertCount( 25, $tables );
		self::assertSame( $tables, array_values( array_unique( $tables ) ) );
		foreach ( $tables as $table ) {
			self::assertStringStartsWith( 'lh_', $table );
			self::assertStringNotContainsString( 'mailconv', $table );
		}
		self::assertContains( 'lh_msg', $tables );
		self::assertContains( 'lh_chat_online_user', $tables );
		self::assertContains( 'lh_generic_bot_chat_event', $tables );
		self::assertContains( 'lh_abstract_form_collected', $tables );
		self::assertNotContains( 'lh_group_msg', $tables );
		self::assertNotContains( 'lh_group_chat', $tables );
		self::assertNotContains( 'lh_transfer', $tables );
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
			(object) array( 'file_path' => 'var/storage/6b/', 'name' => 'a.png' ),
			array( 'file_path' => 'var/storage/zz/', 'name' => 'b.pdf' ),
			(object) array( 'file_path' => '../../../etc/', 'name' => 'passwd' ),
			(object) array( 'file_path' => '/etc/passwd', 'name' => 'x' ),
			(object) array( 'file_path' => 'var/storage/../storage/', 'name' => 'dotdot.png' ),
			(object) array( 'file_path' => '', 'name' => 'no-path.png' ),
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
	 */
	public function test_userdep_recompute_mirrors_update_active_chats(): void {
		$sql = md_chat_delete_userdep_sql( 7 );

		self::assertStringContainsString( 'UPDATE lh_userdep ud', $sql );
		self::assertStringContainsString( 'ud.active_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = 7 AND c.status = 1)', $sql );
		self::assertStringContainsString( 'c.status IN (0,1) AND c.status_sub IN (9,3,5)', $sql );
		self::assertStringContainsString( 'FROM lhc_mailconv_conversation m WHERE m.user_id = 7 AND m.status = 1', $sql );
		self::assertStringContainsString( 'FROM lhc_mailconv_conversation m WHERE m.user_id = 7 AND m.status = 0', $sql );
		self::assertStringNotContainsString( '2,9,3,5', $sql );
		self::assertStringEndsWith( 'WHERE ud.user_id = 7', $sql );
	}

	/**
	 * The department recompute mirrors updateDepartmentStats(): the four chat
	 * counters over dep_id, inactive including CONTACT_FORM (2).
	 */
	public function test_department_recompute_mirrors_update_department_stats(): void {
		$sql = md_chat_delete_department_sql( 3 );

		self::assertStringContainsString( 'UPDATE lh_departament d', $sql );
		self::assertStringContainsString( 'c.dep_id = d.id AND c.status = 1', $sql );
		self::assertStringContainsString( 'c.dep_id = d.id AND c.status = 5', $sql );
		self::assertStringContainsString( 'c.status IN (0,1) AND c.status_sub IN (2,9,3,5)', $sql );
		self::assertStringNotContainsString( 'c.department', $sql );
		self::assertStringNotContainsString( 'max_load', $sql );
		self::assertStringEndsWith( 'WHERE d.id = 3', $sql );
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

		self::assertSame( array( 11 => 555 ), get_option( 'md_wecom_notify_chats', array() ) );

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
}
