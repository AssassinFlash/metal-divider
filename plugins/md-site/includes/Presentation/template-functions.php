<?php
/**
 * The template data functions LiveCanvas's [lc_wp_func] calls by name.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'md_pdp_style' ) ) {
	/**
	 * The current pattern's series word (product_cat term slug's tail),
	 * for the PDP's eyebrow and spec-bar Style cell. A plain global:
	 * LiveCanvas's [lc_wp_func func="…"] resolves a function by name,
	 * and the assembly itself is Shortcodes::style().
	 *
	 * @return string The series word, empty outside a product or without a series.
	 */
	function md_pdp_style(): string {
		return Shortcodes::style();
	}
}
