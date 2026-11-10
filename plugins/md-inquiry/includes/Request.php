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
	 * The submitter's IP address, as the transport reports it: REMOTE_ADDR,
	 * never a request header. The empty string when it is absent or not an IP.
	 *
	 * The site is served by LiteSpeed on Hostinger with no CDN in front of it
	 * (AGENTS.md §2, DNS ruling 2026-10-25), so REMOTE_ADDR is the only address
	 * the connection actually carries. HTTP_CF_CONNECTING_IP is a header the
	 * client can send freely; trusting it here would let one visitor present a
	 * new address on every request and walk through RateLimiter's window.
	 * mu-plugins/md-kb-endpoint.php reads the same fact the same way.
	 *
	 * @return string
	 */
	public static function ip(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $raw && false !== \filter_var( $raw, \FILTER_VALIDATE_IP ) ) {
			return $raw;
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
