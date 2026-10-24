<?php
/**
 * The site guards on a real WordPress.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Integration;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.Security.NonceVerification.Recommended -- this test drives WP-CLI and asks the site over HTTP, from outside WordPress.

/**
 * The smoke test of the public site's security baseline against a real site:
 * md-site-guards.php is installed the way the deployment installs it (a file
 * in wp-content/mu-plugins), and anonymous requests are asked for what the
 * file promises — the five response headers on a page, the pingback header
 * gone, XML-RPC refused, the two author-enumeration doors redirected, and
 * the inquiry CPT's REST face still unregistered (the regression anchor: the
 * day a future registration drifts in, this is where it shows). The site's
 * previous guards file, if any, is put back afterwards.
 *
 *     MD_MU_WP='just wp <instance>' vendor/bin/phpunit --testsuite integration
 *
 * (`just mu-smoke <instance>` runs this suite with that variable set; MD_MU_WP
 * is a command with arguments, read as a shell word list — `just wp <instance>`
 * is the repo's WP-CLI entry.) Without MD_MU_WP the tests are skipped.
 */
final class SiteGuardsSmokeTest extends TestCase {

	/**
	 * The WP-CLI command for the site, as an argument list.
	 *
	 * @var array<int, string>
	 */
	private static array $wp = array();

	/**
	 * The site's wp-content directory.
	 *
	 * @var string
	 */
	private static string $content = '';

	/**
	 * The address the site answers on.
	 *
	 * @var string
	 */
	private static string $url = '';

	/**
	 * The guards file the site had, or '' when it had none.
	 *
	 * @var string
	 */
	private static string $installed = '';

	/**
	 * Skips the tests without a site; finds the site's wp-content and address.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$wp = getenv( 'MD_MU_WP' );
		if ( ! is_string( $wp ) || '' === trim( $wp ) ) {
			self::markTestSkipped( 'MD_MU_WP names no local site.' );
		}
		// A command with arguments is the useful form here (`just wp <instance>`
		// runs the phar through the instance's PHP and php.ini); split it into
		// the word list proc_open wants.
		$words         = array_map( 'strval', (array) preg_split( '/\s+/', trim( $wp ) ) );
		self::$wp      = array_values( array_filter( $words, static fn( string $word ): bool => '' !== $word ) );
		self::$content = trim( self::wp( array( 'eval', 'echo WP_CONTENT_DIR;' ) ) );
		self::$url     = trim( self::wp( array( 'eval', 'echo home_url();' ) ) );
		self::assertDirectoryExists( self::$content );
		self::assertNotSame( '', self::$url );
	}

	/**
	 * Installs the guards under the name the deployment deploys it as.
	 */
	protected function setUp(): void {
		parent::setUp();
		$target          = self::$content . '/mu-plugins/md-site-guards.php';
		self::$installed = is_file( $target ) ? (string) file_get_contents( $target ) : '';
		self::assertTrue( copy( dirname( __DIR__, 3 ) . '/md-site-guards.php', $target ) );
	}

	/**
	 * Puts the site's own guards file back.
	 */
	protected function tearDown(): void {
		$target = self::$content . '/mu-plugins/md-site-guards.php';
		if ( '' === self::$installed ) {
			unlink( $target );
		} else {
			file_put_contents( $target, self::$installed );
		}
		self::$installed = '';
		parent::tearDown();
	}

	/**
	 * An anonymous page carries the five headers the guards declare, with the
	 * values the site's policy states, and no pingback header.
	 */
	public function test_an_anonymous_page_carries_the_security_headers(): void {
		$response = self::fetch( self::$url . '/' );

		self::assertSame( 200, $response['status'], 'the home page answers' );
		self::assertSame(
			'max-age=31536000; includeSubDomains',
			$response['headers']['strict-transport-security'] ?? ''
		);
		self::assertSame( 'SAMEORIGIN', $response['headers']['x-frame-options'] ?? '' );
		self::assertSame( 'nosniff', $response['headers']['x-content-type-options'] ?? '' );
		self::assertSame(
			'strict-origin-when-cross-origin',
			$response['headers']['referrer-policy'] ?? ''
		);
		self::assertSame(
			md_site_guards_header_lines()[4],
			'Content-Security-Policy: ' . ( $response['headers']['content-security-policy'] ?? '' ),
			'the CSP arrives whole, not shadowed by the local stack\'s own'
		);
		self::assertArrayNotHasKey( 'x-pingback', $response['headers'] );
	}

	/**
	 * A singular post with pings open is the page WordPress sends its
	 * X-Pingback header on — the one that proves the filter removes it
	 * (the home page never carries the header, with the guards or without).
	 */
	public function test_a_post_carries_no_pingback_header(): void {
		$post = trim(
			self::wp(
				array(
					'eval',
					'echo (string) ( get_posts( array( "numberposts" => 1, "post_type" => "post" ) )[0] ?? null )->ID ?? 0;',
				)
			)
		);
		self::assertNotSame( '', $post, 'the site has no post to ask for' );
		$response = self::fetch( self::$url . '/?p=' . $post );

		self::assertContains( (int) $response['status'], array( 200, 301 ), 'the post answers or redirects to itself' );
		self::assertArrayNotHasKey( 'x-pingback', $response['headers'] );
	}

	/**
	 * An anonymous `/wp/v2/users` is refused with the guard's own error, not
	 * enumerated: the login names are the first half of a password attack.
	 */
	public function test_the_rest_user_list_is_closed_to_anonymous_requests(): void {
		$response = self::fetch( self::$url . '/wp-json/wp/v2/users' );

		self::assertSame( 403, $response['status'] );
		self::assertStringContainsString( '"code":"rest_forbidden"', $response['body'] );
	}

	/**
	 * XML-RPC is refused outright: no method list for `system.listMethods`,
	 * not even the system methods, and WordPress's own "disabled" answer.
	 */
	public function test_xmlrpc_is_refused_without_a_method_list(): void {
		$request  = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>';
		$response = self::fetch( self::$url . '/xmlrpc.php', 'POST', $request );

		self::assertSame( 405, $response['status'] );
		self::assertStringContainsString( 'XML-RPC services are disabled', $response['body'] );
		self::assertStringNotContainsString( 'system.multicall', $response['body'] );
		self::assertStringNotContainsString( 'wp.getUsersBlogs', $response['body'] );
	}

	/**
	 * The two author-enumeration doors open on the home page instead: the
	 * numeric `?author` parameter, and the author archive — whether the
	 * author exists (WordPress would serve the archive) or not (it would
	 * serve a 404 whose URL still confirms the name).
	 */
	public function test_the_author_doors_redirect_home(): void {
		$author = trim( self::wp( array( 'eval', 'echo (string) ( get_userdata( 1 )->user_nicename ?? "" );' ) ) );
		self::assertNotSame( '', $author, 'the site has no user 1 to name an archive' );

		foreach ( array( '/?author=1', "/author/{$author}", '/author/nobody-here' ) as $target ) {
			$response = self::fetch( self::$url . $target );

			self::assertSame( 301, $response['status'], $target );
			self::assertSame( self::$url . '/', $response['headers']['location'] ?? '', $target );
		}
	}

	/**
	 * The inquiry CPT registers no REST face, by design (the inquiry chain's
	 * own spec), and this anchor is what notices the day that drifts: the
	 * route answers 404, the CPT's controller or not.
	 */
	public function test_the_inquiry_cpt_has_no_rest_face(): void {
		$response = self::fetch( self::$url . '/wp-json/wp/v2/md_inquiry' );

		self::assertSame( 404, $response['status'] );
	}

	/**
	 * One request: its status, its headers lower-cased, and its body.
	 *
	 * The context refuses redirects, so the status and headers asserted above
	 * are the first response's, not the end of a redirect chain.
	 *
	 * @param string $url    The address to ask.
	 * @param string $method The HTTP method.
	 * @param string $body   The request body, if any.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function fetch( string $url, string $method = 'GET', string $body = '' ): array {
		$http = array(
			'method'          => $method,
			'ignore_errors'   => true,
			'follow_location' => 0,
			'timeout'         => 10,
		);
		if ( '' !== $body ) {
			$http['header']  = "Content-Type: text/xml\r\n";
			$http['content'] = $body;
		}
		// Pre-seeded: the http wrapper overwrites $http_response_header with the
		// answer's header lines, and a refused connection leaves the empty seed.
		$http_response_header = array();
		$stream               = (string) file_get_contents( $url, false, stream_context_create( array( 'http' => $http ) ) );
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
