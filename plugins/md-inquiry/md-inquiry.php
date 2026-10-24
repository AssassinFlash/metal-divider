<?php
/**
 * Plugin Name:       MetalDivider Inquiry
 * Description:       The inquiry chain of metal-divider.com: the form, the notifications, the WhatsApp route, and the outbound queue.
 * Version:           0.1.0
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

( new Plugin(
	new PostType()
) )->register();
