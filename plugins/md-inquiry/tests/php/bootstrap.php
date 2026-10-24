<?php
/**
 * PHPUnit bootstrap: the WP-named class stand-ins, this plugin's Composer
 * autoloader, then the bootstrap the PHP projects share (WP_Mock strict).
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

require_once __DIR__ . '/Support/wp-standins.php';
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 4 ) . '/tools/php/bootstrap.php';
