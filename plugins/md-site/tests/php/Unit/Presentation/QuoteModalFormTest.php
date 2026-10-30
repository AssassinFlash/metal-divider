<?php
/**
 * The footer quote dialog places the inquiry form, not a link to it.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * A PDP's "Request a Quote" opened a dialog whose only action was a plain
 * `/inquiry/` link.
 *
 * The footer is the single quote target on every page (`#open-quote` is one
 * real element site-wide), so the dialog's body decides what the highest-intent
 * click on a pattern page actually does. With a link there, the visitor lost
 * the pattern they were looking at at the exact moment they asked about it:
 * they landed on the generic inquiry page with no context, and the PDP had no
 * form at all.
 *
 * The fix places `[md_inquiry_form]` in the dialog. The shortcode resolves the
 * pattern from the current post (`Form::render()` → `Pattern::from_current_post()`),
 * so the dialog inherits the PDP's pattern with no per-page wiring, and brings
 * the plugin's own fields, honeypot and Turnstile slot.
 *
 * These are markup guards, not behaviour tests: they hold the template shape
 * the real-browser acceptance depends on, and fail loudly if the dialog is
 * ever "simplified" back to a link.
 *
 * @coversNothing
 */
final class QuoteModalFormTest extends TestCase {

	/**
	 * One partial's source with its HTML comments removed.
	 *
	 * The comments in these files document the very tokens the assertions look
	 * for ("this band must not carry [md_inquiry_form]", "the link-only CTA
	 * band"), so matching raw source would fail on the explanation of the rule
	 * rather than on a violation of it.
	 *
	 * @param string $relative Path under content/livecanvas/.
	 */
	private function markup( string $relative ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$source = (string) file_get_contents( Templates::path( $relative, true ) );
		return (string) preg_replace( '/<!--.*?-->/s', '', $source );
	}

	/**
	 * The footer partial's markup.
	 */
	private function footer(): string {
		return $this->markup( 'partials/footer.html' );
	}

	/**
	 * The quote dialog's markup, from its opening element to the end of the
	 * card that closes it.
	 *
	 * The card is the dialog's body; asserting inside it — rather than in the
	 * whole footer — is what makes "the dialog contains the form" a real claim
	 * instead of "the file mentions a form somewhere".
	 */
	private function modal_card(): string {
		$footer = $this->footer();
		$start  = strpos( $footer, 'class="md-quote-modal__card"' );
		$this->assertNotFalse( $start, 'the footer ships the quote dialog card' );

		$end = strpos( $footer, '<!-- /md-quote-modal -->', $start );
		if ( false === $end ) {
			// The card is the last element before the dialog closes; cut at the
			// dialog's closing div when no explicit marker exists.
			$end = strpos( $footer, '</div>', (int) strpos( $footer, '[md_inquiry_form', $start ) );
		}
		$this->assertNotFalse( $end, 'the quote dialog card terminates' );

		return substr( $footer, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * The dialog's body is the form shortcode.
	 */
	public function test_the_quote_dialog_contains_the_inquiry_form_shortcode(): void {
		$card = $this->modal_card();

		$this->assertStringContainsString(
			'[md_inquiry_form',
			$card,
			'the quote dialog places the inquiry form'
		);
		$this->assertStringContainsString(
			'source_module="quote-modal"',
			$card,
			'the form names this entry point, so the inquiry record shows where it came from'
		);
	}

	/**
	 * No plain inquiry-page link replaces the form.
	 *
	 * This is the regression itself: the dialog's primary action must not be a
	 * link that throws the pattern context away.
	 */
	public function test_the_quote_dialog_does_not_replace_the_form_with_a_plain_inquiry_link(): void {
		$card = $this->modal_card();

		$this->assertDoesNotMatchRegularExpression(
			'#<a\b[^>]*href="/inquiry/?"#',
			$card,
			'the dialog must not link to the inquiry page in place of the form'
		);
		// The link-only CTA band is not the dialog's body either: it carries
		// that same /inquiry/ link, and placing it here was the original bug.
		$this->assertStringNotContainsString(
			'lc_get_partial slug="md-inquiry-cta"',
			$card,
			'the dialog must not embed the link-only CTA band'
		);
	}

	/**
	 * The form is included directly, not through a partial indirection.
	 *
	 * A partial would be a shared, duplicated body: the form's markup carries
	 * fixed ids (`md-inquiry-name`, `md-inquiry-source-*`) and `inquiry.js`
	 * binds only the first `.md-inquiry-app` on a page, so a form reaching the
	 * page twice leaves the second copy inert and its ids colliding.
	 */
	public function test_the_inquiry_cta_band_stays_a_link_band(): void {
		$band = $this->markup( 'partials/md-inquiry-cta.html' );

		$this->assertStringNotContainsString(
			'[md_inquiry_form',
			$band,
			'the shared CTA band must not render the form: it is included by eighteen page sections, and the form cannot appear twice on a page'
		);
		$this->assertStringContainsString( 'md-inquiry-cta__primary', $band, 'the band keeps its own primary link' );
	}

	/**
	 * The dialog keeps the opening mechanism and the styling that already
	 * worked: the Alpine quoteModal component, #open-quote as the anchor, and
	 * the card's own class hook for the modal-scoped styles.
	 */
	public function test_the_dialog_keeps_its_open_behaviour_and_styling_hook(): void {
		$footer = $this->footer();

		$this->assertStringContainsString( 'x-data="quoteModal()"', $footer, 'the dialog still opens through the quoteModal component' );
		$this->assertStringContainsString( 'id="open-quote"', $footer, 'the #open-quote anchor still exists for every CTA' );
		$this->assertStringContainsString( 'x-on:open-quote.window', $footer, 'the dialog still listens for the open-quote event' );
		$this->assertStringContainsString( 'class="md-quote-modal__card"', $footer, 'the card keeps the class the modal styles target' );
		$this->assertStringContainsString( 'x-trap.noscroll="isOpen"', $footer, 'the dialog still traps focus while open' );
	}

	/**
	 * Every page that offers a quote CTA resolves to this one dialog.
	 *
	 * The PDP's two CTAs (hero and FAQ) both point at `#open-quote`; if a page
	 * grew its own link to `/inquiry/` instead, the pattern context would be
	 * lost there without any test noticing.
	 */
	public function test_the_pdp_quote_ctas_target_the_dialog(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$pdp = (string) file_get_contents( Templates::path( 'pdp' ) );

		$this->assertGreaterThanOrEqual(
			1,
			substr_count( $pdp, 'href="#open-quote"' ),
			'the PDP opens the quote dialog rather than linking away'
		);
		$this->assertStringNotContainsString(
			'href="/inquiry/"',
			$pdp,
			'the PDP must not send quote intent to the generic inquiry page'
		);
	}
}
