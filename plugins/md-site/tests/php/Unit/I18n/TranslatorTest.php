<?php
/**
 * Tests for Translator class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

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
}
