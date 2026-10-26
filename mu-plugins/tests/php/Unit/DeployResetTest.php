<?php
/**
 * Tests for the deploy cache reset.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use Md\Mu\DeployReset;
use PHPUnit\Framework\TestCase;

/**
 * The reset's decision logic, outside WordPress and outside a web server: who
 * may ask (a POST carrying the exact token the deploy wrote), what asking
 * consumes (the one-time token file), and what the answer reports.
 *
 * @covers \Md\Mu\DeployReset::decide
 * @covers \Md\Mu\DeployReset::token_file
 */
final class DeployResetTest extends TestCase {

	/**
	 * The token's path comes from the WordPress root, never from the
	 * environment: the production web SAPI runs with no `HOME` (measured on
	 * the platform 2026-10-26), which is what made every deploy log
	 * `opcache-reset=none` without attempting a reset.
	 */
	public function test_the_token_path_is_derived_from_the_wordpress_root(): void {
		self::assertSame(
			'/home/u/domains/metal-divider.com/.md-deploy/opcache-reset.token',
			DeployReset::token_file( '/home/u/domains/metal-divider.com/public_html' )
		);
		// A trailing slash on ABSPATH must not change the answer: WordPress
		// defines it with one, and a doubled slash would still resolve, but the
		// deploy writes the canonical form and the two must be the same string.
		self::assertSame(
			DeployReset::token_file( '/home/u/domains/metal-divider.com/public_html' ),
			DeployReset::token_file( '/home/u/domains/metal-divider.com/public_html/' )
		);
	}

	/**
	 * The directory is beside the web root, not inside it, and it is per site:
	 * two sites on one host account never share a token, so a review-site
	 * token cannot be presented to production.
	 */
	public function test_the_token_directory_is_beside_the_root_and_per_site(): void {
		$prod    = DeployReset::token_file( '/home/u/domains/metal-divider.com/public_html' );
		$staging = DeployReset::token_file( '/home/u/domains/staging.metal-divider.com/public_html' );

		self::assertStringNotContainsString( '/public_html/', $prod, 'the token must not live in the web root' );
		self::assertStringContainsString( '/domains/metal-divider.com/.md-deploy/', $prod );
		self::assertNotSame( $prod, $staging );
	}

	/**
	 * A valid request: the exact token, by POST. The token file is consumed
	 * and the reset's own outcome is what the answer says.
	 */
	public function test_a_matching_token_on_a_post_resets_and_consumes_the_token(): void {
		$file = $this->token( 'a1b2c3d4e5f6' );
		$ran  = 0;

		$outcome = DeployReset::decide(
			$this->server( 'a1b2c3d4e5f6' ),
			$file,
			static function () use ( &$ran ): bool {
				$ran++;
				return true;
			}
		);

		self::assertSame( 'done', $outcome );
		self::assertSame( 1, $ran );
		self::assertFileDoesNotExist( $file, 'the token is one-time: a replay must not reset again' );
	}

	/**
	 * The reset's own failure is reported, not turned into a success: an
	 * opcode cache that cannot be reset is a state the deploy must see.
	 */
	public function test_a_reset_that_cannot_reset_reports_unavailable(): void {
		$file = $this->token( 'token' );

		$outcome = DeployReset::decide(
			$this->server( 'token' ),
			$file,
			static fn(): bool => false
		);

		self::assertSame( 'unavailable', $outcome );
		self::assertFileDoesNotExist( $file, 'the attempt consumed the token either way' );
	}

	/**
	 * A token that does not match the file's is not a reset request: nothing
	 * is consumed, nothing is reset. The comparison is by hash_equals, so a
	 * prefix is as wrong as a completely different string.
	 */
	public function test_a_wrong_or_partial_token_changes_nothing(): void {
		foreach ( array( 'deadbeef', 'a1b2c3d4', 'A1B2C3D4E5F6' ) as $given ) {
			$file = $this->token( 'a1b2c3d4e5f6' );

			$outcome = DeployReset::decide(
				$this->server( $given ),
				$file,
				self::refuse( ... )
			);

			self::assertNull( $outcome, $given );
			self::assertFileExists( $file, $given );
		}
	}

	/**
	 * The right token on the wrong method is not a reset request: a GET link
	 * to the site (a prefetch, a scanner) must never consume the token.
	 */
	public function test_the_right_token_on_a_get_is_ignored(): void {
		$file                     = $this->token( 'a1b2c3d4e5f6' );
		$server                   = $this->server( 'a1b2c3d4e5f6' );
		$server['REQUEST_METHOD'] = 'GET';

		$outcome = DeployReset::decide(
			$server,
			$file,
			self::refuse( ... )
		);

		self::assertNull( $outcome );
		self::assertFileExists( $file );
	}

	/**
	 * No token file, no header, an empty header: all the shapes a request can
	 * arrive in when no deploy is asking.
	 */
	public function test_every_non_request_shape_is_ignored(): void {
		self::assertNull( DeployReset::decide( $this->server( '' ), $this->token( 'x' ), self::refuse( ... ) ) );
		self::assertNull( DeployReset::decide( array( 'REQUEST_METHOD' => 'POST' ), $this->token( 'x' ), self::refuse( ... ) ) );
		self::assertNull( DeployReset::decide( $this->server( 'token' ), '/nonexistent/md-token-file', self::refuse( ... ) ) );

		$unwritable = sys_get_temp_dir() . '/md-mu-test-' . bin2hex( random_bytes( 4 ) );
		self::assertNull( DeployReset::decide( $this->server( 'token' ), $unwritable, self::refuse( ... ) ) );
		self::assertFileDoesNotExist( $unwritable );
	}

	/**
	 * A reset that must never run: the guard of every "is ignored" assertion.
	 *
	 * @throws \RuntimeException Always: reaching the reset is the failure.
	 */
	private static function refuse(): bool {
		throw new \RuntimeException( 'the reset must not run' );
	}

	/**
	 * A POST's server values with the reset header set to the given token.
	 *
	 * @param string $token The token the request carries.
	 * @return array<string, mixed>
	 */
	private function server( string $token ): array {
		return array(
			'REQUEST_METHOD'    => 'POST',
			DeployReset::HEADER => $token,
		);
	}

	/**
	 * A token file holding the given token, in this test's own directory.
	 *
	 * @param string $token The token the file holds.
	 * @return string
	 */
	private function token( string $token ): string {
		$file = sys_get_temp_dir() . '/md-mu-test-' . bin2hex( random_bytes( 4 ) ) . '.token';
		file_put_contents( $file, $token ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a test fixture outside WordPress.
		return $file;
	}
}
