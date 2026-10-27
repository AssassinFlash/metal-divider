<?php
/**
 * The LiveCanvas templates carry no inline script.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The site's CSP allows `script-src 'self'` with no `'unsafe-inline'`, so an
 * inline `<script>` in a template is not merely untidy: it never runs, and the
 * browser logs an error on every page that carries it.
 *
 * The blog article shipped one. It named the share links for screen readers,
 * opened the TOC at desktop widths and built the section map from the
 * article's own headings — and did none of it, because the CSP blocked the
 * whole block (measured 2026-10-27: zero map sections, four share links with
 * no accessible name, one console error per view). The behaviour moved into
 * `md-chrome.js`; this test keeps the next conversion from re-introducing the
 * pattern, which is the shape the conversion pipeline produces most often.
 *
 * @coversNothing
 */
final class InlineScriptTest extends TestCase {

	/**
	 * No template or partial contains a `<script>` element.
	 */
	public function test_no_template_carries_an_inline_script(): void {
		$offenders = array();

		foreach ( $this->template_files() as $relative ) {
			$contents = (string) file_get_contents( Templates::path( $relative, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			if ( preg_match( '/<script[\s>]/i', $contents ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'these templates carry an inline <script>, which the CSP blocks — move the behaviour into md-chrome.js: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * Every template and partial under content/livecanvas.
	 *
	 * @return string[] Paths relative to content/livecanvas.
	 */
	private function template_files(): array {
		$root  = dirname( Templates::path( 'x', true ) );
		$found = array();

		foreach ( array( '', 'partials/' ) as $sub ) {
			$directory = $root . '/' . $sub;
			foreach ( (array) glob( $directory . '*.html' ) as $path ) {
				$found[] = $sub . basename( (string) $path );
			}
		}

		$this->assertNotEmpty( $found, 'the template directory must be readable for this guard to mean anything' );

		return $found;
	}
}
