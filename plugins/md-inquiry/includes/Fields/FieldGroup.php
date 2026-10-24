<?php
/**
 * The inquiry record's ACF field group.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Fields;

/**
 * Registers the inquiry record's field group with ACF free (the stack's ACF
 * edition, owner ruling 2026-10-24 revision 3): a local field group defined
 * in its own file under Definitions/, so the definition stays a plain array a
 * review can read and a test can include without WordPress — the same shape
 * md-site's field groups use.
 *
 * The group is a back-office lens, not a storage engine: the submission
 * handler writes the meta itself (value plus the field reference ACF's read
 * path expects), so an inactive ACF never stops an inquiry from being stored
 * — only the pretty editing view goes missing.
 */
final class FieldGroup {

	/**
	 * The group's ACF key.
	 */
	public const KEY = 'group_md_inquiry_record';

	/**
	 * Hooks the registration into ACF's own init.
	 */
	public function register(): void {
		\add_action( 'acf/init', array( $this, 'register_group' ) );
	}

	/**
	 * Registers the group. Runs on acf/init, the hook ACF fires once its API
	 * exists; acf_add_local_field_group is an ACF function, so it is only
	 * ever called under ACF.
	 */
	public function register_group(): void {
		if ( \function_exists( 'acf_add_local_field_group' ) ) {
			\acf_add_local_field_group( self::group() );
		}
	}

	/**
	 * The field group definition, included from its definition file.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the definition file does not return a group.
	 */
	public static function group(): array {
		$group = include __DIR__ . '/Definitions/inquiry-record.php';
		if ( ! \is_array( $group ) ) {
			throw new \RuntimeException( 'The inquiry record field group definition does not return a group array.' );
		}
		return $group;
	}

	/**
	 * The field keys by field name: the submission handler writes each value
	 * together with the `_name` reference meta that points at its field, the
	 * pair ACF's read path resolves.
	 *
	 * @return array<string, string> Field name => field key.
	 */
	public static function field_keys(): array {
		$keys = array();
		foreach ( self::group()['fields'] as $field ) {
			if ( \is_array( $field ) && isset( $field['name'], $field['key'] ) && \is_string( $field['name'] ) && \is_string( $field['key'] ) ) {
				$keys[ $field['name'] ] = $field['key'];
			}
		}
		return $keys;
	}
}
