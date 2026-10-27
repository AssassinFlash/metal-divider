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
	 * The project-gallery lightbox starts on the first project's real values.
	 *
	 * Each of these is a fact the template already carries in its first
	 * project card, so leaving it blank made the server withhold something it
	 * knew. The counter must agree with the first card's own `data-images`
	 * length: the three projects with two images and the twenty with three
	 * were restored from the old site's own gallery (see the count guard
	 * below), so the lightbox opens on `1 / 3` for this project, not on a
	 * literal that can drift away from the data.
	 */
	public function test_the_gallery_lightbox_is_seeded_with_real_values(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$html = (string) file_get_contents( Templates::path( 'project-gallery' ) );

		$this->assertMatchesRegularExpression(
			'/<img class="lightbox-image"\s+src="\/wp-content\/uploads\/2026\/10\/our-park-hyatt-mesh-screen-hero-2048w\.webp"/',
			$html,
			'the stage image is the first card\'s own image'
		);
		$this->assertMatchesRegularExpression(
			'/class="lightbox-image"[^>]*\bwidth="2048"\s+height="2048"/s',
			$html,
			'the stage image declares the attachment\'s real dimensions'
		);
		$this->assertMatchesRegularExpression(
			'/class="lightbox-image"[^>]*\balt="Park Hyatt Shenzhen Lobby Partitions[^"]*"/s',
			$html,
			'the stage image carries the project\'s written alt'
		);
		$this->assertMatchesRegularExpression(
			'/<div class="lightbox-title">Park Hyatt Shenzhen Lobby Partitions<\/div>/',
			$html,
			'the info bar starts on the first project\'s name'
		);
		$this->assertMatchesRegularExpression(
			'/<div class="lightbox-counter">1 \/ 3<\/div>/',
			$html,
			'the counter states the first project\'s real image count'
		);
		$this->assertMatchesRegularExpression(
			'/class="lightbox-image"[^>]*\bloading="lazy"/s',
			$html,
			'the hidden dialog does not eagerly download its initial 2048w image'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<div class="lightbox-(?:title|subtitle|counter)"><\/div>/',
			$html,
			'no info-bar slot is left blank for the script to fill'
		);
	}

	/**
	 * Every gallery card's badge states the number of images it actually has.
	 *
	 * This is the guard for the one drift that shipped: the converted template
	 * carried the old site's badge numbers (2 and 3) while `data-images` held a
	 * single URL, so the badge promised pictures the lightbox could not show.
	 * The counts themselves are real — the old site's gallery carried hero,
	 * detail and wide for each project, and the media pipeline had imported all
	 * sixty-four files; only the card data had been trimmed.
	 *
	 * Nothing else keeps the two in step: the badge is hand-written markup and
	 * `data-images` is a hand-written attribute, so a future edit to one
	 * silently desynchronises the other. Reading both out of the same card
	 * block makes the drift a test failure instead.
	 */
	public function test_every_gallery_card_badge_matches_its_image_count(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$html = (string) file_get_contents( Templates::path( 'project-gallery' ) );

		$starts = array();
		// The card div may carry other attributes before `class` (role, data-*),
		// so match the class anywhere in the opening tag rather than assuming it
		// comes first. Anchoring on `<div\s+class=` silently found zero cards once
		// a `role` attribute was added ahead of it.
		if ( preg_match_all( '/<div\b[^>]*\bclass="project-card"/', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$starts[] = (int) $match[1];
			}
		}
		$this->assertCount( 23, $starts, 'the gallery ships twenty-three project cards' );

		foreach ( $starts as $index => $start ) {
			$end   = $starts[ $index + 1 ] ?? strlen( $html );
			$block = substr( $html, $start, $end - $start );

			$title = '';
			if ( 1 === preg_match( '/data-title="([^"]*)"/', $block, $matched_title ) ) {
				$title = $matched_title[1];
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
	}
}
