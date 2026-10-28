<?php
/**
 * Visitor self-service conversation deletion.
 *
 * Owner ruling 2026-10-28: a visitor deletes their own conversation from
 * the widget, automatically, with no operator involvement. The chat hash
 * is the capability: only the browser that owns the chat can delete it.
 * Any chat status is deletable — the chat-page close button only hides
 * the widget and never sets status 2, so a closed-only gate would block
 * erasure for open conversations.
 *
 * Live Helper Chat's own delete (modules/lhchat/deletechatadmin.php →
 * removeThis()) removes only the lh_chat row: 'chat.delete' has zero
 * registered listeners and this schema has zero foreign keys (staging
 * information_schema, 2026-10-28), so nothing cascades. This endpoint
 * therefore deletes every chat-domain satellite row itself — group chats
 * and transfers through their own keyed statements, uploaded files from
 * disk — then mirrors the two counters the native afterRemove()
 * maintains: lh_userdep via
 * erLhcoreClassChat::updateActiveChats() (lib/core/lhchat/lhchat.php) and
 * lh_departament via updateDepartmentStats()
 * (lib/core/lhchat/lhchatstatsresque.php).
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

add_action( 'rest_api_init', 'md_chat_delete_register' );

/**
 * The chat-domain tables deleted by chat_id in the generic sweep.
 *
 * Enumerated from information_schema on staging 2026-10-28. Mail-conversation
 * tables (lhc_mailconv_*) are excluded: that is a different id space, and the
 * chat widget never writes to it.
 *
 * Three tables need more than a bare chat_id match and are handled by their
 * own statements instead of this list: lh_group_msg (its chat_id column is
 * the GROUP id, not the chat id — see md_chat_delete_group_cleanup_sql),
 * lh_group_chat (its row is deleted by that same group cleanup), and
 * lh_transfer (chat_id is shared with mail conversations, which
 * transfer_scope = 0 keeps out).
 *
 * @return string[] Table names, without prefix.
 */
function md_chat_delete_tables(): array {
	return array(
		'lh_abstract_auto_responder_chat',
		'lh_abstract_form_collected',
		'lh_abstract_proactive_chat_campaign_conv',
		'lh_abstract_subject_chat',
		'lh_abstract_survey_item',
		'lh_canned_msg_use',
		'lh_chatbox',
		'lh_chat_accept',
		'lh_chat_action',
		'lh_chat_blocked_user',
		'lh_chat_file',
		'lh_chat_incoming',
		'lh_chat_online_user',
		'lh_chat_online_user_footprint',
		'lh_chat_paid',
		'lh_chat_participant',
		'lh_chat_voice_video',
		'lh_cobrowse',
		'lh_generic_bot_chat_event',
		'lh_generic_bot_chat_workflow',
		'lh_generic_bot_pending_event',
		'lh_generic_bot_repeat_restrict',
		'lh_msg',
		'lh_notification_subscriber',
		'lh_speech_chat_language',
	);
}

/**
 * The group-chat cleanup statements, keyed by their SQL text.
 *
 * lh_group_chat.chat_id is the visitor chat id, but lh_group_msg.chat_id is
 * the GROUP id and lh_group_chat_member is keyed by group_id — the native
 * erLhcoreClassModelGroupChat::afterRemove() deletes both through the group
 * row (lib/models/lhchat/erlhcoreclassmodelgroupchat.php), so a bare
 * chat_id sweep would hit the wrong rows. These statements mirror that
 * flow: messages and members go by group id, the group rows go last.
 *
 * @return array<string, string> Statement templates with %d placeholders.
 */
function md_chat_delete_group_cleanup_sql(): array {
	return array(
		'group_ids'  => 'SELECT id FROM lh_group_chat WHERE chat_id = %d',
		'group_msgs' => 'DELETE FROM lh_group_msg WHERE chat_id = %d',
		'members'    => 'DELETE FROM lh_group_chat_member WHERE group_id = %d',
		'groups'     => 'DELETE FROM lh_group_chat WHERE chat_id = %d',
	);
}

/**
 * The chat transfer delete: chat transfers only, mail stays.
 *
 * lh_transfer.chat_id is shared by chat (transfer_scope 0) and mail
 * (transfer_scope 1) conversations; the native beforeRemove filters
 * transfer_scope = 0 (lib/models/lhchat/erlhcoreclassmodelchat.php).
 *
 * @return string The statement template with a %d placeholder.
 */
function md_chat_delete_transfer_sql(): string {
	return 'DELETE FROM lh_transfer WHERE chat_id = %d AND transfer_scope = 0';
}

/**
 * The Live Helper Chat filesystem root, where chat file paths resolve.
 *
 * lh_chat_file.file_path is stored relative to that root ('var/storage/…'),
 * and file_path_server is file_path . name
 * (lib/models/lhchat/erlhcoreclassmodelchatfile.php).
 *
 * @return string Absolute path with a trailing slash.
 */
function md_chat_delete_lhc_root(): string {
	$root = trailingslashit( (string) apply_filters( 'md_chat_delete_lhc_root', trailingslashit( ABSPATH ) . 'chat' ) );
	$real = wp_normalize_path( $root );
	return rtrim( $real, '/' ) . '/';
}

/**
 * Map lh_chat_file rows to unlinkable absolute targets under the LHC root.
 *
 * A row yields a target only when its resolved path stays inside the root
 * and is a plain file: a crafted or legacy path that escapes the root is
 * reported as skipped, never unlinked. Prune points to the file's storage
 * directory so empty var/storage folders can be removed afterwards.
 *
 * @param array<int, object|array<string, mixed>> $rows Rows with file_path and name.
 * @param string                                    $root The absolute LHC root, normalized.
 * @return array<int, string> Absolute file paths that stay inside the root.
 */
function md_chat_delete_file_targets( array $rows, string $root ): array {
	$targets = array();
	$base    = rtrim( wp_normalize_path( $root ), '/' ) . '/';
	foreach ( $rows as $row ) {
		$file_path = is_object( $row ) && isset( $row->file_path ) ? (string) $row->file_path : ( is_array( $row ) && isset( $row['file_path'] ) ? (string) $row['file_path'] : '' );
		$name      = is_object( $row ) && isset( $row->name ) ? (string) $row->name : ( is_array( $row ) && isset( $row['name'] ) ? (string) $row['name'] : '' );
		if ( '' === $file_path || '' === $name ) {
			continue;
		}
		$rel  = ltrim( $file_path . $name, '/' );
		$full = wp_normalize_path( $base . $rel );
		if ( 0 !== strpos( $full, $base ) || str_contains( $rel, '..' ) || str_starts_with( $file_path, '/' ) ) {
			continue;
		}
		$targets[] = $full;
	}
	return $targets;
}

/**
 * Unlink chat files and prune the empty storage directories they leave.
 *
 * Mirrors erLhcoreClassModelChatFile::beforeRemove(): the file goes first,
 * then empty var/storage parents up to the LHC root's var/ folder disappear.
 * A path that resolves outside the root is skipped — the same containment
 * rule as md_chat_delete_file_targets(), checked again on the real path.
 * Missing files are skipped silently: the database rows are already going,
 * and an absent file is the desired end state.
 *
 * @param array<int, string> $targets From md_chat_delete_file_targets().
 * @param string             $root   The absolute LHC root, normalized.
 * @return int Files actually unlinked.
 */
function md_chat_delete_remove_files( array $targets, string $root ): int {
	$unlinked = 0;
	$base     = rtrim( wp_normalize_path( $root ), '/' );
	$var_stop = $base . '/var';
	foreach ( $targets as $target ) {
		$real = realpath( $target );
		if ( false === $real || ! is_file( $real ) || 0 !== strpos( wp_normalize_path( $real ), $base . '/' ) ) {
			continue;
		}
		if ( ! @unlink( $real ) ) {
			continue;
		}
		$unlinked++;
		$dir = dirname( $real );
		while ( 0 === strpos( $dir, $var_stop ) && $dir !== $var_stop ) {
			$entries = @scandir( $dir );
			if ( false === $entries || count( $entries ) > 2 ) {
				break;
			}
			if ( ! @rmdir( $dir ) ) {
				break;
			}
			$dir = dirname( $dir );
		}
	}
	return $unlinked;
}

/**
 * Whether a submitted chat hash is the stored chat hash.
 *
 * @param mixed $submitted The hash from the request.
 * @param mixed $stored The hash in lh_chat.
 * @return bool
 */
function md_chat_delete_hash_ok( $submitted, $stored ): bool {
	if ( ! is_string( $submitted ) || ! is_string( $stored ) ) {
		return false;
	}
	if ( strlen( $stored ) !== 40 || 1 !== preg_match( '/^[A-Za-z0-9]{40}$/', $submitted ) ) {
		return false;
	}
	return hash_equals( $stored, $submitted );
}

/**
 * The operator counters recompute, mirroring updateActiveChats().
 *
 * inactive uses status_sub IN (9,3,5): SURVEY_COMPLETED, USER_CLOSED_CHAT,
 * SURVEY_SHOW — updateActiveChats() does not include CONTACT_FORM here,
 * unlike the department variant below. Mail counters share the operator's
 * row set: STATUS_ACTIVE 1, STATUS_PENDING 0.
 *
 * @param int $user_id The operator the deleted chat was assigned to.
 * @return string The UPDATE statement, %d placeholders only.
 */
function md_chat_delete_userdep_sql( int $user_id ): string {
	$uid = (string) (int) $user_id;
	return "UPDATE lh_userdep ud
SET ud.active_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = $uid AND c.status = 1),
    ud.pending_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = $uid AND c.status = 0),
    ud.inactive_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = $uid AND c.status IN (0,1) AND c.status_sub IN (9,3,5)),
    ud.active_mails = (SELECT COUNT(*) FROM lhc_mailconv_conversation m WHERE m.user_id = $uid AND m.status = 1),
    ud.pending_mails = (SELECT COUNT(*) FROM lhc_mailconv_conversation m WHERE m.user_id = $uid AND m.status = 0)
WHERE ud.user_id = $uid";
}

/**
 * The department chat counters recompute, mirroring updateDepartmentStats().
 *
 * Only the four chat counters drift when a chat row disappears; the
 * max_load* columns summarise lh_userdep only, so they stay valid.
 * inactive includes CONTACT_FORM (2) here: status_sub IN (2,9,3,5).
 *
 * @param int $dep_id The department the deleted chat belonged to.
 * @return string The UPDATE statement, integer literals only.
 */
function md_chat_delete_department_sql( int $dep_id ): string {
	$did = (string) (int) $dep_id;
	return "UPDATE lh_departament d
SET d.active_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 1),
    d.pending_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 0),
    d.bot_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 5),
    d.inactive_chats_cnt = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status IN (0,1) AND c.status_sub IN (2,9,3,5))
WHERE d.id = $did";
}

/**
 * Drop the deleted chat from the WeCom card cooldown map.
 *
 * @param int $chat_id The deleted chat id.
 * @return void
 */
function md_chat_delete_forget_wecom_card( int $chat_id ): void {
	$map = get_option( 'md_wecom_notify_chats', array() );
	if ( ! is_array( $map ) || ! array_key_exists( $chat_id, $map ) ) {
		return;
	}
	unset( $map[ $chat_id ] );
	update_option( 'md_wecom_notify_chats', $map, true );
}

/**
 * Register the conversation deletion route.
 *
 * @return void
 */
function md_chat_delete_register(): void {
	register_rest_route(
		'md/v1',
		'/chat/delete',
		array(
			'methods'             => 'POST',
			'callback'            => 'md_chat_delete_respond',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Delete the chat that owns the submitted hash, with every satellite row.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response
 */
function md_chat_delete_respond( $request ) {
	$body    = (array) $request->get_json_params();
	$chat_id = isset( $body['chat_id'] ) ? (int) $body['chat_id'] : 0;
	$hash    = isset( $body['hash'] ) ? $body['hash'] : '';

	if ( $chat_id < 1 ) {
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			400
		);
	}

	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
	$rate_key    = 'md_chat_delete_' . md5( $remote_addr . '|' . $chat_id );
	if ( false !== get_transient( $rate_key ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'Too many attempts. Please try again in a minute.' ),
			429
		);
	}

	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, hash, user_id, dep_id FROM lh_chat WHERE id = %d',
			$chat_id
		)
	);

	if ( ! $row ) {
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			404
		);
	}

	if ( ! md_chat_delete_hash_ok( $hash, (string) $row->hash ) ) {
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			403
		);
	}

	$file_rows = $wpdb->get_results(
		$wpdb->prepare( 'SELECT file_path, name FROM lh_chat_file WHERE chat_id = %d', $chat_id )
	);
	md_chat_delete_remove_files(
		md_chat_delete_file_targets( is_array( $file_rows ) ? $file_rows : array(), md_chat_delete_lhc_root() ),
		md_chat_delete_lhc_root()
	);

	$group_sql = md_chat_delete_group_cleanup_sql();
	$group_ids = $wpdb->get_col( $wpdb->prepare( $group_sql['group_ids'], $chat_id ) );

	$committed = true;
	$wpdb->query( 'START TRANSACTION' );
	foreach ( md_chat_delete_tables() as $table ) {
		$deleted = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE chat_id = %d", $chat_id )
		);
		if ( false === $deleted ) {
			$committed = false;
			break;
		}
	}
	if ( $committed && is_array( $group_ids ) ) {
		foreach ( $group_ids as $group_id ) {
			$gid = (int) $group_id;
			if ( $gid < 1 ) {
				continue;
			}
			$deleted = $wpdb->query( $wpdb->prepare( $group_sql['group_msgs'], $gid ) );
			if ( false !== $deleted ) {
				$deleted = $wpdb->query( $wpdb->prepare( $group_sql['members'], $gid ) );
			}
			if ( false === $deleted ) {
				$committed = false;
				break;
			}
		}
	}
	if ( $committed ) {
		$deleted = $wpdb->query(
			$wpdb->prepare( $group_sql['groups'], $chat_id )
		);
		if ( false !== $deleted ) {
			$deleted = $wpdb->query(
				$wpdb->prepare( md_chat_delete_transfer_sql(), $chat_id )
			);
		}
		if ( false !== $deleted ) {
			$deleted = $wpdb->query(
				$wpdb->prepare( 'DELETE FROM lh_chat WHERE id = %d', $chat_id )
			);
		}
		if ( false === $deleted ) {
			$committed = false;
		}
	}
	if ( ! $committed ) {
		$wpdb->query( 'ROLLBACK' );
		return new \WP_REST_Response(
			array( 'message' => 'Could not delete this conversation right now. Please try again.' ),
			500
		);
	}
	$wpdb->query( 'COMMIT' );

	set_transient( $rate_key, 1, 60 );
	md_chat_delete_forget_wecom_card( $chat_id );

	if ( (int) $row->user_id > 0 ) {
		$wpdb->query( md_chat_delete_userdep_sql( (int) $row->user_id ) );
	}
	if ( (int) $row->dep_id > 0 ) {
		$wpdb->query( md_chat_delete_department_sql( (int) $row->dep_id ) );
	}

	return new \WP_REST_Response(
		array(
			'ok'      => true,
			'deleted' => true,
		),
		200
	);
}
