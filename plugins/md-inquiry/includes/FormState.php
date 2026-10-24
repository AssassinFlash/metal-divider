<?php
/**
 * The failed submission's state, carried to the re-rendered form.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry;

/**
 * What a failed submission leaves behind for the form to re-render: the
 * submitted values, so the customer does not retype them, the field errors,
 * and the notice key naming the failure. The state lives for one request —
 * the POST handler fills it, the form on the same page reads it, and a
 * fresh GET never sees it.
 */
final class FormState {

	/**
	 * The submitted values, keyed by field name.
	 *
	 * @var array<string, string>
	 */
	private array $values = array();

	/**
	 * The field errors, keyed by field name.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * The notice key naming the failure.
	 *
	 * @var string
	 */
	private string $notice_key = '';

	/**
	 * Records a failed submission.
	 *
	 * @param array<string, string> $values     The submitted values.
	 * @param array<string, string> $errors     The field errors.
	 * @param string                $notice_key The notice key.
	 * @return void
	 */
	public function fail( array $values, array $errors, string $notice_key ): void {
		$this->values     = $values;
		$this->errors     = $errors;
		$this->notice_key = $notice_key;
	}

	/**
	 * Whether a failed submission waits to be rendered.
	 *
	 * @return bool
	 */
	public function has_failed(): bool {
		return '' !== $this->notice_key;
	}

	/**
	 * The notice key.
	 *
	 * @return string
	 */
	public function notice_key(): string {
		return $this->notice_key;
	}

	/**
	 * A submitted value, or the empty string.
	 *
	 * @param string $key The field name.
	 * @return string
	 */
	public function value( string $key ): string {
		return $this->values[ $key ] ?? '';
	}

	/**
	 * A field's error, or the empty string.
	 *
	 * @param string $key The field name.
	 * @return string
	 */
	public function error( string $key ): string {
		return $this->errors[ $key ] ?? '';
	}
}
