<?php
/**
 * Plugin bootstrap.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site;

use MetalDivider\Site\Media\Sizes;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Fields\FieldGroups;
use MetalDivider\Site\Presentation\Assets;
use MetalDivider\Site\Presentation\PartialFields;
use MetalDivider\Site\Presentation\Partials;
use MetalDivider\Site\Presentation\Shortcodes;
use MetalDivider\Site\Seo\CatalogRobots;
use MetalDivider\Site\Seo\PageNoindex;
use MetalDivider\Site\Seo\SitemapDedupe;
use MetalDivider\Site\Seo\SchemaFilters;

/**
 * Hooks MetalDivider Site into WordPress.
 *
 * The presentation layer's assemblies: the ACF field groups of the
 * pattern data model (#27), the PDP data shortcodes, the card partials and
 * their value shortcodes, the LiveCanvas templates and WindPress volume
 * behind `wp md-site present` (#44), and the SEO plane — its configuration
 * command `wp md-site seo` and the schema filters SEOPress's builders cannot
 * express (#34).
 */
final class Plugin {

	/**
	 * Sets up the plugin.
	 *
	 * @param FieldGroups   $fields     The ACF field groups of the pattern data model.
	 * @param Shortcodes    $shortcodes The PDP data shortcodes.
	 * @param PartialFields $partial_fields The card partials' value shortcodes.
	 * @param Partials      $partials   The card partials and their renderer.
	 * @param SchemaFilters $schemas    The SEO schema filters (ticket #34).
	 * @param CatalogRobots $catalog_robots The catalog filter/search noindex rule.
	 * @param PageNoindex   $page_noindex   The page-level noindex + canonical rules.
	 * @param SitemapDedupe $sitemap_dedupe The shop page's duplicate sitemap entry.
	 * @param Assets        $assets     The front-end scripts (chrome runtime + Alpine).
	 * @param Catalog       $catalog    The pattern catalog's cards, pager and query inputs.
	 * @param Sizes         $media_sizes The 1:1 image sides the catalog card draws.
	 */
	public function __construct(
		private readonly FieldGroups $fields,
		private readonly Shortcodes $shortcodes,
		private readonly PartialFields $partial_fields,
		private readonly Partials $partials,
		private readonly SchemaFilters $schemas,
		private readonly CatalogRobots $catalog_robots,
		private readonly PageNoindex $page_noindex,
		private readonly SitemapDedupe $sitemap_dedupe,
		private readonly Assets $assets,
		private readonly Catalog $catalog,
		private readonly Sizes $media_sizes,
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
		// The card partials' value shortcodes and the partial renderer share
		// one instance (the scope a render pushes is the scope a value reads),
		// so both register on the objects the shared renderer holds.
		add_action( 'init', array( $this->partial_fields, 'register' ) );
		add_action( 'init', array( $this->partials, 'register' ) );
		// Author notes in the templates are HTML comments. WordPress expands
		// shortcodes inside them, and the notes themselves ship in the response.
		// The converter reads the repository files, not this response.
		( new Presentation\HtmlComments() )->register();
		$this->fields->register();
		$this->schemas->register();
		$this->catalog_robots->register();
		$this->page_noindex->register();
		$this->sitemap_dedupe->register();
		$this->assets->register();
		$this->catalog->register();
		$this->media_sizes->register();
		// SEOPress prints an unlayered `.breadcrumb li{display:inline-block}` +
		// ' - ' separator into wp_head; unlayered author CSS outranks the
		// WindPress volume layer and broke the replica's breadcrumb row. The
		// site renders its own breadcrumb, so the generic stylesheet goes.
		add_filter( 'seopress_pro_breadcrumbs_css', '__return_empty_string' );
		add_action( 'cli_init', array( $this, 'register_cli' ) );
	}

	/**
	 * The presentation installer.
	 */
	public function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		// One namespace, six command objects: WP-CLI merges each object's
		// annotated methods into the shared md-site tree, so the surface
		// stays wp md-site present / seo / seed-series / seed-tiles /
		// retarget-thumbnails / windpress-anchors.
		\WP_CLI::add_command( 'md-site', new Cli\PresentCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\SeoCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\CatalogCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\TileOrderCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\ThumbnailCommand() );
		\WP_CLI::add_command( 'md-site', new Cli\WindPressAnchorsCommand() );
	}
}
