<?php
/**
 * Splits plain text and HTML into translatable segments with balanced placeholders.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Lossless segmenter for LiveCanvas templates, post bodies, and prose fields.
 */
final class Segmenter {

	/**
	 * Inline phrasing elements allowed inside a translatable segment.
	 */
	private const INLINE_TAGS = array(
		'a',
		'abbr',
		'b',
		'bdi',
		'bdo',
		'cite',
		'data',
		'del',
		'dfn',
		'em',
		'font',
		'i',
		'ins',
		'label',
		'mark',
		'q',
		's',
		'small',
		'span',
		'strong',
		'sub',
		'sup',
		'time',
		'u',
	);

	/**
	 * Void inline elements.
	 */
	private const VOID_INLINE = array( 'br', 'img', 'wbr' );

	/**
	 * Opaque inline elements preserved verbatim as placeholders.
	 */
	private const OPAQUE_INLINE = array( 'code', 'kbd', 'samp', 'var', 'svg', 'math' );

	/**
	 * Opaque block elements preserved verbatim.
	 */
	private const OPAQUE_BLOCK = array( 'pre', 'template', 'select', 'object', 'video', 'audio', 'canvas' );

	/**
	 * Human-visible translatable attributes on tags.
	 */
	private const TEXT_ATTRIBUTES = array( 'alt', 'title', 'aria-label', 'placeholder' );

	/**
	 * Pattern for content that must be preserved verbatim inside text:
	 * shortcodes, SEOPress variables, email addresses, URLs, and alphanumeric model codes.
	 */
	private const KEEP_PATTERN = '/\[\/?[A-Za-z][\w-]*(?:\s[^\[\]]*)?\]|%%[a-z_]+%%|[\w.+-]+@[\w-]+(?:\.[\w-]+)+|https?:\/\/[^\s<>"]+|(?<model>\b(?=[\w.\/-]*\d)(?=[\w.\/-]*[A-Z])[A-Za-z0-9]+(?:[-.\/][A-Za-z0-9]+)*\b)/';

	/**
	 * Initializer.
	 *
	 * @param HtmlTokenizer $tokenizer Tokenizer instance.
	 */
	public function __construct( private readonly HtmlTokenizer $tokenizer = new HtmlTokenizer() ) {}

	/**
	 * Segments a plain-text value.
	 *
	 * @param string $text Plain text.
	 * @return array{parts: list<mixed>, segments: list<array{text: string, raw: string, map: array<string, string>, html: bool}>}
	 */
	public function segment_text( string $text ): array {
		$core = trim( $text );
		if ( ! self::has_letters( $core ) ) {
			return array(
				'parts'    => array( $text ),
				'segments' => array(),
			);
		}

		$map     = array();
		$counter = 0;
		$masked  = $this->mask_kept( self::collapse_space( $core ), $map, $counter, false );
		if ( ! self::has_letters( TranslationValidator::strip_placeholders( $masked ) ) ) {
			return array(
				'parts'    => array( $text ),
				'segments' => array(),
			);
		}

		$start = (int) strpos( $text, $core );
		$parts = array();
		if ( $start > 0 ) {
			$parts[] = substr( $text, 0, $start );
		}
		$parts[] = 0;
		$tail    = substr( $text, $start + strlen( $core ) );
		if ( '' !== $tail ) {
			$parts[] = $tail;
		}

		return array(
			'parts'    => $parts,
			'segments' => array(
				array(
					'text' => $masked,
					'raw'  => $core,
					'map'  => $map,
					'html' => false,
				),
			),
		);
	}

	/**
	 * Segments an HTML string.
	 *
	 * @param string $html Full HTML.
	 * @return array{parts: list<mixed>, segments: list<array{text: string, raw: string, map: array<string, string>, html: bool}>}
	 */
	public function segment_html( string $html ): array {
		$tokens   = $this->tokenizer->tokens( $html );
		$parts    = array();
		$segments = array();
		$run      = array();
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] ) {
				$run[] = $token;
				continue;
			}

			if ( HtmlTokenizer::TYPE_TAG !== $token['type'] ) {
				$this->flush_run( $run, $parts, $segments );
				$parts[] = $token['raw'];
				continue;
			}

			$opaque = ! $token['closing'] && (
				in_array( $token['name'], self::OPAQUE_INLINE, true ) ||
				in_array( $token['name'], self::OPAQUE_BLOCK, true ) ||
				self::is_notranslate( $token['raw'] )
			);

			if ( $opaque ) {
				$element = $this->consume_element( $tokens, $i );
				if ( in_array( $token['name'], self::OPAQUE_INLINE, true ) ) {
					$run[] = array(
						'type'    => HtmlTokenizer::TYPE_OTHER,
						'raw'     => $element,
						'name'    => '',
						'closing' => false,
					);
				} else {
					$this->flush_run( $run, $parts, $segments );
					$parts[] = $this->process_tag( $token, $segments );
					$rest    = substr( $element, strlen( $token['raw'] ) );
					if ( '' !== $rest ) {
						$parts[] = $rest;
					}
				}
				continue;
			}

			if ( in_array( $token['name'], self::INLINE_TAGS, true ) || in_array( $token['name'], self::VOID_INLINE, true ) ) {
				$run[] = $token;
				continue;
			}

			$this->flush_run( $run, $parts, $segments );
			$parts[] = $this->process_tag( $token, $segments );
		}

		$this->flush_run( $run, $parts, $segments );

		return array(
			'parts'    => array_values( $parts ),
			'segments' => $segments,
		);
	}

	/**
	 * Reassembles translated content into the original structure.
	 *
	 * @param array{parts: list<mixed>, segments: list<array{text: string, raw: string, map: array<string, string>, html: bool}>} $doc Document.
	 * @param array<int, string>                                                                                                  $translated_segments Translated texts matching segments in order.
	 */
	public function reassemble( array $doc, array $translated_segments ): string {
		$output = '';

		foreach ( $doc['parts'] as $part ) {
			if ( is_string( $part ) ) {
				$output .= $part;
			} elseif ( is_int( $part ) ) {
				if ( isset( $doc['segments'][ $part ] ) ) {
					$seg        = $doc['segments'][ $part ];
					$translated = $translated_segments[ $part ] ?? $seg['text'];
					$output    .= $this->expand_placeholders( $translated, $seg['map'], $seg['html'] );
				}
			} elseif ( is_array( $part ) && isset( $part['tag'], $part['attributes'] ) && is_string( $part['tag'] ) && is_array( $part['attributes'] ) ) {
				$tag_str = $part['tag'];
				foreach ( $part['attributes'] as $attr => $seg_idx ) {
					if ( is_string( $attr ) && is_int( $seg_idx ) && isset( $doc['segments'][ $seg_idx ] ) ) {
						$seg        = $doc['segments'][ $seg_idx ];
						$translated = $translated_segments[ $seg_idx ] ?? $seg['text'];
						$val        = $this->expand_placeholders( $translated, $seg['map'], false );
						$tag_str    = HtmlTokenizer::with_attribute( $tag_str, $attr, $val );
					}
				}
				$output .= $tag_str;
			}
		}

		return $output;
	}

	/**
	 * Expands placeholders like <t1>, </t1>, <t2/>, <k3/> in a translated segment.
	 *
	 * @param string                $text Translated text.
	 * @param array<string, string> $map  Placeholder map.
	 * @param bool                  $html Whether target context is HTML.
	 */
	private function expand_placeholders( string $text, array $map, bool $html ): string {
		unset( $html );
		$pattern = '/<(\/?)([tk]\d+\/?)>/';
		return (string) preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $map ): string {
				$key = $matches[1] . $matches[2];
				if ( isset( $map[ $key ] ) ) {
					return $map[ $key ];
				}
				return $matches[0];
			},
			$text
		);
	}

	/**
	 * Flushes an inline run into parts and segments.
	 *
	 * @param list<array{type: string, raw: string, name: string, closing: bool}>            $run      Inline tokens.
	 * @param array<int, mixed>                                                              $parts    Document parts.
	 * @param list<array{text: string, raw: string, map: array<string, string>, html: bool}> $segments Segments.
	 */
	private function flush_run( array &$run, array &$parts, array &$segments ): void {
		if ( empty( $run ) ) {
			return;
		}

		$tokens = $run;
		$run    = array();

		// Check if there are any translatable letters in the run.
		$has_letters = false;
		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] && self::has_letters( $token['raw'] ) ) {
				$has_letters = true;
				break;
			}
		}

		if ( ! $has_letters ) {
			foreach ( $tokens as $token ) {
				$parts[] = HtmlTokenizer::TYPE_TAG === $token['type'] ? $this->process_tag( $token, $segments ) : $token['raw'];
			}
			return;
		}

		// Separate leading/trailing whitespace.
		list( $lead, $trail ) = self::trim_run_space( $tokens );
		if ( '' !== $lead ) {
			$parts[] = $lead;
		}

		if ( ! empty( $tokens ) ) {
			$parts[] = $this->build_segment( $tokens, $segments );
		}

		if ( '' !== $trail ) {
			$parts[] = $trail;
		}
	}

	/**
	 * Builds a segment from a non-empty run of tokens.
	 *
	 * @param list<array{type: string, raw: string, name: string, closing: bool}>            $tokens   Run tokens.
	 * @param list<array{text: string, raw: string, map: array<string, string>, html: bool}> $segments Document segments.
	 * @return int Segment index.
	 */
	private function build_segment( array $tokens, array &$segments ): int {
		$map     = array();
		$counter = 0;
		$text    = '';
		$raw     = '';
		$open    = array();
		$pairs   = $this->find_pairs( $tokens );

		foreach ( $tokens as $pos => $token ) {
			$raw .= $token['raw'];

			if ( HtmlTokenizer::TYPE_TEXT === $token['type'] ) {
				$decoded = html_entity_decode( $token['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$text   .= $this->mask_kept( $decoded, $map, $counter, true );
				continue;
			}

			if ( HtmlTokenizer::TYPE_OTHER === $token['type'] ) {
				$key         = 'k' . ( ++$counter ) . '/';
				$map[ $key ] = $token['raw'];
				$text       .= '<' . $key . '>';
				continue;
			}

			// Tag in inline run.
			$processed = $this->process_tag( $token, $segments );
			$tag_raw   = is_string( $processed ) ? $processed : $token['raw'];

			if ( isset( $pairs[ $pos ] ) && ! $token['closing'] ) {
				$num                    = ++$counter;
				$open[ $pairs[ $pos ] ] = $num;
				$map[ 't' . $num ]      = $tag_raw;
				$text                  .= '<t' . $num . '>';
			} elseif ( isset( $open[ $pos ] ) ) {
				$map[ '/t' . $open[ $pos ] ] = $tag_raw;
				$text                       .= '</t' . $open[ $pos ] . '>';
			} else {
				$key         = 't' . ( ++$counter ) . '/';
				$map[ $key ] = $tag_raw;
				$text       .= '<' . $key . '>';
			}
		}

		$index      = count( $segments );
		$segments[] = array(
			'text' => trim( self::collapse_space( $text ) ),
			'raw'  => $raw,
			'map'  => $map,
			'html' => true,
		);

		return $index;
	}

	/**
	 * Finds matching start/end tag pairs within an inline token run.
	 *
	 * @param list<array{type: string, raw: string, name: string, closing: bool}> $tokens Tokens.
	 * @return array<int, int> Map of start index => end index.
	 */
	private function find_pairs( array $tokens ): array {
		$pairs = array();
		$stack = array();

		foreach ( $tokens as $pos => $token ) {
			if ( HtmlTokenizer::TYPE_TAG !== $token['type'] || in_array( $token['name'], self::VOID_INLINE, true ) ) {
				continue;
			}

			if ( ! $token['closing'] ) {
				$stack[] = array(
					'pos'  => $pos,
					'name' => $token['name'],
				);
				continue;
			}

			for ( $j = count( $stack ) - 1; $j >= 0; $j-- ) {
				if ( $stack[ $j ]['name'] === $token['name'] ) {
					$pairs[ $stack[ $j ]['pos'] ] = $pos;
					array_splice( $stack, $j );
					break;
				}
			}
		}

		return $pairs;
	}

	/**
	 * Processes a tag, extracting translatable attributes as segments.
	 *
	 * @param array{type: string, raw: string, name: string, closing: bool}                  $token    Tag token.
	 * @param list<array{text: string, raw: string, map: array<string, string>, html: bool}> $segments Segments.
	 * @return string|array{tag: string, attributes: array<string, int>}
	 */
	private function process_tag( array $token, array &$segments ): string|array {
		if ( $token['closing'] ) {
			return $token['raw'];
		}

		$attributes = array();
		foreach ( self::TEXT_ATTRIBUTES as $attr ) {
			$val = HtmlTokenizer::attribute( $token['raw'], $attr );
			if ( null !== $val && self::has_letters( $val ) ) {
				$map                 = array();
				$counter             = 0;
				$masked              = $this->mask_kept( self::collapse_space( trim( $val ) ), $map, $counter, false );
				$attributes[ $attr ] = count( $segments );
				$segments[]          = array(
					'text' => $masked,
					'raw'  => $val,
					'map'  => $map,
					'html' => false,
				);
			}
		}

		if ( empty( $attributes ) ) {
			return $token['raw'];
		}

		return array(
			'tag'        => $token['raw'],
			'attributes' => $attributes,
		);
	}

	/**
	 * Masks items that must come back unchanged with <kN/> placeholders.
	 *
	 * @param string                $text    Text.
	 * @param array<string, string> $map     Placeholder map.
	 * @param int                   $counter Counter.
	 * @param bool                  $html    Whether content goes into HTML.
	 */
	private function mask_kept( string $text, array &$map, int &$counter, bool $html ): string {
		return (string) preg_replace_callback(
			self::KEEP_PATTERN,
			static function ( array $matches ) use ( &$map, &$counter, $html ): string {
				$val = $matches[0];
				$key = 'k' . ( ++$counter ) . '/';
				if ( str_starts_with( $val, '[' ) && str_ends_with( $val, ']' ) ) {
					$map[ $key ] = $val;
				} else {
					$map[ $key ] = $html ? htmlspecialchars( $val, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false ) : $val;
				}
				return '<' . $key . '>';
			},
			$text
		);
	}

	/**
	 * Trims leading and trailing whitespace from token runs.
	 *
	 * @param list<array{type: string, raw: string, name: string, closing: bool}> $tokens Tokens.
	 * @return array{0: string, 1: string}
	 */
	private static function trim_run_space( array &$tokens ): array {
		$lead  = '';
		$trail = '';

		while ( ! empty( $tokens ) && HtmlTokenizer::TYPE_TEXT === $tokens[0]['type'] ) {
			$raw     = $tokens[0]['raw'];
			$trimmed = ltrim( $raw, " \t\n\r\f" );
			$lead   .= substr( $raw, 0, strlen( $raw ) - strlen( $trimmed ) );
			if ( '' !== $trimmed ) {
				$tokens[0]['raw'] = $trimmed;
				break;
			}
			array_shift( $tokens );
		}

		while ( ! empty( $tokens ) ) {
			$last = count( $tokens ) - 1;
			if ( HtmlTokenizer::TYPE_TEXT !== $tokens[ $last ]['type'] ) {
				break;
			}
			$raw     = $tokens[ $last ]['raw'];
			$trimmed = rtrim( $raw, " \t\n\r\f" );
			$trail   = substr( $raw, strlen( $trimmed ) ) . $trail;
			if ( '' !== $trimmed ) {
				$tokens[ $last ]['raw'] = $trimmed;
				break;
			}
			array_pop( $tokens );
		}

		return array( $lead, $trail );
	}

	/**
	 * Consumes element tokens from start to matching end tag.
	 *
	 * @param list<array{type: string, raw: string, name: string, closing: bool}> $tokens Tokens.
	 * @param int                                                                 $i      Current index.
	 */
	private function consume_element( array $tokens, int &$i ): string {
		$name  = $tokens[ $i ]['name'];
		$raw   = $tokens[ $i ]['raw'];
		$depth = 1;

		if ( str_ends_with( rtrim( $raw ), '/>' ) || in_array( $name, self::VOID_INLINE, true ) ) {
			return $raw;
		}

		$count = count( $tokens );
		while ( $depth > 0 && $i + 1 < $count ) {
			$next = $tokens[ ++$i ];
			$raw .= $next['raw'];
			if ( HtmlTokenizer::TYPE_TAG === $next['type'] && $next['name'] === $name && ! str_ends_with( rtrim( $next['raw'] ), '/>' ) ) {
				$depth += $next['closing'] ? -1 : 1;
			}
		}

		return $raw;
	}

	/**
	 * Checks if a tag has translate="no" or class="notranslate".
	 *
	 * @param string $tag Tag raw string.
	 */
	private static function is_notranslate( string $tag ): bool {
		$translate = HtmlTokenizer::attribute( $tag, 'translate' );
		if ( 'no' === strtolower( (string) $translate ) ) {
			return true;
		}
		$class = HtmlTokenizer::attribute( $tag, 'class' );
		return null !== $class && str_contains( ' ' . $class . ' ', ' notranslate ' );
	}

	/**
	 * Whether a string contains human-readable letters.
	 *
	 * @param string $text Text to check.
	 */
	public static function has_letters( string $text ): bool {
		return 1 === preg_match( '/[\p{L}]/u', $text );
	}

	/**
	 * Collapses multiple whitespace characters to a single space.
	 *
	 * @param string $text Input text.
	 */
	public static function collapse_space( string $text ): string {
		return (string) preg_replace( '/[ \t\r\n\f]+/u', ' ', $text );
	}
}
