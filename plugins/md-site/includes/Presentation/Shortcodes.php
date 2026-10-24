<?php
/**
 * The PDP data shortcodes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

/**
 * The two `[md_pdp_*]` shortcodes of the PDP template (spec 19 §6.2): data
 * assembly and nothing else — each takes the product context, returns
 * semantic markup with class names, and carries zero inline styles and zero
 * design decisions (AGENTS.md §5: any visual fix lands in a template or a
 * WindPress volume, never here).
 */
final class Shortcodes {

	/**
	 * The spec bar's Material cell.
	 */
	public const MATERIAL = 'md_pdp_material';

	/**
	 * The Design intent block's assembly.
	 */
	public const DESIGN_INTENT = 'md_pdp_design_intent';

	/**
	 * Adds the shortcodes.
	 */
	public function register(): void {
		add_shortcode( self::MATERIAL, array( $this, 'material' ) );
		add_shortcode( self::DESIGN_INTENT, array( $this, 'design_intent' ) );
	}

	/**
	 * The spec bar's Material cell: the site-wide constant, in the
	 * md-spec-table component's value markup. This is the whole assembly —
	 * the material route is a constant (owner ruling 2026-10-24 revision 6:
	 * Stainless Steel (304 / 316) is the one wording the site carries, the
	 * old five-material lists are bad data and are not migrated; spec 06 §3:
	 * material has no per-pattern carrier, the template layer renders the
	 * constant), so there is nothing per-pattern to look up (the spec bar's
	 * other cells are native product data and ACF fields the template reads
	 * themselves, spec 19 §6.2).
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string The value markup.
	 */
	public function material( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return '<span class="md-spec-table__value">' . esc_html__( 'Stainless Steel (304 / 316)', 'md-site' ) . '</span>';
	}

	/**
	 * The Design intent block: the nine series phrase groups with their
	 * attribute slots, plus the per-pattern summary (spec 19 §6.2; the phrase
	 * groups themselves are approved copy under docs/copy/, not code). The
	 * assembly is the PDP content ticket's — the phrase groups do not exist
	 * yet, so the shell answers nothing and the template renders without the
	 * block until they do.
	 *
	 * @param array<string, mixed>|string $atts    Shortcode attributes (unused).
	 * @param string|null                 $content Enclosed content (unused).
	 * @return string Empty until the phrase groups land.
	 */
	public function design_intent( $atts = array(), ?string $content = '' ): string {
		unset( $atts, $content );
		return '';
	}
}
