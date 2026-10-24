<?php
/**
 * Constants PHPStan needs to read the mu-plugins without an instance: the ones
 * md-local writes into an instance's wp-config.php, and WPINC, which
 * wp-settings.php defines and szepeviktor/phpstan-wordpress's bootstrap does not.
 *
 * @package Md\Local
 */

define( 'MD_LOCAL_MAIL_DIR', '/instance/mail' );
define( 'MD_LOCAL_ISOLATION_LOG', '/instance/logs/isolation.log' );
define( 'MD_LOCAL_ALLOW_HOSTS', '/instance/allow-hosts.txt' );
define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant.
