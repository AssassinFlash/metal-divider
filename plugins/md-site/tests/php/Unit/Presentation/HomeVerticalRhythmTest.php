<?php
/**
 * The home page's vertical rhythm against the old site's measurements.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;

/**
 * The owner's ruling of 2026-10-30 (batch five) is that the home page runs
 * longer between sections than the old one, and that the amounts come from
 * measurement.
 *
 * Measured on both sites in a real Chrome at 1440 with one script, the
 * finding that shapes these guards is that padding is NOT the main cause:
 *
 *   section                 old    new     Δ   padOld padNew  padΔ  innerΔ
 *   Know what will ship     824    829    +5     120    192   +72     -67
 *   Pattern Direction       986   1209  +223     120    192   +72    +151
 *   Every step              968   1136  +168     200    192    -8    +176
 *   Featured / 15+          959   1146  +187       0      0     0    +187
 *   5 Steps                1034   1191  +157     192    192     0    +157
 *   FAQ                    1004   1185  +181     192    192     0    +181
 *   Ready to Start          865   1681  +816     100    160   +60    +756
 *
 * Two guards below exist because the naive reading of that table is wrong:
 *
 * - "Know what will ship" has MORE padding and LESS content than the old
 *   page. Cutting its padding by the same amount as its neighbours would put
 *   it below the old site. A blanket rule is the failure this pins.
 * - "Every step under one roof" already pads 96 against the old 100. Cutting
 *   it made the section 24px SHORTER than the old page (measured when the
 *   padding was removed by mistake during this batch).
 *
 * The rest of the difference is the spacing scale: the old page's
 * `html{font-size:62.5%}` made its Tailwind steps 2.5px (live-measured:
 * `p-4`=10px, `py-24`=60px, `gap-8`=20px) against this site's 4px, so every
 * inner gap is 1.6x. AGENTS.md records the 62.5% root as the old site's
 * defect and the px-pinned scale as its fix, so scaling the inner rhythm
 * down would reinstate a bug. The inner scale is deliberately untouched and
 * no guard here asks it to change.
 *
 * @coversNothing
 */
final class HomeVerticalRhythmTest extends TestCase {

	/**
	 * The home face's source.
	 */
	private function home(): string {
		return (string) file_get_contents( Templates::path( 'home' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * The home volume.
	 */
	private function css(): string {
		$path = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/windpress/home.css';
		$this->assertFileExists( $path );
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
	}

	/**
	 * Pattern Direction is padded like the old page.
	 *
	 * The old section is 60px a side; this one was 96. Asserted as a pair on
	 * that section's own id, because the same 96 is correct elsewhere.
	 */
	public function test_pattern_direction_is_padded_to_the_old_sites_measure(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression(
			'/#home-pattern-library-template--21844031930621__home_pattern_library \{\s*padding-top: 60px !important;\s*padding-bottom: 60px !important;/',
			$css,
			'Pattern Direction takes the old page\'s 60px pair'
		);
	}

	/**
	 * The four section heads share the old page's bottom margin.
	 *
	 * `md:mb-16` renders 40px on the old page and 64 here. The hooks are on
	 * the heads themselves (`md-rhythm-head`), so the rule cannot reach a
	 * card or a list.
	 */
	public function test_the_section_heads_share_the_old_margin(): void {
		$css  = $this->css();
		$home = $this->home();

		$this->assertSame(
			6,
			substr_count( $home, 'md-rhythm-head' ),
			'the hook is on six section heads'
		);
		$this->assertMatchesRegularExpression(
			'/\.md-rhythm-head,\s*#home-faq-template--\S+ > div > \.mb-12\.md\\\\:mb-16\{|#home-pattern-library-template--\S+ \.md-rhythm-head/',
			$css,
			'the heads are pulled to the old margin by section id'
		);
		$this->assertStringContainsString( 'margin-bottom: 40px !important;', $css, 'the old value is 40px' );
	}

	/**
	 * "Know what will ship" keeps its padding.
	 *
	 * Its content is 67px SHORTER than the old page's while its padding is
	 * 72px longer; the two cancel. Tightening it would make the section
	 * smaller than the design it is measured against.
	 */
	public function test_know_what_will_ship_is_not_tightened(): void {
		$css = $this->css();

		$this->assertDoesNotMatchRegularExpression(
			'/#home-outcomes-template--\S+\s*\{[^}]*padding/',
			$css,
			'the proof carousel is not in the padding cut'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#home-outcomes-template--\S+[^{]*\{[^}]*margin-bottom: 40px !important/',
			$css,
			'and its head keeps its own margin'
		);
	}

	/**
	 * "Every step under one roof" is not tightened.
	 *
	 * The old page pads it 100px and this one 96px: it is already the tighter
	 * of the two, and the whole of its difference is inner. Removing its
	 * padding during this batch took the section 24px below the old height.
	 */
	public function test_every_step_under_one_roof_keeps_its_padding(): void {
		$css = $this->css();

		$this->assertStringContainsString(
			'.md-td96d13b3 { background-color: #141414;padding-top: 96px;padding-bottom: 96px }',
			$css,
			'the process band keeps its own 96px pair'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/#interior-manufacturing-stats-template--\S+\s*\{[^}]*padding/',
			$css,
			'and no section-scoped rule overrides it'
		);
	}

	/**
	 * The form's two-column layout is scoped to the home page.
	 *
	 * `#contact` is the id of the contact section on three faces — home, the
	 * project gallery and custom screens — and all three carry this form.
	 * Scoping by that id turned the other two pages' forms into two columns
	 * as well (measured: both went from `block` to a grid). The home section
	 * carries a template class of its own, and that is the anchor.
	 */
	public function test_the_two_column_form_is_scoped_to_the_home_page(): void {
		$css  = $this->css();
		$home = $this->home();

		$this->assertStringContainsString(
			'.md-tc14287fe .md-inquiry-fields {',
			$css,
			'the two-column rule hangs off the home section\'s own template class'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/^#contact \.md-inquiry-fields\s*\{/m',
			$css,
			'the bare #contact selector is not used: that id is on three pages'
		);
		$this->assertSame(
			1,
			substr_count( $home, 'md-tc14287fe' ),
			'and the anchor appears exactly once, on this page'
		);
	}

	/**
	 * The form keeps every field and goes back to one column on a phone.
	 *
	 * The layout change is presentation only: ten fields before, ten after,
	 * with the wide ones spanning both columns. A rule that hid or dropped a
	 * field to save height would be a different change.
	 */
	public function test_the_form_keeps_its_fields_and_collapses_on_a_phone(): void {
		$css = $this->css();

		foreach ( array( '--message', '--confirm' ) as $wide ) {
			$this->assertStringContainsString(
				'.md-tc14287fe .md-inquiry-fields > .md-inquiry-field' . $wide,
				$css,
				"the {$wide} row spans both columns"
			);
		}
		$this->assertMatchesRegularExpression(
			'/@media \(max-width: 767px\) \{\s*\.md-tc14287fe \.md-inquiry-fields \{\s*grid-template-columns: minmax\(0, 1fr\)/',
			$css,
			'a phone keeps one column'
		);
		$this->assertStringNotContainsString( 'display: none', $this->two_column_block( $css ), 'nothing in the form layout hides a field' );
	}

	/**
	 * The two-column block, from its comment to the media-query close.
	 *
	 * @param string $css The home volume.
	 */
	private function two_column_block( string $css ): string {
		$start = strpos( $css, '/* Ready to Start: the form' );
		$this->assertNotFalse( $start, 'the form-layout block exists' );
		$end = strpos( $css, '/* Featured projects:', (int) $start );
		$this->assertNotFalse( $end, 'and it is delimited by the next block' );
		return substr( $css, (int) $start, (int) $end - (int) $start );
	}
}
