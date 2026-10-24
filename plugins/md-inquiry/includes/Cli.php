<?php
/**
 * The md-inquiry WP-CLI commands.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * `wp md-inquiry test-mode`: the test mail discipline's switch — set it to
 * one address, and every dispatch is rewritten there; unset it, and the
 * chain sends for real. The WP-CLI form manages the option; a wp-config
 * constant owns the value when one is set, and the command says so instead
 * of pretending the switch moved.
 */
final class Cli {

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the command.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Hooks the command in, under WP-CLI only. The subcommand registers by
	 * its full name so the command line spells it hyphenated — the method
	 * name keeps PHP's own spelling.
	 */
	public function register(): void {
		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'md-inquiry test-mode', array( $this, 'test_mode' ) );
		}
	}

	/**
	 * Shows, sets or clears the test mode recipient.
	 *
	 * ## OPTIONS
	 *
	 * [<email>]
	 * : The one address every outbound mail is rewritten to in test mode.
	 *
	 * [--off]
	 * : Clear the test recipient and leave test mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp md-inquiry test-mode someone@example.com
	 *     wp md-inquiry test-mode --off
	 *     wp md-inquiry test-mode
	 *
	 * @param array<int, string>    $args       The positional arguments.
	 * @param array<string, string> $assoc_args The flags.
	 * @return void
	 */
	public function test_mode( array $args, array $assoc_args ): void {
		$off = isset( $assoc_args['off'] );
		if ( $off ) {
			\delete_option( 'md_inquiry_test_recipient' );
			\WP_CLI::log( 'Test recipient option cleared.' );
			$this->state();
			return;
		}
		if ( isset( $args[0] ) ) {
			$email = \trim( $args[0] );
			if ( false === \is_email( $email ) ) {
				\WP_CLI::error( 'That is not a valid email address.' );
			}
			\update_option( 'md_inquiry_test_recipient', $email );
			\WP_CLI::log( 'Test recipient option set.' );
			$this->state();
			return;
		}
		$this->state();
	}

	/**
	 * Prints the effective state, constant override included.
	 */
	private function state(): void {
		if ( ! $this->config->is_test_mode() ) {
			\WP_CLI::log( 'Test mode: off' );
			return;
		}
		\WP_CLI::log( 'Test mode: on — every dispatch goes to ' . $this->config->test_recipient() . ', one mail per queue tick.' );
		if ( $this->config->test_recipient_is_constant() ) {
			\WP_CLI::log( 'The recipient comes from a wp-config constant (MD_INQUIRY_TEST_RECIPIENT); the option cannot change it.' );
		} else {
			\WP_CLI::log( 'The recipient comes from the option; "wp md-inquiry test-mode --off" clears it.' );
		}
	}
}
