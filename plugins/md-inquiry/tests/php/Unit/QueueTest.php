<?php
/**
 * Tests for the outbound mail queue.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Mail\Queue;
use MetalDivider\Inquiry\Tests\Support\TestableQueue;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The queue's policy (the two ceilings, the ladder, the terminal count) and
 * its behavior against the database double: the row a mail becomes, and what
 * one tick does with it — the discipline included, because test mode's cap
 * of one mail per tick is this class's own rule.
 *
 * @covers \MetalDivider\Inquiry\Mail\Queue
 */
final class QueueTest extends TestCase {

	/**
	 * The two ceilings: whichever is nearer binds the tick, and a spent
	 * window leaves nothing to send.
	 */
	public function test_the_budget_follows_the_two_ceilings(): void {
		$this->assertSame( 8, Queue::budget( 0, 0, false ) );
		$this->assertSame( 0, Queue::budget( 8, 0, false ), 'the minute ceiling is spent' );
		$this->assertSame( 0, Queue::budget( 0, 80, false ), 'the day ceiling is spent' );
		$this->assertSame( 3, Queue::budget( 5, 77, false ), 'the nearer ceiling binds' );
		$this->assertSame( 0, Queue::budget( 9, 90, false ), 'a negative slack never sends' );
	}

	/**
	 * Test mode caps the tick at one mail, whatever the ceilings allow: the
	 * AGENTS.md §5 discipline, one mail per test scenario, made mechanical.
	 */
	public function test_test_mode_caps_a_tick_at_one_mail(): void {
		$this->assertSame( 1, Queue::budget( 0, 0, true ) );
		$this->assertSame( 1, Queue::budget( 0, 79, true ) );
		$this->assertSame( 0, Queue::budget( 8, 0, true ) );
	}

	/**
	 * The ladder: seven waits after seven failures, in ticket #20's spacing —
	 * 1 min, 5 min, 30 min, 2 h, 6 h, 12 h, 24 h — and terminal at the eighth
	 * attempt, the send plus the seven retries.
	 */
	public function test_the_ladder_is_the_seven_levels(): void {
		$this->assertSame( array( 60, 300, 1800, 7200, 21600, 43200, 86400 ), Queue::LADDER );
		$this->assertSame( 60, Queue::delay_for_attempt( 1 ) );
		$this->assertSame( 300, Queue::delay_for_attempt( 2 ) );
		$this->assertSame( 1800, Queue::delay_for_attempt( 3 ) );
		$this->assertSame( 7200, Queue::delay_for_attempt( 4 ) );
		$this->assertSame( 21600, Queue::delay_for_attempt( 5 ) );
		$this->assertSame( 43200, Queue::delay_for_attempt( 6 ) );
		$this->assertSame( 86400, Queue::delay_for_attempt( 7 ) );
		$this->assertSame( 8, Queue::MAX_ATTEMPTS );
		$this->assertFalse( Queue::is_terminal( 7 ) );
		$this->assertTrue( Queue::is_terminal( 8 ) );
	}

	/**
	 * The CREATE statement's dialect: dbDelta wants PRIMARY KEY with its two
	 * spaces and the collation as a DEFAULT CHARACTER SET clause — a bare
	 * "charset collate" tail is a syntax error the database answers silently
	 * through dbDelta (found on the local instance, 2026-10-24).
	 */
	public function test_the_schema_speaks_dbdelta_and_mysql_dialect(): void {
		$this->fake_db();
		$queue = new Queue( new Config() );
		// PHP 8.1 made setAccessible() a no-op (and PHP 8.5 deprecates it), so
		// reflection reaches the private method without the call.
		$method = new \ReflectionMethod( Queue::class, 'schema_sql' );

		$sql = (string) $method->invoke( $queue );
		$this->assertStringContainsString( 'CREATE TABLE wp_md_inquiry_mail_queue', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql, 'dbDelta wants the two spaces after PRIMARY KEY' );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql, 'the collation is a clause, not a bare tail' );
		$this->assertStringNotContainsString( ') utf8mb4', $sql );
	}

	/**
	 * The cron schedule the queue runs on: one tick a minute, a named entry
	 * so nothing else's schedule is touched.
	 */
	public function test_the_queue_runs_on_its_own_minute_schedule(): void {
		$this->mock_passthroughs();
		$queue = new TestableQueue( new Config() );

		$schedules = $queue->cron_schedules( array() );
		$this->assertSame( 60, $schedules[ Queue::CRON_SCHEDULE ]['interval'] );
	}

	/**
	 * The tick stays scheduled: an instance whose events were cleared heals
	 * on the next request.
	 */
	public function test_the_tick_is_kept_scheduled(): void {
		$queue     = new Queue( new Config() );
		$scheduled = array();
		WP_Mock::userFunction( 'wp_next_scheduled' )->once()->with( Queue::CRON_HOOK )->andReturn( false );
		WP_Mock::userFunction( 'wp_schedule_event' )->once()->andReturnUsing(
			static function ( int $when, string $schedule, string $hook ) use ( &$scheduled ): bool {
				$scheduled = array( $when, $schedule, $hook );
				return true;
			}
		);
		$queue->schedule();

		$this->assertSame( Queue::CRON_SCHEDULE, $scheduled[1], 'the tick runs on the queue\'s own minute schedule' );
		$this->assertSame( Queue::CRON_HOOK, $scheduled[2] );
	}

	/**
	 * A scheduled tick is left alone: the healer never double-books.
	 */
	public function test_an_existing_schedule_is_not_double_booked(): void {
		$queue = new Queue( new Config() );
		$asked = array();
		WP_Mock::userFunction( 'wp_next_scheduled' )->once()->andReturnUsing(
			static function ( string $hook ) use ( &$asked ): int {
				$asked[] = $hook;
				return 12345;
			}
		);
		WP_Mock::userFunction( 'wp_schedule_event' )->never();
		$queue->schedule();

		$this->assertSame( array( Queue::CRON_HOOK ), $asked, 'the healer asks only about its own tick' );
	}

	/**
	 * A mail becomes a due-now row: the recipients and headers as the JSON the
	 * assertions read, the body whole, the status pending.
	 */
	public function test_enqueue_writes_the_row_the_assertions_read(): void {
		$this->mock_passthroughs();
		$db                    = $this->fake_db();
		$db->returns['insert'] = 1;
		$queue                 = new TestableQueue( new Config() );

		$written = $queue->enqueue(
			42,
			'internal_notification',
			array(
				'to'      => array( 'sales@sunriverinverter.com', 'info@metal-divider.com' ),
				'subject' => 'New inquiry: Apiary — Jane Chan',
				'body'    => "A new inquiry arrived.\n",
				'headers' => array( 'From: MetalDivider <info@metal-divider.com>' ),
			)
		);

		$this->assertTrue( $written );
		$calls = $db->calls_of( 'insert' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'wp_md_inquiry_mail_queue', $calls[0][0], 'the table name carries the prefix' );
		$row = $calls[0][1];
		$this->assertSame( 42, $row['inquiry_id'] );
		$this->assertSame( 'internal_notification', $row['mail_type'] );
		$this->assertSame( '["sales@sunriverinverter.com","info@metal-divider.com"]', $row['recipients'] );
		$this->assertSame( '["From: MetalDivider <info@metal-divider.com>"]', $row['headers'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, $row['attempts'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['next_attempt'] );
	}

	/**
	 * One tick, normal mode: a due row sends to its recorded recipients, and
	 * the row is marked sent with the addresses it actually went to.
	 */
	public function test_a_due_row_sends_and_is_marked_sent(): void {
		$db    = $this->tick_db( array( self::due_row( 1, 0 ) ), '0' );
		$queue = new TestableQueue( new Config() );

		$mail = array();
		WP_Mock::userFunction( 'wp_mail' )->once()->andReturnUsing(
			static function ( array|string $to, string $subject, string $body, array|string $headers = array() ) use ( &$mail ): bool {
				$mail = array( $to, $subject, $headers );
				return true;
			}
		);

		$this->assertSame( 1, $queue->tick() );
		$this->assertSame( array( 'sales@sunriverinverter.com', 'info@metal-divider.com' ), $mail[0] );
		$this->assertSame( 'New inquiry: Apiary', $mail[1] );
		$this->assertSame( array( 'From: MetalDivider <info@metal-divider.com>' ), $mail[2] );

		$update = $this->first_query_starting( $db, "UPDATE {$db->prefix}md_inquiry_mail_queue SET status = 'sent'" );
		$this->assertNotSame( '', $update, 'the row is marked sent' );
	}

	/**
	 * Test mode: the same row sends to the one test address — and only one
	 * row sends at all, the cap the discipline demands.
	 */
	public function test_test_mode_rewrites_the_recipient_and_caps_the_tick(): void {
		$db    = $this->tick_db( array( self::due_row( 1, 0 ), self::due_row( 2, 0 ) ), '0' );
		$queue = new TestableQueue( new Config( array( 'MD_INQUIRY_TEST_RECIPIENT' => 'tester@example.test' ) ) );

		$mails = array();
		WP_Mock::userFunction( 'wp_mail' )->once()->andReturnUsing(
			static function ( array|string $to ) use ( &$mails ): bool {
				$mails[] = $to;
				return true;
			}
		);

		$this->assertSame( 1, $queue->tick(), 'one mail leaves, however many are due' );
		$this->assertSame( array( array( 'tester@example.test' ) ), $mails, 'the true recipients never leave the machine' );
	}

	/**
	 * A spent minute ceiling sends nothing: the tick reads the ledger and
	 * waits.
	 */
	public function test_a_spent_ceiling_sends_nothing(): void {
		$db    = $this->tick_db( array( self::due_row( 1, 0 ) ), '8' );
		$queue = new TestableQueue( new Config() );

		WP_Mock::userFunction( 'wp_mail' )->never();
		$this->assertSame( 0, $queue->tick() );
		$this->assertSame( array(), $db->calls_of( 'get_results' ), 'no due rows are even fetched' );
	}

	/**
	 * A failed first send waits one minute — the ladder's first level — and
	 * stays pending.
	 */
	public function test_a_failed_send_waits_the_ladder_and_stays_pending(): void {
		$db    = $this->tick_db( array( self::due_row( 7, 0 ) ), '0' );
		$queue = new TestableQueue( new Config() );

		WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( false );
		$queue->mail_failure = new \WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' );

		$this->assertSame( 0, $queue->tick() );
		$update = $this->first_query_starting( $db, "UPDATE {$db->prefix}md_inquiry_mail_queue SET status = 'pending'" );
		$this->assertStringContainsString( 'attempts = 1', $update );
		$this->assertStringContainsString( "last_error = 'Could not instantiate mail function.'", $update );
		$this->assertMatchesRegularExpression( '/next_attempt = \'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\'/', $update );
	}

	/**
	 * The eighth failure is terminal: the row is kept as failed, with no next
	 * attempt — the settings page's evidence, never a silent loss.
	 */
	public function test_the_eighth_failure_is_terminal(): void {
		$db    = $this->tick_db( array( self::due_row( 7, 7 ) ), '0' );
		$queue = new TestableQueue( new Config() );

		WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( false );
		$queue->mail_failure = new \WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' );

		$this->assertSame( 0, $queue->tick() );
		$this->assertNotSame( '', $this->first_query_starting( $db, "UPDATE {$db->prefix}md_inquiry_mail_queue SET status = 'failed'" ) );
		$this->assertSame( '', $this->first_query_starting( $db, "UPDATE {$db->prefix}md_inquiry_mail_queue SET status = 'pending'" ), 'no retry is scheduled' );
	}

	/**
	 * A row whose recipients cannot be read back never sends; it goes
	 * straight to failed instead of retrying forever.
	 */
	public function test_an_unreadable_row_fails_without_sending(): void {
		$row               = self::due_row( 9, 0 );
		$row['recipients'] = 'not json';
		$db                = $this->tick_db( array( $row ), '0' );
		$queue             = new TestableQueue( new Config() );

		WP_Mock::userFunction( 'wp_mail' )->never();
		$this->assertSame( 0, $queue->tick() );
		$this->assertNotSame( '', $this->first_query_starting( $db, "UPDATE {$db->prefix}md_inquiry_mail_queue SET status = 'failed'" ) );
	}

	/**
	 * The queue's health read: the three counts.
	 */
	public function test_the_counts_read_the_three_statuses(): void {
		$db                         = $this->fake_db();
		$db->returns['get_results'] = array(
			array(
				'status' => 'pending',
				'n'      => '2',
			),
			array(
				'status' => 'sent',
				'n'      => '5',
			),
			array(
				'status' => 'failed',
				'n'      => '1',
			),
		);
		$queue                      = new TestableQueue( new Config() );

		$this->assertSame(
			array(
				'pending' => 2,
				'sent'    => 5,
				'failed'  => 1,
			),
			$queue->counts()
		);
	}

	/**
	 * The double a tick runs against: the schema version already current, the
	 * ledger's two counts, and the due rows.
	 *
	 * @param list<array<string, mixed>> $due_rows   The rows waiting.
	 * @param string                     $sent_count The ledger's counts answer.
	 * @return \MetalDivider\Inquiry\Tests\Support\FakeWpdb
	 */
	private function tick_db( array $due_rows, string $sent_count ): \MetalDivider\Inquiry\Tests\Support\FakeWpdb {
		$db = $this->fake_db();
		$this->mock_is_email();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $option ): string {
				return Queue::DB_VERSION_OPTION === $option ? Queue::DB_VERSION : '';
			}
		);
		$db->returns['get_var'] = $sent_count;
		// The due-rows fetch honors the SQL's LIMIT, as the real wpdb does —
		// the cap tests read their meaning from it.
		$db->returns['get_results'] = static function ( string $sql ) use ( $due_rows ): array {
			\preg_match( '/LIMIT (\d+)$/', \trim( $sql ), $match );
			$limit = isset( $match[1] ) ? (int) $match[1] : \count( $due_rows );
			return \array_slice( $due_rows, 0, $limit );
		};
		return $db;
	}
	/**
	 * One due row, in the shape a fetched row has.
	 *
	 * @param int $id       The row's id.
	 * @param int $attempts The attempts already made.
	 * @return array<string, mixed>
	 */
	private static function due_row( int $id, int $attempts ): array {
		return array(
			'id'           => (string) $id,
			'inquiry_id'   => '42',
			'mail_type'    => 'internal_notification',
			'recipients'   => '["sales@sunriverinverter.com","info@metal-divider.com"]',
			'subject'      => 'New inquiry: Apiary',
			'headers'      => '["From: MetalDivider <info@metal-divider.com>"]',
			'body'         => 'A new inquiry arrived.',
			'status'       => 'pending',
			'attempts'     => (string) $attempts,
			'next_attempt' => '2026-10-24 00:00:00',
			'last_error'   => '',
			'created_at'   => '2026-10-24 00:00:00',
		);
	}

	/**
	 * The first recorded query beginning with the given prefix, or ''.
	 *
	 * @param \MetalDivider\Inquiry\Tests\Support\FakeWpdb $db    The double.
	 * @param string                                       $start The SQL prefix.
	 * @return string
	 */
	private function first_query_starting( \MetalDivider\Inquiry\Tests\Support\FakeWpdb $db, string $start ): string {
		foreach ( $db->calls_of( 'query' ) as $call ) {
			if ( \is_string( $call[0] ?? null ) && \str_starts_with( $call[0], $start ) ) {
				return $call[0];
			}
		}
		return '';
	}

	/**
	 * The composition root calls the queue's `register()`.
	 *
	 * The behaviour tests call `cron_schedules()` and `schedule()` directly, so
	 * they passed while the queue was never wired in: the root held the
	 * instance for its callers and never called the queue's own `register()`,
	 * so `cron_schedules` was never filtered, `init` never healed the
	 * schedule, and `wp_schedule_event()` was handed an unknown recurrence
	 * that WordPress silently refuses. Measured on production 2026-10-27:
	 * `wp_get_schedules()` had no `md_inquiry_minute`, `wp_next_scheduled()`
	 * was false, and two real inquiry mails sat at attempts=0 with no tick to
	 * move them.
	 *
	 * Asserting from `Plugin::register()` rather than by calling the queue's
	 * hook method here is the whole point: calling it here proves nothing
	 * about whether the root does.
	 */
	public function test_the_composition_root_registers_the_queue(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Plugin.php' );
		$this->assertMatchesRegularExpression(
			'/\$this->queue->register\(\);/',
			$source,
			'Plugin::register() must call the queue\'s own register(), or the cron schedule is never created'
		);
	}
}
