<?php
/**
 * Tests for the catalog's series hero image data write.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit;

use MetalDivider\Site\Catalog;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Catalog::apply_series_hero_images: the nine series cards get
 * their already-imported source image's attachment URL, and a rerun writes
 * nothing.
 *
 * @covers \MetalDivider\Site\Catalog
 */
final class CatalogTest extends TestCase {

	/**
	 * The nine terms are written on the first run; the second run finds
	 * every value already in place and writes nothing.
	 */
	public function test_series_images_writes_nine_and_a_rerun_reports_no_change(): void {
		$state   = array();
		$updates = 0;
		$this->mock_wordpress( $state, $updates );

		$catalog = new Catalog();
		$first   = $catalog->apply_series_hero_images();

		$this->assertSame( 9, $first['written'], 'all nine series cards are written' );
		$this->assertSame( 0, $first['unchanged'] );
		$this->assertSame( array(), $first['missing'] );
		$this->assertCount( 9, $state, 'every term holds a hero URL' );
		$this->assertSame( $updates, 9 );

		$second = $catalog->apply_series_hero_images();

		$this->assertSame( 0, $second['written'], 'a rerun writes nothing' );
		$this->assertSame( 9, $second['unchanged'], 'a rerun reports the nine unchanged' );
		$this->assertSame( $updates, 9, 'no second update_term_meta call' );
	}

	/**
	 * A term the site does not carry is reported, not invented.
	 */
	public function test_series_images_reports_a_missing_term(): void {
		$state   = array();
		$updates = 0;
		$this->mock_wordpress( $state, $updates, 'pattern-gradient' );

		$report = ( new Catalog() )->apply_series_hero_images();

		$this->assertContains( 'term pattern-gradient', $report['missing'] );
		$this->assertSame( 8, $report['written'] );
	}

	/**
	 * A source image the site does not carry is reported, not invented.
	 */
	public function test_series_images_reports_a_missing_attachment(): void {
		$state   = array();
		$updates = 0;
		$this->mock_wordpress( $state, $updates, '', 'Leaf_Vines.webp' );

		$report = ( new Catalog() )->apply_series_hero_images();

		$this->assertContains( 'image Leaf_Vines.webp', $report['missing'] );
		$this->assertSame( 8, $report['written'] );
	}

	/**
	 * The WordPress functions the write calls: a term per slug, one
	 * attachment per source, the URL, and a term-meta store in $state.
	 *
	 * @param array<int, string> $state          The term-meta store, by term id.
	 * @param int                $updates        The update_term_meta call count.
	 * @param string             $missing_term   A slug get_term_by() does not find.
	 * @param string             $missing_source A source get_posts() does not find.
	 */
	private function mock_wordpress( array &$state, int &$updates, string $missing_term = '', string $missing_source = '' ): void {
		WP_Mock::userFunction( 'get_term_by' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $field, string $slug ) use ( $missing_term ) {
				if ( $slug === $missing_term ) {
					return false;
				}
				$term          = new \WP_Term( (object) array() );
				$term->term_id = abs( (int) crc32( $slug ) );
				return $term;
			}
		);
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( array $args ) use ( $missing_source ) {
				$source = (string) $args['meta_query'][0]['value'];
				if ( '' !== $missing_source && str_contains( $source, $missing_source ) ) {
					return array();
				}
				return array( 500 + abs( (int) crc32( $source ) ) % 100 );
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( int $id ): string => "https://site.test/uploads/{$id}.webp"
		);
		WP_Mock::userFunction( 'get_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id ) use ( &$state ) {
				return $state[ $term_id ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_term_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $term_id, string $key, string $value ) use ( &$state, &$updates ) {
				$state[ $term_id ] = $value;
				++$updates;
				return true;
			}
		);
	}
}
