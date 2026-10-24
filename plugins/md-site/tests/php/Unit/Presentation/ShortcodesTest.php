<?php
/**
 * Tests for the PDP data shortcodes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Shortcodes;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Shortcodes: the two zero-design PDP slots of the template
 * (spec 19 §6.2) — the material constant assembled, the design-intent shell
 * answering nothing until its phrase groups exist.
 *
 * @covers \MetalDivider\Site\Presentation\Shortcodes
 */
final class ShortcodesTest extends TestCase {

	/**
	 * Sets up translation and escaping.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->plain_text();
	}

	/**
	 * The register() method adds both shortcodes.
	 */
	public function test_register_adds_both_shortcodes(): void {
		$shortcodes = new Shortcodes();
		$added      = array();
		WP_Mock::userFunction( 'add_shortcode' )->twice()->andReturnUsing(
			static function ( string $tag, callable $callback ) use ( &$added ): void {
				$added[ $tag ] = $callback;
			}
		);

		$shortcodes->register();

		$this->assertSame(
			array( Shortcodes::MATERIAL, Shortcodes::DESIGN_INTENT ),
			array_keys( $added ),
			'both shortcodes register, and nothing else'
		);
		$this->assertSame( array( $shortcodes, 'material' ), $added[ Shortcodes::MATERIAL ] );
		$this->assertSame( array( $shortcodes, 'design_intent' ), $added[ Shortcodes::DESIGN_INTENT ] );
	}

	/**
	 * The material shortcode assembles the site-wide constant in the
	 * spec-table component's value markup — zero design decisions.
	 */
	public function test_material_assembles_the_site_wide_constant(): void {
		$markup = ( new Shortcodes() )->material();

		$this->assertSame( '<span class="md-spec-table__value">Stainless Steel (304 / 316)</span>', $markup );
		$this->assertStringNotContainsString( ' style=', $markup, 'zero inline styles (AGENTS.md §5)' );
	}

	/**
	 * The design-intent shell answers nothing until its phrase groups exist.
	 */
	public function test_design_intent_shell_answers_nothing(): void {
		$this->assertSame( '', ( new Shortcodes() )->design_intent() );
	}
}
