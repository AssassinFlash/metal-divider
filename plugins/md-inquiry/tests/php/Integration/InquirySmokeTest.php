<?php
/**
 * The inquiry chain on a real WordPress instance.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Integration;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.Security.NonceVerification.Recommended, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- this test drives WP-CLI and asks the site over HTTP, from outside WordPress; it reads POST data it has itself built, and proc_open is how a test harness talks to the CLI.

/**
 * The smoke suite of the whole chain (ticket #33): a real instance, the
 * plugin running the way the deployment runs it, and the owner's path walked
 * end to end — the form renders with its pattern context, a submission 303s
 * to the thank-you URL, the record lands in the CPT with its fields, both
 * mails sit on the queue with their true routing, and exactly one mail —
 * the test mode's one, to the test mode's single address — actually leaves,
 * as the instance's own mail capture proves.
 *
 *     MD_INQUIRY_WP='just wp <instance>' vendor/bin/phpunit --testsuite integration
 *
 * (`just inquiry-smoke <instance>` runs this suite with that variable set.)
 * Without MD_INQUIRY_WP the tests are skipped. The rate-limit scenario is
 * part of the same walk: it never dispatches anything, so the whole run
 * costs the one mail the first scenario sends.
 */
final class InquirySmokeTest extends TestCase {

	/**
	 * The WP-CLI command for the site, as an argument list.
	 *
	 * @var array<int, string>
	 */
	private static array $wp = array();

	/**
	 * The address the site answers on.
	 *
	 * @var string
	 */
	private static string $url = '';

	/**
	 * The instance's mail directory: where the isolation mu-plugin writes
	 * every outbound mail as a file.
	 *
	 * @var string
	 */
	private static string $mail_dir = '';

	/**
	 * The one address test mode rewrites every dispatch to.
	 */
	private const TEST_TO = 'md-smoke@local.test';

	/**
	 * The mail capture's size when the suite began: the whole run — both
	 * scenarios and every cron tick that might fire between them — may cost
	 * exactly one mail, and the after-class check holds the suite to it.
	 *
	 * @var int
	 */
	private static int $mails_at_start = 0;

	/**
	 * Skips the tests without a site; finds the site's address and mail
	 * directory.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$wp = getenv( 'MD_INQUIRY_WP' );
		if ( ! \is_string( $wp ) || '' === \trim( $wp ) ) {
			self::markTestSkipped( 'MD_INQUIRY_WP names no local site.' );
		}
		$words          = \array_map( 'strval', (array) \preg_split( '/\s+/', \trim( $wp ) ) );
		self::$wp       = \array_values( \array_filter( $words, static fn( string $word ): bool => '' !== $word ) );
		self::$url      = \trim( self::wp( array( 'eval', 'echo home_url();' ) ) );
		self::$mail_dir = (string) self::wp( array( 'eval', 'echo MD_LOCAL_MAIL_DIR;' ) );
		self::assertNotSame( '', self::$url, 'the instance answers at some address' );
		self::assertDirectoryExists( self::$mail_dir, 'the instance captures mail to files' );
		self::clean_state();
		self::$mails_at_start = \count( self::mail_files() );
	}

	/**
	 * The whole suite's mail bill: one mail, one address — nothing else, not
	 * even a cron tick's doing, left the instance while the tests ran.
	 */
	public static function tearDownAfterClass(): void {
		if ( '' !== self::$mail_dir ) {
			self::assertSame( self::$mails_at_start + 1, \count( self::mail_files() ), 'the entire suite cost exactly one mail' );
		}
		parent::tearDownAfterClass();
	}

	/**
	 * The owner's path: a pattern page's form, a submission, the 303, the
	 * record, the queue's routing — asserted, not sent — and the one mail
	 * the discipline allows, proven by the capture.
	 */
	public function test_the_whole_chain_walks_the_owners_path(): void {
		// Test mode on, through the WP-CLI switch the ticket demanded.
		self::wp( array( 'md-inquiry', 'test-mode', self::TEST_TO ) );
		$state = \trim( self::wp( array( 'md-inquiry', 'test-mode' ) ) );
		self::assertStringContainsString( 'Test mode: on', $state );
		self::assertStringContainsString( self::TEST_TO, $state );

		// The pattern and the page carrying the form.
		$pattern_id = (int) \trim(
			self::wp(
				array(
					'post',
					'create',
					'--post_type=product',
					'--post_title=Apiary',
					'--post_name=apiary-smoke',
					'--post_status=publish',
					'--porcelain',
				)
			)
		);
		self::assertGreaterThan( 0, $pattern_id );
		self::wp( array( 'post', 'meta', 'update', (string) $pattern_id, '_sku', 'APIARY-SMOKE' ) );
		$page_id = (int) \trim(
			self::wp(
				array(
					'post',
					'create',
					'--post_type=page',
					'--post_title=Inquiry smoke',
					'--post_status=publish',
					'--post_content=[md_inquiry_form pattern="apiary-smoke" source_page="pattern-pdp" source_module="hero"]',
					'--porcelain',
				)
			)
		);
		self::assertGreaterThan( 0, $page_id );
		$page_url = \trim( self::wp( array( 'post', 'url', (string) $page_id ) ) );

		// The form renders: the pattern row, the hidden plumbing, the
		// unconfigured regime's open submit.
		$html = self::fetch( $page_url, 'GET' );
		self::assertSame( 200, $html['status'] );
		foreach ( array(
			'class="md-inquiry-app"',
			'class="md-inquiry-pattern__name">Apiary<',
			'class="md-inquiry-pattern__code">APIARY-SMOKE<',
			'name="md_inquiry_pattern_slug" value="apiary-smoke"',
			'name="md_inquiry_source_module" value="hero"',
			'name="md_inquiry_nonce"',
			'name="website"',
			'class="md-inquiry-submit"',
			'https://wa.me/85264731687?text=',
		) as $needle ) {
			self::assertStringContainsString( $needle, $html['body'], $needle );
		}
		self::assertStringNotContainsString( ' style=', $html['body'], 'zero inline styles, on a real page' );
		self::assertStringNotContainsString( 'cf-turnstile', $html['body'], 'no keys configured locally: no widget, the marked regime' );

		// The submission.
		$nonce = '';
		if ( 1 === \preg_match( '/name="md_inquiry_nonce" value="([0-9a-f]+)"/', $html['body'], $match ) ) {
			$nonce = $match[1];
		}
		self::assertNotSame( '', $nonce );
		$answer = self::fetch(
			$page_url,
			'POST',
			\http_build_query(
				array(
					'md_inquiry_action'        => 'submit',
					'md_inquiry_nonce'         => $nonce,
					'website'                  => '',
					'md_inquiry_name'          => 'Jane Chan',
					'md_inquiry_email'         => 'jane@studio.example',
					'md_inquiry_company'       => 'Chan Design Studio',
					'md_inquiry_country'       => 'Hong Kong',
					'md_inquiry_scope'         => 'Single zone',
					'md_inquiry_route'         => 'Facade screening',
					'md_inquiry_quantity'      => '10-50 sqm',
					'md_inquiry_confirm'       => '1',
					'md_inquiry_message'       => 'Quantity: 40 panels.',
					'md_inquiry_pattern_slug'  => 'apiary-smoke',
					'md_inquiry_source_page'   => 'pattern-pdp',
					'md_inquiry_source_module' => 'hero',
					'md_inquiry_source_button' => 'request_quote',
					'md_inquiry_locale'        => 'en',
				)
			),
			'application/x-www-form-urlencoded'
		);
		self::assertSame( 303, $answer['status'], 'the submission ends in a 303, not a page' );
		self::assertMatchesRegularExpression( '#/inquiry/thank-you/\?md_inquiry=\d+&key=[0-9a-f]{10,}#', $answer['headers']['location'] ?? '', 'the redirect carries the id and its keyed hash' );

		// The record: one inquiry, with the server-side pattern truth.
		$ids = \array_filter( \array_map( 'intval', \explode( "\n", \trim( self::wp( array( 'post', 'list', '--post_type=md_inquiry', '--field=ID', '--posts_per_page=10' ) ) ) ) ) );
		self::assertCount( 1, $ids, 'exactly one inquiry record' );
		$record_id = (int) $ids[0];
		$meta      = static fn( string $key ): string => \trim( self::wp( array( 'post', 'meta', 'get', (string) $record_id, $key ) ) );
		self::assertSame( 'Jane Chan', $meta( 'customer_name' ) );
		self::assertSame( 'jane@studio.example', $meta( 'customer_email' ) );
		self::assertSame( 'Apiary', $meta( 'pattern_name' ), 'the snapshot, rebuilt server-side' );
		self::assertSame( 'APIARY-SMOKE', $meta( 'pattern_code' ), 'the code from the product\'s SKU' );
		self::assertSame( 'hero', $meta( 'source_module' ) );
		self::assertSame( 'not_configured', $meta( 'turnstile_status' ), 'the unconfigured regime marks the record' );
		$new_ids = \array_values( \array_filter( \explode( "\n", \trim( self::wp( array( 'post', 'list', '--post_type=md_inquiry', '--post_status=md_new', '--field=ID', '--posts_per_page=10' ) ) ) ) ) );
		self::assertSame( array( (string) $record_id ), $new_ids, 'the record starts in the New workflow status' );
		$ip = $meta( 'ip_address' );
		self::assertNotSame( '', $ip, 'the submitter\'s address is kept' );

		// The queue: both mails, their true routing asserted from the rows.
		$rows = self::queue_rows();
		self::assertCount( 2, $rows, 'the internal notification and the customer confirmation' );
		$by_type = array();
		foreach ( $rows as $row ) {
			$by_type[ $row['mail_type'] ] = $row;
		}
		self::assertArrayHasKey( 'internal_notification', $by_type );
		self::assertArrayHasKey( 'customer_confirmation', $by_type );
		self::assertSame( array( 'sales@sunriverinverter.com', 'info@metal-divider.com' ), \json_decode( $by_type['internal_notification']['recipients'], true ) );
		self::assertSame( array( 'jane@studio.example' ), \json_decode( $by_type['customer_confirmation']['recipients'], true ) );
		self::assertStringContainsString( 'From: MetalDivider <info@metal-divider.com>', (string) $by_type['customer_confirmation']['headers'] );
		self::assertStringContainsString( 'Reply-To: sales@sunriverinverter.com', (string) $by_type['customer_confirmation']['headers'] );
		self::assertStringContainsString( 'Apiary', (string) $by_type['internal_notification']['subject'] );
		self::assertSame( 'pending', $by_type['internal_notification']['status'] );
		self::assertSame( 'pending', $by_type['customer_confirmation']['status'] );

		// The one mail: one tick, one file in the capture, addressed to the
		// test mode's single address — the discipline, mechanically proven.
		$before = self::mail_files();
		self::wp( array( 'md-inquiry', 'queue', 'process' ) );
		$after = self::mail_files();
		self::assertCount( \count( $before ) + 1, $after, 'exactly one mail left the instance' );
		$sent = (string) \file_get_contents( $after[ \count( $after ) - 1 ] );
		self::assertStringContainsString( 'To: ' . self::TEST_TO, $sent, 'the one mail goes to the test mode\'s single address' );
		self::assertStringNotContainsString( 'To: sales@sunriverinverter.com', $sent );
		self::assertStringNotContainsString( 'To: jane@studio.example', $sent );

		// The queue after: one sent, one still waiting — the cap held.
		$rows     = self::queue_rows();
		$statuses = array();
		foreach ( $rows as $row ) {
			$statuses[ $row['mail_type'] ] = $row['status'];
		}
		self::assertSame( 'sent', $statuses['internal_notification'] );
		self::assertSame( 'pending', $statuses['customer_confirmation'], 'the test mode cap keeps the second mail waiting' );

		// The REST anchor: the CPT's would-be REST face answers 404.
		$rest = self::fetch( self::$url . '/wp-json/wp/v2/md_inquiry', 'GET' );
		self::assertSame( 404, $rest['status'] );
		$rest = self::fetch( self::$url . '/wp-json/md-inquiry/v1/submit', 'GET' );
		self::assertSame( 404, $rest['status'], 'a namespaced route the chain never registered answers the same 404' );
	}

	/**
	 * The rate limit: five submissions fit, the sixth is refused with its
	 * values kept. Nothing dispatches — this scenario costs no mail.
	 */
	public function test_the_rate_window_holds_the_sixth_submission(): void {
		self::clean_state();
		$page_id = (int) \trim(
			self::wp(
				array(
					'post',
					'create',
					'--post_type=page',
					'--post_title=Rate smoke',
					'--post_status=publish',
					'--post_content=[md_inquiry_form]',
					'--porcelain',
				)
			)
		);
		self::assertGreaterThan( 0, $page_id );
		$page_url = \trim( self::wp( array( 'post', 'url', (string) $page_id ) ) );
		$html     = self::fetch( $page_url, 'GET' );
		$nonce    = '';
		if ( 1 === \preg_match( '/name="md_inquiry_nonce" value="([0-9a-f]+)"/', $html['body'], $match ) ) {
			$nonce = $match[1];
		}
		self::assertNotSame( '', $nonce );

		$fields = static fn( string $name ): string => \http_build_query(
			array(
				'md_inquiry_action'   => 'submit',
				'md_inquiry_nonce'    => $nonce,
				'website'             => '',
				'md_inquiry_name'     => $name,
				'md_inquiry_email'    => 'jane@studio.example',
				'md_inquiry_message'  => 'Quantity: 40 panels.',
				// The form requires country, scope, route, and quantity, so a
				// payload carrying only the three free-text fields is refused
				// by validation and never reaches the rate window. These are
				// the same accepted values the main smoke case sends.
				'md_inquiry_country'  => 'Hong Kong',
				'md_inquiry_scope'    => 'Single zone',
				'md_inquiry_route'    => 'Facade screening',
				'md_inquiry_quantity' => '10-50 sqm',
				'md_inquiry_confirm'  => '1',
			)
		);

		$redirects = 0;
		for ( $i = 1; $i <= 5; ++$i ) {
			$answer = self::fetch( $page_url, 'POST', $fields( 'Fit ' . $i ), 'application/x-www-form-urlencoded' );
			if ( 303 === $answer['status'] ) {
				++$redirects;
			}
		}
		self::assertSame( 5, $redirects, 'five fit' );

		$sixth = self::fetch( $page_url, 'POST', $fields( 'The Sixth' ), 'application/x-www-form-urlencoded' );
		self::assertSame( 200, $sixth['status'], 'the sixth is answered by the page again, not a redirect' );
		self::assertStringContainsString( 'sending inquiries too quickly', $sixth['body'], 'the rate limit notice, in the reviewed wording' );
		self::assertStringContainsString( 'value="The Sixth"', $sixth['body'], 'the typed values survive the refusal' );

		$records = \trim( self::wp( array( 'post', 'list', '--post_type=md_inquiry', '--field=ID', '--posts_per_page=20' ) ) );
		self::assertSame( 5, \count( \array_filter( \explode( "\n", $records ) ) ), 'five records, not six' );

		// This scenario dispatches nothing: its pending rows go before the
		// suite ends, so no stray tick could bill them to the run.
		self::wp( array( 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM " . $wpdb->prefix . "md_inquiry_mail_queue" );' ) );
	}

	/**
	 * Wipes the scenarios' state: the records, the queue rows, the rate
	 * windows, so every run asserts on its own facts.
	 */
	private static function clean_state(): void {
		self::wp( array( 'eval', 'foreach ( get_posts( array( "post_type" => "md_inquiry", "post_status" => "any", "numberposts" => 100 ) ) as $post ) { wp_delete_post( $post->ID, true ); }' ) );
		self::wp( array( 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM " . $wpdb->prefix . "md_inquiry_mail_queue" );' ) );
		self::wp( array( 'eval', 'global $wpdb; $wpdb->query( "DELETE FROM " . $wpdb->options . " WHERE option_name LIKE \'%md_inquiry_rl_%\'" );' ) );
		// The queue's tick is the tests' to run, not the scheduler's: the event
		// goes, so nothing dispatches between the scenarios' own steps; the
		// queue's init check re-arms it a minute out, past the suite's end.
		self::wp( array( 'eval', 'wp_clear_scheduled_hook( "md_inquiry_mail_tick" );' ) );
	}

	/**
	 * The queue's rows, decoded from the WP-CLI JSON.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function queue_rows(): array {
		$json = \trim( self::wp( array( 'md-inquiry', 'queue', 'list', '--format=json' ) ) );
		$rows = \json_decode( $json, true );
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

	/**
	 * The mail directory's files, oldest first.
	 *
	 * @return list<string>
	 */
	private static function mail_files(): array {
		$files = \glob( \rtrim( self::$mail_dir, '/' ) . '/*.eml' );
		if ( ! \is_array( $files ) ) {
			$files = array();
		}
		\sort( $files );
		return $files;
	}

	/**
	 * One request: its status, its headers lower-cased, and its body. The
	 * context refuses redirects, so a 303 is seen as itself.
	 *
	 * @param string $url         The address to ask.
	 * @param string $method      The HTTP method.
	 * @param string $body        The request body, if any.
	 * @param string $content_type The body's content type.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function fetch( string $url, string $method = 'GET', string $body = '', string $content_type = 'text/xml' ): array {
		$http = array(
			'method'          => $method,
			'ignore_errors'   => true,
			'follow_location' => 0,
			'timeout'         => 15,
		);
		if ( '' !== $body ) {
			$http['header']  = "Content-Type: {$content_type}\r\n";
			$http['content'] = $body;
		}
		$http_response_header = array();
		$stream               = (string) \file_get_contents( $url, false, \stream_context_create( array( 'http' => $http ) ) );
		$lines                = $http_response_header;
		self::assertNotEmpty( $lines, $url . ' did not answer' );

		$status  = 0;
		$headers = array();
		foreach ( $lines as $line ) {
			if ( \preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $match ) ) {
				$status = (int) $match[1];
				continue;
			}
			$parts = \explode( ':', $line, 2 );
			if ( 2 === \count( $parts ) ) {
				$headers[ \strtolower( \trim( $parts[0] ) ) ] = \trim( $parts[1] );
			}
		}

		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $stream,
		);
	}

	/**
	 * Runs WP-CLI against the site and returns its output.
	 *
	 * @param array<int, string> $args Arguments.
	 * @return string
	 */
	private static function wp( array $args ): string {
		// The array form of proc_open takes the argument list as it is — no
		// shell sits between, so no escaping belongs here.
		$process = \proc_open(
			\array_merge( self::$wp, $args ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process, 'WP-CLI starts' );
		\fclose( $pipes[0] );
		$output = (string) \stream_get_contents( $pipes[1] );
		$errors = (string) \stream_get_contents( $pipes[2] );
		\fclose( $pipes[1] );
		\fclose( $pipes[2] );
		$code = \proc_close( $process );
		self::assertSame( 0, $code, 'wp ' . \implode( ' ', $args ) . ":\n" . $output . $errors );
		return $output;
	}
}
