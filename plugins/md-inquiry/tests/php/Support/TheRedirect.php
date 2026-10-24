<?php
/**
 * The success path's redirect, thrown instead of reaching the exit.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests\Support;

/**
 * A class of its own, so PHPUnit's own failures (which extend RuntimeException)
 * are never mistaken for the redirect the happy path throws.
 */
final class TheRedirect extends \RuntimeException {}
