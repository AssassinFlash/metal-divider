<?php
/**
 * Tests for the SMTP mail transport.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the assertions read PHPMailer's real camel-cased public properties.
namespace Md\Mu\Tests\Unit;

use Md\Mu\MailSmtp;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * The transport's contract outside WordPress: inert until the deployment's
 * constants exist, and once they do, the authenticated mailbox becomes both
 * the SMTP login and the envelope sender, with the port picking the matching
 * transport security. The constants themselves are never defined here — the
 * overrides seam stands in for them, the Config pattern the plugins use.
 *
 * @covers \Md\Mu\MailSmtp
 */
final class MailSmtpTest extends TestCase {

	/**
	 * A mailer with the fields this transport sets, reset before every test.
	 *
	 * @return PHPMailer
	 */
	private function mailer(): PHPMailer {
		$mailer           = new PHPMailer( true );
		$mailer->Mailer   = 'mail';
		$mailer->Host     = 'host-before.example';
		$mailer->Sender   = 'envelope-before@example';
		$mailer->From     = 'header-before@example';
		$mailer->Password = 'password-before';
		return $mailer;
	}

	/**
	 * Without credentials nothing is touched: the host's own transport
	 * configuration stays in force on deployments that define no constants.
	 */
	public function test_stays_inert_without_credentials(): void {
		$mailer = $this->mailer();
		MailSmtp\apply( $mailer, array() );
		$this->assertSame( 'host-before.example', $mailer->Host );
		$this->assertSame( 'envelope-before@example', $mailer->Sender );
		$this->assertSame( 'header-before@example', $mailer->From );
		$this->assertSame( 'password-before', $mailer->Password );
		$this->assertSame( 'mail', $mailer->Mailer );
	}

	/**
	 * A partial credential set is still inert: the mailer must never
	 * authenticate with half a configuration.
	 */
	public function test_partial_credentials_stay_inert(): void {
		$mailer = $this->mailer();
		MailSmtp\apply(
			$mailer,
			array(
				'MD_SMTP_HOST' => 'smtp.hostinger.com',
				'MD_SMTP_USER' => 'info@metal-divider.com',
			)
		);
		$this->assertSame( 'mail', $mailer->Mailer );
		$this->assertSame( 'host-before.example', $mailer->Host );
	}

	/**
	 * With the credentials the mailer authenticates as the site mailbox and
	 * the envelope sender aligns with it — the alignment whose absence put
	 * the inquiry notifications in Junk.
	 */
	public function test_authenticated_mailbox_becomes_the_envelope_sender(): void {
		$mailer = $this->mailer();
		MailSmtp\apply(
			$mailer,
			array(
				'MD_SMTP_HOST' => 'smtp.hostinger.com',
				'MD_SMTP_USER' => 'info@metal-divider.com',
				'MD_SMTP_PASS' => 'fixture-password',
			)
		);
		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp.hostinger.com', $mailer->Host );
		$this->assertSame( 465, $mailer->Port );
		$this->assertTrue( $mailer->SMTPAuth );
		$this->assertSame( 'info@metal-divider.com', $mailer->Username );
		$this->assertSame( 'fixture-password', $mailer->Password );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
		$this->assertSame( 'info@metal-divider.com', $mailer->Sender );
		$this->assertSame( 'info@metal-divider.com', $mailer->From );
	}

	/**
	 * A non-default port switches the transport security to STARTTLS, and an
	 * explicit From address overrides the login address as the header sender.
	 */
	public function test_alternate_port_uses_tls_and_from_overrides(): void {
		$mailer = $this->mailer();
		MailSmtp\apply(
			$mailer,
			array(
				'MD_SMTP_HOST' => 'smtp.hostinger.com',
				'MD_SMTP_USER' => 'info@metal-divider.com',
				'MD_SMTP_PASS' => 'fixture-password',
				'MD_SMTP_PORT' => 587,
				'MD_SMTP_FROM' => 'quotes@metal-divider.com',
			)
		);
		$this->assertSame( 587, $mailer->Port );
		$this->assertSame( 'tls', $mailer->SMTPSecure );
		$this->assertSame( 'info@metal-divider.com', $mailer->Sender );
		$this->assertSame( 'quotes@metal-divider.com', $mailer->From );
	}

	/**
	 * An out-of-range port falls back to the implicit-TLS default rather than
	 * configuring a port the platform would refuse.
	 */
	public function test_invalid_port_falls_back_to_465(): void {
		$mailer = $this->mailer();
		MailSmtp\apply(
			$mailer,
			array(
				'MD_SMTP_HOST' => 'smtp.hostinger.com',
				'MD_SMTP_USER' => 'info@metal-divider.com',
				'MD_SMTP_PASS' => 'fixture-password',
				'MD_SMTP_PORT' => 'not-a-port',
			)
		);
		$this->assertSame( 465, $mailer->Port );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
	}
}
