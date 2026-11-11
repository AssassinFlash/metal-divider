<?php
/**
 * Lossless HTML tokenizer for templates and content.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Tokenizes HTML into tags, text, comments, and verbatim raw-text blocks.
 *
 * Concatenating token raw strings reproduces the original HTML byte-for-byte.
 */
final class HtmlTokenizer {

	public const TYPE_TEXT    = 'text';
	public const TYPE_TAG     = 'tag';
	public const TYPE_COMMENT = 'comment';
	public const TYPE_OTHER   = 'other';

	/**
	 * Elements whose inner content is raw text or opaque code/graphics.
	 */
	private const RAW_TEXT_TAGS = array(
		'script',
		'style',
		'textarea',
		'title',
		'svg',
		'code',
		'pre',
		'xmp',
		'iframe',
		'noembed',
		'noframes',
		'noscript',
	);

	/**
	 * Regex matching HTML start/end tags. Quoted values may contain `>`.
	 */
	private const TAG_REGEX = '/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:\s+[^\s"\'>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?|\s*\/(?!>))*\s*)(\/?)>/s';

	/**
	 * Tokenizes an HTML string into tokens.
	 *
	 * @param string $html HTML content.
	 * @return list<array{type: string, raw: string, name: string, closing: bool}>
	 */
	public function tokens( string $html ): array {
		$tokens = array();
		$length = strlen( $html );
		$at     = 0;

		while ( $at < $length ) {
			$open = strpos( $html, '<', $at );
			if ( false === $open ) {
				$tokens[] = array(
					'type'    => self::TYPE_TEXT,
					'raw'     => substr( $html, $at ),
					'name'    => '',
					'closing' => false,
				);
				break;
			}

			if ( $open > $at ) {
				$tokens[] = array(
					'type'    => self::TYPE_TEXT,
					'raw'     => substr( $html, $at, $open - $at ),
					'name'    => '',
					'closing' => false,
				);
				$at       = $open;
			}

			// HTML Comment <!-- ... -->.
			if ( 0 === substr_compare( $html, '<!--', $at, 4 ) ) {
				$end      = strpos( $html, '-->', $at + 4 );
				$end      = false === $end ? $length : $end + 3;
				$tokens[] = array(
					'type'    => self::TYPE_COMMENT,
					'raw'     => substr( $html, $at, $end - $at ),
					'name'    => '',
					'closing' => false,
				);
				$at       = $end;
				continue;
			}

			// Doctype or processing instruction <! ... > or <? ... >.
			if ( 1 === preg_match( '/\G<[!?]/', $html, $unused, 0, $at ) ) {
				$end      = strpos( $html, '>', $at );
				$end      = false === $end ? $length : $end + 1;
				$tokens[] = array(
					'type'    => self::TYPE_OTHER,
					'raw'     => substr( $html, $at, $end - $at ),
					'name'    => '',
					'closing' => false,
				);
				$at       = $end;
				continue;
			}

			// HTML Tag.
			if ( 1 !== preg_match( self::TAG_REGEX, $html, $match, 0, $at ) ) {
				// Bare '<' without tag syntax.
				$tokens[] = array(
					'type'    => self::TYPE_TEXT,
					'raw'     => '<',
					'name'    => '',
					'closing' => false,
				);
				++$at;
				continue;
			}

			$name     = strtolower( $match[2] );
			$closing  = '/' === $match[1];
			$tokens[] = array(
				'type'    => self::TYPE_TAG,
				'raw'     => $match[0],
				'name'    => $name,
				'closing' => $closing,
			);
			$at      += strlen( $match[0] );

			// If opening raw-text / opaque element, consume content verbatim until closing tag.
			if ( ! $closing && '/' !== $match[4] && in_array( $name, self::RAW_TEXT_TAGS, true ) ) {
				$at = $this->consume_raw_text( $html, $at, $name, $tokens );
			}
		}

		return $tokens;
	}

	/**
	 * Consumes raw-text element content until closing tag.
	 *
	 * @param string                                                              $html   Full HTML.
	 * @param int                                                                 $at     Offset after opening tag.
	 * @param string                                                              $name   Tag name.
	 * @param list<array{type: string, raw: string, name: string, closing: bool}> $tokens Token list so far.
	 * @return int New offset.
	 */
	private function consume_raw_text( string $html, int $at, string $name, array &$tokens ): int {
		if ( 1 !== preg_match( '/<\/' . preg_quote( $name, '/' ) . '\s*>/i', $html, $end, PREG_OFFSET_CAPTURE, $at ) ) {
			$tokens[] = array(
				'type'    => self::TYPE_OTHER,
				'raw'     => substr( $html, $at ),
				'name'    => '',
				'closing' => false,
			);
			return strlen( $html );
		}

		$close = (int) $end[0][1];
		if ( $close > $at ) {
			$tokens[] = array(
				'type'    => self::TYPE_OTHER,
				'raw'     => substr( $html, $at, $close - $at ),
				'name'    => '',
				'closing' => false,
			);
		}

		$tokens[] = array(
			'type'    => self::TYPE_TAG,
			'raw'     => $end[0][0],
			'name'    => $name,
			'closing' => true,
		);

		return $close + strlen( $end[0][0] );
	}

	/**
	 * Extracts an attribute value from a raw tag string.
	 *
	 * @param string $tag       Tag raw string.
	 * @param string $attribute Attribute name.
	 */
	public static function attribute( string $tag, string $attribute ): ?string {
		$match = self::find_attribute( $tag, $attribute );
		return null === $match ? null : html_entity_decode( $match['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Replaces an attribute value in a raw tag string.
	 *
	 * @param string $tag       Tag raw string.
	 * @param string $attribute Attribute name.
	 * @param string $value     New unencoded attribute value.
	 */
	public static function with_attribute( string $tag, string $attribute, string $value ): string {
		$match = self::find_attribute( $tag, $attribute );
		if ( null === $match ) {
			return $tag;
		}

		$encoded = '"' . htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false ) . '"';
		return substr( $tag, 0, $match['offset'] ) . $encoded . substr( $tag, $match['offset'] + $match['length'] );
	}

	/**
	 * Finds attribute offset, length, and value in a tag.
	 *
	 * @param string $tag       Tag string.
	 * @param string $attribute Attribute name.
	 * @return array{value: string, offset: int, length: int}|null
	 */
	private static function find_attribute( string $tag, string $attribute ): ?array {
		$pattern = '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i';
		if ( 1 !== preg_match( $pattern, $tag, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$value = '';
		foreach ( array( 2, 3, 4 ) as $group ) {
			if ( isset( $match[ $group ] ) && -1 !== $match[ $group ][1] ) {
				$value = $match[ $group ][0];
				break;
			}
		}

		return array(
			'value'  => $value,
			'offset' => (int) $match[1][1],
			'length' => strlen( $match[1][0] ),
		);
	}
}
