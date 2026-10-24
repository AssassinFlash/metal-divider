<?php
/**
 * Plugin bootstrap.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Hooks MetalDivider Inquiry into WordPress.
 *
 * The skeleton of the inquiry chain (ticket #27): the system-of-record post
 * type an inquiry lands in. The form, the notifications, the WhatsApp route
 * and the outbound queue land with the chain's own tickets (spec 07,
 * branch research/spec-inquiry-chain).
 */
final class Plugin {

	/**
	 * Sets up the plugin.
	 *
	 * @param PostType $post_type The inquiry system-of-record post type.
	 */
	public function __construct(
		private readonly PostType $post_type,
	) {}

	/**
	 * Adds the plugin's hooks.
	 */
	public function register(): void {
		$this->post_type->register();
	}
}
