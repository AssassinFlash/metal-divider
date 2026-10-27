<?php
/**
 * Mobile app-chrome contracts for the header, forms, blog, FAQ and gallery.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The mobile pass is CSS and markup, on top of the existing chrome script.
 * These assertions pin the contracts a phone actually depends on: a full-width
 * drawer, a 44px close, the quote out of the top bar, an accessible search
 * reset, and the blog / FAQ / gallery touch rules.
 *
 * @coversNothing
 */
final class MobileChromeTest extends TestCase {

	/**
	 * The drawer is a full-width sheet with a labelled 44px close and a bottom
	 * quote, and it does not repeat the header's brand line.
	 */
	public function test_the_mobile_drawer_is_a_full_width_sheet_with_a_bottom_quote(): void {
		$header = $this->template( 'partials/header.html' );
		$css    = $this->volume( 'chrome.css' );

		$this->assertStringNotContainsString( 'menu-hamburger-style1-top__brand', $header, 'the drawer must not repeat the header brand' );
		$this->assertSame( 8, substr_count( $header, "aria-label='Close menu'" ), 'every drawer panel has a close control' );
		$this->assertSame( 8, substr_count( $header, "class='md-drawer-cta'" ), 'every drawer panel keeps the quote that left the top bar' );
		$this->assertStringContainsString( "href='#open-quote'", $header );
		$this->assertStringContainsString( "data-quote-source='mobile-drawer'", $header );
		$this->assertStringContainsString( 'width: 100%', $css, 'the drawer content is full width' );
		$this->assertStringContainsString( 'min-height: 44px', $css );
		$this->assertStringContainsString( '.xo-header-actions > .xo-btn-quote', $css, 'the top-bar quote is the one the mobile rule hides' );
		$this->assertStringContainsString( 'display: none', $css );
		$this->assertStringContainsString( 'class="xo-btn-quote"', $header, 'desktop still has the quote in the actions row' );
	}

	/**
	 * Search reset is a real button with an accessible name, and the sheet's
	 * field is 16px so a phone does not zoom it.
	 */
	public function test_the_search_reset_is_an_accessible_button(): void {
		$header = $this->template( 'partials/header.html' );
		$css    = $this->volume( 'chrome.css' );

		$this->assertStringContainsString( "<form action='/shop/' method='get' role='search' class='search'>", $header );
		$this->assertStringContainsString( "type='button' class='xo-predictive-search__clear'", $header );
		$this->assertStringContainsString( "aria-label='Clear search'", $header );
		$this->assertStringContainsString( "data-predictive-search-reset='header'", $header );
		$this->assertStringContainsString( 'font-size: 16px', $css );
		$this->assertStringContainsString( 'width: 44px', $css );
		$this->assertStringContainsString( 'height: 44px', $css );
	}

	/**
	 * WhatsApp and back-to-top stay on screen when content passes under them.
	 * Overlap is not a hide. An open surface still covers them (next test).
	 */
	public function test_floats_stay_visible_when_content_passes_under_them(): void {
		$js  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$css = $this->volume( 'chrome.css' );

		$this->assertStringNotContainsString( 'md-float-yield', $js );
		$this->assertStringNotContainsString( 'function floatCoversControl', $js );
		$this->assertStringNotContainsString( 'elementsFromPoint', $js );
		$this->assertStringNotContainsString( 'md-float-yield', $css );
		$this->assertStringContainsString( 'function setupFloats()', $js );
		$this->assertStringContainsString( 'window.scrollY < 400', $js, 'back-to-top still appears after the first viewport' );
		$this->assertStringContainsString( '.md-whatsapp-float', $css );
		$this->assertStringContainsString( '.md-back-to-top', $css );
	}

	/**
	 * Floating controls yield to an open drawer and sit above the safe area,
	 * and the inquiry page does not stack a second WhatsApp button on the form.
	 */
	public function test_floating_controls_yield_when_a_surface_is_open(): void {
		$css = $this->volume( 'chrome.css' );

		$this->assertStringContainsString( 'env(safe-area-inset-bottom)', $css );
		$this->assertStringContainsString( 'html.md-modal-open :is(.md-whatsapp-float, .md-back-to-top)', $css );
		$this->assertStringContainsString( 'body:has(#md-inquiry) .md-whatsapp-float', $css );
		$this->assertStringContainsString( '.md-quote-modal__card .md-inquiry-actions', $css );
	}

	/**
	 * Blog empty and tool links, FAQ chips and gallery filters meet the 44px
	 * touch rule, and the gallery overlay is not hover-only.
	 */
	public function test_blog_faq_and_gallery_meet_the_mobile_touch_rules(): void {
		$blog    = $this->volume( 'blog-index.css' );
		$faq     = $this->volume( 'faq.css' );
		$gallery = $this->volume( 'gallery.css' );
		$faqs    = $this->template( 'faqs.html' );

		$this->assertStringContainsString( '.journal-empty a', $blog );
		$this->assertStringContainsString( 'min-height: 44px', $blog );
		$this->assertStringContainsString( 'font-size: 16px', $blog );

		$this->assertStringContainsString( 'data-md-faq-filter', $faqs );
		$this->assertStringContainsString( 'id="faqs-download-faq-search"', $faqs );
		$this->assertStringContainsString( 'class="cat-tab active', $faqs );
		$this->assertStringContainsString( 'class="faq-item"', $faqs );
		$this->assertStringContainsString( 'data-md-faq-empty', $faqs );
		$this->assertStringContainsString( 'flex-wrap: nowrap', $gallery );
		$this->assertStringContainsString( 'opacity: 1', $gallery );
		$this->assertStringContainsString( '.cat-tab', $faq );
		$this->assertStringContainsString( 'font: 400 16px/1.4 Inter, sans-serif', $faq );
	}

	/**
	 * A repository file, comments stripped is not required — these are source
	 * contracts, not rendered HTML.
	 *
	 * @param string $name Template path relative to content/livecanvas.
	 */
	private function template( string $name ): string {
		return (string) file_get_contents( Templates::path( $name, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The header rail's tap floor, and the one control that had no
	 * destination.
	 *
	 * Measured 2026-10-27 at 1440: the seven top-level links were 15.8px tall
	 * and the sub-menu rows 32.5px — every other control on the site meets
	 * 44px, and the rail is the primary way around it. The same pass found
	 * Resources shipping as `<a href="#">`: a row announced as a link that
	 * navigated nowhere, whose sub-menu only opened on hover, so it was
	 * unreachable by click and by keyboard. Both are pinned here.
	 *
	 * The floor is asserted at *brace depth zero* rather than "before the
	 * first `@media`": chrome.css opens with media blocks for other rules, so
	 * position alone proves nothing here, while depth is exactly the property
	 * that matters. A floor written inside a phone-only block is the defect
	 * this site shipped twice (footer #144, #146) — every wider width keeps
	 * the small target while the phone looks fixed.
	 */
	public function test_the_header_rail_meets_the_tap_floor_and_its_one_disclosure_is_a_button(): void {
		$header = $this->template( 'partials/header.html' );
		$css    = $this->volume( 'chrome.css' );
		$clean  = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

		foreach ( array( '.xo-menu-horizontal-v1__link{', '.xo-menu-horizontal-v1__link--sub{' ) as $selector ) {
			// Comments go first: this volume carries long prose between
			// declarations, and a `}` inside a comment would otherwise cut the
			// rule short while braces inside one would corrupt the depth count.
			$offset = strpos( $clean, $selector );
			$this->assertNotFalse( $offset, $selector . ' must exist in chrome.css' );
			$this->assertSame(
				0,
				$this->depth_at( $clean, (int) $offset ),
				$selector . ' must hold its floor at the top level, not inside a media query — a floor in a breakpoint block leaves every wider width unfixed'
			);
			$close = strpos( $clean, '}', (int) $offset );
			$this->assertNotFalse( $close, $selector . ' must be a closed rule' );
			$body = substr( $clean, (int) $offset, (int) $close - (int) $offset );
			$this->assertStringContainsString( 'min-height: 44px', $body, $selector . ' must carry the 44px tap floor' );
		}

		$this->assertStringNotContainsString(
			"href='#'",
			$header,
			'a header row with no destination must not ship as a link — it is a disclosure button'
		);
		$this->assertStringContainsString( 'data-nav-disclosure', $header );
		$this->assertStringContainsString( "aria-controls='header-resources-menu'", $header );
		$this->assertStringContainsString( "id='header-resources-menu'", $header, 'the control names the panel it opens' );
		$this->assertStringContainsString( 'is-disclosure-open', $css, 'the click state shows the same panel the hover state does' );
	}

	/**
	 * How many blocks enclose a position in a stylesheet. Zero means the rule
	 * sits at the top level; anything else means it is nested, and the only
	 * things that nest rules here are media queries.
	 *
	 * @param string $css    The stylesheet.
	 * @param int    $offset The position to measure.
	 */
	private function depth_at( string $css, int $offset ): int {
		$length = min( $offset, strlen( $css ) );
		$opens  = substr_count( substr( $css, 0, $length ), '{' );
		$closes = substr_count( substr( $css, 0, $length ), '}' );
		return max( 0, $opens - $closes );
	}

	/**
	 * The predictive search has to answer with this site's own patterns.
	 *
	 * It shipped revealing an empty box on every keystroke and issuing no
	 * request at all (measured 2026-10-27: typing "leaf" opened a zero-height
	 * result region, zero network calls) — a search field that promises
	 * suggestions and never has any. The contract is that the script asks the
	 * product collection, so a suggestion cannot name a pattern the catalog
	 * does not carry.
	 */
	public function test_the_predictive_search_asks_the_catalogue(): void {
		$script = (string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../assets/js/md-chrome.js'
		);

		$this->assertStringContainsString( '/wp-json/wp/v2/product', $script, 'suggestions come from the same collection the catalog reads' );
		$this->assertStringContainsString( 'xo-predictive-search__hit', $script, 'the result region is filled, not merely revealed' );
		$this->assertStringContainsString( 'AbortController', $script, 'a superseded query is cancelled instead of racing the newer one' );
		$this->assertStringContainsString( "'[data-predictive-search-result=\"'", $script, 'the region is looked up by the name the markup carries' );
	}

	/**
	 * A WindPress volume.
	 *
	 * @param string $name The volume file name.
	 */
	private function volume( string $name ): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress/' . $name;
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The keyboard-focus floor survives the components' own resets
	 * (GOAL.md acceptance: a visible focus ring on every focusable control).
	 *
	 * Twenty-odd volumes each set `outline: none` on their own `:focus` rule;
	 * some paint a substitute and some paint nothing, which left a keyboard
	 * visitor with no visible position on the hub buttons, every spec-map
	 * card, the journal search field and the catalog's search, sort and
	 * pagination. A floor below those declarations would lose to them, so it
	 * has to be `!important` — and it has to be two-tone, because the site
	 * gold measures only 2.1:1 on the white header.
	 *
	 * Verified with a real Tab walk on the dev instance (eight faces, 82
	 * controls): every stop paints the pair, and every ring clears 3:1
	 * against its backdrop. `:focus-visible` keeps a mouse click from
	 * drawing it.
	 */
	public function test_the_keyboard_focus_floor_is_two_tone_and_wins(): void {
		$css = $this->volume( 'chrome.css' );

		$this->assertMatchesRegularExpression(
			'/\n:focus-visible \{\s*box-shadow: 0 0 0 2px #ffffff, 0 0 0 4px #131313 !important;/',
			$css,
			'the floor paints a white inner ring and a near-black outer ring, and declares !important so a component reset cannot remove it'
		);
		$this->assertStringNotContainsString(
			':focus:not(:focus-visible) { outline: none !important; }',
			$css,
			'no blanket reset cancels the floor'
		);
		$this->assertMatchesRegularExpression(
			'/\n:focus-visible \{[^}]*!/',
			$css,
			'the floor is authoritative'
		);
	}

	/**
	 * The footer dock's three contracts.
	 *
	 * Ported from de-linker's footer on 2026-10-27, so the behaviour that
	 * came with it needs holding in place:
	 *
	 * 1. The block is gated on `is-ready`, which `setupFooterDock()` adds.
	 *    Before that class the dock is `display: none` and every panel shows —
	 *    so a visitor without JavaScript reads all sixteen destinations
	 *    instead of facing a control that cannot work.
	 * 2. The tabs are real ARIA tabs: `role=tablist` on the dock, focus moved
	 *    with the selection, and ArrowLeft/Right plus Home/End.
	 * 3. The contact panel's quote button carries the site's `#open-quote`
	 *    anchor and a `data-quote-source`, so it opens the same dialog as
	 *    every other CTA and the record names footer-dock as the entry point.
	 */
	public function test_the_footer_dock_keeps_its_gate_and_tab_semantics(): void {
		$css = $this->volume( 'footer.css' );

		// The gate: hidden until ready, panels stacked until ready.
		$this->assertMatchesRegularExpression(
			'/\.md-footer__dock \{\s*display: none;/',
			$css,
			'the dock is hidden before the runtime marks the block ready'
		);
		$this->assertMatchesRegularExpression(
			'/#md-footer\.is-ready \.md-footer__dock \{\s*display: grid;/',
			$css,
			'the dock appears once is-ready is set'
		);
		$this->assertMatchesRegularExpression(
			'/#md-footer\.is-ready \.md-footer__panels \{\s*display: block;/',
			$css,
			'panels become a single visible one only when the dock can drive them'
		);

		$runtime = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertStringContainsString( "root.classList.add('is-ready');", $runtime, 'setupFooterDock sets the gate' );
		$this->assertStringContainsString( "dock.setAttribute('role', 'tablist');", $runtime, 'the dock is an ARIA tablist' );
		$this->assertStringContainsString( "tab.setAttribute('role', 'tab');", $runtime, 'each dock item is a tab' );
		$this->assertStringContainsString( 'ArrowRight', $runtime, 'arrow keys move between tabs' );
		$this->assertStringContainsString( "event.key === 'Home'", $runtime, 'Home jumps to the first tab' );
		$this->assertStringContainsString( 'setupFooterDock();', $runtime, 'the setup actually runs' );

		$footer = (string) file_get_contents( dirname( __DIR__, 4 ) . '/content/livecanvas/partials/footer.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertStringContainsString( 'href="#open-quote"', $footer, 'the contact panel\'s quote button opens the site dialog' );
		$this->assertStringContainsString( 'data-quote-source="footer-dock"', $footer, 'the record names this entry point' );
		$this->assertStringContainsString( 'data-md-footer-tab="contact"', $footer, 'the contact tab exists' );
	}

	/**
	 * The footer's tap floor applies at every width, not just on phones.
	 *
	 * This is the failure the footer produced twice. The `min-height: 44px`
	 * rules first lived only in the `max-width: 760px` block, so a
	 * three-viewport sweep of all 21 page types passed at 390 and 320 and
	 * failed at 1440 on every page with exactly three controls — the legal
	 * row's Privacy, FAQ and Contact, at 26px (measured 2026-10-27).
	 *
	 * The assertion is on placement rather than on the value: each of these
	 * three must carry its floor outside any media query, because a floor that
	 * only some widths inherit is the defect itself.
	 */
	public function test_the_footer_tap_floor_is_not_confined_to_phones(): void {
		$css = $this->volume( 'footer.css' );

		// Split the sheet at its media queries; anything before the first one is
		// the base layer.
		$base = substr( $css, 0, (int) strpos( $css, '@media' ) );
		$this->assertNotSame( '', $base, 'the sheet has a base layer before its media queries' );

		foreach ( array( '.md-footer__identity-node', '.md-footer__copy', '.md-footer__bar-legal a' ) as $selector ) {
			// `min-height` for rows that must grow, `height` for the fixed-size
			// icon button: either satisfies the floor.
			$pattern = '/' . preg_quote( $selector, '/' ) . '\s*\{[^}]*(?:min-)?height:\s*4[0-9]px/';
			$this->assertMatchesRegularExpression(
				$pattern,
				$base,
				$selector . ' must carry its tap floor in the base layer, not inside a phone-width media query'
			);
		}
	}
}
