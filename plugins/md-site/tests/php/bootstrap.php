<?php
/**
 * PHPUnit bootstrap: this plugin's Composer autoloader, the bootstrap the PHP
 * projects share (WP_Mock strict), then the doubles for the classes
 * WordPress and wp-cli do not ship in stubs (tests/php/Doubles).
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 4 ) . '/tools/php/bootstrap.php';
require_once __DIR__ . '/Doubles/wordpress.php';
require_once __DIR__ . '/Doubles/shortcodes.php';
require_once __DIR__ . '/Doubles/seopress-woocommerce.php';
