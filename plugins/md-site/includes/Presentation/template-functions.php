<?php
/**
 * The template data functions LiveCanvas's [lc_wp_func] calls by name.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/*
 * No namespace in this file. LiveCanvas's [lc_wp_func func="md_pdp_style"]
 * resolves a callback by its global name, so a function declared inside
 * MetalDivider\Site\Presentation would never be found — the template would
 * render a `call_user_func(): not a valid callback` error to visitors
 * (measured on production, 2026-10-25).
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the name is LiveCanvas's [lc_wp_func] contract, not ours to rename.
if ( ! function_exists( 'md_pdp_style' ) ) {
	/**
	 * The current pattern's series word (product_cat term slug's tail),
	 * for the PDP's eyebrow and spec-bar Style cell. The assembly itself
	 * is Shortcodes::style(); this is its global entry point.
	 *
	 * @return string The series word, empty outside a product or without a series.
	 */
	function md_pdp_style(): string {
		return \MetalDivider\Site\Presentation\Shortcodes::style();
	}
}
// phpcs:enable
