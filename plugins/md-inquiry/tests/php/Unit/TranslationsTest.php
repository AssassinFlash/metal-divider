<?php
/**
 * Tests for the 9-language translation dictionary.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Locale;
use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\Translations;
use MetalDivider\Inquiry\Validator;
use WP_Mock;

/**
 * Validates translations, option labels, and confirmation emails across all 9 languages.
 *
 * @covers \MetalDivider\Inquiry\Translations
 */
final class TranslationsTest extends TestCase {

	/**
	 * All non-English canonical languages have complete option label coverage.
	 */
	public function test_all_options_have_translations_in_all_languages(): void {
		$non_en = array( 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' );

		$all_options = array_merge(
			Validator::COUNTRIES,
			Validator::SCOPES,
			Validator::ROUTES,
			Validator::QUANTITIES
		);

		foreach ( $non_en as $lang ) {
			foreach ( $all_options as $option ) {
				$label = Translations::option_label( $option, $lang );
				$this->assertNotEmpty( $label, "Option '{$option}' missing in '{$lang}'" );
				$this->assertNotSame( '', $label );
			}
		}
	}

	/**
	 * Option labels for 'en' return the original English text unchanged.
	 */
	public function test_option_labels_in_english_return_original_text(): void {
		$this->assertSame( 'Facade screening', Translations::option_label( 'Facade screening', 'en' ) );
		$this->assertSame( 'Single zone', Translations::option_label( 'Single zone', 'en' ) );
		$this->assertSame( 'United States', Translations::option_label( 'United States', 'en' ) );
		$this->assertSame( '10-50 sqm', Translations::option_label( '10-50 sqm', 'en' ) );
	}

	/**
	 * Strings translate correctly in non-EN locales and preserve original for EN.
	 */
	public function test_translate_general_strings(): void {
		// In English, returns original.
		$this->assertSame( 'Send inquiry', Translations::translate( 'Send inquiry', 'en' ) );
		$this->assertSame( 'Selected pattern', Translations::translate( 'Selected pattern', 'en' ) );

		// In Chinese, check architectural domain requirements:
		// Pattern = 图案 (never 产品/款式/花型), Inquiry = 询盘 (never 订单/购物车).
		$this->assertSame( '发送询盘', Translations::translate( 'Send inquiry', 'zh' ) );
		$this->assertSame( '已选图案', Translations::translate( 'Selected pattern', 'zh' ) );
		$this->assertSame( '姓名', Translations::translate( 'Name', 'zh' ) );
		$this->assertSame( '电子邮箱', Translations::translate( 'Email', 'zh' ) );
		$this->assertSame( '留言', Translations::translate( 'Message', 'zh' ) );
		$this->assertSame( '请选择您的国家/地区。', Translations::translate( 'Please choose your country.', 'zh' ) );

		// In German.
		$this->assertSame( 'Anfrage senden', Translations::translate( 'Send inquiry', 'de' ) );
		$this->assertSame( 'Ausgewähltes Muster', Translations::translate( 'Selected pattern', 'de' ) );

		// In Spanish.
		$this->assertSame( 'Enviar consulta', Translations::translate( 'Send inquiry', 'es' ) );
		$this->assertSame( 'Patrón seleccionado', Translations::translate( 'Selected pattern', 'es' ) );

		// In French.
		$this->assertSame( 'Envoyer la demande', Translations::translate( 'Send inquiry', 'fr' ) );
		$this->assertSame( 'Motif sélectionné', Translations::translate( 'Selected pattern', 'fr' ) );

		// In Arabic.
		$this->assertSame( 'إرسال الاستفسار', Translations::translate( 'Send inquiry', 'ar' ) );
		$this->assertSame( 'النمط المختار', Translations::translate( 'Selected pattern', 'ar' ) );

		// In Vietnamese.
		$this->assertSame( 'Gửi yêu cầu báo giá', Translations::translate( 'Send inquiry', 'vi' ) );
		$this->assertSame( 'Họa tiết đã chọn', Translations::translate( 'Selected pattern', 'vi' ) );

		// In Indonesian.
		$this->assertSame( 'Kirim permintaan', Translations::translate( 'Send inquiry', 'id' ) );
		$this->assertSame( 'Pola yang dipilih', Translations::translate( 'Selected pattern', 'id' ) );

		// In Portuguese (Brazil).
		$this->assertSame( 'Enviar consulta', Translations::translate( 'Send inquiry', 'pt-br' ) );
		$this->assertSame( 'Padrão selecionado', Translations::translate( 'Selected pattern', 'pt-br' ) );
	}

	/**
	 * Unknown string returns the original text.
	 */
	public function test_translate_unknown_string_returns_original(): void {
		$original = 'Some unknown phrase not in dictionary';
		$this->assertSame( $original, Translations::translate( $original, 'zh' ) );
		$this->assertSame( $original, Translations::translate( $original, 'de' ) );
	}

	/**
	 * Customer confirmation email has high-craft translations adhering to domain rules.
	 */
	public function test_confirmation_email_translations_and_domain_rules(): void {
		$this->mock_passthroughs();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		$non_en = array( 'zh', 'es', 'pt-br', 'de', 'fr', 'ar', 'id', 'vi' );
		$config = new Config( array() );

		foreach ( $non_en as $lang ) {
			$email = Translations::confirmation_email(
				$config,
				array(
					'name'         => 'Jane Chan',
					'email'        => 'jane@studio.com',
					'pattern_name' => 'Apiary',
					'pattern_code' => 'APIARY',
				),
				$lang
			);

			$this->assertArrayHasKey( 'subject', $email );
			$this->assertArrayHasKey( 'body', $email );
			$this->assertNotEmpty( $email['subject'] );
			$this->assertNotEmpty( $email['body'] );

			// Must mention 304 or 316 (stainless steel).
			$this->assertStringContainsString( '304', $email['body'], "Language {$lang} must mention 304 stainless steel" );
			$this->assertStringContainsString( '316', $email['body'], "Language {$lang} must mention 316 stainless steel" );

			// Must NEVER mention aluminum, brass, corten, copper, mild steel.
			$forbidden = array( 'aluminum', 'aluminium', 'corten', 'copper', 'brass', 'mild steel', '黄铜', '耐候钢', '铝合金', '碳钢' );
			foreach ( $forbidden as $term ) {
				$this->assertStringNotContainsStringIgnoringCase( $term, $email['body'] );
			}

			// Must contain the inquiry references.
			$this->assertStringContainsString( 'Jane Chan', $email['body'] );
			$this->assertStringContainsString( 'Apiary', $email['body'] );
		}

		// Chinese specific terminology checks.
		$zh_email = Translations::confirmation_email(
			$config,
			array(
				'name'         => '张工',
				'email'        => 'zhang@design.cn',
				'pattern_name' => '蜂巢',
				'pattern_code' => 'APIARY',
			),
			'zh'
		);
		$this->assertStringContainsString( '询盘', $zh_email['subject'] );
		$this->assertStringContainsString( '图案', $zh_email['body'] );
		$this->assertStringNotContainsString( '产品', $zh_email['body'] );
		$this->assertStringNotContainsString( '订单', $zh_email['body'] );
		$this->assertStringNotContainsString( '购物车', $zh_email['body'] );
		$this->assertStringNotContainsString( '花型', $zh_email['body'] );
	}
}
