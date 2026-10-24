<?php
/**
 * Plugin bootstrap.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

use MetalDivider\Inquiry\Admin\Columns;
use MetalDivider\Inquiry\Admin\SettingsPage;
use MetalDivider\Inquiry\Fields\FieldGroup;
use MetalDivider\Inquiry\Mail\Emails;
use MetalDivider\Inquiry\Mail\Queue;
use MetalDivider\Inquiry\Mail\QueueCli;

/**
 * Hooks MetalDivider Inquiry into WordPress.
 *
 * The inquiry chain (spec 07, branch research/spec-inquiry-chain, as revised
 * by ticket #7: PDP-initiated, single-pattern, no list): the record's post
 * type and workflow statuses, the ACF field group that presents it, the form
 * and its POST handler, the Turnstile gate, the WhatsApp builders, the two
 * mails behind the outbound queue, the REST anchor and the back-office
 * surfaces (the list's columns, the settings page, the WP-CLI doors).
 */
final class Plugin {

	/**
	 * The plugin's version, the asset version included.
	 */
	public const VERSION = '0.2.0';

	/**
	 * Sets up the plugin.
	 *
	 * @param PostType     $post_type   The inquiry system-of-record post type.
	 * @param Statuses     $statuses    The record's workflow statuses.
	 * @param FieldGroup   $fields      The record's ACF field group.
	 * @param Columns      $columns     The inquiries list's columns.
	 * @param SettingsPage $settings    The settings page.
	 * @param Form         $form        The form's output.
	 * @param Submission   $submission  The form's POST handler.
	 * @param WhatsApp     $whatsapp    The WhatsApp builders.
	 * @param ThankYou     $thank_you   The thank-you page's helpers.
	 * @param RestGuard    $rest_guard  The REST 404 anchor.
	 * @param Cli          $cli         The test-mode WP-CLI command.
	 * @param QueueCli     $queue_cli   The queue's WP-CLI commands.
	 */
	public function __construct(
		private readonly PostType $post_type,
		private readonly Statuses $statuses,
		private readonly FieldGroup $fields,
		private readonly Columns $columns,
		private readonly SettingsPage $settings,
		private readonly Form $form,
		private readonly Submission $submission,
		private readonly WhatsApp $whatsapp,
		private readonly ThankYou $thank_you,
		private readonly RestGuard $rest_guard,
		private readonly Cli $cli,
		private readonly QueueCli $queue_cli,
	) {}

	/**
	 * Adds the plugin's hooks.
	 */
	public function register(): void {
		$this->post_type->register();
		$this->statuses->register();
		$this->fields->register();
		$this->columns->register();
		$this->settings->register();
		$this->form->register();
		$this->submission->register();
		$this->whatsapp->register();
		$this->thank_you->register();
		$this->rest_guard->register();
		$this->cli->register();
		$this->queue_cli->register();
	}
}
