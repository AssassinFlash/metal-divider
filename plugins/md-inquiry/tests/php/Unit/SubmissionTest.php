<?php
/**
 * Tests for the inquiry form's POST handler.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\FormState;
use MetalDivider\Inquiry\Mail\Emails;
use MetalDivider\Inquiry\Mail\Queue;
use MetalDivider\Inquiry\Pattern;
use MetalDivider\Inquiry\RateLimiter;
use MetalDivider\Inquiry\Submission;
use MetalDivider\Inquiry\Tests\Support\TheRedirect;
use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\Turnstile;
use MetalDivider\Inquiry\Validator;
use WP_Mock;

/**
 * The chain's order, gate by gate: rate limit, nonce, honeypot, validation,
 * Turnstile, the record, the queue, the 303 — nothing stored and nothing
 * sent until every gate before it has passed (spec 07 §7).
 *
 * @covers \MetalDivider\Inquiry\Submission
 */
final class SubmissionTest extends TestCase {

	/**
	 * The shared state and the redirect the success path throws instead of
	 * reaching the exit.
	 *
	 * @var string
	 */
	private const REDIRECT = 'the-303-redirect';


	/**
	 * Sets the shared mocks up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_passthroughs();
		$this->mock_is_email();
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
	}

	/**
	 * Housekeeping: the superglobals the tests shaped go back.
	 */
	public function tearDown(): void {
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tearDown();
	}

	/**
	 * The happy chain: every gate passes, the record is stored with its
	 * fields and the ACF references, both mails are queued with their true
	 * routing, and the 303 goes to the thank-you URL.
	 */
	public function test_the_happy_chain_stores_records_and_queues_both_mails(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->mock_pattern_resolves();
		$this->mock_record_store();
		$this->mock_thank_you_url();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );

		$db                    = $this->fake_db();
		$db->returns['insert'] = 1;
		$submission            = $this->submission(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
					'MD_INQUIRY_TURNSTILE_SECRET'  => '',
				)
			),
			$db
		);

		$_POST = array(
			'md_inquiry_action'        => 'submit',
			'md_inquiry_nonce'         => 'the-nonce',
			'website'                  => '',
			'md_inquiry_name'          => 'Jane Chan',
			'md_inquiry_email'         => 'jane@studio.com',
			'md_inquiry_company'       => 'Chan Design Studio',
			'md_inquiry_country'       => 'Hong Kong',
			'md_inquiry_scope'         => 'Single zone',
			'md_inquiry_route'         => 'Facade screening',
			'md_inquiry_quantity'      => '10-50 sqm',
			'md_inquiry_confirm'       => '1',
			'md_inquiry_message'       => 'Quantity: 40 panels.',
			'md_inquiry_pattern_slug'  => 'apiary',
			'md_inquiry_source_page'   => 'pattern-pdp',
			'md_inquiry_source_module' => 'hero',
			'md_inquiry_source_button' => 'request_quote',
			'md_inquiry_locale'        => 'en',
		);

		try {
			$submission->handle();
			$this->fail( 'the success path must end in the 303' );
		} catch ( TheRedirect $redirect ) {
			$this->assertSame( self::REDIRECT, $redirect->getMessage() );
		}

		$this->assertSame( 2, \count( $db->calls_of( 'insert' ) ), 'both mails are queued: the internal notification and the customer confirmation' );
		$internal     = \json_decode( (string) $db->calls_of( 'insert' )[0][1]['recipients'], true );
		$confirmation = \json_decode( (string) $db->calls_of( 'insert' )[1][1]['recipients'], true );
		$this->assertSame( array( 'sales@sunriverinverter.com', 'info@metal-divider.com' ), $internal, 'the internal routing is the queue data, per the mail discipline' );
		$this->assertSame( array( 'jane@studio.com' ), $confirmation );
		$this->assertSame( 'internal_notification', $db->calls_of( 'insert' )[0][1]['mail_type'] );
		$this->assertSame( 'customer_confirmation', $db->calls_of( 'insert' )[1][1]['mail_type'] );
		$this->assertSame( 42, $db->calls_of( 'insert' )[0][1]['inquiry_id'] );
	}

	/**
	 * The record's shape: the "name — company — date" title, the md_new
	 * status, the pattern snapshots, the verification mark of the
	 * unconfigured regime.
	 */
	public function test_the_record_carries_the_spec_shape(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->mock_pattern_resolves();
		$this->mock_thank_you_url();
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturn( '' );

		$inserted = array();
		WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturnUsing(
			static function ( array $args ) use ( &$inserted ): int {
				$inserted = $args;
				return 42;
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		$meta = array();
		WP_Mock::userFunction( 'update_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key, mixed $value ) use ( &$meta ): bool {
				$meta[ $key ] = $value;
				return true;
			}
		);
		$db                    = $this->fake_db();
		$db->returns['insert'] = 1;
		$submission            = $this->submission(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
					'MD_INQUIRY_TURNSTILE_SECRET'  => '',
				)
			),
			$db
		);

		$_POST = array(
			'md_inquiry_action'       => 'submit',
			'md_inquiry_nonce'        => 'the-nonce',
			'md_inquiry_name'         => 'Jane Chan',
			'md_inquiry_email'        => 'jane@studio.com',
			'md_inquiry_company'      => 'Chan Design Studio',
			'md_inquiry_country'      => 'Hong Kong',
			'md_inquiry_scope'        => 'Single zone',
			'md_inquiry_route'        => 'Facade screening',
			'md_inquiry_quantity'     => '10-50 sqm',
			'md_inquiry_confirm'      => '1',
			'md_inquiry_message'      => '',
			'md_inquiry_pattern_slug' => 'apiary',
			'md_inquiry_locale'       => 'en',
		);

		try {
			$submission->handle();
		} catch ( TheRedirect $redirect ) {
			unset( $redirect );
		}

		$this->assertSame( 'md_inquiry', $inserted['post_type'] );
		$this->assertSame( 'md_new', $inserted['post_status'] );
		$this->assertSame( 'Jane Chan — Chan Design Studio — ' . \gmdate( 'Y-m-d' ), $inserted['post_title'] );
		$this->assertSame( 'Apiary', $meta['pattern_name'], 'the snapshot, not the client\'s text' );
		$this->assertSame( 'APIARY', $meta['pattern_code'] );
		$this->assertSame( 42, $meta['pattern_product'] );
		$this->assertSame( 'not_configured', $meta['turnstile_status'], 'the unconfigured regime marks, never blocks' );
		$this->assertSame( '203.0.113.7', $meta['ip_address'] );
		$this->assertSame( 'TestAgent/1.0', $meta['user_agent'] );
		$this->assertSame( 'en', $meta['inquiry_locale'] );
		$this->assertSame( 'field_md_inquiry_customer_name', $meta['_customer_name'], 'the ACF reference beside every value' );
	}

	/**
	 * A spent rate window refuses first: nothing behind the gate is reached.
	 */
	public function test_a_spent_rate_window_refuses_first(): void {
		$now = \time();
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( array( $now - 10, $now - 20, $now - 30, $now - 40, $now - 50 ) );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
		$this->refused_with( 'rate_limited', array( 'md_inquiry_action' => 'submit' ) );
	}

	/**
	 * A bad nonce refuses before the honeypot or any field is read.
	 */
	public function test_a_bad_nonce_refuses(): void {
		$this->mock_rate_window_allows();
		WP_Mock::userFunction( 'wp_verify_nonce' )->once()->andReturn( false );
		$this->refused_with(
			'nonce',
			array(
				'md_inquiry_action' => 'submit',
				'md_inquiry_nonce'  => 'wrong',
			)
		);
	}

	/**
	 * A filled honeypot refuses with the generic message: a bot learns
	 * nothing about which gate caught it.
	 */
	public function test_a_filled_honeypot_refuses(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->refused_with(
			'spam',
			array(
				'md_inquiry_action' => 'submit',
				'md_inquiry_nonce'  => 'the-nonce',
				'website'           => 'http://spam.example',
			)
		);
	}

	/**
	 * A validation error refuses with the fields kept for the retry.
	 */
	public function test_a_validation_error_refuses_with_the_values_kept(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->refused_with(
			'validation',
			array(
				'md_inquiry_action' => 'submit',
				'md_inquiry_nonce'  => 'the-nonce',
				'md_inquiry_email'  => 'jane@studio.com',
			)
		);
	}

	/**
	 * A configured Turnstile that fails refuses the submission: no record, no
	 * mail — fail-closed, the spec's one regime with no fallback.
	 */
	public function test_a_failed_turnstile_refuses_without_storing(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->mock_pattern_resolves();
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( 'uuid' );
		WP_Mock::userFunction( 'wp_remote_post' )->once()->andReturn( array( 'body' => '{"success":false}' ) );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( '{"success":false}' );
		WP_Mock::userFunction( 'wp_insert_post' )->never();

		$db         = $this->fake_db();
		$submission = $this->submission(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => 'site',
					'MD_INQUIRY_TURNSTILE_SECRET'  => 'secret',
				)
			),
			$db
		);

		$_POST = array(
			'md_inquiry_action'       => 'submit',
			'md_inquiry_nonce'        => 'the-nonce',
			'md_inquiry_name'         => 'Jane Chan',
			'md_inquiry_email'        => 'jane@studio.com',
			'md_inquiry_country'      => 'Hong Kong',
			'md_inquiry_scope'        => 'Single zone',
			'md_inquiry_route'        => 'Facade screening',
			'md_inquiry_quantity'     => '10-50 sqm',
			'md_inquiry_confirm'      => '1',
			'md_inquiry_pattern_slug' => 'apiary',
			'cf-turnstile-response'   => 'a-token',
		);
		$submission->handle();

		$this->assertSame( 'turnstile', $this->state_of( $submission )->notice_key() );
		$this->assertSame( array(), $db->calls_of( 'insert' ), 'nothing is sent' );
	}

	/**
	 * A record that cannot be stored refuses the whole submission — no mail
	 * without its record, ever.
	 */
	public function test_a_storage_failure_sends_nothing(): void {
		$this->mock_rate_window_allows();
		$this->mock_nonce_valid();
		$this->mock_pattern_resolves();
		WP_Mock::userFunction( 'admin_url' )->zeroOrMoreTimes()->andReturn( 'https://metal-divider.com/wp-admin/' );
		// wp_insert_post with $wp_error true answers an error object, never a
		// bare 0 — that is the shape the handler trusts.
		WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturn( new \WP_Error( 'db_error', 'Could not insert post into the database.' ) );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )->never();
		WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$db         = $this->fake_db();
		$submission = $this->submission(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
					'MD_INQUIRY_TURNSTILE_SECRET'  => '',
				)
			),
			$db
		);

		$_POST = array(
			'md_inquiry_action'   => 'submit',
			'md_inquiry_nonce'    => 'the-nonce',
			'md_inquiry_name'     => 'Jane Chan',
			'md_inquiry_email'    => 'jane@studio.com',
			'md_inquiry_country'  => 'Hong Kong',
			'md_inquiry_scope'    => 'Single zone',
			'md_inquiry_route'    => 'Facade screening',
			'md_inquiry_quantity' => '10-50 sqm',
			'md_inquiry_confirm'  => '1',
			'md_inquiry_message'  => 'Quantity: 40 panels.',
		);
		$submission->handle();

		$this->assertSame( 'storage', $this->state_of( $submission )->notice_key() );
		$this->assertSame( array(), $db->calls_of( 'insert' ) );
	}

	/**
	 * A request that is not the form's POST passes through untouched: no gate
	 * is even asked.
	 */
	public function test_other_requests_pass_through(): void {
		WP_Mock::userFunction( 'get_transient' )->never();

		$state      = new FormState();
		$config     = new Config(
			array(
				'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
				'MD_INQUIRY_TURNSTILE_SECRET'  => '',
			)
		);
		$submission = new Submission(
			new Validator(),
			new Turnstile( $config ),
			new Pattern(),
			new Queue( $config ),
			new Emails( $config ),
			$state
		);

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$submission->handle();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'md_inquiry_action' => 'something-else' );
		$submission->handle();

		$this->assertFalse( $state->has_failed(), 'a passing-through request never fails the form' );
	}

	/**
	 * Builds the handler under test around a shared state.
	 *
	 * @param Config                                       $config The configuration.
	 * @param \MetalDivider\Inquiry\Tests\Support\FakeWpdb $db The queue's double.
	 * @return Submission
	 */
	private function submission( Config $config, \MetalDivider\Inquiry\Tests\Support\FakeWpdb $db ): Submission {
		$state                                    = new FormState();
		$this->states[ \spl_object_id( $state ) ] = $state;
		return new Submission(
			new Validator(),
			new Turnstile( $config ),
			new Pattern(),
			new Queue( $config ),
			new Emails( $config ),
			$state
		);
	}

	/**
	 * The states the submissions were built around, by object id.
	 *
	 * @var array<int, FormState>
	 */
	private array $states = array();

	/**
	 * The state the handler was built with — the one and only, in these tests.
	 *
	 * @param Submission $submission The handler.
	 * @return FormState
	 */
	private function state_of( Submission $submission ): FormState {
		unset( $submission );
		$this->assertNotEmpty( $this->states );
		return \end( $this->states );
	}

	/**
	 * Asserts that this POST is refused with the given notice key, and that
	 * nothing was stored.
	 *
	 * @param string                $expected The notice key.
	 * @param array<string, string> $post     The POST body.
	 * @return void
	 */
	private function refused_with( string $expected, array $post ): void {
		WP_Mock::userFunction( 'wp_insert_post' )->never();
		WP_Mock::userFunction( 'wp_safe_redirect' )->never();

		$db                    = $this->fake_db();
		$db->returns['insert'] = 1;
		$submission            = $this->submission(
			new Config(
				array(
					'MD_INQUIRY_TURNSTILE_SITEKEY' => '',
					'MD_INQUIRY_TURNSTILE_SECRET'  => '',
				)
			),
			$db
		);
		$_POST                 = $post;
		$submission->handle();

		$this->assertSame( $expected, $this->state_of( $submission )->notice_key() );
		$this->assertSame( array(), $db->calls_of( 'insert' ) );
	}

	/**
	 * The rate window mocks: an empty window that allows.
	 *
	 * @return void
	 */
	private function mock_rate_window_allows(): void {
		WP_Mock::userFunction( 'get_transient' )->once()->andReturn( array() );
		WP_Mock::userFunction( 'set_transient' )->once()->andReturn( true );
	}

	/**
	 * The nonce is valid.
	 *
	 * @return void
	 */
	private function mock_nonce_valid(): void {
		WP_Mock::userFunction( 'wp_verify_nonce' )->once()->with( 'the-nonce', Submission::ACTION )->andReturn( 1 );
	}

	/**
	 * The pattern resolves server-side: the client's slug becomes the
	 * product's own title and SKU.
	 *
	 * @return void
	 */
	private function mock_pattern_resolves(): void {
		$product            = new \stdClass();
		$product->ID        = 42;
		$product->post_type = 'product';
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $product ) );
		WP_Mock::userFunction( 'get_the_title' )->andReturn( 'Apiary' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ): string {
				return '_sku' === $key ? 'APIARY' : '';
			}
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/apiary' );
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( '' );
	}

	/**
	 * The record stores: id 42, meta accepted, no error from the insert.
	 *
	 * @return void
	 */
	private function mock_record_store(): void {
		WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturn( 42 );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'update_post_meta' )->zeroOrMoreTimes()->andReturn( true );
	}

	/**
	 * The 303: the redirect throws before the exit, so the chain under it is
	 * assertable.
	 *
	 * @return void
	 */
	private function mock_thank_you_url(): void {
		WP_Mock::userFunction( 'get_page_by_path' )->andReturn( null );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://metal-divider.com/inquiry/thank-you/' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static function ( array $args, string $url ): string {
				return $url . '?md_inquiry=' . $args['md_inquiry'] . '&key=' . $args['key'];
			}
		);
		WP_Mock::userFunction( 'wp_hash' )->andReturn( 'the-key' );
		WP_Mock::userFunction( 'admin_url' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $path ): string {
				return 'https://metal-divider.com/wp-admin/' . $path;
			}
		);
		WP_Mock::userFunction( 'wp_safe_redirect' )->once()->andReturnUsing(
			static function ( string $url, int $status ): never {
				if ( 'https://metal-divider.com/inquiry/thank-you/?md_inquiry=42&key=the-key' === $url && 303 === $status ) {
					throw new TheRedirect( self::REDIRECT );
				}
				throw new TheRedirect( 'unexpected redirect: ' . $url . ' ' . $status );
			}
		);
	}
}
