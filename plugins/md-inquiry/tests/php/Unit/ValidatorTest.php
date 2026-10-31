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
	/**
	 * A value outside a select's list is rejected, not stored.
	 *
	 * The three new selects (country / scope / route) each carry a fixed list
	 * in the validator, and the form prints its options from the same
	 * constant. A hand-posted value the list does not contain must not reach
	 * the record: the field is the contract, so an unknown value reads as
	 * "nothing chosen" and the required-check reports it — the same shape the
	 * source tokens already use.
	 */
	public function test_a_value_outside_a_select_list_is_rejected(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'          => 'Jane Chan',
				'email'         => 'jane@studio.com',
				'message'       => 'A brief.',
				'country'       => 'Atlantis',
				'scope'         => 'Single zone',
				'route'         => 'Facade screening',
				'quantity'      => '10-50 sqm',
				'source_page'   => 'inquiry',
				'source_module' => 'page',
				'source_button' => 'submit',
				'locale'        => 'en',
			),
			false
		);

		$this->assertSame( '', $checked['values']['country'], 'a value outside the list becomes nothing' );
		$this->assertArrayHasKey( 'country', $checked['errors'], 'and the field then fails its required check' );
		$this->assertSame( 'Single zone', $checked['values']['scope'], 'a value in the list passes through' );
	}

	/**
	 * The qualification selects are optional: a brief with only name, email
	 * and message passes without error when the customer skips the optional
	 * project details.
	 */
	public function test_the_selects_are_optional(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'    => 'Jane Chan',
				'email'   => 'jane@studio.com',
				'message' => 'A brief.',
			),
			false
		);

		$this->assertSame( array(), $checked['errors'] );
		foreach ( array( 'country', 'scope', 'route', 'quantity' ) as $field ) {
			$this->assertSame( '', $checked['values'][ $field ] );
		}
	}

	/**
	 * A complete submission whose pattern carries the intent passes, its
	 * message empty because the pattern itself says which screen is wanted.
	 */
	public function test_a_complete_submission_with_a_pattern_passes(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'          => '  Jane Chan  ',
				'email'         => ' jane@studio.com ',
				'company'       => 'Chan Design Studio',
				'country'       => 'Hong Kong',
				'scope'         => 'Single zone',
				'route'         => 'Facade screening',
				'quantity'      => '10-50 sqm',
				'confirm'       => '1',
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
	 * The confirmation checkbox is optional: an unchecked box posts nothing
	 * and does not block submission, while a non-"1" value is normalized to ''.
	 */
	public function test_confirmation_is_optional(): void {
		$checked = ( new Validator() )->validate(
			array(
				'name'     => 'Jane Chan',
				'email'    => 'jane@studio.com',
				'country'  => 'Hong Kong',
				'scope'    => 'Single zone',
				'route'    => 'Facade screening',
				'quantity' => '10-50 sqm',
				'message'  => 'A brief.',
				'confirm'  => '',
			),
			true
		);

		$this->assertArrayNotHasKey( 'confirm', $checked['errors'] );
		$this->assertSame( '', $checked['values']['confirm'] );

		$tampered = ( new Validator() )->validate(
			array(
				'name'     => 'Jane Chan',
				'email'    => 'jane@studio.com',
				'country'  => 'Hong Kong',
				'scope'    => 'Single zone',
				'route'    => 'Facade screening',
				'quantity' => '10-50 sqm',
				'message'  => 'A brief.',
				'confirm'  => 'on',
			),
			true
		);
		$this->assertArrayNotHasKey( 'confirm', $tampered['errors'] );
		$this->assertSame( '', $tampered['values']['confirm'] );
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
