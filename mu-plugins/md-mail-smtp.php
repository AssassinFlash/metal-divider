<?php
/**
 * Plugin Name: MD Mail SMTP
 * Description: Sends every wp_mail through the site mailbox's authenticated Hostinger SMTP so the envelope sender aligns with the From domain. Without the wp-config constants the mailer stays exactly as the host configured it.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\MailSmtp;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The resolved transport settings: the wp-config constants, or the test's
 * stand-ins for them. Null where the deployment has not configured SMTP.
 *
 * @param array<string, mixed>|null $overrides Constant-shaped values for tests.
 * @return array{host: string, user: string, pass: string, port: int, from: string}|null
 */
function settings( ?array $overrides = null ): ?array {
	$read = static function ( string $constant, mixed $fallback ) use ( $overrides ): mixed {
		if ( null !== $overrides && \array_key_exists( $constant, $overrides ) ) {
			$value = $overrides[ $constant ];
		} elseif ( \defined( $constant ) ) {
			$value = \constant( $constant );
		} else {
			$value = $fallback;
		}
		return $value;
	};

	$host = $read( 'MD_SMTP_HOST', '' );
	$user = $read( 'MD_SMTP_USER', '' );
	$pass = $read( 'MD_SMTP_PASS', '' );
	if ( ! \is_string( $host ) || '' === $host || ! \is_string( $user ) || '' === $user || ! \is_string( $pass ) || '' === $pass ) {
		return null;
	}

	$port = $read( 'MD_SMTP_PORT', 465 );
	$port = \is_int( $port ) && 1 <= $port && $port <= 65535 ? $port : 465;
	$from = $read( 'MD_SMTP_FROM', $user );
	$from = \is_string( $from ) && '' !== $from ? $from : $user;

	return array(
		'host' => $host,
		'user' => $user,
		'pass' => $pass,
		'port' => $port,
		'from' => $from,
	);
}

/**
 * Applies the SMTP transport to one mailer instance.
 *
 * @param mixed                     $phpmailer The mailer wp_mail is about to use (PHPMailer at runtime; untyped so the guard below stays honest outside WordPress).
 * @param array<string, mixed>|null $overrides Constant-shaped values for tests.
 * @return void
 */
function apply( $phpmailer, ?array $overrides = null ): void {
	$settings = settings( $overrides );
	if ( null === $settings || ! $phpmailer instanceof PHPMailer ) {
		return;
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's public API is camel-cased; these are its real property names.
	$phpmailer->isSMTP();
	$phpmailer->Host       = $settings['host'];
	$phpmailer->Port       = $settings['port'];
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = $settings['user'];
	$phpmailer->Password   = $settings['pass'];
	$phpmailer->SMTPSecure = 465 === $settings['port'] ? 'ssl' : 'tls';

	// The envelope sender must match the authenticated mailbox: without this
	// the platform's noreply@<server> envelope fails DMARC alignment and the
	// notification lands in Junk even though the header From is correct.
	$phpmailer->Sender = $settings['user'];
	$phpmailer->From   = $settings['from'];
	// phpcs:enable
}

\add_action( 'phpmailer_init', __NAMESPACE__ . '\\apply' );
