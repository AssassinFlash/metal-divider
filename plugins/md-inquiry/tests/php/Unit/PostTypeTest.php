<?php
/**
 * Tests for the inquiry system-of-record post type.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\PostType;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * Tests for PostType: the registration shell of the inquiry system record
 * (spec 07 §7, branch research/spec-inquiry-chain).
 *
 * @covers \MetalDivider\Inquiry\PostType
 */
final class PostTypeTest extends TestCase {

	/**
	 * The register() method hooks init, and the callback registers md_inquiry with the
	 * system-record shape that spec fixes: non-public, back-office UI only,
	 * title and date alone.
	 */
	public function test_register_hooks_init_and_registers_the_system_record(): void {
		$post_type = new PostType();

		WP_Mock::expectActionAdded( 'init', array( $post_type, 'register_post_type' ) );
		$registered = array();
		WP_Mock::userFunction( 'register_post_type' )->once()->andReturnUsing(
			static function ( string $name, array $args ) use ( &$registered ): void {
				$registered[ $name ] = $args;
			}
		);

		$post_type->register();
		$post_type->register_post_type();

		$this->assertArrayHasKey( PostType::NAME, $registered, 'the post type is md_inquiry' );
		$args = $registered[ PostType::NAME ];
		$this->assertFalse( $args['public'], 'an inquiry is never a public post (spec 07 §7)' );
		$this->assertTrue( $args['show_ui'], 'the back-office list is where inquiries are read' );
		$this->assertFalse( $args['show_in_rest'], 'no REST surface of its own: the form writes through its own controller' );
		$this->assertSame( array( 'title', 'date' ), $args['supports'], 'title (filled on submission) and date alone; everything else is the field groups of the chain\'s own ticket' );
		$this->assertSame( 'page', $args['capability_type'], 'the page capabilities gate the records: editors and up, never the author role' );
		$this->assertFalse( $args['show_in_admin_bar'], 'no new-inquiry shortcut in the admin bar' );
	}
}
