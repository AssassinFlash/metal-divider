<?php
/**
 * The knowledge endpoint on a real WordPress.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Integration;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- this test drives WP-CLI and asks the site over HTTP, from outside WordPress.

/**
 * The smoke test of the AI support MVP's answer endpoint against a real
 * site: md-kb-endpoint.php is installed the way the deployment installs it
 * (a file in wp-content/mu-plugins), the bearer token is put in the option
 * the endpoint reads, and the endpoint is asked over HTTP for the contract
 * — the 401s, the 400, and the 503 of a site whose knowledge plugin is not
 * there. The answered paths (200, the transfer, the model failures) need
 * the knowledge plugin and the model key; they are the joint debugging of
 * the environment ticket, not this smoke.
 *
 *     MD_MU_WP='just wp <instance>' vendor/bin/phpunit --testsuite integration
 *
 * (`just mu-smoke <instance>` runs this suite with that variable set;
 * without MD_MU_WP the tests are skipped.)
 */
final class KbEndpointSmokeTest extends TestCase {

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
	 * The endpoint file the site had, or '' when it had none.
	 *
	 * @var string
	 */
	private static string $installed = '';

	/**
	 * The bearer token the smoke installs.
	 *
	 * @var string
	 */
	private static string $token = '';

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
	 * Installs the endpoint the way the deployment deploys it, with the
	 * smoke's bearer token in its option.
	 */
	protected function setUp(): void {
		parent::setUp();
		$target          = self::$content . '/mu-plugins/md-kb-endpoint.php';
		self::$installed = is_file( $target ) ? (string) file_get_contents( $target ) : '';
		self::assertTrue( copy( dirname( __DIR__, 3 ) . '/md-kb-endpoint.php', $target ) );
		self::wp(
			array(
				'option',
				'update',
				'md_kb_endpoint',
				'{"bearer_token":"' . self::$token . '"}',
				'--format=json',
			)
		);
		// The brute-force window outlives a request (it is the point of
		// it); each test starts with no failures counted.
		self::wp(
			array(
				'eval',
				'global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", \'\\\\_transient\\\\_md\\\\_kb\\\\_endpoint\\\\_failures%\', \'\\\\_transient\\\\_timeout\\\\_md\\\\_kb\\\\_endpoint\\\\_failures%\' ) );',
			)
		);
	}

	/**
	 * Puts the site's own endpoint file back.
	 */
	protected function tearDown(): void {
		$target = self::$content . '/mu-plugins/md-kb-endpoint.php';
		if ( '' === self::$installed ) {
			unlink( $target );
		} else {
			file_put_contents( $target, self::$installed );
		}
		self::$installed = '';
		parent::tearDown();
	}

	/**
	 * The endpoint's door is the bearer token: without it, and with a wrong
	 * one, the answer is the contract's 401 — not WordPress's own error
	 * shape, which is what a permission_callback would have sent.
	 */
	public function test_the_endpoint_refuses_requests_without_the_token(): void {
		$none  = self::ask( self::$url . '/wp-json/md/v1/kb/answer', '' );
		$wrong = self::ask( self::$url . '/wp-json/md/v1/kb/answer', 'Bearer ' . str_repeat( 'w', 64 ) );

		foreach ( array(
			'absent' => $none,
			'wrong'  => $wrong,
		) as $case => $response ) {
			self::assertSame( 401, $response['status'], $case );
			self::assertSame( 'unauthorized', self::json( $response )['error']['code'] ?? '', $case );
		}
	}

	/**
	 * A request that carries the token but no question is a 400 of the
	 * contract's own shape.
	 */
	public function test_the_endpoint_refuses_a_request_without_a_question(): void {
		$response = self::ask( self::$url . '/wp-json/md/v1/kb/answer', 'Bearer ' . self::$token );

		self::assertSame( 400, $response['status'] );
		self::assertSame( 'bad_request', self::json( $response )['error']['code'] ?? '' );
	}

	/**
	 * A real question on a site whose knowledge plugin is not installed is
	 * the explicit 503 — the endpoint refuses to let the model answer from
	 * no knowledge, and it says which half of the pipeline is missing. The
	 * smoke site installs no knowledge plugin: that is the condition under
	 * test, not an omission.
	 */
	public function test_a_site_without_the_knowledge_plugin_answers_unavailable(): void {
		$response = self::ask( self::$url . '/wp-json/md/v1/kb/answer', 'Bearer ' . self::$token, '{"q":"delivery time"}' );

		self::assertSame( 503, $response['status'] );
		$body = self::json( $response );
		self::assertSame( 'kb_unavailable', $body['error']['code'] ?? '' );
		self::assertNotSame( '', $body['msg'] ?? '' );
	}

	/**
	 * The brute-force gate over the real stack: ten wrong tokens are ten
	 * 401s, the eleventh is the 429 — the window really living in the
	 * site's transients, not just in the unit's stand-in store.
	 */
	public function test_the_eleventh_wrong_token_over_http_is_rate_limited(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$response = self::ask( self::$url . '/wp-json/md/v1/kb/answer', 'Bearer ' . str_repeat( 'w', 64 ) );
			self::assertSame( 401, $response['status'], "Failure {$i} is still the ordinary refusal." );
		}

		$eleventh = self::ask( self::$url . '/wp-json/md/v1/kb/answer', 'Bearer ' . str_repeat( 'w', 64 ) );

		self::assertSame( 429, $eleventh['status'] );
		$body = self::json( $eleventh );
		self::assertSame( 'rate_limited', $body['error']['code'] ?? '' );
		self::assertNotSame( '', $body['msg'] ?? '' );
	}

	/**
	 * One POST to the endpoint: its status, its headers lower-cased, its
	 * body, and the Authorization header it carried.
	 *
	 * @param string $url     The address to ask.
	 * @param string $auth    The Authorization header's value, '' to send none.
	 * @param string $payload The JSON body, '' for none.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function ask( string $url, string $auth, string $payload = '{}' ): array {
		$header = "Content-Type: application/json\r\n";
		if ( '' !== $auth ) {
			$header .= "Authorization: {$auth}\r\n";
		}
		$http_response_header = array();
		$stream               = (string) file_get_contents(
			$url,
			false,
			stream_context_create(
				array(
					'http' => array(
						'method'        => 'POST',
						'header'        => $header,
						'content'       => $payload,
						'ignore_errors' => true,
						'timeout'       => 10,
					),
				)
			)
		);
		$lines                = $http_response_header;
		self::assertNotEmpty( $lines, $url . ' did not answer' );

		$status  = 0;
		$headers = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $line, $match ) ) {
				$status = (int) $match[1];
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
			}
		}

		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => $stream,
		);
	}

	/**
	 * The response body, as the JSON it is.
	 *
	 * @param array{status: int, headers: array<string, string>, body: string} $response The response.
	 * @return array<string, mixed> The decoded body.
	 */
	private static function json( array $response ): array {
		$decoded = json_decode( $response['body'], true );
		self::assertIsArray( $decoded, 'the body is not JSON: ' . $response['body'] );
		return $decoded;
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
