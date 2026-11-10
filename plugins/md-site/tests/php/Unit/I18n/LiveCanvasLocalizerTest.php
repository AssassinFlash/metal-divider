<?php
/**
 * Tests for LiveCanvasLocalizer class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\I18n\LiveCanvasLocalizer;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests LiveCanvas dynamic template and partial priming filters and RTL language attributes.
 *
 * @covers \MetalDivider\Site\I18n\LiveCanvasLocalizer
 */
final class LiveCanvasLocalizerTest extends TestCase {

	/**
	 * Tears down the fixture.
	 */
	public function tearDown(): void {
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * Verifies filter_language_attributes for LTR and RTL.
	 */
	public function test_filter_language_attributes_for_ltr_and_rtl(): void {
		$localizer = new LiveCanvasLocalizer();

		Languages::force_for_tests( 'zh' );
		$zh_attr = $localizer->filter_language_attributes( 'class="html-root"' );
		$this->assertStringContainsString( 'lang="zh-Hans"', $zh_attr );
		$this->assertStringContainsString( 'dir="ltr"', $zh_attr );

		Languages::force_for_tests( 'pt-br' );
		$pt_attr = $localizer->filter_language_attributes( 'lang="en-US" dir="ltr"' );
		$this->assertStringContainsString( 'lang="pt-BR"', $pt_attr );
		$this->assertStringContainsString( 'dir="ltr"', $pt_attr );

		// Arabic must have dir="rtl".
		Languages::force_for_tests( 'ar' );
		$ar_attr = $localizer->filter_language_attributes( 'lang="en-US" dir="ltr"' );
		$this->assertStringContainsString( 'lang="ar"', $ar_attr );
		$this->assertStringContainsString( 'dir="rtl"', $ar_attr );
	}

	/**
	 * Verifies hook and filter registration.
	 */
	public function test_register_hooks_filters(): void {
		$localizer = new LiveCanvasLocalizer();

		WP_Mock::expectFilterAdded( 'lc_modify_template_id', array( $localizer, 'prime_dynamic_template' ), 20, 1 );
		WP_Mock::expectFilterAdded( 'wpml_object_id', array( $localizer, 'prime_chrome_partial' ), 20, 2 );
		WP_Mock::expectFilterAdded( 'pre_do_shortcode_tag', array( $localizer, 'render_lc_partial_shortcode' ), 20, 3 );
		WP_Mock::expectFilterAdded( 'lc_modify_template_content', array( $localizer, 'rewrite_urls' ), 20, 1 );
		WP_Mock::expectFilterAdded( 'lc_modify_header_content', array( $localizer, 'rewrite_urls' ), 20, 1 );
		WP_Mock::expectFilterAdded( 'lc_modify_footer_content', array( $localizer, 'rewrite_urls' ), 20, 1 );
		WP_Mock::expectFilterAdded( 'language_attributes', array( $localizer, 'filter_language_attributes' ), 20, 1 );

		$localizer->register();
		$this->assertConditionsMet();
	}
}
