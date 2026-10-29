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
 * The chat-domain sweep statements, deleted by chat_id.
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
 * @return string[] Statement templates, each with one %d placeholder.
 */
function md_chat_delete_sweep_sql(): array {
	return array(
		'DELETE FROM lh_abstract_auto_responder_chat WHERE chat_id = %d',
		'DELETE FROM lh_abstract_form_collected WHERE chat_id = %d',
		'DELETE FROM lh_abstract_proactive_chat_campaign_conv WHERE chat_id = %d',
		'DELETE FROM lh_abstract_subject_chat WHERE chat_id = %d',
		'DELETE FROM lh_abstract_survey_item WHERE chat_id = %d',
		'DELETE FROM lh_canned_msg_use WHERE chat_id = %d',
		'DELETE FROM lh_chatbox WHERE chat_id = %d',
		'DELETE FROM lh_chat_accept WHERE chat_id = %d',
		'DELETE FROM lh_chat_action WHERE chat_id = %d',
		'DELETE FROM lh_chat_blocked_user WHERE chat_id = %d',
		'DELETE FROM lh_chat_file WHERE chat_id = %d',
		'DELETE FROM lh_chat_incoming WHERE chat_id = %d',
		'DELETE FROM lh_chat_online_user WHERE chat_id = %d',
		'DELETE FROM lh_chat_online_user_footprint WHERE chat_id = %d',
		'DELETE FROM lh_chat_paid WHERE chat_id = %d',
		'DELETE FROM lh_chat_participant WHERE chat_id = %d',
		'DELETE FROM lh_chat_voice_video WHERE chat_id = %d',
		'DELETE FROM lh_cobrowse WHERE chat_id = %d',
		'DELETE FROM lh_generic_bot_chat_event WHERE chat_id = %d',
		'DELETE FROM lh_generic_bot_chat_workflow WHERE chat_id = %d',
		'DELETE FROM lh_generic_bot_pending_event WHERE chat_id = %d',
		'DELETE FROM lh_generic_bot_repeat_restrict WHERE chat_id = %d',
		'DELETE FROM lh_msg WHERE chat_id = %d',
		'DELETE FROM lh_notification_subscriber WHERE chat_id = %d',
		'DELETE FROM lh_speech_chat_language WHERE chat_id = %d',
	);
}

/**
 * The group-chat cleanup statements, keyed by their SQL text.
 *
 * The lh_group_chat.chat_id is the visitor chat id, but lh_group_msg.chat_id is
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
 * The lh_transfer.chat_id is shared by chat (transfer_scope 0) and mail
 * (transfer_scope 1) conversations; the native beforeRemove filters
 * transfer_scope = 0 (lib/models/lhchat/erlhcoreclassmodelchat.php).
 *
 * @return string The statement template with a %d placeholder.
 */
function md_chat_delete_transfer_sql(): string {
	return 'DELETE FROM lh_transfer WHERE chat_id = %d AND transfer_scope = 0';
}

/**
 * Run one of this file's statement templates against the database.
 *
 * Every statement this file executes is a constant template with %d
 * placeholders only — the sweep list, the group cleanup, transfer, userdep
 * and department — and ChatDeleteTest asserts each one's exact text. The
 * integers are bound through $wpdb->prepare() here, never interpolated.
 *
 * @param string $sql  One of those templates, never request data.
 * @param int    ...$ids The integers to bind, in placeholder order.
 * @return int|bool Rows affected, or false on error.
 */
function md_chat_delete_query( string $sql, int ...$ids ) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare( $sql, ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql is never request data: it is one of this file's constant templates (md_chat_delete_sweep_sql(), md_chat_delete_group_cleanup_sql(), md_chat_delete_transfer_sql(), md_chat_delete_userdep_sql(), md_chat_delete_department_sql()), each asserted in ChatDeleteTest; the values go through $wpdb->prepare().
}

/**
 * Read one integer column through one of this file's statement templates.
 *
 * @param string $sql  One of the constant templates this file defines, never request data.
 * @param int    ...$ids The integers to bind, in placeholder order.
 * @return array<int, string|null> The column values.
 */
function md_chat_delete_get_col( string $sql, int ...$ids ): array {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare( $sql, ...$ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql is a constant template from md_chat_delete_group_cleanup_sql(), asserted in ChatDeleteTest; the value goes through $wpdb->prepare().
}

/**
 * The Live Helper Chat filesystem root, where chat file paths resolve.
 *
 * The lh_chat_file.file_path is stored relative to that root ('var/storage/…'),
 * and file_path_server is file_path . name
 * (lib/models/lhchat/erlhcoreclassmodelchatfile.php).
 *
 * @return string Absolute path with a trailing slash.
 */
function md_chat_delete_lhc_root(): string {
	/**
	 * Filters the Live Helper Chat filesystem root, where chat file paths resolve.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root The default root: ABSPATH . 'chat/', trailingslashed.
	 * @return string The filtered root, trailingslashed.
	 */
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
 * @param string                                  $root The absolute LHC root, normalized.
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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- unlink is the primitive this loop needs: its boolean result decides whether the storage prune runs, and wp_delete_file() returns nothing. @ suppresses only the warning for a file that vanished between realpath() and now — the desired end state.
		if ( ! @unlink( $real ) ) {
			continue;
		}
		++$unlinked;
		$dir = dirname( $real );
		while ( 0 === strpos( $dir, $var_stop ) && $dir !== $var_stop ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- @scandir's false return is handled on the next line; the warning it suppresses is the very vanishing-directory race the loop exists to survive.
			$entries = @scandir( $dir );
			if ( false === $entries || count( $entries ) > 2 ) {
				break;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no directory-prune primitive; rmdir's false return already gates this loop, and @ only silences the warning on the vanished-parent race.
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
 * The visitor's address, as the transport reports it: REMOTE_ADDR, never a
 * forwarded header — the same rule and the same reason as the kb endpoint's
 * gate (md_kb_endpoint_client_ip): a rate limiter that read a
 * visitor-supplied header would let an attacker choose whose window to
 * fill, or dodge their own.
 *
 * @return string The address, '' when the transport reports none.
 */
function md_chat_delete_client_ip(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REMOTE_ADDR has no slashes to strip, and FILTER_VALIDATE_IP is the whole validation: an address that fails it is not an address.
	$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
}

/**
 * The store key of one address's failure window: a digest of the address
 * keyed by the site's salt, so the counter never becomes a log of who
 * asked — the same shape as md_kb_endpoint_failure_key, keyed by the site
 * secret instead of a bearer this endpoint has none of.
 *
 * @return string The transient name.
 */
function md_chat_delete_failure_key(): string {
	return 'md_chat_delete_failures_' . hash_hmac( 'sha256', md_chat_delete_client_ip(), wp_salt( 'auth' ) );
}

/**
 * The address's failed guesses still inside the sliding minute: ints of
 * the timestamps they happened at, older ones dropped — the same window
 * md_kb_endpoint_refuse_auth_failure prunes by.
 *
 * @return array<int, int> The failure timestamps, ascending.
 */
function md_chat_delete_recent_failures(): array {
	$now = time();
	return array_values(
		array_filter(
			(array) get_transient( md_chat_delete_failure_key() ),
			static fn ( mixed $at ): bool => is_int( $at ) && $at > $now - 60
		)
	);
}

/**
 * Whether the address has spent its ten guesses for this minute: a wrong
 * chat id or a wrong hash is a guess at the capability, and ten of them
 * lock the eleventh out before the chat row is even read.
 *
 * @return bool True when the address is locked out.
 */
function md_chat_delete_rate_limited(): bool {
	return count( md_chat_delete_recent_failures() ) >= 10;
}

/**
 * Record one failed guess in the address's sliding minute.
 *
 * @return void
 */
function md_chat_delete_record_failure(): void {
	$failures   = md_chat_delete_recent_failures();
	$failures[] = time();
	set_transient( md_chat_delete_failure_key(), $failures, 60 );
}

/**
 * The operator counters recompute, mirroring updateActiveChats().
 *
 * The inactive counter uses status_sub IN (9,3,5): SURVEY_COMPLETED, USER_CLOSED_CHAT,
 * SURVEY_SHOW — updateActiveChats() does not include CONTACT_FORM here,
 * unlike the department variant below. Mail counters share the operator's
 * row set: STATUS_ACTIVE 1, STATUS_PENDING 0.
 *
 * @return string The UPDATE statement template, %d placeholders only.
 */
function md_chat_delete_userdep_sql(): string {
	return 'UPDATE lh_userdep ud
SET ud.active_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = %d AND c.status = 1),
    ud.pending_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = %d AND c.status = 0),
    ud.inactive_chats = (SELECT COUNT(*) FROM lh_chat c WHERE c.user_id = %d AND c.status IN (0,1) AND c.status_sub IN (9,3,5)),
    ud.active_mails = (SELECT COUNT(*) FROM lhc_mailconv_conversation m WHERE m.user_id = %d AND m.status = 1),
    ud.pending_mails = (SELECT COUNT(*) FROM lhc_mailconv_conversation m WHERE m.user_id = %d AND m.status = 0)
WHERE ud.user_id = %d';
}

/**
 * The department chat counters recompute, mirroring updateDepartmentStats().
 *
 * Only the four chat counters drift when a chat row disappears; the
 * max_load* columns summarise lh_userdep only, so they stay valid.
 * inactive includes CONTACT_FORM (2) here: status_sub IN (2,9,3,5).
 *
 * @return string The UPDATE statement template, %d placeholder only.
 */
function md_chat_delete_department_sql(): string {
	return 'UPDATE lh_departament d
SET d.active_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 1),
    d.pending_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 0),
    d.bot_chats_counter = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status = 5),
    d.inactive_chats_cnt = (SELECT COUNT(*) FROM lh_chat c WHERE c.dep_id = d.id AND c.status IN (0,1) AND c.status_sub IN (2,9,3,5))
WHERE d.id = %d';
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

	// The brute-force gate comes before any chat row is read: a wrong id
	// and a wrong hash are both guesses at the capability, and this
	// endpoint's 404 answers them without a hash comparison.
	if ( md_chat_delete_rate_limited() ) {
		return new \WP_REST_Response(
			array( 'message' => 'Too many attempts. Please try again in a minute.' ),
			429
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
		md_chat_delete_record_failure();
		return new \WP_REST_Response(
			array( 'message' => 'This chat link is no longer valid.' ),
			404
		);
	}

	if ( ! md_chat_delete_hash_ok( $hash, (string) $row->hash ) ) {
		md_chat_delete_record_failure();
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
	$group_ids = md_chat_delete_get_col( $group_sql['group_ids'], $chat_id );

	$committed = true;
	$wpdb->query( 'START TRANSACTION' );
	foreach ( md_chat_delete_sweep_sql() as $sweep ) {
		$deleted = md_chat_delete_query( $sweep, $chat_id );
		if ( false === $deleted ) {
			$committed = false;
			break;
		}
	}
	if ( $committed ) {
		foreach ( $group_ids as $group_id ) {
			$gid = (int) $group_id;
			if ( $gid < 1 ) {
				continue;
			}
			$deleted = md_chat_delete_query( $group_sql['group_msgs'], $gid );
			if ( false !== $deleted ) {
				$deleted = md_chat_delete_query( $group_sql['members'], $gid );
			}
			if ( false === $deleted ) {
				$committed = false;
				break;
			}
		}
	}
	if ( $committed ) {
		$deleted = md_chat_delete_query( $group_sql['groups'], $chat_id );
		if ( false !== $deleted ) {
			$deleted = md_chat_delete_query( md_chat_delete_transfer_sql(), $chat_id );
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
		$op_id = (int) $row->user_id;
		md_chat_delete_query( md_chat_delete_userdep_sql(), $op_id, $op_id, $op_id, $op_id, $op_id, $op_id );
	}
	if ( (int) $row->dep_id > 0 ) {
		md_chat_delete_query( md_chat_delete_department_sql(), (int) $row->dep_id );
	}

	return new \WP_REST_Response(
		array(
			'ok'      => true,
			'deleted' => true,
		),
		200
	);
}
