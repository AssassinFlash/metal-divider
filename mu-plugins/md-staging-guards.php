<?php
/**
 * Plugin Name: MD staging guards
 * Description: Keeps the review site off the indexes and its mail out of the world: the noindex response header, a robots.txt that disallows everything, and wp_mail written to files instead of sent.
 *
 * The review site's protection is three layers deep (docs/specs/20-staging-deploy.md
 * §1.3): the WordPress "discourage search engines" setting, this plugin's
 * X-Robots-Tag header and robots.txt, and hPanel's password protection on the
 * directory. The server-layer password is the layer that holds when WordPress
 * is broken; this file is the layer that follows the content anywhere it is
 * syndicated or previewed.
 *
 * Mail is captured, never sent (spec §1.2): the review site has no reason to
 * send real mail, and a form probe on it must not reach a customer or spend
 * the subscription's mail() quota. The message is written as the exact bytes
 * mail() would receive — To, Subject, Cc and Bcc included — so the queue's
 * recipient data can be asserted from the files (the same discipline as the
 * local stack's isolation, whose mailer this one follows).
 *
 * Only ever deployed to the staging target: the deploy inventory filters this
 * file out of the production set (spec §3.2), and production mail is the
 * queued real thing.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu;

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', array( StagingGuards::class, 'capture_mail' ), PHP_INT_MAX );
add_filter( 'robots_txt', array( StagingGuards::class, 'robots_txt' ) );

if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
	header( 'X-Robots-Tag: noindex, nofollow' );
}

/*
 * This file uses plain PHP file functions and the @ operator where the
 * WordPress rules would ask for WP_Filesystem and checked calls, for the same
 * reason the error recorder does: the guards must hold when WordPress's own
 * code is the thing that broke, and WP_Filesystem needs a working WordPress.
 * Every silenced call is followed by a check of its result or is a best
 * effort whose failure leaves the site as it was.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

/**
 * The review site's three mail and indexing guards.
 */
final class StagingGuards {

	/**
	 * Directory under wp-content the captured messages live in.
	 */
	public const DIRECTORY = 'md-staging-mail';

	/**
	 * The most recent messages kept: a review site is reset freely, and the
	 * files exist to be read, not to accumulate.
	 */
	public const KEEP = 50;

	/**
	 * Hands wp_mail() a mailer that writes the message instead of sending it.
	 *
	 * @param null|bool $pre Short-circuit value from an earlier filter; null to send.
	 * @return null|bool The value unchanged: the capture happens by replacing
	 *                   the mailer, so any earlier short-circuit keeps its word.
	 */
	public static function capture_mail( $pre ) {
		if ( null === $pre && defined( 'WP_CONTENT_DIR' ) ) {
			$directory = self::directory( (string) WP_CONTENT_DIR );
			if ( '' !== $directory ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() sends through this global; replacing it is how mail stays on the site.
				$GLOBALS['phpmailer'] = self::mailer( $directory );
			}
		}
		return $pre;
	}

	/**
	 * A robots.txt that disallows every path.
	 *
	 * @param string $output The robots.txt text so far — the filter's contract;
	 *                       the review site has no public part, so nothing of
	 *                       it survives.
	 * @return string One rule, disallowing everything.
	 */
	public static function robots_txt( $output ): string {
		unset( $output ); // The whole file is replaced; the parameter is the filter's contract.
		return "User-agent: *\nDisallow: /\n";
	}

	/**
	 * The mail directory, created and protected, or '' when it cannot be made.
	 *
	 * @param string $content_dir Absolute path of wp-content.
	 * @return string
	 */
	public static function directory( string $content_dir ): string {
		$directory = rtrim( $content_dir, '/' ) . '/' . self::DIRECTORY;
		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			return '';
		}
		self::protect( $directory );
		return $directory;
	}

	/**
	 * A WordPress mailer whose sending step writes the finished message to a
	 * file: the same bytes mail() would receive.
	 *
	 * @param string $directory Directory of the .eml files.
	 * @return \WP_PHPMailer
	 */
	public static function mailer( string $directory ) {
		// WPINC is 'wp-includes' by WordPress's own definition; the literal is
		// the fallback for the path when the constant is not in the stubs.
		$includes = defined( 'WPINC' ) ? WPINC : 'wp-includes';
		require_once ABSPATH . $includes . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . $includes . '/PHPMailer/SMTP.php';
		require_once ABSPATH . $includes . '/PHPMailer/Exception.php';
		require_once ABSPATH . $includes . '/class-wp-phpmailer.php';

		return new class( $directory ) extends \WP_PHPMailer {

			/**
			 * Directory of the .eml files.
			 *
			 * @var string
			 */
			private string $directory;

			/**
			 * Constructor.
			 *
			 * @param string $directory Directory of the .eml files.
			 */
			public function __construct( string $directory ) {
				// Exceptions on, as wp_mail() creates its mailer.
				parent::__construct( true );
				$this->directory = $directory;
			}

			/**
			 * Writes the message instead of sending it, then prunes the old.
			 *
			 * @return bool True; a failure throws, as PHPMailer's own sending
			 *              does with exceptions on, so wp_mail() reports wp_mail_failed.
			 * @throws \PHPMailer\PHPMailer\Exception When the file cannot be written.
			 */
			public function postSend(): bool {
				$file = sprintf(
					'%s/%s-%s.eml',
					$this->directory,
					gmdate( 'Ymd-His' ),
					wp_generate_uuid4()
				);
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file outside WP_Filesystem's scope, the way the local stack's isolation writes its mail.
				if ( false === file_put_contents( $file, $this->getSentMIMEMessage() ) ) {
					throw new \PHPMailer\PHPMailer\Exception( esc_html( sprintf( 'Could not write %s.', $file ) ) );
				}
				$this->prune();
				return true;
			}

			/**
			 * Keeps the newest messages only: the directory is evidence for
			 * review, not a mailbox.
			 */
			private function prune(): void {
				$files = glob( $this->directory . '/*.eml' );
				if ( ! is_array( $files ) || count( $files ) <= StagingGuards::KEEP ) {
					return;
				}
				sort( $files );
				foreach ( array_slice( $files, 0, count( $files ) - StagingGuards::KEEP ) as $old ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- pruning the plugin's own files.
					@unlink( $old );
				}
			}
		};
	}

	/**
	 * Refuses web requests for the directory: a deny file for the web server,
	 * an empty index for a directory listing that ignores it.
	 *
	 * @param string $directory Directory of the messages.
	 */
	private static function protect( string $directory ): void {
		$files = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.html' => '',
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( $directory . '/' . $name ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a stamp beside the messages, written once, the way the recorder writes its own.
				@file_put_contents( $directory . '/' . $name, $content );
			}
		}
	}
}
