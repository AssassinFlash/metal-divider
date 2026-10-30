<?php
/**
 * The compiled WindPress cache's anchor classes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The anchor assertion of the deploy's `windpress-check` (spec 17 §5.4):
 * what the compiled cache must carry for the site to be styled at all.
 *
 * Two families, and only these two:
 *
 * - **The arbitrary-value utilities the templates use** — every class token
 *   with a `[` in a static `class` attribute under `content/livecanvas/`
 *   (`aspect-[16/10]`, `max-w-[1400px]`, `[&_li]:before:content-['—']`, …).
 *   They are the sharpest anchor in the whole cache: no volume declares them
 *   (spec 17 §5.3 — the volumes are compile *inputs*, not the scan surface),
 *   so a rule for one can only come from the compiler having read the
 *   template and built the utility. That is exactly what #58 broke: the entry
 *   lacked the Tailwind v4 utilities import, so all 502 unique arbitrary
 *   classes compiled to nothing and the page heights doubled.
 * - **The token declarations of `tokens.css`** — `--md-gold: #c9a962` and the
 *   rest of the design tokens (spec 17 §4.4.4's own acceptance sentence:
 *   after the compile the anchor declarations are in the output).
 *
 * Anchor classes that a volume declares (`md-*`, `xo-*`, the templates'
 * `md-t…` rules) are deliberately not part of this set: a volume class absent
 * from the cache is a volume or import defect, and `WindPressVolume::drift()`
 * (`wp md-site present --check`) reports it against the files. This check
 * answers the other question — did the compiler generate what only the
 * compiler can generate.
 *
 * Static attributes only: an Alpine `:class="'text-[#c9a962]'"` binding is
 * skipped by the attribute lookbehind (its value is a JS expression, not a
 * class list), and `<script>` blocks and HTML comments are stripped first so
 * a class name quoted in JS is never read as markup.
 */
final class WindPressAnchors {

	/**
	 * The compiler's output, relative to the uploads directory. The same path
	 * the deploy's mtime half of the check reads.
	 */
	private const CACHE = 'windpress/cache/tailwind.css';

	/**
	 * The template export directory, relative to this plugin.
	 */
	private const TEMPLATES = 'content/livecanvas';

	/**
	 * The template partials' subdirectory.
	 */
	private const PARTIALS = 'partials';

	/**
	 * A static class attribute, either quote style. The lookbehind refuses the
	 * framework bindings (`:class`, `x-bind:class`, `@class`), whose values are
	 * expressions rather than class lists.
	 */
	private const CLASS_ATTRIBUTE = '/(?<![\w:.\-])class\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/';

	/**
	 * A `--md-*` declaration of the tokens volume, value included.
	 */
	private const TOKEN_DECLARATION = '/(--md-[a-z0-9-]+)\s*:\s*([^;{}]+);/';

	/**
	 * The semantic hooks the card partials read from the volumes, by partial
	 * file.
	 *
	 * Deliberately a table rather than a scan of every class token: a partial
	 * also carries Tailwind utilities (styled by the compiler) and element
	 * selectors' hook classes (styled through a descendant rule, like
	 * `.cph__media img`), so "every class must appear as a selector" is false
	 * and a check built on it reports false gaps. What *is* true, and is the
	 * thing that silently breaks, is that these specific hooks are what the
	 * card's layout hangs on — a renamed one renders the card unstyled with
	 * every other check green.
	 *
	 * @var array<string, list<string>>
	 */
	private const CARD_HOOKS = array(
		'pdp-related-card.html' => array( 'pdp-related-card', 'gs-reveal' ),
		'hub-series-card.html'  => array( 'cph__card', 'cph__media', 'cph__body', 'cph__card-title', 'cph__card-copy', 'cph__card-cta' ),
		'hub-series-image.html' => array( 'cph__media-image' ),
		'card-editorial.html'   => array( 'hpl__card' ),
		'card-catalog.html'     => array( 'pattern-card', 'pattern-card__link', 'pattern-card__media', 'pattern-card__body', 'pattern-card__specs', 'pattern-card__row', 'pattern-card__key', 'pattern-card__value', 'pattern-card__action' ),
		'pattern-tile.html'     => array( 'pattern-item', 'pattern-main-card', 'pattern-preview-hint', 'pattern-meta', 'pattern-name', 'pattern-lightbox' ),
	);

	/**
	 * What the compiled cache must carry, one row per anchor.
	 *
	 * @return list<array{kind: string, anchor: string, source: string}> The
	 *         anchor (a class token, or `--name: value`), its family and the
	 *         file it comes from.
	 */
	public function anchors(): array {
		return array_values( array_merge( $this->utilities(), $this->tokens() ) );
	}

	/**
	 * The anchors the compiled cache does not carry. A cache that does not
	 * exist carries nothing, so every anchor is missing — the caller reports
	 * the absent cache itself (the deploy's mtime half reads the same file).
	 *
	 * @return list<string> Missing anchors, empty when the cache carries all.
	 */
	public function missing(): array {
		$css     = self::cache();
		$missing = array();
		foreach ( $this->anchors() as $row ) {
			$carried = '' !== $css && ( 'token' === $row['kind']
				? self::carries_token( $css, $row['anchor'] )
				: self::carries_utility( $css, $row['anchor'] ) );
			if ( ! $carried ) {
				$missing[] = $row['anchor'];
			}
		}
		return $missing;
	}

	/**
	 * Whether each card partial still carries the semantic hooks its styling
	 * targets, and whether every hook is reachable.
	 *
	 * Two failures matter and both are silent:
	 *
	 * - a partial renamed a hook (a typo in `cph__card-title`), so the volume's
	 *   rule no longer matches and the card renders unstyled;
	 * - a partial stopped carrying a hook at all, so the rule is dead and the
	 *   next editor cannot tell whether the class was ever meant to be there.
	 *
	 * @return list<array{partial: string, hook: string, detail: string}> Problems, empty when every hook is present.
	 */
	public function partial_hook_problems(): array {
		$problems = array();
		foreach ( self::CARD_HOOKS as $partial => $hooks ) {
			$path = dirname( __DIR__, 2 ) . '/' . self::TEMPLATES . '/' . self::PARTIALS . '/' . $partial;
			$html = Tokens::read( $path );
			if ( '' === $html ) {
				$problems[] = array(
					'partial' => $partial,
					'hook'    => '',
					'detail'  => 'the partial file is missing or empty',
				);
				continue;
			}
			$present = self::class_tokens( $html );
			foreach ( $hooks as $hook ) {
				if ( ! isset( $present[ $hook ] ) ) {
					$problems[] = array(
						'partial' => $partial,
						'hook'    => $hook,
						'detail'  => 'the partial no longer carries this class, so the volume rule that styles it is dead',
					);
				}
			}
		}
		return $problems;
	}

	/**
	 * The class tokens of an HTML fragment's static `class` attributes, keyed
	 * by token.
	 *
	 * @param string $html The markup.
	 * @return array<string, true>
	 */
	private static function class_tokens( string $html ): array {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace( '/<script\b.*?<\/script>/is', '', $html );
		if ( ! preg_match_all( self::CLASS_ATTRIBUTE, $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$tokens = array();
		foreach ( $matches as $match ) {
			$value = (string) ( $match[1] ?? '' );
			if ( '' === $value ) {
				$value = (string) ( $match[2] ?? '' );
			}
			$parts = preg_split( '/\s+/', trim( $value ) );
			foreach ( is_array( $parts ) ? $parts : array() as $token ) {
				if ( '' !== $token ) {
					$tokens[ $token ] = true;
				}
			}
		}
		return $tokens;
	}

	/**
	 * The compiled cache's absolute path.
	 */
	public static function cache_path(): string {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		return rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::CACHE;
	}

	/**
	 * The compiled cache's contents; empty when it cannot be read.
	 */
	public static function cache(): string {
		$path = self::cache_path();
		return '' === $path ? '' : Tokens::read( $path );
	}

	/**
	 * Absolute paths of the templates and partials, in a stable order.
	 *
	 * @return list<string>
	 */
	public static function template_files(): array {
		$base     = dirname( __DIR__, 2 ) . '/' . self::TEMPLATES;
		$files    = glob( $base . '/*.html' );
		$partials = glob( $base . '/' . self::PARTIALS . '/*.html' );
		return array_merge(
			false === $files ? array() : $files,
			false === $partials ? array() : $partials
		);
	}

	/**
	 * The arbitrary-value utilities the templates use, keyed by class token so
	 * the first file that uses one is the one reported.
	 *
	 * @return array<string, array{kind: string, anchor: string, source: string}>
	 */
	private function utilities(): array {
		$rows = array();
		foreach ( self::template_files() as $path ) {
			$html = Tokens::read( $path );
			if ( '' === $html ) {
				continue;
			}
			$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
			$html = (string) preg_replace( '/<script\b.*?<\/script>/is', '', $html );
			if ( ! preg_match_all( self::CLASS_ATTRIBUTE, $html, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$value = (string) ( $match[1] ?? '' );
				if ( '' === $value ) {
					$value = (string) ( $match[2] ?? '' );
				}
				$tokens = preg_split( '/\s+/', trim( $value ) );
				foreach ( false === $tokens ? array() : $tokens as $token ) {
					if ( '' === $token || ! str_contains( $token, '[' ) || isset( $rows[ $token ] ) ) {
						continue;
					}
					// A class token cannot carry a quote, a backtick or a tag
					// bracket; those only appear when an attribute held an
					// expression rather than a class list. `=` is *not* in this
					// set: a Tailwind arbitrary variant legitimately carries one
					// (`data-[active=true]:bg-[#c9a962]`), and refusing it would
					// drop a real anchor from the assertion.
					if ( 1 !== preg_match( '/^[^\s<>{}"`]+$/u', $token ) ) {
						continue;
					}
					$rows[ $token ] = array(
						'kind'   => 'utility',
						'anchor' => $token,
						'source' => basename( $path ),
					);
				}
			}
		}
		return $rows;
	}

	/**
	 * The tokens volume's `--md-*` declarations, keyed by `name: value`.
	 *
	 * @return array<string, array{kind: string, anchor: string, source: string}>
	 */
	private function tokens(): array {
		$rows = array();
		if ( ! preg_match_all( self::TOKEN_DECLARATION, Tokens::css(), $matches, PREG_SET_ORDER ) ) {
			return $rows;
		}
		foreach ( $matches as $match ) {
			$anchor = $match[1] . ': ' . trim( $match[2] );
			if ( isset( $rows[ $anchor ] ) ) {
				continue;
			}
			$rows[ $anchor ] = array(
				'kind'   => 'token',
				'anchor' => $anchor,
				'source' => basename( Tokens::path() ),
			);
		}
		return $rows;
	}

	/**
	 * Whether the compiled CSS carries a rule for a utility class.
	 *
	 * The selector is compared in the compiler's own escaping (every ASCII
	 * character outside `[A-Za-z0-9_-]` backslash-escaped; verified against
	 * the dev instance's cache: 493/493 arbitrary utilities resolve this way),
	 * between class-name boundaries so `.flex` cannot match inside `.flex-1`,
	 * and a match whose rule body is empty does not count: a selector that
	 * compiled to `{}` is the #58 failure in miniature.
	 *
	 * @param string $css   The compiled cache.
	 * @param string $token The class token as the template writes it.
	 */
	private static function carries_utility( string $css, string $token ): bool {
		$needle = '.' . self::escaped( $token );
		$length = strlen( $needle );
		$offset = 0;
		$at     = strpos( $css, $needle, $offset );
		while ( false !== $at ) {
			$before = $at > 0 ? $css[ $at - 1 ] : '';
			$after  = $at + $length < strlen( $css ) ? $css[ $at + $length ] : '';
			if ( ! self::is_class_char( $before ) && ! self::is_class_char( $after )
				&& '' !== self::rule_body( $css, $at + $length ) ) {
				return true;
			}
			$offset = $at + 1;
			$at     = strpos( $css, $needle, $offset );
		}
		return false;
	}

	/**
	 * Whether the compiled CSS declares a token with the same value.
	 *
	 * The comparison is CSS-equivalent, not byte-equal: the compiler minifies
	 * (`--md-gold-light: #d4b878` becomes `--md-gold-light:#d4b878`), so
	 * whitespace and letter case are normalised away on both sides.
	 *
	 * @param string $css    The compiled cache.
	 * @param string $anchor The declaration as `name: value`.
	 */
	private static function carries_token( string $css, string $anchor ): bool {
		$colon = strpos( $anchor, ':' );
		if ( false === $colon ) {
			return false;
		}
		$name   = substr( $anchor, 0, $colon );
		$want   = self::normalised( substr( $anchor, $colon + 1 ) );
		$start  = strlen( $name ) + 1;
		$offset = 0;
		$at     = strpos( $css, $name . ':', $offset );
		while ( false !== $at ) {
			$value = substr( $css, $at + $start, strcspn( $css, ';}', $at + $start ) );
			if ( self::normalised( $value ) === $want ) {
				return true;
			}
			$offset = $at + 1;
			$at     = strpos( $css, $name . ':', $offset );
		}
		return false;
	}

	/**
	 * The body of the rule whose selector ends at `$offset`.
	 *
	 * @param string $css    The compiled cache.
	 * @param int    $offset Where the selector's text ends.
	 */
	private static function rule_body( string $css, int $offset ): string {
		$open = strpos( $css, '{', $offset );
		if ( false === $open ) {
			return '';
		}
		$close = strpos( $css, '}', $open );
		if ( false === $close ) {
			return '';
		}
		return trim( substr( $css, $open + 1, $close - $open - 1 ) );
	}

	/**
	 * A class token in the compiler's selector escaping.
	 *
	 * Only ASCII is escaped: the compiler leaves multi-byte characters in an
	 * arbitrary value alone (`content-['—']` compiles with the em dash raw),
	 * and escaping those bytes — `preg_match` is byte-oriented here — would
	 * build a needle that can never match.
	 *
	 * @param string $token The class token.
	 */
	private static function escaped( string $token ): string {
		$escaped = '';
		$length  = strlen( $token );
		for ( $i = 0; $i < $length; $i++ ) {
			$char     = $token[ $i ];
			$plain    = ord( $char ) > 127 || 1 === preg_match( '/[A-Za-z0-9_-]/', $char );
			$escaped .= $plain ? $char : '\\' . $char;
		}
		return $escaped;
	}

	/**
	 * Whether a character continues a class name (an escaped byte included).
	 *
	 * Every non-ASCII byte counts as part of a class name: a multi-byte
	 * character inside an arbitrary value is one token in the selector, so it
	 * must not read as a boundary.
	 *
	 * @param string $char One character, '' at either end of the cache.
	 */
	private static function is_class_char( string $char ): bool {
		return '' !== $char && ( ord( $char ) > 127 || 1 === preg_match( '/[\\\\A-Za-z0-9_-]/', $char ) );
	}

	/**
	 * A declaration value with the differences CSS ignores removed.
	 *
	 * @param string $value A declaration value.
	 */
	private static function normalised( string $value ): string {
		return strtolower( (string) preg_replace( '/\s+/', '', $value ) );
	}
}
