<?php
/**
 * Tests for the presentation installer command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\PresentCommand;
use MetalDivider\Site\Presentation\WindPressVolume;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for PresentCommand: the administrator gate, and the install it
 * drives being a safe rerun (the idempotency the acceptance asks of
 * present).
 *
 * The WP_CLI double (tests/php/Doubles/wordpress.php) prints every
 * log/success line, so the expectations can read the output.
 *
 * @covers \MetalDivider\Site\Cli\PresentCommand
 */
final class PresentCommandTest extends TestCase {

	/**
	 * The per-test uploads directory.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * A fresh uploads directory, and the WordPress functions the install calls.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->uploads = (string) tempnam( sys_get_temp_dir(), 'md-present-' );
		unlink( $this->uploads );
		mkdir( $this->uploads );
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn( array( 'basedir' => $this->uploads ) );
		WP_Mock::userFunction( 'wp_mkdir_p' )->andReturnUsing(
			static function ( string $dir ): bool {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		WP_Mock::userFunction( 'wp_get_custom_css' )->andReturn( '' );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );
	}

	/**
	 * Removes the per-test uploads directory.
	 */
	public function tearDown(): void {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->uploads );
		parent::tearDown();
	}

	/**
	 * A run without the administrator capability is refused.
	 */
	public function test_present_refuses_a_non_administrator(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'Run as an administrator' );

		( new PresentCommand() )->present( array(), array() );
	}

	/**
	 * The present() command installs the volume, and a second present writes nothing:
	 * the entry does not grow a second import line — `just present` is a
	 * safe rerun.
	 */
	public function test_present_installs_the_volume_and_is_a_safe_rerun(): void {
		WP_Mock::userFunction( 'current_user_can' )->twice()->with( 'manage_options' )->andReturn( true );
		$command = new PresentCommand();

		ob_start();
		$command->present( array(), array() );
		$first = (string) ob_get_clean();

		$this->assertStringContainsString( 'wp-cli log: tokens: no Additional CSS copy to remove', $first );
		$this->assertStringContainsString( 'wp-cli log: partials: tokens style blocks stripped from 0', $first );
		$this->assertStringContainsString( 'wp-cli log: windpress volume: tokens.css', $first );
		$this->assertStringContainsString( 'wp-cli success: Presentation installed.', $first );

		$entry = $this->uploads . '/windpress/data/main.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$after_first = (string) file_get_contents( $entry );

		ob_start();
		$command->present( array(), array() );
		$second = (string) ob_get_clean();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$this->assertSame( $after_first, (string) file_get_contents( $entry ), 'a second present leaves the entry unchanged' );
		$this->assertStringNotContainsString( 'error', strtolower( $second ) );
		$this->assertFileDoesNotExist( $entry . '.bak', 'the second run backs nothing up' );
		$this->assertFileEquals( WindPressVolume::path() . '/tokens.css', $this->uploads . '/windpress/data/tokens.css' );
	}
}
