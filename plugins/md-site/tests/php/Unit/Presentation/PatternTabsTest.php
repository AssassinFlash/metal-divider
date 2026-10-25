<?php
/**
 * Tests for the pattern library's tab strip wiring.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The custom-screens pattern library shipped four tab panels and no
 * behaviour bound to them: `md-chrome.js` implemented the WAI-ARIA tabs
 * pattern for the converted `<xo-tabs>` elements only, so the
 * `data-tab`/`data-panel` vocabulary on this page was inert and three of the
 * four panels could never be shown.
 *
 * A unit test cannot press a key, so these hold the invariants the fix
 * depends on — the template keeps its buttons and panels paired, and the
 * runtime still binds that vocabulary through the same single engine as the
 * `<xo-tabs>` strip. Real-browser evidence for the behaviour itself is
 * recorded in the handoff; these are the guards that catch a regression in
 * the wiring without a browser.
 *
 * @coversNothing
 */
final class PatternTabsTest extends TestCase {

	/**
	 * The page's four buttons and four panels are paired by name: every
	 * `data-tab` has a `data-panel` and the sets are the same size, so no
	 * button can select nothing and no panel can be unreachable.
	 */
	public function test_the_tab_buttons_and_panels_stay_paired(): void {
		$html = $this->template( 'custom-screens' );

		preg_match_all( '/class="tab-button[^"]*"\s*\n?\s*data-tab="([^"]*)"/', $html, $buttons );
		preg_match_all( '/class="tab-panel[^"]*"\s*\n?\s*data-panel="([^"]*)"/', $html, $panels );

		$this->assertCount( 4, $buttons[1], 'the pattern library carries its four series tabs' );
		$this->assertCount( 4, $panels[1], 'and one panel each' );

		$names = static fn( array $raw ): array => array_map(
			static fn( string $name ): string => html_entity_decode( $name, ENT_QUOTES ),
			$raw
		);

		$this->assertSame(
			$names( $buttons[1] ),
			$names( $panels[1] ),
			'every tab selects a panel of the same series'
		);
	}

	/**
	 * Each panel's pattern grid is rendered from WooCommerce, not from a
	 * list in the template: the fix must not have reintroduced a static
	 * card source while it made the panels reachable.
	 */
	public function test_each_panel_renders_its_cards_from_woocommerce(): void {
		$html = $this->template( 'custom-screens' );

		$this->assertSame(
			4,
			substr_count( $html, '[md_pattern_tiles' ),
			'one tiles call per panel'
		);
		$this->assertSame(
			4,
			substr_count( $html, '[md_pattern_group_count' ),
			'one live count per tab badge'
		);
		$this->assertStringNotContainsString( 'class="pattern-item"' . "\n", $html, 'no hand-written card carries the grid class' );
	}

	/**
	 * The runtime binds the page's vocabulary from the same engine that
	 * serves `<xo-tabs>` — one `bindTabSet`, called by both discovery
	 * passes. A second tab implementation would show up as a second
	 * definition.
	 */
	public function test_one_tab_engine_serves_both_vocabularies(): void {
		$js = $this->runtime();

		$this->assertSame( 1, substr_count( $js, 'function bindTabSet(' ), 'the tab engine is defined once' );
		$this->assertSame( 2, substr_count( $js, 'bindTabSet(triggers, panes, {' ), 'and called by both discovery passes' );
		$this->assertStringContainsString( "querySelectorAll('xo-tabs')", $js, 'the converted strip is still bound' );
		$this->assertStringContainsString( "querySelectorAll('.patterns-tabs')", $js, 'the pattern library strip is bound too' );
	}

	/**
	 * The accessibility contract the fix is for: roles, selection state and
	 * the roving tabindex are applied by the runtime, and the generated ids
	 * are namespaced per tab set so two strips on one page cannot collide.
	 */
	public function test_the_runtime_applies_the_tabs_pattern(): void {
		$js = $this->runtime();

		foreach ( array( 'aria-selected', 'aria-controls', 'aria-labelledby', 'tabpanel', 'tablist', 'role' ) as $attribute ) {
			$this->assertStringContainsString( $attribute, $js, "the runtime sets {$attribute}" );
		}
		$this->assertStringContainsString( 'ArrowRight', $js );
		$this->assertStringContainsString( 'ArrowLeft', $js );
		$this->assertStringContainsString( 'Home', $js );
		$this->assertStringContainsString( 'End', $js );
		$this->assertStringContainsString( "'md-tab-' + serial", $js, 'ids are namespaced per tab set' );
	}

	/**
	 * The pattern library's per-tile preview is bound: the tiles carry a
	 * focusable preview control and a full dialog, and shipping either
	 * without behaviour leaves a dead tab stop behind every card.
	 */
	public function test_the_tile_preview_is_bound(): void {
		$js = $this->runtime();

		$this->assertStringContainsString( "querySelectorAll('[data-pattern-preview]')", $js );
		$this->assertStringContainsString( '[data-pattern-lightbox]', $js );
		$this->assertStringContainsString( 'setupPatternLightbox();', $js, 'and it is actually wired into start()' );
	}

	/**
	 * A closed dialog is out of the tab order.
	 *
	 * The volume hides the closed lightbox with opacity alone, so its close
	 * button, arrows and thumbnails stay focusable — 195 of them across the
	 * page — and Tab walked into a surface marked aria-hidden and announced
	 * nothing. The runtime must therefore take the closed dialog out of the
	 * tab order with `inert` and restore it when the dialog opens.
	 */
	public function test_a_closed_dialog_is_removed_from_the_tab_order(): void {
		$js = $this->runtime();

		$this->assertSame( 2, substr_count( $js, 'setClosed(true);' ), 'closed on init and on every close' );
		$this->assertStringContainsString( "lightbox.setAttribute('inert', '')", $js, 'the closed dialog is inert' );
		$this->assertStringContainsString( "lightbox.removeAttribute('inert')", $js, 'and the open one is not' );
	}

	/**
	 * A template's source.
	 *
	 * @param string $file The template file stem.
	 */
	private function template( string $file ): string {
		$path = Templates::path( $file );
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The chrome runtime's source.
	 */
	private function runtime(): string {
		$path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}
}
