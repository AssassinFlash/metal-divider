<?php
/**
 * Tests for the LiveCanvas settings gate.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\LiveCanvasOptions;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for LiveCanvasOptions: the merge that enables dynamic
 * templating and the chrome partial hooks without touching the
 * operator's other settings.
 *
 * @covers \MetalDivider\Site\Presentation\LiveCanvasOptions
 */
final class LiveCanvasOptionsTest extends TestCase {

	/**
	 * Enables the three keys on an absent option, keeping nothing to keep.
	 */
	public function test_enable_writes_the_three_keys_when_absent(): void {
		WP_Mock::userFunction( 'get_option' )->once()->with( 'lc_settings', array() )->andReturn( array() );
		$written = array();
		WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
			static function ( string $name, $value ) use ( &$written ): bool {
				$written = $value;
				return true;
			}
		);

		$this->assertTrue( ( new LiveCanvasOptions() )->enable() );
		$this->assertSame(
			array(
				'enable-dynamic-templating' => '1',
				'header'                    => '1',
				'footerV2'                  => '1',
			),
			$written
		);
	}

	/**
	 * A merge, never a rewrite: the operator's own settings survive.
	 */
	public function test_enable_merges_without_touching_other_settings(): void {
		WP_Mock::userFunction( 'get_option' )->once()->with( 'lc_settings', array() )->andReturn(
			array(
				'license-code' => 'keep',
				'header'       => '1',
			)
		);
		$written = array();
		WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
			static function ( string $name, $value ) use ( &$written ): bool {
				$written = $value;
				return true;
			}
		);

		$this->assertTrue( ( new LiveCanvasOptions() )->enable() );
		$this->assertSame( 'keep', $written['license-code'] );
		$this->assertSame( '1', $written['header'] );
		$this->assertSame( '1', $written['footerV2'] );
		$this->assertSame( '1', $written['enable-dynamic-templating'] );
	}

	/**
	 * Already-enabled writes nothing.
	 */
	public function test_enable_writes_nothing_when_already_enabled(): void {
		WP_Mock::userFunction( 'get_option' )->once()->with( 'lc_settings', array() )->andReturn(
			array(
				'enable-dynamic-templating' => '1',
				'header'                    => '1',
				'footerV2'                  => '1',
			)
		);
		WP_Mock::userFunction( 'update_option' )->never();

		$this->assertFalse( ( new LiveCanvasOptions() )->enable() );
	}
}
