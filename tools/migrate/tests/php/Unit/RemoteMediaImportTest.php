<?php
/**
 * The remote binding of import_media (attach_uploads, branch imp/54-remote-media):
 * the file the deployer already placed in the target's uploads is registered
 * as the attachment without a copy, the variant siblings bind by the names
 * the dev import recorded (attach_uploads_variants — the sidecar's own tier
 * names postdate that import and are not what the target holds) with a
 * missing one named, and the local attach branch keeps its copy behaviour.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

namespace Md\Migrate\Tests\Unit;

use MD_Migrate_Fakes;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_direaddir, WordPress.PHP.NoSilencedErrors.Discouraged -- this suite runs outside WordPress: it builds its throwaway uploads tree with PHP itself.

/**
 * One uploads tree per test, the doubles pointed at it. The fixture names
 * mirror the real drift: the deployed library and the dev-recorded variants
 * use single hyphens (agave-01-480w.webp), the sidecar tiers double ones
 * (agave-01--480w.webp).
 */
final class RemoteMediaImportTest extends TestCase {

	/**
	 * The uploads basedir of this test.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * Builds the throwaway uploads dir and resets the doubles onto it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->uploads = (string) sys_get_temp_dir() . '/md-migrate-test-' . uniqid();
		@mkdir( $this->uploads . '/2026/10', 0755, true );
		MD_Migrate_Fakes::reset( $this->uploads );
		MD_Migrate_Fakes::$product_ids = array( 'MD-TEST' => 5 );
	}

	/**
	 * Removes the throwaway tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->flat() as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->uploads . '/2026/10' );
		@rmdir( $this->uploads . '/2026' );
		@rmdir( $this->uploads );
		parent::tearDown();
	}

	/**
	 * Every file of the throwaway tree, for emptiness and existence asserts.
	 *
	 * @return array<int, string> The file paths.
	 */
	private function flat(): array {
		$files = array();
		foreach ( (array) glob( $this->uploads . '/2026/10/*' ) as $file ) {
			$files[] = (string) $file;
		}
		return $files;
	}

	/**
	 * The remote import binds the deployed file: one wp_insert_attachment at
	 * the map's path, no directory making, no uniquifying, no copying — the
	 * uploads tree still holds exactly the two deployed files, and the
	 * variants record the dev names, not the sidecar's.
	 *
	 * @return void
	 */
	public function test_remote_import_binds_the_deployed_file_without_copying(): void {
		$dir = $this->uploads . '/2026/10';
		file_put_contents( $dir . '/agave-01-1024w.webp', 'deployed 1024' );
		file_put_contents( $dir . '/agave-01-480w.webp', 'deployed 480' );

		$entry  = md_migrate_remote_entry( '2026/10/agave-01-1024w.webp', 'agave-01' );
		$source = $entry['master_src'];
		$answer = md_migrate_import( $entry, $source );

		$this->assertSame( 'created', $answer['status'] );
		$this->assertSame( 1, $answer['imported'] );

		$this->assertSame( 1, MD_Migrate_Fakes::count( 'wp_insert_attachment' ) );
		list( $args, $file ) = MD_Migrate_Fakes::$calls['wp_insert_attachment'][0];
		$this->assertSame( $this->uploads . '/2026/10/agave-01-1024w.webp', $file );
		$this->assertSame( 'image/webp', $args['post_mime_type'] );

		// The local-import machinery never runs: the tree is untouched.
		$this->assertSame( 0, MD_Migrate_Fakes::count( 'wp_mkdir_p' ) );
		$this->assertSame( 0, MD_Migrate_Fakes::count( 'wp_unique_filename' ) );
		$this->assertCount( 2, $this->flat() );
		$this->assertSame( 'deployed 1024', (string) file_get_contents( $dir . '/agave-01-1024w.webp' ) );

		// The provenance meta lands on the configured attachment id, the
		// variants recorded by width with the dev-recorded names (the
		// sidecar's double-hyphen names never enter).
		$this->assertSame( $source, MD_Migrate_Fakes::meta( 77, '_md_source_url' ) );
		$this->assertSame( 'accent render', MD_Migrate_Fakes::meta( 77, '_md_role' ) );
		$this->assertSame( 'sha-agave-01', MD_Migrate_Fakes::meta( 77, '_md_sha256' ) );
		$this->assertSame(
			array(
				array(
					'w' => 480,
					'f' => 'agave-01-480w.webp',
				),
				array(
					'w' => 1024,
					'f' => 'agave-01-1024w.webp',
				),
			),
			json_decode( MD_Migrate_Fakes::meta( 77, '_md_variants' ), true )
		);
	}

	/**
	 * A file the map points at but the target lacks is refused by name before
	 * anything is written.
	 *
	 * @return void
	 */
	public function test_remote_import_refuses_a_file_missing_on_the_target(): void {
		$entry  = md_migrate_remote_entry( '2026/10/never-1024w.webp', 'never' );
		$answer = md_migrate_import( $entry, $entry['master_src'] );

		$this->assertSame( 'error', $answer['status'] );
		$this->assertSame( 0, $answer['imported'] );
		$this->assertStringContainsString( 'missing on target: 2026/10/never-1024w.webp', (string) $answer['error'] );
		$this->assertSame( 0, MD_Migrate_Fakes::count( 'wp_insert_attachment' ) );
		$this->assertSame( array(), MD_Migrate_Fakes::$meta );
	}

	/**
	 * A variant sibling the target lacks (by its dev-recorded name) is
	 * refused by name before the attachment row is inserted — even when a
	 * file under the sidecar's renamed tier exists, the binding follows the
	 * dev names, so the mismatch surfaces instead of silently passing.
	 *
	 * @return void
	 */
	public function test_remote_import_names_a_missing_variant(): void {
		$dir = $this->uploads . '/2026/10';
		file_put_contents( $dir . '/nara-01-1024w.webp', 'deployed 1024' );
		// The sidecar's renamed tier, which must NOT satisfy the binding.
		file_put_contents( $dir . '/nara-01--480w.webp', 'renamed sidecar tier' );

		$entry  = md_migrate_remote_entry( '2026/10/nara-01-1024w.webp', 'nara-01' );
		$answer = md_migrate_import( $entry, $entry['master_src'] );

		$this->assertSame( 'error', $answer['status'] );
		$this->assertStringContainsString( 'missing variant on target: nara-01-480w.webp (w=480)', (string) $answer['error'] );
		$this->assertSame( 0, MD_Migrate_Fakes::count( 'wp_insert_attachment' ) );
	}

	/**
	 * The local branch keeps its behaviour: the media-final file and its
	 * sibling tier are copied in under the sidecar's names.
	 *
	 * @return void
	 */
	public function test_local_import_still_copies_the_final(): void {
		$finals = (string) sys_get_temp_dir() . '/md-migrate-finals-' . uniqid();
		@mkdir( $finals, 0755 );
		file_put_contents( $finals . '/skyfall--01--1024w.webp', 'final 1024' );
		file_put_contents( $finals . '/skyfall--01--480w.webp', 'final 480' );

		$entry  = md_migrate_remote_entry( 'unused', 'skyfall--01' );
		$source = $entry['master_src'];
		unset( $entry['attach_uploads'], $entry['attach_uploads_variants'] );
		$entry['attach']              = $finals . '/skyfall--01--1024w.webp';
		$entry['variants'][0]['path'] = $finals . '/skyfall--01--480w.webp';

		$answer = md_migrate_import( $entry, $source );
		foreach ( (array) glob( $finals . '/*' ) as $file ) {
			@unlink( (string) $file );
		}
		@rmdir( $finals );

		$this->assertSame( 'created', $answer['status'] );
		$dir = $this->uploads . '/2026/10';
		$this->assertSame( 'final 1024', (string) file_get_contents( $dir . '/skyfall--01--1024w.webp' ) );
		$this->assertSame( 'final 480', (string) file_get_contents( $dir . '/skyfall--01--480w.webp' ) );
		$this->assertSame(
			array(
				array(
					'w' => 480,
					'f' => 'skyfall--01--480w.webp',
				),
				array(
					'w' => 1024,
					'f' => 'skyfall--01--1024w.webp',
				),
			),
			json_decode( MD_Migrate_Fakes::meta( 77, '_md_variants' ), true )
		);
	}
}
