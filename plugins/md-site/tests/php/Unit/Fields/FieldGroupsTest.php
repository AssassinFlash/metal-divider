<?php
/**
 * Tests for the pattern data model's ACF field groups.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Fields;

use MetalDivider\Site\Fields\FieldGroups;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for FieldGroups: the smoke of the registration path and the shape of
 * the four groups the pattern data model fixes (spec 06 §2.2/§4: 4 groups,
 * 17 fields, the provenance group out of REST, the series group on the
 * product_cat term).
 *
 * @covers \MetalDivider\Site\Fields\FieldGroups
 */
final class FieldGroupsTest extends TestCase {

	/**
	 * The groups by key, the way ACF indexes them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function groups_by_key(): array {
		$by_key = array();
		foreach ( FieldGroups::groups() as $group ) {
			$by_key[ (string) $group['key'] ] = $group;
		}
		return $by_key;
	}

	/**
	 * A group's fields, in order.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return list<array<string, mixed>>
	 */
	private static function fields_of( array $group ): array {
		$fields = array();
		foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	/**
	 * A group's field names, in order.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return list<string>
	 */
	private static function names_of( array $group ): array {
		return array_map( static fn( array $field ): string => (string) ( $field['name'] ?? '' ), self::fields_of( $group ) );
	}

	/**
	 * A group's first location rule parameter.
	 *
	 * @param array<string, mixed> $group The group.
	 */
	private static function location_param( array $group ): string {
		return (string) ( self::location_rule( $group )['param'] ?? '' );
	}

	/**
	 * A group's first location rule value.
	 *
	 * @param array<string, mixed> $group The group.
	 */
	private static function location_value( array $group ): string {
		return (string) ( self::location_rule( $group )['value'] ?? '' );
	}

	/**
	 * A group's first location rule: the outer location array wraps an
	 * and-group of rules, and the first group of the data model carries one.
	 *
	 * @param array<string, mixed> $group The group.
	 * @return array<string, mixed>
	 */
	private static function location_rule( array $group ): array {
		$location = $group['location'] ?? array();
		$location = is_array( $location ) ? $location : array();
		$and      = $location[0] ?? array();
		$and      = is_array( $and ) ? $and : array();
		$rule     = $and[0] ?? array();
		return is_array( $rule ) ? $rule : array();
	}

	/**
	 * The four groups of the pattern data model, seventeen fields in all.
	 */
	public function test_groups_are_four_with_seventeen_fields(): void {
		$groups = FieldGroups::groups();

		$this->assertCount( 4, $groups );
		$this->assertSame(
			array( 'group_md_pattern_spec', 'group_md_pattern_copy', 'group_md_pattern_provenance', 'group_md_series_meta' ),
			array_map( static fn( array $group ): string => (string) $group['key'], $groups ),
			'the group keys are the ones the ACF-free instance test registered under'
		);
		$this->assertCount( 17, array_merge( ...array_map( self::names_of( ... ), $groups ) ) );
	}

	/**
	 * Group A carries the six specification facts of the PDP spec bar.
	 */
	public function test_pattern_spec_carries_the_six_specification_fields(): void {
		$group = $this->groups_by_key()['group_md_pattern_spec'];

		$this->assertSame( array( 'open_area', 'visual_density', 'repeat_behavior', 'motifs', 'best_fit', 'cad_status' ), self::names_of( $group ) );
		$this->assertSame( 'side', $group['position'], 'the spec bar\'s data sits in the product edit sidebar (spec 06 §2.2)' );

		$fields = array();
		foreach ( self::fields_of( $group ) as $field ) {
			$fields[ (string) ( $field['name'] ?? '' ) ] = $field;
		}
		$this->assertSame( 'number', $fields['open_area']['type'] );
		$this->assertSame( 0, $fields['open_area']['min'] );
		$this->assertSame( 100, $fields['open_area']['max'] );
		$this->assertSame( 0.01, $fields['open_area']['step'] );
		$this->assertSame( array( 'open', 'balanced', 'dense', 'custom' ), $this->choices_of( $fields['visual_density'] ), 'the old unknown becomes custom at migration (spec 06 §2.2)' );
		$this->assertCount( 15, $this->choices_of( $fields['motifs'] ), 'the motif vocabulary is the fifteen words the export measured' );
		$this->assertSame( array( 'architectural-screening', 'decorative-screening' ), $this->choices_of( $fields['best_fit'] ) );
		$this->assertSame( array( 'gated', 'missing' ), $this->choices_of( $fields['cad_status'] ) );
	}

	/**
	 * A choice field's choice keys, in order.
	 *
	 * @param array<string, mixed> $field The field.
	 * @return list<int|string>
	 */
	private function choices_of( array $field ): array {
		$choices = $field['choices'] ?? array();
		$choices = is_array( $choices ) ? $choices : array();
		return array_keys( $choices );
	}

	/**
	 * Groups A, B and D answer the REST API; the provenance group never does.
	 */
	public function test_provenance_group_stays_out_of_rest(): void {
		$by_key = $this->groups_by_key();

		$this->assertSame( 1, $by_key['group_md_pattern_spec']['show_in_rest'] );
		$this->assertSame( 1, $by_key['group_md_pattern_copy']['show_in_rest'] );
		$this->assertSame( 0, $by_key['group_md_pattern_provenance']['show_in_rest'], 'the provenance fields leave no front-end or REST surface (spec 06 §10.7)' );
		$this->assertSame( 1, $by_key['group_md_series_meta']['show_in_rest'] );
	}

	/**
	 * The series group hangs on the product_cat term; the product groups on the product.
	 */
	public function test_series_group_targets_the_product_cat_term(): void {
		$by_key = $this->groups_by_key();

		foreach ( array( 'group_md_pattern_spec', 'group_md_pattern_copy', 'group_md_pattern_provenance' ) as $key ) {
			$this->assertSame( 'product', self::location_value( $by_key[ $key ] ), "$key hangs on the product" );
		}
		$this->assertSame( 'taxonomy', self::location_param( $by_key['group_md_series_meta'] ) );
		$this->assertSame( 'product_cat', self::location_value( $by_key['group_md_series_meta'] ) );

		$series = $by_key['group_md_series_meta'];
		$this->assertSame( array( 'accent_color', 'difficulty_level', 'typical_applications', 'bottom_content' ), self::names_of( $series ) );
		$this->assertSame( '#c9a962', self::fields_of( $series )[0]['default'], 'the gold accent every series shares is the default, not a stored copy' );
	}

	/**
	 * Every field key and name is unique across the groups.
	 */
	public function test_field_keys_and_names_do_not_collide(): void {
		$keys  = array();
		$names = array();
		foreach ( FieldGroups::groups() as $group ) {
			foreach ( self::fields_of( $group ) as $field ) {
				$keys[]  = (string) ( $field['key'] ?? '' );
				$names[] = (string) ( $field['name'] ?? '' );
			}
		}
		$this->assertCount( 17, array_unique( $keys ), 'field keys are unique' );
		$this->assertCount( 17, array_unique( $names ), 'field names are unique' );
	}

	/**
	 * The register() method hooks acf/init, and the callback hands every group to ACF.
	 */
	public function test_register_hooks_acf_init_and_registers_every_group(): void {
		$fields = new FieldGroups();

		WP_Mock::expectActionAdded( 'acf/init', array( $fields, 'register_groups' ) );
		$registered = array();
		WP_Mock::userFunction( 'acf_add_local_field_group' )->andReturnUsing(
			static function ( array $group ) use ( &$registered ): void {
				$registered[] = $group['key'];
			}
		);

		$fields->register();
		$fields->register_groups();

		$this->assertSame(
			array( 'group_md_pattern_spec', 'group_md_pattern_copy', 'group_md_pattern_provenance', 'group_md_series_meta' ),
			$registered
		);
	}
}
