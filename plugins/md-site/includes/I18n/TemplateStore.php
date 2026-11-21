<?php
/**
 * Persistent storage and retrieval of pre-rendered translated LiveCanvas templates.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Manages localized template storage in wp-content/uploads/md-i18n/ with
 * SHA-256 drift detection and non-autoloaded option fallbacks.
 */
final class TemplateStore {

	/**
	 * Per-request memo of loaded language stores.
	 *
	 * @var array<string, array<string, array{src_hash: string, html: string}>>
	 */
	private static array $stores = array();

	/**
	 * Optional storage directory override used by unit tests instead of `wp_upload_dir()`.
	 *
	 * @var string|null
	 */
	private static ?string $storage_dir_override = null;

	/**
	 * Context-aware Simplified Chinese glossary replacements enforcing GLOSSARY.md
	 * (never "订单" for order/orders; never "产品" for product/products).
	 *
	 * Ordered from most-specific phrase to general fallback so domain-accurate
	 * B2B architectural terms (项目 / 委托 / 批次 / 图案 / 屏风 / 板材) win.
	 *
	 * @var array<string, string>
	 */
	private const ZH_GLOSSARY_REPLACEMENTS = array(
		'订单会拍照记录'        => '每批定制屏风均会拍照记录',
		'订单会拍照'          => '每批定制屏风均会拍照',
		'在订单离开工厂前'       => '在货品离开工厂前',
		'订单离开工厂'         => '货品离开工厂',
		'订单发出前'          => '装箱发出前',
		'量产采购订单'         => '量产项目委托',
		'下达采购订单'         => '确认定制项目',
		'采购订单'           => '正式委托',
		'小批量订单'          => '小批量项目',
		'批量订单'           => '批量项目',
		'出口订单'           => '出口批次',
		'生产订单'           => '生产批次',
		'混尺寸订单'          => '混尺寸批次',
		'三次订单'           => '三次合作项目',
		'已记录订单'          => '已记录项目',
		'切割订单'           => '切割排产',
		'订单类型'           => '项目类型',
		'订单规模'           => '项目规模',
		'每笔订单'           => '每个项目',
		'您的订单'           => '您的项目',
		'影响您订单'          => '影响您项目',
		'您订单'            => '您的项目',
		'下单板材时收取样品费；下单后' => '申领样板时收取样品费；确认合作后',
		'确认、下单、抵扣费用'     => '确认样板、立项排产、抵扣费用',
		'产品目录图案'         => '目录图案',
		'整个产品目录'         => '整套图案目录',
		'产品目录'           => '图案目录',
		'我们的产品：'         => '我们的屏风方案：',
		'我们的产品'          => '我们的屏风板材',
		'件产品'            => '件屏风成品',
		'指定产品'           => '选定屏风图案',
		'结构级产品'          => '结构级板材',
		'这些产品'           => '这些屏风板材',
		'所有产品均'          => '所有屏风板材均',
		'订单'             => '项目',
		'产品'             => '屏风',
	);

	/**
	 * Translatable HTML attributes inspected during glossary sanitization.
	 */
	private const TRANSLATABLE_ATTRS = array(
		'alt',
		'title',
		'aria-label',
		'placeholder',
	);

	/**
	 * Clears per-request memo for tests.
	 */
	public static function forget(): void {
		self::$stores = array();
	}

	/**
	 * Overrides the filesystem storage directory for isolated unit tests.
	 *
	 * @param string|null $dir Directory path or null to restore `wp_upload_dir()`.
	 */
	public static function set_storage_dir_for_tests( ?string $dir ): void {
		self::$storage_dir_override = null !== $dir ? rtrim( $dir, '/\\' ) : null;
		self::$stores               = array();
	}

	/**
	 * Retrieves localized template content.
	 *
	 * @param string      $file_key      Template key (e.g. 'home', 'pdp').
	 * @param string      $fallback_html English source content.
	 * @param string|null $lang          Canonical language code.
	 */
	public static function get_template( string $file_key, string $fallback_html, ?string $lang = null ): string {
		$target = null !== $lang ? Languages::from_wpml( $lang ) : Languages::current();
		if ( Languages::SOURCE === $target ) {
			return $fallback_html;
		}

		$store = self::load( $target );
		$entry = $store[ 'tpl:' . $file_key ] ?? null;

		$src_hash = hash( 'sha256', rtrim( $fallback_html, "\n" ) );
		if ( null !== $entry && $entry['src_hash'] === $src_hash && ! empty( $entry['html'] ) ) {
			return self::postprocess_output( $entry['html'], $target );
		}

		return self::postprocess_output( $fallback_html, $target );
	}

	/**
	 * Retrieves localized partial content.
	 *
	 * @param string      $slug          Partial slug (e.g. 'md-pdp-faq', 'header').
	 * @param string      $fallback_html English source content.
	 * @param string|null $lang          Canonical language code.
	 */
	public static function get_partial( string $slug, string $fallback_html, ?string $lang = null ): string {
		$target = null !== $lang ? Languages::from_wpml( $lang ) : Languages::current();
		if ( Languages::SOURCE === $target ) {
			return $fallback_html;
		}

		$store = self::load( $target );
		$entry = $store[ 'part:' . $slug ] ?? null;

		$src_hash = hash( 'sha256', rtrim( $fallback_html, "\n" ) );
		if ( null !== $entry && $entry['src_hash'] === $src_hash && ! empty( $entry['html'] ) ) {
			return self::postprocess_output( $entry['html'], $target, $slug );
		}

		return self::postprocess_output( $fallback_html, $target, $slug );
	}

	/**
	 * Applies language-specific output post-processing (Mega Menu label sync,
	 * Chinese glossary normalization, RTL arrow mirroring, and URL prefixing).
	 *
	 * @param string      $html         HTML fragment.
	 * @param string      $lang         Target canonical language code.
	 * @param string|null $partial_slug Optional partial slug.
	 */
	private static function postprocess_output( string $html, string $lang, ?string $partial_slug = null ): string {
		if ( 'header' === $partial_slug ) {
			$html = self::sync_header_mega_menu_labels( $html );
		}
		if ( 'zh' === $lang ) {
			$html = self::sanitize_zh_glossary_html( $html );
		}
		if ( Languages::is_rtl( $lang ) ) {
			$html = self::mirror_rtl_arrows_html( $html );
		}
		return UrlLocalizer::rewrite_html( $html, $lang );
	}

	/**
	 * Purifies Simplified Chinese plain text against GLOSSARY.md banned terms ("订单", "产品").
	 *
	 * @param string $text Input Chinese text.
	 */
	public static function sanitize_zh_glossary_text( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}
		if ( ! str_contains( $text, '订单' ) && ! str_contains( $text, '产品' ) && ! str_contains( $text, '下单' ) ) {
			return $text;
		}
		return strtr( $text, self::ZH_GLOSSARY_REPLACEMENTS );
	}

	/**
	 * Purifies Simplified Chinese HTML text nodes and translatable attributes
	 * against GLOSSARY.md banned terms without altering markup structure, scripts, or comments.
	 *
	 * @param string $html Input Chinese HTML.
	 */
	public static function sanitize_zh_glossary_html( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}
		if ( ! str_contains( $html, '订单' ) && ! str_contains( $html, '产品' ) && ! str_contains( $html, '下单' ) ) {
			return $html;
		}

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );
		$out       = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] ) {
				$out .= self::sanitize_zh_glossary_text( $token['raw'] );
				continue;
			}

			if ( HtmlTokenizer::TYPE_TAG === $token['type'] && ! $token['closing'] ) {
				$tag_raw = $token['raw'];
				foreach ( self::TRANSLATABLE_ATTRS as $attr ) {
					$val = HtmlTokenizer::attribute( $tag_raw, $attr );
					if ( null !== $val && '' !== $val ) {
						$clean = self::sanitize_zh_glossary_text( $val );
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
	 * Mirrors unilateral right-pointing arrows (→ / &rarr;) to left-pointing arrows (←)
	 * inside HTML text nodes for RTL languages (Arabic), preserving attributes, scripts,
	 * comments, and bidirectional hints (← ... →).
	 *
	 * @param string $html Input HTML.
	 */
	public static function mirror_rtl_arrows_html( string $html ): string {
		if (
			'' === $html ||
			(
				! str_contains( $html, '→' ) &&
				false === stripos( $html, '&rarr;' ) &&
				! str_contains( $html, '&#8594;' ) &&
				false === stripos( $html, '&#x2192;' )
			)
		) {
			return $html;
		}

		$tokenizer = new HtmlTokenizer();
		$tokens    = $tokenizer->tokens( $html );
		$out       = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TEXT !== $token['type'] ) {
				$out .= $token['raw'];
				continue;
			}

			$raw = $token['raw'];
			// Preserve bidirectional range hints such as "← اسحب للاستكشاف →".
			if (
				str_contains( $raw, '←' ) ||
				false !== stripos( $raw, '&larr;' ) ||
				str_contains( $raw, '&#8592;' ) ||
				false !== stripos( $raw, '&#x2190;' )
			) {
				$out .= $raw;
				continue;
			}

			$out .= (string) preg_replace( '/(?:\x{2192}|&rarr;|&#8594;|&#x2192;)/iu', '←', $raw );
		}

		return $out;
	}

	/**
	 * Synchronizes any untranslated English Mega Menu link labels inside `<template>`
	 * in the header partial with the localized `.xo-menu-horizontal-v1__sub-menu` labels.
	 *
	 * @param string $html Header partial HTML.
	 */
	public static function sync_header_mega_menu_labels( string $html ): string {
		if ( ! str_contains( $html, '<template' ) || ! str_contains( $html, 'mmv2__' ) ) {
			return $html;
		}

		$labels_by_path = array();
		if (
			preg_match_all(
				'/<a\b[^>]*class=[\'"][^\'"]*xo-menu-horizontal-v1__link--sub[^\'"]*[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>\s*<span\b[^>]*class=[\'"][^\'"]*xo-menu-horizontal-v1__label[^\'"]*[\'"][^>]*>(.*?)<\/span>\s*<\/a>/isu',
				$html,
				$matches,
				PREG_SET_ORDER
			)
		) {
			foreach ( $matches as $match ) {
				$norm_path = self::normalize_internal_path( $match[1] );
				$label     = trim( $match[2] );
				if ( '' !== $norm_path && '' !== $label ) {
					$labels_by_path[ $norm_path ] = $label;
				}
			}
		}

		if ( array() === $labels_by_path ) {
			return $html;
		}

		$english_source_labels = array(
			'stainless steel screens',
			'laser-cut & geometric metal screens',
			'glass & hybrid screens',
			'chinese-style artistic screens',
			'outdoor privacy & landscape screens',
			'manufacturing process',
			'laser cut panels',
			'perforated panels',
			'expanded metal panels',
		);

		return (string) preg_replace_callback(
			'/<a(\b[^>]*class="mmv2__(?:hub-)?link"[^>]*href="([^"]+)"[^>]*)>(\s*)(.*?)(\s*)<\/a>/isu',
			static function ( array $m ) use ( $labels_by_path, $english_source_labels ): string {
				$norm_path = self::normalize_internal_path( $m[2] );
				if ( ! isset( $labels_by_path[ $norm_path ] ) ) {
					return $m[0];
				}
				$decoded = strtolower( trim( html_entity_decode( $m[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( in_array( $decoded, $english_source_labels, true ) ) {
					return '<a' . $m[1] . '>' . $m[3] . $labels_by_path[ $norm_path ] . $m[5] . '</a>';
				}
				return $m[0];
			},
			$html
		);
	}

	/**
	 * Strips any leading `/<lang>` prefix from an internal path for canonical matching.
	 *
	 * @param string $href Raw href attribute.
	 */
	private static function normalize_internal_path( string $href ): string {
		$path = trim( $href );
		foreach ( Languages::TARGETS as $code ) {
			if ( str_starts_with( $path, '/' . $code . '/' ) ) {
				$path = substr( $path, strlen( '/' . $code ) );
				break;
			}
		}
		return $path;
	}

	/**
	 * Sanitizes stored template/partial entries for a target language in place
	 * (glossary cleanup for `zh`, RTL arrow mirroring for `ar`, header mega-menu sync,
	 * and optional custom callback).
	 *
	 * @param string        $lang     Canonical language code.
	 * @param bool          $dry_run  When true, count changes without writing to disk/options.
	 * @param callable|null $mutator  Optional `fn(string $html, string $lang): string` mutator.
	 * @return int Number of modified entries in this language store.
	 */
	public static function sanitize_stored_templates( string $lang, bool $dry_run = false, ?callable $mutator = null ): int {
		$target = Languages::from_wpml( $lang );
		if ( Languages::SOURCE === $target ) {
			return 0;
		}

		$store   = self::load( $target );
		$changed = 0;

		foreach ( $store as $key => $entry ) {
			if ( empty( $entry['html'] ) ) {
				continue;
			}
			$updated = $entry['html'];
			if ( 'part:header' === $key ) {
				$updated = self::sync_header_mega_menu_labels( $updated );
			}
			if ( 'zh' === $target ) {
				$updated = self::sanitize_zh_glossary_html( $updated );
			}
			if ( Languages::is_rtl( $target ) ) {
				$updated = self::mirror_rtl_arrows_html( $updated );
			}
			if ( null !== $mutator ) {
				$updated = (string) $mutator( $updated, $target );
			}

			if ( $updated !== $entry['html'] ) {
				$store[ $key ]['html'] = $updated;
				++$changed;
			}
		}

		if ( $changed > 0 && ! $dry_run ) {
			self::save( $target, $store );
		} elseif ( $changed > 0 && $dry_run ) {
			// Do not retain mutated memo when dry-running.
			unset( self::$stores[ $target ] );
		}

		return $changed;
	}

	/**
	 * Stores a localized template translation.
	 *
	 * @param string $file_key        Template file key.
	 * @param string $en_html         English source content.
	 * @param string $translated_html Translated HTML content.
	 * @param string $lang            Canonical language code.
	 */
	public static function put_template( string $file_key, string $en_html, string $translated_html, string $lang ): void {
		$target                      = Languages::from_wpml( $lang );
		$store                       = self::load( $target );
		$store[ 'tpl:' . $file_key ] = array(
			'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			'html'     => $translated_html,
		);
		self::save( $target, $store );
	}

	/**
	 * Stores a localized partial translation.
	 *
	 * @param string $slug            Partial slug.
	 * @param string $en_html         English source content.
	 * @param string $translated_html Translated HTML content.
	 * @param string $lang            Canonical language code.
	 */
	public static function put_partial( string $slug, string $en_html, string $translated_html, string $lang ): void {
		$target                   = Languages::from_wpml( $lang );
		$store                    = self::load( $target );
		$store[ 'part:' . $slug ] = array(
			'src_hash' => hash( 'sha256', rtrim( $en_html, "\n" ) ),
			'html'     => $translated_html,
		);
		self::save( $target, $store );
	}

	/**
	 * Replaces an entire language store in one atomic write when its content changed.
	 *
	 * Strictly idempotent: when every key, `src_hash`, and `html` already matches
	 * the persisted store, no filesystem or database write is performed.
	 *
	 * @param string                                               $lang  Canonical language code.
	 * @param array<string, array{src_hash: string, html: string}> $store Complete language store.
	 * @return bool True when the store changed and was written, false when already up to date.
	 */
	public static function replace_store( string $lang, array $store ): bool {
		$target = Languages::from_wpml( $lang );
		if ( Languages::SOURCE === $target ) {
			return false;
		}

		ksort( $store, SORT_STRING );

		$existing     = self::load( $target );
		$file         = self::storage_file( $target );
		$file_present = null === $file || is_readable( $file );
		ksort( $existing, SORT_STRING );

		if ( $file_present && $existing === $store ) {
			return false;
		}

		self::save( $target, $store );
		return true;
	}

	/**
	 * Loads storage map for a language.
	 *
	 * @param string $lang Canonical language code.
	 * @return array<string, array{src_hash: string, html: string}>
	 */
	public static function load( string $lang ): array {
		if ( isset( self::$stores[ $lang ] ) ) {
			return self::$stores[ $lang ];
		}

		$file = self::storage_file( $lang );
		if ( null !== $file && is_readable( $file ) ) {
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $bytes ) {
				$data = json_decode( $bytes, true );
				if ( is_array( $data ) ) {
					/* @var array<string, array{src_hash: string, html: string}> $data */
					self::$stores[ $lang ] = $data;
					return self::$stores[ $lang ];
				}
			}
		}

		// Fallback to non-autoloaded WP option.
		if ( \function_exists( 'get_option' ) ) {
			$opt = \get_option( 'md_i18n_templates_' . $lang, array() );
			if ( is_array( $opt ) ) {
				/* @var array<string, array{src_hash: string, html: string}> $opt */
				self::$stores[ $lang ] = $opt;
				return self::$stores[ $lang ];
			}
		}

		self::$stores[ $lang ] = array();
		return self::$stores[ $lang ];
	}

	/**
	 * Persists storage map for a language.
	 *
	 * @param string                                               $lang  Canonical language code.
	 * @param array<string, array{src_hash: string, html: string}> $store Store data.
	 * @throws \RuntimeException When the localized store file cannot be written atomically.
	 */
	private static function save( string $lang, array $store ): void {
		self::$stores[ $lang ] = $store;

		$file = self::storage_file( $lang );
		// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
		try {
			$json = \function_exists( 'wp_json_encode' )
				? (string) \wp_json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: (string) json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable ) {
			$json = (string) json_encode( $store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( null !== $file ) {
			$dir = dirname( $file );
			if ( ! is_dir( $dir ) ) {
				$made = false;
				if ( \function_exists( 'wp_mkdir_p' ) ) {
					try {
						$made = (bool) \wp_mkdir_p( $dir );
					} catch ( \Throwable ) {
						$made = false;
					}
				}
				if ( ! $made && ! is_dir( $dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged
					@mkdir( $dir, 0755, true );
				}
			}
			$temp = $file . '.tmp.' . uniqid( '', true );
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
			$res = @file_put_contents( $temp, $json, LOCK_EX );
			if ( false === $res || ! @rename( $temp, $file ) ) {
				@unlink( $temp );
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \RuntimeException( sprintf( 'Failed to write localized template store: %s', $file ) );
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
		}

		// Also sync to options fallback with autoload = false.
		if ( \function_exists( 'update_option' ) ) {
			\update_option( 'md_i18n_templates_' . $lang, $store, false );
		}
	}

	/**
	 * Resolves filesystem path for a language's cache file.
	 *
	 * @param string $lang Canonical language code.
	 */
	private static function storage_file( string $lang ): ?string {
		if ( null !== self::$storage_dir_override && '' !== self::$storage_dir_override ) {
			return self::$storage_dir_override . '/templates-' . $lang . '.json';
		}
		if ( \function_exists( 'wp_upload_dir' ) ) {
			$uploads = \wp_upload_dir();
			if ( ! empty( $uploads['basedir'] ) ) {
				return (string) $uploads['basedir'] . '/md-i18n/templates-' . $lang . '.json';
			}
		}
		return null;
	}
}
