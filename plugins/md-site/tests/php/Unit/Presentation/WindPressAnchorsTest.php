<?php
/**
 * Tests for the compiled cache's anchor assertion.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\WindPressAnchors;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for WindPressAnchors: the anchor set it derives from the templates and
 * the tokens volume, and the assertion it makes against the compiled cache.
 *
 * The pass case runs against the real exported templates and the real
 * tokens.css — that is the half that must never drift with a fixture — while
 * the cache side is a small stylesheet written per test, because the failure
 * this check exists for (#58: a compiled selector with no rule body, a missing
 * arbitrary-value utility, a token that did not make it into the output) is
 * the cache's state, not the templates'.
 *
 * @covers \MetalDivider\Site\Presentation\WindPressAnchors
 */
final class WindPressAnchorsTest extends TestCase {

	/**
	 * The per-test uploads directory.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * A fresh uploads directory, and the uploads lookup the checker calls.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->uploads = (string) tempnam( sys_get_temp_dir(), 'md-anchors-' );
		unlink( $this->uploads );
		mkdir( $this->uploads );
		WP_Mock::userFunction( 'wp_get_upload_dir' )->andReturn( array( 'basedir' => $this->uploads ) );
	}

	/**
	 * Removes the per-test uploads directory.
	 */
	public function tearDown(): void {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->uploads );
		parent::tearDown();
	}

	/**
	 * Writes the per-test compiled cache.
	 *
	 * @param string $css The compiled stylesheet.
	 */
	private function cache( string $css ): void {
		$dir = $this->uploads . '/windpress/cache';
		mkdir( $dir, 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file, not a remote URL.
		file_put_contents( $dir . '/tailwind.css', $css );
	}

	/**
	 * The anchors are derived from the exported templates, not from a list kept
	 * in the checker: each one is an arbitrary-value utility a template really
	 * writes, and every template file contributes its own or none at all.
	 */
	public function test_the_anchors_come_from_the_templates(): void {
		$anchors   = ( new WindPressAnchors() )->anchors();
		$utilities = array();
		foreach ( $anchors as $row ) {
			if ( 'utility' === $row['kind'] ) {
				$utilities[ $row['anchor'] ] = $row['source'];
			}
		}
		$this->assertNotSame( array(), $utilities, 'the templates carry arbitrary-value utilities' );
		foreach ( $utilities as $anchor => $source ) {
			$this->assertStringContainsString( '[', (string) $anchor, $anchor . ' is an arbitrary value' );
			$this->assertNotSame( '', $source, $anchor . ' names the file it comes from' );
		}
		// Spot checks on the two families the templates are full of: a plain
		// length/ratio value, and the negative-lookbehind case (a leading `&`,
		// which the compiler escapes as `\&`).
		$this->assertArrayHasKey( 'aspect-[16/10]', $utilities, 'the aspect utilities are anchors' );
		$this->assertArrayHasKey( 'max-w-[1400px]', $utilities, 'the container width is an anchor' );
	}

	/**
	 * An Alpine binding is not a class list: `:class="'text-[#c9a962]'"` holds a
	 * JS expression, and reading it as markup would put a token in the anchor
	 * set that no compile can satisfy. A quoted arbitrary value that is a real
	 * class — `font-['Playfair_Display']` — keeps its quotes, so the test is
	 * about the shape of the token, not the presence of punctuation.
	 */
	public function test_bound_class_expressions_are_not_anchors(): void {
		foreach ( ( new WindPressAnchors() )->anchors() as $row ) {
			$anchor = $row['anchor'];
			if ( 'token' === $row['kind'] ) {
				continue;
			}
			$this->assertStringNotContainsString( ' ', $anchor, $anchor . ' is one class token, not a list' );
			$this->assertDoesNotMatchRegularExpression( '/^[\'"]/', (string) $anchor, $anchor . ' is not a JS string literal' );
			$this->assertDoesNotMatchRegularExpression( '/\($/', (string) $anchor, $anchor . ' is not a call' );
			$this->assertDoesNotMatchRegularExpression( '/^[A-Za-z_$][A-Za-z0-9_$]*\(/', (string) $anchor, $anchor . ' is not a call' );
		}
	}

	/**
	 * An arbitrary *variant* carries `=` (`data-[active=true]:bg-[#c9a962]`) and
	 * is a real class the compile must satisfy: a filter that refused `=` as a
	 * sign of a template expression dropped it, and the assertion silently
	 * covered 492 of the templates' 493 arbitrary-value utilities (found by
	 * diffing this checker's set against an independent scan of the templates).
	 */
	public function test_an_arbitrary_variant_with_an_equals_sign_is_an_anchor(): void {
		$utilities = array();
		foreach ( ( new WindPressAnchors() )->anchors() as $row ) {
			if ( 'utility' === $row['kind'] ) {
				$utilities[] = $row['anchor'];
			}
		}
		$this->assertContains(
			'data-[active=true]:bg-[#c9a962]',
			$utilities,
			'a data-* arbitrary variant is a class the compiler must build a rule for'
		);
	}

	/**
	 * The token anchors are the tokens volume's own declarations, so a token
	 * added to tokens.css is asserted without touching this checker.
	 */
	public function test_the_token_anchors_are_the_tokens_volume_declarations(): void {
		$tokens = array();
		foreach ( ( new WindPressAnchors() )->anchors() as $row ) {
			if ( 'token' === $row['kind'] ) {
				$tokens[] = $row['anchor'];
			}
		}
		$this->assertContains( '--md-gold: #c9a962', $tokens, 'the gold anchor of spec 17 §4.4' );
		$this->assertContains( '--md-container: 1400px', $tokens );
		$this->assertContains( '--md-whatsapp: #25D366', $tokens, 'the value keeps its source casing: the compile normalises it, the anchor does not' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the plugin's own exported volume, a local file.
		$volume = (string) file_get_contents( \MetalDivider\Site\Presentation\Tokens::path() );
		$this->assertSame(
			(int) preg_match_all( '/(--md-[a-z0-9-]+)\s*:\s*[^;{}]+;/', $volume ),
			count( $tokens ),
			'every --md-* declaration of the volume is an anchor, and nothing else is'
		);
	}

	/**
	 * A cache that carries every anchor passes, and `missing()` is empty.
	 */
	public function test_a_compiled_cache_carrying_every_anchor_passes(): void {
		$this->cache( $this->compiled() );

		$this->assertSame( array(), ( new WindPressAnchors() )->missing() );
	}

	/**
	 * The #58 failure in its smallest form: the selector is there but compiled
	 * to an empty rule — a utility the compiler recognised and built nothing
	 * for. The check must call that missing, not present.
	 */
	public function test_an_empty_rule_does_not_count_as_carried(): void {
		$this->cache( $this->compiled( blank: array( 'aspect-[16/10]' ) ) );

		$this->assertSame(
			array( 'aspect-[16/10]' ),
			( new WindPressAnchors() )->missing(),
			'a selector with an empty body carries no utility'
		);
	}

	/**
	 * A utility the compile never generated — the whole #58 outage — is named,
	 * and only that one.
	 */
	public function test_a_missing_arbitrary_utility_is_named(): void {
		$this->cache( $this->compiled( skip: array( 'aspect-[16/10]' ) ) );

		$this->assertSame( array( 'aspect-[16/10]' ), ( new WindPressAnchors() )->missing() );
	}

	/**
	 * A prefix that only looks like the anchor does not satisfy it: the anchor
	 * is absent while a longer class with the same stem is present.
	 */
	public function test_a_longer_class_does_not_satisfy_a_shorter_anchor(): void {
		$this->cache(
			$this->compiled( skip: array( 'max-w-[1400px]' ) )
			. '.max-w-\\[1400px\\]-wide{padding:0}'
		);

		$this->assertSame( array( 'max-w-[1400px]' ), ( new WindPressAnchors() )->missing() );
	}

	/**
	 * The token comparison is CSS-equivalent: the compiler minifies, so
	 * `--md-whatsapp: #25D366` reaches the cache as `--md-whatsapp:#25d366`, and
	 * a byte comparison would report every upper-case value missing forever.
	 */
	public function test_a_minified_token_declaration_still_matches(): void {
		$this->cache( $this->compiled( minify: true ) );

		$missing = ( new WindPressAnchors() )->missing();

		$this->assertSame( array(), $missing, 'case and spacing are CSS-insignificant' );
	}

	/**
	 * A token with the same name and a different value is a drift, not a pass.
	 */
	public function test_a_token_with_another_value_does_not_match(): void {
		$this->cache( $this->compiled( values: array( '--md-gold' => '#000000' ) ) );

		$this->assertSame(
			array( '--md-gold: #c9a962' ),
			( new WindPressAnchors() )->missing()
		);
	}

	/**
	 * A multi-byte character in an arbitrary value is one token in the
	 * selector: the compiler leaves it unescaped, so the checker must too, or
	 * `content-['—']` can never match (found against the dev instance's cache).
	 */
	public function test_a_multi_byte_arbitrary_value_matches(): void {
		$anchor = null;
		foreach ( ( new WindPressAnchors() )->anchors() as $row ) {
			if ( str_contains( $row['anchor'], '—' ) ) {
				$anchor = $row['anchor'];
			}
		}
		$this->assertNotNull( $anchor, 'a template really carries an em dash in an arbitrary value' );
		$this->cache( $this->compiled() );

		$this->assertSame( array(), ( new WindPressAnchors() )->missing() );
	}

	/**
	 * A site with no compiled cache at all carries nothing, so every anchor is
	 * missing rather than the check passing quietly.
	 */
	public function test_an_absent_cache_reports_every_anchor_missing(): void {
		$check = new WindPressAnchors();

		$missing = $check->missing();

		$this->assertCount( count( $check->anchors() ), $missing, 'an absent cache carries none of the anchors' );
		$this->assertNotSame( array(), $missing );
	}

	/**
	 * The cache path is the file the deploy's mtime half reads.
	 */
	public function test_the_cache_path_is_the_compilers_output(): void {
		$this->assertSame(
			$this->uploads . '/windpress/cache/tailwind.css',
			WindPressAnchors::cache_path()
		);
	}

	/**
	 * A compiled stylesheet carrying every anchor in the form the compiler
	 * writes it, with named defects: a skipped anchor emits no rule, an empty
	 * one emits the selector with no body, `values` replaces a token's value,
	 * and `minify` writes every declaration the way the real compiler does.
	 *
	 * Building the fixture from the anchor set is what keeps these tests about
	 * the cache's state: a fixture that emitted a hand-picked few would report
	 * every other anchor missing and the assertions would pass for the wrong
	 * reason.
	 *
	 * @param string[]              $skip   Utility anchors to leave out.
	 * @param string[]              $blank  Utility anchors to emit with an empty body.
	 * @param array<string, string> $values Token name → the value to emit instead.
	 * @param bool                  $minify Emit minified declarations (no spaces, lower case).
	 */
	private function compiled(
		array $skip = array(),
		array $blank = array(),
		array $values = array(),
		bool $minify = false
	): string {
		$css = '';
		foreach ( ( new WindPressAnchors() )->anchors() as $row ) {
			$anchor = $row['anchor'];
			if ( 'token' === $row['kind'] ) {
				$colon = strpos( $anchor, ':' );
				$name  = false === $colon ? $anchor : substr( $anchor, 0, $colon );
				$value = $values[ $name ] ?? ( false === $colon ? '' : trim( substr( $anchor, $colon + 1 ) ) );
				$css  .= $minify
					? ':root{' . $name . ':' . strtolower( (string) preg_replace( '/\s+/', '', $value ) ) . '}'
					: ':root{' . $name . ': ' . $value . ';}';
				continue;
			}
			if ( in_array( $anchor, $skip, true ) ) {
				continue;
			}
			$body = in_array( $anchor, $blank, true ) ? '' : 'display:block';
			$css .= '.' . self::escaped( $anchor ) . '{' . $body . '}';
		}
		return $css;
	}

	/**
	 * Every card partial still carries the semantic hooks its styling hangs
	 * on.
	 *
	 * The anchor set above cannot see these: a semantic class is produced by a
	 * volume rule, not by the compiler, so a partial that renames one renders
	 * an unstyled card while every anchor still resolves.
	 */
	public function test_every_card_partial_carries_its_semantic_hooks(): void {
		$this->assertSame(
			array(),
			( new WindPressAnchors() )->partial_hook_problems(),
			'each card partial carries the classes its volume rules target'
		);
	}

	/**
	 * The two migrated renderers are covered: the PDP related card and the hub
	 * series card are in the hook table, so their styling cannot be dropped
	 * silently.
	 */
	public function test_the_related_and_series_partials_are_covered_by_the_hook_table(): void {
		$reflection = new \ReflectionClass( WindPressAnchors::class );
		$hooks      = $reflection->getConstant( 'CARD_HOOKS' );

		$this->assertArrayHasKey( 'pdp-related-card.html', $hooks );
		$this->assertContains( 'pdp-related-card', $hooks['pdp-related-card.html'] );
		$this->assertArrayHasKey( 'hub-series-card.html', $hooks );
		$this->assertContains( 'cph__card', $hooks['hub-series-card.html'] );
		$this->assertArrayHasKey( 'hub-series-image.html', $hooks );
		$this->assertContains( 'cph__media-image', $hooks['hub-series-image.html'] );
	}

	/**
	 * The hook check reports a partial whose hook was renamed, rather than
	 * passing because the partial merely exists.
	 */
	public function test_a_renamed_hook_is_reported(): void {
		$partial  = dirname( __DIR__, 4 ) . '/content/livecanvas/partials/pdp-related-card.html';
		$original = (string) file_get_contents( $partial );
		try {
			file_put_contents( $partial, str_replace( 'pdp-related-card', 'pdp-related-crad', $original ) );
			$problems = ( new WindPressAnchors() )->partial_hook_problems();
			$this->assertNotSame( array(), $problems, 'a renamed hook is a problem' );
			$this->assertSame( 'pdp-related-card', $problems[0]['hook'] );
			$this->assertSame( 'pdp-related-card.html', $problems[0]['partial'] );
		} finally {
			file_put_contents( $partial, $original );
		}
	}

	/**
	 * A class token in the compiler's selector escaping (its own copy, so a
	 * change to the checker's escaping shows up as a test failure rather than
	 * moving both sides together).
	 *
	 * @param string $token The class token.
	 */
	private static function escaped( string $token ): string {
		$out    = '';
		$length = strlen( $token );
		for ( $i = 0; $i < $length; $i++ ) {
			$char  = $token[ $i ];
			$plain = ord( $char ) > 127 || 1 === preg_match( '/[A-Za-z0-9_-]/', $char );
			$out  .= $plain ? $char : '\\' . $char;
		}
		return $out;
	}
}
