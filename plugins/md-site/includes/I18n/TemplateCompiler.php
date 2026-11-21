<?php
/**
 * Deterministic offline compiler and drift checker for localized LiveCanvas templates.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

use RuntimeException;

/**
 * Compiles localized LiveCanvas templates and partials purely offline from the
 * git-tracked per-language translation memory files (`content/i18n/tm-<lang>.json`)
 * and audits both TM coverage and runtime `TemplateStore` SHA-256 integrity.
 */
final class TemplateCompiler {

	/**
	 * Resolves the default `content/` directory of the md-site plugin.
	 */
	public static function default_content_dir(): string {
		return dirname( __DIR__, 2 ) . '/content';
	}

	/**
	 * Resolves the path to a language's git-tracked template TM JSON file.
	 *
	 * @param string      $lang        Canonical target language code.
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 */
	public static function tm_file_path( string $lang, ?string $content_dir = null ): string {
		$canonical = Languages::from_wpml( $lang );
		$root      = rtrim( $content_dir ?? self::default_content_dir(), '/\\' );
		return $root . '/i18n/tm-' . $canonical . '.json';
	}

	/**
	 * Discovers all non-empty LiveCanvas template and partial source files in deterministic order.
	 *
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 * @return array<string, array{kind: string, key: string, slug: string, path: string, html: string, src_hash: string}>
	 */
	public static function template_files( ?string $content_dir = null ): array {
		$root     = rtrim( $content_dir ?? self::default_content_dir(), '/\\' );
		$base_dir = $root . '/livecanvas';
		if ( ! is_dir( $base_dir ) ) {
			return array();
		}

		$items = array();

		$tpl_glob = glob( $base_dir . '/*.html' );
		$tpl_list = false === $tpl_glob ? array() : $tpl_glob;
		sort( $tpl_list, SORT_STRING );

		foreach ( $tpl_list as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$en_html = (string) file_get_contents( $file );
			if ( '' === trim( $en_html ) ) {
				continue;
			}
			$slug          = basename( $file, '.html' );
			$key           = 'tpl:' . $slug;
			$items[ $key ] = array(
				'kind'     => 'template',
				'key'      => $key,
				'slug'     => $slug,
				'path'     => $file,
				'html'     => $en_html,
				'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			);
		}

		$part_glob = glob( $base_dir . '/partials/*.html' );
		$part_list = false === $part_glob ? array() : $part_glob;
		sort( $part_list, SORT_STRING );

		foreach ( $part_list as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$en_html = (string) file_get_contents( $file );
			if ( '' === trim( $en_html ) ) {
				continue;
			}
			$slug          = basename( $file, '.html' );
			$key           = 'part:' . $slug;
			$items[ $key ] = array(
				'kind'     => 'partial',
				'key'      => $key,
				'slug'     => $slug,
				'path'     => $file,
				'html'     => $en_html,
				'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			);
		}

		ksort( $items, SORT_STRING );
		return $items;
	}

	/**
	 * Walks `<template ...>...</template>` elements using HtmlTokenizer and applies
	 * a transformation callback to each top-level template's inner HTML losslessly.
	 *
	 * @param string   $html          Source HTML.
	 * @param callable $inner_handler `fn(string $inner_html): string` callback.
	 */
	public static function transform_template_blocks( string $html, callable $inner_handler ): string {
		if ( false === stripos( $html, '<template' ) ) {
			return $html;
		}

		$tokenizer      = new HtmlTokenizer();
		$tokens         = $tokenizer->tokens( $html );
		$out            = '';
		$template_depth = 0;
		$open_tag       = '';
		$inner_buf      = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TAG === $token['type'] && 'template' === $token['name'] ) {
				$self_closing = ! $token['closing'] && str_ends_with( rtrim( substr( $token['raw'], 0, -1 ) ), '/' );
				if ( ! $token['closing'] && ! $self_closing ) {
					if ( 0 === $template_depth ) {
						$open_tag       = $token['raw'];
						$inner_buf      = '';
						$template_depth = 1;
					} else {
						++$template_depth;
						$inner_buf .= $token['raw'];
					}
					continue;
				}

				if ( $token['closing'] ) {
					if ( $template_depth > 1 ) {
						--$template_depth;
						$inner_buf .= $token['raw'];
					} elseif ( 1 === $template_depth ) {
						$template_depth = 0;
						$transformed    = (string) $inner_handler( $inner_buf );
						$out           .= $open_tag . $transformed . $token['raw'];
						$open_tag       = '';
						$inner_buf      = '';
					} else {
						$out .= $token['raw'];
					}
					continue;
				}
			}

			if ( $template_depth > 0 ) {
				$inner_buf .= $token['raw'];
			} else {
				$out .= $token['raw'];
			}
		}

		if ( $template_depth > 0 ) {
			$out .= $open_tag . $inner_buf;
		}

		return $out;
	}

	/**
	 * Extracts all unique translatable masked segments from an HTML document,
	 * including inner markup of `<template>` blocks.
	 *
	 * @param string         $html      Source English HTML.
	 * @param Segmenter|null $segmenter Optional Segmenter instance.
	 * @return list<string>
	 */
	public static function extract_segments( string $html, ?Segmenter $segmenter = null ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$seg  = $segmenter ?? new Segmenter();
		$seen = array();

		$collect = null;
		$collect = static function ( string $fragment ) use ( &$collect, $seg, &$seen ): void {
			if ( '' === trim( $fragment ) ) {
				return;
			}
			self::transform_template_blocks(
				$fragment,
				static function ( string $inner ) use ( &$collect ): string {
					$collect( $inner );
					return $inner;
				}
			);
			$doc = $seg->segment_html( $fragment );
			foreach ( $doc['segments'] as $item ) {
				$text = $item['text'];
				if ( '' !== trim( $text ) && 1 === preg_match( '/\p{L}/u', TranslationValidator::strip_placeholders( $text ) ) ) {
					$seen[ $text ] = true;
				}
			}
		};

		$collect( $html );
		return array_keys( $seen );
	}

	/**
	 * Loads a language's git-tracked template TM map (`sha256(segment) => translation`).
	 *
	 * @param string      $lang        Canonical target language code.
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 * @return array<string, string>
	 */
	public static function load_tm( string $lang, ?string $content_dir = null ): array {
		$path = self::tm_file_path( $lang, $content_dir );
		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bytes = file_get_contents( $path );
		if ( false === $bytes ) {
			return array();
		}

		$decoded = json_decode( $bytes, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$clean = array();
		foreach ( $decoded as $hash => $translation ) {
			if ( is_string( $hash ) && is_string( $translation ) ) {
				$clean[ $hash ] = $translation;
			}
		}

		return $clean;
	}

	/**
	 * Persists a language's template TM map deterministically (sorted keys, pretty JSON).
	 *
	 * @param string                $lang        Canonical target language code.
	 * @param array<string, string> $tm          Map of `sha256(segment) => translation`.
	 * @param string|null           $content_dir Optional custom `content/` root for tests.
	 * @throws RuntimeException When the file cannot be written.
	 */
	public static function save_tm( string $lang, array $tm, ?string $content_dir = null ): void {
		$path = self::tm_file_path( $lang, $content_dir );
		$dir  = dirname( $path );

		// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( sprintf( 'Cannot create template TM directory: %s', $dir ) );
		}

		ksort( $tm, SORT_STRING );

		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
		$json  = json_encode( $tm, $flags );
		if ( false === $json ) {
			throw new RuntimeException( 'Failed to JSON-encode template TM map.' );
		}
		$json .= "\n";

		$temp = $path . '.tmp.' . uniqid( '', true );
		if ( false === @file_put_contents( $temp, $json, LOCK_EX ) || ! @rename( $temp, $path ) ) {
			@unlink( $temp );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( sprintf( 'Failed to write template TM file: %s', $path ) );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Audits git-tracked `tm-<lang>.json` coverage against every translatable segment
	 * in `content/livecanvas/` across the requested target languages.
	 *
	 * @param list<string>|null $langs       Target canonical language codes (defaults to all targets).
	 * @param string|null       $content_dir Optional custom `content/` root for tests.
	 * @return array<string, list<array{key: string, hash: string, segment: string}>> Missing entries by language (empty when 100% covered).
	 * @throws RuntimeException When no LiveCanvas template source files exist.
	 */
	public static function check_tm_coverage( ?array $langs = null, ?string $content_dir = null ): array {
		$targets = null !== $langs ? array_map( array( Languages::class, 'from_wpml' ), $langs ) : Languages::TARGETS;
		$files   = self::template_files( $content_dir );
		if ( array() === $files ) {
			throw new RuntimeException( 'No LiveCanvas template source files found to check.' );
		}
		$segmenter = new Segmenter();

		// Extract segments per template once across all languages.
		$file_segments = array();
		foreach ( $files as $key => $info ) {
			$file_segments[ $key ] = self::extract_segments( $info['html'], $segmenter );
		}

		$missing = array();
		foreach ( $targets as $lang ) {
			if ( Languages::SOURCE === $lang ) {
				continue;
			}
			$tm           = self::load_tm( $lang, $content_dir );
			$lang_missing = array();
			$seen_hashes  = array();

			foreach ( $file_segments as $key => $segments ) {
				foreach ( $segments as $segment ) {
					$hash = hash( 'sha256', $segment );
					if ( isset( $seen_hashes[ $hash ] ) ) {
						continue;
					}
					if ( ! isset( $tm[ $hash ] ) || '' === trim( $tm[ $hash ] ) ) {
						$seen_hashes[ $hash ] = true;
						$lang_missing[]       = array(
							'key'     => $key,
							'hash'    => $hash,
							'segment' => $segment,
						);
					}
				}
			}

			if ( array() !== $lang_missing ) {
				$missing[ $lang ] = $lang_missing;
			}
		}

		return $missing;
	}

	/**
	 * Compiles a single English HTML document offline from a per-language TM map.
	 *
	 * @param string                $en_html      Source English HTML.
	 * @param string                $lang         Target canonical language code.
	 * @param array<string, string> $tm           Per-language TM map (`sha256(segment) => translation`).
	 * @param Segmenter|null        $segmenter    Optional Segmenter instance.
	 * @param string|null           $partial_slug Optional partial slug (e.g. `'header'`).
	 * @param string                $context_key  Template/partial key for diagnostic messages.
	 * @throws RuntimeException When any translatable segment is missing from `$tm`.
	 */
	public static function compile_html(
		string $en_html,
		string $lang,
		array $tm,
		?Segmenter $segmenter = null,
		?string $partial_slug = null,
		string $context_key = ''
	): string {
		$target = Languages::from_wpml( $lang );
		if ( Languages::SOURCE === $target ) {
			return $en_html;
		}

		$seg      = $segmenter ?? new Segmenter();
		$compiled = self::compile_fragment( $en_html, $target, $tm, $seg, $context_key );

		if ( 'header' === $partial_slug ) {
			$compiled = TemplateStore::sync_header_mega_menu_labels( $compiled );
		}
		if ( 'zh' === $target ) {
			$compiled = TemplateStore::sanitize_zh_glossary_html( $compiled );
		}
		if ( Languages::is_rtl( $target ) ) {
			$compiled = TemplateStore::mirror_rtl_arrows_html( $compiled );
		}

		return $compiled;
	}

	/**
	 * Recursively compiles an HTML fragment (including nested `<template>` blocks) from `$tm`.
	 *
	 * @param string                $html        Source HTML fragment.
	 * @param string                $target      Target canonical language code.
	 * @param array<string, string> $tm          Per-language TM map.
	 * @param Segmenter             $segmenter   Segmenter instance.
	 * @param string                $context_key Context key for error messages.
	 * @throws RuntimeException When any translatable segment is missing from `$tm`.
	 */
	private static function compile_fragment(
		string $html,
		string $target,
		array $tm,
		Segmenter $segmenter,
		string $context_key
	): string {
		$html = self::transform_template_blocks(
			$html,
			static fn( string $inner ): string => self::compile_fragment( $inner, $target, $tm, $segmenter, $context_key )
		);

		$doc = $segmenter->segment_html( $html );
		if ( empty( $doc['segments'] ) ) {
			return $html;
		}

		$translated_list = array();
		foreach ( $doc['segments'] as $seg ) {
			$src_text = $seg['text'];
			if ( '' === trim( $src_text ) || 1 !== preg_match( '/\p{L}/u', TranslationValidator::strip_placeholders( $src_text ) ) ) {
				$translated_list[] = $src_text;
				continue;
			}

			$hash = hash( 'sha256', $src_text );
			if ( ! isset( $tm[ $hash ] ) || '' === trim( $tm[ $hash ] ) ) {
				$snippet = mb_substr( $src_text, 0, 80 );
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException(
					sprintf(
						'Missing template TM entry for [%s] %s (sha256:%s): "%s". Run `wp md-site i18n translate --only=templates` locally and commit content/i18n/tm-%s.json.',
						$target,
						'' !== $context_key ? $context_key : 'fragment',
						substr( $hash, 0, 12 ),
						$snippet,
						$target
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$tr                = $tm[ $hash ];
			$translated_list[] = 'zh' === $target ? TemplateStore::sanitize_zh_glossary_text( $tr ) : $tr;
		}

		return $segmenter->reassemble( $doc, $translated_list );
	}

	/**
	 * Compiles all LiveCanvas templates and partials offline from `content/i18n/tm-<lang>.json`
	 * into `TemplateStore` (`wp-content/uploads/md-i18n/templates-<lang>.json` + `wp_options`).
	 *
	 * @param string|null $target_lang Optional canonical language code (null for all targets).
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 * @return array{languages: int, entries: int, updated_languages: int}
	 * @throws RuntimeException When any template TM file or segment is missing.
	 */
	public static function compile_all( ?string $target_lang = null, ?string $content_dir = null ): array {
		$targets = null !== $target_lang && 'all' !== $target_lang
			? array( Languages::from_wpml( $target_lang ) )
			: Languages::TARGETS;

		$files = self::template_files( $content_dir );
		if ( array() === $files ) {
			throw new RuntimeException( 'No LiveCanvas template source files found to compile.' );
		}

		$segmenter       = new Segmenter();
		$compiled_stores = array();
		$total_entries   = 0;

		// Compile every requested language in memory first so a missing TM entry never
		// leaves a partially updated subset of languages persisted on disk/options.
		foreach ( $targets as $lang ) {
			if ( Languages::SOURCE === $lang ) {
				continue;
			}
			$tm = self::load_tm( $lang, $content_dir );
			if ( array() === $tm ) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException(
					sprintf( 'Template TM file is missing or empty: %s', self::tm_file_path( $lang, $content_dir ) )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			$new_store = array();
			foreach ( $files as $key => $info ) {
				$partial_slug      = 'partial' === $info['kind'] ? $info['slug'] : null;
				$new_store[ $key ] = array(
					'src_hash' => $info['src_hash'],
					'html'     => self::compile_html(
						$info['html'],
						$lang,
						$tm,
						$segmenter,
						$partial_slug,
						$key
					),
				);
			}

			$compiled_stores[ $lang ] = $new_store;
			$total_entries           += count( $new_store );
		}

		TemplateStore::forget();
		$updated_languages = 0;
		foreach ( $compiled_stores as $lang => $new_store ) {
			if ( TemplateStore::replace_store( $lang, $new_store ) ) {
				++$updated_languages;
			}
		}

		return array(
			'languages'         => count( $compiled_stores ),
			'entries'           => $total_entries,
			'updated_languages' => $updated_languages,
		);
	}

	/**
	 * Read-only audit of persisted `TemplateStore` entries against the LiveCanvas
	 * source files' SHA-256 hashes (and installed DB template posts when running
	 * inside WordPress) across target languages.
	 *
	 * @param string|null $target_lang Optional canonical language code (null for all targets).
	 * @param string|null $content_dir Optional custom `content/` root for tests.
	 * @return list<array{lang: string, key: string, reason: string}>
	 */
	public static function check_store_drift( ?string $target_lang = null, ?string $content_dir = null ): array {
		$targets = null !== $target_lang && 'all' !== $target_lang
			? array( Languages::from_wpml( $target_lang ) )
			: Languages::TARGETS;

		$files = self::template_files( $content_dir );
		if ( array() === $files ) {
			return array(
				array(
					'lang'   => 'all',
					'key'    => 'source',
					'reason' => 'no_template_source_files',
				),
			);
		}

		$db_hashes = null === $content_dir ? self::installed_db_hashes() : array();
		TemplateStore::forget();

		$issues = array();
		foreach ( $targets as $lang ) {
			if ( Languages::SOURCE === $lang ) {
				continue;
			}
			$store = TemplateStore::load( $lang );
			foreach ( $files as $key => $info ) {
				$entry = $store[ $key ] ?? null;
				if ( null === $entry ) {
					$issues[] = array(
						'lang'   => $lang,
						'key'    => $key,
						'reason' => 'missing_entry',
					);
					continue;
				}
				if ( empty( $entry['html'] ) ) {
					$issues[] = array(
						'lang'   => $lang,
						'key'    => $key,
						'reason' => 'empty_html',
					);
					continue;
				}
				if ( $entry['src_hash'] !== $info['src_hash'] ) {
					$issues[] = array(
						'lang'   => $lang,
						'key'    => $key,
						'reason' => 'src_hash_mismatch',
					);
					continue;
				}
				if ( isset( $db_hashes[ $key ] ) && $entry['src_hash'] !== $db_hashes[ $key ] ) {
					$issues[] = array(
						'lang'   => $lang,
						'key'    => $key,
						'reason' => 'db_src_hash_mismatch',
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Reads live `lc_dynamic_template` and `lc_partial` post_content hashes from WordPress
	 * so drift between DB posts (what LiveCanvasLocalizer hashes at request time) and
	 * `TemplateStore` is caught even when files on disk match.
	 *
	 * @return array<string, string>
	 */
	private static function installed_db_hashes(): array {
		if (
			! isset( $GLOBALS['wpdb'] ) ||
			! $GLOBALS['wpdb'] instanceof \wpdb ||
			! \function_exists( 'get_posts' ) ||
			! \function_exists( 'get_post_meta' )
		) {
			return array();
		}

		$hashes          = array();
		$page_type_files = array(
			'is_single_product'              => 'pdp',
			'is_archive_for_tax_product_cat' => 'series-archive',
			'is_shop_page'                   => 'shop',
			'is_blog_posts_index'            => 'blog-index',
			'is_single_post'                 => 'blog-single',
			'is_archive_for_tax_category'    => 'blog-category',
			'is_search'                      => 'blog-search',
		);

		$templates = \get_posts(
			array(
				'post_type'        => 'lc_dynamic_template',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		foreach ( $templates as $post ) {
			$file_key = null;
			foreach ( $page_type_files as $meta_key => $mapped ) {
				$val = \get_post_meta( (int) $post->ID, $meta_key, true );
				if ( '1' === (string) $val || 1 === $val || true === $val ) {
					$file_key = $mapped;
					break;
				}
			}
			if ( null === $file_key ) {
				$post_name = (string) $post->post_name;
				$file_key  = str_starts_with( $post_name, 'md-' ) ? substr( $post_name, 3 ) : $post_name;
			}
			$hashes[ 'tpl:' . $file_key ] = hash( 'sha256', rtrim( (string) $post->post_content, "\n" ) );
		}

		$partials = \get_posts(
			array(
				'post_type'        => 'lc_partial',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
			)
		);
		foreach ( $partials as $post ) {
			$hashes[ 'part:' . (string) $post->post_name ] = hash( 'sha256', rtrim( (string) $post->post_content, "\n" ) );
		}

		return $hashes;
	}
}
