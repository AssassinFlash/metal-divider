<?php
/**
 * The WooCommerce product the doubles hand back: it only records.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

/**
 * The recording product of the wc_get_product double.
 */
final class MD_Migrate_Fake_Product extends WC_Product {

	/**
	 * The SKU this product carries (the read the rename path makes).
	 *
	 * @return string The configured SKU.
	 */
	public function get_sku(): string {
		return MD_Migrate_Fakes::$product_sku;
	}

	/**
	 * The product id the write path records its meta against.
	 *
	 * @return int The configured product id.
	 */
	public function get_id(): int {
		return MD_Migrate_Fakes::$product_id;
	}

	/**
	 * Records the SKU rename and states the new value the way the real
	 * setter does (a later get_sku answers with it).
	 *
	 * @param string $sku The SKU.
	 * @return void
	 */
	public function set_sku( string $sku ): void {
		MD_Migrate_Fakes::called( 'product_set_sku', $sku );
		MD_Migrate_Fakes::$product_sku = $sku;
	}

	/**
	 * Records set_image_id.
	 *
	 * @param int $id The attachment id.
	 * @return void
	 */
	public function set_image_id( int $id ): void {
		MD_Migrate_Fakes::called( 'product_set_image_id', $id );
	}

	/**
	 * Records set_gallery_image_ids.
	 *
	 * @param array<int, int> $ids The attachment ids.
	 * @return void
	 */
	public function set_gallery_image_ids( array $ids ): void {
		MD_Migrate_Fakes::called( 'product_set_gallery_image_ids', $ids );
	}

	/**
	 * Records save.
	 *
	 * @return int The unchanged product id.
	 */
	public function save(): int {
		MD_Migrate_Fakes::called( 'product_save' );
		return 5;
	}
}
