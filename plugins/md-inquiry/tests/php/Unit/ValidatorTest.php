<?php
/**
 * Tests for the inquiry form's field validation.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Tests\TestCase;
use MetalDivider\Inquiry\Validator;
use WP_Mock;

/**
 * The four fields and the plumbing values, against the spec's minimum set.
 *
 * @covers \MetalDivider\Inquiry\Validator
 */
final class ValidatorTest extends TestCase {

	/**
	 * Sets the mocks up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->mock_passthroughs();
		$this->mock_is_email();
	}

	/**
	 * A complete, pattern-attached submission passes untouched: the values
	 * come back trimmed, the errors come back empty, and the message may be
	 * empty because the pattern carries the intent.
	 */
	public function test_a_complete_submission_with_a_pattern_passes(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'          => '  Jane Chan  ',
				'email'         => ' jane@studio.com ',
				'company'       => 'Chan Design Studio',
				'message'       => '',
				'pattern_slug'  => 'apiary',
				'source_page'   => 'pattern-pdp',
				'source_module' => 'hero',
				'source_button' => 'request_quote',
				'locale'        => 'en',
			),
			true
		);

		$this->assertSame( 'Jane Chan', $checked['values']['name'] );
		$this->assertSame( 'jane@studio.com', $checked['values']['email'] );
		$this->assertSame( 'apiary', $checked['values']['pattern_slug'] );
		$this->assertSame( array(), $checked['errors'], 'the pattern carries the intent; an empty message is fine' );
	}

	/**
	 * Without a pattern the message becomes the required field: it is the
	 * only carrier of what the customer wants.
	 */
	public function test_without_a_pattern_the_message_is_required(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'    => 'Jane Chan',
				'email'   => 'jane@studio.com',
				'message' => '',
			),
			false
		);

		$this->assertArrayHasKey( 'message', $checked['errors'] );
		$this->assertArrayNotHasKey( 'name', $checked['errors'] );
	}

	/**
	 * Name and email are always required, and a broken email is an error even
	 * when the field is filled.
	 */
	public function test_missing_name_and_broken_email_are_errors(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'    => '',
				'email'   => 'not-an-email',
				'message' => 'Quantity: 40 panels',
			),
			true
		);

		$this->assertArrayHasKey( 'name', $checked['errors'] );
		$this->assertArrayHasKey( 'email', $checked['errors'] );
		$this->assertArrayNotHasKey( 'message', $checked['errors'] );
	}

	/**
	 * The length limits hold: a field past its limit is cut to it, never
	 * stored whole.
	 */
	public function test_overlong_fields_are_cut_to_their_limits(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'    => \str_repeat( 'a', Validator::NAME_MAX + 10 ),
				'email'   => 'jane@studio.com',
				'company' => \str_repeat( 'b', Validator::COMPANY_MAX + 10 ),
				'message' => \str_repeat( 'c', Validator::MESSAGE_MAX + 10 ),
			),
			true
		);

		$this->assertSame( Validator::NAME_MAX, \strlen( $checked['values']['name'] ) );
		$this->assertSame( Validator::COMPANY_MAX, \strlen( $checked['values']['company'] ) );
		$this->assertSame( Validator::MESSAGE_MAX, \strlen( $checked['values']['message'] ) );
	}

	/**
	 * Source tokens are the old site's lowercase-slug vocabulary: a valid one
	 * passes, an invalid one is normalized to the empty string — quietly, so
	 * a tampered parameter never blocks a real customer (spec 07 §3).
	 */
	public function test_source_tokens_are_whitelisted_by_shape(): void {
		$this->assertSame( 'pattern-pdp', Validator::source_token( ' pattern-pdp ' ) );
		$this->assertSame( 'list-drawer', Validator::source_token( 'list-drawer' ) );
		$this->assertSame( '', Validator::source_token( 'Pattern PDP' ) );
		$this->assertSame( '', Validator::source_token( 'javascript:alert(1)' ) );
		$this->assertSame( '', Validator::source_token( \str_repeat( 'x', 65 ) ) );
	}

	/**
	 * The locale is normalized the same quiet way: 'en' passes this phase,
	 * anything else is dropped rather than trusted.
	 */
	public function test_the_locale_is_whitelisted(): void {
		$this->assertSame( 'en', Validator::locale( 'en' ) );
		$this->assertSame( '', Validator::locale( 'de' ) );
		$this->assertSame( '', Validator::locale( '<script>' ) );
	}
}
