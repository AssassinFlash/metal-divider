<?php
/**
 * A counter in a template must have a behaviour that fills it.
 *
 * `/laser-cut-panels/` renders four quality metrics whose visible value starts
 * at `0` and is meant to be animated up to a target carried in the markup. The
 * old site shipped that animation as an inline `<script>`; the conversion
 * dropped it, because templates may not carry scripts (the CSP blocks them and
 * InlineScriptTest enforces it). Nothing replaced it, so the page has been
 * advertising "0 checkpoints, ±0 mm, 0 % traced, < 0 % defect" — the exact
 * opposite of what those cards exist to say.
 *
 * Measured on production 2026-10-29: all four `[data-num]` elements still read
 * `0` in a real Chrome DOM, and no script on the page mentioned them.
 *
 * The guard is deliberately two-sided: the behaviour must exist, and the value
 * must be read from the card's own `data-target` rather than typed a second time
 * into JavaScript. A test that only checked "the word setupMetricCounters is
 * present" would pass a script that hardcoded the numbers and then drifted from
 * the markup the first time a value was edited.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * Every template carrying `[data-num]` must be covered by the counter behaviour.
 *
 * @coversNothing
 */
final class MetricCounterTest extends TestCase {

	/**
	 * The behaviour exists and reads its targets from the markup.
	 */
	public function test_the_counter_behaviour_reads_targets_from_the_markup(): void {
		$js = $this->chrome_js();

		$this->assertStringContainsString( 'function setupMetricCounters', $js, 'the counter behaviour exists' );
		$this->assertStringContainsString( 'setupMetricCounters();', $js, 'and it is called from start()' );
		$this->assertStringContainsString( "querySelectorAll('[data-counter][data-target]')", $js, 'it selects the cards the templates carry' );
		$this->assertStringContainsString( "getAttribute('data-target')", $js, 'the target is read from the card, not retyped' );
		$this->assertStringContainsString( "getAttribute('data-decimals')", $js, 'the precision likewise' );
		$this->assertStringContainsString( "querySelectorAll('[data-num]')", $js, 'and it writes the elements the templates mark' );
	}

	/**
	 * It must not leave a card at its placeholder, and must respect reduced motion.
	 */
	public function test_the_counter_always_reaches_its_final_value(): void {
		$js = $this->chrome_js();

		$this->assertMatchesRegularExpression(
			'/IntersectionObserver/',
			$js,
			'the count waits for the card to be visible'
		);
		$this->assertMatchesRegularExpression(
			'/fallback|else\s*\{\s*each\(\s*cards,\s*settle/s',
			$js,
			'a browser without IntersectionObserver still gets real values'
		);
		$this->assertStringContainsString( 'prefers-reduced-motion', $js, 'reduced motion skips the animation' );
		$this->assertMatchesRegularExpression(
			'/target\.toFixed\(\s*decimals\s*\)/',
			$js,
			'the final frame writes the exact target, not an eased approximation'
		);
	}

	/**
	 * The templates that carry a counter must also carry the target it needs.
	 *
	 * This is the half that catches a template edit: a new card with `[data-num]`
	 * and no `data-target` renders `0` forever whatever the JavaScript does.
	 */
	public function test_every_counter_card_declares_a_target(): void {
		$offenders = array();

		foreach ( $this->templates_with_counters() as $relative ) {
			$contents = (string) file_get_contents( Templates::path( $relative, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$cards    = $this->counter_cards( $contents );
			if ( array() === $cards ) {
				continue;
			}
			foreach ( $cards as $index => $card ) {
				if ( ! preg_match( '/\bdata-target=/', $card ) ) {
					$offenders[] = "{$relative} card {$index}";
					continue;
				}
				if ( ! preg_match( '/\bdata-num\b/', $card ) ) {
					$offenders[] = "{$relative} card {$index} declares a target but nothing to fill";
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a counter card needs both a data-target and a data-num to write it into: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * And the placeholder must be a number, so a failed fill is visibly wrong.
	 */
	public function test_counter_placeholders_are_numeric(): void {
		$offenders = array();

		foreach ( $this->templates_with_counters() as $relative ) {
			$contents = (string) file_get_contents( Templates::path( $relative, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			if ( ! preg_match_all( '/data-num[^>]*>([^<]*)</', $contents, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $value ) {
				if ( ! preg_match( '/^\s*-?\d+(\.\d+)?\s*$/', $value ) ) {
					$offenders[] = "{$relative}: " . trim( $value );
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'a counter placeholder must be a number the behaviour can replace: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * The template files that carry `[data-num]`.
	 *
	 * @return list<string>
	 */
	private function templates_with_counters(): array {
		$found = array();
		$root  = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			if ( str_contains( $source, 'data-num' ) ) {
				$found[] = str_replace( $root . '/', '', (string) $file );
			}
		}
		return $found;
	}

	/**
	 * The markup of each `[data-counter]` card, up to the next opening of one.
	 *
	 * A card is split on its own attribute rather than parsed as a tree: the
	 * templates carry unbalanced markup (LiveCanvas sections), and an HTML parser
	 * would silently repair it into a shape the file does not have.
	 *
	 * @param string $source Template source.
	 * @return list<string>
	 */
	private function counter_cards( string $source ): array {
		$parts = preg_split( '/(?=\bdata-counter\b)/', $source );
		if ( false === $parts || count( $parts ) < 2 ) {
			return array();
		}
		// The first chunk is everything before the first card.
		array_shift( $parts );
		return $parts;
	}

	/**
	 * The behaviour file.
	 */
	private function chrome_js(): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../assets/js/md-chrome.js';
		$this->assertFileExists( $path, 'the behaviour file is part of the plugin' );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}
	/**
	 * LiveCanvas's lazy images must be upgraded, because their runtime is absent.
	 *
	 * The converted markup carries `<img is="xo-lazyload" xo-src=...>`; LiveCanvas
	 * upgrades that built-in by copying xo-src into src, and that runtime is not on
	 * these pages. Measured on production 2026-10-29: twenty-five <img> with
	 * src="" on /custom-metal-divider-screens/ alone, naturalWidth 0, seven per
	 * page across twelve pages — the header mega menu's pictures, which is why the
	 * menus read as empty boxes.
	 *
	 * This guard is the template half: a template that ships an xo-src image is
	 * only safe while the behaviour that upgrades it exists.
	 */
	public function test_lazy_images_have_a_behaviour_that_upgrades_them(): void {
		$js = $this->chrome_js();

		$carriers = array();
		foreach ( $this->template_files() as $relative ) {
			$source = (string) file_get_contents( Templates::path( $relative, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			if ( preg_match( '/\bxo-src=/', $source ) ) {
				$carriers[] = $relative;
			}
		}
		if ( array() === $carriers ) {
			$this->markTestSkipped( 'no template carries an xo-src image' );
		}

		$this->assertStringContainsString( 'function setupLazyImages', $js, 'the upgrade behaviour exists (' . count( $carriers ) . ' templates need it)' );
		$this->assertStringContainsString( 'setupLazyImages();', $js, 'and it is called from start()' );
		$this->assertMatchesRegularExpression(
			'/img\[is="xo-lazyload"\], img\[xo-src\]|img\[xo-src\], img\[is="xo-lazyload"\]|\[xo-src\]/',
			$js,
			'it selects the images the templates mark'
		);
		$this->assertStringContainsString( "getAttribute('xo-src')", $js, 'it reads the URL the markup carries' );
		$this->assertStringContainsString( "setAttribute('src'", $js, 'and writes it where the browser reads it' );
		$this->assertMatchesRegularExpression(
			'/removeAttribute\(\s*.is.\s*\)|removeAttribute\(\s*\x27is\x27\s*\)/',
			$js,
			'the customised built-in is dissolved once upgraded'
		);
	}

	/**
	 * Every template file, relative to the volume root.
	 *
	 * @return list<string>
	 */
	private function template_files(): array {
		$root  = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$out   = array();
		foreach ( $files as $file ) {
			$out[] = str_replace( $root . '/', '', (string) $file );
		}
		return $out;
	}
}
