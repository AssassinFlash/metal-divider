<?php
/**
 * Tests for the WindPress volume.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\WindPressVolume;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for WindPressVolume: the idempotent install of the exported
 * stylesheets into the volume directory the compiler reads, and its
 * read-only twin drift().
 *
 * @covers \MetalDivider\Site\Presentation\WindPressVolume
 */
final class WindPressVolumeTest extends TestCase {

	/**
	 * The per-test uploads directory: wp_get_upload_dir() answers it, the
	 * volume directory is created inside it.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * A fresh uploads directory, and the WordPress functions the volume calls.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->uploads = (string) tempnam( sys_get_temp_dir(), 'md-volume-' );
		unlink( $this->uploads );
		mkdir( $this->uploads );
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn( array( 'basedir' => $this->uploads ) );
		WP_Mock::userFunction( 'wp_mkdir_p' )->andReturnUsing(
			static function ( string $dir ): bool {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
	}

	/**
	 * The volume directory of this test's uploads.
	 */
	private function data_dir(): string {
		return $this->uploads . '/windpress/data';
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
	 * The exported stylesheets exist, name the tokens volume, and are
	 * the spec's 36 plus the #66 baseline: tokens, base, chrome, the nine
	 * component volumes and the twenty-five template volumes (spec 19 §7,
	 * ticket #66).
	 */
	public function test_files_are_the_exported_stylesheets(): void {
		$files = WindPressVolume::files();
		$this->assertContains( WindPressVolume::path() . '/tokens.css', $files, 'the tokens volume is there' );
		$this->assertContains( WindPressVolume::path() . '/base.css', $files, 'the #66 baseline volume is there' );
		$this->assertCount( 37, $files, 'spec 19 §7: 36 volumes plus the #66 baseline — tokens, base, chrome, nine components, twenty-five templates' );
		$volumes = glob( WindPressVolume::path() . '/*.css' );
		$this->assertIsArray( $volumes, 'the volume directory is readable' );
		foreach ( $volumes as $volume ) {
			$this->assertContains( $volume, $files, basename( $volume ) . ' is exported' );
			// CHECK-7's sibling: no version-family naming in the volumes.
			$this->assertDoesNotMatchRegularExpression( '/-v\d+\./', basename( $volume ) );
		}
	}

	/**
	 * The install() method copies every export into the volume and imports it
	 * from the entry stylesheet into the `components` layer, whose head carries
	 * the Tailwind v4 theme/utilities imports — the compiler's utility
	 * definitions (#58: an entry without them compiles no utility class at
	 * all, and an unlayered volume import lets the carried old-site utility
	 * rules beat the freshly built ones).
	 * rules beat the freshly built ones) — and whose tail carries the
	 * old-site px type scale (#66: the v4 rem defaults rendered 5xl/7xl at
	 * 48px/72px against the old 42px/56px).
	 */
	public function test_install_copies_the_exports_and_imports_them(): void {
		( new WindPressVolume() )->install();

		$this->assertFileEquals( WindPressVolume::path() . '/tokens.css', $this->data_dir() . '/tokens.css' );
		$this->assertFileEquals( WindPressVolume::path() . '/base.css', $this->data_dir() . '/base.css' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$entry = (string) file_get_contents( $this->data_dir() . '/main.css' );
		$this->assertStringContainsString( '@import "tailwindcss/utilities.css" layer(utilities);', $entry, 'the entry carries the utilities import' );
		$this->assertStringContainsString( '@import "tailwindcss/theme.css" layer(theme) theme(static);', $entry, 'the entry carries the theme import' );
		$this->assertStringContainsString( '@import "./tokens.css" layer(components);', $entry, 'the volume import is layered' );
		$this->assertStringNotContainsString( '@import "./tokens.css";', $entry, 'no unlayered twin of the volume import remains' );
		$this->assertLessThan( strpos( $entry, '@import "./tokens.css" layer(components);' ), strpos( $entry, '@import "tailwindcss/utilities.css"' ), 'the head precedes the volume imports' );
		$this->assertStringContainsString( '@import "./base.css" layer(components);', $entry, 'the baseline volume import is layered' );
		$this->assertStringNotContainsString( '@import "./tokens.css";', $entry, 'no unlayered twin of the volume import remains' );
		$this->assertLessThan( strpos( $entry, '@import "./tokens.css" layer(components);' ), strpos( $entry, '@import "tailwindcss/utilities.css"' ), 'the head precedes the volume imports' );

		$this->assertStringContainsString( '@theme static {', $entry, 'the entry carries the @theme px type scale (#66)' );
		$this->assertStringContainsString( '--text-5xl: 42px;--text-5xl--line-height: inherit;', $entry );
		$this->assertStringContainsString( '--text-7xl: 56px;--text-7xl--line-height: inherit;', $entry );
		$this->assertStringContainsString( '--text-9xl: 72px;--text-9xl--line-height: inherit;', $entry );
		$this->assertGreaterThan(
			strrpos( $entry, '@import "./tokens.css" layer(components);' ),
			strpos( $entry, '@theme static {' ),
			'the @theme block follows the imports: an @import after it would be ignored'
		);
	}

	/**
	 * A pre-#58 entry — bare volume imports, no Tailwind head — is repaired:
	 * the head is prepended, the bare imports are migrated to their layered
	 * form (never kept beside it), and the old entry stays beside it for a
	 * one-rename rollback.
	 */
	public function test_install_prepends_the_head_to_a_headless_entry(): void {
		$entry = $this->data_dir() . '/main.css';
		mkdir( $this->data_dir(), 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents( $entry, "@import \"./tokens.css\";\n" );

		( new WindPressVolume() )->install();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$updated = (string) file_get_contents( $entry );
		$this->assertStringStartsWith( '@layer theme, base, components, utilities;', $updated, 'the head leads the entry' );
		$this->assertStringContainsString( '@import "tailwindcss/utilities.css" layer(utilities);', $updated );
		$this->assertStringContainsString( '@import "./tokens.css" layer(components);', $updated, 'the legacy import is migrated to the components layer' );
		$this->assertStringNotContainsString( "\"./tokens.css\";\n", $updated, 'the bare import does not survive beside the layered one' );
		$this->assertSame( "@import \"./tokens.css\";\n", (string) file_get_contents( $entry . '.bak' ), 'the headless entry is kept for rollback' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$this->assertStringContainsString( '@theme static {', $updated, 'the repaired entry also carries the #66 px type scale' );
		$this->assertSame( "@import \"./tokens.css\";\n", (string) file_get_contents( $entry . '.bak' ), 'the headless entry is kept for rollback' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
	}

	/**
	 * An entry that carries an older copy of the #66 theme block is upgraded
	 * in place: the block is the entry's tail, so the current block replaces
	 * it and the entry never grows a second copy.
	 */
	public function test_install_upgrades_an_older_theme_block(): void {
		$volume = new WindPressVolume();
		$volume->install();
		$entry = $this->data_dir() . '/main.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$content  = (string) file_get_contents( $entry );
		$theme_at = strpos( $content, '/* md-site: old-site px type scale (#66) */' );
		$this->assertIsInt( $theme_at, 'the fresh entry carries the theme block' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents(
			$entry,
			substr( $content, 0, $theme_at ) . "/* md-site: old-site px type scale (#66) */\n@theme {\n--text-5xl: 48px;--text-5xl--line-height: 1;\n}\n"
		);

		$volume->install();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$updated = (string) file_get_contents( $entry );
		$this->assertSame( 1, substr_count( $updated, '/* md-site: old-site px type scale (#66) */' ), 'the older block is replaced, not duplicated' );
		$this->assertStringContainsString( '--text-5xl: 42px;--text-5xl--line-height: inherit;', $updated, 'the current px scale is written' );
		$this->assertStringNotContainsString( '--text-5xl: 48px', $updated, 'the stale value is gone' );
		$this->assertSame( array(), $volume->drift(), 'the upgraded entry is in sync' );
	}

	/**
	 * A second install with unchanged exports writes nothing: the entry does
	 * not grow a second import line, and no .bak appears — present is
	 * idempotent, which is what makes `just present` a safe rerun.
	 */
	public function test_second_install_writes_nothing(): void {
		$volume = new WindPressVolume();
		$volume->install();
		$entry = $this->data_dir() . '/main.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$first  = (string) file_get_contents( $entry );
		$tokens = $this->data_dir() . '/tokens.css';
		touch( $tokens, (int) ( time() - 3600 ) );

		$volume->install();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
		$this->assertSame( $first, (string) file_get_contents( $entry ), 'the entry does not grow a second import line' );
		$this->assertFileDoesNotExist( $entry . '.bak' );
		$this->assertFileDoesNotExist( $tokens . '.bak' );
		$this->assertFileEquals( WindPressVolume::path() . '/tokens.css', $tokens );
	}

	/**
	 * A drifted volume copy is restored and kept as .bak beside it.
	 */
	public function test_install_restores_a_drifted_copy_and_keeps_the_backup(): void {
		$volume = new WindPressVolume();
		$volume->install();
		$tokens = $this->data_dir() . '/tokens.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents( $tokens, '/* drifted */' );

		$volume->install();

		$this->assertFileEquals( WindPressVolume::path() . '/tokens.css', $tokens, 'the drift is replaced by the export' );
		$this->assertSame( '/* drifted */', (string) file_get_contents( $tokens . '.bak' ), 'the drifted copy stays for a one-rename rollback' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file, not a remote URL.
	}

	/**
	 * The drift() method is empty against a freshly installed volume, and reports a
	 * missing file, a drifted copy, a missing import and an entry without the
	 * Tailwind head each as its own row.
	 * missing file, a drifted copy, a missing import, an entry without the
	 * Tailwind head and an entry without the #66 @theme scale each as its own row.
	 */
	public function test_drift_reads_the_volume_against_the_exports(): void {
		$volume = new WindPressVolume();

		$this->assertSame(
			array(
				array(
					'surface' => 'windpress volume',
					'target'  => 'windpress/data',
					'detail'  => 'the volume directory does not exist: present has not installed it',
				),
			),
			$volume->drift(),
			'a site without a volume says so'
		);

		$volume->install();
		$this->assertSame( array(), $volume->drift(), 'a fresh install is in sync' );

		unlink( $this->data_dir() . '/tokens.css' );
		$missing = $volume->drift();
		$this->assertSame(
			array(
				array(
					'surface' => 'windpress volume',
					'target'  => 'tokens.css',
					'detail'  => 'not installed',
				),
			),
			$missing,
			'a missing volume file is its own row; the entry still imports it, so that is no row'
		);

		$entry = $this->data_dir() . '/main.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents( $entry, "@import \"./tokens.css\";\n" );
		$headless = $volume->drift();
		$this->assertContains(
			array(
				'surface' => 'windpress volume',
				'target'  => 'main.css',
				'detail'  => 'the entry does not carry the Tailwind v4 theme/utilities imports: no utility class compiles',
			),
			$headless,
			'an entry without the Tailwind head is a drift row (#58)'
		);
		$this->assertContains(
			array(
				'surface' => 'windpress volume',
				'target'  => 'main.css',
				'detail'  => 'the entry does not carry the current old-site px type scale @theme: text-5xl…9xl fall back to the Tailwind v4 rem defaults (#66)',
			),
			$headless,
			'an entry without the @theme px scale is a drift row (#66)'
		);
		$this->assertContains(
			array(
				'surface' => 'windpress volume',
				'target'  => 'main.css',
				'detail'  => 'the entry does not import ./tokens.css into the components layer',
			),
			$headless,
			'a bare volume import is a drift row: unlayered, the carried old-site utility rules beat the fresh build (#58)'
		);
		$this->assertCount( 40, $headless, 'the headless row, the theme row and the not-installed row join the 37 missing-layered-import rows' );
	}
}
