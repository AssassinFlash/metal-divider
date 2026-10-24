<?php
/**
 * Tests for the presentation installer command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\PresentCommand;
use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Presentation\WindPressVolume;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for PresentCommand: the administrator gate, the check mode's
 * drift report, and the install it drives being a safe rerun (the
 * idempotency the acceptance asks of present).
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
		WP_Mock::passthruFunction( 'wp_slash' );
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
	 * The check mode on a bare site reports the drift and exits 1.
	 */
	public function test_check_reports_drift_on_a_bare_site(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );

		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'rows (run wp md-site present to repair)' );

		ob_start();
		try {
			( new PresentCommand() )->present( array(), array( 'check' => true ) );
		} finally {
			$output = (string) ob_get_clean();
		}
		$this->assertStringContainsString( 'not installed', $output );
	}

	/**
	 * The present() command installs the volume and every face, and a
	 * second present writes nothing: the entry does not grow a second
	 * import line — `just present` is a safe rerun. The site is mocked
	 * as fully in sync (every face's post content equal to its file).
	 */
	public function test_present_installs_the_volume_and_is_a_safe_rerun(): void {
		$this->mock_synced_site();
		WP_Mock::userFunction( 'current_user_can' )->twice()->with( 'manage_options' )->andReturn( true );
		$command = new PresentCommand();

		ob_start();
		$command->present( array(), array() );
		$first = (string) ob_get_clean();

		$this->assertStringContainsString( 'wp-cli log: tokens: no Additional CSS copy to remove', $first );
		$this->assertStringContainsString( 'wp-cli log: partials: tokens style blocks stripped from 0', $first );
		$this->assertStringContainsString( 'wp-cli log: windpress volume:', $first );
		$this->assertStringContainsString( 'tokens.css', $first );
		$this->assertStringContainsString( 'templates: 0 created, 0 updated, 25 unchanged', $first, 'a synced site writes nothing' );
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

	/**
	 * A fully-synced site passes the check: zero drift rows.
	 */
	public function test_check_passes_on_a_synced_site(): void {
		$this->mock_synced_site();
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );

		ob_start();
		( new PresentCommand() )->present( array(), array( 'check' => true ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'present drift: 0 rows', $output );
	}

	/**
	 * One post double. The runtime double's WP_Post has no constructor;
	 * PHP ignores the argument the stubs' signature would ask for.
	 *
	 * @param int              $id    The post id (1-based).
	 * @param non-empty-string $name  The post name.
	 * @param non-empty-string $title The title.
	 * @param string           $path  The source path ('' for pages: page content).
	 * @param non-empty-string $type  The post type.
	 * @return \WP_Post The post.
	 */
	private function face_post( int $id, string $name, string $title, string $path, string $type ): \WP_Post {
		assert( $id >= 1 );
		$post               = new \WP_Post( new \stdClass() );
		$post->ID           = $id;
		$post->post_name    = $name;
		$post->post_title   = $title;
		$post->post_type    = $type;
		$post->post_status  = 'publish';
		$post->post_content = '' !== $path ? Templates::read( $path ) : 'page content';
		return $post;
	}

	/**
	 * Mocks a site whose every face matches the repository files: the
	 * template rows (page-type and per-page), the partials, the pages
	 * with their LiveCanvas choice, all answering through get_posts /
	 * get_post / get_post_meta.
	 */
	private function mock_synced_site(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$posts      = array();
		$remember   = static function ( \WP_Post $post ) use ( &$posts ): void {
			$posts[ 'id:' . $post->ID ]                                   = $post;
			$posts[ 'name:' . $post->post_name . ':' . $post->post_type ] = $post;
		};

		$id = 0;
		foreach ( $reflection->getConstant( 'FILES' ) as $meta => $file ) {
			$post                     = $this->face_post( ++$id, $file, $reflection->getConstant( 'FILE_TITLES' )[ $meta ], Templates::path( $file ), 'lc_dynamic_template' );
			$posts[ 'type:' . $meta ] = $post;
			$remember( $post );
		}
		foreach ( $reflection->getConstant( 'PAGES' ) as $slug => $page_row ) {
			list( $file, $title, $page_slug ) = $page_row;
			$remember( $this->face_post( ++$id, $slug, $title, Templates::path( $file ), 'lc_dynamic_template' ) );
			$page_post                           = $this->face_post( ++$id, $page_slug, $title, '', 'page' );
			$posts[ 'choice:' . $page_post->ID ] = $slug;
			$remember( $page_post );
		}
		foreach ( $reflection->getConstant( 'PARTIALS' ) as $rel => $partial_row ) {
			$remember( $this->face_post( ++$id, $partial_row[0], $partial_row[1], Templates::path( $rel, true ), 'lc_partial' ) );
		}

		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( array $args ) use ( $posts ) {
				if ( 'lc_dynamic_template' === ( $args['post_type'] ?? '' ) && isset( $args['meta_key'] ) ) {
					return isset( $posts[ 'type:' . $args['meta_key'] ] ) ? array( $posts[ 'type:' . $args['meta_key'] ] ) : array();
				}
				if ( isset( $args['name'] ) ) {
					$key = 'name:' . $args['name'] . ':' . ( $args['post_type'] ?? 'lc_dynamic_template' );
					return isset( $posts[ $key ] ) ? array( $posts[ $key ] ) : array();
				}
				return array();
			}
		);
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id ) use ( $posts ) {
				return $posts[ 'id:' . (int) $id ] ?? null;
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( $id, $key ) use ( $posts ) {
				if ( Templates::PAGE_CHOICE === $key ) {
					return $posts[ 'choice:' . (int) $id ] ?? '';
				}
				return '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->zeroOrMoreTimes()->andReturn( true );
		WP_Mock::userFunction( 'wp_update_post' )->zeroOrMoreTimes()->andReturn( true );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->with( 'lc_settings', array() )->andReturn(
			array(
				'enable-dynamic-templating' => '1',
				'header'                    => '1',
				'footerV2'                  => '1',
			)
		);
	}
}
