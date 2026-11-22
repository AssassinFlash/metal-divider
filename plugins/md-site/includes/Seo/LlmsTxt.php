<?php
/**
 * Managed /llms.txt endpoint, discovery link, and legacy Hostinger cleanup.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

use MetalDivider\Site\I18n\Languages;

/**
 * Serves a curated, bounded Markdown /llms.txt index directly from WooCommerce's
 * published English pattern catalog and core B2B pages, emits the canonical
 * `<link rel="describedby">` tag on the home and pattern library views, and
 * provides idempotent cleanup and drift detection for Hostinger's legacy
 * static llms.txt generator.
 */
final class LlmsTxt {

	/**
	 * Transient cache key for the rendered /llms.txt body.
	 */
	public const TRANSIENT_KEY = 'md_site_llms_txt_v1';

	/**
	 * Transient and HTTP Cache-Control TTL in seconds (1 hour).
	 */
	public const CACHE_TTL = 3600;

	/**
	 * Hard byte cap for the rendered /llms.txt response (40 KB).
	 */
	public const MAX_BYTES = 40960;

	/**
	 * Hostinger Tools WordPress option name carrying the legacy llms.txt toggle.
	 */
	public const HOSTINGER_OPTION = 'hostinger_tools';

	/**
	 * Core canonical B2B pages listed under `## Core Pages`.
	 *
	 * Explicitly excludes `/inquiry/thank-you/`, `/blog/`, `/cart/`,
	 * `/checkout/`, `/account/`, paginated paths, and query parameters.
	 *
	 * @var list<array{path: string, title: string, summary: string}>
	 */
	private const CORE_PAGES = array(
		array(
			'path'    => '/',
			'title'   => 'Home',
			'summary' => 'Architectural stainless steel (304 / 316) metal screen patterns and custom B2B fabrication',
		),
		array(
			'path'    => '/patterns/',
			'title'   => 'Pattern Library',
			'summary' => 'Browse all laser-cut stainless steel screen patterns by series, open area, and visual density',
		),
		array(
			'path'    => '/pattern-category/',
			'title'   => 'Pattern Series',
			'summary' => 'Pattern families grouped by style, privacy, light behavior, and airflow',
		),
		array(
			'path'    => '/stainless-steel-screens/',
			'title'   => 'Stainless Steel Screens',
			'summary' => 'Stainless steel (304 / 316) screen systems for facades, partitions, and outdoor privacy',
		),
		array(
			'path'    => '/laser-cut-geometric-metal-screens/',
			'title'   => 'Laser-Cut & Geometric Metal Screens',
			'summary' => 'Laser-cut stainless steel screens in geometric and architectural pattern directions',
		),
		array(
			'path'    => '/glass-hybrid-screens/',
			'title'   => 'Glass & Hybrid Screens',
			'summary' => 'Stainless steel framed glass and hybrid screens for interior zoning and privacy',
		),
		array(
			'path'    => '/outdoor-privacy-landscape-screens/',
			'title'   => 'Outdoor Privacy & Landscape Screens',
			'summary' => 'Exterior 304 and 316 stainless steel screens specified for coastal, alpine, and courtyard sites',
		),
		array(
			'path'    => '/chinese-style-artistic-screens/',
			'title'   => 'Chinese-Style Artistic Screens',
			'summary' => 'Chinese-style and artistic stainless steel screens for hotels, restaurants, and residences',
		),
		array(
			'path'    => '/laser-cut-panels/',
			'title'   => 'Laser-Cut Panels',
			'summary' => 'Custom laser-cut 304 / 316 stainless steel panels with brushed or PVD finishes',
		),
		array(
			'path'    => '/perforated-panels/',
			'title'   => 'Perforated Panels',
			'summary' => 'Perforated stainless steel panels punched to specified hole patterns and open area',
		),
		array(
			'path'    => '/expanded-metal-panels/',
			'title'   => 'Expanded Metal Panels',
			'summary' => 'Expanded stainless steel mesh panels for facades, partitions, and ceilings',
		),
		array(
			'path'    => '/custom-metal-divider-screens/',
			'title'   => 'Custom Metal Divider Screens',
			'summary' => 'Custom stainless steel fabrication from CAD drawings or existing catalog patterns',
		),
		array(
			'path'    => '/project-gallery/',
			'title'   => 'Project Gallery',
			'summary' => 'Interior and architectural stainless steel screen installations across hospitality and commercial spaces',
		),
		array(
			'path'    => '/manufacturing-process/',
			'title'   => 'Manufacturing Process',
			'summary' => 'Drawing review, laser cutting, perforation, expanded metal, PVD finishing, and quality inspection',
		),
		array(
			'path'    => '/faqs/',
			'title'   => 'FAQs & Technical Downloads',
			'summary' => 'Answers on 304 and 316 stainless steel grades, panel sizes, tolerances, lead times, and installation',
		),
		array(
			'path'    => '/about-us/',
			'title'   => 'About Us',
			'summary' => 'Fabrication capability, PVD finish quality checks, and global B2B export delivery',
		),
		array(
			'path'    => '/inquiry/',
			'title'   => 'Request a Quote',
			'summary' => 'Direct B2B project quote and pattern specification inquiry form',
		),
		array(
			'path'    => '/privacy/',
			'title'   => 'Privacy Policy',
			'summary' => 'How metal-divider.com handles inquiry data, retention, and deletion requests',
		),
	);

	/**
	 * Optional header emitter override for testing.
	 *
	 * @var (callable(string): void)|null
	 */
	private $header_sender;

	/**
	 * Optional request terminator override for testing.
	 *
	 * @var (callable(): void)|null
	 */
	private $terminator;

	/**
	 * Constructs the endpoint controller with optional test hooks.
	 *
	 * @param (callable(string): void)|null $header_sender Optional HTTP header sender callback.
	 * @param (callable(): void)|null       $terminator    Optional termination callback replacing exit.
	 */
	public function __construct( ?callable $header_sender = null, ?callable $terminator = null ) {
		$this->header_sender = $header_sender;
		$this->terminator    = $terminator;
	}

	/**
	 * Registers request routing, discovery link emission, and catalog cache invalidation.
	 *
	 * Hooks `do_parse_request` at priority -10 (ahead of SEOPress PRO's `seopress_serve_llms_txt`
	 * at priority 0), unhooks SEOPress PRO's responder on `init`, and also filters
	 * `seopress_llms_txt_file` as a belt-and-suspenders fallback.
	 */
	public function register(): void {
		\add_filter( 'do_parse_request', array( $this, 'maybe_serve_early' ), -10, 1 );
		\add_action( 'init', array( $this, 'unhook_seopress_llms_txt' ), 100, 0 );
		\add_filter( 'seopress_llms_txt_file', array( $this, 'filter_seopress_llms_txt' ), 99, 1 );
		\add_action( 'wp_head', array( $this, 'output_head_link' ), 5, 0 );

		\add_action( 'save_post_product', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'deleted_post', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'trashed_post', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'untrashed_post', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'edited_product_cat', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'created_product_cat', array( $this, 'invalidate_cache' ), 10, 0 );
		\add_action( 'delete_product_cat', array( $this, 'invalidate_cache' ), 10, 0 );
	}

	/**
	 * Unhooks SEOPress PRO's built-in `/llms.txt` responder so this plugin owns `/llms.txt`.
	 */
	public function unhook_seopress_llms_txt(): void {
		if ( \function_exists( 'remove_filter' ) ) {
			\remove_filter( 'do_parse_request', 'seopress_serve_llms_txt', 0 );
		}
	}

	/**
	 * Intercepts `/llms.txt` on `do_parse_request` before WordPress routing or SEOPress PRO.
	 *
	 * @param mixed $do_parse Incoming `do_parse_request` boolean.
	 * @return mixed Unchanged `$do_parse` when the request is not `/llms.txt`.
	 */
	public function maybe_serve_early( mixed $do_parse ): mixed {
		$this->maybe_serve();
		return $do_parse;
	}

	/**
	 * Fallback filter for SEOPress PRO's `seopress_llms_txt_file` hook.
	 *
	 * @param mixed $content SEOPress PRO generated content (ignored).
	 * @return string Curated `/llms.txt` Markdown body.
	 */
	public function filter_seopress_llms_txt( mixed $content = '' ): string {
		unset( $content );
		return $this->render();
	}

	/**
	 * Determines whether an incoming HTTP request targets the canonical root `/llms.txt` route.
	 *
	 * Matches only exact root path `/llms.txt` (ignoring query string / fragment) on GET or HEAD.
	 * Rejects localized paths (`/zh/llms.txt`), sub-paths (`/llms.txt/foo`), trailing slashes,
	 * protocol-relative paths, and non-read HTTP methods.
	 *
	 * @param string $request_uri Raw request URI (path + optional query string).
	 * @param string $method      HTTP request method.
	 */
	public static function should_serve_request( string $request_uri, string $method ): bool {
		$normalized_method = strtoupper( trim( $method ) );
		if ( 'GET' !== $normalized_method && 'HEAD' !== $normalized_method ) {
			return false;
		}

		$uri = trim( $request_uri );
		if ( '' === $uri || ! str_starts_with( $uri, '/' ) || str_starts_with( $uri, '//' ) ) {
			return false;
		}

		$path = $uri;
		$qpos = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$path = substr( $path, 0, $qpos );
		}
		$hpos = strpos( $path, '#' );
		if ( false !== $hpos ) {
			$path = substr( $path, 0, $hpos );
		}

		return '/llms.txt' === $path;
	}

	/**
	 * Intercepts `/llms.txt` requests on `parse_request` and serves the Markdown document.
	 */
	public function maybe_serve(): void {
		if ( ( \defined( 'WP_CLI' ) && WP_CLI ) || ( \function_exists( 'wp_doing_cron' ) && \wp_doing_cron() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only exact comparison against '/llms.txt' and GET/HEAD.
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only exact comparison against GET/HEAD.
		$raw_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

		if ( ! self::should_serve_request( $raw_uri, $raw_method ) ) {
			return;
		}

		$this->serve_response( $raw_method );
	}

	/**
	 * Sends HTTP status, headers, and optional Markdown body for a matched `/llms.txt` request.
	 *
	 * @param string $method HTTP request method (`GET` or `HEAD`).
	 */
	public function serve_response( string $method ): void {
		$body = $this->render();

		if ( \function_exists( 'status_header' ) ) {
			\status_header( 200 );
		}

		$this->send_header( 'Content-Type: text/plain; charset=utf-8' );
		$this->send_header( 'Cache-Control: public, max-age=' . self::CACHE_TTL );
		$this->send_header( 'X-Content-Type-Options: nosniff' );

		if ( 'HEAD' !== strtoupper( trim( $method ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text Markdown document served with text/plain and nosniff.
			echo $body;
		}

		$this->terminate();
	}

	/**
	 * Emits `<link rel="describedby" href=".../llms.txt" />` on the home and pattern library views.
	 *
	 * Always points to the unfiltered canonical root `/llms.txt`, even on localized routes.
	 */
	public function output_head_link(): void {
		if ( ! $this->should_emit_head_link() ) {
			return;
		}

		$href = Languages::unfiltered_home_url( '/llms.txt' );
		echo '<link rel="describedby" href="' . \esc_url( $href ) . "\" />\n";
	}

	/**
	 * Clears the cached `/llms.txt` transient so WooCommerce catalog updates take effect immediately.
	 */
	public function invalidate_cache(): void {
		if ( \function_exists( 'delete_transient' ) ) {
			\delete_transient( self::TRANSIENT_KEY );
		}
	}

	/**
	 * Renders the complete `/llms.txt` document from live WooCommerce data, with transient caching.
	 *
	 * @param bool $use_cache Whether to read and populate the transient cache.
	 * @return string Curated UTF-8 Markdown document bounded to `self::MAX_BYTES`.
	 */
	public function render( bool $use_cache = true ): string {
		if ( $use_cache && \function_exists( 'get_transient' ) ) {
			$cached = \get_transient( self::TRANSIENT_KEY );
			if ( is_string( $cached ) && '' !== $cached && strlen( $cached ) <= self::MAX_BYTES ) {
				return $cached;
			}
		}

		$home_url = Languages::unfiltered_home_url( '/' );
		$catalog  = $this->fetch_live_catalog();
		$output   = self::build( $home_url, $catalog['series'], $catalog['patterns'] );

		if ( $use_cache && \function_exists( 'set_transient' ) ) {
			\set_transient( self::TRANSIENT_KEY, $output, self::CACHE_TTL );
		}

		return $output;
	}

	/**
	 * Pure deterministic renderer for the `/llms.txt` Markdown document.
	 *
	 * Sanitizes every label, excludes disallowed/non-canonical URLs, deduplicates
	 * URLs globally across all sections, and enforces the hard 40 KB byte budget
	 * without ever truncating a Markdown link mid-line.
	 *
	 * @param string                                                                            $home_url Canonical site root URL.
	 * @param array<int, array{name: string, url: string, description?: string}>                $series   Published English pattern series.
	 * @param array<int, array{name: string, url: string, series?: string, open_area?: string}> $patterns Published English patterns.
	 * @return string Complete Markdown text <= `self::MAX_BYTES`.
	 */
	public static function build( string $home_url, array $series, array $patterns ): string {
		$base = rtrim( trim( $home_url ), '/' );
		if ( '' === $base ) {
			$base = 'https://metal-divider.com';
		}

		$seen_urls = array();

		$lines   = array();
		$lines[] = '# MetalDivider';
		$lines[] = '';
		$lines[] = '> Laser-cut stainless steel (304 / 316) architectural metal screen patterns, facades, and partitions. Custom B2B fabrication; quote-only (no cart or checkout). Email: info@metal-divider.com, WhatsApp: +85264731687.';
		$lines[] = '';
		$lines[] = '## Core Pages';
		$lines[] = '';

		foreach ( self::CORE_PAGES as $page ) {
			$url = '/' === $page['path'] ? $base . '/' : $base . $page['path'];
			$key = self::canonical_url_key( $url );
			if ( '' === $key || isset( $seen_urls[ $key ] ) ) {
				continue;
			}
			$seen_urls[ $key ] = true;
			$lines[]           = sprintf( '- [%s](%s): %s', $page['title'], $url, $page['summary'] );
		}

		$lines[] = '';
		$lines[] = '## Languages';
		$lines[] = '';
		$lines[] = 'Default edition: English (`en`) is served at the canonical site root `/` listed under Core Pages above. Localized editions:';
		$lines[] = '';

		$all_languages = Languages::all();
		foreach ( Languages::TARGETS as $code ) {
			if ( ! isset( $all_languages[ $code ] ) ) {
				continue;
			}
			$info = $all_languages[ $code ];
			$url  = $base . '/' . $code . '/';
			$key  = self::canonical_url_key( $url );
			if ( '' === $key || isset( $seen_urls[ $key ] ) ) {
				continue;
			}
			$seen_urls[ $key ] = true;
			$direction         = $info['rtl'] ? ' (RTL)' : '';
			$lines[]           = sprintf(
				'- [%s (%s)](%s): %s edition%s',
				$info['label'],
				$info['hreflang'],
				$url,
				$info['english_name'],
				$direction
			);
		}

		$series_entries = array();
		foreach ( $series as $item ) {
			$label = self::sanitize_label( $item['name'] );
			$url   = self::normalize_canonical_url( $item['url'] );
			if ( '' === $label || '' === $url || ! self::is_allowed_english_catalog_url( $url ) ) {
				continue;
			}
			$desc             = isset( $item['description'] ) ? self::sanitize_label( $item['description'] ) : '';
			$series_entries[] = array(
				'label' => $label,
				'url'   => $url,
				'desc'  => $desc,
			);
		}

		usort(
			$series_entries,
			static function ( array $a, array $b ): int {
				$cmp = strnatcasecmp( $a['label'], $b['label'] );
				return 0 !== $cmp ? $cmp : strcmp( $a['url'], $b['url'] );
			}
		);

		$series_lines = array();
		foreach ( $series_entries as $entry ) {
			$key = self::canonical_url_key( $entry['url'] );
			if ( '' === $key || isset( $seen_urls[ $key ] ) ) {
				continue;
			}
			$seen_urls[ $key ] = true;
			$series_lines[]    = '' !== $entry['desc']
				? sprintf( '- [%s](%s): %s', $entry['label'], $entry['url'], $entry['desc'] )
				: sprintf( '- [%s](%s)', $entry['label'], $entry['url'] );
		}

		if ( array() !== $series_lines ) {
			$lines[] = '';
			$lines[] = '## Pattern Series';
			$lines[] = '';
			foreach ( $series_lines as $series_line ) {
				$lines[] = $series_line;
			}
		}

		$pattern_entries = array();
		foreach ( $patterns as $item ) {
			$label = self::sanitize_label( $item['name'] );
			$url   = self::normalize_canonical_url( $item['url'] );
			if ( '' === $label || '' === $url || ! self::is_allowed_english_catalog_url( $url ) ) {
				continue;
			}
			$series_name = isset( $item['series'] ) ? self::sanitize_label( $item['series'] ) : '';
			$open_area   = isset( $item['open_area'] ) ? self::format_open_area( $item['open_area'] ) : '';

			$pattern_entries[] = array(
				'label'     => $label,
				'url'       => $url,
				'series'    => $series_name,
				'open_area' => $open_area,
			);
		}

		usort(
			$pattern_entries,
			static function ( array $a, array $b ): int {
				$cmp = strnatcasecmp( $a['label'], $b['label'] );
				return 0 !== $cmp ? $cmp : strcmp( $a['url'], $b['url'] );
			}
		);

		$pattern_lines = array();
		foreach ( $pattern_entries as $entry ) {
			$key = self::canonical_url_key( $entry['url'] );
			if ( '' === $key || isset( $seen_urls[ $key ] ) ) {
				continue;
			}
			$seen_urls[ $key ] = true;

			$meta = array();
			if ( '' !== $entry['series'] ) {
				$meta[] = $entry['series'];
			}
			if ( '' !== $entry['open_area'] ) {
				$meta[] = $entry['open_area'] . ' open area';
			}

			$pattern_lines[] = array() !== $meta
				? sprintf( "- [%s](%s): %s\n", $entry['label'], $entry['url'], implode( ', ', $meta ) )
				: sprintf( "- [%s](%s)\n", $entry['label'], $entry['url'] );
		}

		if ( array() === $pattern_lines ) {
			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = '';
		$lines[] = '## Pattern Library';
		$lines[] = '';

		$prefix = implode( "\n", $lines ) . "\n";
		$full   = $prefix . implode( '', $pattern_lines );

		if ( strlen( $full ) <= self::MAX_BYTES ) {
			return $full;
		}

		$total_patterns = count( $pattern_lines );
		$max_footer     = self::truncation_footer( $total_patterns );
		$budget         = self::MAX_BYTES - strlen( $prefix ) - strlen( $max_footer );

		$kept = array();
		$used = 0;
		if ( $budget > 0 ) {
			foreach ( $pattern_lines as $line ) {
				$len = strlen( $line );
				if ( ( $used + $len ) > $budget ) {
					break;
				}
				$kept[] = $line;
				$used  += $len;
			}
		}

		$omitted = $total_patterns - count( $kept );
		return $prefix . implode( '', $kept ) . self::truncation_footer( $omitted );
	}

	/**
	 * Sanitizes a pattern or series label for customer- and LLM-facing Markdown output.
	 *
	 * Strips HTML, decodes entities, drops `| ...` / `｜ ...` SEO suffixes, strips
	 * third-party brand names (`DSD`, `Decorative Screen Direct`, `Parasoleil`,
	 * `Hostinger`), replaces customer-facing `product`/`products` with `pattern`/`patterns`,
	 * removes `?` and Markdown-breaking brackets, and normalizes whitespace.
	 *
	 * @param string $raw Raw title, term name, or description text.
	 * @return string Clean single-line label.
	 */
	public static function sanitize_label( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure unit-testable string cleaner without WP dependency.
		$text = strip_tags( $raw );

		if ( str_contains( $text, '&' ) ) {
			$text = (string) preg_replace( '/&amp;(#\d+|#x[0-9a-fA-F]+|[a-zA-Z0-9]+);/', '&$1;', $text );
			$text = html_entity_decode( $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		}

		$text = str_replace( "\xC2\xA0", ' ', $text );

		// Strip SEO suffix after ASCII or full-width vertical pipe.
		$pipe_parts = preg_split( '/\s*[|｜]\s*/u', $text, 2 );
		if ( is_array( $pipe_parts ) && isset( $pipe_parts[0] ) && '' !== trim( $pipe_parts[0] ) ) {
			$text = $pipe_parts[0];
		}

		// Strip third-party brand strings (case-insensitive), including leading attribution words.
		$brand_patterns = array(
			'/\b(?:generated\s+by\s+)?hostinger\b/iu',
			'/\b(?:by|from)\s+(?:decorative\s+screen\s+direct|parasoleil|dsd)\b/iu',
			'/\bdecorative\s+screen\s+direct\b/iu',
			'/\bparasoleil\b/iu',
			'/\bdsd\b/iu',
		);
		foreach ( $brand_patterns as $pattern ) {
			$text = (string) preg_replace( $pattern, ' ', $text );
		}

		// Replace customer-facing "product" / "products" with "pattern" / "patterns".
		$text = (string) preg_replace_callback(
			'/\b(products?)\b/iu',
			static function ( array $matches ): string {
				$word      = $matches[1];
				$is_plural = str_ends_with( strtolower( $word ), 's' );
				$is_upper  = strtoupper( $word[0] ) === $word[0];
				if ( $is_plural ) {
					return $is_upper ? 'Patterns' : 'patterns';
				}
				return $is_upper ? 'Pattern' : 'pattern';
			},
			$text
		);

		// Collapse accidental doubled "pattern pattern" after replacement.
		$text = (string) preg_replace( '/\b(patterns?)\s+\1\b/iu', '$1', $text );

		// Remove empty parens/brackets left behind after stripping brand tags.
		$text = (string) preg_replace( '/\(\s*\)|\[\s*\]/u', ' ', $text );

		// Prevent Markdown link syntax corruption or stray query punctuation.
		$text = str_replace( array( '[', ']', '?' ), array( '(', ')', '' ), $text );
		$text = (string) preg_replace( '/[\r\n\t]+/u', ' ', $text );
		$text = (string) preg_replace( '/\s{2,}/u', ' ', $text );
		$text = trim( $text );

		// Strip dangling leading/trailing separators left over from removed brand prefixes/suffixes.
		$text = (string) preg_replace( '/^[\s\-–—:,\/]+|[\s\-–—:,\/]+$/u', '', $text );

		return trim( $text );
	}

	/**
	 * Idempotently disables Hostinger's legacy llms.txt generator option and removes
	 * any static `llms.txt` file at `$abspath/llms.txt`.
	 *
	 * @param string $abspath WordPress root directory path (ABSPATH).
	 * @return array{option_updated: bool, file_deleted: bool, file_path: string} Cleanup report.
	 */
	public static function disable_hostinger_generator( string $abspath ): array {
		$option_updated = false;
		if ( \function_exists( 'get_option' ) && \function_exists( 'update_option' ) ) {
			$opts = \get_option( self::HOSTINGER_OPTION );
			if ( is_array( $opts ) && ! empty( $opts['enable_llms_txt'] ) ) {
				$opts['enable_llms_txt'] = false;
				\update_option( self::HOSTINGER_OPTION, $opts );
				$option_updated = true;
			}
		}

		$trimmed      = rtrim( trim( $abspath ), '/\\' );
		$file_path    = '' !== $trimmed ? $trimmed . '/llms.txt' : '';
		$file_deleted = false;

		if ( '' !== $file_path ) {
			clearstatcache( true, $file_path );
			if ( is_file( $file_path ) && ! is_link( $file_path ) ) {
				if ( \function_exists( 'wp_delete_file' ) ) {
					\wp_delete_file( $file_path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Non-WP fallback when wp_delete_file() is unavailable.
					@unlink( $file_path );
				}
				clearstatcache( true, $file_path );
				$file_deleted = ! file_exists( $file_path );
			}
		}

		return array(
			'option_updated' => $option_updated,
			'file_deleted'   => $file_deleted,
			'file_path'      => $file_path,
		);
	}

	/**
	 * Read-only drift check for Hostinger's legacy llms.txt generator.
	 *
	 * @param string $abspath WordPress root directory path (ABSPATH).
	 * @return array<int, string> Drift messages (empty when clean).
	 */
	public static function hostinger_drift( string $abspath ): array {
		$rows = array();

		if ( \function_exists( 'get_option' ) ) {
			$opts = \get_option( self::HOSTINGER_OPTION );
			if ( is_array( $opts ) && ! empty( $opts['enable_llms_txt'] ) ) {
				$rows[] = 'hostinger_tools.enable_llms_txt: enabled (shadows managed /llms.txt; run wp md-site seo to disable)';
			}
		}

		$trimmed = rtrim( trim( $abspath ), '/\\' );
		if ( '' !== $trimmed ) {
			$file_path = $trimmed . '/llms.txt';
			clearstatcache( true, $file_path );
			if ( is_file( $file_path ) && ! is_link( $file_path ) ) {
				$rows[] = sprintf( 'static file %s: exists and shadows managed /llms.txt (run wp md-site seo to remove)', $file_path );
			}
		}

		return $rows;
	}

	/**
	 * Checks whether the current view should emit the `<link rel="describedby">` tag.
	 */
	private function should_emit_head_link(): bool {
		if ( \function_exists( 'is_search' ) && \is_search() ) {
			return false;
		}
		if ( \function_exists( 'is_front_page' ) && \is_front_page() ) {
			return true;
		}
		if ( \function_exists( 'is_shop' ) && \is_shop() ) {
			return true;
		}
		return \function_exists( 'is_post_type_archive' ) && \is_post_type_archive( 'product' );
	}

	/**
	 * Queries published English pattern series and patterns from WooCommerce within an English WPML context.
	 *
	 * @return array{
	 *     series: list<array{name: string, url: string, description: string}>,
	 *     patterns: list<array{name: string, url: string, series: string, open_area: string}>
	 * }
	 */
	private function fetch_live_catalog(): array {
		$orig_wpml_lang = null;
		if ( \function_exists( 'has_filter' ) && \has_filter( 'wpml_current_language' ) ) {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
			$current = \apply_filters( 'wpml_current_language', null );
			if ( is_string( $current ) && '' !== $current ) {
				$orig_wpml_lang = $current;
			}
		}

		/**
		 * Catalog payload resolved under the English language context.
		 *
		 * @var array{
		 *     series: list<array{name: string, url: string, description: string}>,
		 *     patterns: list<array{name: string, url: string, series: string, open_area: string}>
		 * } $result
		 */
		$result = Languages::with_language(
			Languages::SOURCE,
			function () use ( $orig_wpml_lang ): array {
				$switched_via_action = false;
				if ( \function_exists( 'has_action' ) && \has_action( 'wpml_switch_language' ) ) {
					// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML action.
					\do_action( 'wpml_switch_language', Languages::to_wpml( Languages::SOURCE ) );
					$switched_via_action = true;
				}

				try {
					return $this->query_english_catalog();
				} finally {
					if ( $switched_via_action && null !== $orig_wpml_lang ) {
						// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML action.
						\do_action( 'wpml_switch_language', $orig_wpml_lang );
					}
				}
			}
		);

		return $result;
	}

	/**
	 * Runs the WordPress queries for published English series and patterns.
	 *
	 * @return array{
	 *     series: list<array{name: string, url: string, description: string}>,
	 *     patterns: list<array{name: string, url: string, series: string, open_area: string}>
	 * }
	 */
	private function query_english_catalog(): array {
		$patterns       = array();
		$active_series  = array();
		$has_lang_check = \function_exists( 'has_filter' ) && \has_filter( 'wpml_element_language_code' );

		if ( \function_exists( 'get_posts' ) ) {
			$query_args = array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			);

			if ( \function_exists( 'wc_get_product_visibility_term_ids' ) ) {
				$vis_ids = \wc_get_product_visibility_term_ids();
				if ( is_array( $vis_ids ) && isset( $vis_ids['exclude-from-catalog'] ) && (int) $vis_ids['exclude-from-catalog'] > 0 ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Cached for 1 hour in transient; respects WooCommerce catalog visibility.
					$query_args['tax_query'] = array(
						array(
							'taxonomy'         => 'product_visibility',
							'field'            => 'term_taxonomy_id',
							'terms'            => array( (int) $vis_ids['exclude-from-catalog'] ),
							'operator'         => 'NOT IN',
							'include_children' => false,
						),
					);
				}
			}

			$raw_posts = \get_posts( $query_args );

			foreach ( $raw_posts as $post ) {
				$post_id = (int) $post->ID;
				if ( $post_id <= 0 ) {
					continue;
				}
				if ( ! Languages::is_post_in_language( $post_id, 'product', Languages::SOURCE ) ) {
					continue;
				}
				if ( $has_lang_check ) {
					$el_args = array(
						'element_id'   => $post_id,
						'element_type' => 'product',
					);
					// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
					$el_lang = \apply_filters( 'wpml_element_language_code', null, $el_args );
					if ( is_string( $el_lang ) && '' !== $el_lang && Languages::SOURCE !== Languages::from_wpml( $el_lang ) ) {
						continue;
					}
				}

				$url = \function_exists( 'get_permalink' ) ? (string) \get_permalink( $post_id ) : '';
				if ( '' === $url ) {
					continue;
				}

				$series_name = '';
				if ( \function_exists( 'get_the_terms' ) ) {
					$post_terms = \get_the_terms( $post_id, 'product_cat' );
					if ( is_array( $post_terms ) ) {
						foreach ( $post_terms as $term ) {
							if ( 'uncategorized' === strtolower( (string) $term->slug ) ) {
								continue;
							}
							if ( ! Languages::is_term_in_language( $term, 'product_cat', Languages::SOURCE ) ) {
								continue;
							}
							$active_series[ (string) $term->slug ] = true;
							if ( '' === $series_name ) {
								$series_name = (string) $term->name;
							}
						}
					}
				}

				$open_area = \function_exists( 'get_post_meta' ) ? (string) \get_post_meta( $post_id, 'open_area', true ) : '';

				$patterns[] = array(
					'name'      => (string) $post->post_title,
					'url'       => $url,
					'series'    => $series_name,
					'open_area' => $open_area,
				);
			}
		}

		$series = array();
		if ( \function_exists( 'get_terms' ) ) {
			$raw_terms = \get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
					'menu_order' => false,
				)
			);

			if ( is_array( $raw_terms ) ) {
				foreach ( $raw_terms as $term ) {
					$slug = (string) $term->slug;
					if ( '' === $slug || 'uncategorized' === strtolower( $slug ) ) {
						continue;
					}
					if ( ! Languages::is_term_in_language( $term, 'product_cat', Languages::SOURCE ) ) {
						continue;
					}
					if ( $has_lang_check ) {
						$term_args = array(
							'element_id'   => (int) $term->term_taxonomy_id > 0 ? (int) $term->term_taxonomy_id : (int) $term->term_id,
							'element_type' => 'product_cat',
						);
						// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party WPML filter.
						$term_lang = \apply_filters( 'wpml_element_language_code', null, $term_args );
						if ( is_string( $term_lang ) && '' !== $term_lang && Languages::SOURCE !== Languages::from_wpml( $term_lang ) ) {
							continue;
						}
					}
					if ( (int) $term->count <= 0 && ! isset( $active_series[ $slug ] ) ) {
						continue;
					}

					$link = \function_exists( 'get_term_link' ) ? \get_term_link( $term ) : '';
					if ( ! is_string( $link ) || '' === $link ) {
						continue;
					}

					$series[] = array(
						'name'        => (string) $term->name,
						'url'         => $link,
						'description' => (string) $term->description,
					);
				}
			}
		}

		return array(
			'series'   => $series,
			'patterns' => $patterns,
		);
	}

	/**
	 * Formats a numeric open-area value as `NN%` (empty string when unset or non-numeric).
	 *
	 * Never fabricates a percentage for patterns whose open area is "On request".
	 *
	 * @param string $raw Raw meta value (e.g. '17', '32.5', '17%', or '').
	 */
	private static function format_open_area( string $raw ): string {
		$trimmed = rtrim( trim( $raw ), '%' );
		if ( '' === $trimmed || ! is_numeric( $trimmed ) ) {
			return '';
		}
		$number = (float) $trimmed;
		if ( $number <= 0.0 || $number > 100.0 ) {
			return '';
		}
		$digits = floor( $number ) === $number ? 0 : 2;
		return number_format( $number, $digits ) . '%';
	}

	/**
	 * Normalizes an absolute HTTP(S) URL for display and deduplication.
	 *
	 * Rejects URLs containing query strings, fragments, whitespace, or non-HTTP schemes.
	 *
	 * @param string $raw Raw URL.
	 */
	private static function normalize_canonical_url( string $raw ): string {
		$url = trim( $raw );
		if ( '' === $url || preg_match( '/[\s?#]/', $url ) ) {
			return '';
		}
		if ( ! preg_match( '#^https?://[^/]+(?:/.*)?$#i', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Validates that a catalog URL (series or pattern) is clean, canonical, English-root,
	 * and free of excluded routes (`/inquiry/thank-you/`, `/blog/`, `/cart`, `/account`, `/page/`).
	 *
	 * @param string $url Normalized URL.
	 */
	private static function is_allowed_english_catalog_url( string $url ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Guarded fallback when wp_parse_url() is unavailable in tests.
		$path = \function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		// Reject disallowed routes and pagination.
		if ( 1 === preg_match( '#/(?:inquiry/)?thank-you(?:/|$)#i', $path ) ) {
			return false;
		}
		if ( 1 === preg_match( '#/blog(?:/|$)#i', $path ) ) {
			return false;
		}
		if ( 1 === preg_match( '#/(?:cart|checkout|my-account|account)(?:/|$)#i', $path ) ) {
			return false;
		}
		if ( 1 === preg_match( '#/page/\d+(?:/|$)#i', $path ) ) {
			return false;
		}

		// Reject non-English localized catalog URLs (WPML directory prefixes).
		if ( 1 === preg_match( '#^/(?:zh|zh-hans|es|pt-br|de|fr|ar|id|vi)(?:/|$)#i', $path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Computes a canonical deduplication key for a URL (case-insensitive scheme/host, normalized slash).
	 *
	 * @param string $url Normalized URL.
	 */
	private static function canonical_url_key( string $url ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Guarded fallback when wp_parse_url() is unavailable in tests.
		$parts = \function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$path   = '/' === $path ? '/' : rtrim( $path, '/' ) . '/';

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Builds the closed plain-text footer appended when pattern entries are trimmed for the 40 KB cap.
	 *
	 * Contains no URL so global URL uniqueness is preserved even under truncation.
	 *
	 * @param int $omitted_count Number of omitted patterns.
	 */
	private static function truncation_footer( int $omitted_count ): string {
		return sprintf(
			"\n... (%d additional patterns omitted to stay within the 40 KB llms.txt budget; browse the full Pattern Library at the /patterns/ URL listed under Core Pages.)\n",
			max( 1, $omitted_count )
		);
	}

	/**
	 * Emits a single HTTP header line (or delegates to the injected test double).
	 *
	 * @param string $line Raw HTTP header line.
	 */
	private function send_header( string $line ): void {
		if ( null !== $this->header_sender ) {
			( $this->header_sender )( $line );
			return;
		}
		if ( ! headers_sent() ) {
			header( $line );
		}
	}

	/**
	 * Terminates request execution after serving `/llms.txt` (or delegates to the injected test double).
	 */
	private function terminate(): void {
		if ( null !== $this->terminator ) {
			( $this->terminator )();
			return;
		}
		exit;
	}
}
