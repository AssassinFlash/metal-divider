<?php
/**
 * Static-analysis stubs for wp-cli: wp-cli is neither in the WordPress stubs
 * nor shipped by this plugin. phpstan reads this file via scanFiles; the test
 * bootstrap never loads it (the WP-CLI guard in the commands answers at
 * runtime, where the real WP_CLI exists). Only the surface this plugin
 * touches is declared.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declarations for static analysis; the WordPress prefix and naming sniffs do not apply to wp-cli's own names.

namespace {
	class WP_CLI {

		public static function add_command( string $name, $command ): void {
		}

		public static function error( string $message ): void {
		}

		public static function line( string $message ): void {
		}

		public static function log( string $message ): void {
		}

		public static function success( string $message ): void {
		}
	}
}

namespace WP_CLI {

	class ExitException extends \Exception {
	}
}

namespace WP_CLI\Utils {

	/**
	 * Formats items into a table or another shape (wp-cli's own helper, in
	 * its Utils namespace).
	 *
	 * @param string             $format The output format.
	 * @param array<int, mixed>  $items  The items.
	 * @param array<int, string> $fields The fields to show.
	 */
	function format_items( string $format, array $items, array $fields ): void {
	}
}
