<?php
/**
 * Static-analysis stubs for ACF free: it supplies acf_add_local_field_group
 * at runtime, is neither in the WordPress stubs nor on Packagist, and the
 * test runtime needs no declaration (WP_Mock defines the function the
 * registration test intercepts). phpstan reads this file via scanFiles; the
 * test bootstrap never loads it. Only the surface this plugin touches is
 * declared.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis; the
// WordPress prefix and naming sniffs do not apply to ACF's own name.

namespace {
	function acf_add_local_field_group( array $field_group ): void {}
}
