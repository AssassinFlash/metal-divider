<?php
/**
 * The inquiry record's workflow statuses.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * The three custom post statuses an inquiry record moves through (spec 07 §7,
 * branch research/spec-inquiry-chain): every record starts as new, the staff
 * marks it contacted once they have replied, and closed when the thread ends.
 *
 * The names carry the md_ prefix because post status names are global — an
 * unprefixed "new" would collide with the status WordPress itself uses
 * between insert and the first save. The labels stay clean for the list.
 */
final class Statuses {

	/**
	 * The status a record is created with.
	 */
	public const NEW = 'md_new';

	/**
	 * The staff has replied.
	 */
	public const CONTACTED = 'md_contacted';

	/**
	 * The thread has ended.
	 */
	public const CLOSED = 'md_closed';

	/**
	 * The three statuses, in workflow order.
	 *
	 * @var list<string>
	 */
	public const ALL = array( self::NEW, self::CONTACTED, self::CLOSED );

	/**
	 * Hooks the registration into init.
	 */
	public function register(): void {
		\add_action( 'init', array( $this, 'register_statuses' ) );
	}

	/**
	 * Registers the statuses.
	 */
	public function register_statuses(): void {
		foreach ( self::args() as $name => $status ) {
			\register_post_status( $name, $status );
		}
	}

	/**
	 * The registration arguments, one entry per status.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return array(
			self::NEW       => array(
				'label'                     => _x( 'New', 'inquiry status', 'md-inquiry' ),
				// translators: %s: the number of new inquiries.
				'label_count'               => _n_noop( 'New <span class="count">(%s)</span>', 'New <span class="count">(%s)</span>', 'md-inquiry' ),
				'public'                    => false,
				'internal'                  => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			),
			self::CONTACTED => array(
				'label'                     => _x( 'Contacted', 'inquiry status', 'md-inquiry' ),
				// translators: %s: the number of contacted inquiries.
				'label_count'               => _n_noop( 'Contacted <span class="count">(%s)</span>', 'Contacted <span class="count">(%s)</span>', 'md-inquiry' ),
				'public'                    => false,
				'internal'                  => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			),
			self::CLOSED    => array(
				'label'                     => _x( 'Closed', 'inquiry status', 'md-inquiry' ),
				// translators: %s: the number of closed inquiries.
				'label_count'               => _n_noop( 'Closed <span class="count">(%s)</span>', 'Closed <span class="count">(%s)</span>', 'md-inquiry' ),
				'public'                    => false,
				'internal'                  => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			),
		);
	}

	/**
	 * A status's label, or the empty string for an unknown one.
	 *
	 * @param string $status The status name.
	 * @return string
	 */
	public static function label( string $status ): string {
		$args = self::args();
		return isset( $args[ $status ] ) && \is_string( $args[ $status ]['label'] ) ? $args[ $status ]['label'] : '';
	}

	/**
	 * Whether a value is one of the workflow statuses.
	 *
	 * @param string $status The value to check.
	 * @return bool
	 */
	public static function is_valid( string $status ): bool {
		return \in_array( $status, self::ALL, true );
	}
}
