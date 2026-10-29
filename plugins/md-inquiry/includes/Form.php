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
	 * The onload name api.js calls once explicit mode is ready.
	 *
	 * It has to be a window function in the behavior file: the CSP allows
	 * no inline script, so the name travels in the script URL instead.
	 */
	public const TURNSTILE_ONLOAD = 'mdInquiryTurnstileReady';

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
	 * How many forms this request has rendered.
	 *
	 * A page carries several: one in the shared CTA band, one in the footer's
	 * quote dialog, and one per family section that asks for a quote in place.
	 * Every one of them used to print the same element ids, so a page shipped
	 * thirteen duplicated ids four times over — invalid HTML, and a real
	 * breakage: each `<label for>` and every `aria-describedby` resolved to the
	 * first form on the page, so a screen reader announced the wrong field and
	 * clicking a label focused a different form's input.
	 *
	 * Static, not per instance: the uniqueness of the ids on a page cannot depend
	 * on how many Form objects the request happened to build. One instance is
	 * created today, but a second registration or a test that renders twice
	 * would silently start the count over and duplicate every id again.
	 *
	 * @var int
	 */
	private static int $rendered = 0;

	/**
	 * The id suffix of the form being rendered, '' for the first on the page.
	 *
	 * @var string
	 */
	private string $suffix = '';

	/**
	 * One element id, suffixed for every instance after the first.
	 *
	 * Labels, `aria-describedby` references and the runtime's lookups all go
	 * through this, so a page can carry several forms without an id, a `for`
	 * or an error reference ever resolving to a different form's element.
	 *
	 * @param string $base The unsuffixed id.
	 * @return string
	 */
	private function id_of( string $base ): string {
		return $base . $this->suffix;
	}

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
		$url     = \plugin_dir_url( \dirname( __DIR__ ) . '/md-inquiry.php' );
		$digest  = @\md5_file( \dirname( __DIR__ ) . '/assets/inquiry.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a deploy race must fall back to the plugin version, not fatal.
		$version = '' === (string) $digest ? Plugin::VERSION : Plugin::VERSION . '.' . \substr( (string) $digest, 0, 10 );
		\wp_register_script( self::SCRIPT, $url . 'assets/inquiry.js', array(), $version, true );
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare rejects WordPress's unrelated ver parameter; this third-party API owns its versioning.
		\wp_register_script( self::TURNSTILE_SCRIPT, 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=' . self::TURNSTILE_ONLOAD, array( self::SCRIPT ), null, true );
	}

	/**
	 * Turnstile's api.js loads deferred, after this file's behavior script.
	 *
	 * The URL names mdInquiryTurnstileReady as its onload. That function lives
	 * in the behavior file, which is this script's dependency and is therefore
	 * printed first. Defer keeps api.js from running, and from calling that
	 * function, until the behavior file has defined it. There is no inline
	 * script: the CSP allows none.
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

		// The first form on a request keeps the bare ids the PDP hero and FAQ
		// aim at (`#md-inquiry-source-page` and friends); every later instance
		// suffixes them, because HTML ids are unique per document.
		++self::$rendered;
		$suffix       = 1 === self::$rendered ? '' : '-' . (string) self::$rendered;
		$this->suffix = $suffix;

		$out  = '<div class="md-inquiry-app" data-md-inquiry="' . \esc_attr( (string) \wp_json_encode( $this->config_json( $pattern, $source ) ) ) . '">';
		$out .= '<div class="md-inquiry">';

		$out .= '<form class="md-inquiry-form" method="post">';
		// wp_nonce_field() writes `id="md_inquiry_nonce"` with no suffix, so two
		// forms on one page produced two elements with the same id — measured on
		// production `/` (91 ids, one duplicated) and on every page that now
		// carries a closing-card form beside the footer dialog. Duplicate ids are
		// invalid HTML and make getElementById and any `for=` label resolve to
		// whichever form came first.
		//
		// The call stays native (so plugins filtering the field keep working);
		// only its id is rewritten to carry this instance's suffix. The name is
		// never touched: Submission reads it from $_POST, which knows nothing
		// about instances.
		$nonce_field = \wp_nonce_field( Submission::ACTION, Submission::NONCE_FIELD, false, false );
		$out        .= str_replace(
			'id="' . Submission::NONCE_FIELD . '"',
			'id="' . \esc_attr( $this->id_of( Submission::NONCE_FIELD ) ) . '"',
			$nonce_field
		);
		$out        .= '<input type="hidden" name="md_inquiry_action" value="submit">';
		$out        .= '<input type="hidden" name="md_inquiry_locale" id="' . \esc_attr( $this->id_of( 'md-inquiry-locale' ) ) . '" value="en">';
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
		$out .= $this->notice_markup();
		$out .= $this->field( 'name', __( 'Name', 'md-inquiry' ), 'text', true, 'name', __( 'Jane Chan', 'md-inquiry' ) );
		$out .= $this->field( 'email', __( 'Email', 'md-inquiry' ), 'email', true, 'email', __( 'jane@studio.com', 'md-inquiry' ) );
		$out .= $this->field( 'company', __( 'Company (optional)', 'md-inquiry' ), 'text', false, 'organization', __( 'Chan Design Studio', 'md-inquiry' ) );
		$out .= $this->field( 'phone', __( 'Phone / WhatsApp (optional)', 'md-inquiry' ), 'tel', false, 'tel', __( '+852 0000 0000', 'md-inquiry' ) );
		$out .= $this->select_field( 'country', __( 'Country', 'md-inquiry' ), Validator::COUNTRIES, __( 'Select country', 'md-inquiry' ), true, 'country-name' );
		$out .= $this->select_field( 'scope', __( 'Project scope', 'md-inquiry' ), Validator::SCOPES, __( 'Select project scope', 'md-inquiry' ), true, 'off' );
		$out .= $this->select_field( 'route', __( 'Inquiry route', 'md-inquiry' ), Validator::ROUTES, __( 'Select inquiry route', 'md-inquiry' ), true, 'off' );
		$out .= $this->select_field( 'quantity', __( 'Estimated quantity', 'md-inquiry' ), Validator::QUANTITIES, __( 'Select quantity range', 'md-inquiry' ), true, 'off' );
		$out .= $this->message_field( null !== $pattern );
		$out .= $this->confirm_field();

		$out .= $this->honeypot();
		$out .= $this->turnstile( $source );
		$out .= '<p class="md-inquiry-promise">' . \esc_html__( 'We reply within 24 hours on business days. Your details are used only to answer this inquiry — we do not share them.', 'md-inquiry' ) . '</p>';
		$note = null !== $pattern
			? __( 'The WhatsApp button opens a chat pre-filled with your details and this pattern. Choosing WhatsApp does not submit this form.', 'md-inquiry' )
			: __( 'The WhatsApp button opens a chat pre-filled with your details. Choosing WhatsApp does not submit this form.', 'md-inquiry' );
		$out .= '<p class="md-inquiry-note">' . \esc_html( $note ) . '</p>';
		$out .= '</div>';

		$out .= '<div class="md-inquiry-actions">';
		$out .= '<button type="submit" class="md-inquiry-submit"' . ( $this->config->turnstile_is_configured() ? ' disabled' : '' ) . '>' . \esc_html__( 'Send inquiry', 'md-inquiry' ) . '</button>';
		$out .= '<a class="md-inquiry-whatsapp" href="' . $this->whatsapp_href( WhatsApp::cta_text( $source['module'], $pattern, $source['page'] ) ) . '" target="_blank" rel="noopener">' . \esc_html__( 'Send via WhatsApp instead', 'md-inquiry' ) . '</a>';
		$out .= '</div>';
		$out .= '</form>';
		$out .= '</div>';
		$out .= '</div>';
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
	 * @param string             $key          The field key.
	 * @param string             $label        The field's label.
	 * @param array<int, string> $options The permitted values.
	 * @param string             $placeholder  The empty first option.
	 * @param bool               $required     Whether the field is required.
	 * @param string             $autocomplete The autocomplete token.
	 * @return string
	 */
	private function select_field( string $key, string $label, array $options, string $placeholder, bool $required, string $autocomplete ): string {
		$value   = $this->state->value( $key );
		$error   = $this->state->error( $key );
		$invalid = '' !== $error ? ' md-inquiry-field--invalid' : '';

		$out  = '<p class="md-inquiry-field' . $invalid . '">';
		$out .= '<label class="md-inquiry-field__label" for="' . \esc_attr( $this->id_of( 'md-inquiry-' . $key ) ) . '">' . \esc_html( $label ) . '</label>';
		$out .= '<select class="md-inquiry-field__input md-inquiry-field__select" id="' . \esc_attr( $this->id_of( 'md-inquiry-' . $key ) ) . '" name="md_inquiry_' . \esc_attr( $key ) . '" autocomplete="' . \esc_attr( $autocomplete ) . '"' . ( $required ? ' required' : '' ) . $this->invalid_attrs( $key, $error ) . '>';
		$out .= '<option value="">' . \esc_html( $placeholder ) . '</option>';
		foreach ( $options as $option ) {
			$out .= '<option value="' . \esc_attr( $option ) . '"' . ( $value === $option ? ' selected' : '' ) . '>' . \esc_html( $option ) . '</option>';
		}
		$out .= '</select>';
		$out .= $this->error_span( $key, $error );
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
		$max     = (string) match ( $key ) {
			'email'   => Validator::EMAIL_MAX,
			'company' => Validator::COMPANY_MAX,
			'phone'   => Validator::PHONE_MAX,
			default   => Validator::NAME_MAX,
		};

		$out  = '<p class="md-inquiry-field' . $invalid . '">';
		$out .= '<label class="md-inquiry-field__label" for="' . \esc_attr( $this->id_of( 'md-inquiry-' . $key ) ) . '">' . \esc_html( $label ) . '</label>';
		$out .= '<input class="md-inquiry-field__input" type="' . \esc_attr( $type ) . '" id="' . \esc_attr( $this->id_of( 'md-inquiry-' . $key ) ) . '" name="md_inquiry_' . \esc_attr( $key ) . '" value="' . \esc_attr( $value ) . '" maxlength="' . \esc_attr( $max ) . '" autocomplete="' . \esc_attr( $autocomplete ) . '" placeholder="' . \esc_attr( $placeholder ) . '"' . ( $required ? ' required' : '' ) . $this->invalid_attrs( $key, $error ) . '>';
		$out .= $this->error_span( $key, $error );
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
		$out .= '<label class="md-inquiry-field__label" for="' . \esc_attr( $this->id_of( 'md-inquiry-message' ) ) . '">' . \esc_html( $label ) . '</label>';
		$out .= '<textarea class="md-inquiry-field__input" id="' . \esc_attr( $this->id_of( 'md-inquiry-message' ) ) . '" name="md_inquiry_message" maxlength="' . \esc_attr( (string) Validator::MESSAGE_MAX ) . '" rows="5"' . ( $has_pattern ? '' : ' required' ) . $this->invalid_attrs( 'message', $error, $this->id_of( 'md-inquiry-message-hint' ) ) . '>' . \esc_textarea( $value ) . '</textarea>';
		// The pages ask for drawings by name ("Send your DXF, DWG, or PDF"), so
		// the form has to say how a drawing reaches us. It cannot take a file:
		// there is no file input and no upload handler, and the old shop's
		// panel form had both. Until that exists, the honest instruction is the
		// reply — which is also how the internal mail already frames it
		// ("attaching them to your reply helps us quote faster").
		$out .= '<span class="md-inquiry-field__hint" id="' . \esc_attr( $this->id_of( 'md-inquiry-message-hint' ) ) . '">'
			. \esc_html__( 'Quantities, dimensions, timeline, finish preferences — anything you already have. Have a drawing, DXF, DWG or PDF? Send it in reply to the confirmation email and it reaches the same engineer.', 'md-inquiry' )
			. '</span>';
		$out .= $this->error_span( 'message', $error );
		$out .= '</p>';
		return $out;
	}


	/**
	 * The form-level notice. Always rendered, so a failure has a live region
	 * to write into. Empty until a submission fails, and the stylesheet
	 * collapses that empty state so it does not add a band above the fields.
	 *
	 * @return string
	 */
	private function notice_markup(): string {
		$class = 'md-inquiry-notice';
		$text  = '';
		if ( $this->state->has_failed() ) {
			$class .= ' md-inquiry-notice--error';
			$text   = \esc_html( $this->notice( $this->state->notice_key() ) );
		}
		return '<div class="' . \esc_attr( $class ) . '" role="alert" aria-live="polite">' . $text . '</div>';
	}

	/**
	 * The required confirmation that this inquiry is for an architectural or
	 * commercial project. It sits before the honeypot.
	 *
	 * @return string
	 */
	private function confirm_field(): string {
		$error   = $this->state->error( 'confirm' );
		$checked = '1' === $this->state->value( 'confirm' ) ? ' checked' : '';
		$invalid = '' !== $error ? ' md-inquiry-field--invalid' : '';
		$id      = $this->id_of( 'md-inquiry-confirm' );

		$out  = '<p class="md-inquiry-field md-inquiry-field--confirm' . $invalid . '">';
		$out .= '<label class="md-inquiry-confirm" for="' . \esc_attr( $id ) . '">';
		$out .= '<input class="md-inquiry-confirm__input" type="checkbox" id="' . \esc_attr( $id ) . '" name="md_inquiry_confirm" value="1"' . $checked . ' required' . $this->invalid_attrs( 'confirm', $error ) . '>';
		$out .= '<span class="md-inquiry-confirm__text">' . \esc_html__( 'I confirm this inquiry is for an architectural or commercial project.', 'md-inquiry' ) . '</span>';
		$out .= '</label>';
		$out .= $this->error_span( 'confirm', $error );
		$out .= '</p>';
		return $out;
	}

	/**
	 * A wa.me href that keeps the prefilled text's line breaks.
	 *
	 * WordPress deletes %0A and %0D inside esc_url(). Passing the whole URL
	 * through it glues the Ref line onto the greeting. Escape the base, then
	 * append the already-encoded text.
	 *
	 * @param string $text The prefilled message.
	 * @return string
	 */
	private function whatsapp_href( string $text ): string {
		return \esc_url( 'https://wa.me/' . WhatsApp::NUMBER ) . '?text=' . \rawurlencode( $text );
	}

	/**
	 * Attributes that mark a control invalid and point at its description.
	 *
	 * @param string $key               The field key.
	 * @param string $error             The field's error, or ''.
	 * @param string $extra_describedby An id that always describes the control, such as a hint.
	 * @return string
	 */
	private function invalid_attrs( string $key, string $error, string $extra_describedby = '' ): string {
		$ids   = array();
		$attrs = '';
		if ( '' !== $extra_describedby ) {
			$ids[] = $extra_describedby;
		}
		if ( '' !== $error ) {
			$attrs .= ' aria-invalid="true"';
			$ids[]  = $this->id_of( 'md-inquiry-' . $key . '-error' );
		}
		if ( array() !== $ids ) {
			$attrs .= ' aria-describedby="' . \esc_attr( implode( ' ', $ids ) ) . '"';
		}
		return $attrs;
	}

	/**
	 * A field error, associated by id and announced as an alert.
	 *
	 * @param string $key   The field key.
	 * @param string $error The error, or '' when the field is clean.
	 * @return string
	 */
	private function error_span( string $key, string $error ): string {
		if ( '' === $error ) {
			return '';
		}
		return '<span class="md-inquiry-field__error" id="' . \esc_attr( $this->id_of( 'md-inquiry-' . $key . '-error' ) ) . '" role="alert">' . \esc_html( $error ) . '</span>';
	}

	/**
	 * The honeypot: a field no customer ever sees filled, and no bot can
	 * resist. The template's stylesheet hides it; without styles it shows an
	 * honest "leave this empty" label.
	 *
	 * @return string
	 */
	private function honeypot(): string {
		return '<p class="md-inquiry-honeypot" aria-hidden="true"><label class="md-inquiry-field__label" for="' . \esc_attr( $this->id_of( 'md-inquiry-website' ) ) . '">' . \esc_html__( 'Leave this field empty', 'md-inquiry' ) . '</label><input class="md-inquiry-field__input" type="text" id="' . \esc_attr( $this->id_of( 'md-inquiry-website' ) ) . '" name="website" autocomplete="off" tabindex="-1"></p>';
	}

	/**
	 * The Turnstile slot, when the keys are in place.
	 *
	 * Explicit, not implicit. The container is not a cf-turnstile element:
	 * api.js is loaded with render=explicit, and the behavior file calls
	 * turnstile.render once per form, with that form's own callbacks. An
	 * implicit widget inside the closed quote dialog has no box; its error
	 * used to run the page-level callback and disable every submit.
	 *
	 * The sitekey and appearance stay on the element so the behavior file can
	 * read them without an inline script. The callbacks do not: a data-callback
	 * name would be one function for every form again.
	 *
	 * Without keys the slot is absent and the submission is marked instead of
	 * gated.
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
		// The widget's own language, stated rather than inferred. Cloudflare falls
		// back to the browser's locale, so a visitor on a Chinese-locale machine
		// saw "请验证您是真人 / 隐私 · 帮助" inside an otherwise English page — the
		// first and only interactive control in the inquiry chain, in a language
		// the site never uses. Measured on production 2026-10-29.
		//
		// Site language, not visitor language: this site ships English only
		// (owner ruling: multilingual is phase 2), and a control that switches
		// language between the label beside it and the widget itself reads as a
		// defect, not as personalisation.
		$lang = \esc_attr( substr( (string) \get_locale(), 0, 2 ) );
		$out  = '<div class="md-inquiry-turnstile" data-sitekey="' . $sitekey . '" data-language="' . $lang . '" data-appearance="interaction-only" data-theme="auto" data-retry="auto"></div>';
		$out .= '<p class="md-inquiry-turnstile-status" role="status" hidden></p>';
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
		return '<input type="hidden" class="md-inquiry-source" id="' . \esc_attr( $this->id_of( 'md-inquiry-source-' . $part ) ) . '" name="md_inquiry_source_' . \esc_attr( $part ) . '" value="' . \esc_attr( $value ) . '">';
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
				'errorCountry'     => __( 'Please choose your country.', 'md-inquiry' ),
				'errorScope'       => __( 'Please choose the project scope.', 'md-inquiry' ),
				'errorRoute'       => __( 'Please choose an inquiry route.', 'md-inquiry' ),
				'errorQuantity'    => __( 'Please choose a quantity range.', 'md-inquiry' ),
				'errorMessage'     => __( 'Please add a short message.', 'md-inquiry' ),
				'errorConfirm'     => __( 'Please confirm this inquiry is for an architectural or commercial project.', 'md-inquiry' ),
				'errorSummary'     => __( 'Please complete the highlighted fields.', 'md-inquiry' ),
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
