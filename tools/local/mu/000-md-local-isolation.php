<?php
/**
 * Plugin Name: MD local isolation
 * Description: Keeps a local instance to itself: mail is written to files, WordPress's outbound HTTP may reach 127.0.0.1 (and the allowlist) only, pages may load resources from this machine only, and search engines are asked not to index.
 *
 * Copied into wp-content/mu-plugins by md-local (tools/local); never deployed.
 *
 * @package Md\Local
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// Where mail and refused requests go, defined in wp-config.php by md-local.
// Without them there is nowhere to put mail, so the site stops instead of sending any.
if ( ! defined( 'MD_LOCAL_MAIL_DIR' ) || ! defined( 'MD_LOCAL_ISOLATION_LOG' ) ) {
	throw new RuntimeException( 'MD_LOCAL_MAIL_DIR or MD_LOCAL_ISOLATION_LOG is not defined; this mu-plugin belongs to an instance made by md-local.' );
}

add_filter( 'pre_wp_mail', 'md_local_capture_mail', PHP_INT_MAX );
add_filter( 'wp_mail_from', 'md_local_mail_from' );
add_filter( 'pre_http_request', 'md_local_filter_http', PHP_INT_MAX, 3 );
add_action( 'requests-requests.before_redirect', 'md_local_filter_redirect' );

if ( 'cli' !== PHP_SAPI && ! headers_sent() ) {
	// The browser may fetch from this machine only (any port: the instance).
	$md_local_sources = "'self' http://127.0.0.1:* data: blob: 'unsafe-inline' 'unsafe-eval'";
	header( "Content-Security-Policy: default-src {$md_local_sources}; form-action 'self'" );
	header( 'X-Robots-Tag: noindex, nofollow' );
}

/**
 * Lets wp_mail() build the message as usual, then hands it a mailer that writes
 * the message to MD_LOCAL_MAIL_DIR/*.eml instead of sending it.
 *
 * @param null|bool $pre Short-circuit value from an earlier filter; null to send.
 * @return null|bool The value unchanged.
 */
function md_local_capture_mail( $pre ) {
	if ( null === $pre ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp_mail() sends through this global; replacing it is how mail stays local.
		$GLOBALS['phpmailer'] = md_local_mailer( MD_LOCAL_MAIL_DIR );
	}
	return $pre;
}

/**
 * WordPress's default sender is wordpress@<site host>. With the host 127.0.0.1
 * PHPMailer rejects it ("Invalid address: (From)") and every such mail fails
 * here while it is sent from a domain in production; .test is reserved and
 * never delivered (RFC 6761 §6.2).
 *
 * @param string $from Sender address.
 * @return string
 */
function md_local_mail_from( string $from ): string {
	return 'wordpress@127.0.0.1' === $from ? 'wordpress@example.test' : $from;
}

/**
 * A WordPress mailer whose sending step writes the finished message to a file:
 * the same bytes mail() would receive, with To, Subject, Cc and Bcc headers.
 *
 * @param string $directory Directory of the .eml files.
 * @return PHPMailer\PHPMailer\PHPMailer
 */
function md_local_mailer( string $directory ) {
	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
	require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';

	return new class( $directory ) extends WP_PHPMailer {

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
		 * Writes the message instead of sending it.
		 *
		 * @return bool True; a failure throws, as PHPMailer's own sending does
		 *              with exceptions on, so wp_mail() reports wp_mail_failed.
		 * @throws PHPMailer\PHPMailer\Exception When the file cannot be written.
		 */
		public function postSend(): bool {
			$file = sprintf( '%s/%s-%s.eml', $this->directory, gmdate( 'Ymd-His' ), wp_generate_uuid4() );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local file outside WP_Filesystem's scope.
			if ( false === file_put_contents( $file, $this->getSentMIMEMessage() ) ) {
				throw new PHPMailer\PHPMailer\Exception( esc_html( sprintf( 'Could not write %s.', $file ) ) );
			}
			return true;
		}
	};
}

/**
 * Lets WordPress's HTTP API reach 127.0.0.1 and the allowlisted hosts; anything
 * else is refused and written to the isolation log.
 *
 * @param false|array<string, mixed>|WP_Error $response Answer from an earlier filter, false to send.
 * @param array<string, mixed>                $args     Request arguments.
 * @param string                              $url      Request URL.
 * @return false|array<string, mixed>|WP_Error
 */
function md_local_filter_http( $response, array $args, string $url ) {
	if ( false !== $response || md_local_is_allowed( $url ) ) {
		return $response;
	}
	md_local_log_blocked( isset( $args['method'] ) && is_string( $args['method'] ) ? $args['method'] : 'GET', $url );
	return new WP_Error( 'md_local_blocked', sprintf( 'Blocked by the local isolation: %s', $url ) );
}

/**
 * Requests follows redirects itself, after pre_http_request has seen only the
 * first URL; a redirect from an allowed host to anywhere else is refused here.
 * WP_Http turns the exception into a WP_Error, as for its own redirect checks
 * (WP_Http::validate_redirects()).
 *
 * @param string $location Redirect target.
 * @throws WpOrg\Requests\Exception When the target is not allowed.
 */
function md_local_filter_redirect( string $location ): void {
	if ( ! md_local_is_allowed( $location ) ) {
		md_local_log_blocked( 'REDIRECT', $location );
		throw new WpOrg\Requests\Exception( esc_html( sprintf( 'Blocked by the local isolation: %s', $location ) ), 'md_local_blocked' );
	}
}

/**
 * Whether a URL may leave this machine: 127.0.0.1 always, plus the hosts of
 * MD_LOCAL_ALLOW_HOSTS, one per line.
 *
 * @param string $url URL.
 * @return bool
 */
function md_local_is_allowed( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) ) {
		return false;
	}
	if ( '127.0.0.1' === $host ) {
		return true;
	}
	static $allowed = null;
	if ( null === $allowed ) {
		$allowed = array();
		$file    = md_local_allow_hosts_file();
		if ( '' !== $file && is_readable( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file outside WP_Filesystem's scope; wp_remote_get() is exactly what this plugin forbids.
			$contents = (string) file_get_contents( $file );
			$lines    = preg_split( '/\R/u', $contents );
			foreach ( is_array( $lines ) ? $lines : array() as $line ) {
				$entry = strtolower( trim( (string) $line ) );
				if ( '' !== $entry && '#' !== $entry[0] ) {
					$allowed[ $entry ] = true;
				}
			}
		}
	}
	return isset( $allowed[ strtolower( $host ) ] );
}

/**
 * The egress allowlist file of this instance: one host per line, empty in
 * phase 1 (docs/specs/14-local-stack.md §1.4 keeps the phase-2 DeepSeek
 * translation API as the only planned entry). md-local defines
 * MD_LOCAL_ALLOW_HOSTS in wp-config.php; an instance built without it allows
 * the loopback only.
 *
 * @return string The file's path, or '' when none is configured.
 */
function md_local_allow_hosts_file(): string {
	return defined( 'MD_LOCAL_ALLOW_HOSTS' ) ? (string) constant( 'MD_LOCAL_ALLOW_HOSTS' ) : '';
}

/**
 * Appends a refused request to MD_LOCAL_ISOLATION_LOG.
 *
 * @param string $method HTTP method, or REDIRECT for a refused redirect.
 * @param string $url    URL.
 */
function md_local_log_blocked( string $method, string $url ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local log outside WP_Filesystem's scope.
	file_put_contents( MD_LOCAL_ISOLATION_LOG, sprintf( "%s\t%s\t%s\n", gmdate( 'c' ), $method, $url ), FILE_APPEND | LOCK_EX );
}
