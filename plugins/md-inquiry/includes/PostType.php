<?php
/**
 * The inquiry system-of-record post type.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * The `md_inquiry` post type: the system record an inquiry lands in (spec 07
 * §7, branch research/spec-inquiry-chain: non-public, back-office only; the
 * mail is a notification, not the archive). Supports title and date alone —
 * the title is filled on submission ("name — company — date"), and every
 * field travels as the ACF field group of the chain's own ticket, not as post
 * type supports. The page capabilities gate the records: the back office is
 * the owner's and the editors'; the author and contributor roles never see
 * a customer's inquiries.
 */
final class PostType {

	/**
	 * The post type's name.
	 */
	public const NAME = 'md_inquiry';

	/**
	 * Hooks the registration into init.
	 */
	public function register(): void {
		\add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the post type.
	 */
	public function register_post_type(): void {
		\register_post_type( self::NAME, self::args() );
	}

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function args(): array {
		return array(
			'labels'            => array(
				'name'          => 'Inquiries',
				'singular_name' => 'Inquiry',
			),
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_rest'      => false,
			'show_in_admin_bar' => false,
			'capability_type'   => 'page',
			'menu_icon'         => 'dashicons-email-alt',
			'supports'          => array( 'title', 'date' ),
		);
	}
}
