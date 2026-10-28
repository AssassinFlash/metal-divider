<?php
/**
 * The inquiry form's field validation.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Validates the four fields a customer fills, and normalizes the plumbing
 * values that ride along: the three-part source, the pattern slug and the
 * locale. The field set is the spec's minimum (spec 07 §3, branch
 * research/spec-inquiry-chain, as revised by ticket #7: no pattern list, the
 * pattern rides as the single-pattern context) — the message is optional when
 * a pattern is attached and required when it is not, because then it is the
 * only carrier of intent.
 */
final class Validator {

	/**
	 * The field length limits (spec 07 §3).
	 */
	public const NAME_MAX    = 120;
	public const EMAIL_MAX   = 200;
	public const COMPANY_MAX = 160;
	public const PHONE_MAX   = 40;

	/**
	 * The country list the form offers. These are the markets the site already
	 * names in its own copy (the family pages and the trust band), and the old
	 * contact page's own list — carried as data rather than typed into the
	 * template so the value the validator accepts and the value the form
	 * prints cannot drift.
	 *
	 * @var list<string>
	 */
	public const COUNTRIES = array(
		'United States',
		'United Kingdom',
		'United Arab Emirates',
		'Canada',
		'Australia',
		'Saudi Arabia',
		'Qatar',
		'Singapore',
		'Hong Kong',
		'Malaysia',
		'Germany',
		'France',
		'Netherlands',
		'Other',
	);

	/**
	 * Project scope, from the old contact page's own select.
	 *
	 * @var list<string>
	 */
	public const SCOPES = array(
		'Sample panel',
		'Single zone',
		'Multi-zone project',
		'Full facade package',
	);

	/**
	 * Inquiry route: which screen family the brief belongs to.
	 *
	 * @var list<string>
	 */
	public const ROUTES = array(
		'Facade screening',
		'Interior partitions',
		'Outdoor privacy screens',
		'Feature walls',
		'Custom fabrication',
	);

	/**
	 * Quantity bands the form requires before a quote can be priced.
	 *
	 * Volume decides the price band and whether the job is a sample or a
	 * production run. The list is the contract the select and this validator
	 * share. It is not a record of a control on the old contact page.
	 *
	 * @var list<string>
	 */
	public const QUANTITIES = array(
		'Sample / test panel (1-10 sqm)',
		'10-50 sqm',
		'50-100 sqm',
		'100-250 sqm',
		'250+ sqm',
		'Not decided yet',
	);

	/**
	 * One value from a fixed list: never free text, never an error.
	 *
	 * A value outside the list becomes the empty string, which the caller's
	 * required-check then reports — the same shape the source tokens use. The
	 * list is the contract, so a stale option cannot reach the record.
	 *
	 * @param array<string, mixed> $input   The raw input.
	 * @param string               $key     The field key.
	 * @param array<int, string>   $allowed The permitted values.
	 * @return string
	 */
	private function choice( array $input, string $key, array $allowed ): string {
		$raw = isset( $input[ $key ] ) && \is_string( $input[ $key ] ) ? \trim( \wp_unslash( $input[ $key ] ) ) : '';
		return \in_array( $raw, $allowed, true ) ? $raw : '';
	}
	public const COUNTRY_MAX  = 80;
	public const SCOPE_MAX    = 60;
	public const ROUTE_MAX    = 60;
	public const QUANTITY_MAX = 60;
	public const MESSAGE_MAX  = 5000;

	/**
	 * The source tokens' shape: the old site's lowercase vocabulary, where
	 * page slugs and module names hyphenate (pattern-pdp, list-drawer) and
	 * button actions underscore (request_quote, send_project_context) — the
	 * measured vocabulary of spec 07's F6, kept whole.
	 */
	public const SOURCE_PATTERN = '/^[a-z0-9_-]{1,64}$/';

	/**
	 * The locales the first phase carries.
	 */
	public const LOCALES = array( 'en' );

	/**
	 * Validates the submitted values.
	 *
	 * @param array<string, mixed> $input       The raw, already-sanitized input.
	 * @param bool                 $has_pattern Whether a pattern context resolved.
	 * @return array{values: array<string, string>, errors: array<string, string>} The clean values and the errors, keyed by field name.
	 */
	public function validate( array $input, bool $has_pattern ): array {
		$values = array(
			'name'          => $this->text( $input, 'name', self::NAME_MAX ),
			'email'         => $this->text( $input, 'email', self::EMAIL_MAX ),
			'company'       => $this->text( $input, 'company', self::COMPANY_MAX ),
			'phone'         => $this->text( $input, 'phone', self::PHONE_MAX ),
			'country'       => $this->choice( $input, 'country', self::COUNTRIES ),
			'scope'         => $this->choice( $input, 'scope', self::SCOPES ),
			'route'         => $this->choice( $input, 'route', self::ROUTES ),
			'quantity'      => $this->choice( $input, 'quantity', self::QUANTITIES ),
			'confirm'       => '1' === $this->text( $input, 'confirm', 8 ) ? '1' : '',
			'message'       => $this->text( $input, 'message', self::MESSAGE_MAX ),
			'pattern_slug'  => $this->slug( $input ),
			'source_page'   => self::source_token( (string) ( $input['source_page'] ?? '' ) ),
			'source_module' => self::source_token( (string) ( $input['source_module'] ?? '' ) ),
			'source_button' => self::source_token( (string) ( $input['source_button'] ?? '' ) ),
			'locale'        => self::locale( (string) ( $input['locale'] ?? '' ) ),
		);
		$errors = array();
		if ( '' === $values['name'] ) {
			$errors['name'] = __( 'Please enter your name.', 'md-inquiry' );
		}
		if ( '' === $values['email'] || false === \is_email( $values['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'md-inquiry' );
		}
		if ( '' === $values['country'] ) {
			$errors['country'] = __( 'Please choose your country.', 'md-inquiry' );
		}
		if ( '' === $values['scope'] ) {
			$errors['scope'] = __( 'Please choose the project scope.', 'md-inquiry' );
		}
		if ( '' === $values['route'] ) {
			$errors['route'] = __( 'Please choose an inquiry route.', 'md-inquiry' );
		}
		if ( '' === $values['quantity'] ) {
			$errors['quantity'] = __( 'Please choose a quantity range.', 'md-inquiry' );
		}
		if ( '1' !== $values['confirm'] ) {
			$errors['confirm'] = __( 'Please confirm this inquiry is for an architectural or commercial project.', 'md-inquiry' );
		}
		if ( '' === $values['message'] && ! $has_pattern ) {
			$errors['message'] = __( 'Please add a short message.', 'md-inquiry' );
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * One source token, normalized: a lowercase slug passes through, anything
	 * else becomes the empty string — never an error, never stored (spec 07
	 * §3: values outside the whitelist are normalized away quietly).
	 *
	 * @param string $raw The raw token.
	 * @return string
	 */
	public static function source_token( string $raw ): string {
		$raw = \trim( $raw );
		return 1 === \preg_match( self::SOURCE_PATTERN, $raw ) ? $raw : '';
	}

	/**
	 * One locale, normalized the same quiet way.
	 *
	 * @param string $raw The raw locale.
	 * @return string
	 */
	public static function locale( string $raw ): string {
		$raw = \trim( $raw );
		return \in_array( $raw, self::LOCALES, true ) ? $raw : '';
	}

	/**
	 * One trimmed text field.
	 *
	 * @param array<string, mixed> $input The raw input.
	 * @param string               $key   The field's key.
	 * @param int                  $max   The field's limit.
	 * @return string
	 */
	private function text( array $input, string $key, int $max ): string {
		$value = isset( $input[ $key ] ) && \is_string( $input[ $key ] ) ? \trim( $input[ $key ] ) : '';
		if ( \strlen( $value ) > $max ) {
			$value = \substr( $value, 0, $max );
		}
		return $value;
	}

	/**
	 * The pattern slug, as a slug.
	 *
	 * @param array<string, mixed> $input The raw input.
	 * @return string
	 */
	private function slug( array $input ): string {
		$value = isset( $input['pattern_slug'] ) && \is_string( $input['pattern_slug'] ) ? \sanitize_title( $input['pattern_slug'] ) : '';
		return '' === $value ? '' : \substr( $value, 0, 200 );
	}
}
