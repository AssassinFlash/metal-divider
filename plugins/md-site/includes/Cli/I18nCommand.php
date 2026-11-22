<?php
/**
 * WP-CLI commands for 9-language I18n setup, status, and translation pipelines.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Cli;

use MetalDivider\Site\I18n\HtmlTokenizer;
use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\I18n\Setup;
use MetalDivider\Site\I18n\TemplateCompiler;
use MetalDivider\Site\I18n\TemplateStore;
use MetalDivider\Site\I18n\Translator;
use MetalDivider\Site\Seo\SitemapLastmod;
use WP_CLI;
use WP_Post;

/**
 * Manages the programmatic setup, validation, status, and translation of
 * metal-divider.com's nine supported languages.
 */
final class I18nCommand {

	/**
	 * Optional custom `content/` directory root (used by tests).
	 *
	 * @var string|null
	 */
	private ?string $content_dir;

	/**
	 * Initializer.
	 *
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 */
	public function __construct( ?string $content_dir = null ) {
		$this->content_dir = $content_dir;
	}

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
	 * [--write-repo-tm]
	 * : Also export updated template segments back to git-tracked `content/i18n/tm-<lang>.json`
	 *   (local development workflow only; omitted by default so deployed hosts stay read-only).
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
		$write_repo_tm = isset( $assoc_args['write-repo-tm'] );
		$tm_cache_path = isset( $assoc_args['tm-cache'] ) ? (string) $assoc_args['tm-cache'] : null;

		$translator = new Translator( null, null, null, $tm_cache_path, $this->content_dir, $write_repo_tm );

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
	 * Compiles localized LiveCanvas templates and partials purely offline from
	 * git-tracked `content/i18n/tm-<lang>.json` into `uploads/md-i18n/` and `wp_options`.
	 *
	 * Fails closed if any translatable segment is missing from the template TM.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<code|all>]
	 * : Target language canonical code or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * @subcommand compile-templates
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function compile_templates( array $args, array $assoc_args ): void {
		unset( $args );
		$lang = (string) ( $assoc_args['lang'] ?? 'all' );

		try {
			$report = TemplateCompiler::compile_all(
				'all' === $lang ? null : $lang,
				$this->content_dir
			);

			if ( \function_exists( 'wp_cache_flush' ) ) {
				\wp_cache_flush();
			}

			$lastmod = SitemapLastmod::sync_post_modified_from_templates();
			WP_CLI::log(
				sprintf(
					'sitemap lastmod: %d pages updated, %d products updated, %d transients cleared',
					$lastmod['pages_updated'],
					$lastmod['products_updated'],
					$lastmod['transients_cleared']
				)
			);

			WP_CLI::success(
				sprintf(
					'Compiled %d template/partial entries across %d language(s) (%d updated).',
					$report['entries'],
					$report['languages'],
					$report['updated_languages']
				)
			);
		} catch ( \Throwable $e ) {
			WP_CLI::error( 'Template compilation failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Read-only audit of template TM coverage and live `TemplateStore` SHA-256 integrity.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<code|all>]
	 * : Target language canonical code or all.
	 * ---
	 * default: all
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table. `json` prints one JSON array
	 * (`[]` on pass) on stdout and exits non-zero when any drift or missing TM exists.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--source-only]
	 * : Only audit repository `content/i18n/tm-<lang>.json` coverage without checking live `TemplateStore`.
	 *
	 * @subcommand check-templates
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function check_templates( array $args, array $assoc_args ): void {
		unset( $args );
		$lang        = (string) ( $assoc_args['lang'] ?? 'all' );
		$format      = (string) ( $assoc_args['format'] ?? 'table' );
		$source_only = isset( $assoc_args['source-only'] );
		$lang_list   = 'all' === $lang ? null : array( Languages::from_wpml( $lang ) );

		$issues = array();

		$tm_missing = TemplateCompiler::check_tm_coverage( $lang_list, $this->content_dir );
		foreach ( $tm_missing as $code => $items ) {
			foreach ( $items as $item ) {
				$issues[] = array(
					'kind'   => 'tm_missing',
					'lang'   => $code,
					'key'    => $item['key'],
					'reason' => 'missing_tm:' . substr( $item['hash'], 0, 12 ),
				);
			}
		}

		if ( ! $source_only ) {
			$store_drift = TemplateCompiler::check_store_drift(
				'all' === $lang ? null : $lang,
				$this->content_dir
			);
			foreach ( $store_drift as $drift ) {
				$issues[] = array(
					'kind'   => 'store_drift',
					'lang'   => $drift['lang'],
					'key'    => $drift['key'],
					'reason' => $drift['reason'],
				);
			}
		}

		if ( 'json' === $format ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
			try {
				$encoded = \function_exists( 'wp_json_encode' )
					? (string) \wp_json_encode( $issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
					: (string) json_encode( $issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( \Throwable ) {
				$encoded = (string) json_encode( $issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
			WP_CLI::log( $encoded );
			if ( array() !== $issues ) {
				WP_CLI::error( sprintf( 'i18n templates: %d issue(s) detected.', count( $issues ) ) );
			}
			return;
		}

		if ( array() === $issues ) {
			$scope = $source_only ? 'TM coverage' : 'TM coverage and TemplateStore src_hash';
			WP_CLI::success( sprintf( 'i18n templates: 0 drift (%s verified).', $scope ) );
			return;
		}

		foreach ( $issues as $issue ) {
			WP_CLI::log( sprintf( '[%s] %s %s (%s)', $issue['kind'], $issue['lang'], $issue['key'], $issue['reason'] ) );
		}
		WP_CLI::error( sprintf( 'i18n templates: %d issue(s) detected.', count( $issues ) ) );
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

	/**
	 * Sanitizes third-party brand references ("Decorative Screens Direct" / "DSD"),
	 * duplicate paragraphs, and Simplified Chinese banned glossary terms ("订单" / "产品")
	 * across all 9 languages in posts, postmeta, and stored templates without touching post slugs.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview the number of posts, meta rows, and templates that would be updated without writing.
	 *
	 * @subcommand sanitize-content
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative flags.
	 */
	public function sanitize_content( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = isset( $assoc_args['dry-run'] );

		$posts          = $this->load_all_language_posts();
		$updated_posts  = 0;
		$updated_metas  = 0;
		$meta_text_keys = array(
			'_sku',
			'summary',
			'inspiration',
			'notable_effect',
			'_seopress_titles_title',
			'_seopress_titles_desc',
		);

		global $wpdb;

		foreach ( $posts as $post ) {
			$post_id   = (int) $post->ID;
			$post_type = (string) $post->post_type;
			$lang      = $this->detect_post_language( $post_id, $post_type );

			$orig_title   = (string) $post->post_title;
			$orig_content = (string) $post->post_content;
			$orig_excerpt = (string) $post->post_excerpt;

			$new_title   = self::sanitize_brand_title( $orig_title );
			$new_content = self::sanitize_post_html( $orig_content, $lang );
			$new_excerpt = self::sanitize_post_plain_text( $orig_excerpt, $lang );

			if ( 'zh' === $lang ) {
				$new_title = TemplateStore::sanitize_zh_glossary_text( $new_title );
			}

			$changed_fields = array();
			if ( $new_title !== $orig_title ) {
				$changed_fields['post_title'] = $new_title;
			}
			if ( $new_content !== $orig_content ) {
				$changed_fields['post_content'] = $new_content;
			}
			if ( $new_excerpt !== $orig_excerpt ) {
				$changed_fields['post_excerpt'] = $new_excerpt;
			}

			if ( array() !== $changed_fields ) {
				++$updated_posts;
				if ( ! $dry_run ) {
					if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->posts ) {
						// Direct update modifies ONLY title/content/excerpt — post_name slug is untouched.
						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$wpdb->posts,
							$changed_fields,
							array( 'ID' => $post_id )
						);
						if ( \function_exists( 'clean_post_cache' ) ) {
							\clean_post_cache( $post_id );
						}
					} elseif ( \function_exists( 'wp_update_post' ) ) {
						$changed_fields['ID'] = $post_id;
						\wp_update_post( \wp_slash( $changed_fields ) );
					}
				}
			}

			if ( ! \function_exists( 'get_post_meta' ) || ! \function_exists( 'update_post_meta' ) ) {
				continue;
			}

			foreach ( $meta_text_keys as $meta_key ) {
				$raw_meta = \get_post_meta( $post_id, $meta_key, true );
				if ( ! is_string( $raw_meta ) || '' === $raw_meta ) {
					continue;
				}

				$clean_meta = '_sku' === $meta_key
					? self::sanitize_brand_title( $raw_meta )
					: self::sanitize_post_plain_text( $raw_meta, $lang );

				if ( $clean_meta !== $raw_meta ) {
					++$updated_metas;
					if ( ! $dry_run ) {
						\update_post_meta( $post_id, $meta_key, \function_exists( 'wp_slash' ) ? \wp_slash( $clean_meta ) : $clean_meta );
					}
				}
			}
		}

		$updated_templates = 0;
		foreach ( Languages::TARGETS as $target_lang ) {
			$updated_templates += TemplateStore::sanitize_stored_templates(
				$target_lang,
				$dry_run,
				static fn( string $html, string $code ): string => self::sanitize_brand_html( $html, $code )
			);
		}

		if ( ! $dry_run ) {
			TemplateStore::forget();
			if ( \function_exists( 'wp_cache_flush' ) ) {
				\wp_cache_flush();
			}
		}

		$prefix = $dry_run ? '[DRY RUN] Would sanitize' : 'Sanitized';
		WP_CLI::success(
			sprintf(
				'%s %d post(s), %d postmeta row(s), and %d stored template/partial entry(ies).',
				$prefix,
				$updated_posts,
				$updated_metas,
				$updated_templates
			)
		);
	}

	/**
	 * Strips third-party brand suffix "Decorative Screens Direct" from titles and SKUs.
	 *
	 * @param string $value Raw title or SKU.
	 */
	public static function sanitize_brand_title( string $value ): string {
		if ( '' === $value || false === stripos( $value, 'Decorative' ) ) {
			return $value;
		}

		$cleaned = (string) preg_replace( '/\s+Decorative\s+Screens\s+Direct\b/iu', '', $value );
		$cleaned = (string) preg_replace( '/\bDecorative\s+Screens\s+Direct\s*/iu', '', $cleaned );
		$cleaned = (string) preg_replace( '/ {2,}/u', ' ', $cleaned );
		return trim( $cleaned );
	}

	/**
	 * Deduplicates consecutive identical `<p>...</p>` blocks in product HTML descriptions.
	 *
	 * @param string $html Input HTML.
	 */
	public static function dedupe_identical_paragraphs( string $html ): string {
		if ( '' === $html || false === stripos( $html, '<p' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/(<p\b[^>]*>.*?<\/p>)(?:\s*\1)+/isu',
			static fn( array $m ): string => $m[1],
			$html
		);
	}

	/**
	 * Sanitizes HTML post content (deduplicating identical paragraphs, removing third-party
	 * brand names across all 9 languages, and applying Chinese glossary rules for `zh`).
	 *
	 * @param string $html Input HTML.
	 * @param string $lang Canonical language code.
	 */
	public static function sanitize_post_html( string $html, string $lang ): string {
		if ( '' === $html ) {
			return $html;
		}

		$out = self::dedupe_identical_paragraphs( $html );
		$out = self::sanitize_brand_html( $out, $lang );

		if ( 'zh' === $lang ) {
			$out = TemplateStore::sanitize_zh_glossary_html( $out );
		}

		return $out;
	}

	/**
	 * Sanitizes plain-text post fields (excerpt, ACF summary, SEO title/desc).
	 *
	 * @param string $text Input plain text.
	 * @param string $lang Canonical language code.
	 */
	public static function sanitize_post_plain_text( string $text, string $lang ): string {
		if ( '' === $text ) {
			return $text;
		}

		$out = self::sanitize_brand_text( $text, $lang );
		if ( 'zh' === $lang ) {
			$out = TemplateStore::sanitize_zh_glossary_text( $out );
		}

		return $out;
	}

	/**
	 * Sanitizes third-party brand references inside HTML text nodes and translatable attributes.
	 *
	 * @param string $html Input HTML.
	 * @param string $lang Canonical language code.
	 */
	public static function sanitize_brand_html( string $html, string $lang ): string {
		if (
			'' === $html ||
			(
				false === stripos( $html, 'Decorative Screens Direct' ) &&
				false === stripos( $html, 'DSD' ) &&
				false === stripos( $html, 'adds a different appeal' )
			)
		) {
			return $html;
		}

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );
		$out       = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] ) {
				$out .= self::sanitize_brand_text( $token['raw'], $lang );
				continue;
			}

			if ( HtmlTokenizer::TYPE_TAG === $token['type'] && ! $token['closing'] ) {
				$tag_raw = $token['raw'];
				foreach ( array( 'alt', 'title', 'aria-label' ) as $attr ) {
					$val = HtmlTokenizer::attribute( $tag_raw, $attr );
					if ( null !== $val && '' !== $val ) {
						$clean = self::sanitize_brand_text( $val, $lang );
						if ( $clean !== $val ) {
							$tag_raw = HtmlTokenizer::with_attribute( $tag_raw, $attr, $clean );
						}
					}
				}
				$out .= $tag_raw;
				continue;
			}

			$out .= $token['raw'];
		}

		return $out;
	}

	/**
	 * Replaces third-party brand terms ("Decorative Screens Direct", "DSD") and
	 * known source typos with clean first-party phrasing across all 9 languages.
	 *
	 * @param string $text Plain text or HTML text node.
	 * @param string $lang Canonical language code.
	 */
	public static function sanitize_brand_text( string $text, string $lang ): string {
		if (
			'' === $text ||
			(
				false === stripos( $text, 'Decorative Screens Direct' ) &&
				false === stripos( $text, 'DSD' ) &&
				false === stripos( $text, 'adds a different appeal' )
			)
		) {
			return $text;
		}

		$out = $text;

		// 1. Strip "Decorative Screens Direct" in any language context.
		$out = (string) preg_replace( '/Square\s+Rage\s+Decorative\s+Screens\s+Direct\s+design/iu', 'Square Rage design', $out );
		$out = (string) preg_replace( '/Square\s+Rage\s+Decorative\s+Screens\s+Direct\s*设计/u', 'Square Rage 设计', $out );
		$out = (string) preg_replace( '/\s+Decorative\s+Screens\s+Direct\b/iu', '', $out );
		$out = (string) preg_replace( '/\bDecorative\s+Screens\s+Direct\s+/iu', '', $out );
		$out = (string) preg_replace( '/\bDecorative\s+Screens\s+Direct\b/iu', '', $out );

		// 2. Fix English source duplicate-verb typo on Skyfall Gradient.
		$out = str_ireplace(
			'adds a sense of movement adds a different appeal',
			'adds a sense of movement and a distinct appeal',
			$out
		);

		// 3. Language-specific natural phrasing for DSD references.
		$target = Languages::from_wpml( $lang );

		// Always run English patterns first (handles English source and any untranslated fallback).
		$out = (string) preg_replace( '/\bAs\s+part\s+of\s+DSD(?:\x{2019}s|\'s)\s+/u', 'As part of our ', $out );
		$out = (string) preg_replace( '/\bas\s+part\s+of\s+DSD(?:\x{2019}s|\'s)\s+/iu', 'as part of our ', $out );
		$out = (string) preg_replace( '/\bfrom\s+DSD(?:\x{2019}s|\'s)\s+/iu', 'from our ', $out );
		$out = (string) preg_replace( '/\bone\s+of\s+DSD(?:\x{2019}s|\'s)\s+/iu', 'one of our ', $out );
		$out = (string) preg_replace( '/\bsimilar\s+to\s+DSD(?:\x{2019}s|\'s)\s+/iu', 'similar to our ', $out );
		$out = (string) preg_replace( '/,\s*DSD\s+provides\s+/iu', '; we provide ', $out );
		$out = (string) preg_replace( '/\bDSD\s+provides\s+/u', 'We provide ', $out );
		$out = (string) preg_replace_callback(
			'/(^|[.!?]\s+)DSD(?:\x{2019}s|\'s)\s+screens\b/u',
			static fn( array $m ): string => $m[1] . 'Our screens',
			$out
		);
		$out = (string) preg_replace( '/\bDSD(?:\x{2019}s|\'s)\s+screens\b/iu', 'our screens', $out );
		$out = (string) preg_replace_callback(
			'/(^|[.!?]\s+)DSD(?:\x{2019}s|\'s)\s+/u',
			static fn( array $m ): string => $m[1] . 'Our ',
			$out
		);
		$out = (string) preg_replace( '/\bDSD(?:\x{2019}s|\'s)\s+/iu', 'our ', $out );

		if ( 'zh' === $target ) {
			$zh_map = array(
				'作为 DSD 的'  => '作为我们的',
				'作为DSD的'    => '作为我们的',
				'作为 DSD '   => '作为我们的',
				'作为DSD'     => '作为我们的',
				'出自 DSD 的'  => '出自我们的',
				'出自DSD的'    => '出自我们的',
				'来自 DSD 的'  => '来自我们的',
				'来自DSD的'    => '来自我们的',
				'是 DSD 最'   => '是我们最',
				'是DSD最'     => '是我们最',
				'是 DSD 的'   => '是我们的',
				'是DSD的'     => '是我们的',
				'是 DSD '    => '是我们的',
				'与 DSD 的'   => '与我们的',
				'与DSD的'     => '与我们的',
				'DSD 的屏风'   => '我们的屏风',
				'DSD的屏风'    => '我们的屏风',
				'DSD 的'     => '我们的',
				'DSD的'      => '我们的',
				'，DSD 会提供'  => '，我们会提供',
				', DSD 会提供' => '，我们会提供',
				'，DSD 提供'   => '，我们提供',
				', DSD 提供'  => '，我们提供',
				'DSD 会提供'   => '我们提供',
				'DSD 提供'    => '我们提供',
			);
			$out    = strtr( $out, $zh_map );
			$out    = (string) preg_replace( '/\s*\bDSD\b\s*/u', '我们', $out );
		} elseif ( 'es' === $target ) {
			$out = (string) preg_replace( '/\bLas\s+pantallas\s+de\s+DSD\b/iu', 'Nuestras pantallas', $out );
			$out = (string) preg_replace( '/\bLos\s+paneles\s+de\s+DSD\b/iu', 'Nuestros paneles', $out );
			$out = (string) preg_replace( '/([;,])\s*DSD\s+proporciona\b/iu', '$1 proporcionamos', $out );
			$out = (string) preg_replace( '/\bDSD\s+proporciona\b/iu', 'Proporcionamos', $out );
			$out = (string) preg_replace( '/\s+de\s+DSD\b/iu', '', $out );
		} elseif ( 'pt-br' === $target ) {
			$out = (string) preg_replace( '/\bAs\s+telas\s+da\s+DSD\b/iu', 'Os nossos painéis', $out );
			$out = (string) preg_replace( '/\bOs\s+painéis\s+da\s+DSD\b/iu', 'Os nossos painéis', $out );
			$out = (string) preg_replace( '/([;,])\s*a?\s*DSD\s+fornece\b/iu', '$1 fornecemos', $out );
			$out = (string) preg_replace( '/\b[Aa]?\s*DSD\s+fornece\b/u', 'Fornecemos', $out );
			$out = (string) preg_replace( '/\s+d[aeo]\s+DSD\b/iu', '', $out );
		} elseif ( 'de' === $target ) {
			$out = (string) preg_replace( '/\bDie\s+(?:Screens|Paneele|Sichtschutzwände|Schirme)\s+von\s+DSD\b/u', 'Unsere Paneele', $out );
			$out = (string) preg_replace( '/([–—,;])\s*DSD\s+stellt\b/u', '$1 wir stellen', $out );
			$out = (string) preg_replace( '/\bDSD\s+stellt\b/u', 'Wir stellen', $out );
			$out = (string) preg_replace( '/\s+von\s+DSD\b/iu', '', $out );
			$out = (string) preg_replace( '/\bDSDs\s+/u', 'unsere ', $out );
		} elseif ( 'fr' === $target ) {
			$out = (string) preg_replace( '/\bLes\s+panneaux\s+(?:de\s+)?DSD\b/iu', 'Nos panneaux', $out );
			$out = (string) preg_replace( '/\bLes\s+écrans\s+(?:de\s+)?DSD\b/iu', 'Nos écrans', $out );
			$out = (string) preg_replace( '/\bLes\s+claustras\s+(?:de\s+)?DSD\b/iu', 'Nos claustras', $out );
			$out = (string) preg_replace( '/([:; ,])\s*DSD\s+fournit\b/iu', '$1 nous fournissons', $out );
			$out = (string) preg_replace( '/\bDSD\s+fournit\b/iu', 'Nous fournissons', $out );
			$out = (string) preg_replace( '/\s+de\s+DSD\b/iu', '', $out );
		} elseif ( 'ar' === $target ) {
			$ar_map = array(
				'شاشات DSD'       => 'شاشاتنا',
				'ألواح DSD'       => 'ألواحنا',
				'حيث توفر DSD'    => 'حيث نوفر',
				'توفر DSD'        => 'نوفر',
				'تقدم DSD'        => 'نقدم',
				'مجموعة DSD '     => 'مجموعتنا ',
				'تصاميم DSD '     => 'تصاميمنا ',
				' لدى DSD'        => '',
				' من DSD'         => '',
				' الخاصة بـ DSD'  => '',
				' التابع لـ DSD'  => '',
				' التابعة لـ DSD' => '',
			);
			$out    = strtr( $out, $ar_map );
		} elseif ( 'id' === $target ) {
			$out = (string) preg_replace( '/\b(?:Layar|Panel)\s+DSD\b/iu', 'Panel kami', $out );
			$out = (string) preg_replace( '/([,;])\s*DSD\s+menyediakan\b/iu', '$1 kami menyediakan', $out );
			$out = (string) preg_replace( '/\bDSD\s+menyediakan\b/iu', 'Kami menyediakan', $out );
			$out = (string) preg_replace( '/\s+(?:dari|milik)\s+DSD\b/iu', '', $out );
		} elseif ( 'vi' === $target ) {
			$out = (string) preg_replace( '/\b(?:Các\s+tấm\s+chắn|Màn\s+hình|Tấm\s+vách\s+ngăn)\s+của\s+DSD\b/iu', 'Các tấm vách ngăn của chúng tôi', $out );
			$out = (string) preg_replace( '/([,;])\s*DSD\s+cung\s+cấp\b/iu', '$1 chúng tôi cung cấp', $out );
			$out = (string) preg_replace( '/\bDSD\s+cung\s+cấp\b/iu', 'Chúng tôi cung cấp', $out );
			$out = (string) preg_replace( '/\s+của\s+DSD\b/iu', ' của chúng tôi', $out );
		}

		// 4. Universal fallback for any remaining standalone "DSD" brand token.
		if ( false !== stripos( $out, 'DSD' ) ) {
			$out = (string) preg_replace( '/\bDSD(?:\x{2019}s|\'s)?\b/u', '', $out );
		}

		// Clean residual spacing before punctuation caused by stripped tokens while keeping leading/trailing newlines.
		$out = (string) preg_replace( '/ {2,}/u', ' ', $out );
		$out = (string) preg_replace( '/ +([,.;:!?])/u', '$1', $out );

		return $out;
	}

	/**
	 * Loads all `product` and `page` posts across all WPML languages without language filtering.
	 *
	 * @return list<WP_Post>
	 */
	private function load_all_language_posts(): array {
		global $wpdb;

		if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->posts && \function_exists( 'get_post' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids   = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'page') AND post_status IN ('publish', 'draft', 'pending', 'private') ORDER BY ID ASC"
			);
			$posts = array();
			foreach ( $ids as $id ) {
				$post = \get_post( (int) $id );
				if ( $post instanceof WP_Post ) {
					$posts[] = $post;
				}
			}
			return $posts;
		}

		if ( \function_exists( 'get_posts' ) ) {
			$rows = \get_posts(
				array(
					'post_type'        => array( 'product', 'page' ),
					'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page'   => -1,
					'suppress_filters' => true,
					'orderby'          => 'ID',
					'order'            => 'ASC',
				)
			);
			return array_values( $rows );
		}

		return array();
	}

	/**
	 * Resolves canonical language code (`en`, `zh`, ...) for a post ID via WPML `sitepress` or `icl_translations`.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type (`product` or `page`).
	 */
	private function detect_post_language( int $post_id, string $post_type ): string {
		$element_type = 'post_' . $post_type;
		$sitepress    = $GLOBALS['sitepress'] ?? null;

		if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_language_details' ) ) {
			$details = $sitepress->get_element_language_details( $post_id, $element_type );
			if ( is_object( $details ) && ! empty( $details->language_code ) ) {
				return Languages::from_wpml( (string) $details->language_code );
			}
		}

		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->prefix ) {
			$table = $wpdb->prefix . 'icl_translations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$code = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT language_code FROM %i WHERE element_id = %d AND element_type = %s LIMIT 1',
					$table,
					$post_id,
					$element_type
				)
			);
			if ( is_string( $code ) ) {
				return Languages::from_wpml( $code );
			}
		}

		return Languages::SOURCE;
	}
}
