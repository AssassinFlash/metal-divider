<?php
/**
 * Tests for the template inventory and its drift check.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Templates: the inventory every face of spec 19 §3 names
 * exists as a file, the reader refuses an empty source, and the drift
 * check reports exactly what is missing on a bare site.
 *
 * @covers \MetalDivider\Site\Presentation\Templates
 */
final class TemplatesTest extends TestCase {

	/**
	 * Every face the spec names (25 templates, spec 19 §3) exists as a
	 * repository file: the page-type files, the per-page files, and the
	 * four partials.
	 */
	public function test_every_face_has_its_file(): void {
		$reflection = new \ReflectionClass( Templates::class );
		foreach ( array( 'FILES', 'PAGES', 'PARTIALS' ) as $constant ) {
			$table = $reflection->getConstant( $constant );
			$files = array();
			foreach ( $table as $entry ) {
				$files[] = is_array( $entry ) ? $entry[0] : $entry;
			}
			if ( 'PARTIALS' === $constant ) {
				$files = array_keys( $table );
			}
			foreach ( $files as $file ) {
				$path = Templates::path( (string) $file, 'PARTIALS' === $constant );
				$this->assertFileExists( $path, "{$constant} names {$file}" );
				$this->assertNotSame( '', trim( (string) file_get_contents( $path ) ), "{$file} is not empty" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			}
		}
		$this->assertCount( 7, $reflection->getConstant( 'FILES' ) );
		$this->assertCount( 18, $reflection->getConstant( 'PAGES' ) );
		$this->assertCount( 5, $reflection->getConstant( 'PARTIALS' ) );
	}

	/**
	 * The face count is the spec's 25: seven page-type faces plus
	 * eighteen per-page faces.
	 */
	public function test_the_face_count_is_the_specs_25(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$this->assertSame(
			25,
			count( $reflection->getConstant( 'FILES' ) ) + count( $reflection->getConstant( 'PAGES' ) ),
			'spec 19 §3: 25 LiveCanvas dynamic templates'
		);
	}

	/**
	 * The read() method refuses an empty source — an empty export would blank a
	 * live face, which is a refusal, never a write.
	 */
	public function test_read_refuses_an_empty_source(): void {
		$path = (string) tempnam( sys_get_temp_dir(), 'md-tpl-' );
		file_put_contents( $path, "   \n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file.
		try {
			Templates::read( $path );
			$this->fail( 'an empty source must refuse' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'missing or empty', $error->getMessage() );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * On a bare site — no template row, no partial, no page — drift
	 * reports every face absent: the read-only twin names what present
	 * would install.
	 */
	public function test_drift_on_a_bare_site_reports_every_face(): void {
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );

		$rows = ( new Templates() )->drift();

		$reflection = new \ReflectionClass( Templates::class );
		$expected   = count( $reflection->getConstant( 'FILES' ) )
			+ 2 * count( $reflection->getConstant( 'PAGES' ) )  // Template absent + page absent each.
			+ count( $reflection->getConstant( 'PARTIALS' ) );
		$this->assertCount( $expected, $rows );
		$this->assertContainsOnly( 'array', $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( array( 'surface', 'target', 'detail' ), array_keys( $row ) );
		}
	}

	/**
	 * Zero inline styles in the templates (AGENTS.md §5, spec 19 §4.2 T2,
	 * ticket #47): no style= attribute, no <style> block — composition in
	 * the template, styles in the WindPress volume. The scan is the whole
	 * tree, so a future face cannot reintroduce the debt unseen; the one
	 * exception is the marquee's Alpine :style binding, a runtime
	 * expression (interaction, not chrome), which carries no static value.
	 */
	public function test_the_templates_carry_no_inline_styles(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$this->assertGreaterThan( 20, iterator_count( $files ), 'the tree the scan walks is the template inventory' );
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = basename( (string) $file );
			// The attribute form — whitespace, then style= — in either quote
			// style; the Alpine :style binding (colon-prefixed) is not one.
			preg_match_all( '/(?<=\s)style=/', $source, $attributes );
			$this->assertSame( array(), $attributes[0], "{$relative} carries style= attributes" );
			$this->assertStringNotContainsString( '<style', $source, "{$relative} carries a <style> block" );
		}
	}
}
