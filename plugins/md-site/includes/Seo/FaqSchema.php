<?php
/**
 * The FAQPage schema's single source: the Q&A of the FAQs face.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * Extracts the Q&A pairs from the single-source FAQ component (the
 * faqs face's markup, spec 08 D2: the PDP FAQ component and the
 * /pages/faqs face render the same set) and builds the FAQPage
 * JSON-LD from them, so the schema can never drift from what the
 * page shows (spec 22 S5: the builder's FAQ type carries one pair
 * per post, the Custom type carries the payload — the data still
 * has exactly one source, this plugin's template).
 */
final class FaqSchema {

	/**
	 * The Q&A pairs of the single-source FAQ component.
	 *
	 * @param string $html The faqs face's markup (Templates::read of faqs.html).
	 * @return array<int, array{question: string, answer: string}> The pairs, in template order.
	 */
	public static function pairs( string $html ): array {
		$pairs = array();
		if ( preg_match_all( '/x-on:click="toggle\(\'(faq_[0-9a-z_-]+)\'\)"/', $html, $buttons, PREG_SET_ORDER ) < 1 ) {
			return $pairs;
		}
		foreach ( $buttons as $button ) {
			$id = $button[1];
			// The button block carrying this toggle; its first span is the
			// question (the second is the plus/minus icon).
			if ( 1 !== preg_match( '/toggle\(\'' . preg_quote( $id, '/' ) . '\'\)[^>]*>(.*?)<\/button>/s', $html, $block ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/<span[^>]*>(.*?)<\/span>/s', $block[1], $question ) ) {
				continue;
			}
			// The answer div's id ends with the same toggle id; the replica's
			// answers hold paragraphs only (verified: no nested div), so the
			// first closing div is the answer's own.
			if ( 1 !== preg_match( '/id="faq-download-answer-[^"]*-' . preg_quote( $id, '/' ) . '"[^>]*>(.*?)<\/div>/s', $html, $answer ) ) {
				continue;
			}
			$pairs[] = array(
				'question' => self::text( $question[1] ),
				'answer'   => self::text( $answer[1] ),
			);
		}
		return $pairs;
	}

	/**
	 * The FAQPage JSON-LD: the complete script element, ready for the
	 * builder's Custom field (which passes a payload carrying its own
	 * script tags through untouched).
	 *
	 * @param string $html      The faqs face's markup.
	 * @param string $faqs_url  The /pages/faqs/ permalink, the @id's base.
	 * @return string The <script type="application/ld+json"> element, '' without pairs.
	 */
	public static function json_ld( string $html, string $faqs_url ): string {
		$pairs = self::pairs( $html );
		if ( array() === $pairs ) {
			return '';
		}
		$entities = array();
		foreach ( $pairs as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				),
			);
		}
		$json = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => untrailingslashit( $faqs_url ) . '/#faqpage',
			'mainEntity' => $entities,
		);
		return '<script type="application/ld+json">' . (string) wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * A Q&A fragment's plain text: tags stripped, entities decoded,
	 * whitespace collapsed (block joins become single spaces).
	 *
	 * @param string $fragment The markup fragment.
	 * @return string The text.
	 */
	private static function text( string $fragment ): string {
		// A space before every tag, so a block join ("<\/p><p>") does not
		// collapse two sentences into one word after the strip.
		$spaced   = str_replace( '<', ' <', $fragment );
		$stripped = wp_strip_all_tags( $spaced );
		$decoded  = html_entity_decode( $stripped, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5 );
		return trim( (string) preg_replace( '/\s+/u', ' ', $decoded ) );
	}
}
