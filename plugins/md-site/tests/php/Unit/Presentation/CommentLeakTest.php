<?php
/**
 * HTML comments must not carry a shortcode, and must not ship long.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\HtmlComments;
use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Two defects, both measured on the live response: a shortcode tag written
 * inside an author note expands there, because WordPress runs shortcodes
 * over the whole post, and the notes themselves (internal paths, spec
 * numbers) are not for a visitor.
 *
 * The first assertion reads the template files. The second runs those files
 * through the function the front-end buffer calls, and fails if a comment
 * longer than the ceiling is still there — which is what a buffer that
 * stopped stripping would leave, because the files still carry the notes.
 *
 * @covers \MetalDivider\Site\Presentation\HtmlComments
 */
final class CommentLeakTest extends TestCase {

	/**
	 * A shortcode tag between `<!--` and `-->` in any template or partial.
	 */
	public function test_no_template_hides_a_shortcode_in_a_comment(): void {
		$offenders = array();

		foreach ( $this->template_files() as $relative ) {
			$source = $this->source( $relative );
			foreach ( $this->comment_bodies( $source ) as $body ) {
				if ( preg_match( '/\[md_[a-z0-9_]+/i', $body ) ) {
					$offenders[] = $relative;
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a shortcode tag inside an HTML comment is expanded before the comment can be stripped: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * The bytes the front-end buffer would send for each template carry no
	 * comment longer than the ceiling.
	 */
	public function test_rendered_templates_ship_no_comment_over_the_bound(): void {
		$this->assertLessThanOrEqual( 80, HtmlComments::MAX_SHIPPED_BYTES );
		$this->assertGreaterThan( 0, HtmlComments::MAX_SHIPPED_BYTES );

		$over = array();
		foreach ( $this->template_files() as $relative ) {
			$rendered = HtmlComments::strip( $this->source( $relative ) );
			foreach ( $this->comment_bodies( $rendered ) as $body ) {
				$bytes = strlen( '<!--' . $body . '-->' );
				if ( $bytes > HtmlComments::MAX_SHIPPED_BYTES ) {
					$over[] = $relative . ':' . $bytes;
				}
			}
		}

		$this->assertSame(
			array(),
			$over,
			'rendered template output still carries an HTML comment longer than ' . HtmlComments::MAX_SHIPPED_BYTES . ' bytes: ' . implode( ', ', $over )
		);
	}

	/**
	 * A note the length of the ones in these files does not survive the strip.
	 */
	public function test_strip_drops_a_comment_longer_than_the_bound(): void {
		$note = '<!-- ' . str_repeat( 'internal ', 20 ) . ' -->';
		$this->assertGreaterThan( HtmlComments::MAX_SHIPPED_BYTES, strlen( $note ) );

		$out = HtmlComments::strip( '<p>visible</p>' . $note . '<p>still</p>' );

		$this->assertStringNotContainsString( 'internal', $out );
		$this->assertSame( '<p>visible</p><p>still</p>', $out );
	}

	/**
	 * A real shortcode outside a comment, and text inside a script, stay.
	 */
	public function test_strip_leaves_shortcodes_and_script_text(): void {
		$html = '<div>[md_inquiry_form]</div>'
			. '<script>var s = "<!-- not a comment -->";</script>'
			. '<textarea><!-- kept --></textarea>'
			. '<!-- secret ' . str_repeat( 'x', 80 ) . ' -->';

		$out = HtmlComments::strip( $html );

		$this->assertStringContainsString( '[md_inquiry_form]', $out );
		$this->assertStringContainsString( '<!-- not a comment -->', $out );
		$this->assertStringContainsString( '<!-- kept -->', $out );
		$this->assertStringNotContainsString( 'secret', $out );
	}

	/**
	 * The front-end send path is the buffer, not a function nobody calls.
	 */
	public function test_front_end_send_registers_the_strip(): void {
		$comments = new HtmlComments();
		WP_Mock::expectActionAdded( 'template_redirect', array( $comments, 'start' ), 0 );

		$comments->register();

		$this->assertConditionsMet();

		$plugin = (string) file_get_contents( dirname( Templates::path( 'x', true ), 3 ) . '/includes/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertStringContainsString( 'new Presentation\\HtmlComments()', $plugin );
	}

	/**
	 * Comment interiors, including an unclosed tail. The shortcode parser
	 * does not require the comment to be closed.
	 *
	 * @param string $html A template or a rendered fragment.
	 * @return list<string>
	 */
	private function comment_bodies( string $html ): array {
		$bodies = array();
		if ( preg_match_all( '/<!--(.*?)-->/s', $html, $closed ) ) {
			foreach ( $closed[1] as $body ) {
				$bodies[] = (string) $body;
			}
		}
		$stripped = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$open     = strpos( $stripped, '<!--' );
		if ( false !== $open ) {
			$bodies[] = substr( $stripped, $open + 4 );
		}
		return $bodies;
	}

	/**
	 * One template file.
	 *
	 * @param string $relative Path under content/livecanvas/.
	 */
	private function source( string $relative ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		return (string) file_get_contents( Templates::path( $relative, true ) );
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
			foreach ( (array) glob( $root . '/' . $sub . '*.html' ) as $path ) {
				$found[] = $sub . basename( (string) $path );
			}
		}

		$this->assertNotEmpty( $found, 'the template directory must be readable for this guard to mean anything' );

		return $found;
	}
}
