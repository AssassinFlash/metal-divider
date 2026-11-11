<?php
/**
 * Translation validation and guardrail rules for Metal Divider I18n.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

/**
 * Validates translated segments against placeholder integrity, figure accuracy,
 * brand term preservation, and banned terminology (metals/commerce semantics).
 */
final class TranslationValidator {

	/**
	 * Proper brand terms that must be preserved verbatim whenever present in source.
	 */
	private const PRESERVED_TERMS = array(
		'Metal Divider',
		'MetalDivider',
		'WhatsApp',
		'304',
		'316',
	);

	/**
	 * Non-304/316 metals prohibited in translations unless present in English source.
	 */
	private const BANNED_METALS = array(
		'acero corten',
		'acero cortén',
		'aço corten',
		'acier corten',
		'cortenstahl',
		'weathering steel',
		'耐候钢',
		'考顿钢',
		'紫铜',
		'红铜',
		'纯铜',
		'黄铜',
		'铝',
		'低碳钢',
		'碳钢',
		'alumin',
		'copper',
		'mild steel',
		'carbon steel',
		'brass',
	);

	/**
	 * Commerce and shopping cart terms prohibited on this B2B inquiry site.
	 */
	private const BANNED_COMMERCE = array(
		'购物车',
		'结账',
		'结算',
		'立即购买',
		'加入购物车',
		'下单',
		'cart',
		'checkout',
		'buy now',
		'add to cart',
		'shopping bag',
		'order now',
		'carrito',
		'comprar ahora',
		'añadir al carrito',
		'carrinho',
		'adicionar ao carrinho',
		'finalizar compra',
		'warenkorb',
		'jetzt kaufen',
		'in den warenkorb',
		'panier',
		'acheter maintenant',
		'ajouter au panier',
		'عربة التسوق',
		'سلة التسوق',
		'شراء الآن',
		'أضف إلى السلة',
		'keranjang belanja',
		'beli sekarang',
		'tambah ke keranjang',
		'giỏ hàng',
		'mua ngay',
		'thêm vào giỏ',
	);

	/**
	 * Arabic Eastern numeral to Western ASCII digit translation table.
	 */
	private const ARABIC_DIGIT_MAP = array(
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Normalizes Eastern Arabic numerals to Western ASCII digits.
	 *
	 * @param string $text Input text.
	 */
	public static function normalize_digits( string $text ): string {
		return strtr( $text, self::ARABIC_DIGIT_MAP );
	}

	/**
	 * English spelled-out number words mapped to their canonical digit strings,
	 * allowing natural target-language numerals when the English prose spelled a number out.
	 */
	private const ENGLISH_NUMBER_WORDS = array(
		'0'    => array( 'zero', 'none' ),
		'1'    => array( 'one', 'single', 'first', 'once', 'a', 'an', 'per' ),
		'2'    => array( 'two', 'both', 'pair', 'second', 'twice', 'double', 'dual', 'sqm', 'sq' ),
		'3'    => array( 'three', 'third', 'triple', 'trio' ),
		'4'    => array( 'four', 'fourth', 'quad' ),
		'5'    => array( 'five', 'fifth' ),
		'6'    => array( 'six', 'sixth', 'half-dozen' ),
		'7'    => array( 'seven', 'seventh' ),
		'8'    => array( 'eight', 'eighth' ),
		'9'    => array( 'nine', 'ninth' ),
		'10'   => array( 'ten', 'tenth', 'decade' ),
		'11'   => array( 'eleven' ),
		'12'   => array( 'twelve', 'dozen' ),
		'13'   => array( 'thirteen' ),
		'14'   => array( 'fourteen', 'fortnight' ),
		'15'   => array( 'fifteen' ),
		'16'   => array( 'sixteen' ),
		'17'   => array( 'seventeen' ),
		'18'   => array( 'eighteen' ),
		'19'   => array( 'nineteen' ),
		'20'   => array( 'twenty' ),
		'24'   => array( 'twenty-four' ),
		'25'   => array( 'twenty-five', 'quarter' ),
		'30'   => array( 'thirty' ),
		'40'   => array( 'forty' ),
		'48'   => array( 'forty-eight' ),
		'50'   => array( 'fifty', 'half' ),
		'60'   => array( 'sixty' ),
		'70'   => array( 'seventy' ),
		'72'   => array( 'seventy-two' ),
		'80'   => array( 'eighty' ),
		'90'   => array( 'ninety' ),
		'100'  => array( 'hundred' ),
		'1000' => array( 'thousand' ),
	);

	/**
	 * Target-language number words/prefixes for small counting integers 1..10 and 24
	 * (e.g. "3-dimensional" -> "tridimensional" / "三维", "5-star" -> "五星级").
	 */
	private const TARGET_NUMBER_WORDS = array(
		'1'  => array( '一', '单', '首', 'uno', 'una', 'primero', 'primera', 'uma', 'primeiro', 'primeira', 'ein', 'eine', 'erste', 'une', 'premier', 'première', 'واحد', 'أول', 'أحاد', 'satu', 'pertama', 'một', 'nhất', 'đơn' ),
		'2'  => array( '二', '两', '双', 'dos', 'bidimensional', 'segundo', 'segunda', 'dois', 'duas', 'zwei', 'beide', 'doppelt', 'deux', 'double', 'اثن', 'ثان', 'ثنائ', 'مزدوج', 'ان', 'ين', 'dua', 'kedua', 'hai', 'nhị', 'đôi' ),
		'3'  => array( '三', 'tres', 'tridimensional', 'tridimensionnel', 'tercer', 'três', 'drei', 'dritt', 'trois', 'ثلاث', 'ثالث', 'tiga', 'ketiga', 'ba', 'tam' ),
		'4'  => array( '四', 'cuatro', 'cuart', 'quatro', 'vier', 'quatre', 'أربع', 'رابع', 'رباع', 'empat', 'keempat', 'bốn', 'tứ' ),
		'5'  => array( '五', 'cinco', 'quint', 'fünf', 'cinq', 'خمس', 'خامس', 'خماس', 'lima', 'kelima', 'năm', 'ngũ' ),
		'6'  => array( '六', 'seis', 'sext', 'sechs', 'six', 'ست', 'سادس', 'سداس', 'enam', 'keenam', 'sáu', 'lục' ),
		'7'  => array( '七', 'siete', 'sete', 'sieben', 'sept', 'سبع', 'سابع', 'tujuh', 'ketujuh', 'bảy', 'thất' ),
		'8'  => array( '八', 'ocho', 'oito', 'acht', 'huit', 'ثمان', 'ثامن', 'delapan', 'kedelapan', 'tám', 'bát' ),
		'9'  => array( '九', 'nueve', 'nove', 'neun', 'neuf', 'تسع', 'تاسع', 'sembilan', 'kesembilan', 'chín', 'cửu' ),
		'10' => array( '十', 'diez', 'dez', 'zehn', 'dix', 'عشر', 'عاشر', 'sepuluh', 'kesepuluh', 'mười', 'thập' ),
		'24' => array( '二十四', 'veinticuatro', 'vinte e quatro', 'vierundzwanzig', 'vingt-quatre', 'أربع وعشر', 'أربعة وعشر', 'dua puluh empat', 'hai mươi tư', 'hai mươi bốn' ),
	);

	/**
	 * A figure: digits with decimal separators, or thousands separated by a
	 * point, comma, or (no-break) space before three digits.
	 */
	private const FIGURE_PATTERN = '/\d+(?:[.,]\d+|[ \x{00A0}\x{202F}]\d{3}(?!\d))*/u';

	/**
	 * Validates a translated segment against the English source.
	 *
	 * @param string $source      English source segment with placeholders.
	 * @param string $translation Target translation segment with placeholders.
	 * @param string $lang         Target language canonical code.
	 * @return list<string> List of validation errors (empty if valid).
	 */
	public function validate( string $source, string $translation, string $lang ): array {
		$problems = array();
		$trimmed  = trim( $translation );

		if ( '' === $trimmed ) {
			return array( 'Empty translation' );
		}

		// Normalize any Eastern Arabic digits in target.
		$normalized_trans = self::normalize_digits( $trimmed );

		// 1. Placeholder multiset equality and nesting check.
		$source_placeholders = self::extract_placeholders( $source );
		$trans_placeholders  = self::extract_placeholders( $normalized_trans );

		$sorted_source = $source_placeholders;
		$sorted_trans  = $trans_placeholders;
		sort( $sorted_source );
		sort( $sorted_trans );

		if ( $sorted_source !== $sorted_trans ) {
			$problems[] = sprintf(
				'Placeholders mismatch: expected [%s], got [%s]',
				implode( ', ', $sorted_source ),
				implode( ', ', $sorted_trans )
			);
		} else {
			// Check proper nesting of <tN> and </tN>.
			$open_stack = array();
			$nested_ok  = true;
			foreach ( $trans_placeholders as $ph ) {
				if ( str_starts_with( $ph, '</t' ) ) {
					$tag_num = substr( $ph, 3, -1 );
					$last    = array_pop( $open_stack );
					if ( $last !== $tag_num ) {
						$nested_ok = false;
						break;
					}
				} elseif ( str_starts_with( $ph, '<t' ) && ! str_ends_with( $ph, '/>' ) ) {
					$tag_num      = substr( $ph, 2, -1 );
					$open_stack[] = $tag_num;
				}
			}
			if ( ! $nested_ok || ! empty( $open_stack ) ) {
				$problems[] = 'Placeholders improperly nested in translation.';
			}
		}

		// 2. Preserved terms check.
		foreach ( self::PRESERVED_TERMS as $term ) {
			if ( str_contains( $source, $term ) && ! str_contains( $normalized_trans, $term ) ) {
				$problems[] = sprintf( 'Preserved brand term missing: "%s"', $term );
			}
		}

		// 3. Figures equality check (excluding placeholder numbers).
		$source_clean = (string) preg_replace( '/<(\/?)[tk]\d+\/?>/', ' ', $source );
		$trans_clean  = (string) preg_replace( '/<(\/?)[tk]\d+\/?>/', ' ', $normalized_trans );
		if ( ! self::figures_compatible( $source_clean, $trans_clean ) ) {
			$problems[] = sprintf(
				'Figures changed: source [%s] vs target [%s]',
				implode( ', ', self::extract_figures( $source_clean ) ),
				implode( ', ', self::extract_figures( $trans_clean ) )
			);
		}

		// 4. Banned metal words (only if English source didn't mention other metals/finishes).
		$source_lower            = strtolower( $source );
		$source_has_other_metals = false;
		foreach ( array( 'corten', 'alumin', 'copper', 'mild steel', 'carbon steel', 'brass', 'bronze', 'iron', 'patina', 'weathered' ) as $m ) {
			if ( str_contains( $source_lower, $m ) ) {
				$source_has_other_metals = true;
				break;
			}
		}

		if ( ! $source_has_other_metals ) {
			$metals_to_check = self::BANNED_METALS;
			if ( ! in_array( $lang, array( 'es', 'pt-br' ), true ) ) {
				$metals_to_check[] = 'corten';
			}
			foreach ( $metals_to_check as $banned ) {
				if ( self::contains_banned_term( $normalized_trans, $banned ) ) {
					$problems[] = sprintf( 'Banned metal mentioned: "%s"', $banned );
					break;
				}
			}
		}

		// 5. Banned cart / checkout terms (only if English source didn't mention them).
		$source_has_commerce = false;
		foreach ( array( 'cart', 'checkout', 'buy now', 'order', 'basket' ) as $c ) {
			if ( self::contains_banned_term( $source, $c ) ) {
				$source_has_commerce = true;
				break;
			}
		}

		if ( ! $source_has_commerce ) {
			foreach ( self::BANNED_COMMERCE as $banned ) {
				if ( self::contains_banned_term( $normalized_trans, $banned ) ) {
					$problems[] = sprintf( 'Banned commerce term mentioned: "%s"', $banned );
					break;
				}
			}
		}

		// 6. Chinese glossary guard (GLOSSARY.md: 图案 not 款式/花型; 询盘/项目/委托/批次 not 订单; 图案/屏风/板材 not 产品).
		if ( 'zh' === $lang ) {
			if ( ! str_contains( $source_lower, 'style' ) ) {
				foreach ( array( '款式', '花型' ) as $banned_zh ) {
					if ( str_contains( $normalized_trans, $banned_zh ) ) {
						$problems[] = sprintf( 'Banned Chinese glossary term: "%s" (use 图案)', $banned_zh );
						break;
					}
				}
			}

			if ( str_contains( $normalized_trans, '订单' ) ) {
				$problems[] = 'Banned Chinese glossary term: "订单" (NEVER translate order/orders as 订单; use 项目 / 委托 / 批次)';
			}

			if ( str_contains( $normalized_trans, '产品' ) ) {
				$problems[] = 'Banned Chinese glossary term: "产品" (NEVER translate product/products as 产品; use 图案 / 屏风 / 板材)';
			}
		}

		return $problems;
	}

	/**
	 * Checks whether text contains a banned term: substring match for CJK scripts,
	 * prefix match for `alumin*`, and Unicode word-boundary match for alphabetic phrases.
	 *
	 * @param string $text   Target text.
	 * @param string $banned Banned phrase.
	 */
	private static function contains_banned_term( string $text, string $banned ): bool {
		if ( 1 === preg_match( '/\p{Han}/u', $banned ) ) {
			return str_contains( $text, $banned );
		}
		if ( 'alumin' === $banned ) {
			return 1 === preg_match( '/(?<![\p{L}\p{N}])alumin/iu', $text );
		}
		return 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $banned, '/' ) . '(?![\p{L}\p{N}])/iu', $text );
	}

	/**
	 * Extracts all placeholders from text in order of appearance.
	 *
	 * @param string $text Text with placeholders.
	 * @return list<string>
	 */
	public static function extract_placeholders( string $text ): array {
		if ( false === preg_match_all( '/<(\/?)[tk]\d+\/?>/', $text, $matches ) || empty( $matches[0] ) ) {
			return array();
		}
		return $matches[0];
	}

	/**
	 * Strips all placeholders from text.
	 *
	 * @param string $text Text with placeholders.
	 */
	public static function strip_placeholders( string $text ): string {
		return (string) preg_replace( '/<(\/?)[tk]\d+\/?>/', '', $text );
	}

	/**
	 * Extracts numeric sequences from text, sorted.
	 *
	 * @param string $text Text without placeholders.
	 * @return list<string>
	 */
	private static function extract_figures( string $text ): array {
		if ( false === preg_match_all( self::FIGURE_PATTERN, $text, $matches ) || empty( $matches[0] ) ) {
			return array();
		}
		$figures = array();
		foreach ( $matches[0] as $match ) {
			// Strip thousand separators and commas to canonical integer/float string.
			$digits = preg_replace( '/[^\d]/', '', $match );
			if ( is_string( $digits ) && '' !== $digits ) {
				$figures[] = $digits;
			}
		}
		sort( $figures );
		return $figures;
	}

	/**
	 * Verifies that every numeric figure in the source appears in the translation,
	 * and any extra numeric figure in the translation corresponds to a spelled-out
	 * English number word in the source.
	 *
	 * @param string $source_clean Source text without placeholders.
	 * @param string $trans_clean  Target text without placeholders.
	 */
	private static function figures_compatible( string $source_clean, string $trans_clean ): bool {
		$src_figs = self::extract_figures( $source_clean );
		$tr_figs  = self::extract_figures( $trans_clean );

		if ( $src_figs === $tr_figs ) {
			return true;
		}

		$src_counts = array_count_values( $src_figs );
		$tr_counts  = array_count_values( $tr_figs );

		foreach ( $src_counts as $fig => $needed ) {
			$fig_key = (string) $fig;
			if ( ( $tr_counts[ $fig_key ] ?? 0 ) >= $needed ) {
				continue;
			}
			$target_words = self::TARGET_NUMBER_WORDS[ $fig_key ] ?? array();
			$matched_word = false;
			foreach ( $target_words as $tw ) {
				if ( mb_stripos( $trans_clean, $tw ) !== false ) {
					$matched_word = true;
					break;
				}
			}
			if ( ! $matched_word ) {
				// Allow decade words (e.g. "1920s-30s", "60s or 70s" -> Arabic العشرينيات / الستينيات / السبعينيات).
				if ( 1 === preg_match( '/\b' . preg_quote( $fig_key, '/' ) . 's\b/i', $source_clean ) ) {
					continue;
				}
				return false;
			}
		}

		foreach ( $tr_counts as $fig => $found ) {
			$fig_key = (string) $fig;
			$surplus = $found - ( $src_counts[ $fig_key ] ?? 0 );
			if ( $surplus <= 0 ) {
				continue;
			}
			$words   = self::ENGLISH_NUMBER_WORDS[ $fig_key ] ?? array();
			$matched = false;
			foreach ( $words as $word ) {
				if ( self::contains_banned_term( $source_clean, $word ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}

		return true;
	}
}
