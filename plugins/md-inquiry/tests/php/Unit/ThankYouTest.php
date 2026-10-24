<?php
/**
 * Tests for the thank-you page's redirect target and verified context.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\ThankYou;
use WP_Mock;

/**
 * The redirect target and its verified context.
 *
 * @covers \MetalDivider\Inquiry\ThankYou
 */
final class ThankYouTest extends TestCase {

	/**
	 * The URL: the page's address when the template face has created it, the
	 * path under the home URL until then, and always the id and the keyed
	 * hash — enough to greet, never enough to enumerate.
	 */
	public function test_the_url_carries_the_id_and_its_key(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'get_page_by_path' )->once()->with( ThankYou::PATH )->andReturn( null );
		WP_Mock::userFunction( 'home_url' )->once()->with( '/inquiry/thank-you/' )->andReturn( 'https://metal-divider.com/inquiry/thank-you/' );
		WP_Mock::userFunction( 'add_query_arg' )->once()->andReturnUsing(
			static function ( array $args, string $url ): string {
				return $url . '?md_inquiry=' . $args['md_inquiry'] . '&key=' . $args['key'];
			}
		);
		WP_Mock::userFunction( 'wp_hash' )->once()->with( 'md_inquiry_thank_you_123' )->andReturn( 'the-key' );

		$this->assertSame(
			'https://metal-divider.com/inquiry/thank-you/?md_inquiry=123&key=the-key',
			ThankYou::url( 123 )
		);
	}

	/**
	 * The context answers only the request the chain itself issued: the right
	 * key over the right id, an inquiry record behind it.
	 */
	public function test_the_context_answers_a_verified_request(): void {
		$this->mock_passthroughs();
		$_GET = array(
			'md_inquiry' => '123',
			'key'        => 'the-key',
		);

		WP_Mock::userFunction( 'wp_hash' )->andReturn( 'the-key' );
		$post = new \WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'md_inquiry',
			)
		);
		WP_Mock::userFunction( 'get_post' )->once()->andReturn( $post );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ): string {
				return 'pattern_name' === $key ? 'Apiary' : 'APIARY';
			}
		);

		$this->assertSame(
			array(
				'pattern_name' => 'Apiary',
				'pattern_code' => 'APIARY',
			),
			ThankYou::context()
		);
	}

	/**
	 * Every other shape answers nothing: a wrong key, a missing id, a record
	 * of another type.
	 */
	public function test_every_other_shape_answers_nothing(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'wp_hash' )->andReturn( 'the-key' );

		$_GET = array(
			'md_inquiry' => '123',
			'key'        => 'not-the-key',
		);
		$this->assertNull( ThankYou::context(), 'a forged key answers nothing' );

		$_GET = array( 'key' => 'the-key' );
		$this->assertNull( ThankYou::context(), 'a missing id answers nothing' );

		$_GET = array(
			'md_inquiry' => 'abc',
			'key'        => 'the-key',
		);
		$this->assertNull( ThankYou::context(), 'a non-numeric id answers nothing' );

		$_GET = array(
			'md_inquiry' => '123',
			'key'        => 'the-key',
		);
		$post = new \WP_Post(
			(object) array(
				'ID'        => 123,
				'post_type' => 'post',
			)
		);
		WP_Mock::userFunction( 'get_post' )->once()->andReturn( $post );
		$this->assertNull( ThankYou::context(), 'a record of another type answers nothing' );
	}

	/**
	 * Housekeeping: the superglobal the tests borrowed goes back.
	 */
	public static function tearDownAfterClass(): void {
		$_GET = array();
		parent::tearDownAfterClass();
	}
}
