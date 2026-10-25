<?php
/**
 * Tests for the WindPress anchor command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\WindPressAnchorsCommand;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for WindPressAnchorsCommand: the machine-readable contract the
 * deploy's `windpress-check` reads (`wp md-site windpress-anchors
 * --format=json` prints one JSON array of the missing anchors, `[]` on a
 * pass), the operator's table form, and the absent cache.
 *
 * @covers \MetalDivider\Site\Cli\WindPressAnchorsCommand
 */
final class WindPressAnchorsCommandTest extends TestCase {

	/**
	 * The per-test uploads directory.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * A fresh uploads directory, and the uploads lookup the checker calls.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->uploads = (string) tempnam( sys_get_temp_dir(), 'md-anchors-cli-' );
		unlink( $this->uploads );
		mkdir( $this->uploads );
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn( array( 'basedir' => $this->uploads ) );
		WP_Mock::userFunction( 'wp_json_encode' )->zeroOrMoreTimes()->andReturnUsing( static fn( $value ) => json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
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
	 * Writes the per-test compiled cache.
	 *
	 * @param string $css The compiled stylesheet.
	 */
	private function cache( string $css ): void {
		$dir = $this->uploads . '/windpress/cache';
		mkdir( $dir, 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents( $dir . '/tailwind.css', $css );
	}

	/**
	 * Every anchor the checker derives, each with a rule to match it — a
	 * passing cache, with one entry optionally wrong.
	 *
	 * @param string[] $missing Anchors to leave out.
	 * @param string[] $blank   Anchors to emit with an empty body.
	 */
	private function passing_cache( array $missing = array(), array $blank = array() ): string {
		$css = '';
		foreach ( ( new \MetalDivider\Site\Presentation\WindPressAnchors() )->anchors() as $row ) {
			$anchor = $row['anchor'];
			if ( 'token' === $row['kind'] ) {
				$css .= ':root{' . $anchor . '}';
				continue;
			}
			if ( in_array( $anchor, $missing, true ) ) {
				continue;
			}
			$escaped = '';
			$length  = strlen( $anchor );
			for ( $i = 0; $i < $length; $i++ ) {
				$char     = $anchor[ $i ];
				$plain    = ord( $char ) > 127 || 1 === preg_match( '/[A-Za-z0-9_-]/', $char );
				$escaped .= $plain ? $char : '\\' . $char;
			}
			$css .= '.' . $escaped . '{' . ( in_array( $anchor, $blank, true ) ? '' : 'display:block' ) . '}';
		}
		return $css;
	}

	/**
	 * The JSON form prints `[]` and exits 0 when the cache carries every
	 * anchor — the exact stream the deploy reads as a pass. (The WP_CLI double
	 * prefixes logged lines with `wp-cli log: `; real wp-cli writes them bare,
	 * which the deploy's `[]` / `[` check reads either way.)
	 */
	public function test_the_json_form_prints_an_empty_array_on_a_pass(): void {
		$this->cache( $this->passing_cache() );

		ob_start();
		( new WindPressAnchorsCommand() )->windpress_anchors( array(), array( 'format' => 'json' ) );
		$output = trim( (string) ob_get_clean() );

		$this->assertStringEndsWith( '[]', $output, 'the array is the whole payload of the pass' );
		$this->assertStringNotContainsString( 'present in the compiled cache', $output, 'the JSON form prints no prose' );
	}

	/**
	 * The JSON form prints the missing anchors as one array and exits non-zero,
	 * so the deploy's note names them.
	 */
	public function test_the_json_form_prints_the_missing_anchors(): void {
		$this->cache( $this->passing_cache( array( 'aspect-[16/10]' ) ) );

		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'windpress anchors: 1 of' );

		ob_start();
		try {
			( new WindPressAnchorsCommand() )->windpress_anchors( array(), array( 'format' => 'json' ) );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertSame( '["aspect-[16/10]"]', $output, 'the deploy parses this stream: one array, nothing else' );
	}

	/**
	 * The table form is the operator's: the anchors on their own lines and a
	 * summary, so a recompile decision does not need a JSON reader.
	 */
	public function test_the_table_form_lists_the_missing_anchors(): void {
		$this->cache( $this->passing_cache( array(), array( 'max-w-[1400px]' ) ) );

		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'windpress anchors: 1 of' );

		ob_start();
		try {
			( new WindPressAnchorsCommand() )->windpress_anchors( array(), array() );
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'max-w-[1400px]', $output );
	}

	/**
	 * The table form on a pass says how many anchors it checked.
	 */
	public function test_the_table_form_reports_a_pass(): void {
		$this->cache( $this->passing_cache() );

		ob_start();
		( new WindPressAnchorsCommand() )->windpress_anchors( array(), array() );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'present in the compiled cache', $output );
	}

	/**
	 * No compiled cache is a refusal, not `[]`: the anchors are not selectively
	 * missing, the compile has not run.
	 */
	public function test_no_compiled_cache_is_a_refusal(): void {
		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'the WindPress cache does not exist' );

		( new WindPressAnchorsCommand() )->windpress_anchors( array(), array( 'format' => 'json' ) );
	}
}
