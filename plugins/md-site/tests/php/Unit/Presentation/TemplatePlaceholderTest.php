<?php
/**
 * Every customer-facing template ships real values, never a placeholder a
 * server could already fill.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The converted markup carried placeholder values for facts the server knows.
 *
 * The project-gallery lightbox was the last one: its stage image shipped
 * `src=""` with an empty `alt`, and its info bar shipped an empty title, an
 * empty subtitle and an empty counter — while the template itself already
 * carried that project's image, name and subtitle in the first card's
 * `data-` attributes. A visitor who opened the dialog before the interaction
 * layer ran (or with the script blocked) saw an empty frame, and the server
 * had every value needed to draw a real one.
 *
 * The existing guard for this class of defect lived in `CardsTest`, which
 * iterates `Partials::names()` only — the card partials. Page templates were
 * never scanned, which is why the gallery's placeholder survived it. This
 * guard covers the rest of the inventory: the page-type faces and the
 * eighteen per-page faces, alongside the partials.
 *
 * HTML comments are stripped before matching: a `src=""` quoted inside a
 * comment is prose about the defect, not an attribute the browser parses, and
 * matching it would make the guard refuse its own documentation.
 *
 * @coversNothing
 */
final class TemplatePlaceholderTest extends TestCase {

	/**
	 * Every exported customer-facing template and partial, absolute paths.
	 *
	 * @return list<string>
	 */
	private function every_template(): array {
		$reflection = new \ReflectionClass( Templates::class );
		$paths      = array();

		$files = (array) $reflection->getConstant( 'FILES' );
		foreach ( $files as $name ) {
			$paths[] = Templates::path( (string) $name );
		}

		$pages = (array) $reflection->getConstant( 'PAGES' );
		foreach ( $pages as $entry ) {
			$paths[] = Templates::path( (string) $entry[0] );
		}

		$partials = (array) $reflection->getConstant( 'PARTIALS' );
		foreach ( array_keys( $partials ) as $relative ) {
			$paths[] = Templates::path( (string) $relative, true );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * No template ships an empty image source.
	 *
	 * An empty `src` is not a neutral default: the browser resolves it to the
	 * page's own URL and issues a second request for the document as if it
	 * were an image, and assistive technology announces an image with no
	 * content. Every image this site renders points at a real upload.
	 *
	 * `alt=""` is deliberately NOT banned here: an empty alt is the correct,
	 * WCAG-mandated value for a decorative image, and this site's dark
	 * sections carry background photographs that are decorative by design
	 * (`absolute inset-0 object-cover`). Banning it would fail 36 correct
	 * images. The card partials ban it separately because every image there
	 * is content and carries a written alt.
	 */
	public function test_no_template_ships_an_empty_image_source(): void {
		$patterns = array(
			'src'      => '/\bsrc\s*=\s*(""|\'\')/',
			'srcset'   => '/\bsrcset\s*=\s*(""|\'\')/',
			'data-src' => '/\bdata-src\s*=\s*(""|\'\')/',
		);

		foreach ( $this->every_template() as $path ) {
			$name = basename( $path );
			$this->assertFileExists( $path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$source = (string) file_get_contents( $path );
			$markup = (string) preg_replace( '/<!--.*?-->/s', '', $source );

			foreach ( $patterns as $attribute => $pattern ) {
				$this->assertDoesNotMatchRegularExpression(
					$pattern,
					$markup,
					$name . ' must not ship an empty ' . $attribute
				);
			}
		}
	}

	/**
	 * The project-gallery lightbox omits its initial image `src` and ships a
	 * visitor-safe fallback and demand-driven runtime.
	 *
	 * The dialog starts hidden: seeding a static `2048w` `src` made every
	 * `/project-gallery/` visit download ~944 KB before anyone opened a
	 * lightbox, while an empty `src=""` triggers a duplicate document request.
	 * Omitting `src` entirely lets `md-chrome.js` assign the selected card's
	 * `1536w` display URL on open (falling back to `2048w` on error), preload
	 * only the next slide instead of the whole set, and offer Retry (`mdretry`),
	 * WhatsApp and Inquiry actions when loading fails — never internal editor
	 * copy. The info bar still starts on the first project's real title and
	 * `1 / 3` counter so no text slot is left blank.
	 */
	public function test_the_gallery_lightbox_is_seeded_with_real_values(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$html   = (string) file_get_contents( Templates::path( 'project-gallery' ) );
		$markup = (string) preg_replace( '/<!--.*?-->/s', '', $html );

		if ( 1 !== preg_match( '/<img\b[^>]*\bclass="lightbox-image"[^>]*>/s', $markup, $matched_img ) ) {
			$this->fail( 'the gallery lightbox carries a stage image element' );
		}
		$img_tag = $matched_img[0];

		$this->assertDoesNotMatchRegularExpression(
			'/\bsrc\s*=/i',
			$img_tag,
			'the hidden stage image omits src (no empty src and no initial 2048w preload)'
		);
		$this->assertMatchesRegularExpression(
			'/\bwidth="1536"\s+height="1536"/',
			$img_tag,
			'the stage image declares the 1536w display dimensions'
		);
		$this->assertMatchesRegularExpression(
			'/\bloading="lazy"/',
			$img_tag,
			'the hidden stage image declares lazy loading'
		);
		$this->assertMatchesRegularExpression(
			'/\bdecoding="async"/',
			$img_tag,
			'the stage image decodes asynchronously'
		);
		$this->assertMatchesRegularExpression(
			'/\balt="Park Hyatt Shenzhen Lobby Partitions[^"]*project image[^"]*"/i',
			$img_tag,
			'the stage image carries the project\'s written project-image alt'
		);
		$this->assertMatchesRegularExpression(
			'/<div class="lightbox-title">Park Hyatt Shenzhen Lobby Partitions<\/div>/',
			$markup,
			'the info bar starts on the first project\'s name'
		);
		$this->assertMatchesRegularExpression(
			'/<div class="lightbox-counter">1 \/ 3<\/div>/',
			$markup,
			'the counter states the first project\'s real image count'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<div class="lightbox-(?:title|subtitle|counter)"><\/div>/',
			$markup,
			'no info-bar slot is left blank for the script to fill'
		);

		$fallback_start = strpos( $markup, '<div class="lightbox-fallback"' );
		$this->assertNotFalse( $fallback_start, 'the lightbox carries a fallback block' );
		$info_start = strpos( $markup, '<div class="lightbox-info"', $fallback_start );
		$this->assertNotFalse( $info_start, 'the lightbox info bar follows the fallback block' );
		$fallback_block = substr( $markup, $fallback_start, $info_start - $fallback_start );

		$this->assertStringNotContainsString(
			'replace the image in the editor',
			$fallback_block,
			'the fallback copy never exposes internal editor instructions'
		);
		$this->assertStringContainsString(
			'Preview image took too long to load',
			$fallback_block,
			'the fallback shows visitor-friendly timeout guidance'
		);
		$this->assertStringContainsString(
			'lightbox-fallback__actions',
			$fallback_block,
			'the fallback renders the action bar'
		);
		$this->assertStringContainsString(
			'data-lightbox-retry',
			$fallback_block,
			'the fallback exposes a Retry button'
		);
		$this->assertStringContainsString(
			'wa.me/85264731687',
			$fallback_block,
			'the fallback exposes a WhatsApp action'
		);
		$this->assertStringContainsString(
			'href="/inquiry/"',
			$fallback_block,
			'the fallback exposes an Inquiry action'
		);

		$chrome_path = dirname( __DIR__, 4 ) . '/assets/js/md-chrome.js';
		$this->assertFileExists( $chrome_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$chrome_js = (string) file_get_contents( $chrome_path );
		$start     = strpos( $chrome_js, 'function setupGallery()' );
		$this->assertNotFalse( $start, 'md-chrome.js defines setupGallery()' );
		$end          = strpos( $chrome_js, "\n  function ", $start + 10 );
		$gallery_body = false === $end ? $chrome_js : substr( $chrome_js, $start, $end - $start );

		$this->assertStringNotContainsString(
			'currentImages.forEach',
			$gallery_body,
			'setupGallery() must not eagerly preload every image in the project'
		);
		$this->assertStringContainsString(
			'displayUrl',
			$gallery_body,
			'setupGallery() downgrades 2048w stage URLs via displayUrl()'
		);
		$this->assertStringContainsString(
			'withRetryParam',
			$gallery_body,
			'setupGallery() rewrites retry cache-busting query via withRetryParam()'
		);
		$this->assertStringContainsString(
			'preloadNext',
			$gallery_body,
			'setupGallery() preloads only the next image via preloadNext()'
		);
		$this->assertStringContainsString(
			'mdretry',
			$gallery_body,
			'setupGallery() cache-busts manual retries with mdretry'
		);
		$this->assertStringContainsString(
			'fallbackTried',
			$gallery_body,
			'setupGallery() tracks 1536w -> 2048w fallback state'
		);
		$this->assertStringContainsString(
			'loadToken',
			$gallery_body,
			'setupGallery() guards async image events with loadToken'
		);
		$this->assertStringContainsString(
			'activeRetryTs',
			$gallery_body,
			'setupGallery() retains retry timestamp across fallback'
		);
		$this->assertStringContainsString(
			'data-lightbox-retry',
			$gallery_body,
			'setupGallery() binds the Retry action button'
		);

		$gallery_css_path = dirname( __DIR__, 4 ) . '/content/windpress/gallery.css';
		$this->assertFileExists( $gallery_css_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$gallery_css = (string) file_get_contents( $gallery_css_path );
		foreach ( array(
			'.lightbox-image[hidden]',
			'.lightbox-fallback[hidden]',
			'.lightbox-fallback__actions',
			'.lightbox-fallback__btn',
			'.lightbox-fallback__btn--retry',
			'.lightbox-fallback__btn--wa',
			'.lightbox-fallback__btn--inquiry',
			'min-height: 44px',
		) as $css_needle ) {
			$this->assertStringContainsString(
				$css_needle,
				$gallery_css,
				'gallery.css ships lightbox fallback rule: ' . $css_needle
			);
		}
	}

	/**
	 * Every gallery card's badge states the number of images it actually has,
	 * and initial paging is pre-applied in HTML to eliminate layout jump.
	 *
	 * This is the guard for the one drift that shipped: the converted template
	 * carried the old site's badge numbers (2 and 3) while `data-images` held a
	 * single URL, so the badge promised pictures the lightbox could not show.
	 * The counts themselves are real — the old site's gallery carried hero,
	 * detail and wide for each project, and the media pipeline had imported all
	 * sixty-four files; only the card data had been trimmed.
	 *
	 * It also locks the zero-jump pagination contract: the first 9 cards render
	 * without `is-paged-out`, cards 10–23 carry `is-paged-out` statically (gated
	 * in CSS on `@media (scripting: enabled)` and `html.md-js` so no-JS visitors
	 * still see all 23 cards), and `#loadMoreWrap` omits a static `hidden`
	 * attribute.
	 */
	public function test_every_gallery_card_badge_matches_its_image_count(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$html = (string) file_get_contents( Templates::path( 'project-gallery' ) );

		$starts  = array();
		$classes = array();
		// The card div may carry other attributes before `class` (role, data-*),
		// and cards 10–23 also carry `is-paged-out` for zero-jump first paint.
		if ( preg_match_all( '/<div\b[^>]*\bclass="(project-card(?:\s+[^"]*)?)"/', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $i => $match ) {
				$starts[]  = (int) $match[1];
				$classes[] = (string) $matches[1][ $i ][0];
			}
		}
		$this->assertCount( 23, $starts, 'the gallery ships twenty-three project cards' );

		$initial_visible = 0;
		$paged_out       = 0;

		foreach ( $starts as $index => $start ) {
			$end   = $starts[ $index + 1 ] ?? strlen( $html );
			$block = substr( $html, $start, $end - $start );

			$title = '';
			if ( 1 === preg_match( '/data-title="([^"]*)"/', $block, $matched_title ) ) {
				$title = $matched_title[1];
			}

			$card_classes = preg_split( '/\s+/', trim( $classes[ $index ] ) );
			$is_paged_out = is_array( $card_classes ) && in_array( 'is-paged-out', $card_classes, true );
			if ( $index < 9 ) {
				$this->assertFalse(
					$is_paged_out,
					sprintf( 'card #%d (%s) must not carry is-paged-out', $index + 1, $title )
				);
				++$initial_visible;
			} else {
				$this->assertTrue(
					$is_paged_out,
					sprintf( 'card #%d (%s) must carry static is-paged-out for zero-jump first paint', $index + 1, $title )
				);
				++$paged_out;
			}

			// Each capture is guarded before its group is read. preg_match()
			// returns int|false and leaves the match array EMPTY on no match, so
			// reading group 1 without a guard is an undefined offset: a PHP
			// warning on a genuinely broken template instead of the message that
			// names the card. fail() is never-returning, so the guard both stops
			// the test and leaves the capture known-populated to static analysis.
			if ( 1 !== preg_match( '/data-images="([^"]*)"/', $block, $matched_images ) ) {
				$this->fail( $title . ' carries data-images' );
			}
			$urls = array_values( array_filter( array_map( 'trim', explode( '|', $matched_images[1] ) ) ) );
			$this->assertNotEmpty( $urls, $title . ' lists at least one image' );

			$count = count( $urls );

			if ( 1 !== preg_match( '/aria-label="(\d+) images?"/', $block, $matched_label ) ) {
				$this->fail( $title . ' carries the badge\'s aria-label' );
			}
			$this->assertSame(
				$count,
				(int) $matched_label[1],
				sprintf( '%s: the badge aria-label says %s but data-images holds %d', $title, $matched_label[1], $count )
			);

			if ( 1 !== preg_match( '/<\/svg>\s*(\d+)\s*<\/div>/', $block, $matched_visible ) ) {
				$this->fail( $title . ' carries the badge\'s visible number' );
			}
			$this->assertSame(
				$count,
				(int) $matched_visible[1],
				sprintf( '%s: the badge shows %s but data-images holds %d', $title, $matched_visible[1], $count )
			);

			// Every listed image is a WebP under this site's own uploads: the
			// uploads plane is WebP only (spec 15 §2.1), and a remote or
			// non-WebP address is a name no build produces.
			foreach ( $urls as $url ) {
				$this->assertMatchesRegularExpression(
					'#^/wp-content/uploads/\\d{4}/\\d{2}/[^?]+\.webp$#',
					$url,
					$title . ' lists a local WebP upload'
				);
			}
		}

		$this->assertSame( 9, $initial_visible, 'the first nine gallery cards start unpaged' );
		$this->assertSame( 14, $paged_out, 'cards 10–23 start statically paged out' );

		if ( 1 !== preg_match( '/<div\b[^>]*\bid="loadMoreWrap[^"]*"[^>]*>/', $html, $matched_load_more ) ) {
			$this->fail( 'the gallery ships a #loadMoreWrap container' );
		}
		$this->assertDoesNotMatchRegularExpression(
			'/\bhidden\b/i',
			$matched_load_more[0],
			'#loadMoreWrap must not carry a static hidden attribute'
		);

		$gallery_css_path = dirname( __DIR__, 4 ) . '/content/windpress/gallery.css';
		$this->assertFileExists( $gallery_css_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$gallery_css = (string) file_get_contents( $gallery_css_path );
		$this->assertMatchesRegularExpression(
			'/\.project-card\.is-filtered-out\s*\{\s*display:\s*none\s*!important;\s*\}/s',
			$gallery_css,
			'gallery.css keeps .is-filtered-out unconditional'
		);
		$this->assertMatchesRegularExpression(
			'/html\.md-js\s+#project-gallery-[^\s{]+\s+\.project-card\.is-paged-out\s*\{\s*display:\s*none\s*!important;\s*\}/s',
			$gallery_css,
			'gallery.css gates .is-paged-out on html.md-js'
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(scripting:\s*enabled\)\s*\{\s*#project-gallery-[^\s{]+\s+\.project-card\.is-paged-out\s*\{\s*display:\s*none\s*!important;\s*\}\s*\}/s',
			$gallery_css,
			'gallery.css gates .is-paged-out on @media (scripting: enabled)'
		);
		$this->assertMatchesRegularExpression(
			'/\.load-more-wrap\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/s',
			$gallery_css,
			'gallery.css lets [hidden] override display:flex on .load-more-wrap'
		);
		$this->assertMatchesRegularExpression(
			'/@media\s*\(scripting:\s*none\)\s*\{\s*#project-gallery-[^\s{]+\s+\.load-more-wrap\s*\{\s*display:\s*none\s*!important;\s*\}\s*\}/s',
			$gallery_css,
			'gallery.css hides .load-more-wrap when scripting is disabled'
		);
	}
}
