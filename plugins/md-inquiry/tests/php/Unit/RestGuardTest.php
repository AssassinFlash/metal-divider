<?php
/**
 * Tests for the REST 404 anchor.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\RestGuard;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The anchor of ticket #33's REST item: every md-inquiry route answers 404,
 * in any namespace, forever — the regression wall the mu-plugins smoke test
 * already patrols from the outside; this is the inside half.
 *
 * @covers \MetalDivider\Inquiry\RestGuard
 */
final class RestGuardTest extends TestCase {

	/**
	 * Every md-inquiry route — the CPT's own REST face, a namespaced
	 * controller, the types endpoint — answers 404 before dispatch.
	 */
	public function test_every_md_inquiry_route_answers_404(): void {
		$guard = new RestGuard();
		WP_Mock::expectFilterAdded( 'rest_pre_dispatch', array( $guard, 'close_md_inquiry_routes' ), 10, 3 );
		$guard->register();

		foreach ( array( '/wp/v2/md_inquiry', '/wp/v2/md_inquiry/12', '/md-inquiry/v1/submit', '/md_inquiry/v2/x', '/wp/v2/types/md_inquiry' ) as $route ) {
			$result = $guard->close_md_inquiry_routes( null, null, new \WP_REST_Request( $route ) );
			$this->assertInstanceOf( \WP_Error::class, $result, $route );
			$this->assertSame( 'rest_not_found', $result->get_error_code(), $route );
		}
	}

	/**
	 * Everything else passes through untouched: the guard closes its own
	 * routes and nothing else's — the users guard's routes included.
	 */
	public function test_everything_else_passes_through(): void {
		$guard    = new RestGuard();
		$incoming = array( 'answer' => 'kept' );

		foreach ( array( '/wp/v2/users', '/wp/v2/posts', '/wc/v3/products', '/', '/wp/v2/pages/3' ) as $route ) {
			$this->assertSame( $incoming, $guard->close_md_inquiry_routes( $incoming, null, new \WP_REST_Request( $route ) ), $route );
		}
		$this->assertSame( $incoming, $guard->close_md_inquiry_routes( $incoming, null, 'not a request' ), 'a non-request passes through' );
	}
}
