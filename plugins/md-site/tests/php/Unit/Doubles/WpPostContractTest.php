<?php
/**
 * The shared WP_Post double against the members this plugin reads.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Doubles;

use MetalDivider\Site\Tests\TestCase;

/**
 * WP_Mock mocks functions, not classes: a fixture hands production code
 * whatever object it likes for `get_post()`. The shared double in
 * tests/php/Doubles/wordpress.php is the real contract's stand-in, so it
 * has to declare every member the plugin reads — otherwise the first
 * fixture that reaches a new read fails as a fatal in whichever test
 * happens to run first, which says nothing about the change that caused
 * it.
 *
 * That is exactly how the design-intent lead broke: `lead_text()` reads
 * `$post->post_excerpt`, the double carried it, and four fixtures that
 * built a bare `stdClass` did not. This test pins the double to the
 * reads so the failure lands on the member, not on a random test.
 *
 * @coversNothing
 */
final class WpPostContractTest extends TestCase {

	/**
	 * Every `->post_*` / `->ID` member read anywhere in the plugin's
	 * includes/ is declared on the shared double.
	 */
	public function test_the_double_declares_every_member_the_plugin_reads(): void {
		$includes = dirname( __DIR__, 4 ) . '/includes';
		$reads    = array();
		$files    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $includes, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			// A member read, not a method call and not a property of this
			// class: `->post_by_title(` is Config's own method, and
			// `$this->…` never reaches a WP_Post.
			preg_match_all( '/->(post_[a-z_]+|ID)\b(?!\s*\()/', $source, $matches );
			foreach ( $matches[1] as $member ) {
				$reads[ $member ] = true;
			}
		}

		$this->assertNotSame( array(), $reads, 'the scan found the plugin’s post reads' );

		$declared = get_class_vars( \WP_Post::class );
		foreach ( array_keys( $reads ) as $member ) {
			$this->assertArrayHasKey(
				$member,
				$declared,
				"the WP_Post double must declare \${$member}: production code reads it, and a fixture that mocks get_post() relies on the double to carry it"
			);
		}
	}

	/**
	 * The member this test exists for: the design-intent lead's excerpt
	 * fallback, which was absent from four fixtures' bare objects.
	 *
	 * The post is built the way every fixture in this suite builds one —
	 * `new WP_Post( (object) array() )` — because WordPress's own
	 * constructor takes the row object (stubs: `__construct($post)`).
	 * The runtime double ignores the argument, so the defaults still
	 * apply; the single argument keeps the static and runtime contracts
	 * the same shape.
	 */
	public function test_the_double_carries_the_post_excerpt(): void {
		$post = new \WP_Post( new \stdClass() );

		$this->assertObjectHasProperty( 'post_excerpt', $post );
		$this->assertSame( '', $post->post_excerpt, 'an unset excerpt is the empty string, as WordPress returns it' );
	}
}
