<?php
/**
 * HTML comments do not belong in the response.
 *
 * The LiveCanvas files carry author notes in HTML comments: spec numbers,
 * internal paths, and — in one note — a shortcode tag written out in full.
 * WordPress runs shortcodes over the whole post, comments included
 * (`lct_do_shortcode` in LiveCanvas does not skip them), so a tag in a note
 * expands inside the note and the note ships. The offline converter reads
 * `md-section:` and `md-data:` from the repository files at build time
 * (`tools/template-convert`); nothing in the running site reads a comment
 * out of the response. LiveCanvas's own `<!-- LC DT ID: -->` marker is
 * written into the render and never read back.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * Strips HTML comments from the front-end response before it is sent.
 *
 * Script, style, textarea and title elements are copied through: inside
 * those, `<!--` is text, not a comment, and eating it would change a script
 * or a field a visitor can submit.
 */
final class HtmlComments {

	/**
	 * A comment longer than this in the response is a leak.
	 *
	 * The ceiling sits under the author notes in the template files (the
	 * shortest of those notes is a few hundred bytes) and under the expanded
	 * form a shortcode leaves inside a note. A one-line marker is shorter.
	 * It is a guard ceiling, not a measured limit of the platform.
	 */
	public const MAX_SHIPPED_BYTES = 64;

	/**
	 * Elements in which `<!--` is character data, not a comment.
	 *
	 * @var list<string>
	 */
	private const RAW = array( 'script', 'style', 'textarea', 'title' );

	/**
	 * Starts the buffer that strips comments on the way out.
	 *
	 * Front-end HTML only. Admin, AJAX, cron, feeds, REST and JSON requests
	 * are not the visitor page, and a buffer there would rewrite a response
	 * that is not a document.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 0 );
	}

	/**
	 * Opens the buffer. No-op off the public HTML document.
	 */
	public function start(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}
		ob_start( array( self::class, 'strip' ) );
	}

	/**
	 * The same string with its HTML comments removed.
	 *
	 * An unclosed `<!--` is left in place: deleting through to the end of the
	 * buffer would eat the rest of the page on a truncated flush.
	 *
	 * @param string $html The buffer, or any fragment.
	 */
	public static function strip( string $html ): string {
		if ( ! str_contains( $html, '<!--' ) ) {
			return $html;
		}
		$length = strlen( $html );
		$out    = '';
		$i      = 0;
		while ( $i < $length ) {
			$comment = strpos( $html, '<!--', $i );
			$raw     = self::next_raw( $html, $i );
			if ( false === $comment && null === $raw ) {
				$out .= substr( $html, $i );
				break;
			}
			$comment_at = false === $comment ? $length : $comment;
			$raw_open   = null === $raw ? $length : $raw['at'];
			if ( null !== $raw && $raw_open < $comment_at ) {
				$out .= substr( $html, $i, $raw['end'] - $i );
				$i    = $raw['end'];
				continue;
			}
			$out  .= substr( $html, $i, $comment_at - $i );
			$close = strpos( $html, '-->', $comment_at + 4 );
			if ( false === $close ) {
				$out .= substr( $html, $comment_at );
				break;
			}
			$i = $close + 3;
		}
		return $out;
	}

	/**
	 * The next raw-text element at or after `$from`, and where it ends.
	 *
	 * @param string $html The document.
	 * @param int    $from Byte offset.
	 * @return array{at: int, end: int}|null Null when none remains.
	 */
	private static function next_raw( string $html, int $from ): ?array {
		$best = null;
		foreach ( self::RAW as $tag ) {
			$at = self::find_open( $html, $from, $tag );
			if ( null === $at ) {
				continue;
			}
			if ( null === $best || $at < $best['at'] ) {
				$best = array(
					'at'  => $at,
					'end' => self::raw_end( $html, $at, $tag ),
				);
			}
		}
		return $best;
	}

	/**
	 * The byte offset of a real `<$tag` open, or null.
	 *
	 * A match has to be the tag, not a longer name that merely starts with
	 * it (`<scripting` is not `<script`).
	 *
	 * @param string $html The document.
	 * @param int    $from Byte offset to search from.
	 * @param string $tag  The element name, lower case.
	 */
	private static function find_open( string $html, int $from, string $tag ): ?int {
		$needle = '<' . $tag;
		$length = strlen( $html );
		$offset = $from;
		while ( $offset < $length ) {
			$at = stripos( $html, $needle, $offset );
			if ( false === $at ) {
				return null;
			}
			$after = $at + strlen( $needle );
			if ( $after >= $length ) {
				return $at;
			}
			$next = $html[ $after ];
			if ( '>' === $next || '/' === $next || ctype_space( $next ) ) {
				return $at;
			}
			$offset = $at + 1;
		}
		return null;
	}

	/**
	 * The offset just past the element's closing tag, or the end of the string.
	 *
	 * @param string $html The document.
	 * @param int    $at   Offset of the opening tag.
	 * @param string $tag  The element name.
	 */
	private static function raw_end( string $html, int $at, string $tag ): int {
		$close = stripos( $html, '</' . $tag, $at );
		if ( false === $close ) {
			return strlen( $html );
		}
		$gt = strpos( $html, '>', $close );
		return false === $gt ? strlen( $html ) : $gt + 1;
	}
}
