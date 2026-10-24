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
 * endpoint, a broken answer or an error code is a failure, never a pass —
 * a B2B audience tolerates a refresh better than the inbox tolerates spam.
 * The idempotency key makes a replayed attempt answer timeout-or-duplicate
 * on Cloudflare's side, the same answer a used token earns.
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
	 * @param string $token The widget's token.
	 * @param string $ip    The submitter's IP, when known.
	 * @return bool True only when siteverify says success.
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
		return \is_array( $answer ) && ( $answer['success'] ?? false ) === true;
	}
}
