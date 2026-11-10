<?php
/**
 * A filter mock that passes the first argument straight through.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Support;

use WP_Mock\Filter;

/**
 * A filter mock that passes the first argument straight through.
 */
final class PassthruFilter extends Filter {

	/**
	 * Apply the filter: if a matching processor exists, use it; otherwise pass $args[0] through.
	 *
	 * @param array<int, mixed> $args Arguments passed to apply_filters.
	 * @return mixed
	 */
	public function apply( $args ) {
		$orig_args = $args;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP_Mock property compatibility.
		if ( isset( self::$filtersWithAnyArgs[ $this->name ] ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP_Mock property compatibility.
			$args = self::$filtersWithAnyArgs[ $this->name ];
		}

		if ( null === $args[0] && 1 === \count( $args ) ) {
			if ( isset( $this->processors['argsnull'] ) ) {
				return $this->processors['argsnull']->send();
			}
			return null;
		}

		$processors = $this->processors;
		foreach ( $args as $arg ) {
			$key = $this->safe_offset( $arg );
			if ( ! \is_array( $processors ) || ! isset( $processors[ $key ] ) ) {
				return $orig_args[0] ?? null;
			}
			$processors = $processors[ $key ];
		}

		if ( \is_object( $processors ) && \method_exists( $processors, 'send' ) ) {
			return \call_user_func_array( array( $processors, 'send' ), $args );
		}

		return $orig_args[0] ?? null;
	}
}
