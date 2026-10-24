<?php
/**
 * The request facts an inquiry record keeps.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * The two facts about the submitter the record stores: the IP address and a
 * User Agent summary. Both are evidence, not identity — the site has no
 * accounts — and both are read defensively, because every value here comes
 * from the client.
 */
final class Request {

	/**
	 * The User Agent string's stored length: a summary, not the whole header.
	 */
	public const UA_MAX = 300;

	/**
	 * The submitter's IP address: Cloudflare's connecting IP when the site is
	 * proxied (its value is a plausible IP), otherwise the transport-level
	 * remote address. The empty string when neither reads.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			$raw = isset( $_SERVER[ $key ] ) ? \sanitize_text_field( \wp_unslash( $_SERVER[ $key ] ) ) : '';
			if ( '' !== $raw && false !== \filter_var( $raw, \FILTER_VALIDATE_IP ) ) {
				return $raw;
			}
		}
		return '';
	}

	/**
	 * The User Agent, truncated to a summary the record can keep.
	 *
	 * @return string
	 */
	public static function user_agent_summary(): string {
		$raw = isset( $_SERVER['HTTP_USER_AGENT'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$raw = \trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		return \strlen( $raw ) > self::UA_MAX ? \substr( $raw, 0, self::UA_MAX - 1 ) . '…' : $raw;
	}
}
