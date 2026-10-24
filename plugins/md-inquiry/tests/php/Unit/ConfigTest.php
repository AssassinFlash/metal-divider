<?php
/**
 * Tests for the inquiry chain's configuration.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The resolution order: constants, options, defaults.
 *
 * @covers \MetalDivider\Inquiry\Config
 */
final class ConfigTest extends TestCase {

	/**
	 * The constants win: the injected values stand for wp-config constants,
	 * and a configured option is never read when one is present.
	 */
	public function test_a_constant_wins_over_the_option(): void {
		$config = new Config( array( 'MD_INQUIRY_TURNSTILE_SITEKEY' => 'from-constant' ) );
		WP_Mock::userFunction( 'get_option' )->never();

		$this->assertSame( 'from-constant', $config->turnstile_sitekey() );
	}

	/**
	 * The option is the fallback, and the default answers when nothing
	 * configures the value.
	 */
	public function test_the_option_is_the_fallback_and_the_default_last(): void {
		$config = new Config();

		WP_Mock::userFunction( 'get_option' )->once()->with( 'md_inquiry_turnstile_sitekey', '' )->andReturn( 'from-option' );
		$this->assertSame( 'from-option', $config->turnstile_sitekey() );

		WP_Mock::userFunction( 'get_option' )->once()->with( 'md_inquiry_turnstile_sitekey', '' )->andReturn( '' );
		$this->assertSame( '', $config->turnstile_sitekey() );
	}

	/**
	 * Turnstile is configured only when both keys exist — the switch between
	 * the fail-closed regime and the marked-but-open one.
	 */
	public function test_turnstile_needs_both_keys(): void {
		$no_keys = array(
			'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
			'MD_INQUIRY_TURNSTILE_SECRET'  => '',
		);
		$this->assertFalse( ( new Config( $no_keys ) )->turnstile_is_configured() );
		$this->assertFalse(
			( new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => '',
				)
			) )->turnstile_is_configured()
		);
		$this->assertTrue(
			( new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			) )->turnstile_is_configured()
		);
	}

	/**
	 * The internal recipients follow the route: both (the default, primary
	 * first), the primary alone, or the backup alone.
	 */
	public function test_the_notification_route_selects_the_recipients(): void {
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );
		$this->assertSame(
			array( Config::INTERNAL_PRIMARY, Config::INTERNAL_BACKUP ),
			( new Config() )->internal_recipients()
		);
		$this->assertSame(
			array( Config::INTERNAL_PRIMARY ),
			( new Config( array( 'MD_INQUIRY_NOTIFICATION_ROUTE' => Config::ROUTE_PRIMARY ) ) )->internal_recipients()
		);
		$this->assertSame(
			array( Config::INTERNAL_BACKUP ),
			( new Config( array( 'MD_INQUIRY_NOTIFICATION_ROUTE' => Config::ROUTE_BACKUP ) ) )->internal_recipients()
		);
	}

	/**
	 * An unknown route falls back to both — never to no mail at all.
	 */
	public function test_an_unknown_route_falls_back_to_both(): void {
		WP_Mock::userFunction( 'get_option' )->once()->with( 'md_inquiry_notification_route', Config::ROUTE_BOTH )->andReturn( 'nonsense' );
		$this->assertSame(
			array( Config::INTERNAL_PRIMARY, Config::INTERNAL_BACKUP ),
			( new Config() )->internal_recipients()
		);
	}

	/**
	 * The sender address defaults to the site-domain-aligned one.
	 */
	public function test_the_from_address_is_the_site_domain_one_by_default(): void {
		WP_Mock::userFunction( 'get_option' )->once()->andReturn( '' );
		$this->assertSame( 'info@metal-divider.com', ( new Config() )->from_email() );
		$this->assertSame( 'no-reply@metal-divider.com', ( new Config( array( 'MD_INQUIRY_FROM_EMAIL' => 'no-reply@metal-divider.com' ) ) )->from_email() );
	}

	/**
	 * Test mode rides on one valid address: an invalid recipient is the same
	 * as none, whichever way it was configured.
	 */
	public function test_test_mode_needs_one_valid_recipient(): void {
		$this->mock_is_email();
		WP_Mock::userFunction( 'get_option' )->once()->andReturn( '' );

		$on = new Config( array( 'MD_INQUIRY_TEST_RECIPIENT' => 'tester@example.test' ) );
		$this->assertTrue( $on->is_test_mode() );
		$this->assertSame( 'tester@example.test', $on->test_recipient() );

		$broken = new Config( array( 'MD_INQUIRY_TEST_RECIPIENT' => 'not-an-address' ) );
		$this->assertFalse( $broken->is_test_mode() );
		$this->assertSame( '', $broken->test_recipient() );

		$this->assertFalse( ( new Config() )->is_test_mode() );
	}
}
