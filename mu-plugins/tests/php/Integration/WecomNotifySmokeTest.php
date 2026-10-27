<?php
/**
 * The WeCom notification connector on a real WordPress.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Integration;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- this test drives WP-CLI and asks the site over HTTP, from outside WordPress.

/**
 * The smoke test of the LHC transfer connector against a real site:
 * md-wecom-notify.php is installed the way the deployment installs it (a
 * file in wp-content/mu-plugins), the bearer token is put in the option
 * the connector reads, and the route is asked over HTTP for the contract's
 * refusals — the 401s, the 400, and the 503 of a site whose upstreams are
 * not configured. The delivered paths (the textcard, the one-time link)
 * need the WeCom application and the LHC REST credentials; they are the
 * environment ticket's joint debugging, not this smoke.
 *
 *     MD_MU_WP='just wp <instance>' vendor/bin/phpunit --testsuite integration
 *
 * (`just mu-smoke <instance>` runs this suite with that variable set;
 * without MD_MU_WP the tests are skipped.)
 */
final class WecomNotifySmokeTest extends TestCase {

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
	 * The site's wp-content directory.
	 *
	 * @var string
	 */
	private static string $content = '';

	/**
	 * The connector file the site had, or '' when it had none.
	 *
	 * @var string
	 */
	private static string $installed = '';

	/**
	 * The connector option the site had, or '' when it had none.
	 *
	 * @var string
	 */
	private static string $installed_option = '';

	/**
	 * The bearer token the smoke installs.
	 *
	 * @var string
	 */
	private static string $token = '';

	/**
	 * The connector option and the chats record the site had: both are
	 * the site's own state, and the smoke puts back exactly what it
	 * found — a run of the tests must never widen the dedup window of a
	 * real site (that would let a live chat notify twice).
	 *
	 * @var string
	 */
	private static string $installed_chats = '';

	/**
	 * Skips the tests without a site; finds the site's faces.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$wp = getenv( 'MD_MU_WP' );
		if ( ! is_string( $wp ) || '' === trim( $wp ) ) {
			self::markTestSkipped( 'MD_MU_WP names no local site.' );
		}
		$words         = array_map( 'strval', (array) preg_split( '/\s+/', trim( $wp ) ) );
		self::$wp      = array_values( array_filter( $words, static fn( string $word ): bool => '' !== $word ) );
		self::$content = trim( self::wp( array( 'eval', 'echo WP_CONTENT_DIR;' ) ) );
		self::$url     = trim( self::wp( array( 'eval', 'echo home_url();' ) ) );
		self::$token   = 'smoke-' . bin2hex( random_bytes( 24 ) );
		self::assertDirectoryExists( self::$content );
		self::assertNotSame( '', self::$url );
	}

	/**
	 * Installs the connector the way the deployment deploys it, with the
	 * smoke's bearer token as its only setting — the upstreams stay
	 * unconfigured, which is the 503 this smoke asserts.
	 */
	protected function setUp(): void {
		parent::setUp();
		$target                 = self::$content . '/mu-plugins/md-wecom-notify.php';
		self::$installed        = is_file( $target ) ? (string) file_get_contents( $target ) : '';
		self::$installed_option = trim( self::wp( array( 'eval', 'echo json_encode( get_option( "md_wecom_notify" ) );' ) ) );
		self::assertTrue( copy( dirname( __DIR__, 3 ) . '/md-wecom-notify.php', $target ) );
		self::wp(
			array(
				'option',
				'update',
				'md_wecom_notify',
				'{"bearer_token":"' . self::$token . '"}',
				'--format=json',
			)
		);
		self::$installed_chats = trim( self::wp( array( 'eval', 'echo json_encode( get_option( "md_wecom_notify_chats" ) );' ) ) );
		self::wp( array( 'eval', 'delete_option( "md_wecom_notify_chats" ); delete_option( "md_wecom_notify_lock_123" ); delete_option( "md_wecom_notify_lock_321" );' ) );
	}

	/**
	 * Puts the site's own connector file and option back.
	 */
	protected function tearDown(): void {
		$target = self::$content . '/mu-plugins/md-wecom-notify.php';
		if ( '' === self::$installed ) {
			unlink( $target );
		} else {
			file_put_contents( $target, self::$installed );
		}
		self::$installed = '';
		if ( '' === self::$installed_option || 'false' === self::$installed_option || 'null' === self::$installed_option ) {
			self::wp( array( 'option', 'delete', 'md_wecom_notify' ) );
		} else {
			self::wp( array( 'option', 'update', 'md_wecom_notify', self::$installed_option, '--format=json' ) );
		}
		// The site's own dedup record goes back exactly as it was, whatever
		// the smoke's own requests left behind.
		if ( 'false' === self::$installed_chats ) {
			self::wp( array( 'eval', 'delete_option( "md_wecom_notify_chats" ); delete_option( "md_wecom_notify_lock_123" ); delete_option( "md_wecom_notify_lock_321" );' ) );
		} else {
			self::wp(
				array(
					'eval',
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- a PHP literal for the eval; the value is the site's own record, read back this same run.
					sprintf( 'update_option( "md_wecom_notify_chats", %s );', var_export( json_decode( self::$installed_chats, true ), true ) ),
				)
			);
		}
		self::$installed_option = '';
		self::$installed_chats  = '';
		parent::tearDown();
	}

	/**
	 * The route's door is the bearer token: without it, and with a wrong
	 * one, the answer is the contract's 401 — not WordPress's own error
	 * shape, which is what a permission_callback would have sent.
	 */
	public function test_the_route_refuses_requests_without_the_token(): void {
		$none  = self::ask( '' );
		$wrong = self::ask( 'Bearer ' . str_repeat( 'w', 64 ) );

		foreach ( array(
			'absent' => $none,
			'wrong'  => $wrong,
		) as $case => $response ) {
			self::assertSame( 401, $response['status'], $case );
			self::assertSame( 'unauthorized', self::json( $response )['error']['code'] ?? '', $case );
		}
	}

	/**
	 * A request that carries the token but not the event's structure is a
	 * 400 of the contract's own shape.
	 */
	public function test_the_route_refuses_an_event_without_the_structure(): void {
		$no_event = self::ask( 'Bearer ' . self::$token, '{"chat":{"id":123,"nick":"Jane"}}' );
		$no_chat  = self::ask( 'Bearer ' . self::$token, '{"event":"chat.genericbot_chat_command_transfer"}' );

		foreach ( array(
			'no event' => $no_event,
			'no chat'  => $no_chat,
		) as $case => $response ) {
			self::assertSame( 400, $response['status'], $case );
			self::assertSame( 'bad_request', self::json( $response )['error']['code'] ?? '', $case );
		}
	}

	/**
	 * An event the connector does not serve — a visitor message, not a
	 * transfer — is acknowledged and ignored, with no upstream call:
	 * nothing here reaches WeCom or the LHC.
	 */
	public function test_the_route_ignores_events_that_are_not_transfers(): void {
		$response = self::ask( 'Bearer ' . self::$token, '{"event":"chat.addmsguser","chat":{"id":123,"nick":"Jane"},"msg":{"msg":"hello"}}' );

		self::assertSame( 204, $response['status'] );
		self::assertSame( '', $response['body'] );
	}

	/**
	 * A well-formed transfer event on a site whose upstreams are not
	 * configured is the explicit 503: the connector names the operator's
	 * missing configuration instead of calling out with half a setting.
	 * No upstream is ever asked, so nothing here reaches WeCom or the
	 * LHC.
	 */
	public function test_a_transfer_event_on_an_unconfigured_site_answers_not_configured(): void {
		$response = self::ask( 'Bearer ' . self::$token, '{"event":"chat.genericbot_chat_command_transfer","chat":{"id":123,"nick":"Jane"},"msg":{"msg":"hello"}}' );

		self::assertSame( 503, $response['status'] );
		self::assertSame( 'not_configured', self::json( $response )['error']['code'] ?? '' );
	}

	/**
	 * The same event twice on the unconfigured site never becomes a
	 * duplicate: the record is only written by a delivered card, so the
	 * second answer is the same 503, the lock is never left behind, and
	 * the chats option stays as absent as it was.
	 */
	public function test_an_undelivered_notification_records_no_chat(): void {
		$event = '{"event":"chat.chat_transfered","chat":{"id":321,"nick":"Jane"}}';

		$first  = self::ask( 'Bearer ' . self::$token, $event );
		$second = self::ask( 'Bearer ' . self::$token, $event );

		self::assertSame( 503, $first['status'] );
		self::assertSame( 503, $second['status'] );
		$chats = trim( self::wp( array( 'eval', 'echo json_encode( get_option( "md_wecom_notify_chats" ) );' ) ) );
		self::assertSame( 'false', $chats );
		$lock = trim( self::wp( array( 'eval', 'echo json_encode( get_option( "md_wecom_notify_lock_321" ) );' ) ) );
		self::assertSame( 'false', $lock );
	}

	/**
	 * One POST to the route: its status and its body.
	 *
	 * @param string $auth    The Authorization header's value, '' to send none.
	 * @param string $payload The JSON body, '' for an empty object.
	 * @return array{status: int, body: string}
	 */
	private static function ask( string $auth, string $payload = '{}' ): array {
		$header = "Content-Type: application/json\r\n";
		if ( '' !== $auth ) {
			$header .= "Authorization: {$auth}\r\n";
		}
		$http_response_header = array();
		$stream               = (string) file_get_contents(
			self::$url . '/wp-json/md/v1/wecom/notify',
			false,
			stream_context_create(
				array(
					'http' => array(
						'method'        => 'POST',
						'header'        => $header,
						'content'       => '' === $payload ? '{}' : $payload,
						'ignore_errors' => true,
						'timeout'       => 10,
					),
				)
			)
		);
		$lines                = $http_response_header;
		self::assertNotEmpty( $lines, self::$url . ' did not answer' );

		$status = 0;
		foreach ( $lines as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $match ) ) {
				$status = (int) $match[1];
			}
		}

		return array(
			'status' => $status,
			'body'   => $stream,
		);
	}

	/**
	 * Decodes a response's body as JSON, an empty array for what is not one.
	 *
	 * @param array{status: int, body: string} $response The response ask() returned.
	 * @return array<string, mixed> The decoded body.
	 */
	private static function json( array $response ): array {
		$decoded = json_decode( $response['body'], true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Runs WP-CLI against the site and returns its output.
	 *
	 * @param array<int, string> $args Arguments.
	 */
	private static function wp( array $args ): string {
		$process = proc_open(
			array_merge( self::$wp, $args ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), 'wp ' . implode( ' ', $args ) . ":\n" . $output . $errors );

		return $output;
	}
}
