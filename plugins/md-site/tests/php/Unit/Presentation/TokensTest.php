<?php
/**
 * Tests for the design-token stylesheet and its legacy-copy guards.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Tokens;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;
use WP_Post;

/**
 * Tests for Tokens: the exported stylesheet's anchors, and the two guards
 * that remove a tokens copy from Additional CSS or a LiveCanvas partial —
 * each idempotent, each a no-op on a clean site.
 *
 * @covers \MetalDivider\Site\Presentation\Tokens
 */
final class TokensTest extends TestCase {

	/**
	 * The normalized sha256 of the exported tokens stylesheet: comments
	 * stripped, whitespace collapsed, lowercased. The string checks below
	 * only catch a renamed plugin path — the de-linker full-teardown audits
	 * showed a whole-CSS byte copy can slip past them. The hash catches any
	 * wholesale change: when a change to tokens.css is INTENDED, recompute
	 * the hash and re-pin it in the same diff, so the review sees a
	 * conscious token-layer change instead of a silent transplant.
	 *
	 * Re-pinned 2026-10-26 for the `--md-header-h` addition: the sticky
	 * header band's measured height (73px below 991, 60px from 991 up after
	 * the 2026-10-31 ruling), which the sticky rails and every in-page anchor
	 * offset now read instead of hard-coding a guess.
	 *
	 * Re-pinned 2026-10-27 for `--md-gold-ink`: the palette had one gold and
	 * used it for both fills and text, so every gold word on a cream or white
	 * panel measured 2.14–3.28:1 — the whole light-surface half of the site
	 * (`--md-gold-ink` holds 4.86:1 there). Same hue, separate role.
	 *
	 * Re-pinned 2026-10-31 for the header band's two upper tiers: the owner
	 * ruled the blank above the header too tall (GOAL.md 2026-10-31). The
	 * >=1200 tier was 113px only because the old site's 37px announcement
	 * strip added it, and that strip held no text at any width (measured at
	 * 1440, visitor state); it is gone from the markup and the band is 60px
	 * from 991 up — the 44px controls plus symmetric 8px.
	 */
	private const NORMALIZED_SHA256 = '5f24373ddeb1d3a89c11aa39e28153acc9ad7c5dcfa5e43d67888d446e706584';

	/**
	 * The exported stylesheet carries the design tokens of the MASTER.md
	 * mapping (spec 17 §4.2) and its normalized content is exactly the
	 * pinned hash.
	 */
	public function test_css_carries_the_master_md_tokens(): void {
		$css = Tokens::css();

		$this->assertSame( '/* md-site-tokens */', Tokens::MARKER );
		$this->assertStringContainsString( 'color-scheme: dark', $css );
		$this->assertStringContainsString( '--md-bg: #1a1a1a', $css );
		$this->assertStringContainsString( '--md-bg-deep: #141414', $css );
		$this->assertStringContainsString( '--md-bg-darkest: #0a0a0a', $css );
		$this->assertStringContainsString( '--md-gold: #c9a962', $css );
		$this->assertStringContainsString( '--md-gold-light: #d4b878', $css );
		$this->assertStringContainsString( '--md-gold-dark: #a88a4a', $css );
		$this->assertStringContainsString( '--md-gold-accent: #d4af37', $css );
		$this->assertStringContainsString( '--md-cream: #faf9f7', $css );
		// Owner ruling 2026-10-30: the WhatsApp channel is gone site-wide, so
		// the brand token went with it.
		$this->assertStringNotContainsString( '--md-whatsapp', $css, 'no WhatsApp token (owner ruling 2026-10-30)' );
		$this->assertStringContainsString( '--md-font-display: "Playfair Display", Georgia, serif', $css );
		$this->assertStringContainsString( '--md-font-sans: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif', $css );
		$this->assertStringContainsString( '--md-container: 1400px', $css );
		$this->assertStringContainsString( '--md-header-h: 73px', $css, 'the sticky band height is a token the sticky rails and anchors read' );
		$this->assertStringContainsString( '--md-header-h: 60px', $css, 'the compact band from 991 up: 44px controls plus symmetric 8px (owner ruling 2026-10-31)' );
		$this->assertStringNotContainsString( '--md-header-h: 113px', $css, 'the 37px announcement strip that made the old>=1200 tier 113px is gone' );
		$this->assertStringContainsString( '/wp-content/plugins/md-site/assets/fonts/inter-var.woff2', $css, 'the fonts are self-hosted from this plugin (OFL), never a third-party CDN' );

		$stripped   = preg_replace( '/\/\*.*?\*\//s', '', $css ) ?? '';
		$normalized = strtolower( preg_replace( '/\s+/', ' ', $stripped ) ?? '' );
		$this->assertSame(
			self::NORMALIZED_SHA256,
			hash( 'sha256', trim( $normalized ) ),
			'tokens.css changed beyond its pinned normalized hash — if intended, re-pin NORMALIZED_SHA256 in this test within the same diff.'
		);
	}

	/**
	 * The uninstall() method removes a marked block from Additional CSS, and a second
	 * run on the stripped CSS changes nothing.
	 */
	public function test_uninstall_strips_the_marked_block_once(): void {
		$store   = "/* lead */\n" . Tokens::MARKER . "\n:root{--md-gold:#c9a962}\n/* /md-site-tokens */\n/* tail */";
		$written = '';
		WP_Mock::userFunction( 'wp_get_custom_css' )->andReturnUsing(
			function () use ( &$store ): string {
				return $store;
			}
		);
		WP_Mock::userFunction( 'wp_update_custom_css_post' )->andReturnUsing(
			static function ( string $css ) use ( &$written ): int {
				$written = $css;
				return 1;
			}
		);

		$tokens = new Tokens();

		$this->assertTrue( $tokens->uninstall() );
		$this->assertSame( "/* lead */\n\n/* tail */", $written, 'only the marked block goes' );

		$store = $written;
		$this->assertFalse( $tokens->uninstall(), 'a second run has nothing to remove' );
	}

	/**
	 * A custom CSS without the marker is left alone.
	 */
	public function test_uninstall_leaves_unmarked_css_alone(): void {
		WP_Mock::userFunction( 'wp_get_custom_css' )->andReturn( 'body{margin:0}' );

		$this->assertFalse( ( new Tokens() )->uninstall() );
	}

	/**
	 * The detach_from_partials() method strips the inlined tokens style block from
	 * LiveCanvas partials, and a second run rewrites nothing.
	 */
	public function test_detach_from_partials_strips_the_inlined_block(): void {
		$partial               = new WP_Post( new \stdClass() );
		$partial->ID           = 7;
		$partial->post_type    = 'lc_partial';
		$partial->post_content = '<header><style data-site-css="tokens.css">:root{--md-gold:#c9a962}</style><nav></nav></header>';
		$clean                 = new WP_Post( new \stdClass() );
		$clean->ID             = 8;
		$clean->post_type      = 'lc_partial';
		$clean->post_content   = '<footer><nav></nav></footer>';

		$updates = array();
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $partial, $clean ) );
		WP_Mock::userFunction( 'wp_update_post' )->andReturnUsing(
			static function ( array $data ) use ( &$updates ): int {
				$updates[ (int) $data['ID'] ] = (string) $data['post_content'];
				return (int) $data['ID'];
			}
		);
		WP_Mock::passthruFunction( 'wp_slash' );

		$tokens = new Tokens();

		$this->assertSame( 1, $tokens->detach_from_partials() );
		$this->assertSame( '<header><nav></nav></header>', stripslashes( $updates[7] ), 'only the marked style block goes' );

		$partial->post_content = '<header><nav></nav></header>';
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $partial ) );
		$this->assertSame( 0, $tokens->detach_from_partials(), 'a second run rewrites nothing' );
	}
}
