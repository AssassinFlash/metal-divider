<?php
/**
 * Tests for the two mails an inquiry sends.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Mail\Emails;
use MetalDivider\Inquiry\Tests\TestCase;

/**
 * The routing table of spec 07 §5 as it becomes mail: who receives what,
 * which address sends, where a reply lands — and the plain-text shape, the
 * one format with no design debt. The wording itself is the archived final
 * of the adversarial review (docs/copy/inquiry-emails.md); the tests pin
 * its load-bearing lines.
 *
 * @covers \MetalDivider\Inquiry\Mail\Emails
 */
final class EmailsTest extends TestCase {

	/**
	 * The configuration's defaults, without WordPress in the room: the route
	 * and the sender both fall back to their spec values.
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
	}

	/**
	 * One record, in the shape the submission handler assembles.
	 *
	 * @return array<string, mixed>
	 */
	private static function record(): array {
		return array(
			'name'           => 'Jane Chan',
			'email'          => 'jane@studio.com',
			'company'        => 'Chan Design Studio',
			'phone'          => '+852 1234 5678',
			'country'        => 'Hong Kong',
			'scope'          => 'Single zone',
			'route'          => 'Interior partitions',
			'quantity'       => '10-50 sqm',
			'message'        => 'Quantity: 40 panels for a lobby feature wall.',
			'pattern_name'   => 'Apiary',
			'pattern_code'   => 'APIARY',
			'pattern_url'    => 'https://metal-divider.com/pattern/apiary-architectural-metal-screen-pattern',
			'source_page'    => 'pattern-pdp',
			'source_module'  => 'hero',
			'source_button'  => 'request_quote',
			'verification'   => 'verified',
			'submitted_time' => '2026-10-24 15:04:11',
			'edit_url'       => 'https://metal-divider.com/wp-admin/post.php?post=123&action=edit',
		);
	}

	/**
	 * The internal notification reaches both internal recipients, primary
	 * first, from the site-domain sender.
	 */
	public function test_the_internal_notification_routes_to_both_internal_addresses(): void {
		$mail = ( new Emails( new Config() ) )->internal( self::record() );

		$this->assertSame( array( 'sales@sunriverinverter.com', 'info@metal-divider.com' ), $mail['to'] );
		$this->assertSame( 'New inquiry: Apiary — Jane Chan', $mail['subject'] );
		$this->assertContains( 'From: MetalDivider <info@metal-divider.com>', $mail['headers'] );
	}

	/**
	 * The internal notification carries everything the staff reads: the
	 * pattern with its code and page, the submitted time and source, the
	 * verification outcome, the customer's fields, the dashboard link.
	 */
	public function test_the_internal_notification_carries_the_whole_record(): void {
		$body = ( new Emails( new Config() ) )->internal( self::record() )['body'];

		$this->assertStringContainsString( 'A new inquiry arrived through the inquiry form.', $body );
		$this->assertStringContainsString( 'Pattern: Apiary (APIARY)', $body );
		$this->assertStringContainsString( 'Submitted: 2026-10-24 15:04:11 UTC', $body );
		$this->assertStringContainsString( 'Source: pattern-pdp/hero/request_quote', $body );
		$this->assertStringContainsString( 'Verification: verified', $body );
		$this->assertStringContainsString( 'Name: Jane Chan', $body );
		$this->assertStringContainsString( 'Email: jane@studio.com', $body );
		$this->assertStringContainsString( 'Company: Chan Design Studio', $body );
		$this->assertStringContainsString( 'Quantity: 40 panels for a lobby feature wall.', $body );
		$this->assertStringContainsString( 'post.php?post=123&action=edit', $body );
		$this->assertStringNotContainsString( '<', $body, 'the mail is plain text, no markup' );
	}

	/**
	 * The primary-only route addresses the primary alone; the backup route the
	 * backup alone — the switch the settings page owns.
	 */
	public function test_the_route_switch_selects_one_recipient(): void {
		$emails = new Emails( new Config( array( 'MD_INQUIRY_NOTIFICATION_ROUTE' => Config::ROUTE_PRIMARY ) ) );
		$this->assertSame( array( 'sales@sunriverinverter.com' ), $emails->internal( self::record() )['to'] );

		$emails = new Emails( new Config( array( 'MD_INQUIRY_NOTIFICATION_ROUTE' => Config::ROUTE_BACKUP ) ) );
		$this->assertSame( array( 'info@metal-divider.com' ), $emails->internal( self::record() )['to'] );
	}

	/**
	 * The confirmation goes to the customer alone, from the site-domain
	 * address, with the reply routed to the owner's sales mailbox.
	 */
	public function test_the_confirmation_reaches_the_customer_from_the_site_address(): void {
		$mail = ( new Emails( new Config() ) )->confirmation( self::record() );

		$this->assertSame( array( 'jane@studio.com' ), $mail['to'] );
		$this->assertSame( "We've received your inquiry about Apiary", $mail['subject'] );
		$this->assertContains( 'From: MetalDivider <info@metal-divider.com>', $mail['headers'] );
		$this->assertContains( 'Reply-To: sales@sunriverinverter.com', $mail['headers'] );
	}

	/**
	 * The confirmation's wording: the receipt with the pattern named, the
	 * one-business-day promise, the two faster channels with the number as
	 * people read it, and the reply invitation the Reply-To backs.
	 */
	public function test_the_confirmation_carries_the_reviewed_wording(): void {
		$body = ( new Emails( new Config() ) )->confirmation( self::record() )['body'];

		$this->assertStringContainsString( 'Hello Jane Chan,', $body, 'the full name, international B2B' );
		$this->assertStringContainsString( 'Thank you for your inquiry about Apiary (APIARY) — this email confirms we have received it.', $body );
		$this->assertStringContainsString( 'replies within 24 hours on business days', $body );
		$this->assertStringContainsString( 'Reply directly to this email', $body );
		$this->assertStringContainsString( 'WhatsApp: +852 6473 1687 — https://wa.me/85264731687', $body, 'the number reads grouped; the link carries the wa.me digits' );
		$this->assertStringContainsString( 'Email: info@metal-divider.com', $body );
		$this->assertStringNotContainsString( '<', $body, 'the mail is plain text, no markup' );
	}

	/**
	 * Without a pattern both mails say so without the pattern's lines, and an
	 * empty company or message shows the honest "(none)".
	 */
	public function test_the_no_pattern_and_empty_field_variants(): void {
		$record = self::record();
		unset( $record['pattern_name'], $record['pattern_code'], $record['pattern_url'] );
		$record['company'] = '';
		$record['message'] = '';
		$emails            = new Emails( new Config() );

		$internal = $emails->internal( $record );
		$this->assertSame( 'New inquiry: Jane Chan', $internal['subject'] );
		$this->assertStringNotContainsString( 'Pattern:', $internal['body'] );
		$this->assertStringContainsString( 'Company: (none)', $internal['body'] );
		$this->assertStringContainsString( "Message:\n(none)", $internal['body'] );

		$confirmation = $emails->confirmation( $record );
		$this->assertSame( "We've received your inquiry", $confirmation['subject'] );
		$this->assertStringContainsString( 'Thank you for your inquiry — this email confirms we have received it.', $confirmation['body'] );
	}

	/**
	 * The internal notification carries every qualifier the form asked for.
	 *
	 * The record has always stored the country, the scope, the route and the
	 * quantity, but the mail printed only name, email, company and message. The
	 * sales inbox therefore opened each brief without the two facts that decide
	 * the answer — how big the job is and which product family it belongs to —
	 * and had to ask for them again.
	 */
	public function test_the_internal_notification_carries_every_qualified_field(): void {
		$body = ( new Emails( new Config() ) )->internal( self::record() )['body'];

		foreach ( array(
			'Phone: +852 1234 5678',
			'Country: Hong Kong',
			'Project scope: Single zone',
			'Inquiry route: Interior partitions',
			'Est. quantity: 10-50 sqm',
		) as $line ) {
			$this->assertStringContainsString( $line, $body, $line . ' reaches the sales inbox' );
		}
	}
}
