<?php
/**
 * The queue's hand-rolled database double: the six wpdb members the queue
 * touches, recorded and steerable, with no WordPress and no Mockery needed.
 *
 * Unlike the WP-named stand-ins, this class is phpstan's to read: it is the
 * tests' own code, named its own name.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Support;

/**
 * The double: every call lands in $calls; every return comes from $returns,
 * where a value answers every call and a closure is invoked with the call's
 * arguments.
 */
final class FakeWpdb {

	/**
	 * The table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * The charset dbDelta's statement carries.
	 *
	 * @var string
	 */
	public string $charset = 'utf8mb4';

	/**
	 * The collation.
	 *
	 * @var string
	 */
	public string $collate = '';

	/**
	 * Every call, by method name.
	 *
	 * @var array<string, list<array<int, mixed>>>
	 */
	public array $calls = array();

	/**
	 * The answers, by method name.
	 *
	 * @var array<string, mixed>
	 */
	public array $returns = array();

	/**
	 * Records and answers any of the catch-all members.
	 *
	 * @param string            $name The method's name.
	 * @param array<int, mixed> $args The call's arguments.
	 * @return mixed
	 */
	public function __call( string $name, array $args ): mixed {
		$this->calls[ $name ][] = $args;
		if ( ! \array_key_exists( $name, $this->returns ) ) {
			return null;
		}
		$return = $this->returns[ $name ];
		if ( $return instanceof \Closure ) {
			return $return( ...$args );
		}
		return $return;
	}

	/**
	 * Records a prepared statement and returns it with its values
	 * substituted, as the real prepare does — so the recorded SQL carries the
	 * values the assertions read.
	 *
	 * @param string            $query  The statement's template.
	 * @param array<int, mixed> ...$args The values.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		$this->calls['prepare'][] = \array_merge( array( $query ), \array_values( $args ) );
		$index                    = 0;
		$result                   = \preg_replace_callback(
			'/%[sd]/',
			static function ( array $matches ) use ( $args, &$index ): string {
				$value = $args[ $index++ ] ?? '';
				if ( '%d' === $matches[0] ) {
					return (string) (int) $value;
				}
				return "'" . \addslashes( \is_scalar( $value ) ? (string) $value : '' ) . "'";
			},
			$query
		);
		return \is_string( $result ) ? $result : $query;
	}

	/**
	 * The recorded calls of one method.
	 *
	 * @param string $name The method's name.
	 * @return list<array<int, mixed>>
	 */
	public function calls_of( string $name ): array {
		return $this->calls[ $name ] ?? array();
	}
}
