<?php
/**
 * Runtime doubles for the third-party functions the schema filters
 * probe: SEOPress's service locator and WooCommerce's product getter.
 * Neither plugin is loaded under WP_Mock, and the filters guard on
 * function_exists(), so the tests that need the guarded paths define
 * the functions here, in the global namespace.
 *
 * @package MetalDivider\Site
 */

// phpcs:disable -- runtime doubles; the WordPress naming sniffs do not
// apply to the third-party names they carry.

if ( ! function_exists( 'seopress_get_service' ) ) {
	/**
	 * The double of SEOPress's service locator: the registry the
	 * SchemaFilters tests fill (null for a service nobody registered,
	 * the way the real locator answers).
	 *
	 * @param string $service The service name.
	 * @return object|null The service.
	 */
	function seopress_get_service( string $service ) {
		return \MetalDivider\Site\Tests\Unit\Seo\SchemaFiltersTest::$services[ $service ] ?? null;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * The double of WooCommerce's product getter: the gallery product the
	 * fixture installs in $GLOBALS.
	 *
	 * A product is bound to one catalog row, so the double answers per id:
	 * `md_wc_products` maps ids to their doubles (the tests that render
	 * several products in one process), and `md_wc_product_double` is the
	 * single-product fixture every other test uses. Falling back keeps the
	 * existing fixtures working unchanged.
	 *
	 * @param int|object $id The product id.
	 * @return object|false The product double.
	 */
	function wc_get_product( $id = 0 ) {
		if ( is_numeric( $id ) && isset( $GLOBALS['md_wc_products'][ (int) $id ] ) ) {
			return $GLOBALS['md_wc_products'][ (int) $id ];
		}
		return $GLOBALS['md_wc_product_double'] ?? false;
	}
}

if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	/**
	 * The double of WooCommerce's SKU lookup: the id the command tests
	 * install in $GLOBALS, answered the way the real function answers (0 for
	 * a SKU the catalog does not carry).
	 *
	 * @param string $sku The pattern code.
	 * @return int The product id, 0 when absent.
	 */
	function wc_get_product_id_by_sku( $sku ) {
		$map = \MetalDivider\Site\Tests\Unit\Cli\TileOrderCommandTest::$skus ?? array();
		return (int) ( $map[ $sku ] ?? 0 );
	}
}

if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
	/**
	 * The double of WooCommerce's visibility term lookup: the ids the tests
	 * install in $GLOBALS, defaulting to the four terms a live store carries.
	 * The production code guards on function_exists(), so this declaration is
	 * what lets the catalog-visibility paths run under WP_Mock at all.
	 *
	 * @return array<string, int> Term ids by visibility name.
	 */
	function wc_get_product_visibility_term_ids() {
		return $GLOBALS['md_wc_visibility_double'] ?? array(
			'exclude-from-catalog' => 7,
			'exclude-from-search'  => 6,
			'featured'             => 8,
			'outofstock'           => 9,
		);
	}
}

if ( ! class_exists( 'FakeGalleryProduct' ) ) {
	/**
	 * A WooCommerce product double with a gallery: the ids the shortcode
	 * fixtures declare. A real class, not a closure property — the
	 * shortcode guards with method_exists(), which a property would fail.
	 */
	final class FakeGalleryProduct {

		/**
		 * The gallery attachment ids.
		 *
		 * @var int[]
		 */
		private array $gallery;

		/**
		 * Called on every gallery read, when a test counts them.
		 *
		 * @var callable|null
		 */
		private $on_read;

		/**
		 * Sets the gallery ids.
		 *
		 * @param int[]         $gallery The gallery attachment ids.
		 * @param callable|null $on_read Called on every get_gallery_image_ids(), so a
		 *                              test can prove the read happened once.
		 */
		public function __construct( array $gallery, ?callable $on_read = null ) {
			$this->gallery = $gallery;
			$this->on_read = $on_read;
		}

		/**
		 * The gallery attachment ids.
		 *
		 * @return int[]
		 */
		public function get_gallery_image_ids(): array {
			if ( null !== $this->on_read ) {
				( $this->on_read )();
			}
			return $this->gallery;
		}
	}
}
