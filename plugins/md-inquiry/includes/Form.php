<?php
/**
 * The inquiry form's output.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Renders the inquiry form: the pattern context row, the fields, the
 * Turnstile widget and the WhatsApp alternate.
 *
 * The templates own the page (ticket #32): they place this form with the
 * `[md_inquiry_form]` shortcode or the `do_action( 'md_inquiry_form' )` hook,
 * and they style everything through WindPress with the md-inquiry-* class
 * names. This class assembles data and semantics and nothing else — zero
 * inline styles, zero scripts beyond the one behavior file, and no inline
 * script at all: the site's CSP allows no unsafe-inline, so the behavior
 * file reads its configuration from this element's data attribute.
 *
 * The two PDP entries (hero and FAQ, spec 07 as revised by ticket #7) aim
 * their source at the form by writing the three hidden source inputs —
 * `#md-inquiry-source-page`, `#md-inquiry-source-module` and
 * `#md-inquiry-source-button` — before opening it; the defaults below are
 * the spec's PDP vocabulary for a form no entry has re-aimed.
 */
final class Form {

	/**
	 * The shortcode the templates place.
	 */
	public const SHORTCODE = 'md_inquiry_form';

	/**
	 * The script handle of the behavior file.
	 */
	public const SCRIPT = 'md-inquiry';

	/**
	 * The Turnstile script handle.
	 */
	public const TURNSTILE_SCRIPT = 'md-inquiry-turnstile';

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The pattern resolver.
	 *
	 * @var Pattern
	 */
	private Pattern $pattern;

	/**
	 * The failed submission's state.
	 *
	 * @var FormState
	 */
	private FormState $state;

	/**
	 * Sets up the renderer.
	 *
	 * @param Config    $config  The configuration.
	 * @param Pattern   $pattern The pattern resolver.
	 * @param FormState $state   The failed submission's state.
	 */
	public function __construct( Config $config, Pattern $pattern, FormState $state ) {
		$this->config  = $config;
		$this->pattern = $pattern;
		$this->state   = $state;
	}

	/**
	 * Hooks the form in: the shortcode, the template action and the scripts.
	 */
	public function register(): void {
		\add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		\add_action( 'md_inquiry_form', array( $this, 'render_action' ), 10, 0 );
		\add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		\add_filter( 'script_loader_tag', array( $this, 'defer_turnstile' ), 10, 3 );
	}

	/**
	 * The shortcode entry.
	 *
	 * @param array<string, mixed>|string $atts    The shortcode attributes.
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The form's markup.
	 */
	public function render_shortcode( $atts = array(), ?string $content = '' ): string {
		unset( $content );
		$atts = \is_array( $atts ) ? $atts : array();
		$atts = \shortcode_atts(
			array(
				'pattern'       => '',
				'source_page'   => '',
				'source_module' => '',
				'source_button' => '',
			),
			$atts,
			self::SHORTCODE
		);
		return $this->render(
			\sanitize_title( $atts['pattern'] ),
			Validator::source_token( $atts['source_page'] ),
			Validator::source_token( $atts['source_module'] ),
			Validator::source_token( $atts['source_button'] )
		);
	}

	/**
	 * The template action entry: `do_action( 'md_inquiry_form' )` echoes the
	 * form with the current post's pattern context.
	 */
	public function render_action(): void {
		echo $this->render( '', '', '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- render() returns fully escaped markup.
	}

	/**
	 * Registers the behavior script (and, when configured, Turnstile's), for
	 * the render step to enqueue where the form actually appears.
	 */
	public function register_assets(): void {
		$url = \plugin_dir_url( \dirname( __DIR__ ) . '/md-inquiry.php' );
		\wp_register_script( self::SCRIPT, $url . 'assets/inquiry.js', array(), Plugin::VERSION, true );
		\wp_register_script( self::TURNSTILE_SCRIPT, 'https://challenges.cloudflare.com/turnstile/v0/api.js', array( self::SCRIPT ), Plugin::VERSION, true );
	}

	/**
	 * Turnstile's api.js loads deferred: it renders after the page parses, and
	 * after our behavior file, whose window-level callbacks it must find.
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script's handle.
	 * @param string $src    The script's URL.
	 * @return string
	 */
	public function defer_turnstile( string $tag, string $handle, string $src ): string {
		unset( $src );
		if ( self::TURNSTILE_SCRIPT !== $handle ) {
			return $tag;
		}
		return \str_replace( ' src', ' defer src', $tag );
	}

	/**
	 * Renders the form.
	 *
	 * @param string $pattern_slug   An explicit pattern slug, overriding the current post's context.
	 * @param string $source_page    The source page, or '' for the default.
	 * @param string $source_module  The source module, or '' for the default.
	 * @param string $source_button  The source button, or '' for the default.
	 * @return string The form's markup, fully escaped.
	 */
	public function render( string $pattern_slug = '', string $source_page = '', string $source_module = '', string $source_button = '' ): string {
		$pattern = '' !== $pattern_slug ? $this->pattern->resolve( $pattern_slug ) : $this->pattern->from_current_post();
		$source  = $this->source( $pattern, $source_page, $source_module, $source_button );
		$this->enqueue_assets();

		$out  = '<div class="md-inquiry-app" data-md-inquiry="' . \esc_attr( (string) \wp_json_encode( $this->config_json( $pattern, $source ) ) ) . '">';
		$out .= '<div class="md-inquiry">';

		$out .= '<form class="md-inquiry-form" method="post">';
		$out .= \wp_nonce_field( Submission::ACTION, Submission::NONCE_FIELD, false, false );
		$out .= '<input type="hidden" name="md_inquiry_action" value="submit">';
		$out .= '<input type="hidden" name="md_inquiry_locale" id="md-inquiry-locale" value="en">';
		if ( null !== $pattern ) {
			$out .= '<input type="hidden" name="md_inquiry_pattern_slug" value="' . \esc_attr( $this->pattern_slug_of( $pattern_slug, $pattern ) ) . '">';
		}
		$out .= $this->source_input( 'page', $source['page'] );
		$out .= $this->source_input( 'module', $source['module'] );
		$out .= $this->source_input( 'button', $source['button'] );

		$out .= '<div class="md-inquiry-fields">';
		if ( null !== $pattern ) {
			$out .= $this->pattern_row( $pattern );
		}
		if ( $this->state->has_failed() ) {
			$out .= '<p class="md-inquiry-notice md-inquiry-notice--error" role="alert">' . \esc_html( $this->notice( $this->state->notice_key() ) ) . '</p>';
		}
		$out .= $this->field( 'name', __( 'Name', 'md-inquiry' ), 'text', true, 'name', __( 'Jane Chan', 'md-inquiry' ) );
		$out .= $this->field( 'email', __( 'Email', 'md-inquiry' ), 'email', true, 'email', __( 'jane@studio.com', 'md-inquiry' ) );
		$out .= $this->field( 'company', __( 'Company (optional)', 'md-inquiry' ), 'text', false, 'organization', __( 'Chan Design Studio', 'md-inquiry' ) );
		$out .= $this->field( 'phone', __( 'Phone / WhatsApp (optional)', 'md-inquiry' ), 'tel', false, 'tel', __( '+852 0000 0000', 'md-inquiry' ) );
		$out .= $this->select_field( 'country', __( 'Country', 'md-inquiry' ), Validator::COUNTRIES, __( 'Select country', 'md-inquiry' ), true, 'country-name' );
		$out .= $this->select_field( 'scope', __( 'Project scope', 'md-inquiry' ), Validator::SCOPES, __( 'Select project scope', 'md-inquiry' ), true, 'off' );
		$out .= $this->select_field( 'route', __( 'Inquiry route', 'md-inquiry' ), Validator::ROUTES, __( 'Select inquiry route', 'md-inquiry' ), true, 'off' );
		$out .= $this->message_field( null !== $pattern );

		$out .= $this->honeypot();
		$out .= $this->turnstile( $source );
		$out .= '<p class="md-inquiry-note">' . \esc_html__( 'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.', 'md-inquiry' ) . '</p>';
		$out .= '</div>';

		$whatsapp = WhatsApp::url( WhatsApp::cta_text( $source['module'], $pattern, $source['page'] ) );
		$out     .= '<div class="md-inquiry-actions">';
		$out     .= '<button type="submit" class="md-inquiry-submit"' . ( $this->config->turnstile_is_configured() ? ' disabled' : '' ) . '>' . \esc_html__( 'Send inquiry', 'md-inquiry' ) . '</button>';
		$out     .= '<a class="md-inquiry-whatsapp" href="' . \esc_url( $whatsapp ) . '" target="_blank" rel="noopener">' . \esc_html__( 'Send via WhatsApp instead', 'md-inquiry' ) . '</a>';
		$out     .= '</div>';
		$out     .= '</form>';
		$out     .= '</div>';
		$out     .= '</div>';
		return $out;
	}

	/**
	 * Enqueues the scripts for a rendered form.
	 */
	private function enqueue_assets(): void {
		\wp_enqueue_script( self::SCRIPT );
		if ( $this->config->turnstile_is_configured() ) {
			\wp_enqueue_script( self::TURNSTILE_SCRIPT );
		}
	}

	/**
	 * The pattern context row: the pattern this inquiry is about, assembled
	 * server-side so the customer's belief and the record agree.
	 *
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string} $pattern The pattern context.
	 * @return string
	 */
	private function pattern_row( array $pattern ): string {
		$out = '<figure class="md-inquiry-pattern">';
		if ( '' !== $pattern['thumb'] ) {
			$out .= '<img class="md-inquiry-pattern__thumb" src="' . \esc_url( $pattern['thumb'] ) . '" alt="">';
		}
		$out .= '<figcaption class="md-inquiry-pattern__caption">';
		$out .= '<span class="md-inquiry-pattern__name">' . \esc_html( $pattern['name'] ) . '</span>';
		if ( '' !== $pattern['code'] ) {
			$out .= '<span class="md-inquiry-pattern__code">' . \esc_html( $pattern['code'] ) . '</span>';
		}
		$out .= '<span class="md-inquiry-pattern__note">' . \esc_html__( 'This pattern is attached to your inquiry.', 'md-inquiry' ) . '</span>';
		$out .= '</figcaption>';
		$out .= '</figure>';
		return $out;
	}

	/**
	 * One select, repopulated and error-flagged from the failed state.
	 *
	 * A `<select>` rather than a text input because both sides already own a
	 * fixed list: the validator accepts only a member of it, so a free-text
	 * box would let a visitor submit something the record rejects. The
	 * options are printed from the same constant the validator checks.
	 *
	 * @param string       $key          The field's key.
	 * @param string       $label        The field's label.
	 * @param list<string> $options      The permitted values.
	 * @param string       $placeholder  The empty first option.
	 * @param bool         $required     Whether the field is required.
	 * @param string       $autocomplete The autocomplete token.
	 * @return string
	 */
	private function select_field( string $key, string $label, array $options, string $placeholder, bool $required, string $autocomplete ): string {
		$value   = $this->state->value( $key );
		$error   = $this->state->error( $key );
		$invalid = '' !== $error ? ' md-inquiry-field--invalid' : '';

		$out  = '<p class="md-inquiry-field' . $invalid . '">';
		$out .= '<label class="md-inquiry-field__label" for="md-inquiry-' . \esc_attr( $key ) . '">' . \esc_html( $label ) . '</label>';
		$out .= '<select class="md-inquiry-field__input md-inquiry-field__select" id="md-inquiry-' . \esc_attr( $key ) . '" name="md_inquiry_' . \esc_attr( $key ) . '" autocomplete="' . \esc_attr( $autocomplete ) . '"' . ( $required ? ' required' : '' ) . '>';
		$out .= '<option value="">' . \esc_html( $placeholder ) . '</option>';
		foreach ( $options as $option ) {
			$out .= '<option value="' . \esc_attr( $option ) . '"' . ( $value === $option ? ' selected' : '' ) . '>' . \esc_html( $option ) . '</option>';
		}
		$out .= '</select>';
		if ( '' !== $error ) {
			$out .= '<span class="md-inquiry-field__error" id="md-inquiry-' . \esc_attr( $key ) . '-error">' . \esc_html( $error ) . '</span>';
		}
		$out .= '</p>';
		return $out;
	}

	/**
	 * One input field, repopulated and error-flagged from the failed state.
	 *
	 * @param string $key         The field's key.
	 * @param string $label       The field's label.
	 * @param string $type        The input type.
	 * @param bool   $required    Whether the field is required.
	 * @param string $autocomplete The autocomplete token.
	 * @param string $placeholder The field's placeholder.
	 * @return string
	 */
	private function field( string $key, string $label, string $type, bool $required, string $autocomplete, string $placeholder ): string {
		$value   = $this->state->value( $key );
		$error   = $this->state->error( $key );
		$invalid = '' !== $error ? ' md-inquiry-field--invalid' : '';
		$max     = 'email' === $key ? (string) Validator::EMAIL_MAX : (string) Validator::NAME_MAX;
		if ( 'company' === $key ) {
			$max = (string) Validator::COMPANY_MAX;
		}

		$out  = '<p class="md-inquiry-field' . $invalid . '">';
		$out .= '<label class="md-inquiry-field__label" for="md-inquiry-' . \esc_attr( $key ) . '">' . \esc_html( $label ) . '</label>';
		$out .= '<input class="md-inquiry-field__input" type="' . \esc_attr( $type ) . '" id="md-inquiry-' . \esc_attr( $key ) . '" name="md_inquiry_' . \esc_attr( $key ) . '" value="' . \esc_attr( $value ) . '" maxlength="' . \esc_attr( $max ) . '" autocomplete="' . \esc_attr( $autocomplete ) . '" placeholder="' . \esc_attr( $placeholder ) . '"' . ( $required ? ' required' : '' ) . '>';
		if ( '' !== $error ) {
			$out .= '<span class="md-inquiry-field__error" id="md-inquiry-' . \esc_attr( $key ) . '-error">' . \esc_html( $error ) . '</span>';
		}
		$out .= '</p>';
		return $out;
	}

	/**
	 * The message field: optional beside a pattern, required without one.
	 *
	 * @param bool $has_pattern Whether a pattern context is attached.
	 * @return string
	 */
	private function message_field( bool $has_pattern ): string {
		$value   = $this->state->value( 'message' );
		$error   = $this->state->error( 'message' );
		$invalid = '' !== $error ? ' md-inquiry-field--invalid' : '';
		$label   = $has_pattern
			? __( 'Message (optional — the pattern is already attached)', 'md-inquiry' )
			: __( 'Message', 'md-inquiry' );

		$out  = '<p class="md-inquiry-field md-inquiry-field--message' . $invalid . '">';
		$out .= '<label class="md-inquiry-field__label" for="md-inquiry-message">' . \esc_html( $label ) . '</label>';
		$out .= '<textarea class="md-inquiry-field__input" id="md-inquiry-message" name="md_inquiry_message" maxlength="' . \esc_attr( (string) Validator::MESSAGE_MAX ) . '" rows="5"' . ( $has_pattern ? '' : ' required' ) . '>' . \esc_textarea( $value ) . '</textarea>';
		$out .= '<span class="md-inquiry-field__hint">' . \esc_html__( 'Quantities, dimensions, timeline, finish preferences — anything you already have.', 'md-inquiry' ) . '</span>';
		if ( '' !== $error ) {
			$out .= '<span class="md-inquiry-field__error" id="md-inquiry-message-error">' . \esc_html( $error ) . '</span>';
		}
		$out .= '</p>';
		return $out;
	}

	/**
	 * The honeypot: a field no customer ever sees filled, and no bot can
	 * resist. The template's stylesheet hides it; without styles it shows an
	 * honest "leave this empty" label.
	 *
	 * @return string
	 */
	private function honeypot(): string {
		return '<p class="md-inquiry-honeypot" aria-hidden="true"><label class="md-inquiry-field__label" for="md-inquiry-website">' . \esc_html__( 'Leave this field empty', 'md-inquiry' ) . '</label><input class="md-inquiry-field__input" type="text" id="md-inquiry-website" name="website" autocomplete="off" tabindex="-1"></p>';
	}

	/**
	 * The Turnstile widget, when the keys are in place: implicit rendering,
	 * interaction-only appearance (spec 07 §8), with the callbacks the
	 * behavior file defines. Without keys — the early deployment and the
	 * local and staging instances — the widget is absent and the submission
	 * is marked instead of gated.
	 *
	 * @param array{page: string, module: string, button: string} $source The source triple.
	 * @return string
	 */
	private function turnstile( array $source ): string {
		unset( $source );
		if ( ! $this->config->turnstile_is_configured() ) {
			return '';
		}
		$sitekey = \esc_attr( $this->config->turnstile_sitekey() );
		$out     = '<div class="md-inquiry-turnstile cf-turnstile" data-sitekey="' . $sitekey . '" data-appearance="interaction-only" data-theme="auto" data-retry="auto" data-callback="mdInquiryTurnstileCallback" data-expired-callback="mdInquiryTurnstileExpired" data-error-callback="mdInquiryTurnstileError" data-timeout-callback="mdInquiryTurnstileTimeout"></div>';
		$out    .= '<p class="md-inquiry-turnstile-status" role="status" hidden></p>';
		return $out;
	}

	/**
	 * One hidden source input, named for the POST handler and id'd for the
	 * template's entry-point buttons to re-aim.
	 *
	 * @param string $part  The source part: page, module or button.
	 * @param string $value The part's value.
	 * @return string
	 */
	private function source_input( string $part, string $value ): string {
		return '<input type="hidden" class="md-inquiry-source" id="md-inquiry-source-' . \esc_attr( $part ) . '" name="md_inquiry_source_' . \esc_attr( $part ) . '" value="' . \esc_attr( $value ) . '">';
	}

	/**
	 * The resolved source triple: the shortcode's explicit values, else the
	 * spec's PDP vocabulary, with the page falling back to the current post's
	 * slug off a pattern page.
	 *
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern The pattern context.
	 * @param string                                                                           $page   The explicit page.
	 * @param string                                                                           $module The explicit module.
	 * @param string                                                                           $button The explicit button.
	 * @return array{page: string, module: string, button: string}
	 */
	private function source( ?array $pattern, string $page, string $module, string $button ): array {
		if ( '' === $page ) {
			$page = null !== $pattern ? 'pattern-pdp' : $this->current_page_slug();
		}
		return array(
			'page'   => Validator::source_token( $page ),
			'module' => Validator::source_token( '' !== $module ? $module : 'pattern-actions' ),
			'button' => Validator::source_token( '' !== $button ? $button : 'request_quote' ),
		);
	}

	/**
	 * The current post's slug, as a source page.
	 *
	 * @return string
	 */
	private function current_page_slug(): string {
		$post = \get_post();
		return $post instanceof \WP_Post && '' !== $post->post_name ? $post->post_name : 'inquiry';
	}

	/**
	 * The pattern slug the hidden field carries.
	 *
	 * @param string                                                                      $explicit The shortcode's explicit slug.
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string} $pattern  The resolved pattern.
	 * @return string
	 */
	private function pattern_slug_of( string $explicit, array $pattern ): string {
		return '' !== $explicit ? $explicit : (string) \get_post_field( 'post_name', $pattern['post_id'] );
	}

	/**
	 * The behavior file's configuration, as the data attribute carries it.
	 *
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern The pattern context.
	 * @param array{page: string, module: string, button: string}                              $source The source triple.
	 * @return array<string, mixed>
	 */
	private function config_json( ?array $pattern, array $source ): array {
		return array(
			'waNumber'  => WhatsApp::NUMBER,
			'pattern'   => null === $pattern ? null : array(
				'name' => $pattern['name'],
				'code' => $pattern['code'],
			),
			'source'    => $source,
			'turnstile' => $this->config->turnstile_is_configured(),
			'i18n'      => array(
				'turnstileExpired' => __( 'Verification expired. Please try again.', 'md-inquiry' ),
				'turnstileError'   => __( 'Verification could not run. Please refresh the page and try again.', 'md-inquiry' ),
				'errorName'        => __( 'Please enter your name.', 'md-inquiry' ),
				'errorEmail'       => __( 'Please enter a valid email address.', 'md-inquiry' ),
			),
		);
	}

	/**
	 * A failure notice's wording, by its key.
	 *
	 * @param string $key The notice key.
	 * @return string
	 */
	private function notice( string $key ): string {
		return match ( $key ) {
			'rate_limited' => __( 'You\'re sending inquiries too quickly. Please wait a few minutes and try again.', 'md-inquiry' ),
			'nonce'        => __( 'Your form session expired. Please reload the page and try again.', 'md-inquiry' ),
			'turnstile'    => __( 'Verification failed. Please refresh the page and try again.', 'md-inquiry' ),
			'spam'         => __( 'We could not accept this submission. If this keeps happening, reach us on WhatsApp or by email.', 'md-inquiry' ),
			'storage'      => __( 'Your inquiry could not be saved right now. Please try again, or reach us on WhatsApp or by email.', 'md-inquiry' ),
			default        => __( 'Please complete the highlighted fields.', 'md-inquiry' ),
		};
	}
}
