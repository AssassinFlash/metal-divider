<?php
/**
 * Tests for TranslationValidator class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\TranslationValidator;
use MetalDivider\Site\Tests\TestCase;

/**
 * Tests placeholder checks, figure checks, brand checks, and banned metal/commerce checks.
 *
 * @covers \MetalDivider\Site\I18n\TranslationValidator
 */
final class TranslationValidatorTest extends TestCase {

	/**
	 * Verifies valid translation passes.
	 */
	public function test_valid_translation_passes(): void {
		$validator = new TranslationValidator();
		$source    = 'Welcome to <t1>Metal Divider</t1>. Pattern has 25% open area and 304 stainless steel.';
		$target    = '欢迎来到 <t1>Metal Divider</t1>。图案具有 25% 镂空率和 304 不锈钢。';

		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertSame( array(), $problems );
	}

	/**
	 * Verifies placeholder mismatch fails.
	 */
	public function test_placeholder_mismatch_fails(): void {
		$validator = new TranslationValidator();
		$source    = 'Visit <t1>our patterns</t1> and <t2>quote</t2>.';
		$target    = '浏览 <t1>我们的图案</t1>。'; // Missing <t2>.

		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'Placeholders mismatch', $problems[0] );
	}

	/**
	 * Verifies placeholder nesting error fails.
	 */
	public function test_placeholder_nesting_error_fails(): void {
		$validator = new TranslationValidator();
		$source    = '<t1><t2>Nested</t2></t1>';
		$target    = '<t1><t2>Nested</t1></t2>'; // Inverted closing tags.

		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'improperly nested', $problems[0] );
	}

	/**
	 * Verifies preserved brand missing fails.
	 */
	public function test_preserved_brand_missing_fails(): void {
		$validator = new TranslationValidator();
		$source    = 'Contact Metal Divider on WhatsApp for details.';
		$target    = '联系我们获取详情。'; // Missing Metal Divider and WhatsApp.

		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertCount( 2, $problems );
	}

	/**
	 * Verifies changed figures fail.
	 */
	public function test_figures_changed_fails(): void {
		$validator = new TranslationValidator();
		$source    = 'Thickness is 3.0 mm and 304 grade.';
		$target    = '厚度为 2.0 mm 和 304 等级。'; // 3.0 changed to 2.0.

		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'Figures changed', $problems[0] );
	}

	/**
	 * Verifies banned metals rejected unless present in source.
	 */
	public function test_banned_metals_rejected_unless_in_source(): void {
		$validator = new TranslationValidator();

		// Banned metal introduced in target.
		$source   = 'We produce screens for architectural partitions.';
		$target   = '我们提供铝制和耐候钢屏风。';
		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'Banned metal mentioned', $problems[0] );

		// Comparative sentence in source where other metals were mentioned in English.
		$source_comp   = 'Unlike corten or aluminum, our stainless steel never rusts.';
		$target_comp   = '与耐候钢或铝不同，我们的不锈钢永不生锈。';
		$problems_comp = $validator->validate( $source_comp, $target_comp, 'zh' );
		$this->assertSame( array(), $problems_comp );
	}

	/**
	 * Verifies banned commerce rejected unless present in source.
	 */
	public function test_banned_commerce_rejected_unless_in_source(): void {
		$validator = new TranslationValidator();

		$source   = 'Request a quote for this pattern.';
		$target   = '加入购物车并立即购买此图案。';
		$problems = $validator->validate( $source, $target, 'zh' );
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'Banned commerce term mentioned', $problems[0] );
	}

	/**
	 * Verifies Arabic numeral normalization to ASCII digits.
	 */
	public function test_arabic_numeral_normalization(): void {
		$normalized = TranslationValidator::normalize_digits( 'نسبة الفراغ ٢٥% وسماكة ٣ مم' );
		$this->assertSame( 'نسبة الفراغ 25% وسماكة 3 مم', $normalized );
	}

	/**
	 * Verifies word boundaries prevent false positives on French/Spanish words and space thousand separators pass.
	 */
	public function test_word_boundaries_and_adjacent_placeholders(): void {
		$validator = new TranslationValidator();

		// French "écart" / "carton" must not falsely trigger banned commerce "cart".
		$this->assertSame(
			array(),
			$validator->validate(
				'Panel gap tolerance and protective packaging up to 10,000 mm.',
				'Tolérance d’écart des panneaux et emballage carton jusqu’à 10 000 mm.',
				'fr'
			)
		);

		// Adjacent placeholders must not merge figures.
		$this->assertSame(
			array(),
			$validator->validate(
				'Grades <t1>304</t1><t2>316</t2> with bronze PVD finish.',
				'等级 <t1>304</t1> <t2>316</t2> 配古铜色 PVD 表面处理。',
				'zh'
			)
		);

		// Chinese banned pattern terms 款式 / 花型 must be caught.
		$problems = $validator->validate( 'Architectural pattern screen.', '建筑花型屏风。', 'zh' );
		$this->assertNotEmpty( $problems );
	}

	/**
	 * Verifies Chinese banned terms 订单 and 产品 are strictly rejected by validator.
	 */
	public function test_chinese_banned_order_and_product_terms_rejected(): void {
		$validator = new TranslationValidator();

		$order_problems = $validator->validate( 'Every batch order is inspected before crating.', '每笔批量订单在装箱前均经过检验。', 'zh' );
		$this->assertNotEmpty( $order_problems );
		$this->assertStringContainsString( '订单', implode( '; ', $order_problems ) );

		$product_problems = $validator->validate( 'Browse our stainless steel products.', '浏览我们的不锈钢产品。', 'zh' );
		$this->assertNotEmpty( $product_problems );
		$this->assertStringContainsString( '产品', implode( '; ', $product_problems ) );
	}
}
