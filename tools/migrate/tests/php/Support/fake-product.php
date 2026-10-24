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
