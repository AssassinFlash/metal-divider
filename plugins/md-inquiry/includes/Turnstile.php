<?php
/**
 * The Turnstile server-side verification.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * Verifies a Turnstile token with Cloudflare's siteverify endpoint (spec 07
 * §8, branch research/spec-inquiry-chain). Fail-closed: an unreachable
 * endpoint, a broken answer, an error code, or a success whose hostname is
 * not exactly metal-divider.com is a failure, never a pass — a B2B audience
 * tolerates a refresh better than the inbox tolerates spam.
 *
 * Each call sends a fresh idempotency key. Cloudflare documents that key as
 * a handle for retrying the same siteverify request and receiving the same
 * answer, not as a replay lock (server-side validation, fetched 2026-10-28).
 * A token is single-use on its own: validating it again answers
 * timeout-or-duplicate whether or not this call sends a new key. This
 * method does not retry.
 */
final class Turnstile {

	/**
	 * Cloudflare's siteverify endpoint.
	 */
	public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * The request timeout: short, because the customer is waiting.
	 */
	public const TIMEOUT = 8;

	/**
	 * The hostname siteverify must report, exactly.
	 */
	public const EXPECTED_HOSTNAME = 'metal-divider.com';

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Sets up the verifier.
	 *
	 * @param Config $config The configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Whether the two keys are in place: the switch between the fail-closed
	 * regime and the marked-but-open one.
	 */
	public function is_configured(): bool {
		return $this->config->turnstile_is_configured();
	}

	/**
	 * Verifies one token. Only meaningful when configured — the caller decides
	 * the regime; this method answers the verification alone.
	 *
	 * A success flag is not enough. The hostname must be exactly
	 * metal-divider.com; a missing field or any other value fails closed.
	 *
	 * @param string $token The widget's token.
	 * @param string $ip    The submitter's IP, when known.
	 * @return bool True only when siteverify says success for this host.
	 */
	public function verify( string $token, string $ip ): bool {
		if ( '' === $token || ! $this->is_configured() ) {
			return false;
		}
		$body = array(
			'secret'   => $this->config->turnstile_secret(),
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}
		$body['idempotency_key'] = (string) \wp_generate_uuid4();

		$response = \wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);
		if ( \is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}
		$answer = \json_decode( (string) \wp_remote_retrieve_body( $response ), true );
		return \is_array( $answer )
			&& true === ( $answer['success'] ?? false )
			&& self::EXPECTED_HOSTNAME === ( $answer['hostname'] ?? null );
	}
}
