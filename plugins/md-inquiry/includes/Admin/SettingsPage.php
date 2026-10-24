<?php
/**
 * The inquiry chain's settings page.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Admin;

use MetalDivider\Inquiry\Config;
use MetalDivider\Inquiry\Mail\Queue;

/**
 * The back office's one page of switches: the Turnstile keys (the fallback
 * while the wp-config constants are not in place), the notification route,
 * and test mode — plus the queue's health, because a mail the chain could
 * not deliver must be visible, never silently gone.
 *
 * Everything a wp-config constant already owns shows as locked: the
 * deployment's values win, and the input beneath cannot mislead anyone into
 * editing a setting that is never read.
 */
final class SettingsPage {

	/**
	 * The page's slug.
	 */
	public const SLUG = 'md-inquiry-settings';

	/**
	 * The settings' option group.
	 */
	public const GROUP = 'md_inquiry';

	/**
	 * The configuration.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * The outbound queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Sets up the page.
	 *
	 * @param Config $config The configuration.
	 * @param Queue  $queue  The outbound queue.
	 */
	public function __construct( Config $config, Queue $queue ) {
		$this->config = $config;
		$this->queue  = $queue;
	}

	/**
	 * Hooks the page in.
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_page' ) );
		\add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the page beneath the inquiries menu.
	 */
	public function add_page(): void {
		\add_submenu_page(
			'edit.php?post_type=md_inquiry',
			__( 'Inquiry settings', 'md-inquiry' ),
			__( 'Settings', 'md-inquiry' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers the four options, each with its sanitizer.
	 */
	public function register_settings(): void {
		\register_setting(
			self::GROUP,
			'md_inquiry_turnstile_sitekey',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		\register_setting(
			self::GROUP,
			'md_inquiry_turnstile_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		\register_setting(
			self::GROUP,
			'md_inquiry_notification_route',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_route' ),
			)
		);
		\register_setting(
			self::GROUP,
			'md_inquiry_test_recipient',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_test_recipient' ),
			)
		);
	}

	/**
	 * Sanitizes the notification route: one of the three, or the default.
	 *
	 * @param string $value The posted value.
	 * @return string
	 */
	public function sanitize_route( string $value ): string {
		return \in_array( $value, array( Config::ROUTE_BOTH, Config::ROUTE_PRIMARY, Config::ROUTE_BACKUP ), true ) ? $value : Config::ROUTE_BOTH;
	}

	/**
	 * Sanitizes the test recipient: an email, or the empty string that turns
	 * test mode off.
	 *
	 * @param string $value The posted value.
	 * @return string
	 */
	public function sanitize_test_recipient( string $value ): string {
		$value = \trim( $value );
		return false !== \is_email( $value ) ? $value : '';
	}

	/**
	 * Renders the page.
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = $this->queue->counts();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Inquiry settings', 'md-inquiry' ); ?></h1>
			<form method="post" action="options.php">
				<?php \settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php $this->row( \esc_html__( 'Turnstile sitekey', 'md-inquiry' ), $this->text_input( 'md_inquiry_turnstile_sitekey', $this->config->turnstile_sitekey(), $this->config->turnstile_sitekey_is_constant() ) ); ?>
						<?php $this->row( \esc_html__( 'Turnstile secret', 'md-inquiry' ), $this->text_input( 'md_inquiry_turnstile_secret', '', $this->config->turnstile_secret_is_constant(), 'password' ) ); ?>
						<?php $this->row( \esc_html__( 'Internal notification recipients', 'md-inquiry' ), $this->route_select() ); ?>
						<?php $this->row( \esc_html__( 'Test mode recipient', 'md-inquiry' ), $this->text_input( 'md_inquiry_test_recipient', '', $this->config->test_recipient_is_constant() ) ); ?>
					</tbody>
				</table>
				<?php \submit_button(); ?>
			</form>

			<h2><?php \esc_html_e( 'Mail queue', 'md-inquiry' ); ?></h2>
			<p>
				<?php
				echo \esc_html(
					\sprintf(
						/* translators: 1: pending count, 2: sent count, 3: failed count. */
						\__( 'Pending %1$d · Sent %2$d · Failed %3$d', 'md-inquiry' ),
						$counts['pending'],
						$counts['sent'],
						$counts['failed']
					)
				);
				?>
			</p>
			<?php $this->failures_table(); ?>
		</div>
		<?php
	}

	/**
	 * One form-table row.
	 *
	 * @param string $label The row's label.
	 * @param string $input The row's input markup.
	 * @return void
	 */
	private function row( string $label, string $input ): void {
		echo '<tr><th scope="row">' . $label . '</th><td>' . $input . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- both halves are escaped by their builders.
	}

	/**
	 * One text input; disabled with a note when a wp-config constant owns the
	 * value.
	 *
	 * @param string $name     The option's name.
	 * @param string $value    The current value to show (empty for secrets).
	 * @param bool   $constant Whether a constant owns the value.
	 * @param string $type     The input type.
	 * @return string
	 */
	private function text_input( string $name, string $value, bool $constant, string $type = 'text' ): string {
		$input = '<input type="' . \esc_attr( $type ) . '" name="' . \esc_attr( $name ) . '" value="' . \esc_attr( $value ) . '" class="regular-text"' . ( $constant ? ' disabled' : '' ) . '>';
		if ( $constant ) {
			$input .= '<p class="description">' . \esc_html__( 'Set in wp-config.php; this field is not in use.', 'md-inquiry' ) . '</p>';
		}
		return $input;
	}

	/**
	 * The notification route's select.
	 *
	 * @return string
	 */
	private function route_select(): string {
		$current = $this->config->notification_route();
		$out     = '<select name="md_inquiry_notification_route">';
		foreach ( array(
			Config::ROUTE_BOTH    => \__( 'Primary and backup', 'md-inquiry' ) . ' — sales@sunriverinverter.com, info@metal-divider.com',
			Config::ROUTE_PRIMARY => \__( 'Primary only', 'md-inquiry' ) . ' — sales@sunriverinverter.com',
			Config::ROUTE_BACKUP  => \__( 'Backup only', 'md-inquiry' ) . ' — info@metal-divider.com',
		) as $route => $label ) {
			$out .= '<option value="' . \esc_attr( $route ) . '"' . ( $route === $current ? ' selected' : '' ) . '>' . \esc_html( $label ) . '</option>';
		}
		$out .= '</select>';
		return $out;
	}

	/**
	 * The latest failed queue rows, when there are any.
	 *
	 * @return void
	 */
	private function failures_table(): void {
		$rows = $this->queue->recent_failures();
		if ( array() === $rows ) {
			return;
		}
		echo '<h3>' . \esc_html__( 'Latest delivery failures', 'md-inquiry' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . \esc_html__( 'Inquiry', 'md-inquiry' ) . '</th><th>' . \esc_html__( 'Mail', 'md-inquiry' ) . '</th><th>' . \esc_html__( 'Attempts', 'md-inquiry' ) . '</th><th>' . \esc_html__( 'Last error', 'md-inquiry' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . \esc_html( (string) ( $row['inquiry_id'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['mail_type'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['attempts'] ?? '' ) ) . '</td>';
			echo '<td>' . \esc_html( (string) ( $row['last_error'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
