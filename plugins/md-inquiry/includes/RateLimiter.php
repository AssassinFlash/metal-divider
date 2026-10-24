<?php
/**
 * The inquiry submission rate limit.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * The same-IP window rate limit (ticket #33: Turnstile plus a window, the
 * two anti-spam layers). Five submissions per ten minutes per IP — room for
 * a customer to fix a typo and retry, no room for a bot to walk through the
 * unconfigured early deployment. The hits ride in a transient, so the limit
 * needs no table of its own and disappears with the window.
 */
final class RateLimiter {

	/**
	 * How many submissions one IP may send per window.
	 */
	public const LIMIT = 5;

	/**
	 * The window's length, in seconds.
	 */
	public const WINDOW = 600;

	/**
	 * Records a hit and reports whether it still fits the window.
	 *
	 * @param string $ip The submitter's IP.
	 * @return bool True when the submission may proceed.
	 */
	public static function allow( string $ip ): bool {
		if ( '' === $ip ) {
			// Without an address there is nothing to key the window on; the
			// honeypot and Turnstile are the guards that remain.
			return true;
		}
		$now    = \time();
		$hits   = self::hits( $ip );
		$hits   = \array_values(
			\array_filter(
				$hits,
				static function ( int $hit ) use ( $now ): bool {
					return $hit > $now - self::WINDOW;
				}
			)
		);
		$hits[] = $now;
		\set_transient( self::key( $ip ), $hits, self::WINDOW );
		return \count( $hits ) <= self::LIMIT;
	}

	/**
	 * The window's current hits.
	 *
	 * @param string $ip The submitter's IP.
	 * @return list<int> The hit timestamps still inside the window.
	 */
	private static function hits( string $ip ): array {
		$stored = \get_transient( self::key( $ip ) );
		if ( ! \is_array( $stored ) ) {
			return array();
		}
		$hits = array();
		foreach ( $stored as $hit ) {
			if ( \is_int( $hit ) ) {
				$hits[] = $hit;
			}
		}
		return $hits;
	}

	/**
	 * The transient key for one IP.
	 *
	 * @param string $ip The submitter's IP.
	 * @return string
	 */
	public static function key( string $ip ): string {
		return 'md_inquiry_rl_' . \md5( $ip );
	}
}
