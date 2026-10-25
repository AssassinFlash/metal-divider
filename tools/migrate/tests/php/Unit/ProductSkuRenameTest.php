<?php
/**
 * The products/write SKU rename: a product whose row still carries the
 * source's pattern code (the plan's SKU is that code with the superseded
 * material word dropped) is renamed in place — never created, never looked
 * up by the plan's SKU alone — and the source code is kept as provenance.
 * The read side is what makes the rename visible: the driver resolves a
 * product by the SKU its row actually holds, so the plan's diff can see the
 * drift instead of a missing product.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

namespace Md\Migrate\Tests\Unit;

use MD_Migrate_Fakes;
use PHPUnit\Framework\TestCase;

/**
 * The products stage's rename contract against the driver's real request
 * shape, evaluated outside WordPress exactly as `wp eval-file` evaluates it.
 */
final class ProductSkuRenameTest extends TestCase {

	/**
	 * A fresh recording state per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		MD_Migrate_Fakes::reset( (string) sys_get_temp_dir() . '/md-migrate-sku' );
	}

	/**
	 * A row carrying the source's code is renamed to the plan's SKU, and the
	 * source code lands as provenance — one write, no create.
	 *
	 * @return void
	 */
	public function test_a_row_with_the_source_code_is_renamed_in_place(): void {
		MD_Migrate_Fakes::$product_sku = 'Aztec Corten';
		MD_Migrate_Fakes::$product_id  = 51;

		$answer = md_migrate_products_write(
			array(
				'sku'  => 'Aztec',
				'id'   => 51,
				'item' => array( 'source_code' => 'Aztec Corten' ),
				'set'  => array( 'sku' => 'Aztec' ),
			)
		);

		$this->assertSame( array( 'Aztec' => 'updated' ), $answer['results'] );
		$this->assertSame( array(), $answer['errors'] );
		$this->assertSame( 'Aztec', MD_Migrate_Fakes::$product_sku, 'the SKU is renamed' );
		$this->assertSame(
			'Aztec Corten',
			MD_Migrate_Fakes::meta( 51, '_md_source_code' ),
			'the source code stays traceable'
		);
		$this->assertSame( 1, MD_Migrate_Fakes::count( 'product_set_sku' ) );
	}

	/**
	 * A row already at the plan's SKU is not renamed again: the write is
	 * idempotent, which is what keeps a second apply at zero writes.
	 *
	 * @return void
	 */
	public function test_a_row_already_at_the_plan_sku_is_left_alone(): void {
		MD_Migrate_Fakes::$product_sku = 'Aztec';
		MD_Migrate_Fakes::$product_id  = 51;

		$answer = md_migrate_products_write(
			array(
				'sku'  => 'Aztec',
				'id'   => 51,
				'item' => array( 'source_code' => 'Aztec Corten' ),
				'set'  => array( 'sku' => 'Aztec' ),
			)
		);

		$this->assertSame( array( 'Aztec' => 'updated' ), $answer['results'] );
		$this->assertSame( 0, MD_Migrate_Fakes::count( 'product_set_sku' ), 'no second rename' );
		// The provenance still lands: it is written once, not on every apply.
		$this->assertSame( 'Aztec Corten', MD_Migrate_Fakes::meta( 51, '_md_source_code' ) );
	}
}
