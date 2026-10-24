<?php
/**
 * Plugin bootstrap.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site;

use MetalDivider\Site\Fields\FieldGroups;
use MetalDivider\Site\Presentation\Shortcodes;
use MetalDivider\Site\Seo\SchemaFilters;

/**
 * Hooks MetalDivider Site into WordPress.
 *
 * The skeleton of the presentation layer (ticket #27): the ACF field
 * groups of the pattern data model, the two zero-design PDP
 * shortcodes, and the `wp md-site present` installer. The LiveCanvas
 * templates, the full WindPress volume, the compile providers and the
 * SEO plane's schema filters land with their own tickets.
 */
final class Plugin {

	/**
	 * Sets up the plugin.
	 *
	 * @param FieldGroups   $fields     The ACF field groups of the pattern data model.
	 * @param Shortcodes    $shortcodes The PDP data shortcodes.
	 * @param SchemaFilters $schemas    The SEO schema filters (ticket #34).
	 */
	public function __construct(
		private readonly FieldGroups $fields,
		private readonly Shortcodes $shortcodes,
		private readonly SchemaFilters $schemas,
	) {}

	/**
	 * Adds the plugin's hooks.
	 */
	public function register(): void {
		// Shortcodes register on init (the earliest hook WordPress documents
		// for add_shortcode); the ACF groups register on ACF's own acf/init,
		// which fires only when ACF is active — without it there is nothing
		// to register against and no error to make. The schema filters are
		// the same: every callback guards on the SEOPress service it reads.
		add_action( 'init', array( $this->shortcodes, 'register' ) );
		$this->fields->register();
		$this->schemas->register();
		add_action( 'cli_init', array( $this, 'register_cli' ) );
	}

	/**
	 * The presentation installer.
	 */
	public function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		// One namespace, two command objects: WP-CLI merges each object's
		// annotated methods into the shared md-site tree, so the surface
		// stays wp md-site present / wp md-site seo.
		\WP_CLI::add_command( 'md-site', new Cli\PresentCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\SeoCommand() );
	}
}
