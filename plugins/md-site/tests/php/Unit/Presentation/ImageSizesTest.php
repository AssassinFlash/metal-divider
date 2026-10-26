<?php
/**
 * Every responsive image in the LiveCanvas templates declares its sizes.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Two rules over every LiveCanvas template and partial, with no allowlist
 * and no tolerated count:
 *
 * 1. A width-descriptor `srcset` always carries a `sizes`. Without one the
 *    browser assumes `100vw`: `our-park-hyatt-mesh-screen-hero` shipped that
 *    way and downloaded a 943 KB 2048w file for a 326x204 box, because
 *    1440 CSS pixels at 2x looked like 2880 to the selection algorithm.
 *    Density sets (`1x`, `2x`) state their scale in the descriptor and are
 *    exempt by construction.
 *
 * 2. An `<img>` pointing at an upload that has a width ladder offers it.
 *    A bare `…-2048w.webp` is handed to a 320px phone exactly as readily as
 *    an unsized `srcset` hands it a wide candidate. The checks are silent
 *    otherwise: nothing else in the build notices either defect.
 *
 * The exemptions below are evidence, not convenience, and each is re-proved
 * by `test_the_documented_exceptions_are_still_necessary()` so none rots.
 *
 * @coversNothing
 */
final class ImageSizesTest extends TestCase {

	/**
	 * `<img>` elements that are fallback content inside a `<video>`.
	 *
	 * A video's `poster` attribute cannot carry a `srcset` — the grammar has
	 * no such attribute — so the poster always fetches its one file. The
	 * inner `<img src>` names that same file, and the browser serves both
	 * from one cache entry: measured on the outdoor-privacy face at 390px,
	 * each poster file is requested exactly once.
	 *
	 * Adding a `srcset` to the inner image would therefore cost more, not
	 * less: at any width where the ladder picks something other than the
	 * poster's own file, the browser fetches the poster *and* a second,
	 * different image — the dual download these entries exist to prevent.
	 * The correct fix is not a `srcset` but dropping the redundant inner
	 * `<img>` or giving the poster a ladder, and neither exists in HTML for
	 * `poster`, so the duplicate stays.
	 *
	 * @var list<string> uploads file names.
	 */
	private const VIDEO_POSTER_FALLBACKS = array(
		'00af6f1ec2da47bfbb8e296ba63de4f1-thumbnail-0000000000-2200x-398w.webp',
		'09254a60368d4d1e87ddf4bec24378d8-thumbnail-0000000000-1100x-720w.webp',
		'63ec62970a9a4ffebeaa1befe9ace3c8-thumbnail-0000000000-1100x-720w.webp',
		'9163b183271e41c698d020c9412e2945-thumbnail-0000000000-1100x-720w.webp',
		'aa6c9f5ed26e4e199234d89d3f1480e1-thumbnail-0000000000-1100x-720w.webp',
	);

	/**
	 * Uploads that have exactly one final width, so a `srcset` would be a
	 * one-candidate list that selects nothing.
	 *
	 * Every entry was checked against the media pipeline's output; the test
	 * re-proves each still has one width and would fail if the pipeline ever
	 * gained tiers for it.
	 *
	 * @var list<string> asset stems (the file name without `-{width}w.webp`).
	 */
	private const SINGLE_TIER_STEMS = array(
		// Finish swatches: 320px chips, sampled once.
		'ext-finish-anodised-champagne',
		'ext-finish-brushed-hairline',
		'ext-finish-pvd-champagne-gold',
		'ext-finish-pvd-matte-black',
		'ext-finish-pvd-rose-gold',
		// Spec-panel sub image: only 480w was produced.
		'our-perforated-metal-screen-detail',
	);

	/**
	 * The project-gallery lightbox placeholder.
	 *
	 * Its `src` is overwritten by `md-chrome.js` (`image.src = nextSrc`) with
	 * whichever project the visitor opens, so the element never shows the
	 * asset named in the markup and no single `srcset` can describe it. A
	 * `srcset` here would also take precedence over the assigned `src` per
	 * the HTML spec and pin the lightbox to one image.
	 *
	 * This element is nonetheless a real, separate defect: it is
	 * `loading="eager"` while its dialog is hidden, so every project-gallery
	 * visit downloads the 2048w placeholder (944 KB measured 2026-10-26)
	 * before anyone opens a lightbox. `project-gallery.html` is another
	 * session's face, so that is reported rather than edited here.
	 *
	 * @var list<string> uploads file names.
	 */
	private const JAVASCRIPT_MANAGED_IMAGES = array(
		'our-park-hyatt-mesh-screen-hero-2048w.webp',
	);

	/**
	 * Every template and partial the inventory names.
	 *
	 * @return array<string, string> absolute path => repository-relative label.
	 */
	private function sources(): array {
		$reflection = new \ReflectionClass( Templates::class );
		$sources    = array();
		foreach ( array( 'FILES', 'PAGES', 'PARTIALS' ) as $constant ) {
			$table = $reflection->getConstant( $constant );
			foreach ( $table as $key => $entry ) {
				// FILES maps a request condition to a file name; PAGES maps a
				// template slug to ( file, title, page slug ); PARTIALS maps
				// the file itself to ( slug, title, assignment ).
				$file = 'PARTIALS' === $constant
					? (string) $key
					: ( 'FILES' === $constant ? (string) $entry : (string) $entry[0] );
				$sources[ Templates::path( $file, 'PARTIALS' === $constant ) ] = $file;
			}
		}
		return $sources;
	}

	/**
	 * The attributes of one tag, keyed by lower-cased name.
	 *
	 * A real attribute scan, not a substring search, for two reasons the
	 * templates force:
	 *
	 * - Both quote styles ship. The converted faces are double-quoted, but
	 *   the carried chrome is single-quoted (`xo-srcset='…' sizes='323px'`
	 *   throughout `partials/header.html`), and a regex that only matched
	 *   `"` saw none of it.
	 * - Names that merely contain `srcset` or `sizes` exist and mean
	 *   something else: `image_srcset` and `md_pattern_image_srcset` are
	 *   Tangible/plugin *variables* interpolated into the tag, not HTML
	 *   attributes, and LiveCanvas's own `[md_pattern_image_srcset …]`
	 *   shortcode emits the attribute at render time. Matching them would
	 *   report a defect that is not there.
	 *
	 * So the name must match exactly — `srcset`, `xo-srcset`, `sizes`,
	 * `xo-sizes` — which the `([a-z-]+)` capture enforces by refusing the
	 * underscore in the lookalikes.
	 *
	 * @param string $tag One `<img …>` tag.
	 *
	 * @return array<string, string> lower-cased attribute name => raw value.
	 */
	private function attributes( string $tag ): array {
		// The leading `\s` is load-bearing: without it the name capture
		// happily matches the tail of a longer name, reading `srcset=` out of
		// `image_srcset=` and inventing a defect the template does not have.
		// In a real tag every attribute follows whitespace.
		preg_match_all( '/(?<=\s)([a-z][a-z0-9-]*)\s*=\s*(["\'])(.*?)\2/is', $tag, $matches, PREG_SET_ORDER );
		$attributes = array();
		foreach ( $matches as $match ) {
			$attributes[ strtolower( $match[1] ) ] = $match[3];
		}
		return $attributes;
	}

	/**
	 * The responsive source-set of one image, whichever attribute carries it.
	 *
	 * `xo-srcset` is this site's own deferred form: `md-chrome.js` copies it
	 * onto `srcset` at runtime (`image.srcset = srcset`, md-chrome.js:885),
	 * so a width-descriptor `xo-srcset` needs a `sizes` for exactly the same
	 * reason a `srcset` does — without one the browser falls back to
	 * `100vw` the moment the handler runs.
	 *
	 * @param array<string, string> $attributes The tag's attributes.
	 *
	 * @return string|null The source set, or null when the tag has none.
	 */
	private function source_set( array $attributes ): ?string {
		foreach ( array( 'srcset', 'xo-srcset' ) as $name ) {
			if ( isset( $attributes[ $name ] ) && '' !== trim( $attributes[ $name ] ) ) {
				return $attributes[ $name ];
			}
		}
		return null;
	}

	/**
	 * The `<img>` tags of one template that carry a width-descriptor source
	 * set, split into those that declare `sizes` and those that do not.
	 *
	 * @param string $path Absolute path of a template.
	 *
	 * @return array{with: int, without: list<string>} Counts and the
	 *         offending `src` values.
	 */
	private function responsive_images( string $path ): array {
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		preg_match_all( '/<img\b[^>]*>/i', $source, $tags );

		$with    = 0;
		$without = array();
		foreach ( $tags[0] as $tag ) {
			$attributes = $this->attributes( $tag );
			$srcset     = $this->source_set( $attributes );
			if ( null === $srcset ) {
				continue;
			}
			// A density set states the scale in the descriptor; `sizes`
			// would be meaningless for it.
			if ( preg_match( '/\s\d+(?:\.\d+)?x\b/', $srcset ) ) {
				continue;
			}
			if ( isset( $attributes['sizes'] ) ) {
				++$with;
				continue;
			}
			$raw       = $attributes['src'] ?? $attributes['xo-src'] ?? '';
			$without[] = basename( $raw );
		}
		return array(
			'with'    => $with,
			'without' => $without,
		);
	}

	/**
	 * Rule 1: no width-descriptor `srcset` anywhere lacks a `sizes`.
	 */
	public function test_no_width_descriptor_srcset_lacks_sizes(): void {
		$scanned    = 0;
		$responsive = 0;
		foreach ( $this->sources() as $path => $file ) {
			$found = $this->responsive_images( $path );
			++$scanned;
			$responsive += $found['with'] + count( $found['without'] );

			$this->assertSame(
				array(),
				$found['without'],
				"{$file} has width-descriptor srcsets with no sizes (the browser assumes 100vw): "
				. implode( ', ', $found['without'] )
			);
		}
		$this->assertGreaterThan( 20, $scanned, 'the scan walks the template inventory' );
		$this->assertGreaterThan( 400, $responsive, 'the scan sees the site\'s responsive images' );
	}

	/**
	 * Rule 2: no bare upload image where the asset has a ladder.
	 *
	 * The exemptions are the two documented classes, plus assets uploads
	 * holds a single width for (computed from disk, not listed).
	 */
	public function test_no_variant_backed_bare_upload_image_remains(): void {
		$held = $this->upload_widths();
		if ( array() === $held ) {
			$this->markTestSkipped( 'the instance uploads are not on this Mac (a local-only check)' );
		}

		$offenders = array();
		$checked   = 0;
		foreach ( $this->sources() as $path => $file ) {
			$label  = basename( $path );
			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			foreach ( $this->bare_upload_images( $source ) as $entry ) {
				++$checked;
				if ( $this->is_exempt( $entry['file'], $entry['stem'], $entry['in_video'], $held ) ) {
					continue;
				}
				$offenders[] = "{$label}: {$entry['file']}";
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'these images point at an upload that has a width ladder but offer none of it: '
			. implode( ', ', $offenders )
		);
		$this->assertGreaterThan( 20, $checked, 'the scan finds the bare upload images' );
	}

	/**
	 * Every documented exemption still describes a real, still-necessary
	 * case. An exemption that has become redundant fails, so the lists only
	 * shrink.
	 */
	public function test_the_documented_exceptions_are_still_necessary(): void {
		$held        = $this->upload_widths();
		$bare        = array();
		$video_names = array();
		foreach ( $this->sources() as $path => $label ) {
			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			foreach ( $this->bare_upload_images( $source ) as $entry ) {
				$bare[ $entry['file'] ]        = true;
				$video_names[ $entry['file'] ] = $video_names[ $entry['file'] ] ?? $entry['in_video'];
			}
		}

		foreach ( self::VIDEO_POSTER_FALLBACKS as $file ) {
			$this->assertArrayHasKey( $file, $bare, "{$file} is exempted as a video poster fallback but no template ships it bare" );
			$this->assertTrue( $video_names[ $file ], "{$file} is exempted as a video poster fallback but is not inside a <video>" );
		}

		foreach ( self::JAVASCRIPT_MANAGED_IMAGES as $file ) {
			$this->assertArrayHasKey( $file, $bare, "{$file} is exempted as JS-managed but no template ships it bare" );
		}

		if ( array() !== $held ) {
			foreach ( self::SINGLE_TIER_STEMS as $stem ) {
				$this->assertArrayHasKey( $stem, $held, "{$stem} is exempted as single-tier but uploads does not hold it" );
				$this->assertSame(
					1,
					$held[ $stem ],
					"{$stem} is exempted as single-tier but uploads now holds {$held[ $stem ]} widths — "
					. 'give it a srcset and drop the exemption'
				);
			}
		}
	}

	/**
	 * Whether one bare upload image falls under a documented exemption.
	 *
	 * @param string            $file     Uploads file name.
	 * @param string            $stem     Asset stem, the name without `-{width}w.webp`.
	 * @param bool              $in_video Whether the tag sits inside a `<video>`.
	 * @param array<string,int> $held   Asset stem => width count.
	 */
	private function is_exempt( string $file, string $stem, bool $in_video, array $held ): bool {
		if ( $in_video ) {
			return true;
		}
		if ( in_array( $file, self::JAVASCRIPT_MANAGED_IMAGES, true ) ) {
			return true;
		}
		if ( in_array( $stem, self::SINGLE_TIER_STEMS, true ) ) {
			return true;
		}
		// Computed, not listed: one width on disk means no ladder exists.
		return isset( $held[ $stem ] ) && $held[ $stem ] < 2;
	}

	/**
	 * The bare `<img>` tags of one template that point at an upload with a
	 * width-suffixed name.
	 *
	 * @param string $source Template source.
	 *
	 * @return list<array{file: string, stem: string, in_video: bool}> One entry per bare upload image.
	 */
	private function bare_upload_images( string $source ): array {
		// The byte ranges of every <video> element, so a tag can be told
		// apart from the video's fallback content.
		preg_match_all( '/<video\b[^>]*>.*?<\/video>/is', $source, $videos, PREG_OFFSET_CAPTURE );
		$range_starts = array();
		$range_ends   = array();
		foreach ( $videos[0] as $range ) {
			$range_starts[] = $range[1];
			$range_ends[]   = $range[1] + strlen( (string) $range[0] );
		}

		preg_match_all( '/<img\b[^>]*>/i', $source, $tags, PREG_OFFSET_CAPTURE );
		$found = array();
		foreach ( $tags[0] as $tag ) {
			list( $markup, $offset ) = $tag;
			$attributes              = $this->attributes( $markup );
			// Either source-set spelling counts as "offers a ladder"; both
			// quote styles are already normalised by attributes().
			if ( null !== $this->source_set( $attributes ) ) {
				continue;
			}
			// The deferred chrome images carry `xo-src`, not `src`.
			$raw = (string) ( $attributes['src'] ?? $attributes['xo-src'] ?? '' );
			if ( ! str_contains( $raw, '/wp-content/uploads/' ) ) {
				continue; // an icon or a logo, not an upload.
			}
			$file = basename( $raw );
			if ( ! preg_match( '/^(.*)-(\d+)w\.webp$/', $file, $stem ) ) {
				continue; // an upload without a width suffix: no ladder to offer.
			}

			$in_video = false;
			foreach ( $range_starts as $index => $start ) {
				if ( $offset >= $start && $offset < $range_ends[ $index ] ) {
					$in_video = true;
					break;
				}
			}

			$found[] = array(
				'file'     => $file,
				'stem'     => $stem[1],
				'in_video' => $in_video,
			);
		}
		return $found;
	}

	/**
	 * How many final widths uploads holds for each asset stem.
	 *
	 * Reads the instance's uploads directory — the finished set, the same
	 * bytes the deployer mirrors — so the check follows the real ladder
	 * rather than a list maintained by hand. Absent on a machine that has
	 * not built the local stack, hence the skip above.
	 *
	 * @return array<string, int> asset stem => number of width variants.
	 */
	private function upload_widths(): array {
		$home = getenv( 'MD_LOCAL_HOME' );
		$base = ( false === $home || '' === $home ? ( getenv( 'HOME' ) . '/metal-divider-local' ) : $home )
			. '/dev/wordpress/wp-content/uploads';
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$years = glob( $base . '/[0-9][0-9][0-9][0-9]/*', GLOB_ONLYDIR );
		if ( ! is_array( $years ) || array() === $years ) {
			return array();
		}
		$widths = array();
		foreach ( $years as $dir ) {
			foreach ( (array) glob( $dir . '/*.webp' ) as $file ) {
				if ( preg_match( '/^(.*)-(\d+)w\.webp$/', basename( (string) $file ), $matched ) ) {
					$widths[ $matched[1] ][ (int) $matched[2] ] = true;
				}
			}
		}
		return array_map( 'count', $widths );
	}

	/**
	 * The home face's most expensive slots, named one by one.
	 *
	 * Rule 1 proves a `sizes` exists; it cannot tell a correct one from
	 * `100vw` on a 326px card. These are the slots the anonymous baseline
	 * measured (2026-10-26, home ~4.2 MB) with the geometry each renders at,
	 * so widening a declaration past its real box fails here.
	 */
	public function test_the_high_cost_home_slots_declare_their_measured_width(): void {
		$home = (string) file_get_contents( Templates::path( 'home' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$expected = array(
			// Full-bleed hero backgrounds: the layer is inset:0 over the
			// viewport, so 100vw is exactly right.
			'ext-pp-hero-perforated-facade'      => '100vw',
			'our-aspen-villa-garden-screen-hero' => '100vw',
			// The spec-map card: 4 columns at 1280+, 2 at 768+, one below.
			'our-park-hyatt-mesh-screen-hero'    => '(min-width: 1400px) 330px',
			// The horizontal outcomes carousel: min-w-[82vw] slides.
			'ext-pp-cnc-turret-punching'         => '(min-width: 1024px) 692px',
			// The fixed-width factory scroller.
			'laser-cutter-working'               => '(min-width: 768px) 320px',
			// Marks the 24px process carousel thumbs.
			'ext-shop-drawing-review-480w'       => '(min-width: 768px) 56px',
		);

		foreach ( $expected as $needle => $fragment ) {
			$tag = $this->image_naming( $home, $needle );
			$this->assertNotNull( $tag, "the home face renders {$needle}" );
			$this->assertMatchesRegularExpression( '/\bsizes\s*=\s*"/i', $tag, "{$needle} declares sizes" );
			$this->assertStringContainsString(
				$fragment,
				$tag,
				"{$needle} declares its measured width ({$fragment})"
			);
		}
	}

	/**
	 * One `<img>` tag whose `src` names the given asset.
	 *
	 * @param string $source Template source.
	 * @param string $needle File-name fragment to match.
	 */
	private function image_naming( string $source, string $needle ): ?string {
		preg_match_all( '/<img\b[^>]*>/i', $source, $tags );
		foreach ( $tags[0] as $tag ) {
			if ( str_contains( $tag, $needle ) ) {
				return $tag;
			}
		}
		return null;
	}
}
