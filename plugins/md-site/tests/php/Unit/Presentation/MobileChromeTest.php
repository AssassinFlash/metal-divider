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
		$this->assertSame( 5, substr_count( $header, "aria-label='Close menu'" ), 'the drawer and its four submenus each have a close control' );
		$this->assertSame( 5, substr_count( $header, "class='md-drawer-cta'" ), 'every drawer panel keeps the quote that left the top bar' );
		$this->assertStringContainsString( "href='#open-quote'", $header );
		$this->assertStringContainsString( "data-quote-source='mobile-drawer'", $header );
		$this->assertStringContainsString( 'width: 100%', $css, 'the drawer content is full width' );
		$this->assertStringContainsString( 'min-height: 44px', $css );
		$this->assertStringContainsString( '.xo-header-actions > .xo-btn-quote', $css, 'the top-bar quote is the one the mobile rule hides' );
		$this->assertStringContainsString( 'display: none', $css );
		$this->assertStringContainsString( 'class="xo-btn-quote"', $header, 'desktop still has the quote in the actions row' );
		$this->assertStringNotContainsString( '/project-gallery/', $header, 'gallery stays in the footer, not the main nav' );
		$this->assertStringContainsString( "href='/collections/'", $header );
		$this->assertStringContainsString( '>Inquiry<', $header );
	}

	/**
	 * The search sheet is a dark panel under the header, not a full-viewport
	 * white card, and the quote dialog scrolls its fields instead of pinning
	 * the buttons over them.
	 */
	public function test_search_and_quote_sheets_do_not_cover_their_own_content(): void {
		$header = $this->template( 'partials/header.html' );
		$css    = $this->volume( 'chrome.css' );
		$js     = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringNotContainsString( 'data-locales', $header );
		$this->assertStringContainsString( '[md_partial name="search-series-tile"]', $header );
		$this->assertStringContainsString( 'id=\'md-search-hit\'', $header );
		$this->assertStringNotContainsString( "min-height: 100dvh;\n    margin: 0;\n    padding: 80px 20px 24px;", $css );
		$this->assertStringContainsString( 'max-height:calc(100dvh - var(--md-header-h, 113px) - 24px)', $css );
		$this->assertStringContainsString( 'background:#141414', $css );
		$this->assertStringContainsString( '.md-quote-modal__card .md-inquiry-fields{', $css );
		$this->assertStringContainsString( 'position: static;', $css );
		$this->assertStringContainsString( '/wp-json/wc/store/v1/products?per_page=6', $js );
		$this->assertStringNotContainsString( '/wp-json/wp/v2/product', $js );
		$this->assertStringContainsString( "panel.classList.contains('xo-menu-hamburger-style1__mega-menu')", $js );
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
	 * Padding on `body` never applies, so nothing may rely on it.
	 *
	 * WordPress prints `body{padding:0px}` from its global styles as an
	 * unlayered rule. An unlayered declaration outranks every `@layer` —
	 * layering is resolved before specificity — and these volumes compile into
	 * `components`. So a `body { padding-bottom: … }` written here is dead on
	 * arrival while looking perfectly correct in the source.
	 *
	 * Measured 2026-10-27: `:has()` matched, the rule was in the compiled
	 * cache, computed `padding-bottom` was 0px, and the catalog's legal row
	 * sat 31px under the fixed quote bar with no scroll left to reach it. The
	 * clearance moved onto `#md-footer`, which has no unlayered competitor.
	 *
	 * This pins the *rule*, because the failure is invisible in review: the
	 * CSS reads right, the selector matches, and only the computed value
	 * reveals it.
	 */
	public function test_no_volume_puts_padding_on_the_body(): void {
		$offenders = array();

		foreach ( (array) glob( $this->volume_dir() . '/*.css' ) as $path ) {
			$name = basename( (string) $path );
			$css  = (string) file_get_contents( (string) $path ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
			$css  = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

			// `body …{ padding… }` anywhere, but not `body …{ … padding on a
			// descendant }` — the declaration has to be in the body's own block.
			if ( preg_match_all( '/(^|[,}])\s*(body(?::has\([^)]*\))?(?:::?[a-z-]+)?)\s*\{([^}]*)\}/i', $css, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( preg_match( '/padding(-[a-z]+)?\s*:/i', $match[3] ) ) {
						$offenders[] = $name . ': ' . trim( $match[2] );
					}
				}
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $offenders ) ),
			'padding on `body` is dead: WordPress prints an unlayered body{padding:0} that beats every @layer. Put the clearance on the element that needs it.'
		);
	}

	/**
	 * A horizontal rail has to show that it continues.
	 *
	 * The family showcase rails held their card at 92vw — 358.8px inside a
	 * 358px track — so the next card began exactly where the rail ended and
	 * the rail looked like a single static card (measured 2026-10-27 on six
	 * pages: every card 358.8px, every track 358px, peek 0). A rail is only a
	 * rail if the edge of the next card is visible.
	 */
	public function test_the_family_showcase_rails_leave_a_peek_on_a_phone(): void {
		$families = array(
			'family-chinese-style.css',
			'family-glass-hybrid.css',
			'family-laser-cut-geometric.css',
			'family-laser-cut-panels.css',
			'family-outdoor-privacy.css',
			'family-perforated-panels.css',
		);

		foreach ( $families as $name ) {
			$css = (string) file_get_contents( $this->volume_dir() . '/' . $name ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.

			$this->assertMatchesRegularExpression(
				'/@media \(max-width: 767px\)[\s\S]*?showcase-track[\s\S]*?width: calc\(100vw - \d+px\)/',
				$css,
				$name . ' must size its showcase card narrower than the rail below 768 so the next card shows'
			);
			$this->assertMatchesRegularExpression(
				'/@media \(max-width: 767px\)[\s\S]*?mask-image: none/',
				$css,
				$name . ' must drop the edge fade below 768 — it fades the very edge the peek needs'
			);
		}
	}

	/**
	 * The hero's primary action has to be reachable without scrolling.
	 *
	 * At 320 the quote button sat at y=813 against a 568px screen — 245px
	 * below the fold — on the one page whose whole purpose is to start an
	 * inquiry. Three stacked causes: a 96px top pad, an 88svh floor, and a
	 * stats strip that stayed single-column until 640px.
	 *
	 * The rules that fix it must carry `!important`: `min-h-[88svh]` and
	 * `pt-24` are Tailwind utilities, and utilities live in a later layer than
	 * this volume's `components`, so the layer decides before specificity
	 * does. The first attempt without it changed nothing at all and looked
	 * correct in the source.
	 */
	public function test_the_home_hero_fits_its_action_without_the_utility_layer_winning(): void {
		$css = (string) file_get_contents( $this->volume_dir() . '/home.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.

		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 767px\)[\s\S]*?#home-hero-ioh-[^{]*\{\s*min-height: auto !important;/',
			$css,
			'the 88svh floor is on the hero section itself; the phone override has to beat that layer'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 767px\)[\s\S]*?#home-hero-ioh-[^{]*> \.relative \{\s*min-height: auto !important;[\s\S]*?padding-top: 32px !important;/',
			$css,
			'the inner 88svh floor and the 96px top pad are the other two utilities that pushed the CTA below the fold'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 767px\)[\s\S]*?\.home-hero-stats[\s\S]*?grid-template-columns: repeat\(3, minmax\(0, 1fr\)\) !important/',
			$css,
			'three short figures fit three across at any width; the 640px breakpoint was the wrong threshold'
		);
		$this->assertStringContainsString(
			'@media (max-width: 359px)',
			$css,
			'the narrowest specified width gets its own type step rather than scrolling its CTA out of reach'
		);
	}

	/**
	 * The family volumes' directory.
	 */
	private function volume_dir(): string {
		return dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress';
	}

	/**
	 * The catalog card fits more than one card per phone screen, and the PDP
	 * leads with its photograph.
	 *
	 * The card stacked every key over its value (`grid-template-columns:
	 * minmax(0, 1fr)` inside the row), which cost each row a second line: four
	 * rows came to 264px and one card to 695px — a card nearly a full screen
	 * tall, with the next one never in view (measured 2026-10-27 at 390). The
	 * column was also capped at 340px inside a 358px grid, leaving an 18px
	 * strip empty down the right of every catalog page.
	 *
	 * The PDP stacked title, buttons and email links above the plate, so it
	 * started at y=601 under a quote bar at y=775 — 174px of a 477px image
	 * visible, and nothing at 320.
	 */
	public function test_the_catalog_card_is_compact_and_the_pdp_leads_with_its_image(): void {
		$grid = (string) file_get_contents( $this->volume_dir() . '/pattern-grid.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		$pdp  = (string) file_get_contents( $this->volume_dir() . '/pdp.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.

		$this->assertMatchesRegularExpression(
			'/\.pattern-card__row \{[^}]*grid-template-columns: 72px minmax\(0, 1fr\)/s',
			$grid,
			'the card row keeps its key beside its value — stacking them made one card 695px tall'
		);
		$this->assertStringNotContainsString(
			'grid-template-columns: minmax(0, min(100%, 340px))',
			$grid,
			'a 340px cap inside a 358px column left an 18px strip empty on every catalog page'
		);

		$this->assertMatchesRegularExpression(
			'/\.pdp-hero-plate \{[^}]*order: -1/s',
			$pdp,
			'the pattern leads its own page on a phone — it is the subject, and it was cut by the quote bar'
		);
		$this->assertMatchesRegularExpression(
			'/\.pdp-hero-plate \{[^}]*max-height: 46vh/s',
			$pdp,
			'the plate is bounded so the title and one action still fit under it'
		);
	}

	/**
	 * Two shortcodes may never sit flush against each other in a partial.
	 *
	 * `[md_media_srcset …][md_media_dimensions]` — no space between — defeats
	 * WordPress's shortcode regex: it reads the pair as one malformed tag and
	 * expands *neither*. The whole attribute pair then ships as literal text.
	 *
	 * This is worth a guard because of how it failed: the markup looked
	 * ordinary, every shortcode was registered, the same two tags expanded
	 * fine one space apart, and the hub rendered nine cards whose every image
	 * was the literal string `[md_media_url]` — nine broken images on the page
	 * that introduces the catalog, on dev and production alike, through
	 * several rounds of review (measured 2026-10-27).
	 */
	public function test_no_partial_puts_two_shortcodes_flush_against_each_other(): void {
		$offenders = array();
		$root      = dirname( \MetalDivider\Site\Presentation\Templates::path( 'x', true ) );

		foreach ( (array) glob( $root . '/partials/*.html' ) as $path ) {
			$contents = (string) file_get_contents( (string) $path ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
			$contents = (string) preg_replace( '/<!--.*?-->/s', '', $contents );
			// Flush means no separator at all: one space already separates the
			// tags for the regex, which is why the fix is a space.
			if ( preg_match( '/\]\[(?:md_|lc_)/', $contents ) ) {
				$offenders[] = basename( (string) $path );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'these partials put two shortcodes flush against each other; WordPress reads the pair as one malformed tag and expands neither — separate them with a space: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * Body copy keeps a readable measure.
	 *
	 * The migrated templates cap paragraphs at `max-w-[980px]` or leave them
	 * uncapped in a 1400px container: 980px at 18px is about 109 characters a
	 * line, and one manufacturing paragraph measured 1,320px at 14px — around
	 * 189 characters. A line that long loses the reader.
	 *
	 * The first attempt wrote `max-width: 40em !important` on `main p`, and the
	 * measurement showed the opposite failure: `!important` from `components`
	 * outranks the utility layer, so it *replaced* every template cap instead of
	 * deferring to the smaller one. Copy landed at exactly 40em — 640px at 16px,
	 * 44% of a 1440 viewport — and the hero's `max-w-[620px]` lede was stretched
	 * to 720px. The measure must therefore cap only what has no cap of its own,
	 * and must not use `!important`, which is what this asserts.
	 */
	public function test_body_copy_keeps_a_readable_measure(): void {
		$css = (string) file_get_contents( $this->volume_dir() . '/base.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		$css = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

		$this->assertDoesNotMatchRegularExpression(
			'/max-width:\s*[\d.]+(?:em|px)\s*!important/',
			$css,
			'a body-copy cap must not use !important: it outranks the utility layer and replaces every template cap instead of deferring to the narrower one'
		);

		$this->assertMatchesRegularExpression(
			'/main p:not\(\[class\*="max-w-"\]\)[^{]*\{[^}]*max-width:\s*\d+px/s',
			$css,
			'uncapped body copy needs an explicit pixel measure, since a narrow parent container alone lets a 14px paragraph run 1,320px wide'
		);
	}

	/**
	 * The desktop dropdown carries its own surface.
	 *
	 * The mega-menu panel measured `rgba(0, 0, 0, 0)` at 1440px wide: a
	 * full-width transparent dropdown whose dark cards floated on whatever sat
	 * behind them. A panel that overlays page content must paint.
	 */
	public function test_the_mega_menu_panel_paints_a_surface(): void {
		$css = (string) file_get_contents( $this->volume_dir() . '/chrome.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		$css = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

		preg_match_all( '/([^{}]*mega-menu:not\(:empty\)[^{}]*)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER );
		$this->assertNotEmpty( $rules, 'the mega-menu panel rule must exist' );

		$painted = false;
		foreach ( $rules as $rule ) {
			if ( false !== strpos( $rule[2], 'background' ) ) {
				$painted = true;
				break;
			}
		}
		$this->assertTrue( $painted, 'the mega-menu panel must paint a surface, not float transparent' );
	}

	/**
	 * The custom-screens pattern tiles load without waiting for a scroll.
	 *
	 * Eighteen tiles across four tabs, and they are the page's content. With
	 * `lazy` the active tab's six tiles stayed blank until the visitor scrolled
	 * far enough, which reads as empty card slots.
	 */
	public function test_the_pattern_tiles_load_eagerly(): void {
		$tile = $this->template( 'partials/pattern-tile.html' );

		$this->assertStringContainsString( 'loading="eager"', $tile, 'the pattern tiles are content, not a footnote; lazy leaves them blank' );
		$this->assertStringNotContainsString( 'loading="lazy"', $tile, 'no remaining lazy tile in this partial' );
	}
	/**
	 * Nothing may hide content unconditionally; the mask has to be removable.
	 *
	 * `x-cloak` and `hidden` are both pre-init masks: they assume a script is
	 * coming to lift them. Applied unconditionally they hide content *forever*
	 * for a visitor without scripts — the family faces kept 66–67% of their
	 * words and the FAQ lost seven of its eight category groups entirely
	 * (measured 2026-10-27). Twenty-two `[x-cloak]` rules across nine volumes
	 * all said `display:none !important`; each one looked like a local
	 * decision and none of them was.
	 */
	public function test_no_style_hides_content_unconditionally(): void {
		$offenders = array();

		foreach ( (array) glob( $this->volume_dir() . '/*.css' ) as $path ) {
			$name = basename( (string) $path );
			$css  = (string) file_get_contents( (string) $path ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
			$css  = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

			// A selector mentioning `[x-cloak]` must carry the `md-js` gate.
			preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER );
			foreach ( $rules as $rule ) {
				$selector = trim( $rule[1] );
				if ( false === strpos( $selector, '[x-cloak]' ) ) {
					continue;
				}
				if ( false === strpos( $selector, 'md-js' ) ) {
					$offenders[] = $name . ': ' . mb_substr( $selector, 0, 60 );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a mask that a script cannot lift hides content from every visitor without scripts — gate it on `html.md-js`'
		);
	}

	/**
	 * The FAQ's category groups ship open, and the filter closes them at run
	 * time. Seven of the eight used to carry a literal `hidden` in the markup.
	 */
	public function test_the_faq_groups_ship_open(): void {
		$faqs = $this->template( 'faqs.html' );

		// The groups must exist before "none of them is hidden" means anything:
		// deleting every group satisfied the old negative regex.
		$groups = preg_match_all( '/class="faq-group"/', $faqs );
		$this->assertGreaterThanOrEqual( 7, $groups, 'the category groups are in the markup' );

		// Any `hidden` attribute inside a group tag is the defect, whatever the
		// attribute order — the old regex only matched `hidden` written after
		// `data-cat` with exactly one space.
		preg_match_all( '/<section[^>]*class="faq-group"[^>]*>/', $faqs, $tags );
		foreach ( $tags[0] as $tag ) {
			$this->assertStringNotContainsString(
				'hidden',
				$tag,
				'a category group ships visible: ' . substr( $tag, 0, 80 )
			);
		}
	}

	/**
	 * The mesh stage must not depend on requestAnimationFrame or on scroll
	 * events to select a scene.
	 *
	 * The rAF API does not fire in some environments (HeadlessChrome has no
	 * compositor, and it pauses in a background tab), and programmatic
	 * `window.scrollTo` does not fire a scroll event everywhere. Wrapping
	 * `sync()` in rAF meant the scene never changed on scroll, and relying on
	 * the scroll event after a nav click meant the scene never changed on
	 * click either — the whole stage read as dead (reported 2026-10-27).
	 *
	 * `setTimeout` runs in every environment, and the nav click applies its
	 * scene directly.
	 */
	public function test_the_mesh_stage_does_not_depend_on_raf_or_scroll_events(): void {
		$script = (string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../assets/js/md-chrome.js'
		);

		$this->assertStringNotContainsString(
			'window.requestAnimationFrame(function () {',
			$script,
			'wrapping scene sync in requestAnimationFrame leaves it unable to run in some environments'
		);
		$this->assertStringContainsString(
			'apply(index);',
			$script,
			'the nav click applies its scene directly rather than relying on a scroll event'
		);
		$this->assertStringContainsString(
			'function smoothScrollTo(',
			$script,
			'smooth scrolling is driven by setTimeout, not by the browser\'s smooth option, which performs no scroll in some environments'
		);
	}

	/**
	 * A class-only rule cannot carry a state against an id-scoped colour.
	 *
	 * `#md-footer a { color: inherit }` is (1, 0, 1). A `.md-footer__x:hover`
	 * rule is (0, 2, 1), so it loses even though it is the more specific
	 * *intent* — and the failure is invisible: the selector matches, the
	 * declaration parses, and only the computed colour reveals that hover
	 * never happened. The site's footer's legal links shipped exactly that
	 * (measured 2026-10-27: hovered=true, colour unchanged), the same shape
	 * as the quote button and the article CTA before it.
	 *
	 * The fix is not `!important` (that would win against every later rule,
	 * including the ones that should change) but the same id scope the base
	 * rule already uses.
	 */
	public function test_footer_state_rules_use_the_same_id_scope_as_the_base_colour(): void {
		$css = (string) file_get_contents( $this->volume_dir() . '/footer.css' ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		$css = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

		preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER );
		$offenders = array();
		foreach ( $rules as $rule ) {
			$selector = trim( $rule[1] );
			if ( false === strpos( $selector, ':hover' ) && false === strpos( $selector, ':focus-visible' ) ) {
				continue;
			}
			if ( false === strpos( $selector, '.md-footer__' ) ) {
				continue;
			}
			// The base `#md-footer a { color: inherit }` wins against any
			// class-only state rule. Each state rule must therefore carry the
			// same id so the state can actually land.
			if ( ! preg_match( '/#md-footer\s/', $selector ) ) {
				$offenders[] = mb_substr( $selector, 0, 60 );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'these hover/focus rules are class-only against `#md-footer a { color: inherit }` (1,0,1), so they never apply — scope them to the same id'
		);
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

		$this->assertStringContainsString( '/wp-json/wc/store/v1/products', $script, 'suggestions come from the Woo catalogue, with images' );
		$this->assertStringContainsString( 'md-search-hit', $script, 'the result region is filled from the hit template, not merely revealed' );
		$this->assertStringContainsString( 'AbortController', $script, 'a superseded query is cancelled instead of racing the newer one' );
		$this->assertStringContainsString( "'[data-predictive-search-result=\"'", $script, 'the region is looked up by the name the markup carries' );
	}

	/**
	 * The series tiles load when the panel opens, not on every page load.
	 *
	 * The nine tiles carry thumbnails. Fetching them at boot cost every visitor
	 * nine image requests for a panel most never open: measured 2026-10-28 at
	 * 23 images and 3467 KB on the home page against a 15-request profile
	 * before the panel existed. Deferring them brought it to 14 images, and the
	 * panel still shows all nine once opened (verified in the browser).
	 *
	 * A regex on the script is the guard that can be written without a browser:
	 * the deferred path must register a MutationObserver on the panel, and no
	 * unconditional `loadSeries()` may sit in the initialiser.
	 */
	public function test_the_series_tiles_are_fetched_when_the_panel_opens(): void {
		$script = (string) file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../assets/js/md-chrome.js'
		);

		$this->assertStringContainsString(
			'MutationObserver',
			$script,
			'the tile fetch is triggered by the panel opening'
		);
		$this->assertStringContainsString(
			"attributeFilter: ['class']",
			$script,
			'the observer watches the class the open state lands on'
		);
		$this->assertMatchesRegularExpression(
			'/series\.hidden = hasValue \|\| !seriesRequested;/',
			$script,
			'an unopened panel keeps the empty list out of the layout instead of reserving a box for it'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\n      loadSeries\(\);\n/',
			$script,
			'no unconditional fetch in the initialiser — that is the regression this guards'
		);
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

	/**
	 * The inquiry page's Turnstile frame is 300px and must not be cropped
	 * by the 230px column at 320.
	 *
	 * Measured: 16px container padding, 1px panel border and 28px
	 * quote-inner padding leave a 230px slot. The challenge frame is 300px,
	 * so a slot that stays at the column width crops its right edge. The
	 * narrow rule widens the slot to the frame and centers it on the parent;
	 * (100% - 300px) / 2 is -35px in that column, which is 10px from each
	 * viewport edge. The quote panel's overflow: hidden is the ancestor that
	 * would still clip the overhang. 390 is already a 300px column and 1440
	 * is wider, so the breakout stops at 389px. Scaling the frame or hiding
	 * its Privacy link and logo is not the repair.
	 */
	public function test_the_inquiry_turnstile_frame_fits_a_320_viewport_without_cropping(): void {
		$css   = $this->volume( 'inquiry.css' );
		$block = $this->media_block( $css, 389 );
		$slot  = $this->rule_body( $block, '#md-inquiry .md-inquiry-turnstile' );
		$panel = $this->rule_body( $block, '#md-inquiry .quote-panel' );
		$frame = $this->rule_body( $block, '#md-inquiry .md-inquiry-turnstile iframe' );

		$this->assertNotSame( '', $slot, 'the 300px slot rule is missing — the 230px column crops the challenge' );
		$this->assertStringContainsString( 'width: 300px', $slot );
		$this->assertStringContainsString( 'max-width: none', $slot, 'a 100% cap squeezes the frame back to the 230px column' );
		$this->assertStringContainsString( 'margin-left: calc((100% - 300px) / 2)', $slot );
		$this->assertStringContainsString( 'margin-right: calc((100% - 300px) / 2)', $slot );
		$this->assertStringContainsString( 'padding-left: 0', $slot );
		$this->assertStringContainsString( 'padding-right: 0', $slot );
		$this->assertStringNotContainsString( 'max-width: 100%', $slot );
		$this->assertStringNotContainsString( 'transform:', $slot, 'scaling the frame is not the repair' );
		$this->assertDoesNotMatchRegularExpression( '/font-size\s*:/', $slot );

		$this->assertStringContainsString( 'overflow: visible', $panel, 'quote-panel overflow: hidden still clips the frame' );
		$this->assertStringContainsString( 'width: 300px', $frame );
		$this->assertStringContainsString( 'max-width: none', $frame );
		$this->assertStringContainsString( 'flex-shrink: 0', $frame );
		$this->assertStringNotContainsString( 'display: none', $block, 'the challenge Privacy link and logo stay visible' );

		$base = substr( $css, 0, (int) strpos( $css, '@media (max-width: 389px)' ) );
		$this->assertStringNotContainsString( 'width: 300px', $this->rule_body( $base, '.md-inquiry .md-inquiry-turnstile' ), '390 and 1440 keep the full-width slot' );

		// 320: the column is 230px, and centering a 300px frame in it is 10px
		// from each viewport edge. 390 is the first width whose column is
		// already 300px, so the breakout must not be required there.
		$this->assertSame( 230, 320 - 16 * 2 - 1 * 2 - 28 * 2 );
		$this->assertSame( 10, 16 + 1 + 28 + (int) ( ( 230 - 300 ) / 2 ) );
		$this->assertSame( 300, 390 - 16 * 2 - 1 * 2 - 28 * 2 );
	}

	/**
	 * The body of one media query, from its opening brace to its matching close.
	 *
	 * @param string $css       The volume.
	 * @param int    $max_width The query's max-width in px.
	 */
	private function media_block( string $css, int $max_width ): string {
		$needle = '@media (max-width: ' . $max_width . 'px)';
		$start  = strpos( $css, $needle );
		if ( false === $start ) {
			return '';
		}
		$open = strpos( $css, '{', $start );
		if ( false === $open ) {
			return '';
		}
		$depth = 0;
		$end   = strlen( $css );
		for ( $i = $open; $i < $end; $i++ ) {
			$char = $css[ $i ];
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $css, $start, $i - $start + 1 );
				}
			}
		}
		return '';
	}

	/**
	 * The declaration block of one selector, or '' when the selector is absent.
	 *
	 * @param string $css      The stylesheet fragment.
	 * @param string $selector The exact selector.
	 */
	private function rule_body( string $css, string $selector ): string {
		$pattern = '/' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/';
		if ( 1 !== preg_match( $pattern, $css, $matches ) ) {
			return '';
		}
		return $matches[1];
	}
}
