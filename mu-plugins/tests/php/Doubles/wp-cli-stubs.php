<?php
/**
 * Static-analysis stubs for wp-cli: wp-cli is neither in the WordPress stubs
 * nor shipped with the mu-plugins, and the runtime double (tests/php/
 * bootstrap.php) exists only under phpunit. phpstan reads this file via
 * scanFiles; the test bootstrap never loads it. Only the surface production
 * code touches is declared.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declarations for static analysis; the
// WordPress prefix and naming sniffs do not apply to wp-cli's own names.

namespace {
	class WP_CLI {

		/**
		 * The commands add_command registered; only the test double keeps
		 * this readable (the runtime double in tests/php/bootstrap.php
		 * records into it), but the tests' read-back needs the type.
		 *
		 * @var array<string, callable|string>
		 */
		public static $registered_commands = array();

		/**
		 * @param string                          $name    Command name.
		 * @param callable|object|array|string    $command The command implementation; the mu-plugins are procedural, so theirs are plain function names.
		 */
		public static function add_command( string $name, $command ): void {
		}

		public static function error( string $message ): void {
		}

		public static function log( string $message ): void {
		}

		public static function success( string $message ): void {
		}

		public static function warning( string $message ): void {
		}
	}
}

namespace WP_CLI {

	class ExitException extends \Exception {
	}
}
