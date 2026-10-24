<?php
/**
 * The inquiry chain's configuration.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Every knob the chain reads: the Turnstile keys, the notification routing,
 * the sender address and the test mode.
 *
 * Each value resolves in a fixed order — the injected overrides (tests, and
 * nothing else), then the wp-config constants (the deployment's way: secrets
 * reach the instance through wp-config.php and never through the repository,
 * AGENTS.md §4), then the options the settings page stores (the manual
 * fallback while a constant is not in place yet), then the default.
 */
final class Config {

	/**
	 * The sender of every outbound mail (spec 07 §5, branch
	 * research/spec-inquiry-chain: From = info@metal-divider.com, the
	 * site-domain-aligned address; switchable to a same-domain address by the
	 * constant alone).
	 */
	public const FROM_EMAIL = 'info@metal-divider.com';

	/**
	 * The internal notification's primary recipient.
	 */
	public const INTERNAL_PRIMARY = 'sales@sunriverinverter.com';

	/**
	 * The internal notification's backup recipient.
	 */
	public const INTERNAL_BACKUP = 'info@metal-divider.com';

	/**
	 * Notification routes: both recipients, the primary alone, the backup alone.
	 */
	public const ROUTE_BOTH    = 'both';
	public const ROUTE_PRIMARY = 'primary';
	public const ROUTE_BACKUP  = 'backup';

	/**
	 * The injected values, standing in for the wp-config constants in tests.
	 *
	 * @var array<string, mixed>
	 */
	private array $overrides;

	/**
	 * Sets up the configuration reader.
	 *
	 * @param array<string, mixed> $overrides Constant-shaped values for tests.
	 */
	public function __construct( array $overrides = array() ) {
		$this->overrides = $overrides;
	}

	/**
	 * One value, through the whole resolution order. An empty value anywhere
	 * means "not configured", so the default answers — a cleared settings
	 * field must never become a half-configured chain.
	 *
	 * @param string $constant The wp-config constant's name.
	 * @param string $option   The option the settings page stores.
	 * @param mixed  $fallback The value when nothing configures it.
	 * @return mixed
	 */
	private function value( string $constant, string $option, mixed $fallback ): mixed {
		if ( \array_key_exists( $constant, $this->overrides ) ) {
			$value = $this->overrides[ $constant ];
		} elseif ( \defined( $constant ) ) {
			$value = \constant( $constant );
		} else {
			$value = \get_option( $option, $fallback );
		}
		return ( null === $value || '' === $value ) ? $fallback : $value;
	}

	/**
	 * The Turnstile sitekey, as the widget's markup needs it.
	 *
	 * @return string
	 */
	public function turnstile_sitekey(): string {
		return self::string( $this->value( 'MD_INQUIRY_TURNSTILE_SITEKEY', 'md_inquiry_turnstile_sitekey', '' ) );
	}

	/**
	 * The Turnstile secret, as siteverify needs it.
	 *
	 * @return string
	 */
	public function turnstile_secret(): string {
		return self::string( $this->value( 'MD_INQUIRY_TURNSTILE_SECRET', 'md_inquiry_turnstile_secret', '' ) );
	}

	/**
	 * Whether both Turnstile keys are in place: the moment they are, every
	 * submission must pass siteverify (fail-closed, spec 07 §8); before that,
	 * the form stays open and the record is marked instead (the deployment
	 * pipeline's local and staging phases carry no keys).
	 */
	public function turnstile_is_configured(): bool {
		return '' !== $this->turnstile_sitekey() && '' !== $this->turnstile_secret();
	}

	/**
	 * Whether the sitekey comes from a wp-config constant: the settings page
	 * shows the input as disabled then, because it would never be read.
	 */
	public function turnstile_sitekey_is_constant(): bool {
		return \array_key_exists( 'MD_INQUIRY_TURNSTILE_SITEKEY', $this->overrides ) || \defined( 'MD_INQUIRY_TURNSTILE_SITEKEY' );
	}

	/**
	 * Whether the secret comes from a wp-config constant.
	 */
	public function turnstile_secret_is_constant(): bool {
		return \array_key_exists( 'MD_INQUIRY_TURNSTILE_SECRET', $this->overrides ) || \defined( 'MD_INQUIRY_TURNSTILE_SECRET' );
	}

	/**
	 * The sender address of every outbound mail.
	 *
	 * @return string
	 */
	public function from_email(): string {
		return self::string( $this->value( 'MD_INQUIRY_FROM_EMAIL', 'md_inquiry_from_email', self::FROM_EMAIL ) );
	}

	/**
	 * The internal notification's route: which of the two recipients receive it.
	 *
	 * @return string One of the ROUTE_ constants.
	 */
	public function notification_route(): string {
		$route = self::string( $this->value( 'MD_INQUIRY_NOTIFICATION_ROUTE', 'md_inquiry_notification_route', self::ROUTE_BOTH ) );
		return \in_array( $route, array( self::ROUTE_BOTH, self::ROUTE_PRIMARY, self::ROUTE_BACKUP ), true ) ? $route : self::ROUTE_BOTH;
	}

	/**
	 * The internal notification's recipients, in order (the primary first).
	 *
	 * @return list<string>
	 */
	public function internal_recipients(): array {
		return match ( $this->notification_route() ) {
			self::ROUTE_PRIMARY => array( self::INTERNAL_PRIMARY ),
			self::ROUTE_BACKUP  => array( self::INTERNAL_BACKUP ),
			default             => array( self::INTERNAL_PRIMARY, self::INTERNAL_BACKUP ),
		};
	}

	/**
	 * The one address every outbound mail is rewritten to in test mode, or ''
	 * when test mode is off. The AGENTS.md §5 discipline: a test sends one
	 * mail, to the designated address only; the true routing is asserted from
	 * the queue's recipient data instead.
	 *
	 * @return string
	 */
	public function test_recipient(): string {
		$recipient = self::string( $this->value( 'MD_INQUIRY_TEST_RECIPIENT', 'md_inquiry_test_recipient', '' ) );
		return \is_email( $recipient ) ? $recipient : '';
	}

	/**
	 * Whether test mode is on: a recipient is configured for it.
	 */
	public function is_test_mode(): bool {
		return '' !== $this->test_recipient();
	}

	/**
	 * Whether the test recipient comes from a wp-config constant (WP-CLI can
	 * only ever switch the option, never the constant).
	 */
	public function test_recipient_is_constant(): bool {
		return \array_key_exists( 'MD_INQUIRY_TEST_RECIPIENT', $this->overrides ) || \defined( 'MD_INQUIRY_TEST_RECIPIENT' );
	}

	/**
	 * Casts a configured value to the string shape every consumer needs.
	 *
	 * @param mixed $value The configured value.
	 * @return string
	 */
	private static function string( mixed $value ): string {
		return \is_string( $value ) ? \trim( $value ) : '';
	}
}
