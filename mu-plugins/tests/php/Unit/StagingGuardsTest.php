<?php
/**
 * Tests for the staging guards.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use Md\Mu\StagingGuards;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- this test runs outside WordPress: it creates and removes real files.

/**
 * The review site's guards, outside WordPress: the robots.txt the filter
 * answers with, the mail capture's passthrough contract, the hooks the file
 * attaches itself with, and the protected directory the messages land in.
 * The mailer itself needs WordPress's PHPMailer and is exercised by the
 * deploy drill on a real instance.
 *
 * @covers \Md\Mu\StagingGuards
 */
final class StagingGuardsTest extends TestCase {

	/**
	 * A temporary wp-content directory of this test.
	 *
	 * @var string
	 */
	private string $content = '';

	/**
	 * A fresh directory per test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content = sys_get_temp_dir() . '/md-mu-staging-' . bin2hex( random_bytes( 4 ) );
		self::assertTrue( mkdir( $this->content, 0755, true ) );
	}

	/**
	 * The directory goes after the test.
	 */
	protected function tearDown(): void {
		$this->remove( $this->content );
		parent::tearDown();
	}

	/**
	 * Robots.txt is one rule that disallows every path: whatever WordPress or
	 * a plugin had built into it is replaced, because the review site has no
	 * public part.
	 */
	public function test_robots_txt_disallows_everything_and_replaces_what_came_before(): void {
		$expected = "User-agent: *\nDisallow: /\n";

		self::assertSame( $expected, StagingGuards::robots_txt( '' ) );
		self::assertSame(
			$expected,
			StagingGuards::robots_txt( "User-agent: *\nDisallow: /wp-admin/\n" )
		);
	}

	/**
	 * The capture keeps an earlier filter's word: a short-circuited wp_mail
	 * (true: already sent, false: already failed) passes through untouched,
	 * and no mailer is installed for it — the capture only replaces the
	 * mailer of a mail that was about to be sent.
	 */
	public function test_a_short_circuited_mail_passes_through_without_a_mailer(): void {
		unset( $GLOBALS['phpmailer'] );

		self::assertTrue( StagingGuards::capture_mail( true ) );
		self::assertFalse( StagingGuards::capture_mail( false ) );
		self::assertArrayNotHasKey( 'phpmailer', $GLOBALS );
	}

	/**
	 * The file attaches the capture at the latest priority, so anything else
	 * that rewrites mail on the review site has said its word first, and the
	 * robots filter wherever WordPress builds the file.
	 */
	public function test_the_file_attaches_the_capture_last_and_the_robots_filter(): void {
		$hooks = $this->recorded( 'md_mu_test_hooks' );

		self::assertContains(
			array( array( StagingGuards::class, 'capture_mail' ), PHP_INT_MAX, 1 ),
			$hooks['pre_wp_mail'] ?? array()
		);
		self::assertContains(
			array( array( StagingGuards::class, 'robots_txt' ), 10, 1 ),
			$hooks['robots_txt'] ?? array()
		);
	}

	/**
	 * The mail directory is created on demand under wp-content, with the
	 * web-server denial and the empty index the error recorder's directory
	 * gets: a captured message is not public, whatever the server does with
	 * directory listings.
	 */
	public function test_the_mail_directory_is_created_protected_and_reused(): void {
		$first = StagingGuards::directory( $this->content );

		self::assertSame(
			rtrim( $this->content, '/' ) . '/' . StagingGuards::DIRECTORY,
			$first
		);
		self::assertDirectoryExists( $first );
		self::assertSame(
			"Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			file_get_contents( $first . '/.htaccess' )
		);
		self::assertSame( '', file_get_contents( $first . '/index.html' ) );

		self::assertSame( $first, StagingGuards::directory( $this->content . '/' ), 'an existing directory is reused, not recreated' );
	}

	/**
	 * What the bootstrap's hook stand-ins recorded when the plugins loaded.
	 *
	 * @param string $name The record's name in $GLOBALS.
	 *
	 * @return array<string, array<int, array<int, callable|int>>>
	 */
	private function recorded( string $name ): array {
		$recorded = $GLOBALS[ $name ] ?? array();

		return is_array( $recorded ) ? $recorded : array();
	}

	/**
	 * Removes a directory tree, the way the recorder test's teardown does.
	 *
	 * @param string $directory The tree's root.
	 */
	private function remove( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		foreach ( is_array( scandir( $directory ) ) ? scandir( $directory ) : array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $directory );
	}
}
