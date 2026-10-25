<?php
/**
 * Every asset a LiveCanvas template references exists in this repository.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The custom-screen faces' logos shipped broken: the templates pointed at
 * `/wp-content/uploads/stainless-steel-expert-logo--300w.jpg`, a name the
 * old site's CDN carried (`?width=300`) but the media pipeline never
 * produced — the pipeline emits WebP only, and the approved 1048x1046
 * JPEG was never in the corpus. WordPress answered that address with a
 * 301 to the homepage, so both logos rendered as alt text on every
 * viewport.
 *
 * The guard: an asset a template addresses under this plugin's own assets
 * directory is a repository file, so the build can prove it exists before
 * the deployment does; and the uploads plane is WebP only (spec 15 §2.1),
 * so a JPEG/PNG address there is a name no build will ever create.
 *
 * @coversNothing
 */
final class TemplateAssetsTest extends TestCase {

	/**
	 * The plugin's own assets, as the templates address them.
	 */
	private const PLUGIN_ASSETS = '/wp-content/plugins/md-site/';

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
				// FILES maps a request condition to the file name; PAGES maps
				// a template slug to ( file, title, page slug ); PARTIALS maps
				// the file itself to ( slug, title, assignment ).
				$file             = 'PARTIALS' === $constant
					? (string) $key
					: ( 'FILES' === $constant ? (string) $entry : (string) $entry[0] );
				$path             = Templates::path( $file, 'PARTIALS' === $constant );
				$sources[ $path ] = $file;
			}
		}
		return $sources;
	}

	/**
	 * The plugin root `Templates::path()` resolves against.
	 */
	private function plugin_root(): string {
		return dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../..';
	}

	/**
	 * No template addresses a plugin asset that is not in the repository.
	 */
	public function test_every_plugin_asset_a_template_addresses_exists(): void {
		$root    = $this->plugin_root();
		$checked = 0;
		foreach ( $this->sources() as $path => $file ) {
			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			preg_match_all( '#' . preg_quote( self::PLUGIN_ASSETS, '#' ) . '[^"\'\s)>]+#', $source, $matches );
			foreach ( array_unique( $matches[0] ) as $address ) {
				$relative = substr( (string) $address, strlen( self::PLUGIN_ASSETS ) );
				$on_disk  = $root . '/' . $relative;
				$this->assertFileExists( $on_disk, "{$file} addresses {$address}, which this repository does not hold" );
				++$checked;
			}
		}
		$this->assertGreaterThan( 0, $checked, 'the scan finds the templates\' plugin assets' );
	}

	/**
	 * No template addresses a non-WebP file under uploads: the final media
	 * pipeline emits WebP only, so such a name can never be served.
	 */
	public function test_no_template_addresses_a_non_webp_upload(): void {
		$pattern = '#/wp-content/uploads/[^"\'\s)>]+\.(?:jpe?g|png|gif)#i';
		foreach ( $this->sources() as $path => $file ) {
			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			preg_match_all( $pattern, $source, $matches );
			$this->assertSame(
				array(),
				$matches[0],
				"{$file} addresses a non-WebP upload the media pipeline never produces: " . implode( ', ', array_unique( $matches[0] ) )
			);
		}
	}

	/**
	 * No WindPress volume loads a file from the old shop's host.
	 *
	 * The T4 asset rewrite (`tools/template-convert`) only ever walked the
	 * template HTML, so a `background-image: url('//metalpartition.com/…')`
	 * carried in a volume's inline-style rules survived every conversion:
	 * four `.hero-chip-swatch` rules did, and the site's CSP (`default-src
	 * 'self'`) blocked all four requests, leaving the pattern chips' swatch
	 * dots empty. A volume is repository CSS served from this site, so an
	 * address on the old host is a request that can only fail.
	 */
	public function test_no_volume_loads_a_file_from_the_old_host(): void {
		$dir     = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress';
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$scanned = 0;
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = basename( (string) $file );
			preg_match_all( '#(?:https?:)?//(?:www\.)?metalpartition\.com/[^"\'\s)]*#i', $source, $matches );
			$this->assertSame(
				array(),
				array_values( array_unique( $matches[0] ) ),
				"{$relative} loads a file from the old shop's host, which the CSP blocks: " . implode( ', ', array_unique( $matches[0] ) )
			);
			++$scanned;
		}
		$this->assertGreaterThan( 30, $scanned, 'the scan walks the volume set' );
	}

	/**
	 * No template repeats an `id`.
	 *
	 * `custom-screens` carried the old landing page's `id="contact"` twice —
	 * once on the `<section>` and once on the zero-height offset `<div>` the
	 * old markup also put there. A browser resolves a fragment to the first
	 * match in document order, so the `<section>` always won and the offset
	 * div was dead; the duplicate is invalid HTML either way and the
	 * accessibility tree it produces is ambiguous. (`scroll-padding-top` on
	 * `html`, `calc(var(--md-header-h) + 16px)`, is what actually keeps the
	 * fixed header off an anchor target.)
	 */
	public function test_no_template_repeats_an_id(): void {
		foreach ( $this->sources() as $path => $file ) {
			$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			preg_match_all( '/\sid="([^"]+)"/', $source, $matches );
			$counts     = array_count_values( $matches[1] );
			$duplicates = array_keys( array_filter( $counts, static fn( int $n ): bool => $n > 1 ) );
			sort( $duplicates );
			$this->assertSame(
				array(),
				$duplicates,
				"{$file} repeats " . implode( ', ', $duplicates )
			);
		}
	}

	/**
	 * The landing face's 16px rem base stays scoped to that face.
	 *
	 * The carried faces were captured from old pages that ran at
	 * `html { font-size: 62.5% }`; the landing page alone ran at the
	 * browser's 16px (measured on the old page 2026-10-26: `3rem` = 48px).
	 * `custom-screens.css` therefore restores a 16px root under
	 * `html:has(#landing-nav)`. The WindPress cache is one stylesheet for
	 * every face, so an unscoped `html { font-size: 100% }` would resize all
	 * of them — this test pins the guard, and the body pin that keeps
	 * base.css's rem-sized body text at its old 16px.
	 */
	public function test_the_landing_rem_base_stays_scoped_to_its_face(): void {
		$volume = (string) ( new \ReflectionClass( Templates::class ) )->getFileName();
		$path   = dirname( $volume ) . '/../../content/windpress/custom-screens.css';
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertMatchesRegularExpression(
			'/html:has\(\s*#landing-nav\s*\)\s*\{\s*font-size:\s*100%\s*;?\s*\}/',
			$source,
			'the landing face restores the 16px root its capture assumed'
		);
		$this->assertMatchesRegularExpression(
			'/html:has\(\s*#landing-nav\s*\)\s*body\s*\{\s*font-size:\s*1rem\s*;?\s*\}/',
			$source,
			'the body is pinned so base.css\'s rem body size does not inflate'
		);
		// An unscoped root override would resize every other face: every
		// `html { font-size: … }` in this volume must carry the guard. The
		// comments are dropped first — this volume's own header explains the
		// old rule in prose.
		$rules = (string) preg_replace( '#/\*.*?\*/#s', '', $source );
		preg_match_all( '/html\s*\{[^}]*font-size\s*:[^;}]*/', $rules, $roots, PREG_OFFSET_CAPTURE );
		foreach ( $roots[0] as $root ) {
			$before = substr( $rules, max( 0, (int) $root[1] - 40 ), 40 );
			$this->assertStringContainsString(
				'#landing-nav',
				$before,
				'a root font-size in custom-screens.css is not scoped to #landing-nav'
			);
		}
	}
}
