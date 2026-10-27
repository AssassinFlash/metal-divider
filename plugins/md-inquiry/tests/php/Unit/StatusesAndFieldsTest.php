<?php
/**
 * Tests for the inquiry record's workflow statuses and field group.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Unit;

use MetalDivider\Inquiry\Fields\FieldGroup;
use MetalDivider\Inquiry\Statuses;
use MetalDivider\Inquiry\Tests\TestCase;
use WP_Mock;

/**
 * The workflow statuses and the record's field group.
 *
 * @covers \MetalDivider\Inquiry\Statuses
 * @covers \MetalDivider\Inquiry\Fields\FieldGroup
 */
final class StatusesAndFieldsTest extends TestCase {

	/**
	 * The three workflow statuses register on init, admin-visible, each with
	 * its label.
	 */
	public function test_the_three_statuses_register_admin_visible(): void {
		$this->mock_passthroughs();
		$statuses = new Statuses();

		WP_Mock::expectActionAdded( 'init', array( $statuses, 'register_statuses' ) );
		$registered = array();
		WP_Mock::userFunction( 'register_post_status' )->times( 3 )->andReturnUsing(
			static function ( string $name, array $args ) use ( &$registered ): void {
				$registered[ $name ] = $args;
			}
		);
		$statuses->register();
		$statuses->register_statuses();

		$this->assertSame( array( Statuses::NEW, Statuses::CONTACTED, Statuses::CLOSED ), \array_keys( $registered ) );
		foreach ( $registered as $args ) {
			$this->assertFalse( $args['public'], 'a workflow status is never public' );
			$this->assertTrue( $args['show_in_admin_all_list'], 'records with it stay in the All view' );
			$this->assertTrue( $args['show_in_admin_status_list'], 'the list can filter by it' );
		}
		$this->assertSame( 'New', Statuses::label( Statuses::NEW ) );
		$this->assertSame( 'Contacted', Statuses::label( Statuses::CONTACTED ) );
		$this->assertSame( 'Closed', Statuses::label( Statuses::CLOSED ) );
		$this->assertSame( '', Statuses::label( 'publish' ), 'an unknown status has no label' );
		$this->assertTrue( Statuses::is_valid( Statuses::NEW ) );
		$this->assertFalse( Statuses::is_valid( 'publish' ) );
	}

	/**
	 * The field group definition: the record's fifteen fields, on the
	 * md_inquiry post type, with the field keys the submission handler writes
	 * as ACF references.
	 */
	public function test_the_field_group_carries_the_records_fields(): void {
		$group = FieldGroup::group();

		$this->assertSame( 'group_md_inquiry_record', $group['key'] );
		$this->assertSame( 'md_inquiry', $group['location'][0][0]['value'], 'the group lives on the inquiry record' );
		$this->assertSame( 0, $group['show_in_rest'], 'no REST surface of its own' );

		$names = array();
		foreach ( $group['fields'] as $field ) {
			$names[] = $field['name'];
		}
		$this->assertSame(
			array(
				'submitted_time',
				'customer_name',
				'customer_email',
				'customer_company',
				'customer_phone',
				'customer_country',
				'project_scope',
				'inquiry_route',
				'message',
				'pattern_product',
				'pattern_name',
				'pattern_code',
				'pattern_slug',
				'source_page',
				'source_module',
				'source_button',
				'ip_address',
				'user_agent',
				'turnstile_status',
			),
			$names,
			'the ticket\'s field list, in record order — the pattern list fields are gone (ticket #7 revision)'
		);

		$keys = FieldGroup::field_keys();
		$this->assertSame( $names, \array_keys( $keys ), 'every field name maps to its key' );
		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( 'field_md_inquiry_', $key );
		}
	}
}
