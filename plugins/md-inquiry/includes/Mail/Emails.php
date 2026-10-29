<?php
/**
 * The two mails an inquiry sends.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Mail;

use MetalDivider\Inquiry\Config;

/**
 * Composes the internal notification and the customer confirmation (spec 07
 * §5, branch research/spec-inquiry-chain, as revised by ticket #7). Both are
 * plain text — the chain sends through the native wp_mail pipeline, and an
 * unstyled text mail is the one format that carries no design debt. Every
 * line of customer-visible wording below is the archived final of the
 * adversarial review (AGENTS.md §5); the review record and the final texts
 * live together in docs/copy/inquiry-emails.md.
 */
final class Emails {

	/**
	 * The internal notification's mail type, as the queue records it.
	 */
	public const INTERNAL = 'internal_notification';

	/**
	 * The customer confirmation's mail type, as the queue records it.
	 */
	public const CONFIRMATION = 'customer_confirmation';

	/**
	 * The confirmation's reply-to: the customer's answer lands in the site's
	 * own mailbox, so the address a customer sees is on the domain they wrote
	 * to. The internal notification still goes to the owner's sales mailbox
	 * (`Config::internal_recipients()`), which is routing, not identity.
	 */
	public const REPLY_TO = 'info@metal-divider.com';

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the composer.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * The internal notification: everything the staff needs, addressed to the
	 * configured recipients (the primary first).
	 *
	 * @param array<string, mixed> $record The inquiry record's values.
	 * @return array{to: list<string>, subject: string, body: string, headers: list<string>}
	 */
	public function internal( array $record ): array {
		$pattern = self::pattern( $record );
		$subject = null !== $pattern
			? 'New inquiry: ' . $pattern['name'] . ' — ' . $record['name']
			: 'New inquiry: ' . $record['name'];

		$lines = array( 'A new inquiry arrived through the inquiry form.', '' );
		if ( null !== $pattern ) {
			$lines[] = 'Pattern: ' . $pattern['name'] . ' (' . $pattern['code'] . ')';
			$lines[] = 'Pattern page: ' . $record['pattern_url'];
		}
		$lines[] = 'Submitted: ' . $record['submitted_time'] . ' UTC';
		$lines[] = 'Source: ' . $record['source_page'] . '/' . $record['source_module'] . '/' . $record['source_button'];
		$lines[] = 'Verification: ' . $record['verification'];
		$lines[] = '';
		$lines[] = 'Name: ' . $record['name'];
		$lines[] = 'Email: ' . $record['email'];
		$lines[] = 'Company: ' . ( '' !== $record['company'] ? $record['company'] : '(none)' );
		$lines[] = 'Phone: ' . ( '' !== ( $record['phone'] ?? '' ) ? $record['phone'] : '(none)' );
		$lines[] = 'Country: ' . ( '' !== ( $record['country'] ?? '' ) ? $record['country'] : '(none)' );
		// The three qualifiers the form requires. They were stored on the record
		// but never printed here, so the sales inbox received a brief with the
		// customer's name and message and nothing that says how big the job is
		// or which product family it belongs to — every reply started with a
		// question the customer had already answered.
		$lines[] = 'Project scope: ' . ( '' !== ( $record['scope'] ?? '' ) ? $record['scope'] : '(none)' );
		$lines[] = 'Inquiry route: ' . ( '' !== ( $record['route'] ?? '' ) ? $record['route'] : '(none)' );
		$lines[] = 'Est. quantity: ' . ( '' !== ( $record['quantity'] ?? '' ) ? $record['quantity'] : '(none)' );
		$lines[] = 'Message:';
		$lines[] = '' !== $record['message'] ? \wordwrap( $record['message'], 76 ) : '(none)';
		$lines[] = '';
		$lines[] = 'Open the inquiry in the dashboard:';
		$lines[] = $record['edit_url'];
		$lines[] = '';
		$lines[] = '-- metal-divider.com inquiries';

		return array(
			'to'      => $this->config->internal_recipients(),
			'subject' => $subject,
			'body'    => \implode( "\n", $lines ),
			'headers' => array( 'From: MetalDivider <' . $this->config->from_email() . '>' ),
		);
	}

	/**
	 * The customer confirmation: the receipt, the reply promise, and the two
	 * faster channels.
	 *
	 * @param array<string, mixed> $record The inquiry record's values.
	 * @return array{to: list<string>, subject: string, body: string, headers: list<string>}
	 */
	public function confirmation( array $record ): array {
		$pattern = self::pattern( $record );
		$subject = null !== $pattern
			? 'We\'ve received your inquiry about ' . $pattern['name']
			: 'We\'ve received your inquiry';

		$lines = array( 'Hello ' . $record['name'] . ',', '' );
		if ( null !== $pattern ) {
			$lines[] = 'Thank you for your inquiry about ' . $pattern['name'] . ' (' . $pattern['code'] . ') — this email confirms we have received it.';
		} else {
			$lines[] = 'Thank you for your inquiry — this email confirms we have received it.';
		}
		$lines[] = '';
		$lines[] = 'Our team replies within 24 hours on business days with pattern availability, material options and pricing. If you sent dimensions, quantities or a timeline, we will address them in that reply. If you have drawings or site photos, attaching them to your reply helps us quote faster.';
		$lines[] = '';
		$lines[] = 'Need to reach us sooner? Reply directly to this email, or use:';
		$lines[] = '- WhatsApp: +852 6473 1687 — https://wa.me/85264731687';
		$lines[] = '- Email: info@metal-divider.com';
		$lines[] = '';
		$lines[] = 'Kind regards,';
		$lines[] = 'The MetalDivider team';
		$lines[] = 'metal-divider.com';

		return array(
			'to'      => array( $record['email'] ),
			'subject' => $subject,
			'body'    => \implode( "\n", $lines ),
			'headers' => array(
				'From: MetalDivider <' . $this->config->from_email() . '>',
				'Reply-To: ' . self::REPLY_TO,
			),
		);
	}

	/**
	 * The record's pattern context, or null when the inquiry carries none.
	 *
	 * @param array<string, mixed> $record The inquiry record's values.
	 * @return array{name: string, code: string}|null
	 */
	private static function pattern( array $record ): ?array {
		$name = isset( $record['pattern_name'] ) && \is_string( $record['pattern_name'] ) ? $record['pattern_name'] : '';
		return '' !== $name ? array(
			'name' => $name,
			'code' => (string) ( $record['pattern_code'] ?? '' ),
		) : null;
	}
}
