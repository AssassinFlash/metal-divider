<?php
/**
 * Tests for the optional guest email endpoint.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The endpoint's pure validation rules and the route it registers. The
 * database write itself is exercised against staging; the contract the
 * browser depends on is the normalisation, the address check and the
 * capability check on the chat hash.
 *
 * @covers ::md_chat_email_normalize
 * @covers ::md_chat_email_valid
 * @covers ::md_chat_email_hash_ok
 * @covers ::md_chat_email_register
 */
final class ChatEmailTest extends TestCase {

	/**
	 * Trims a submitted string and rejects anything that is not a string.
	 */
	public function test_normalises_to_a_trimmed_string(): void {
		self::assertSame( 'visitor@example.com', md_chat_email_normalize( '  visitor@example.com  ' ) );
		self::assertSame( '', md_chat_email_normalize( '' ) );
		self::assertSame( '', md_chat_email_normalize( null ) );
		self::assertSame( '', md_chat_email_normalize( array( 'visitor@example.com' ) ) );
	}

	/**
	 * Accepts ordinary addresses and rejects empty, malformed and oversized
	 * values, because the column holds 100 characters.
	 */
	public function test_validates_an_address_with_a_bounded_length(): void {
		self::assertTrue( md_chat_email_valid( 'visitor@example.com' ) );
		self::assertTrue( md_chat_email_valid( 'visitor+patterns@metal-divider.com' ) );
		self::assertFalse( md_chat_email_valid( '' ) );
		self::assertFalse( md_chat_email_valid( 'not-an-address' ) );
		self::assertFalse( md_chat_email_valid( 'visitor@' ) );
		self::assertFalse( md_chat_email_valid( str_repeat( 'a', 90 ) . '@example.com' ) );
	}

	/**
	 * Requires the stored 40-character chat hash exactly.
	 */
	public function test_accepts_only_the_stored_chat_hash(): void {
		$stored = 'dryymNnc0foLr9Y9OWbTI5oqH5nX3NROttOCfyUQ';

		self::assertTrue( md_chat_email_hash_ok( $stored, $stored ) );
		self::assertFalse( md_chat_email_hash_ok( strtolower( $stored ), $stored ) );
		self::assertFalse( md_chat_email_hash_ok( 'short', $stored ) );
		self::assertFalse( md_chat_email_hash_ok( str_repeat( 'x', 40 ), $stored ) );
		self::assertFalse( md_chat_email_hash_ok( null, $stored ) );
	}

	/**
	 * Registers the md/v1 /chat/email POST route with the same public
	 * permission contract as the knowledge endpoint.
	 */
	public function test_registers_the_guest_email_route(): void {
		$hooks = $GLOBALS['md_mu_test_hooks'] ?? array();
		self::assertContains( array( 'md_chat_email_register', 10, 1 ), $hooks['rest_api_init'] ?? array() );

		md_chat_email_register();

		self::assertContains(
			array(
				'md/v1',
				'/chat/email',
				array(
					'methods'             => 'POST',
					'callback'            => 'md_chat_email_respond',
					'permission_callback' => '__return_true',
				),
			),
			$GLOBALS['md_mu_test_routes'] ?? array()
		);
	}
}
