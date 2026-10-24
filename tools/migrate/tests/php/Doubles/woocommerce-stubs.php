<?php
/**
 * Static-analysis stubs for the WooCommerce surface 18-wordpress.php
 * touches: wc_get_product, wc_get_product_id_by_sku and the WC_Product the
 * read/write closures walk are neither in the WordPress stubs nor on
 * Packagist. At runtime the real classes and functions exist wherever
 * WooCommerce is active, and the test runtime defines its own doubles in
 * tests/php/Doubles/wordpress-fakes.php and tests/php/Support/fakes.php.
 * phpstan reads this file via scanFiles; the test bootstrap never loads it,
 * and PHPStan does not analyse it (phpstan.neon.dist excludes the doubles).
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis; the
// WordPress prefix and naming sniffs do not apply to WooCommerce's own names.

namespace {
	/**
	 * WooCommerce's product, with only the members this driver calls.
	 */
	class WC_Product {

		/**
		 * The product's id.
		 *
		 * @return int The post id.
		 */
		public function get_id(): int {
			return 0;
		}

		/**
		 * The product's name.
		 *
		 * @return string The name.
		 */
		public function get_name(): string {
			return '';
		}

		/**
		 * The product's slug.
		 *
		 * @return string The slug.
		 */
		public function get_slug(): string {
			return '';
		}

		/**
		 * The product's status.
		 *
		 * @return string The status.
		 */
		public function get_status(): string {
			return '';
		}

		/**
		 * The product's description.
		 *
		 * @return string The description.
		 */
		public function get_description(): string {
			return '';
		}

		/**
		 * The product's short description.
		 *
		 * @return string The short description.
		 */
		public function get_short_description(): string {
			return '';
		}

		/**
		 * Whether the product is virtual.
		 *
		 * @return bool Virtual or not.
		 */
		public function get_virtual(): bool {
			return false;
		}

		/**
		 * The product's stock status.
		 *
		 * @return string The status.
		 */
		public function get_stock_status(): string {
			return '';
		}

		/**
		 * Whether stock is managed.
		 *
		 * @return bool Managed or not.
		 */
		public function get_manage_stock(): bool {
			return false;
		}

		/**
		 * The product's regular price.
		 *
		 * @return string The price ('' when empty).
		 */
		public function get_regular_price(): string {
			return '';
		}

		/**
		 * The product's catalog visibility.
		 *
		 * @return string The visibility.
		 */
		public function get_catalog_visibility(): string {
			return '';
		}

		/**
		 * The product's tax status.
		 *
		 * @return string The status.
		 */
		public function get_tax_status(): string {
			return '';
		}

		/**
		 * Whether reviews are allowed.
		 *
		 * @return bool Allowed or not.
		 */
		public function get_reviews_allowed(): bool {
			return false;
		}

		/**
		 * Whether the product is purchasable.
		 *
		 * @return bool Purchasable or not.
		 */
		public function is_purchasable(): bool {
			return false;
		}

		/**
		 * Sets the SKU.
		 *
		 * @param string $sku The SKU.
		 * @return void
		 */
		public function set_sku( string $sku ): void {}

		/**
		 * Sets the name.
		 *
		 * @param string $name The name.
		 * @return void
		 */
		public function set_name( string $name ): void {}

		/**
		 * Sets the slug.
		 *
		 * @param string $slug The slug.
		 * @return void
		 */
		public function set_slug( string $slug ): void {}

		/**
		 * Sets the status.
		 *
		 * @param string $status The status.
		 * @return void
		 */
		public function set_status( string $status ): void {}

		/**
		 * Sets the description.
		 *
		 * @param string $description The description.
		 * @return void
		 */
		public function set_description( string $description ): void {}

		/**
		 * Sets the short description.
		 *
		 * @param string $description The short description.
		 * @return void
		 */
		public function set_short_description( string $description ): void {}

		/**
		 * Sets the category ids.
		 *
		 * @param array<int, int> $ids The term ids.
		 * @return void
		 */
		public function set_category_ids( array $ids ): void {}

		/**
		 * Sets virtual.
		 *
		 * @param bool $virtual Virtual or not.
		 * @return void
		 */
		public function set_virtual( bool $virtual ): void {}

		/**
		 * Sets the stock status.
		 *
		 * @param string $status The status.
		 * @return void
		 */
		public function set_stock_status( string $status ): void {}

		/**
		 * Sets stock management.
		 *
		 * @param bool $manage Managed or not.
		 * @return void
		 */
		public function set_manage_stock( bool $manage ): void {}

		/**
		 * Sets the regular price.
		 *
		 * @param string $price The price.
		 * @return void
		 */
		public function set_regular_price( string $price ): void {}

		/**
		 * Sets the sale price.
		 *
		 * @param string $price The price.
		 * @return void
		 */
		public function set_sale_price( string $price ): void {}

		/**
		 * Sets the active price.
		 *
		 * @param string $price The price.
		 * @return void
		 */
		public function set_price( string $price ): void {}

		/**
		 * Sets the catalog visibility.
		 *
		 * @param string $visibility The visibility.
		 * @return void
		 */
		public function set_catalog_visibility( string $visibility ): void {}

		/**
		 * Sets the tax status.
		 *
		 * @param string $status The status.
		 * @return void
		 */
		public function set_tax_status( string $status ): void {}

		/**
		 * Sets whether reviews are allowed.
		 *
		 * @param bool $allowed Allowed or not.
		 * @return void
		 */
		public function set_reviews_allowed( bool $allowed ): void {}

		/**
		 * Sets the featured image.
		 *
		 * @param int $id The attachment id.
		 * @return void
		 */
		public function set_image_id( int $id ): void {}

		/**
		 * Sets the gallery images.
		 *
		 * @param array<int, int> $ids The attachment ids.
		 * @return void
		 */
		public function set_gallery_image_ids( array $ids ): void {}

		/**
		 * Saves the product.
		 *
		 * @return int The product id.
		 */
		public function save(): int {
			return 0;
		}
	}

	/**
	 * The simple product the create path instantiates.
	 */
	class WC_Product_Simple extends WC_Product {}

	/**
	 * WooCommerce's product getter.
	 *
	 * @param int|object|false $the_product The product id or object.
	 * @return WC_Product|false The product, false when absent.
	 */
	function wc_get_product( $the_product = false ) {
		return false;
	}

	/**
	 * The SKU lookup of the write paths.
	 *
	 * @param string $sku The SKU.
	 * @return int The product id, 0 when absent.
	 */
	function wc_get_product_id_by_sku( $sku ) {
		return 0;
	}
}
