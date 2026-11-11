<?php
/**
 * Tests for Dictionary class.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\I18n\Dictionary;
use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Tests\TestCase;

/**
 * Tests dictionary retrieval and 304/316 preservation across all nine languages.
 *
 * @covers \MetalDivider\Site\I18n\Dictionary
 */
final class DictionaryTest extends TestCase {

	/**
	 * Tears down the fixture.
	 */
	public function tearDown(): void {
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * Verifies material_wording preserves 304 and 316 in all 9 languages.
	 */
	public function test_material_wording_preserves_304_316_in_all_nine_languages(): void {
		foreach ( Languages::ALL as $code ) {
			$text = Dictionary::text( 'material_wording', $code );
			$this->assertStringContainsString( '304', $text, "304 missing in language {$code}" );
			$this->assertStringContainsString( '316', $text, "316 missing in language {$code}" );
			$this->assertStringContainsString( '304 / 316', $text, "304 / 316 format missing in language {$code}" );
		}
	}

	/**
	 * Verifies spec bar labels exist in all 9 languages.
	 */
	public function test_spec_bar_labels_exist_in_all_nine_languages(): void {
		$keys = array(
			'spec_material',
			'spec_style',
			'spec_open_area',
			'spec_visual_density',
			'spec_repeat_behavior',
			'spec_best_fit',
			'spec_motifs',
		);

		foreach ( Languages::ALL as $code ) {
			foreach ( $keys as $k ) {
				$val = Dictionary::text( $k, $code );
				$this->assertNotEmpty( $val );
				$this->assertNotSame( $k, $val, "Key {$k} untranslated in {$code}" );
			}
		}
	}

	/**
	 * Verifies ACF choices exist in all 9 languages.
	 */
	public function test_acf_choices_exist_in_all_nine_languages(): void {
		$choice_keys = array(
			'best_fit.architectural-screening',
			'best_fit.decorative-screening',
			'visual_density.open',
			'visual_density.balanced',
			'visual_density.dense',
			'visual_density.custom',
			'repeat_behavior.repeating',
			'repeat_behavior.flowing',
			'motif.abstract',
			'motif.geometric',
			'series.abstract',
			'series.geometric',
		);

		foreach ( Languages::ALL as $code ) {
			foreach ( $choice_keys as $ck ) {
				$val = Dictionary::text( $ck, $code );
				$this->assertNotEmpty( $val );
				$this->assertNotSame( $ck, $val, "Choice key {$ck} untranslated in {$code}" );
			}
		}
	}

	/**
	 * Verifies chrome_config returns complete keys for all languages.
	 */
	public function test_chrome_config_returns_complete_keys_for_all_languages(): void {
		$required_keys = array(
			'noResults',
			'kindPattern',
			'kindPage',
			'kindArticle',
			'copied',
			'copyFailed',
			'viewAllResults',
			'clearSearch',
			'closeLabel',
			'selectLanguage',
		);

		foreach ( Languages::ALL as $code ) {
			$cfg = Dictionary::chrome_config( $code );
			foreach ( $required_keys as $rk ) {
				$this->assertArrayHasKey( $rk, $cfg );
				$this->assertNotEmpty( $cfg[ $rk ] );
			}
		}
	}

	/**
	 * Verifies fallback behavior for unknown keys.
	 */
	public function test_fallback_behavior(): void {
		// Missing key falls back to key.
		$this->assertSame( 'non_existent_key', Dictionary::text( 'non_existent_key', 'en' ) );
		$this->assertSame( 'non_existent_key', Dictionary::text( 'non_existent_key', 'zh' ) );
	}

	/**
	 * Verifies catalog summary, pagination, empty-state, and PDP hero sentence keys are localized across all target languages.
	 */
	public function test_catalog_and_pdp_hero_ui_keys_localized_across_all_target_languages(): void {
		$keys = array(
			'hero_sentence',
			'hero_sentence_fit',
			'catalog_summary',
			'catalog_search_summary',
			'pattern_plural',
			'pager_prev',
			'pager_next',
			'pager_aria',
			'empty_search_title',
			'empty_search_body',
			'empty_series_title',
			'empty_series_body',
		);

		foreach ( Languages::TARGETS as $code ) {
			foreach ( $keys as $key ) {
				$en_val = Dictionary::text( $key, 'en' );
				$tr_val = Dictionary::text( $key, $code );
				$this->assertNotEmpty( $tr_val, "Key {$key} empty in {$code}" );
				$this->assertNotSame( $key, $tr_val, "Key {$key} missing in {$code}" );
				$this->assertNotSame( $en_val, $tr_val, "Key {$key} still in English in {$code}" );
			}
		}
	}
}
