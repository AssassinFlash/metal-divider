<?php
/**
 * Tests for Translator class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\Cli\I18nCommand;
use MetalDivider\Site\I18n\DeepSeekClient;
use MetalDivider\Site\I18n\Segmenter;
use MetalDivider\Site\I18n\TranslationValidator;
use MetalDivider\Site\I18n\Translator;
use MetalDivider\Site\Tests\TestCase;

/**
 * Tests Translator TM caching, batching, and document translation.
 *
 * @covers \MetalDivider\Site\I18n\Translator
 */
final class TranslatorTest extends TestCase {

	/**
	 * Path to temporary TM file.
	 *
	 * @var string
	 */
	private string $temp_tm_file;

	/**
	 * Sets up the fixture.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->temp_tm_file = sys_get_temp_dir() . '/test-tm-cache-' . uniqid() . '.json';
	}

	/**
	 * Tears down the fixture.
	 */
	public function tearDown(): void {
		if ( file_exists( $this->temp_tm_file ) ) {
			unlink( $this->temp_tm_file );
		}
		parent::tearDown();
	}

	/**
	 * Verifies cached segments hit TM cache without API call.
	 */
	public function test_cached_segments_hit_tm_cache_without_api_call(): void {
		// Pre-populate TM cache.
		$seg   = 'Custom architectural metal screens';
		$tr    = '定制建筑金属屏风';
		$key   = 'zh:' . hash( 'sha256', $seg );
		$cache = array( $key => $tr );
		file_put_contents( $this->temp_tm_file, (string) json_encode( $cache ) );

		// Mock client that should NEVER be called.
		$client = $this->createMock( DeepSeekClient::class );
		$client->expects( $this->never() )->method( 'translate_batches_parallel' );

		$translator = new Translator( $client, new TranslationValidator(), new Segmenter(), $this->temp_tm_file );

		$result = $translator->translate_segments_cached( 'zh', array( $seg ) );

		$this->assertSame( array( $seg => $tr ), $result );
	}

	/**
	 * Verifies misses call client and persist to TM cache.
	 */
	public function test_misses_call_client_and_persist_to_tm_cache(): void {
		$seg = 'Standard panel size 1200 x 2400 mm';
		$tr  = '标准面板尺寸 1200 x 2400 mm';

		$client = $this->createMock( DeepSeekClient::class );
		$client->expects( $this->once() )
			->method( 'translate_batches_parallel' )
			->willReturn( array( array( $tr ) ) );

		$translator = new Translator( $client, new TranslationValidator(), new Segmenter(), $this->temp_tm_file );

		$result = $translator->translate_segments_cached( 'zh', array( $seg ) );

		$this->assertSame( array( $seg => $tr ), $result );

		// Verify file written.
		$this->assertFileExists( $this->temp_tm_file );
		$disk_data = json_decode( (string) file_get_contents( $this->temp_tm_file ), true );
		$this->assertIsArray( $disk_data );
		$key = 'zh:' . hash( 'sha256', $seg );
		$this->assertSame( $tr, $disk_data[ $key ] );
	}

	/**
	 * Verifies translate_html_document uses segmenter and cache.
	 */
	public function test_translate_html_document_uses_segmenter_and_cache(): void {
		$html = '<div class="banner"><h1>Architectural screens</h1></div>';
		$seg  = 'Architectural screens';
		$tr   = '建筑屏风';

		$client = $this->createMock( DeepSeekClient::class );
		$client->expects( $this->once() )
			->method( 'translate_batches_parallel' )
			->willReturn( array( array( $tr ) ) );

		$translator = new Translator( $client, new TranslationValidator(), new Segmenter(), $this->temp_tm_file );

		$translated_html = $translator->translate_html_document( $html, 'zh' );

		$this->assertSame( '<div class="banner"><h1>建筑屏风</h1></div>', $translated_html );
	}

	/**
	 * Verifies batch_translate_map masks SEOPress variables and skips zero-letter strings.
	 */
	public function test_batch_translate_map_masks_seopress_variables(): void {
		$client = $this->createMock( DeepSeekClient::class );
		$client->expects( $this->once() )
			->method( 'translate_batches_parallel' )
			->with(
				$this->callback(
					static function ( array $batches ): bool {
						return 1 === count( $batches )
							&& array( '<k1/> | Architectural Metal Screens' ) === $batches[0]['segments'];
					}
				)
			)
			->willReturn( array( array( '<k1/> | 建筑金属屏风' ) ) );

		$translator = new Translator( $client, new TranslationValidator(), new Segmenter(), $this->temp_tm_file );

		$result = $translator->batch_translate_map(
			'zh',
			array(
				'pure_vars' => '%%post_title%% %%sep%% %%sitetitle%%',
				'seo_title' => '%%post_title%% | Architectural Metal Screens',
			)
		);

		$this->assertSame( '%%post_title%% %%sep%% %%sitetitle%%', $result['pure_vars'] );
		$this->assertSame( '%%post_title%% | 建筑金属屏风', $result['seo_title'] );
	}

	/**
	 * Verifies translate_html_document extracts and translates text and alt attributes inside `<template>`.
	 */
	public function test_translate_html_document_translates_inside_template_mega_menu(): void {
		$html = '<header><p><a href="/patterns/">All patterns</a></p>'
			. '<template xo-mega-menu-name="header-mega-menu-v1">'
			. '<div class="mmv2"><h4 class="mmv2__title"><a class="mmv2__link" href="/laser-cut-panels/">Laser Cut Panels</a></h4>'
			. '<img src="/a.webp" alt="Perforated metal panels"></div>'
			. '</template></header>';

		$tm = array(
			'zh:' . hash( 'sha256', '<t1>All patterns</t1>' )     => '<t1>全部图案</t1>',
			'zh:' . hash( 'sha256', '<t1>Laser Cut Panels</t1>' ) => '<t1>激光切割板材</t1>',
			'zh:' . hash( 'sha256', 'Perforated metal panels' )   => '冲孔金属板材',
		);
		file_put_contents( $this->temp_tm_file, (string) json_encode( $tm ) );

		$client = $this->createMock( DeepSeekClient::class );
		$client->expects( $this->never() )->method( 'translate_batches_parallel' );

		$translator = new Translator( $client, new TranslationValidator(), new Segmenter(), $this->temp_tm_file );
		$out        = $translator->translate_html_document( $html, 'zh' );

		$this->assertStringContainsString( '>全部图案</a>', $out );
		$this->assertStringContainsString( '<a class="mmv2__link" href="/laser-cut-panels/">激光切割板材</a>', $out );
		$this->assertStringContainsString( 'alt="冲孔金属板材"', $out );
	}

	/**
	 * Verifies third-party brand ("Decorative Screens Direct" / "DSD"), duplicate paragraph,
	 * and Chinese glossary sanitization helpers are accurate and strictly idempotent.
	 */
	public function test_sanitize_content_brand_and_paragraph_helpers_are_idempotent(): void {
		$this->assertSame(
			'Square Rage | Architectural Metal Screen Pattern',
			I18nCommand::sanitize_brand_title( 'Square Rage Decorative Screens Direct | Architectural Metal Screen Pattern' )
		);
		$this->assertSame(
			'Square Rage',
			I18nCommand::sanitize_brand_title( 'Square Rage Decorative Screens Direct' )
		);

		$en_raw = '<p>As part of DSD’s Gradient range, the Skyfall adds a sense of movement adds a different appeal. DSD’s screens are precision cut, DSD provides workshop concept drawings for Square Rage Decorative Screens Direct design. <a href="/pattern/square-rage-decorative-screens-direct/">View</a></p>'
			. '<p>As part of DSD’s Gradient range, the Skyfall adds a sense of movement adds a different appeal. DSD’s screens are precision cut, DSD provides workshop concept drawings for Square Rage Decorative Screens Direct design. <a href="/pattern/square-rage-decorative-screens-direct/">View</a></p>';

		$en_clean = I18nCommand::sanitize_post_html( $en_raw, 'en' );
		$this->assertSame( 1, substr_count( $en_clean, '<p>' ) );
		$this->assertStringNotContainsString( 'DSD', $en_clean );
		$this->assertStringNotContainsString( 'Decorative Screens Direct</p>', $en_clean );
		$this->assertStringContainsString( 'href="/pattern/square-rage-decorative-screens-direct/"', $en_clean );
		$this->assertStringContainsString( 'As part of our Gradient range,', $en_clean );
		$this->assertStringContainsString( 'adds a sense of movement and a distinct appeal', $en_clean );
		$this->assertStringContainsString( 'Our screens are precision cut', $en_clean );
		$this->assertStringContainsString( 'Square Rage design', $en_clean );
		// Second run must be an exact no-op.
		$this->assertSame( $en_clean, I18nCommand::sanitize_post_html( $en_clean, 'en' ) );

		$es_out = I18nCommand::sanitize_post_plain_text( 'Como parte de la gama Gradient de DSD, aporta movimiento.', 'es' );
		$this->assertStringNotContainsString( 'DSD', $es_out );
		$this->assertSame( $es_out, I18nCommand::sanitize_post_plain_text( $es_out, 'es' ) );

		$de_out = I18nCommand::sanitize_post_plain_text( 'Als Teil der Gradient-Reihe von DSD bietet dieses Muster Privatsphäre.', 'de' );
		$this->assertStringNotContainsString( 'DSD', $de_out );
		$this->assertSame( $de_out, I18nCommand::sanitize_post_plain_text( $de_out, 'de' ) );

		$zh_raw   = '<p>作为 DSD 的渐变系列，DSD 的屏风适用于每笔批量订单与我们的产品，Square Rage Decorative Screens Direct 设计。</p>';
		$zh_clean = I18nCommand::sanitize_post_html( $zh_raw, 'zh' );
		$this->assertStringNotContainsString( 'DSD', $zh_clean );
		$this->assertStringNotContainsString( 'Decorative Screens Direct', $zh_clean );
		$this->assertStringNotContainsString( '订单', $zh_clean );
		$this->assertStringNotContainsString( '产品', $zh_clean );
		$this->assertSame( $zh_clean, I18nCommand::sanitize_post_html( $zh_clean, 'zh' ) );
	}
}
