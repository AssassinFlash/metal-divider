<?php
/**
 * The outbound mail queue.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Mail;

use MetalDivider\Inquiry\Config;

/**
 * The queue every inquiry's mail goes through (spec 07 §5, branch
 * research/spec-inquiry-chain: native wp_mail behind a table, cron-driven,
 * with retries — the de-linker pattern). Nothing is sent in the request the
 * customer waits in: the mails are rows, and a once-a-minute cron tick sends
 * what is due.
 *
 * The tick honors the mail platform's two ceilings — 8 mails a minute, 80 in
 * a rolling 24 hours (ticket #20's verified Hostinger limits, with 20% margin
 * below the plan's 10/100) — by counting the queue's own sent rows, so the
 * ledger and the limiter are the same data. A send that fails waits out the
 * seven-level ladder — 1 min, 5 min, 30 min, 2 h, 6 h, 12 h, 24 h — and a
 * row that fails the eighth attempt is kept as failed for the settings page
 * to show, because a mail lost silently is an inquiry answered never.
 *
 * Test mode rewrites every dispatch to the one configured test address and
 * caps each tick at a single mail (AGENTS.md §5: a test sends one mail, to
 * the designated address; the true routing is asserted from the queue's
 * recipient data, which the rows keep in full).
 *
 * The class is not final for one seam: the two protected capture methods
 * below, which the unit tests replace — WP_Mock cannot intercept add_action
 * for a closure, and the capture is the only hook plumbing in the class.
 */
class Queue {

	/**
	 * The retry ladder, in seconds, after the 1st through the 7th failure.
	 *
	 * @var list<int>
	 */
	public const LADDER = array( 60, 300, 1800, 7200, 21600, 43200, 86400 );

	/**
	 * A row's maximum send attempts: the first send plus the ladder's seven
	 * retries.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * The per-minute send ceiling.
	 */
	public const PER_MINUTE = 8;

	/**
	 * The rolling-24-hour send ceiling.
	 */
	public const PER_DAY = 80;

	/**
	 * The cron hook that drives the queue.
	 */
	public const CRON_HOOK = 'md_inquiry_mail_tick';

	/**
	 * The cron schedule's name: one tick a minute.
	 */
	public const CRON_SCHEDULE = 'md_inquiry_minute';

	/**
	 * The schema version: the day the table's shape changes, this changes and
	 * dbDelta runs again.
	 */
	public const DB_VERSION = '1';

	/**
	 * The schema version's option name.
	 */
	public const DB_VERSION_OPTION = 'md_inquiry_queue_db_version';

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the queue.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Hooks the queue into WordPress: the schema check, the cron schedule and
	 * the tick itself.
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_init', array( $this, 'ensure_schema' ) );
		\add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- the queue's own schedule is one tick a minute: catching up inside Hostinger's 8-a-minute mail ceiling needs minute granularity, and a 15-minute tick would stretch first delivery and every ladder retry by that much.
		\add_action( 'init', array( $this, 'schedule' ) );
		\add_action( self::CRON_HOOK, array( $this, 'dispatch' ) );
	}

	/**
	 * The cron entry: one tick, with its result dropped — the ledger is where
	 * a tick's outcome is read, never the cron run's return.
	 */
	public function dispatch(): void {
		$this->tick();
	}

	/**
	 * The queue table's name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'md_inquiry_mail_queue';
	}

	/**
	 * Creates the table when the installed schema version says it is missing
	 * or stale. The option check makes this a no-op on every request but the
	 * first, so the cron tick can call it too — a deploy that swaps files
	 * without a visit to the admin must still find its table.
	 */
	public function ensure_schema(): void {
		if ( self::DB_VERSION === \get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $this->schema_sql() );
		\update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * The table's CREATE statement, in dbDelta's dialect.
	 *
	 * @return string
	 */
	private function schema_sql(): string {
		global $wpdb;
		$table   = $this->table();
		$collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
		if ( ! empty( $wpdb->collate ) ) {
			$collate .= " COLLATE {$wpdb->collate}";
		}
		return "CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			inquiry_id bigint unsigned NOT NULL,
			mail_type varchar(40) NOT NULL,
			recipients text NOT NULL,
			subject text NOT NULL,
			headers text NOT NULL,
			body longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			next_attempt datetime NOT NULL,
			last_error varchar(500) NOT NULL DEFAULT '',
			dispatched_to varchar(500) NOT NULL DEFAULT '',
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_next (status, next_attempt),
			KEY sent_at (sent_at),
			KEY inquiry_id (inquiry_id)
		) {$collate};";
	}

	/**
	 * The one-minute cron schedule the queue runs on.
	 *
	 * @param array<string, array<string, mixed>> $schedules The registered schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedules( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (md-inquiry mail queue)', 'md-inquiry' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the tick scheduled: the activation hook schedules it, and this
	 * check heals an instance whose events were cleared.
	 */
	public function schedule(): void {
		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( \time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Puts one mail on the queue, due now.
	 *
	 * @param int                                                                           $inquiry_id The inquiry record the mail belongs to.
	 * @param string                                                                        $mail_type  One of the Emails type constants.
	 * @param array{to: list<string>, subject: string, body: string, headers: list<string>} $mail The composed mail.
	 * @return bool Whether the row was written.
	 */
	public function enqueue( int $inquiry_id, string $mail_type, array $mail ): bool {
		global $wpdb;
		$now = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue is a table of its own; the values travel as insert values, not SQL.
		$written = $wpdb->insert(
			$this->table(),
			array(
				'inquiry_id'   => $inquiry_id,
				'mail_type'    => $mail_type,
				'recipients'   => \wp_json_encode( $mail['to'] ),
				'subject'      => $mail['subject'],
				'headers'      => \wp_json_encode( $mail['headers'] ),
				'body'         => $mail['body'],
				'status'       => 'pending',
				'attempts'     => 0,
				'next_attempt' => $now,
				'created_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return false !== $written;
	}

	/**
	 * Sends what is due, within the two ceilings. Returns the number of mails
	 * sent, for the WP-CLI entry and the tests to assert on.
	 *
	 * @return int
	 */
	public function tick(): int {
		$this->ensure_schema();
		$test_mode = $this->config->is_test_mode();

		$minute_count = $this->sent_since( 60 );
		$day_count    = $this->sent_since( 86400 );
		$budget       = self::budget( $minute_count, $day_count, $test_mode );
		if ( $budget <= 0 ) {
			return 0;
		}

		$sent = 0;
		foreach ( $this->due_rows( $budget ) as $row ) {
			$recipients = \json_decode( (string) $row['recipients'], true );
			$recipients = \is_array( $recipients ) ? \array_values( \array_filter( $recipients, 'is_string' ) ) : array();
			if ( array() === $recipients ) {
				// A row with no readable recipients can never send; it stays as
				// evidence instead of retrying forever.
				$this->mark_failed( (int) $row['id'], self::MAX_ATTEMPTS, 'Unreadable recipients' );
				continue;
			}
			if ( $test_mode ) {
				$recipients = array( $this->config->test_recipient() );
			}
			$headers = \json_decode( (string) $row['headers'], true );
			$headers = \is_array( $headers ) ? \array_values( \array_filter( $headers, 'is_string' ) ) : array();

			$error   = '';
			$capture = static function ( \WP_Error $failure ) use ( &$error ): void {
				$error = $failure->get_error_message();
			};
			$this->register_mail_failure_capture( $capture );
			$sent_ok = \wp_mail( $recipients, (string) $row['subject'], (string) $row['body'], $headers );
			$this->clear_mail_failure_capture( $capture );

			if ( $sent_ok ) {
				$this->mark_sent( (int) $row['id'], (int) $row['attempts'] + 1, \implode( ', ', $recipients ) );
				++$sent;
				continue;
			}
			$this->mark_failed( (int) $row['id'], (int) $row['attempts'] + 1, $error );
		}
		return $sent;
	}

	/**
	 * Registers the wp_mail_failed capture around one send: wp_mail reports
	 * only a boolean, and the failure's message is what the row keeps.
	 *
	 * @param \Closure $capture The capture, taking the WP_Error.
	 * @return void
	 */
	protected function register_mail_failure_capture( \Closure $capture ): void {
		\add_action( 'wp_mail_failed', $capture );
	}

	/**
	 * Removes the capture again: nothing of one mail's failure may leak into
	 * the next send's error.
	 *
	 * @param \Closure $capture The capture.
	 * @return void
	 */
	protected function clear_mail_failure_capture( \Closure $capture ): void {
		\remove_action( 'wp_mail_failed', $capture );
	}

	/**
	 * How many mails one tick may still send: the two ceilings' slack, and in
	 * test mode at most one — the discipline, made mechanical.
	 *
	 * @param int  $sent_minute The mails sent in the last minute.
	 * @param int  $sent_day    The mails sent in the last 24 hours.
	 * @param bool $test_mode   Whether test mode is on.
	 * @return int
	 */
	public static function budget( int $sent_minute, int $sent_day, bool $test_mode ): int {
		$budget = \min( self::PER_MINUTE - $sent_minute, self::PER_DAY - $sent_day );
		if ( $budget < 0 ) {
			$budget = 0;
		}
		if ( $test_mode && $budget > 1 ) {
			$budget = 1;
		}
		return $budget;
	}

	/**
	 * The wait after a failed attempt, in seconds: the ladder's next level.
	 *
	 * @param int $attempts The attempts already made, including the failed one.
	 * @return int
	 */
	public static function delay_for_attempt( int $attempts ): int {
		$index = $attempts - 1;
		if ( $index < 0 || $index >= \count( self::LADDER ) ) {
			return 0;
		}
		return self::LADDER[ $index ];
	}

	/**
	 * Whether an attempt count has exhausted the ladder: the row is failed,
	 * terminal, visible on the settings page.
	 *
	 * @param int $attempts The attempts already made.
	 * @return bool
	 */
	public static function is_terminal( int $attempts ): bool {
		return $attempts >= self::MAX_ATTEMPTS;
	}

	/**
	 * The queue's row counts, for the settings page.
	 *
	 * @return array{pending: int, sent: int, failed: int}
	 */
	public function counts(): array {
		global $wpdb;
		$table  = $this->table();
		$counts = array(
			'pending' => 0,
			'sent'    => 0,
			'failed'  => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- a fixed table name, a fixed status list, no input.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status IN ('pending','sent','failed') GROUP BY status", ARRAY_A );
		if ( \is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( \is_array( $row ) && isset( $row['status'], $row['n'] ) && isset( $counts[ (string) $row['status'] ] ) ) {
					$counts[ (string) $row['status'] ] = (int) $row['n'];
				}
			}
		}
		return $counts;
	}

	/**
	 * The latest failed rows, for the settings page: the evidence of every
	 * mail the chain could not deliver.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recent_failures(): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger; a fixed table name, no input.
		global $wpdb;
		$table = $this->table();
		$rows  = $wpdb->get_results( "SELECT id, inquiry_id, mail_type, recipients, attempts, last_error, created_at FROM {$table} WHERE status = 'failed' ORDER BY id DESC LIMIT 5", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		return self::row_list( \is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The rows waiting for this tick, oldest first.
	 *
	 * @param int $limit How many may send.
	 * @return list<array<string, mixed>>
	 */
	private function due_rows( int $limit ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger, read on its own tick; the statement is prepared.
		global $wpdb;
		$table = $this->table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND next_attempt <= %s ORDER BY id ASC LIMIT %d", self::now(), $limit );
		$rows  = \is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
		return self::row_list( \is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Fetched rows as a typed list: only the well-shaped arrays survive, and
	 * the list is what the callers promised.
	 *
	 * @param array<int, mixed> $rows The fetched rows.
	 * @return list<array<string, mixed>>
	 */
	private static function row_list( array $rows ): array {
		$list = array();
		foreach ( $rows as $row ) {
			if ( \is_array( $row ) ) {
				$list[] = $row;
			}
		}
		return $list;
	}

	/**
	 * How many mails were sent inside the last N seconds.
	 *
	 * @param int $seconds The window's length.
	 * @return int
	 */
	private function sent_since( int $seconds ): int {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue's own ledger, read on its own tick.
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND sent_at > %s", self::now( \time() - $seconds ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- prepare() owns the value.
		if ( ! \is_string( $sql ) ) {
			return self::PER_DAY;
		}
		$count = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- the statement above prepared it.
		return null === $count ? self::PER_DAY : (int) $count;
	}

	/**
	 * Marks a row sent.
	 *
	 * @param int    $id       The row's id.
	 * @param int    $attempts The attempts made.
	 * @param string $to       The addresses the mail actually went to.
	 * @return void
	 */
	private function mark_sent( int $id, int $attempts, string $to ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger, written on its own tick; the statement is prepared, and the table name is the queue's own constant shape.
		global $wpdb;
		$table = $this->table();
		$sql   = $wpdb->prepare( "UPDATE {$table} SET status = 'sent', attempts = %d, sent_at = %s, dispatched_to = %s, last_error = '' WHERE id = %d", $attempts, self::now(), self::substr500( $to ), $id );
		if ( \is_string( $sql ) ) {
			$wpdb->query( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * Marks a row failed: terminal when the ladder is exhausted, otherwise
	 * scheduled for the ladder's next level.
	 *
	 * @param int    $id       The row's id.
	 * @param int    $attempts The attempts made.
	 * @param string $error    The failure's message.
	 * @return void
	 */
	private function mark_failed( int $id, int $attempts, string $error ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger, written on its own tick; every statement is prepared, and the table name is the queue's own constant shape.
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the queue's own ledger, written on its own tick.
		if ( self::is_terminal( $attempts ) ) {
			$sql = $wpdb->prepare( "UPDATE {$table} SET status = 'failed', attempts = %d, last_error = %s WHERE id = %d", $attempts, self::substr500( $error ), $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare() owns every value.
		} else {
			$sql = $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = %d, last_error = %s, next_attempt = %s WHERE id = %d", $attempts, self::substr500( $error ), self::now( \time() + self::delay_for_attempt( $attempts ) ), $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare() owns every value.
		}
		if ( \is_string( $sql ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL -- the statement above prepared it.
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * A string cut to the columns' 500 characters.
	 *
	 * @param string $value The string.
	 * @return string
	 */
	private static function substr500( string $value ): string {
		return \strlen( $value ) > 500 ? \substr( $value, 0, 499 ) . '…' : $value;
	}

	/**
	 * A GMT timestamp as MySQL wants it.
	 *
	 * @param int|null $time The moment, or now.
	 * @return string
	 */
	private static function now( ?int $time = null ): string {
		return \gmdate( 'Y-m-d H:i:s', $time ?? \time() );
	}
}
