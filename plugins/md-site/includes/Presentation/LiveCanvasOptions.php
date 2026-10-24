<?php
/**
 * The LiveCanvas settings the presentation layer needs.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The three LiveCanvas options the installed presentation needs to
 * actually render: dynamic templating (the page-type keys' loader —
 * livecanvas/modules/templating.php passes through without it), and
 * the header/footer partial hooks (picostrap's header.php/footer.php
 * call lc_custom_header/lc_custom_footer only when LiveCanvas defined
 * them, which it does on its own "header"/"footerV2" settings).
 *
 * The enable is a merge, never a rewrite: an operator's other
 * LiveCanvas settings survive, and a second run with the keys already
 * set writes nothing.
 */
final class LiveCanvasOptions {

	/**
	 * The option's name in wp_options.
	 */
	private const OPTION = 'lc_settings';

	/**
	 * The keys the presentation needs, and the truthy value each stores.
	 */
	private const NEEDED = array(
		'enable-dynamic-templating' => '1',
		'header'                    => '1',
		'footerV2'                  => '1',
	);

	/**
	 * Enables the needed keys.
	 *
	 * @return bool Whether the option changed.
	 */
	public function enable(): bool {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$missing = array();
		foreach ( self::NEEDED as $key => $value ) {
			if ( ! isset( $current[ $key ] ) ) {
				$missing[ $key ] = $value;
			}
		}
		if ( array() === $missing ) {
			return false;
		}
		update_option( self::OPTION, array_merge( $current, $missing ) );
		return true;
	}
}
