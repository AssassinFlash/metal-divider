<?php
/**
 * Base of this plugin's unit tests.
 *
 * @package MetalDivider\Inquiry
 */

declare( strict_types = 1 );

namespace MetalDivider\Inquiry\Tests;

use WP_Mock\Tools\TestCase as WP_Mock_TestCase;

/**
 * WP_Mock's test case, as this plugin needs it: no WordPress shortcuts of
 * its own yet — the inquiry chain's tickets add the ones their code calls.
 */
abstract class TestCase extends WP_Mock_TestCase {
}
