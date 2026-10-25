<?php
/**
 * Tests for the series hero image command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\CatalogCommand;
use MetalDivider\Site\Tests\TestCase;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Tests for CatalogCommand: the administrator gate and the idempotent
 * data write's report.
 *
 * @covers \MetalDivider\Site\Cli\CatalogCommand
 */
final class CatalogCommandTest extends TestCase {

	/**
	 * A non-administrator is refused before any write.
	 */
	public function test_series_images_refuses_a_non_administrator(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );

		$this->expectException( ExitException::class );
		( new CatalogCommand() )->series_images( array(), array() );
	}

	/**
	 * A run reports the nine writes.
	 */
	public function test_series_images_reports_the_write(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->mock_wordpress();

		$this->expectOutputString( "wp-cli success: series hero images: 9 written, 0 unchanged\n" );
		( new CatalogCommand() )->series_images( array(), array() );
	}

	/**
	 * A missing term or image fails visibly instead of printing success.
	 */
	public function test_series_images_fails_on_a_missing_image(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->mock_wordpress( true );

		$this->expectException( ExitException::class );
		$this->expectExceptionMessage( 'series hero images: missing' );
		( new CatalogCommand() )->series_images( array(), array() );
	}

	/**
	 * The WordPress functions the command drives.
	 *
	 * @param bool $miss Whether one source image is absent.
	 */
	private function mock_wordpress( bool $miss = false ): void {
		/**
		 * The term-meta store, by term id.
		 *
		 * @var array<int, string>
		 */
		$state = array();
		WP_Mock::userFunction( 'get_term_by' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $field, string $slug ) {
				$term          = new \WP_Term( (object) array() );
				$term->term_id = abs( (int) crc32( $slug ) );
				return $term;
			}
		);
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( array $args ) use ( $miss ) {
				if ( $miss && str_contains( (string) $args['meta_query'][0]['value'], 'Leaf_Vines' ) ) {
					return array();
				}
				return array( 500 );
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->zeroOrMoreTimes()->andReturn( 'https://site.test/uploads/500.webp' );
		WP_Mock::userFunction( 'get_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id ) use ( &$state ) {
				return $state[ $term_id ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $key, string $value ) use ( &$state ) {
				$state[ $term_id ] = $value;
				return true;
			}
		);
	}
}
