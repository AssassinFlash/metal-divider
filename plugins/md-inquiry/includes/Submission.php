<?php
/**
 * The inquiry form's POST handler.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

use MetalDivider\Inquiry\Mail\Emails;
use MetalDivider\Inquiry\Mail\Queue;

/**
 * Receives the form's POST — which goes to the page the form sits on, the
 * CSP's form-action 'self' honored by the plainest means — and walks it
 * through the chain: rate limit, nonce, honeypot, validation, Turnstile,
 * the record, the queue, the 303.
 *
 * The order is the spec's (spec 07 §7, branch research/spec-inquiry-chain):
 * nothing is stored and nothing is sent until every gate has passed, and a
 * failure anywhere returns the page to the customer with their values still
 * in it — a failed submission never costs the typed words. The one gate with
 * a regime switch is Turnstile: configured, it must pass (fail-closed);
 * unconfigured, the inquiry proceeds and the record carries the mark instead.
 */
final class Submission {

	/**
	 * The form's action value.
	 */
	public const ACTION = 'md_inquiry_submit';

	/**
	 * The nonce's field name.
	 */
	public const NONCE_FIELD = 'md_inquiry_nonce';

	/**
	 * The field validator.
	 *
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * The Turnstile verifier.
	 *
	 * @var Turnstile
	 */
	private Turnstile $turnstile;

	/**
	 * The pattern resolver.
	 *
	 * @var Pattern
	 */
	private Pattern $pattern;

	/**
	 * The outbound queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * The mail composer.
	 *
	 * @var Emails
	 */
	private Emails $emails;

	/**
	 * The failed submission's state.
	 *
	 * @var FormState
	 */
	private FormState $state;

	/**
	 * Sets up the handler.
	 *
	 * @param Validator $validator The field validator.
	 * @param Turnstile $turnstile The Turnstile verifier.
	 * @param Pattern   $pattern   The pattern resolver.
	 * @param Queue     $queue     The outbound queue.
	 * @param Emails    $emails    The mail composer.
	 * @param FormState $state     The failed submission's state.
	 */
	public function __construct( Validator $validator, Turnstile $turnstile, Pattern $pattern, Queue $queue, Emails $emails, FormState $state ) {
		$this->validator = $validator;
		$this->turnstile = $turnstile;
		$this->pattern   = $pattern;
		$this->queue     = $queue;
		$this->emails    = $emails;
		$this->state     = $state;
	}

	/**
	 * Hooks the handler into the front end's template phase.
	 */
	public function register(): void {
		\add_action( 'template_redirect', array( $this, 'handle' ), 20 );
	}

	/**
	 * Answers the form's POST, when this request is one.
	 */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- reading only the routing marker; the nonce is verified the moment the submission is one.
		$action = isset( $_POST['md_inquiry_action'] ) && \is_string( $_POST['md_inquiry_action'] ) ? \sanitize_key( \wp_unslash( $_POST['md_inquiry_action'] ) ) : '';
		if ( 'submit' !== $action ) {
			return;
		}
		$this->process();
	}

	/**
	 * The chain itself.
	 */
	private function process(): void {
		$values = $this->raw_values();

		$ip = Request::ip();
		if ( ! RateLimiter::allow( $ip ) ) {
			$this->state->fail( $values, array(), 'rate_limited' );
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && \is_string( $_POST[ self::NONCE_FIELD ] ) ? \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( false === \wp_verify_nonce( $nonce, self::ACTION ) ) {
			$this->state->fail( $values, array(), 'nonce' );
			return;
		}

		$honeypot = isset( $_POST['website'] ) && \is_string( $_POST['website'] ) ? \sanitize_text_field( \wp_unslash( $_POST['website'] ) ) : '';
		if ( '' !== $honeypot ) {
			$this->state->fail( array(), array(), 'spam' );
			return;
		}

		$pattern = $this->pattern->resolve( $values['pattern_slug'] );
		$checked = $this->validator->validate( $values, null !== $pattern );
		if ( array() !== $checked['errors'] ) {
			$this->state->fail( $values, $checked['errors'], 'validation' );
			return;
		}

		if ( $this->turnstile->is_configured() ) {
			$token = isset( $_POST['cf-turnstile-response'] ) && \is_string( $_POST['cf-turnstile-response'] ) ? \sanitize_text_field( \wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
			if ( ! $this->turnstile->verify( $token, $ip ) ) {
				$this->state->fail( $values, array(), 'turnstile' );
				return;
			}
			$verification = 'verified';
		} else {
			$verification = 'not_configured';
		}

		$record  = $checked['values'];
		$post_id = $this->store( $record, $pattern, $verification, $ip );
		if ( false === $post_id ) {
			$this->state->fail( $values, array(), 'storage' );
			return;
		}

		$mail_record = $this->mail_record( (int) $post_id, $record, $pattern, $verification );
		$this->enqueue_mails( (int) $post_id, $mail_record );

		\wp_safe_redirect( ThankYou::url( (int) $post_id ), 303 );
		exit;
	}

	/**
	 * The submitted values, sanitized, before validation.
	 *
	 * @return array<string, string>
	 */
	private function raw_values(): array {
		// phpcs:ignore WordPress.Security.NonceVerification -- sanitization follows immediately; the nonce is verified in process() before this runs.
		$post = $_POST;
		return array(
			'name'          => $this->post_text( $post, 'md_inquiry_name' ),
			'email'         => isset( $post['md_inquiry_email'] ) && \is_string( $post['md_inquiry_email'] ) ? \sanitize_email( \wp_unslash( $post['md_inquiry_email'] ) ) : '',
			'company'       => $this->post_text( $post, 'md_inquiry_company' ),
			'phone'         => $this->post_text( $post, 'md_inquiry_phone' ),
			'country'       => $this->post_text( $post, 'md_inquiry_country' ),
			'scope'         => $this->post_text( $post, 'md_inquiry_scope' ),
			'route'         => $this->post_text( $post, 'md_inquiry_route' ),
			'message'       => isset( $post['md_inquiry_message'] ) && \is_string( $post['md_inquiry_message'] ) ? \sanitize_textarea_field( \wp_unslash( $post['md_inquiry_message'] ) ) : '',
			'pattern_slug'  => isset( $post['md_inquiry_pattern_slug'] ) && \is_string( $post['md_inquiry_pattern_slug'] ) ? \sanitize_title( \wp_unslash( $post['md_inquiry_pattern_slug'] ) ) : '',
			'source_page'   => $this->post_text( $post, 'md_inquiry_source_page' ),
			'source_module' => $this->post_text( $post, 'md_inquiry_source_module' ),
			'source_button' => $this->post_text( $post, 'md_inquiry_source_button' ),
			'locale'        => $this->post_text( $post, 'md_inquiry_locale' ),
		);
	}

	/**
	 * One POST field, sanitized as plain text.
	 *
	 * @param array<string, mixed> $post The superglobal.
	 * @param string               $key  The field's name.
	 * @return string
	 */
	private function post_text( array $post, string $key ): string {
		return isset( $post[ $key ] ) && \is_string( $post[ $key ] ) ? \sanitize_text_field( \wp_unslash( $post[ $key ] ) ) : '';
	}

	/**
	 * Writes the inquiry record: the CPT post, then the meta, each field with
	 * the ACF reference the field group's read path resolves. Returns false
	 * when the post could not be created — then nothing was stored, and the
	 * customer is asked to retry (spec 07 §7: a record-less inquiry is
	 * refused outright, never mailed).
	 *
	 * @param array<string, string>                                                            $record      The validated values.
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern     The pattern context.
	 * @param string                                                                           $verification The Turnstile outcome.
	 * @param string                                                                           $ip          The submitter's IP.
	 * @return int|false The record's ID, or false.
	 */
	private function store( array $record, ?array $pattern, string $verification, string $ip ) {
		$company = '' !== $record['company'] ? $record['company'] . ' — ' : '';
		$title   = $record['name'] . ' — ' . $company . \gmdate( 'Y-m-d' );
		$post_id = \wp_insert_post(
			array(
				'post_type'   => PostType::NAME,
				'post_status' => Statuses::NEW,
				'post_title'  => $title,
			),
			true
		);
		if ( \is_wp_error( $post_id ) ) {
			return false;
		}
		$post_id = (int) $post_id;

		$fields = array(
			'submitted_time'   => \gmdate( 'Y-m-d H:i:s' ),
			'customer_name'    => $record['name'],
			'customer_email'   => $record['email'],
			'customer_company' => $record['company'],
			'customer_phone'   => $record['phone'],
			'customer_country' => $record['country'],
			'project_scope'    => $record['scope'],
			'inquiry_route'    => $record['route'],
			'message'          => $record['message'],
			'pattern_product'  => null !== $pattern ? $pattern['post_id'] : '',
			'pattern_name'     => null !== $pattern ? $pattern['name'] : '',
			'pattern_code'     => null !== $pattern ? $pattern['code'] : '',
			'pattern_slug'     => null !== $pattern ? $record['pattern_slug'] : '',
			'source_page'      => $record['source_page'],
			'source_module'    => $record['source_module'],
			'source_button'    => $record['source_button'],
			'ip_address'       => $ip,
			'user_agent'       => Request::user_agent_summary(),
			'turnstile_status' => $verification,
		);
		$keys   = Fields\FieldGroup::field_keys();
		foreach ( $fields as $name => $value ) {
			\update_post_meta( $post_id, $name, $value );
			if ( isset( $keys[ $name ] ) ) {
				\update_post_meta( $post_id, '_' . $name, $keys[ $name ] );
			}
		}
		\update_post_meta( $post_id, 'inquiry_locale', $record['locale'] );
		return $post_id;
	}

	/**
	 * The record shaped for the mail composer.
	 *
	 * @param int                                                                              $post_id     The record's ID.
	 * @param array<string, string>                                                            $record      The validated values.
	 * @param array{post_id: int, name: string, code: string, url: string, thumb: string}|null $pattern The pattern context.
	 * @param string                                                                           $verification The Turnstile outcome.
	 * @return array<string, mixed>
	 */
	private function mail_record( int $post_id, array $record, ?array $pattern, string $verification ): array {
		return array(
			'name'           => $record['name'],
			'email'          => $record['email'],
			'company'        => $record['company'],
			'phone'          => $record['phone'],
			'country'        => $record['country'],
			'scope'          => $record['scope'],
			'route'          => $record['route'],
			'message'        => $record['message'],
			'pattern_name'   => null !== $pattern ? $pattern['name'] : '',
			'pattern_code'   => null !== $pattern ? $pattern['code'] : '',
			'pattern_url'    => null !== $pattern ? $pattern['url'] : '',
			'source_page'    => $record['source_page'],
			'source_module'  => $record['source_module'],
			'source_button'  => $record['source_button'],
			'verification'   => $verification,
			'submitted_time' => \gmdate( 'Y-m-d H:i:s' ),
			'edit_url'       => \admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);
	}

	/**
	 * Puts both mails on the queue. A row that cannot be written is a PHP
	 * error for the recorder, never a failed submission: the record — the
	 * system's source of truth — is already stored.
	 *
	 * @param int                  $post_id The record's ID.
	 * @param array<string, mixed> $record  The mail record.
	 * @return void
	 */
	private function enqueue_mails( int $post_id, array $record ): void {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput -- a lost enqueue must be visible, and the error recorder is where it lands; these are developer log lines, never page output.
		if ( ! $this->queue->enqueue( $post_id, Emails::INTERNAL, $this->emails->internal( $record ) ) ) {
			\trigger_error( 'md-inquiry: the internal notification of inquiry ' . (string) $post_id . ' could not be queued.', \E_USER_WARNING );
		}
		if ( ! $this->queue->enqueue( $post_id, Emails::CONFIRMATION, $this->emails->confirmation( $record ) ) ) {
			\trigger_error( 'md-inquiry: the customer confirmation of inquiry ' . (string) $post_id . ' could not be queued.', \E_USER_WARNING );
		}
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput
	}
}
