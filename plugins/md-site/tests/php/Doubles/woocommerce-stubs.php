<?php
/**
 * Static-analysis stubs for the WooCommerce surface this plugin touches:
 * wc_get_product is neither in the WordPress stubs nor on Packagist. At
 * runtime the real function exists wherever WooCommerce is active, and the
 * test runtime defines its own double in the SchemaFilters tests. phpstan
 * reads this file via scanFiles; the test bootstrap never loads it. Only
 * the surface this plugin touches is declared.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis; the
// WordPress prefix and naming sniffs do not apply to WooCommerce's own names.

namespace {
	/**
	 * WooCommerce's product getter. The return stays a bare object on
	 * purpose: both callers guard with is_object() + method_exists()
	 * before reading the gallery, and a typed interface would make the
	 * guard provably dead in one of them.
	 *
	 * @param int|object $the_product The product id or object.
	 * @return object|false The product, false when absent.
	 */
	function wc_get_product( $the_product = false ) {}

	/**
	 * The shape of the runtime product double (tests/php/Doubles/
	 * seopress-woocommerce.php, which the test bootstrap loads): the
	 * shortcode tests build it with their media fixture's gallery ids.
	 * Declared here because PHPStan excludes the runtime Doubles from
	 * analysis and scans this file for the symbols instead.
	 */
	final class FakeGalleryProduct {

		/**
		 * The gallery attachment ids.
		 *
		 * @param int[] $gallery The gallery attachment ids.
		 */
		public function __construct( array $gallery ) {}

		/**
		 * The gallery attachment ids.
		 *
		 * @return int[]
		 */
		public function get_gallery_image_ids(): array {}
	}
}
