<?php
/**
 * Plugin Name:       MetalDivider Site
 * Description:       The presentation layer of metal-divider.com: the LiveCanvas templates, the WindPress volume, present, and the pattern data shortcodes.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Author:            MetalDivider
 * Text Domain:       md-site
 *
 * The stack runs WordPress 7.1.3 on PHP 8.3 (docs/specs/14-local-stack.md §2.3,
 * branch research/spec-local-stack).
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

( new Plugin(
	new Fields\FieldGroups(),
	new Presentation\Shortcodes()
) )->register();
