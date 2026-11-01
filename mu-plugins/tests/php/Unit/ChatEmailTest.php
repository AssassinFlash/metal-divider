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
		self::assertContains(
			array(
				'md/v1',
				'/chat/context',
				array(
					'methods'             => 'POST',
					'callback'            => 'md_chat_context_respond',
					'permission_callback' => '__return_true',
				),
			),
			$GLOBALS['md_mu_test_routes'] ?? array()
		);
	}

	/**
	 * Resets the bootstrap's stand-in state for the respond-path tests, so
	 * no test sees another's transients or database answers.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['md_mu_test_transients']   = array();
		$GLOBALS['md_mu_test_db_responder'] = null;
		$GLOBALS['wpdb']->queries           = array();
		$_SERVER['REMOTE_ADDR']             = '192.0.2.20';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Puts the request environment back the way the runner had it.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tearDown();
	}

	/**
	 * One request to the route, with the body the browser sends.
	 *
	 * @param int    $chat_id  The chat's id.
	 * @param string $hash     The chat's hash.
	 * @param string $email    The email to store.
	 * @param string $page_url Optional current page URL.
	 * @return \WP_REST_Response The response.
	 */
	private function email_request( int $chat_id, string $hash, string $email = 'visitor@example.com', string $page_url = '' ): \WP_REST_Response {
		$body = array(
			'chat_id' => $chat_id,
			'hash'    => $hash,
			'email'   => $email,
		);
		if ( '' !== $page_url ) {
			$body['page_url'] = $page_url;
		}
		/**
		 * The suite's WP_REST_Request double takes the decoded body as its
		 * first parameter; WordPress's own class takes a method string
		 * there. The double is what runs, so the exception is pinned to
		 * this line.
		 *
		 * @phpstan-ignore-next-line
		 */
		$request = new \WP_REST_Request( $body );
		return md_chat_email_respond( $request );
	}

	/**
	 * One request to the /chat/context route.
	 *
	 * @param int    $chat_id  The chat's id.
	 * @param string $hash     The chat's hash.
	 * @param string $page_url The page URL to store.
	 * @return \WP_REST_Response The response.
	 */
	private function context_request( int $chat_id, string $hash, string $page_url ): \WP_REST_Response {
		$body = array(
			'chat_id'  => $chat_id,
			'hash'     => $hash,
			'page_url' => $page_url,
		);
		/**
		 * The suite's WP_REST_Request double takes the decoded body as its
		 * first parameter; WordPress's own class takes a method string
		 * there. The double is what runs, so the exception is pinned to
		 * this line.
		 *
		 * @phpstan-ignore-next-line
		 */
		$request = new \WP_REST_Request( $body );
		return md_chat_context_respond( $request );
	}

	/**
	 * Answers every row query with the one chat row given; null leaves the
	 * chat missing.
	 *
	 * @param array<string, mixed>|null $row The chat row, null for no chat.
	 * @return void
	 */
	private function answer_row_with( ?array $row ): void {
		$GLOBALS['md_mu_test_db_responder'] = static fn (): ?array => $row;
	}

	/**
	 * The stored hash shape the capability check accepts.
	 *
	 * @return string A 40-character hash.
	 */
	private function stored_hash(): string {
		return 'dryymNnc0foLr9Y9OWbTI5oqH5nX3NROttOCfyUQ';
	}

	/**
	 * Ten unknown chat ids from one address lock the eleventh request out
	 * before the chat row is even read — the database sees ten queries for
	 * eleven requests.
	 */
	public function test_locks_the_address_out_after_ten_unknown_chat_ids(): void {
		$this->answer_row_with( null );

		for ( $i = 0; $i < 10; ++$i ) {
			self::assertSame( 404, $this->email_request( 100 + $i, $this->stored_hash() )->status );
		}

		$queries_before = count( $GLOBALS['wpdb']->queries );
		$eleventh       = $this->email_request( 999, $this->stored_hash() );
		self::assertSame( 429, $eleventh->status );
		self::assertSame( 'Too many attempts. Please try again in a minute.', $eleventh->data['message'] );
		self::assertCount( $queries_before, $GLOBALS['wpdb']->queries );
	}

	/**
	 * A wrong hash is a guess at the capability: it answers 403 and leaves
	 * a failure in the address's window.
	 */
	public function test_a_wrong_hash_is_recorded_as_a_guess(): void {
		$this->answer_row_with(
			array(
				'id'         => 7,
				'hash'       => $this->stored_hash(),
				'status'     => 0,
				'anonymized' => 0,
			)
		);

		self::assertSame( 403, $this->email_request( 7, str_repeat( 'x', 40 ) )->status );

		$failures = $GLOBALS['md_mu_test_transients'][ md_chat_email_failure_key() ] ?? null;
		self::assertIsArray( $failures );
		self::assertCount( 1, $failures );
	}

	/**
	 * A closed chat answered 409 has produced its hash already: it is not a
	 * guess, and the address's failure window stays empty.
	 */
	public function test_a_closed_chat_is_not_a_guess(): void {
		$this->answer_row_with(
			array(
				'id'         => 7,
				'hash'       => $this->stored_hash(),
				'status'     => 2,
				'anonymized' => 0,
			)
		);

		self::assertSame( 409, $this->email_request( 7, $this->stored_hash() )->status );
		self::assertArrayNotHasKey( md_chat_email_failure_key(), $GLOBALS['md_mu_test_transients'] );
	}

	/**
	 * The failure window is per address: ten failures from one address do
	 * not lock a second one out, while the first stays locked.
	 */
	public function test_the_failure_window_is_per_address(): void {
		$closed = array(
			'id'         => 7,
			'hash'       => $this->stored_hash(),
			'status'     => 2,
			'anonymized' => 0,
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->answer_row_with( null );
		for ( $i = 0; $i < 10; ++$i ) {
			self::assertSame( 404, $this->email_request( 200 + $i, $this->stored_hash() )->status );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->answer_row_with( $closed );
		self::assertSame( 409, $this->email_request( 7, $this->stored_hash() )->status );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		self::assertSame( 429, $this->email_request( 7, $this->stored_hash() )->status );
	}

	/**
	 * The window's key is a digest, not the address: reading the store
	 * cannot learn who asked.
	 */
	public function test_the_failure_key_holds_no_address(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		self::assertSame( 1, preg_match( '/^md_chat_email_failures_[0-9a-f]{64}$/', md_chat_email_failure_key() ) );
		self::assertStringNotContainsString( '203.0.113.9', md_chat_email_failure_key() );
	}

	/**
	 * Accepts only http/https URLs on the site's own hosts or local loopback,
	 * and extracts the pattern slug when the path points at /pattern/<slug>/.
	 */
	public function test_sanitizes_page_url_and_extracts_pattern_slug(): void {
		self::assertSame(
			'https://metal-divider.com/pattern/breeze/',
			md_chat_email_sanitize_page_url( '  https://metal-divider.com/pattern/breeze/  ' )
		);
		self::assertSame(
			'https://staging.metal-divider.com/collections/geometric/',
			md_chat_email_sanitize_page_url( 'https://staging.metal-divider.com/collections/geometric/' )
		);
		self::assertSame(
			'http://127.0.0.1:8080/pattern/breeze/',
			md_chat_email_sanitize_page_url( 'http://127.0.0.1:8080/pattern/breeze/' )
		);
		self::assertSame(
			'https://metal-divider.com/pattern/breeze/',
			md_chat_email_sanitize_page_url( '//metal-divider.com/pattern/breeze/' )
		);
		self::assertSame( '', md_chat_email_sanitize_page_url( 'https://evil.example.com/pattern/breeze/' ) );
		self::assertSame( '', md_chat_email_sanitize_page_url( 'javascript:alert(1)' ) );
		self::assertSame( '', md_chat_email_sanitize_page_url( 'https://user:pass@metal-divider.com/' ) );
		self::assertSame( '', md_chat_email_sanitize_page_url( '' ) );
		self::assertSame( '', md_chat_email_sanitize_page_url( null ) );

		self::assertSame( 'breeze', md_chat_email_pattern_slug( 'https://metal-divider.com/pattern/breeze/' ) );
		self::assertSame( '', md_chat_email_pattern_slug( 'https://metal-divider.com/collections/geometric/' ) );
	}

	/**
	 * Stores the email (and optional page_url) on lh_chat when the hash matches.
	 */
	public function test_stores_email_and_optional_page_url_on_lh_chat(): void {
		$this->answer_row_with(
			array(
				'id'         => 11,
				'hash'       => $this->stored_hash(),
				'status'     => 5,
				'anonymized' => 0,
			)
		);

		$response = $this->email_request( 11, $this->stored_hash(), 'architect@example.com' );
		self::assertSame( 200, $response->status );
		self::assertSame(
			array(
				'ok'          => true,
				'email_saved' => true,
			),
			$response->data
		);
		self::assertContains( 'UPDATE lh_chat SET email WHERE id', $GLOBALS['wpdb']->queries );

		$GLOBALS['md_mu_test_transients'] = array();
		$GLOBALS['wpdb']->queries         = array();

		$with_url = $this->email_request( 11, $this->stored_hash(), 'architect@example.com', 'https://metal-divider.com/pattern/breeze/' );
		self::assertSame( 200, $with_url->status );
		self::assertContains( 'UPDATE lh_chat SET email,referrer WHERE id', $GLOBALS['wpdb']->queries );
	}

	/**
	 * Updates lh_chat.referrer through /chat/context when the capability hash
	 * and page_url are valid, and refuses invalid URLs or wrong hashes.
	 */
	public function test_updates_referrer_via_chat_context_route(): void {
		$this->answer_row_with(
			array(
				'id'         => 15,
				'hash'       => $this->stored_hash(),
				'status'     => 5,
				'anonymized' => 0,
			)
		);

		$bad_url = $this->context_request( 15, $this->stored_hash(), 'https://evil.example.com/pattern/breeze/' );
		self::assertSame( 400, $bad_url->status );

		$wrong_hash = $this->context_request( 15, str_repeat( 'x', 40 ), 'https://metal-divider.com/pattern/breeze/' );
		self::assertSame( 403, $wrong_hash->status );

		$saved = $this->context_request( 15, $this->stored_hash(), 'https://metal-divider.com/pattern/breeze/' );
		self::assertSame( 200, $saved->status );
		self::assertSame(
			array(
				'ok'             => true,
				'referrer_saved' => true,
			),
			$saved->data
		);
		self::assertContains( 'UPDATE lh_chat SET referrer WHERE id', $GLOBALS['wpdb']->queries );
	}
}
