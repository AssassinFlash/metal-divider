<?php
/**
 * The journal renders inside the site's own chrome, on the site's own surface.
 *
 * @package MetalDivider\Site\Tests\Unit\Presentation
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The four blog faces are the de-linker fork of a separate publication's
 * layout (spec 19 §2.2 R1). Two things came with that fork and do not belong
 * on this site, and both were measured before they were removed:
 *
 * 1. **A second header.** The discovery faces opened with their own brand
 *    block — `journal-home` (an `<a>` reading "Metal Divider Journal" behind a
 *    gold `M` mark) — and the reader face opened with a `reader-topbar`
 *    carrying a "Back to blog" link and a journal-only search box. The site
 *    header already renders above all four (LiveCanvas's `is_header` partial),
 *    so those blocks were a second, smaller header directly under the real
 *    one, with its own search that could not search patterns.
 *
 * 2. **A separate surface.** The fork carried de-linker's light reader
 *    palette: `#f7f8f8` page ground with `#fff` panels and `#5a6363` ink.
 *    Measured at 1440 on 2026-10-31: on `/` the site's white header sits on
 *    #141414, and on `/blog/` the same header sat on #f7f8f8 for the whole
 *    page height. A pixel diff of the two header bands is identical to y=59
 *    and diverges from y=60 — the header was never different, the ground under
 *    it was. The owner read it as a different header, which is the outcome the
 *    palette caused.
 *
 * These guards are stated as properties, so the journal can still be styled
 * freely as long as it stays inside the site's chrome and on the site's
 * surface.
 *
 * @coversNothing
 */
final class JournalChromeTest extends TestCase {

	/**
	 * The discovery faces.
	 *
	 * @var array<int, string>
	 */
	private const DISCOVERY = array( 'blog-index', 'blog-category', 'blog-search' );

	/**
	 * No blog face renders a header of its own.
	 *
	 * The site header is the `is_header` partial and renders on every request;
	 * a second one in the template is duplicated chrome, a duplicated landmark
	 * for a screen reader, and a second search box that searches something
	 * else. The assertion names the two blocks by the classes they carried, so
	 * the failure message says which one came back.
	 */
	public function test_no_blog_face_carries_its_own_header(): void {
		foreach ( array_merge( self::DISCOVERY, array( 'blog-single' ) ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$markup = (string) preg_replace( '/<!--.*?-->/s', '', $source );

			$this->assertStringNotContainsString( 'journal-home', $markup, "{$face}: the journal's own brand block is gone; the site header is the only one" );
			$this->assertStringNotContainsString( 'journal-mark', $markup, "{$face}: and its gold mark with it" );
			$this->assertStringNotContainsString( 'reader-topbar', $markup, "{$face}: the reader's second top bar is gone" );
			$this->assertStringNotContainsString( 'reader-search', $markup, "{$face}: so is its journal-only search; the site header carries the site search" );
		}
	}

	/**
	 * The reader keeps its article header.
	 *
	 * `#md-blog-single-hero` is the article's own title-and-cover block, not
	 * site chrome — it is the `<header>` of the `<article>`, which is correct
	 * markup and is what the removal above must not take with it.
	 */
	public function test_the_reader_keeps_its_article_header(): void {
		$source = (string) file_get_contents( Templates::path( 'blog-single' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( '<article id="md-blog-single">', $source, 'the article element is the reader face' );
		$this->assertStringContainsString( '<header id="md-blog-single-hero"', $source, 'the article keeps its own header' );
		$this->assertStringContainsString( '<h1 class="md-blog-single-hero-title">', $source, 'with the post title inside it' );
	}

	/**
	 * The journal sits on the site's surface, not on the fork's light one.
	 *
	 * All four faces declare the same surface variables and read them, so a
	 * future palette change is one block rather than a hunt through two
	 * volumes. The guard checks both halves: the variables are declared against
	 * the site's tokens (not the old literal), and the face paints itself from
	 * them.
	 */
	public function test_the_journal_paints_the_site_surface(): void {
		foreach ( array( 'blog-index', 'blog' ) as $volume ) {
			$css = (string) file_get_contents( $this->volume_path( $volume . '.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$css = (string) preg_replace( '/\/\*.*?\*\//s', '', $css );

			$this->assertMatchesRegularExpression( '/--journal-surface:\s*var\(--md-bg-deep\)/', $css, "{$volume}.css: the journal's ground is the site's deep surface" );
			$this->assertMatchesRegularExpression( '/--journal-panel:\s*var\(--md-bg\)/', $css, "{$volume}.css: its raised panels are the site's raised surface" );
			$this->assertMatchesRegularExpression( '/background:\s*var\(--journal-surface\)/', $css, "{$volume}.css: and the face paints itself from it" );
			$this->assertStringNotContainsString( '#f7f8f8', $css, "{$volume}.css: the fork's light page ground is gone" );
		}
	}

	/**
	 * No blog volume keeps a light-surface literal in a rule.
	 *
	 * The fork's palette was spread over four files as hex literals: a page
	 * ground, white panels, a hairline and two greys. Reading the colours from
	 * the surface variables is what makes the four faces one object; a literal
	 * that survives is a rule still painting the old page.
	 */
	public function test_no_blog_volume_keeps_a_light_surface_literal(): void {
		$literals = array( '#f7f8f8', '#e2e5e5', '#5a6363', '#c8cdcd', '#eef0f0', '#ffffff' );
		foreach ( array( 'blog.css', 'blog-index.css', 'blog-category.css', 'blog-search.css' ) as $volume ) {
			$css = (string) preg_replace(
				'/\/\*.*?\*\//s',
				'',
				(string) file_get_contents( $this->volume_path( $volume ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			);
			foreach ( $literals as $literal ) {
				$this->assertStringNotContainsString( $literal, strtolower( $css ), "{$volume}: {$literal} is a light-surface value; the journal reads the surface variables" );
			}
		}
	}

	/**
	 * The removed top bar's rules went with it.
	 *
	 * A stylesheet that still carries rules for markup no template emits is
	 * dead weight in the compiled cache, and it is how a removed block quietly
	 * comes back.
	 */
	public function test_the_reader_volume_no_longer_styles_the_removed_top_bar(): void {
		$css = (string) preg_replace(
			'/\/\*.*?\*\//s',
			'',
			(string) file_get_contents( $this->volume_path( 'blog.css' ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		);

		$this->assertStringNotContainsString( '.reader-topbar', $css, 'the top bar is gone from the markup; its rules go too' );
		$this->assertStringNotContainsString( '.reader-search', $css, 'and its journal-only search box' );
		$this->assertStringNotContainsString( '.reader-back', $css, 'and its back link' );
	}

	/**
	 * A volume's path, under the plugin's content directory.
	 *
	 * @param string $name The file name.
	 */
	private function volume_path( string $name ): string {
		return dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress/' . $name;
	}
}
