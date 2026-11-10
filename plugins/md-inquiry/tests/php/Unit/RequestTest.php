<?php
/**
 * The request facts an inquiry record keeps.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Request;
use MetalDivider\Inquiry\Tests\TestCase;

/**
 * The IP address is evidence the record keeps and RateLimiter keys on. The
 * site sits behind LiteSpeed with no CDN (AGENTS.md §2), so REMOTE_ADDR is the
 * only address the transport reports; a Cloudflare header is client input and
 * must never be read as the visitor's address.
 *
 * @covers \MetalDivider\Inquiry\Request
 */
final class RequestTest extends TestCase {

	/**
	 * Sets the pass-throughs up and starts from no server address.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_passthroughs();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * Removes the addresses a test planted.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
		parent::tearDown();
	}

	/**
	 * The transport address is the address, IPv4 and IPv6 alike.
	 */
	public function test_ip_reads_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertSame( '203.0.113.7', Request::ip() );

		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';
		$this->assertSame( '2001:db8::1', Request::ip(), 'IPv6 is an address too' );
	}

	/**
	 * A client-sent Cloudflare header cannot replace the transport address.
	 *
	 * Before this, HTTP_CF_CONNECTING_IP came first in the candidate list, so
	 * one client could present a new address on every request and RateLimiter
	 * keyed on a value the client chose.
	 */
	public function test_ip_ignores_a_forged_cloudflare_header(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';

		$this->assertSame( '203.0.113.7', Request::ip(), 'the transport address wins over a request header' );

		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '', Request::ip(), 'without REMOTE_ADDR the header is not a fallback' );
	}

	/**
	 * Anything that is not an address reads as the empty string.
	 */
	public function test_ip_is_empty_without_a_valid_transport_address(): void {
		$this->assertSame( '', Request::ip(), 'no REMOTE_ADDR at all' );

		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( '', Request::ip(), 'a rejected value is not an address' );

		$_SERVER['REMOTE_ADDR'] = '';
		$this->assertSame( '', Request::ip(), 'an empty value is not an address' );
	}
}
