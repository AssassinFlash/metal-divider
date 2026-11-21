<?php
/**
 * Tests for TemplateCompiler and the repository's 8-language template TM guard.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\I18n;

use MetalDivider\Site\Cli\I18nCommand;
use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\I18n\TemplateCompiler;
use MetalDivider\Site\I18n\TemplateStore;
use MetalDivider\Site\Tests\TestCase;
use RuntimeException;
use WP_CLI\ExitException;
use WP_Mock;

/**
 * Verifies offline template compilation, idempotent store updates, SHA-256 drift
 * detection, CLI `compile-templates` / `check-templates`, and repository-wide TM coverage.
 *
 * @covers \MetalDivider\Site\I18n\TemplateCompiler
 * @covers \MetalDivider\Site\Cli\I18nCommand
 */
final class TemplateCompilerTest extends TestCase {

	/**
	 * Temporary directory for isolated test fixtures.
	 *
	 * @var string
	 */
	private string $temp_root;

	/**
	 * Temporary uploads directory simulating `wp-content/uploads`.
	 *
	 * @var string
	 */
	private string $uploads_dir;

	/**
	 * Sets up isolated fixture directories.
	 */
	public function setUp(): void {
		parent::setUp();
		TemplateStore::forget();
		Languages::force_for_tests( null );

		$this->temp_root   = sys_get_temp_dir() . '/md-tpl-compiler-' . uniqid( '', true );
		$this->uploads_dir = $this->temp_root . '/uploads';
		mkdir( $this->temp_root . '/content/livecanvas/partials', 0755, true );
		mkdir( $this->temp_root . '/content/i18n', 0755, true );
		mkdir( $this->uploads_dir . '/md-i18n', 0755, true );
		TemplateStore::set_storage_dir_for_tests( $this->uploads_dir . '/md-i18n' );
	}

	/**
	 * Cleans up temporary directories.
	 */
	public function tearDown(): void {
		TemplateStore::set_storage_dir_for_tests( null );
		TemplateStore::forget();
		Languages::force_for_tests( null );

		if ( is_dir( $this->temp_root ) ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->temp_root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->temp_root );
		}

		parent::tearDown();
	}

	/**
	 * Verifies offline compilation builds localized stores, is idempotent, and passes drift checks.
	 */
	public function test_compile_all_builds_store_idempotently_and_passes_drift_check(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$content_dir = $this->temp_root . '/content';
		$home_html   = '<main><h1>Architectural screens</h1><a href="/patterns/">Browse patterns →</a></main>';
		$header_html = '<header><nav><a class="xo-menu-horizontal-v1__link xo-menu-horizontal-v1__link--sub" href="/laser-cut-panels/"><span class="xo-menu-horizontal-v1__label">Laser Cut Panels</span></a></nav>'
			. '<template xo-mega-menu-name="header-mega-menu-v1"><a class="mmv2__link" href="/laser-cut-panels/">Laser Cut Panels</a></template></header>';

		file_put_contents( $content_dir . '/livecanvas/home.html', $home_html );
		file_put_contents( $content_dir . '/livecanvas/partials/header.html', $header_html );

		$de_tm = array(
			hash( 'sha256', 'Architectural screens' )      => 'Architektur-Metalltrennwände',
			hash( 'sha256', '<t1>Browse patterns →</t1>' ) => '<t1>Muster durchsuchen →</t1>',
			hash( 'sha256', '<t1><t2>Laser Cut Panels</t2></t1>' ) => '<t1><t2>Lasergeschnittene Paneele</t2></t1>',
			hash( 'sha256', '<t1>Laser Cut Panels</t1>' )  => '<t1>Lasergeschnittene Paneele</t1>',
		);
		$ar_tm = array(
			hash( 'sha256', 'Architectural screens' )      => 'فواصل معدنية معمارية',
			hash( 'sha256', '<t1>Browse patterns →</t1>' ) => '<t1>تصفح الأنماط →</t1>',
			hash( 'sha256', '<t1><t2>Laser Cut Panels</t2></t1>' ) => '<t1><t2>ألواح مقطوعة بالليزر</t2></t1>',
			hash( 'sha256', '<t1>Laser Cut Panels</t1>' )  => '<t1>ألواح مقطوعة بالليزر</t1>',
		);

		TemplateCompiler::save_tm( 'de', $de_tm, $content_dir );
		TemplateCompiler::save_tm( 'ar', $ar_tm, $content_dir );

		// First compile writes both languages.
		$first_de = TemplateCompiler::compile_all( 'de', $content_dir );
		$first_ar = TemplateCompiler::compile_all( 'ar', $content_dir );
		$this->assertSame( 1, $first_de['updated_languages'] );
		$this->assertSame( 2, $first_de['entries'] );
		$this->assertSame( 1, $first_ar['updated_languages'] );

		// Second compile is a strict no-op (idempotent).
		$second_de = TemplateCompiler::compile_all( 'de', $content_dir );
		$this->assertSame( 0, $second_de['updated_languages'] );

		// Drift check returns empty when compiled store matches source hashes.
		$this->assertSame( array(), TemplateCompiler::check_store_drift( 'de', $content_dir ) );
		$this->assertSame( array(), TemplateCompiler::check_store_drift( 'ar', $content_dir ) );

		// Verify rendered output carries localized text and Arabic RTL mirrored arrow.
		$de_home = TemplateStore::get_template( 'home', $home_html, 'de' );
		$this->assertStringContainsString( 'Architektur-Metalltrennwände', $de_home );
		$this->assertStringContainsString( 'href="/de/patterns/"', $de_home );

		$ar_home = TemplateStore::get_template( 'home', $home_html, 'ar' );
		$this->assertStringContainsString( 'تصفح الأنماط ←</a>', $ar_home );

		// Verify header partial compiles localized Mega Menu `<template>` labels too.
		$de_header = TemplateStore::get_partial( 'header', $header_html, 'de' );
		$this->assertStringContainsString( '<a class="mmv2__link" href="/de/laser-cut-panels/">Lasergeschnittene Paneele</a>', $de_header );

		// Mutate source file without recompiling -> drift detector MUST catch src_hash_mismatch.
		file_put_contents( $content_dir . '/livecanvas/home.html', $home_html . '<!-- edited -->' );
		$drift = TemplateCompiler::check_store_drift( 'de', $content_dir );
		$this->assertSame(
			array(
				array(
					'lang'   => 'de',
					'key'    => 'tpl:home',
					'reason' => 'src_hash_mismatch',
				),
			),
			$drift
		);
	}

	/**
	 * Verifies offline compilation fails closed when a translatable segment is missing from TM.
	 */
	public function test_compile_all_refuses_missing_tm_segment(): void {
		$content_dir = $this->temp_root . '/content';
		file_put_contents( $content_dir . '/livecanvas/home.html', '<h1>Untranslated brand new headline</h1>' );
		TemplateCompiler::save_tm( 'de', array( hash( 'sha256', 'Something else' ) => 'Etwas anderes' ), $content_dir );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Missing template TM entry for [de] tpl:home' );

		TemplateCompiler::compile_all( 'de', $content_dir );
	}

	/**
	 * Verifies `wp md-site i18n check-templates --format=json` emits `[]` when clean
	 * and a non-empty JSON array + ExitException when drifted.
	 */
	public function test_cli_check_templates_json_contract_passes_and_fails_on_drift(): void {
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );
		WP_Mock::userFunction( 'wp_cache_flush' )->andReturn( true );

		$content_dir = $this->temp_root . '/content';
		$html        = '<section><h2>Precision stainless screens</h2></section>';
		file_put_contents( $content_dir . '/livecanvas/about.html', $html );
		TemplateCompiler::save_tm(
			'fr',
			array( hash( 'sha256', 'Precision stainless screens' ) => 'Écrans en acier inoxydable de précision' ),
			$content_dir
		);

		$cmd = new I18nCommand( $content_dir );

		ob_start();
		$cmd->compile_templates( array(), array( 'lang' => 'fr' ) );
		$compile_out = (string) ob_get_clean();
		$this->assertStringContainsString( 'Compiled 1 template/partial entries', $compile_out );

		// Pass case: prints `[]` and exits normally.
		ob_start();
		$cmd->check_templates(
			array(),
			array(
				'lang'   => 'fr',
				'format' => 'json',
			)
		);
		$pass_out = trim( (string) ob_get_clean() );
		$this->assertStringEndsWith( '[]', $pass_out );

		// Drift case: edit template HTML without recompiling -> prints JSON issue and throws ExitException.
		file_put_contents( $content_dir . '/livecanvas/about.html', $html . ' ' );
		$failed = false;
		ob_start();
		try {
			$cmd->check_templates(
				array(),
				array(
					'lang'   => 'fr',
					'format' => 'json',
				)
			);
		} catch ( ExitException $e ) {
			$failed = true;
			$this->assertStringContainsString( '1 issue(s) detected', $e->getMessage() );
		} finally {
			$fail_out = (string) ob_get_clean();
		}

		$this->assertTrue( $failed, 'drifted template store must fail check-templates' );
		$this->assertStringContainsString( '"reason":"src_hash_mismatch"', $fail_out );
		$this->assertStringContainsString( '"key":"tpl:about"', $fail_out );
	}

	/**
	 * Static repository guard: every translatable segment across all LiveCanvas templates
	 * and partials must have a non-empty translation in all 8 git-tracked `tm-<lang>.json` files,
	 * and the fixed German typo `Gestrebtes` must never reappear.
	 */
	public function test_repository_template_tm_covers_all_livecanvas_templates_across_8_languages(): void {
		$files = TemplateCompiler::template_files();
		$this->assertGreaterThanOrEqual( 40, count( $files ), 'expected all LiveCanvas templates and partials' );

		$missing = TemplateCompiler::check_tm_coverage();
		if ( array() !== $missing ) {
			$lines = array();
			foreach ( $missing as $lang => $items ) {
				$sample  = array_slice( $items, 0, 3 );
				$preview = implode(
					'; ',
					array_map(
						static fn( array $item ): string => $item['key'] . ': ' . mb_substr( $item['segment'], 0, 60 ),
						$sample
					)
				);
				$lines[] = sprintf( '%s (%d missing: %s)', $lang, count( $items ), $preview );
			}
			$this->fail( 'Repository template TM is incomplete — run `wp md-site i18n translate --only=templates`: ' . implode( ' | ', $lines ) );
		}

		// Regression guard for the German expanded-metal subheading spelling fix (`Gestrebtes` -> `Gestrecktes`).
		$de_tm = TemplateCompiler::load_tm( 'de' );
		foreach ( $de_tm as $translation ) {
			$this->assertStringNotContainsString( 'Gestrebtes', $translation );
		}

		// Regression guard for GLOSSARY.md / B2B inquiry banned Chinese terms across all template TM entries.
		$zh_tm        = TemplateCompiler::load_tm( 'zh' );
		$banned_terms = array( '订单', '产品', '款式', '花型', '下单' );
		foreach ( $zh_tm as $hash => $translation ) {
			foreach ( $banned_terms as $term ) {
				$this->assertStringNotContainsString(
					$term,
					$translation,
					sprintf( 'tm-zh.json entry %s must not contain banned term "%s"', $hash, $term )
				);
			}
		}
	}
}
