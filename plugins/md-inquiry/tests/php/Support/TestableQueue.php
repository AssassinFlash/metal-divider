<?php
/**
 * The queue's test double: the capture seam replaced.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Support;

/**
 * The queue under test: the capture seam fires the configured failure the way
 * wp_mail would have, so the tick's failure paths run without WP_Mock's
 * add_action limitation.
 */
final class TestableQueue extends \MetalDivider\Inquiry\Mail\Queue {

	/**
	 * The failure the capture is handed, when one is set.
	 *
	 * @var \WP_Error|null
	 */
	public ?\WP_Error $mail_failure = null;

	/**
	 * Fires the configured failure into the capture, the way a failed wp_mail
	 * would.
	 *
	 * @param \Closure $capture The capture.
	 */
	protected function register_mail_failure_capture( \Closure $capture ): void {
		if ( null !== $this->mail_failure ) {
			$capture( $this->mail_failure );
		}
	}

	/**
	 * Nothing to clear: the seam never registers anything.
	 *
	 * @param \Closure $capture The capture.
	 */
	protected function clear_mail_failure_capture( \Closure $capture ): void {
		// The seam the tests replace: nothing to clear.
		unset( $capture );
	}
}
