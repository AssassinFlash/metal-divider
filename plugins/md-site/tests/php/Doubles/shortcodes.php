<?php
/**
 * WordPress's shortcode engine, for the tests that render the card partials.
 *
 * @package MetalDivider\Site
 */

// phpcs:disable -- test doubles mirror WordPress's own function names and signatures.

/*
 * WP_Mock mocks *functions*, and it does not ship the shortcode API: the
 * engine is `wp-includes/shortcodes.php`, which the unit suite never loads.
 * The card partials are rendered with `do_shortcode()`, so the tests need a
 * real engine rather than a mock — a mock would let a partial render with its
 * shortcodes silently unexpanded, which is the defect the renderer exists to
 * prevent.
 *
 * The tag regex, the attribute parser and the tag expander below are
 * WordPress's own, copied from `wp-includes/shortcodes.php` (7.1.3) with the
 * group numbering intact, because the numbering is the contract:
 *
 *   1  the optional second `[` of an escaped tag (`[[tag]]`)
 *   2  the tag name
 *   3  the attribute text
 *   4  `/` when the tag is self-closing
 *   5  the enclosed content
 *   6  the optional second `]` of an escaped tag
 *
 * One part is deliberately not modelled: `do_shortcodes_in_html_tags()`, the
 * pass that rewrites brackets inside HTML tags before the expander runs, and
 * its KSES attribute parser. Its observable rule matters to the templates and
 * is asserted directly instead — see the partials' attribute test: a
 * shortcode written inside a quoted HTML attribute must not carry double
 * quotes of its own, because the attribute would end at the inner quote and
 * WordPress would entity-encode the brackets instead of expanding the tag
 * (measured on the dev instance, 2026-10-26). Modelling that parser here would
 * duplicate a thousand lines of KSES to re-test core.
 */

if ( ! function_exists( 'shortcode_parse_atts' ) ) {
	/**
	 * WordPress's attribute parser: `name="value"`, `name='value'`,
	 * `name=value`, bare positional parts.
	 *
	 * @param string $text The attribute text between the tag and the bracket.
	 * @return array<int|string, string> The attributes.
	 */
	function shortcode_parse_atts( $text ) {
		$atts        = array();
		$pattern     = get_shortcode_atts_regex();
		$text        = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', (string) $text );
		$text        = trim( (string) $text );
		$positional  = 0;
		if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
			foreach ( $match as $m ) {
				if ( ! empty( $m[1] ) ) {
					$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
				} elseif ( ! empty( $m[3] ) ) {
					$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
				} elseif ( ! empty( $m[5] ) ) {
					$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
				} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
					$atts[ $positional ] = stripcslashes( $m[7] );
					++$positional;
				} else {
					$atts[] = stripcslashes( $m[0] );
				}
			}
		}
		return $atts;
	}
}

if ( ! function_exists( 'get_shortcode_atts_regex' ) ) {
	/**
	 * WordPress's attribute regex.
	 *
	 * @return string The pattern.
	 */
	function get_shortcode_atts_regex() {
		return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	/**
	 * WordPress's defaults merge: unknown keys are dropped, known ones keep
	 * their default, positional values fill the known keys in order.
	 *
	 * @param array<string, mixed>     $pairs     The defaults.
	 * @param array<int|string, mixed> $atts      The supplied attributes.
	 * @param string                   $shortcode The tag (unused by the algorithm).
	 * @return array<string, mixed> The merged attributes.
	 */
	function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
		unset( $shortcode );
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		$index = 0;
		$names = array_keys( $pairs );
		foreach ( $atts as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( isset( $names[ $index ] ) ) {
					$out[ $names[ $index ] ] = $value;
				}
				++$index;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	/**
	 * WordPress's tag regex, verbatim from `wp-includes/shortcodes.php`.
	 *
	 * @param array<int, string>|null $tagnames The tags, null for the registered ones.
	 * @return string The pattern.
	 */
	function get_shortcode_regex( $tagnames = null ) {
		global $shortcode_tags;
		if ( empty( $tagnames ) ) {
			$tagnames = array_keys( $shortcode_tags );
		}
		$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

		// The indentation below is WordPress's own: keeping it lets the groups be compared line for line against wp-includes/shortcodes.php.
		return '\\['                             // Opening bracket.
			. '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
			. "($tagregexp)"                     // 2: Shortcode name.
			. '(?![\\w-])'                       // Not followed by word character or hyphen.
			. '('                                // 3: Unroll the loop: Inside the opening shortcode tag.
			.     '[^\\]\\/]*'                   // Not a closing bracket or forward slash.
			.     '(?:'
			.         '\\/(?!\\])'               // A forward slash not followed by a closing bracket.
			.         '[^\\]\\/]*'               // Not a closing bracket or forward slash.
			.     ')*?'
			. ')'
			. '(?:'
			.     '(\\/)'                        // 4: Self closing tag...
			.     '\\]'                          // ...and closing bracket.
			. '|'
			.     '\\]'                          // Closing bracket.
			.     '(?:'
			.         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
			.             '[^\\[]*+'             // Not an opening bracket.
			.             '(?:'
			.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
			.                 '[^\\[]*+'         // Not an opening bracket.
			.             ')*+'
			.         ')'
			.         '\\[\\/\\2\\]'             // Closing shortcode tag.
			.     ')?'
			. ')'
			. '(\\]?)';                          // 6: Optional second closing bracket for escaping shortcodes: [[tag]].
	}
}

if ( ! function_exists( 'do_shortcode_tag' ) ) {
	/**
	 * WordPress's single-tag expander.
	 *
	 * @param array<int, string> $m The regex match.
	 * @return string The replacement.
	 */
	function do_shortcode_tag( $m ) {
		global $shortcode_tags;
		if ( '[' === ( $m[1] ?? '' ) && ']' === ( $m[6] ?? '' ) ) {
			return substr( $m[0], 1, -1 );
		}
		$tag = $m[2];
		if ( ! isset( $shortcode_tags[ $tag ] ) || ! is_callable( $shortcode_tags[ $tag ] ) ) {
			return $m[0];
		}
		$attr    = shortcode_parse_atts( $m[3] );
		$attr    = is_array( $attr ) ? $attr : array();
		$content = $m[5] ?? null;
		return ( $m[1] ?? '' ) . (string) call_user_func( $shortcode_tags[ $tag ], $attr, $content, $tag ) . ( $m[6] ?? '' );
	}
}

if ( ! function_exists( 'do_shortcode' ) ) {
	/**
	 * WordPress's expander: one pass over the tags the content actually names.
	 *
	 * A tag's callback may itself call `do_shortcode()` (the partial renderer
	 * does, once per nested partial), which is how the templates' composition
	 * nests without this function recursing.
	 *
	 * @param string $content The content.
	 * @return string The content with every known tag expanded.
	 */
	function do_shortcode( $content ) {
		global $shortcode_tags;
		if ( ! is_string( $content ) || ! str_contains( $content, '[' ) ) {
			return (string) $content;
		}
		if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
			return $content;
		}
		preg_match_all( '@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches );
		$tagnames = array_intersect( array_keys( $shortcode_tags ), $matches[1] );
		if ( empty( $tagnames ) ) {
			return $content;
		}
		$pattern = get_shortcode_regex( $tagnames );
		return (string) preg_replace_callback( "/$pattern/", 'do_shortcode_tag', $content );
	}
}

/*
 * `add_shortcode()` is deliberately NOT defined here. WP_Mock mocks a
 * function by declaring it (when WordPress's own is absent), and it cannot
 * redeclare one that already exists without Patchwork — so a double here
 * would silently disarm every existing test that asserts what a `register()`
 * method registers. A test that needs the registry fills it the way the
 * production code does: through a mocked `add_shortcode()` writing into
 * `$GLOBALS['shortcode_tags']`, which the engine above then reads.
 */

if ( ! function_exists( 'shortcode_tags_reset' ) ) {
	/**
	 * Empties the registry, so one test's shortcodes cannot answer another's
	 * render. WordPress has no such function; the registry is global state.
	 */
	function shortcode_tags_reset() {
		global $shortcode_tags;
		$shortcode_tags = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the double's own registry.
	}
}
