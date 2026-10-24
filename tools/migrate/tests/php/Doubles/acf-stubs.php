<?php
/**
 * Static-analysis stubs for the ACF free surface 18-wordpress.php touches:
 * get_field/update_field/delete_field/acf_get_field are runtime functions of
 * the plugin, neither in the WordPress stubs nor on Packagist. At runtime
 * they exist wherever ACF is active. phpstan reads this file via scanFiles;
 * the test bootstrap never loads it, and PHPStan does not analyse it.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis; the
// WordPress prefix and naming sniffs do not apply to ACF's own names.

namespace {
	/**
	 * ACF's field reader.
	 *
	 * @param string          $selector     The field name.
	 * @param int|string|bool $post_id      The post the field lives on.
	 * @param bool            $format_value Whether to format the raw value.
	 * @return mixed The field's value.
	 */
	function get_field( $selector, $post_id = false, $format_value = true ) {}

	/**
	 * ACF's field writer.
	 *
	 * @param string          $selector The field name.
	 * @param mixed           $value    The value to write.
	 * @param int|string|bool $post_id  The post the field lives on.
	 * @return bool|int Whether it wrote (or the meta id).
	 */
	function update_field( $selector, $value, $post_id = false ) {}

	/**
	 * ACF's field deleter.
	 *
	 * @param string          $selector The field name.
	 * @param int|string|bool $post_id  The post the field lives on.
	 * @return bool Whether it deleted.
	 */
	function delete_field( $selector, $post_id = false ) {}

	/**
	 * ACF's field registry lookup.
	 *
	 * @param string|int $selector The field name or key.
	 * @return array<string, mixed>|false The field's definition, false when absent.
	 */
	function acf_get_field( $selector ) {}
}
