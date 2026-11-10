<?php
/**
 * WP-CLI commands for 9-language I18n setup, status, and translation pipelines.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\I18n\Setup;
use MetalDivider\Site\I18n\TemplateStore;
use MetalDivider\Site\I18n\Translator;
use WP_CLI;

/**
 * Manages the programmatic setup, validation, status, and translation of
 * metal-divider.com's nine supported languages.
 */
final class I18nCommand {

	/**
	 * Configures or verifies the 9-language WPML + ST + WCML + ACFML stack.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Runs a read-only drift check against required settings.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function setup( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['check'] ) ) {
			$check = Setup::check();
			if ( $check['ok'] ) {
				WP_CLI::success( 'I18n configuration matches desired state (no drift).' );
				return;
			}

			foreach ( $check['issues'] as $issue ) {
				WP_CLI::warning( $issue );
			}
			WP_CLI::error( sprintf( '%d configuration drift issue(s) detected.', count( $check['issues'] ) ) );
		}

		$result = Setup::apply();
		foreach ( $result['steps'] as $step ) {
			WP_CLI::log( '  [setup] ' . $step );
		}

		if ( ! $result['ok'] ) {
			foreach ( $result['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
			WP_CLI::error( 'I18n setup finished with errors.' );
		}

		WP_CLI::success( 'I18n 9-language stack configured successfully.' );
	}

	/**
	 * Translates pattern catalog, series terms, pages, and LiveCanvas templates.
	 *
	 * ## OPTIONS
	 *
	 * [--only=<scope>]
	 * : Execution scope: all, terms, pages, products, or templates.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - terms
	 *   - pages
	 *   - products
	 *   - templates
	 * ---
	 *
	 * [--lang=<code|all>]
	 * : Target language canonical code or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--tm-cache=<path>]
	 * : Custom translation memory cache JSON file path.
	 *
	 * [--force]
	 * : Force re-translation even if already translated.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function translate( array $args, array $assoc_args ): void {
		$only          = (string) ( $assoc_args['only'] ?? 'all' );
		$lang          = (string) ( $assoc_args['lang'] ?? 'all' );
		$force         = isset( $assoc_args['force'] );
		$tm_cache_path = isset( $assoc_args['tm-cache'] ) ? (string) $assoc_args['tm-cache'] : null;

		$translator = new Translator( null, null, null, $tm_cache_path );

		WP_CLI::log( sprintf( 'Starting I18n translation (scope: %s, lang: %s, force: %s)...', $only, $lang, $force ? 'yes' : 'no' ) );

		try {
			$report = $translator->translate_all(
				array(
					'only'  => $only,
					'lang'  => $lang,
					'force' => $force,
				)
			);

			WP_CLI::success(
				sprintf(
					'Translation completed: %d series terms, %d pages, %d products, %d templates.',
					$report['terms'],
					$report['pages'],
					$report['products'],
					$report['templates']
				)
			);
		} catch ( \Throwable $e ) {
			WP_CLI::error( 'Translation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Displays translation status matrix across all nine languages.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$all_langs = Languages::all();
		$rows      = array();

		// Count English source entities.
		$source_terms = array_filter(
			Languages::source_terms( 'product_cat' ),
			static fn( $t ): bool => 'uncategorized' !== $t->slug
		);
		$term_count   = count( $source_terms );

		$source_pages = Languages::source_posts( 'page' );
		$page_count   = count( $source_pages );

		$source_prods = Languages::source_posts( 'product' );
		$prod_count   = count( $source_prods );

		foreach ( Languages::ALL as $code ) {
			$info = $all_langs[ $code ];
			if ( Languages::SOURCE === $code ) {
				$rows[] = array(
					'Language'  => $info['label'] . ' (' . $code . ')',
					'Terms'     => sprintf( '%d / %d', $term_count, $term_count ),
					'Pages'     => sprintf( '%d / %d', $page_count, $page_count ),
					'Products'  => sprintf( '%d / %d', $prod_count, $prod_count ),
					'Templates' => 'Source',
				);
				continue;
			}

			// Count translated terms.
			$tr_terms = 0;
			foreach ( $source_terms as $t ) {
				if ( Languages::term_translation( (int) $t->term_id, 'product_cat', $code ) > 0 ) {
					++$tr_terms;
				}
			}

			// Count translated pages.
			$tr_pages = 0;
			foreach ( $source_pages as $p ) {
				if ( Languages::post_translation( (int) $p->ID, $code ) > 0 ) {
					++$tr_pages;
				}
			}

			// Count translated products.
			$tr_prods = 0;
			foreach ( $source_prods as $pr ) {
				if ( Languages::post_translation( (int) $pr->ID, $code ) > 0 ) {
					++$tr_prods;
				}
			}

			// Templates cached.
			$store        = TemplateStore::load( $code );
			$tr_templates = count( $store );

			$rows[] = array(
				'Language'  => $info['label'] . ' (' . $code . ')',
				'Terms'     => sprintf( '%d / %d', $tr_terms, $term_count ),
				'Pages'     => sprintf( '%d / %d', $tr_pages, $page_count ),
				'Products'  => sprintf( '%d / %d', $tr_prods, $prod_count ),
				'Templates' => (string) $tr_templates,
			);
		}

		WP_CLI::log( sprintf( '%-25s | %-12s | %-12s | %-12s | %s', 'Language', 'Terms', 'Pages', 'Products', 'Templates' ) );
		WP_CLI::log( str_repeat( '-', 80 ) );
		foreach ( $rows as $r ) {
			WP_CLI::log( sprintf( '%-25s | %-12s | %-12s | %-12s | %s', $r['Language'], $r['Terms'], $r['Pages'], $r['Products'], $r['Templates'] ) );
		}
	}
}
