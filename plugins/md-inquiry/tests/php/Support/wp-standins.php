<?php
/**
 * WordPress class stand-ins for the unit tests' runtime.
 *
 * PHPStan never reads this file (phpstan.neon.dist excludes it): it knows
 * these names from the WordPress stubs. At runtime the stubs are not loaded,
 * so these definitions are what the tests get — the two classes the chain
 * constructs or type-checks against (WP_Error, WP_REST_Request), carrying
 * just the surface the tests touch.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- these are WordPress's own names; a test bootstrap stands in for WordPress itself.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Error' ) ) {

	/**
	 * The error the tests pass around.
	 */
	final class WP_Error {

		/**
		 * The error's message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * @param string $code    The error's code.
		 * @param string $message The error's message.
		 * @param mixed  $data    The error's data (unused here).
		 */
		public function __construct( public string $code = '', string $message = '', public mixed $data = null ) {
			$this->message = $message;
		}

		/**
		 * The error's message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * The error's code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {

	/**
	 * The post the tests pass around, with the three members the chain reads.
	 */
	final class WP_Post {

		/**
		 * The post's ID.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * The post's type.
		 *
		 * @var string
		 */
		public string $post_type = '';

		/**
		 * The post's slug.
		 *
		 * @var string
		 */
		public string $post_name = '';

		/**
		 * The members the tests set, the way core's own constructor takes
		 * the whole row: as an object.
		 *
		 * @param object|null $post The post's row.
		 */
		public function __construct( ?object $post = null ) {
			foreach ( array( 'ID', 'post_type', 'post_name' ) as $key ) {
				$value = $post->{$key} ?? null;
				if ( null !== $value ) {
					$this->{$key} = $value; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the stand-in carries WordPress's own member names.
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {

	/**
	 * The request the REST anchor checks.
	 */
	final class WP_REST_Request {

		/**
		 * The route the request names.
		 *
		 * @var string
		 */
		public string $route = '';

		/**
		 * @param string $route The route the request names.
		 */
		public function __construct( string $route = '' ) {
			$this->route = $route;
		}

		/**
		 * The route the request names.
		 *
		 * @return string
		 */
		public function get_route(): string {
			return $this->route;
		}
	}
}
