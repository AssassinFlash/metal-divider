<?php
/**
 * PHPUnit bootstrap of the migrator's WordPress side: the Composer
 * autoloader, the runtime doubles of the WordPress/WooCommerce surface, and
 * ABSPATH so the driver's guard lets the file be evaluated outside WordPress
 * exactly the way `wp eval-file` evaluates it inside.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- a test bootstrap defines ABSTRACT exactly as WordPress would.

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/Doubles/wordpress-fakes.php';
require_once __DIR__ . '/Support/fakes.php';
require_once __DIR__ . '/Support/fake-product.php';
require_once __DIR__ . '/Support/fake-wpdb.php';

define( 'ABSPATH', sys_get_temp_dir() . '/md-migrate-test/' );

require_once __DIR__ . '/Support/runner.php';
