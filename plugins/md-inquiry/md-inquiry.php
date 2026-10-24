<?php
/**
 * Plugin Name:       MetalDivider Inquiry
 * Description:       The inquiry chain of metal-divider.com: the form, the notifications, the WhatsApp route, and the outbound queue.
 * Version:           0.2.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            MetalDivider
 * Text Domain:       md-inquiry
 *
 * The stack runs WordPress 7.1.3 on PHP 8.3 (docs/specs/14-local-stack.md §2.3,
 * branch research/spec-local-stack).
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Wires the chain together and hooks it into WordPress: the composition
 * root, kept inside a function so the instances it builds are its own and
 * never leak into the global namespace.
 */
function boot(): void {
	$config  = new Config();
	$state   = new FormState();
	$pattern = new Pattern();
	$queue   = new Mail\Queue( $config );

	( new Plugin(
		new PostType(),
		new Statuses(),
		new Fields\FieldGroup(),
		new Admin\Columns(),
		new Admin\SettingsPage( $config, $queue ),
		new Form( $config, $pattern, $state ),
		new Submission(
			new Validator(),
			new Turnstile( $config ),
			$pattern,
			$queue,
			new Mail\Emails( $config ),
			$state
		),
		new WhatsApp(),
		new ThankYou(),
		new RestGuard(),
		new Cli( $config ),
		new Mail\QueueCli( $queue )
	) )->register();
}

boot();

/**
 * Activation: the queue's table and cron entry exist from the first moment.
 */
\register_activation_hook(
	__FILE__,
	static function (): void {
		$config = new Config();
		( new Mail\Queue( $config ) )->ensure_schema();
		if ( ! \wp_next_scheduled( Mail\Queue::CRON_HOOK ) ) {
			\wp_schedule_event( \time() + 60, Mail\Queue::CRON_SCHEDULE, Mail\Queue::CRON_HOOK );
		}
	}
);

/**
 * Deactivation: the cron entry goes; the table and its rows stay, records of
 * real inquiries that no plugin's off state may take with it.
 */
\register_deactivation_hook(
	__FILE__,
	static function (): void {
		\wp_clear_scheduled_hook( Mail\Queue::CRON_HOOK );
	}
);
