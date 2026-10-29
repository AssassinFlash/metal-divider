<?php
/**
 * A page carries exactly one `main` landmark.
 *
 * @package MetalDivider\Site\Tests\Unit\Presentation
 */

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Guards the templates that draw the catalog body against a nested `<main>`.
 *
 * The theme skeleton already renders `<main id="theme-main">` around a
 * template's markup. `shop.html` and `series-archive.html` also opened their own
 * `<main class="pattern-catalog__content">`, so those ten pages served **two
 * main landmarks**: measured on production 2026-10-29, `/shop/` and all nine
 * series archives returned `<main` twice while `/`, `/faqs/` and `/about-us/`
 * returned it once.
 *
 * HTML forbids nesting `main` inside `main`. Assistive technology and crawlers
 * both use the main landmark to skip navigation, and two of them make that
 * ambiguous on exactly the pages a pattern buyer lands on.
 *
 * The fix is a `<div>` with the same class, not a rewrite: the outer `main`
 * still supplies the `main p` measure cap in `base.css`, which a spot check
 * confirmed is applied (720px on a catalog paragraph).
 */
final class SingleMainTest extends TestCase {

	/**
	 * Neither catalog template opens a `main` element.
	 */
	public function test_the_catalog_templates_do_not_open_a_main(): void {
		foreach ( array( 'shop.html', 'series-archive.html' ) as $name ) {
			$html = $this->template( $name );

			$this->assertDoesNotMatchRegularExpression(
				'/<main\b/i',
				$html,
				$name . ': the theme already renders <main id="theme-main"> around this template'
			);
		}
	}

	/**
	 * The content wrapper keeps its class, so layout is unchanged.
	 *
	 * Dropping the element without keeping the class would lose
	 * `pattern-grid.css`'s `.pattern-catalog__content` rules — a silent layout
	 * change dressed up as a semantics fix.
	 */
	public function test_the_content_wrapper_keeps_its_class(): void {
		foreach ( array( 'shop.html', 'series-archive.html' ) as $name ) {
			$html = $this->template( $name );

			$this->assertStringContainsString(
				'class="pattern-catalog__content"',
				$html,
				$name . ': the wrapper element must survive the tag change'
			);
		}
	}

	/**
	 * Reads a template with HTML comments stripped.
	 *
	 * The note added beside the fix quotes `<main id="theme-main">`; a search
	 * that did not strip comments would find its own explanation and fail.
	 *
	 * @param string $name Template file name.
	 */
	private function template( string $name ): string {
		$dir  = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$html = (string) file_get_contents( $dir . '/' . $name ); // phpcs:ignore WordPress.WP.FileSystem.FileGetContents.file_get_contents -- repository file in a test.
		return (string) preg_replace( '/<!--.*?-->/s', '', $html );
	}
}
