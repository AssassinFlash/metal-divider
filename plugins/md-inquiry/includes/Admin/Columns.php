<?php
/**
 * The inquiries list's columns.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Admin;

use MetalDivider\Inquiry\Statuses;

/**
 * The back-office list an inquiry is read from (spec 07 §7): the submitted
 * time, the name, the email, the company, the pattern, the source and the
 * workflow status — the mail is a notification, this list is where the
 * record lives.
 */
final class Columns {

	/**
	 * Hooks the columns in.
	 */
	public function register(): void {
		\add_filter( 'manage_md_inquiry_posts_columns', array( $this, 'columns' ) );
		\add_action( 'manage_md_inquiry_posts_custom_column', array( $this, 'render' ), 10, 2 );
	}

	/**
	 * The list's columns: the title (already the "name — company — date"
	 * line), then the fields the staff scans for.
	 *
	 * @param array<string, string> $columns The default columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return array(
			'cb'         => $columns['cb'] ?? '<input type="checkbox">',
			'title'      => __( 'Inquiry', 'md-inquiry' ),
			'md_email'   => __( 'Email', 'md-inquiry' ),
			'md_company' => __( 'Company', 'md-inquiry' ),
			'md_pattern' => __( 'Pattern', 'md-inquiry' ),
			'md_source'  => __( 'Source', 'md-inquiry' ),
			'md_status'  => __( 'Status', 'md-inquiry' ),
			'date'       => $columns['date'] ?? __( 'Date', 'md-inquiry' ),
		);
	}

	/**
	 * Renders one custom column's cell.
	 *
	 * @param string $column  The column's key.
	 * @param int    $post_id The inquiry record's ID.
	 * @return void
	 */
	public function render( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'md_email':
				$email = (string) \get_post_meta( $post_id, 'customer_email', true );
				echo '<a class="md-inquiry-column-email" href="' . \esc_url( 'mailto:' . $email ) . '">' . \esc_html( $email ) . '</a>';
				return;
			case 'md_company':
				echo \esc_html( (string) \get_post_meta( $post_id, 'customer_company', true ) );
				return;
			case 'md_pattern':
				$name = (string) \get_post_meta( $post_id, 'pattern_name', true );
				$code = (string) \get_post_meta( $post_id, 'pattern_code', true );
				if ( '' === $name ) {
					echo '<span aria-hidden="true">—</span>';
					return;
				}
				echo \esc_html( $name );
				if ( '' !== $code ) {
					echo ' <span class="md-inquiry-column-code">' . \esc_html( $code ) . '</span>';
				}
				return;
			case 'md_source':
				$source = array(
					(string) \get_post_meta( $post_id, 'source_page', true ),
					(string) \get_post_meta( $post_id, 'source_module', true ),
					(string) \get_post_meta( $post_id, 'source_button', true ),
				);
				echo \esc_html( \implode( ' / ', \array_filter( $source, static fn( string $part ): bool => '' !== $part ) ) );
				return;
			case 'md_status':
				$status = (string) \get_post_status( $post_id );
				echo \esc_html( Statuses::label( $status ) );
				return;
		}
	}
}
