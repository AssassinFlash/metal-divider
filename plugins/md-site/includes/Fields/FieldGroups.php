<?php
/**
 * The ACF field groups of the pattern data model.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Fields;

/**
 * Registers the pattern data model's field groups with ACF free: four groups,
 * eighteen fields (spec 06 §2.2/§4, branch research/spec-data-model — groups
 * A/B/C on the product, D on the product_cat term). The seventeen of the spec
 * plus `notable_effect`, the old PDP's design-intent second paragraph, which
 * is retained product data rather than one of the discarded metafields. The
 * groups are local field groups, each defined in its own file under
 * Definitions/ and included here, so the definitions stay plain arrays a
 * review can read and a test can include without WordPress.
 */
final class FieldGroups {

	/**
	 * The definition files, in registration order.
	 */
	private const DEFINITIONS = array(
		'pattern-spec.php',
		'pattern-copy.php',
		'pattern-provenance.php',
		'series-meta.php',
	);

	/**
	 * Hooks the registration into ACF's own init.
	 */
	public function register(): void {
		add_action( 'acf/init', array( $this, 'register_groups' ) );
	}

	/**
	 * Registers every group. Runs on acf/init, the hook ACF fires once its
	 * API exists; acf_add_local_field_group is an ACF function, so it is only
	 * ever called under ACF.
	 */
	public function register_groups(): void {
		foreach ( self::groups() as $group ) {
			acf_add_local_field_group( $group );
		}
	}

	/**
	 * The four field groups of the pattern data model, each included from its
	 * definition file.
	 *
	 * @return list<array<string, mixed>>
	 * @throws \RuntimeException When a definition file does not return a group.
	 */
	public static function groups(): array {
		$groups = array();
		foreach ( self::DEFINITIONS as $name ) {
			$group = include __DIR__ . '/Definitions/' . $name;
			if ( ! is_array( $group ) ) {
				throw new \RuntimeException( 'The field group definition ' . $name . ' does not return a group array.' ); // phpcs:ignore WordPress.Security.EscapeOutput -- an exception message for the developer's log, never page output.
			}
			$groups[] = $group;
		}
		return $groups;
	}
}
