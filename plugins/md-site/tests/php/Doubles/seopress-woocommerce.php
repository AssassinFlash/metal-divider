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
	 * The double of WooCommerce's product getter: the gallery product
	 * the Product-schema test installs in $GLOBALS.
	 *
	 * @param int|object $id The product id (unused).
	 * @return object|false The product double.
	 */
	function wc_get_product( $id = 0 ) {
		return $GLOBALS['md_wc_product_double'] ?? false;
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
		 * Sets the gallery ids.
		 *
		 * @param int[] $gallery The gallery attachment ids.
		 */
		public function __construct( array $gallery ) {
			$this->gallery = $gallery;
		}

		/**
		 * The gallery attachment ids.
		 *
		 * @return int[]
		 */
		public function get_gallery_image_ids(): array {
			return $this->gallery;
		}
	}
}
