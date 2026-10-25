<?php
/**
 * Tests for the global template functions LiveCanvas resolves by name.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * The functions must be GLOBAL: [lc_wp_func func="md_pdp_style"] calls
 * call_user_func( 'md_pdp_style' ), so a namespaced declaration is invisible
 * to it — measured on production 2026-10-25, where every pattern page
 * rendered the PHP error instead of the series word.
 *
 * @covers ::md_pdp_style
 */
final class TemplateFunctionsTest extends TestCase {

	/**
	 * Defines ABSPATH (the file guards on it) and loads the file once.
	 */
	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own bootstrap constant.
			define( 'ABSPATH', '/tmp/md-site-tests/' );
		}
		require_once dirname( __DIR__, 4 ) . '/includes/Presentation/template-functions.php';
	}

	/**
	 * The name LiveCanvas calls resolves in the global namespace.
	 */
	public function test_the_global_function_exists(): void {
		$this->assertTrue( function_exists( 'md_pdp_style' ) );
	}

	/**
	 * A product in pattern-abstract renders the series word, not the slug.
	 */
	public function test_it_returns_the_series_word(): void {
		WP_Mock::userFunction( 'get_post' )->once()->andReturn(
			(object) array(
				'ID'        => 44,
				'post_type' => 'product',
			)
		);
		WP_Mock::userFunction( 'get_the_terms' )->once()->andReturn(
			array( (object) array( 'slug' => 'pattern-abstract' ) )
		);

		$this->assertSame( 'abstract', \md_pdp_style() );
	}
}
