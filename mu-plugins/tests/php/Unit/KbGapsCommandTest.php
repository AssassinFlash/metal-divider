<?php
/**
 * Tests for the knowledge-gap read-back command.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The command is the consumer of what md_kb_endpoint_log_unanswered
 * writes: an empty log answers with a clear line instead of an error, and
 * a full log prints newest first, with --limit only ever narrowing the
 * writer's fifty-entry cap.
 *
 * @covers ::md_kb_endpoint_register_cli
 * @covers ::md_kb_endpoint_cli_kb_gaps
 */
final class KbGapsCommandTest extends TestCase {

	/**
	 * Starts every test from an empty option store and a clean command
	 * registry, so no test sees another's gaps.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_options'] = array();
		\WP_CLI::$registered_commands  = array();
	}

	/**
	 * The command registers on cli_init, the hook the bootstrap recorded
	 * at load time, and hands WP-CLI the function it can call.
	 */
	public function test_registers_the_kb_gaps_command_on_cli_init(): void {
		$hooks = $GLOBALS['md_mu_test_hooks'] ?? array();
		self::assertContains( array( 'md_kb_endpoint_register_cli', 10, 1 ), $hooks['cli_init'] ?? array() );

		md_kb_endpoint_register_cli();

		self::assertSame( 'md_kb_endpoint_cli_kb_gaps', \WP_CLI::$registered_commands['md-chat kb-gaps'] ?? null );
	}

	/**
	 * A log with nothing in it — absent, empty, or a non-array the writer
	 * would never leave — answers with one clear line, not a WP_CLI error.
	 */
	public function test_empty_log_answers_with_a_clear_line_not_an_error(): void {
		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array() );
		$absent = (string) ob_get_clean();

		self::assertStringContainsString( 'No gaps recorded.', $absent );
		self::assertStringNotContainsString( 'wp-cli error', $absent );

		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = array();

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array() );
		$empty = (string) ob_get_clean();

		self::assertStringContainsString( 'No gaps recorded.', $empty );

		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = 'not-a-log';

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array() );
		$not_a_log = (string) ob_get_clean();

		self::assertStringContainsString( 'No gaps recorded.', $not_a_log );
	}

	/**
	 * Entries print newest first with the time (UTC), the repeat count,
	 * the reason and the question — whatever order the option holds them
	 * in, and with malformed rows skipped rather than printed.
	 */
	public function test_prints_entries_newest_first_with_their_counts(): void {
		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = array(
			'not-an-entry',
			array(
				'q'      => "older question with a\nline break",
				't'      => 200,
				'n'      => 3,
				'reason' => 'grounding',
			),
			array(
				'q'      => 'newer question',
				't'      => 300,
				'n'      => 1,
				'reason' => 'no_context',
			),
		);

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array() );
		$out = (string) ob_get_clean();

		self::assertSame( 2, substr_count( $out, 'wp-cli log: ' ) );
		self::assertLessThan( strpos( $out, 'older question' ), strpos( $out, 'newer question' ) );
		self::assertStringContainsString( '1970-01-01 00:05:00 UTC  x1  no_context  newer question', $out );
		self::assertStringContainsString( '1970-01-01 00:03:20 UTC  x3  grounding  older question with a line break', $out );
		self::assertStringContainsString( '2 gaps recorded.', $out );
	}

	/**
	 * --limit narrows the output and never widens the writer's cap:
	 * --limit=5 prints five lines, no limit prints fifty of sixty stored
	 * entries, and --limit=500 still prints fifty.
	 */
	public function test_limit_narrows_and_never_passes_the_fifty_cap(): void {
		$log = array();
		for ( $i = 0; $i < 60; ++$i ) {
			$log[] = array(
				'q'      => "question number $i",
				't'      => 1000 + $i,
				'n'      => 1,
				'reason' => 'no_context',
			);
		}
		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = $log;

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array( 'limit' => 5 ) );
		$five = (string) ob_get_clean();
		self::assertSame( 5, substr_count( $five, 'wp-cli log: ' ) );
		self::assertStringContainsString( 'question number 59', $five );
		self::assertStringNotContainsString( 'question number 54', $five );

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array() );
		$fifty = (string) ob_get_clean();
		self::assertSame( 50, substr_count( $fifty, 'wp-cli log: ' ) );
		self::assertStringContainsString( 'question number 59', $fifty );
		self::assertStringNotContainsString( 'question number 9 ', $fifty );

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array( 'limit' => 500 ) );
		$capped = (string) ob_get_clean();
		self::assertSame( 50, substr_count( $capped, 'wp-cli log: ' ) );
	}

	/**
	 * An explicit --limit of zero or below still prints one entry — the
	 * read-back answers something, never nothing, for a non-empty log.
	 */
	public function test_a_non_positive_limit_still_answers_with_one_entry(): void {
		$GLOBALS['md_mu_test_options']['raplsaich_unanswered_log'] = array(
			array(
				'q'      => 'the only question',
				't'      => 400,
				'n'      => 1,
				'reason' => 'grounding',
			),
		);

		ob_start();
		md_kb_endpoint_cli_kb_gaps( array(), array( 'limit' => 0 ) );
		$out = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $out, 'wp-cli log: ' ) );
		self::assertStringContainsString( 'the only question', $out );
	}
}
