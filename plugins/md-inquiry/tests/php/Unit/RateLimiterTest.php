<?php
/**
 * Tests for the same-IP submission rate limit.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\RateLimiter;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The same-IP window: five fit, the sixth waits.
 *
 * @covers \MetalDivider\Inquiry\RateLimiter
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Runs one submission against a stored window state, with the transient
	 * pair mocked in memory.
	 *
	 * @param array<int, int> $stored The hits already in the window.
	 * @return bool Whether the submission was allowed.
	 */
	private function attempt( array $stored ): bool {
		$now  = \time();
		$hits = null;
		WP_Mock::userFunction( 'get_transient' )->once()->with( RateLimiter::key( '203.0.113.7' ) )->andReturn( $stored );
		WP_Mock::userFunction( 'set_transient' )->once()->with(
			RateLimiter::key( '203.0.113.7' ),
			WP_Mock\Functions::type( 'array' ),
			RateLimiter::WINDOW
		)->andReturnUsing(
			static function ( string $key, array $written ) use ( &$hits ): bool {
				$hits = $written;
				return true;
			}
		);
		$allowed = RateLimiter::allow( '203.0.113.7' );
		$this->assertIsArray( $hits );
		$this->assertGreaterThanOrEqual( 1, \count( \array_filter( $hits, static fn( int $hit ): bool => $hit >= $now ) ), 'the attempt itself is recorded' );
		return $allowed;
	}

	/**
	 * The window's limit: five submissions fit, the sixth does not.
	 */
	public function test_five_fit_and_the_sixth_is_refused(): void {
		$now = \time();
		$this->assertTrue( $this->attempt( array() ), 'the first fits' );
		$this->assertTrue( $this->attempt( array( $now - 10, $now - 20, $now - 30, $now - 40 ) ), 'the fifth fits' );
		$this->assertFalse( $this->attempt( array( $now - 10, $now - 20, $now - 30, $now - 40, $now - 50 ) ), 'the sixth does not' );
	}

	/**
	 * Old hits leave the window: a burst an hour ago does not count against a
	 * customer who comes back.
	 */
	public function test_hits_outside_the_window_stop_counting(): void {
		$now = \time();
		$old = $now - RateLimiter::WINDOW - 60;
		$this->assertTrue(
			$this->attempt( array( $old, $old, $old, $old, $old, $old ) ),
			'six stale hits are not six submissions'
		);
	}

	/**
	 * A record the window cannot read is ignored, not trusted.
	 */
	public function test_a_corrupted_window_reads_as_empty(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->with( RateLimiter::key( '203.0.113.7' ) )->andReturn( 'not-an-array' );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturnTrue();

		$this->assertTrue( RateLimiter::allow( '203.0.113.7' ) );
	}

	/**
	 * Without an address there is no window to key on; the other guards
	 * (honeypot, Turnstile) hold the line.
	 */
	public function test_no_address_is_no_window(): void {
		WP_Mock::userFunction( 'get_transient' )->never();
		WP_Mock::userFunction( 'set_transient' )->never();

		$this->assertTrue( RateLimiter::allow( '' ) );
	}

	/**
	 * Two addresses never share a window.
	 */
	public function test_the_window_is_per_address(): void {
		$this->assertSame( 'md_inquiry_rl_' . \md5( 'a.example' ), RateLimiter::key( 'a.example' ) );
		$this->assertNotSame( RateLimiter::key( 'a.example' ), RateLimiter::key( 'b.example' ) );
	}
}
