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
 * (measured on production, 2026-10-25). LiveCanvas's [lc_if] resolves its
 * `condition` the same way, through call_user_func().
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

if ( ! function_exists( 'md_pdp_slot_has_image' ) ) {
	/**
	 * Whether the PDP's image slot resolves to an image, for the template's
	 * `[lc_if condition="md_pdp_slot_has_image" param="plate"]` frames: a
	 * slot without an image leaves no empty frame in the DOM, so the
	 * converted markup's `:has()` solo layouts read the real state instead
	 * of an always-present wrapper.
	 *
	 * @param string $slot hero-bg|plate|square|wide.
	 * @return bool
	 */
	function md_pdp_slot_has_image( $slot = '' ): bool {
		return ( new \MetalDivider\Site\Presentation\Shortcodes() )->has_image( (string) $slot );
	}
}

if ( ! function_exists( 'md_pdp_has_design_intent' ) ) {
	/**
	 * Whether the PDP's design-intent slot resolves to text, for the
	 * template's `[lc_if condition="md_pdp_has_design_intent" param="lead"]`
	 * guards: a slot WooCommerce holds no prose for leaves its paragraph
	 * out of the DOM instead of printing an empty `.pdp-body` and the
	 * margin it carries. The predicate asks `design_intent()` itself, so a
	 * paragraph and its content can never disagree.
	 *
	 * @param string $slot hero|lead|supplemental.
	 * @return bool
	 */
	function md_pdp_has_design_intent( $slot = '' ): bool {
		return ( new \MetalDivider\Site\Presentation\Shortcodes() )->has_design_intent( (string) $slot );
	}
}
// phpcs:enable
