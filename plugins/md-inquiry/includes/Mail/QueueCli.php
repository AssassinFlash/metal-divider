<?php
/**
 * The md-inquiry queue WP-CLI commands.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Mail;

/**
 * `wp md-inquiry queue`: the queue's two manual doors. `process` runs one
 * tick now — the smoke suite's dispatch step, and an operator's hand on a
 * mail that must not wait for the minute. `list` reads the rows: the
 * recipients exactly as recorded, which is where the mail routing discipline
 * asserts from (AGENTS.md §5 — routing correctness comes from this data,
 * never from sending more mail).
 */
final class QueueCli {

	/**
	 * The outbound queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Sets up the commands.
	 *
	 * @param Queue $queue The outbound queue.
	 */
	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Hooks the commands in, under WP-CLI only.
	 */
	public function register(): void {
		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'md-inquiry queue process', array( $this, 'process' ) );
			\WP_CLI::add_command( 'md-inquiry queue list', array( $this, 'list_rows' ) );
		}
	}

	/**
	 * Runs one queue tick now.
	 *
	 * @param array<int, string>    $args       The positional arguments (unused).
	 * @param array<string, string> $assoc_args The flags (unused).
	 * @return void
	 */
	public function process( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$sent = $this->queue->tick();
		\WP_CLI::log( 'Queue tick: ' . $sent . ' mail(s) sent.' );
	}

	/**
	 * Lists the queue's rows, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only rows with this status: pending, sent or failed.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int, string>    $args       The positional arguments (unused).
	 * @param array<string, string> $assoc_args The flags.
	 * @return void
	 */
	public function list_rows( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = $this->rows( isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : '' );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) \wp_json_encode( $rows ) );
			return;
		}
		$table = array();
		foreach ( $rows as $row ) {
			$table[] = array(
				'id'         => $row['id'],
				'inquiry'    => $row['inquiry_id'],
				'mail'       => $row['mail_type'],
				'recipients' => $row['recipients'],
				'status'     => $row['status'],
				'attempts'   => $row['attempts'],
				'last_error' => $row['last_error'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $table, array( 'id', 'inquiry', 'mail', 'recipients', 'status', 'attempts', 'last_error' ) );
	}

	/**
	 * The queue's rows, newest first.
	 *
	 * @param string $status A status filter, or '' for all.
	 * @return list<array<string, mixed>>
	 */
	private function rows( string $status ): array {
		global $wpdb;
		$table = $this->queue->table();
		if ( \in_array( $status, array( 'pending', 'sent', 'failed' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger, a whitelisted status.
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 100", $status );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- the queue's own ledger, no input.
			$sql = "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100";
		}
		$rows = \is_string( $sql ) ? $wpdb->get_results( $sql, ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL -- the statement above prepared it, or is fixed SQL.
		$list = array();
		if ( \is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( \is_array( $row ) ) {
					$list[] = $row;
				}
			}
		}
		return $list;
	}
}
