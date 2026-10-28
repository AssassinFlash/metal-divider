<?php
/**
 * Tests for the inquiry form's output.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Form;
use MetalDivider\Inquiry\FormState;
use MetalDivider\Inquiry\Pattern;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The form as the templates receive it: the pattern row, the fields with
 * their limits, the hidden plumbing, the Turnstile widget's presence and
 * absence — semantic markup with the md-inquiry-* class names, and zero
 * inline styles (AGENTS.md §5: any visual decision belongs to the WindPress
 * volume, never to this output).
 *
 * @covers \MetalDivider\Inquiry\Form
 */
final class FormTest extends TestCase {

	/**
	 * Sets the shared mocks up: escaping and sanitization pass through, the
	 * pattern resolves to Apiary, the assets enqueue quietly.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_passthroughs();

		$product            = new \stdClass();
		$product->ID        = 42;
		$product->post_type = 'product';
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $product ) );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Apiary' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ): string {
				return '_sku' === $key ? 'APIARY' : '';
			}
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/apiary' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( '' );
		WP_Mock::userFunction( 'get_post_field' )->andReturn( 'apiary-architectural-metal-screen-pattern' );
		WP_Mock::userFunction( 'get_post' )->andReturn( null );
		WP_Mock::userFunction( 'wp_nonce_field' )->andReturnUsing(
			static function ( string $action, string $name ): string {
				return '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">';
			}
		);
		WP_Mock::userFunction( 'wp_register_script' )->andReturn( null );
		WP_Mock::userFunction( 'wp_enqueue_script' )->andReturn( null );
		WP_Mock::userFunction( 'plugin_dir_url' )->andReturn( 'https://metal-divider.com/wp-content/plugins/md-inquiry/' );
	}

	/**
	 * The form with a pattern: the context row, the repopulatable fields with
	 * their limits, the hidden source triple and pattern slug, the nonce, the
	 * honeypot, the WhatsApp alternate — and not one inline style.
	 */
	public function test_the_form_assembles_the_patterned_shape(): void {
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		$this->assertStringContainsString( 'class="md-inquiry-app" data-md-inquiry="', $html, 'the behavior file reads its configuration from the data attribute — the CSP allows no inline script' );
		$this->assertStringContainsString( 'class="md-inquiry-pattern__name">Apiary<', $html );
		$this->assertStringContainsString( 'class="md-inquiry-pattern__code">APIARY<', $html );
		$this->assertStringContainsString( 'This pattern is attached to your inquiry.', $html );
		$this->assertStringContainsString( '<form class="md-inquiry-form" method="post">', $html, 'no action attribute: the form posts to the page it sits on, the CSP\'s form-action honored' );
		$this->assertStringContainsString( 'name="md_inquiry_nonce" value="nonce-for-md_inquiry_submit"', $html );
		$this->assertStringContainsString( 'name="md_inquiry_pattern_slug" value="apiary-architectural-metal-screen-pattern"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-page" name="md_inquiry_source_page" value="pattern-pdp"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-module" name="md_inquiry_source_module" value="pattern-actions"', $html );
		$this->assertStringContainsString( 'id="md-inquiry-source-button" name="md_inquiry_source_button" value="request_quote"', $html );
		$this->assertStringContainsString( 'name="md_inquiry_name"', $html );
		$this->assertStringContainsString( 'maxlength="120"', $html );
		$this->assertStringContainsString( 'name="md_inquiry_phone"', $html );
		$this->assertStringContainsString( 'maxlength="40"', $html, 'phone is capped at PHONE_MAX, not NAME_MAX' );
		$this->assertStringContainsString( 'name="md_inquiry_confirm"', $html );
		$this->assertStringContainsString( 'I confirm this inquiry is for an architectural or commercial project.', $html );
		$this->assertStringContainsString( '<div class="md-inquiry-notice" role="alert" aria-live="polite"></div>', $html );
		$this->assertStringContainsString( 'id="md-inquiry-message-hint"', $html );
		$this->assertStringContainsString( 'aria-describedby="md-inquiry-message-hint"', $html );
		$this->assertStringContainsString( '%0A', $html, 'the WhatsApp href keeps the encoded line break' );
		$this->assertStringContainsString( 'name="website"', $html, 'the honeypot' );
		$this->assertStringContainsString( 'class="md-inquiry-submit"', $html );
		$this->assertStringContainsString( 'class="md-inquiry-fields"', $html, 'fields scroll independently of the sticky actions' );
		$this->assertStringContainsString( 'class="md-inquiry-whatsapp" href="https://wa.me/85264731687?text=', $html );
		$this->assertStringNotContainsString( ' style=', $html, 'zero inline styles, always' );
		$this->assertStringNotContainsString( '<script', $html, 'no inline script: the CSP closes that door' );

		// The reply promise. Every old page told the visitor when to expect an
		// answer; the rebuilt form told them nothing, so the highest-intent
		// moment on the site — a filled-in brief — ended without a commitment.
		$this->assertStringContainsString( 'class="md-inquiry-promise"', $html, 'the form states its response window' );
		$this->assertStringContainsString( 'We reply within 24 hours on business days.', $html );
		$this->assertStringContainsString( 'Your details are used only to answer this inquiry', $html, 'and says what it does with them' );

		// Six pages ask the visitor to send a drawing by name. The form has no
		// file input and the plugin has no upload handler, so the only honest
		// instruction is that a drawing reaches the engineer by reply.
		$this->assertStringContainsString( 'Send it in reply to the confirmation email', $html, 'the form says how a drawing reaches us' );
		$this->assertStringNotContainsString( 'type="file"', $html, 'and does not pretend to take one' );
	}

	/**
	 * Several forms on one page never share an element id.
	 *
	 * A page renders the shared CTA band, the footer dialog and a section that
	 * asks in place. Before the suffix, thirteen ids repeated per instance and
	 * every `<label for>` on the page pointed at the first form's input.
	 */
	public function test_two_renders_on_one_page_never_share_an_id(): void {
		$this->render( 'apiary-architectural-metal-screen-pattern', array() );
		$first  = $this->render( 'apiary-architectural-metal-screen-pattern', array() );
		$second = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		preg_match_all( '/\sid="([^"]+)"/', $first, $a );
		preg_match_all( '/\sid="([^"]+)"/', $second, $b );

		$this->assertNotEmpty( $a[1], 'the form prints ids' );
		$this->assertNotEmpty( $b[1], 'and so does the next one' );
		$this->assertSame(
			array(),
			array_values( array_intersect( $a[1], $b[1] ) ),
			'two renders on one page never repeat an id'
		);
	}

	/**
	 * With Turnstile configured the widget renders with the documented
	 * callbacks, and the submit button starts disabled — fail-closed: the
	 * callback is what unlocks it.
	 */
	public function test_turnstile_configured_renders_the_widget_and_a_locked_submit(): void {
		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => 'the-sitekey',
				'MD_INQUIRY_TURNSTILE_SECRET'  => 'the-secret',
			)
		);
		$html   = $this->render( 'apiary-architectural-metal-screen-pattern', array(), $config );

		$this->assertStringContainsString( 'class="md-inquiry-turnstile cf-turnstile" data-sitekey="the-sitekey" data-appearance="interaction-only"', $html );
		$this->assertStringContainsString( 'data-callback="mdInquiryTurnstileCallback"', $html );
		$this->assertStringContainsString( 'data-expired-callback="mdInquiryTurnstileExpired"', $html );
		$this->assertStringContainsString( 'data-error-callback="mdInquiryTurnstileError"', $html );
		$this->assertStringContainsString( 'data-timeout-callback="mdInquiryTurnstileTimeout"', $html );
		$this->assertStringContainsString( 'class="md-inquiry-submit" disabled', $html );
	}

	/**
	 * Without Turnstile keys — the early deployment, the local and staging
	 * instances — no widget and no lock: the form submits, and the record
	 * carries the mark instead.
	 */
	public function test_turnstile_unconfigured_renders_no_widget_and_an_open_submit(): void {
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array() );

		// Positives first: an empty render would satisfy both negatives below
		// while shipping no form at all, which is the state this test is meant
		// to rule out.
		$this->assertStringContainsString( '<form class="md-inquiry-form"', $html, 'the form is there' );
		$this->assertStringContainsString( 'class="md-inquiry-submit"', $html, 'the submit button is there' );
		$this->assertStringNotContainsString( 'cf-turnstile', $html );
		$this->assertDoesNotMatchRegularExpression(
			'/class="md-inquiry-submit"[^>]*\sdisabled/',
			$html,
			'the submit is not locked when no challenge is configured'
		);
	}

	/**
	 * A failed submission re-renders with its values and its notice: the
	 * customer's typed words survive the retry.
	 */
	public function test_a_failed_submission_repopulates_and_notifies(): void {
		$state = new FormState();
		$state->fail(
			array(
				'name'    => 'Jane Chan',
				'email'   => 'jane@studio.com',
				'message' => '40 panels',
			),
			array( 'email' => 'Please enter a valid email address.' ),
			'validation'
		);
		$html = $this->render( 'apiary-architectural-metal-screen-pattern', array(), null, $state );

		$this->assertStringContainsString( 'class="md-inquiry-notice md-inquiry-notice--error" role="alert"', $html );
		$this->assertStringContainsString( 'Please complete the highlighted fields.', $html );
		$this->assertStringContainsString( 'value="Jane Chan"', $html );
		$this->assertStringContainsString( 'md-inquiry-field--invalid', $html );
		$this->assertStringContainsString( 'Please enter a valid email address.', $html );
	}

	/**
	 * The explicit pattern attribute decides the context — the template face's
	 * way of placing the form with a named pattern.
	 */
	public function test_the_pattern_attribute_decides_the_context(): void {
		$html = $this->render( 'apiary', array() );

		$this->assertStringContainsString( 'class="md-inquiry-pattern__name">Apiary<', $html );
		$this->assertStringContainsString( 'name="md_inquiry_pattern_slug" value="apiary"', $html );
		$this->assertStringContainsString( '"module":"pattern-actions"', $html, 'the config JSON carries the source for the behavior file' );
	}


	/**
	 * Without a pattern the form must not claim one is attached, and the
	 * inquiry page's source triple is the contact hub, not a pattern action.
	 */
	public function test_a_form_without_a_pattern_does_not_claim_one(): void {
		$config = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
				'MD_INQUIRY_TURNSTILE_SECRET'  => '',
			)
		);
		$html   = ( new Form( $config, new Pattern(), new FormState() ) )->render( '', 'inquiry', 'contact-hub', 'send-inquiry' );

		$this->assertStringNotContainsString( 'this pattern', $html );
		$this->assertStringNotContainsString( 'md-inquiry-pattern', $html );
		$this->assertStringContainsString( 'pre-filled with your details.', $html );
		$this->assertStringContainsString( 'value="inquiry"', $html );
		$this->assertStringContainsString( 'value="contact-hub"', $html );
		$this->assertStringContainsString( 'value="send-inquiry"', $html );
		$this->assertStringContainsString( 'inquiry%2Fcontact-hub%2Fwhatsapp', $html );
	}

	/**
	 * Renders the form: directly with the given pattern slug, or through the
	 * shortcode when the test says so.
	 *
	 * @param string                $pattern_slug The pattern to attach, or '' for the current post's.
	 * @param array<string, string> $atts         The shortcode attributes, when the path is the shortcode.
	 * @param Config|null           $config       The configuration, or the unconfigured default.
	 * @param FormState|null        $state        The form state, or a fresh one.
	 * @return string
	 */
	private function render( string $pattern_slug, array $atts, ?Config $config = null, ?FormState $state = null ): string {
		$config = $config ?? new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
				'MD_INQUIRY_TURNSTILE_SECRET'  => '',
			)
		);
		$state  = $state ?? new FormState();
		$form   = new Form( $config, new Pattern(), $state );

		if ( array() !== $atts ) {
			WP_Mock::userFunction( 'shortcode_atts' )->once()->andReturnUsing(
				static function ( array $defaults, array $given ): array {
					return \array_merge( $defaults, $given );
				}
			);
			return $form->render_shortcode( $atts );
		}
		return $form->render( $pattern_slug, '', '', '' );
	}
}
