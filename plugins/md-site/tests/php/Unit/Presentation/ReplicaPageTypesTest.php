<?php
/**
 * The six page types of the owner's 2026-10-30 (late 2) ruling.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Batch six of the replica restoration: the FAQ's opening state and its lost
 * question, the custom-screens page's second footer, the PDP's adjacent-card
 * selection, and the manufacturing page's closing band and mid CTA.
 *
 * Each guard here pins a fact that was measured, and the measurements that
 * decided the shape are in the file each guard reads (home.css and
 * manufacturing.css carry the numbers for their bands).
 *
 * @coversNothing
 */
final class ReplicaPageTypesTest extends TestCase {

	/**
	 * One template's source.
	 *
	 * @param string $file Template stem.
	 */
	private function template( string $file ): string {
		return (string) file_get_contents( Templates::path( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The chrome runtime's source.
	 */
	private function runtime(): string {
		$path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The FAQ face opens on a named category, and names it in the markup.
	 *
	 * The owner's ruling asks for a real category rather than 'all'. The name
	 * travels as an attribute so the runtime is not carrying a hardcoded
	 * string of its own (which is what hid seven groups on 2026-10-27).
	 */
	public function test_the_faq_opens_on_a_named_category(): void {
		$faqs = $this->template( 'faqs' );

		$this->assertStringContainsString( 'data-md-faq-default="Project Start"', $faqs, 'the page names its opening category' );
		$this->assertStringNotContainsString( 'data-md-faq-default="all"', $faqs, '\'all\' is not a category' );
		$this->assertStringContainsString( 'x-init="activeFaqCategory = \'Project Start\'"', $faqs, 'the tab highlight starts on the same category' );
	}

	/**
	 * The opening category is one the page actually has.
	 *
	 * A name that matches no group would leave the runtime's filter rejecting
	 * every group it is compared against; the runtime falls back to wide on
	 * an unknown name, but the *page* would then silently not open on what it
	 * says it opens on. Checked against the group attributes themselves.
	 */
	public function test_the_opening_category_is_a_group_the_page_has(): void {
		$faqs = $this->template( 'faqs' );

		// Read by string surgery rather than preg_match: PHPStan cannot narrow
		// preg_match's `array{}|array{string,string}` from a hit count, and a
		// guard should be about the page, not about that signature.
		$at = strpos( $faqs, 'data-md-faq-default="' );
		$this->assertNotFalse( $at, 'the page declares an opening category' );
		$rest  = substr( $faqs, (int) $at + strlen( 'data-md-faq-default="' ) );
		$close = strpos( $rest, '"' );
		$this->assertNotFalse( $close, 'the declaration is a quoted value' );
		$named = substr( $rest, 0, (int) $close );

		$marker = '<section class="faq-group" data-cat="';
		$groups = array();
		$offset = 0;
		$hit    = strpos( $faqs, $marker, $offset );
		while ( false !== $hit ) {
			$from = (int) $hit + strlen( $marker );
			$to   = strpos( $faqs, '"', $from );
			$this->assertNotFalse( $to, 'the group declares a category' );
			$groups[] = substr( $faqs, $from, (int) $to - $from );
			$offset   = (int) $to;
			$hit      = strpos( $faqs, $marker, $offset );
		}
		$this->assertContains(
			$named,
			$groups,
			'the declared category is one of the page\'s own groups: ' . implode( ', ', $groups )
		);
	}

	/**
	 * Every group still ships visible, so a reader without JavaScript sees
	 * the whole FAQ.
	 *
	 * This is the equivalence condition the owner's ruling depends on: the
	 * default category narrows the *runtime's* view, and the markup keeps
	 * every question. On 2026-10-27 the same page shipped seven groups with
	 * `hidden` in the markup and read 2,919 characters with scripts against
	 * 31,656 without.
	 */
	public function test_every_faq_group_still_ships_visible(): void {
		$faqs = $this->template( 'faqs' );

		$this->assertDoesNotMatchRegularExpression(
			'/<section class="faq-group" data-cat="[^"]*"[^>]*\shidden/',
			$faqs,
			'no group carries hidden in the markup: the default category is a runtime filter'
		);
		$this->assertSame( 8, substr_count( $faqs, '<section class="faq-group" data-cat=' ), 'all eight groups are in the document' );
		$this->assertSame( 40, substr_count( $faqs, 'class="faq-item"' ), 'and all forty questions' );
	}

	/**
	 * The runtime falls back to the wide view when the page names no category
	 * or names one that does not exist.
	 *
	 * A typo must not empty the page, and a page that never asked for a
	 * default (the laser-cut panels FAQ) must keep the behaviour it had.
	 */
	public function test_the_runtime_falls_back_to_wide(): void {
		$js = $this->runtime();

		$this->assertStringContainsString( "root.getAttribute('data-md-faq-default')", $js, 'the default is read from the markup' );
		$this->assertMatchesRegularExpression(
			'/groups\.some\(\s*function \( group \) \{\s*return group\.getAttribute\( .data-cat. \) === defaultCat;/',
			$js,
			'a named category is only adopted when a group carries it'
		);
		$this->assertStringContainsString( "activeCat = 'all';", $js, 'the wide view is the starting value' );
	}

	/**
	 * The fourth Project Start question is back, with the old site's own id.
	 *
	 * The group's badge read 4 against 3 questions and the id sequence ran
	 * 01, 02, 03, 05 — the missing one is the old site's `faq_04`.
	 */
	public function test_the_lost_fourth_question_is_restored(): void {
		$faqs = $this->template( 'faqs' );

		$this->assertStringContainsString( 'data-q="Can I send my own pattern design?"', $faqs, 'the lost question is present' );
		$this->assertSame( 1, substr_count( $faqs, 'data-q="Can I send my own pattern design?"' ), 'the question is asked once' );
		$this->assertSame(
			1,
			substr_count( $faqs, 'id="faq-download-answer-template--21844278411517__faqs_download-faq_04"' ),
			'one answer panel carries the id'
		);
		$this->assertStringContainsString( "x-on:click=\"toggle('faq_04')\"", $faqs, 'and the button toggles it' );
		$this->assertStringContainsString( 'Your design stays yours', $faqs, 'and the old site\'s closing line' );

		// The group's badge counts its own questions: a badge that disagreed
		// with the list is what pointed at the loss in the first place.
		$from = strpos( $faqs, 'data-cat="Project Start"' );
		$this->assertNotFalse( $from );
		$end = strpos( $faqs, '</section>', (int) $from );
		$this->assertNotFalse( $end );
		$group = substr( $faqs, (int) $from, (int) $end - (int) $from );

		$badge_at = strpos( $group, 'font-semibold text-[#c9a962]' );
		$this->assertNotFalse( $badge_at, 'the group carries its badge' );
		// The badge is the digits inside the span that follows the label.
		$digit = substr( $group, (int) $badge_at );
		$open  = strpos( $digit, '>' );
		$this->assertNotFalse( $open, 'the badge span opens' );
		$numbered = substr( $digit, (int) $open + 1 );
		$digits   = '';
		foreach ( str_split( $numbered ) as $char ) {
			if ( ! ctype_digit( $char ) ) {
				break;
			}
			$digits .= $char;
		}
		$this->assertNotSame( '', $digits, 'the badge is a number' );
		$this->assertSame(
			(int) $digits,
			substr_count( $group, 'class="faq-item"' ),
			'the Project Start badge equals its question count'
		);
	}

	/**
	 * The FAQ rows use the tightened leading the ruling asked for.
	 */
	public function test_the_faq_rows_are_tightened(): void {
		$faqs = $this->template( 'faqs' );

		$this->assertSame( 0, substr_count( $faqs, 'md:py-11' ), 'the 11-step row padding is gone' );
		$this->assertSame( 40, substr_count( $faqs, 'md:py-7' ), 'every row carries the 7-step padding' );
	}

	/**
	 * The custom-screens page has one footer, not two.
	 *
	 * The page carried the site's footer partial *and* a `landing-footer`
	 * block of its own, so it closed with two footers stacked.
	 */
	public function test_custom_screens_has_one_footer(): void {
		$custom = $this->template( 'custom-screens' );

		$this->assertSame( 0, substr_count( $custom, '<footer' ), 'the page markup carries no footer element of its own' );
		$this->assertSame( 0, substr_count( $custom, 'landing-footer' ), 'the second footer is gone' );
		$this->assertSame( 0, substr_count( $custom, 'footer-landing' ), 'and so is its class' );
	}

	/**
	 * The PDP's adjacent cards come from the pattern's series, not from
	 * WooCommerce's tag-union that shuffles.
	 *
	 * Measured 2026-10-30: `wc_get_related_products()` returned Arboreal I,
	 * Bluestem, Dora and Creative Moment for BirdBlindC, while the old site's
	 * page for the same pattern returned Apiary, Zelda, Winter Branches and
	 * Wild Spinach — four members of the same family. The heading above the
	 * cards says "in this family", so the family is what the selection must
	 * mean.
	 */
	public function test_the_adjacent_cards_come_from_the_patterns_series(): void {
		$js         = $this->runtime();
		$shortcodes = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Presentation/Shortcodes.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$catalog    = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Catalog.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( '->adjacent(', $shortcodes, 'the shortcode asks the catalogue for the family' );
		$this->assertStringNotContainsString( 'wc_get_related_products( $post->ID', $shortcodes, 'and no longer asks WooCommerce for its tag-union' );
		$this->assertStringContainsString( "'product_cat'", $catalog, 'the family is the product_cat taxonomy' );
		$this->assertStringContainsString( 'function adjacent(', $catalog, 'the selection has its own named method' );

		// The blog face has no adjacent selection; the runtime is checked only
		// so the old call could not have been reintroduced there.
		$this->assertStringNotContainsString( 'wc_get_related_products', $js, 'the runtime does not select patterns itself' );
	}

	/**
	 * The manufacturing page's closing band fits the brief's ceiling, and the
	 * mechanism that gets it there is stated where it lives.
	 */
	public function test_the_closing_band_is_shaped_to_fit(): void {
		$css  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/content/windpress/manufacturing.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$page = $this->template( 'manufacturing' );

		$this->assertStringContainsString( 'md-closing-facts', $page, 'the facts row has its own hook' );
		$this->assertStringContainsString( '.md-closing-facts', $css, 'and its own rules' );
		$this->assertMatchesRegularExpression(
			'/\.md-closing-well \.md-inquiry-fields \{[^}]*display: grid/',
			$css,
			'the closing form takes the two-column shape'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 767px\) \{\s*\.md-closing-well \.md-inquiry-fields \{\s*grid-template-columns: minmax\(0, 1fr\)/',
			$css,
			'and a phone keeps one column'
		);
	}

	/**
	 * The mid CTA is a thin band.
	 *
	 * The ruling asks for ~24px top and bottom. Measured after: 281px against
	 * the 553 it carried, whose padding was 64 a side on the band and 64/96
	 * on its inner wrapper — the second pair is the one that decided the
	 * height, so both are pinned here.
	 */
	public function test_the_mid_cta_is_a_thin_band(): void {
		$css  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/content/windpress/manufacturing.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$page = $this->template( 'manufacturing' );

		$this->assertStringContainsString( 'py-6 text-center md:py-6', $page, 'the CTA\'s inner wrapper pads 24px a side' );
		$this->assertStringContainsString( '.md-tcc07631f { padding-top: 0;padding-bottom: 0 }', $css, 'and the band adds none of its own' );
	}

	/**
	 * Both WhatsApp and #open-quote inquiry CTAs are present across the
	 * touched faces (owner ruling 2026-10-31: ubiquitous CTA & WhatsApp
	 * conversion channels).
	 */
	public function test_whatsapp_and_quote_ctas_are_present_in_the_touched_faces(): void {
		foreach ( array( 'manufacturing', 'custom-screens', 'faqs' ) as $face ) {
			$source = $this->template( $face );
			$this->assertSame( 1, preg_match( '/wa\.me\/85264731687/i', $source ), "{$face} carries the official WhatsApp link" );
			$this->assertStringContainsString( '#open-quote', $source, "{$face} carries in-place quote modal triggers" );
		}
	}
}
