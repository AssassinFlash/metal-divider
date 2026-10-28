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
	 * WordPress's escaping functions return their argument.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plain_text();
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
		$post            = new \WP_Post( new \stdClass() );
		$post->ID        = 44;
		$post->post_type = 'product';
		WP_Mock::userFunction( 'get_post' )->once()->andReturn( $post );
		$term       = new \WP_Term( (object) array() );
		$term->slug = 'pattern-abstract';
		WP_Mock::userFunction( 'get_the_terms' )->once()->andReturn( array( $term ) );

		$this->assertSame( 'abstract', \md_pdp_style() );
	}

	/**
	 * The design-intent guard the template's [lc_if] asks before it draws
	 * a paragraph: a slot WooCommerce holds no prose for answers false, so
	 * the empty `.pdp-body` never reaches the DOM.
	 */
	public function test_the_design_intent_predicate_exists_in_the_global_namespace(): void {
		$this->assertTrue( function_exists( 'md_pdp_has_design_intent' ) );
	}

	/**
	 * Outside a product there is no prose to print, so the guard is false
	 * and the template renders the same file on any face.
	 */
	public function test_the_design_intent_predicate_is_false_outside_a_product(): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( null );

		$this->assertFalse( \md_pdp_has_design_intent( 'lead' ) );
	}

	/**
	 * The slot predicate the template's [lc_if] asks: LiveCanvas resolves
	 * `condition` with call_user_func(), so this name has to be global too.
	 */
	public function test_the_slot_predicate_exists_in_the_global_namespace(): void {
		$this->assertTrue( function_exists( 'md_pdp_slot_has_image' ) );
	}

	/**
	 * Outside a product the predicate is false, so no frame is drawn —
	 * the template renders the same file on any face.
	 */
	public function test_the_slot_predicate_is_false_outside_a_product(): void {
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( null );

		$this->assertFalse( \md_pdp_slot_has_image( 'plate' ) );
	}

	/**
	 * The related-section guard is global, like the other [lc_if] predicates,
	 * and false outside a product so the heading is not printed on other faces.
	 */
	public function test_the_related_predicate_is_false_outside_a_product(): void {
		$this->assertTrue( function_exists( 'md_pdp_has_related' ) );
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( null );

		$this->assertFalse( \md_pdp_has_related() );
	}

	/**
	 * The eyebrow guard is global, and false when there is no series word
	 * to lead the word "pattern".
	 */
	public function test_the_style_predicate_is_false_outside_a_product(): void {
		$this->assertTrue( function_exists( 'md_pdp_has_style' ) );
		WP_Mock::userFunction( 'get_post' )->zeroOrMoreTimes()->andReturn( null );

		$this->assertFalse( \md_pdp_has_style() );
	}
}
