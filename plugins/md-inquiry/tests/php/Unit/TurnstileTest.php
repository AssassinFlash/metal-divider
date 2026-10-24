<?php
/**
 * Tests for the Turnstile server-side verification.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\Turnstile;
use WP_Mock;

/**
 * The siteverify call and its fail-closed answers.
 *
 * @covers \MetalDivider\Inquiry\Turnstile
 */
final class TurnstileTest extends TestCase {

	/**
	 * A configured verifier that answers with the given body and code.
	 *
	 * @param string $body The siteverify answer's body.
	 * @param int    $code The siteverify answer's code.
	 * @return Turnstile
	 */
	private function verifier_answering( string $body, int $code = 200 ): Turnstile {
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( 'uuid-1' );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturnUsing(
			static function ( string $url, array $args ) use ( $body, $code ): array {
				return array(
					'url'  => $url,
					'args' => $args,
					'code' => $code,
					'body' => $body,
				);
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static function ( mixed $value ): bool {
				return $value instanceof \WP_Error;
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $code );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		return new Turnstile(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			)
		);
	}

	/**
	 * A success answer passes, and the request carries every field the
	 * endpoint documents: secret, response, remoteip, idempotency key.
	 */
	public function test_a_success_answer_passes_with_the_documented_fields(): void {
		$captured = array();
		WP_Mock::userFunction( 'wp_generate_uuid4' )->once()->andReturn( 'uuid-1' );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturnUsing(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$body     = isset( $args['body'] ) && \is_array( $args['body'] ) ? $args['body'] : array();
				$captured = array( $url, (string) \json_encode( $body ) );
				return array( 'body' => '{"success":true}' );
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnFalse();
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"success":true}' );

		$turnstile = new Turnstile(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			)
		);
		$this->assertTrue( $turnstile->verify( 'a-token', '203.0.113.7' ) );

		$this->assertSame( Turnstile::VERIFY_URL, $captured[0] );
		$this->assertStringContainsString( '"secret":"secret"', $captured[1] );
		$this->assertStringContainsString( '"response":"a-token"', $captured[1] );
		$this->assertStringContainsString( '"remoteip":"203.0.113.7"', $captured[1] );
		$this->assertStringContainsString( '"idempotency_key":"uuid-1"', $captured[1] );
	}

	/**
	 * Fail-closed: every failure shape — the endpoint's own error codes, a
	 * transport error, a broken answer — reads as a failure. No path to
	 * "verified" exists except the endpoint's success.
	 */
	public function test_every_failure_shape_fails_closed(): void {
		$this->assertFalse( $this->verifier_answering( '{"success":false,"error-codes":["invalid-input-response"]}' )->verify( 'a-token', '203.0.113.7' ) );
		$this->assertFalse( $this->verifier_answering( '', 503 )->verify( 'a-token', '203.0.113.7' ) );
		$this->assertFalse( $this->verifier_answering( 'not json' )->verify( 'a-token', '203.0.113.7' ) );
	}

	/**
	 * A transport error fails like any other error: the verifier is never
	 * offline-tolerant.
	 */
	public function test_a_transport_error_fails_closed(): void {
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( 'uuid-1' );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'Connection refused' ) );
		WP_Mock::userFunction( 'is_wp_error' )->andReturnTrue();

		$turnstile = new Turnstile(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			)
		);
		$this->assertFalse( $turnstile->verify( 'a-token', '203.0.113.7' ) );
	}

	/**
	 * An empty token is a failure before any request is made, and an
	 * unconfigured verifier answers nothing else: the caller decides the
	 * regime.
	 */
	public function test_an_empty_token_never_reaches_the_endpoint(): void {
		WP_Mock::userFunction( 'wp_remote_post' )->never();
		$turnstile = new Turnstile(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			)
		);
		$this->assertFalse( $turnstile->verify( '', '203.0.113.7' ) );

		$this->assertFalse(
			( new Turnstile(
				new Config(
					array(
						'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
						'MD_INQUIRY_TURNSTILE_SECRET'  => '',
					)
				)
			) )->is_configured()
		);
	}
}
