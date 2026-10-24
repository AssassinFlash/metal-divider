<?php
/**
 * Minimal WordPress classes for the unit tests: the members this plugin uses,
 * with WordPress's signatures (WP_Mock mocks functions, not classes), and a
 * wp-cli double that prints every line the commands log.
 *
 * @package MetalDivider\Site
 */

// phpcs:disable -- test doubles mirror WordPress's own class names and signatures.

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $post_content = '';
		/** @var string */
		public $post_type = '';
		/** @var string */
		public $post_name = '';
		/** @var string */
		public $post_title = '';
		/** @var string */
		public $post_status = '';
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var array<string, object> The commands add_command registered. */
		public static array $registered_commands = array();
		public static function error( $message ) {
			throw new \WP_CLI\ExitException( (string) $message );
		}
		public static function log( $message ) {
			echo 'wp-cli log: ' . $message . "\n";
		}
		public static function success( $message ) {
			echo 'wp-cli success: ' . $message . "\n";
		}
		public static function warning( $message ) {
			echo 'wp-cli warning: ' . $message . "\n";
		}
		public static function add_command( $name, $callable ) {
			self::$registered_commands[ (string) $name ] = $callable;
		}
	}
}
if ( ! class_exists( 'WP_CLI\ExitException' ) ) {
	class WP_CLI_ExitException extends \Exception {}
	class_alias( WP_CLI_ExitException::class, 'WP_CLI\\ExitException' );
}
