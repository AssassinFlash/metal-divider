<?php
/**
 * Plugin Name:       MetalDivider Site
 * Description:       The presentation layer of metal-divider.com: the LiveCanvas templates, the WindPress volume, present, the pattern data shortcodes, and the SEO configuration plane.
 * Version:           0.2.0
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
require_once __DIR__ . '/includes/Presentation/template-functions.php';

( new Plugin(
	new Fields\FieldGroups(),
	new Presentation\Shortcodes(),
	new Seo\SchemaFilters(),
	new Presentation\Assets(),
	new Catalog()
) )->register();
