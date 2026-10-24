<?php
/**
 * The REST 404 anchor of the inquiry chain.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Every md-inquiry REST route answers 404, forever.
 *
 * The chain has no REST surface of its own — the form writes through its own
 * POST handler — and this anchor keeps it that way even against drift: a
 * future registration (a flipped show_in_rest, a route added elsewhere) hits
 * this filter before dispatch and answers not-found, the same answer the
 * mu-plugins smoke test asserts today (md-site-guards' inquiry REST anchor,
 * ticket #28). The two guards do not overlap: that one closes /wp/v2/users,
 * this one closes anything md-inquiry, and neither touches the other's
 * routes.
 */
final class RestGuard {

	/**
	 * The routes this anchor closes: any path whose segment is md-inquiry or
	 * md_inquiry, in any namespace.
	 */
	public const ROUTE = '#/(md[-_]inquiry)(/|$)#i';

	/**
	 * Hooks the anchor in.
	 */
	public function register(): void {
		\add_filter( 'rest_pre_dispatch', array( $this, 'close_md_inquiry_routes' ), 10, 3 );
	}

	/**
	 * Answers 404 for every md-inquiry route, before dispatch.
	 *
	 * @param mixed                $result  The pre-dispatch result, untouched unless this guard refuses.
	 * @param \WP_REST_Server|null $server  The server handling the request.
	 * @param mixed                $request The request being dispatched.
	 * @return mixed
	 */
	public function close_md_inquiry_routes( $result, $server = null, $request = null ) {
		unset( $server );
		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( 1 === \preg_match( self::ROUTE, $request->get_route() ) ) {
			return new \WP_Error( 'rest_not_found', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
		}
		return $result;
	}
}
