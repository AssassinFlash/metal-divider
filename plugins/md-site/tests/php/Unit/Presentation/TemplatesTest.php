<?php
/**
 * Tests for the template inventory and its drift check.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Presentation;

use MetalDivider\Site\Presentation\Templates;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Templates: the inventory every face of spec 19 §3 names
 * exists as a file, the reader refuses an empty source, and the drift
 * check reports exactly what is missing on a bare site.
 *
 * @covers \MetalDivider\Site\Presentation\Templates
 */
final class TemplatesTest extends TestCase {

	/**
	 * Every face the spec names (25 templates, spec 19 §3) exists as a
	 * repository file: the page-type files, the per-page files, and the
	 * partials — the five PDP/chrome partials plus the cards' eleven markup
	 * partials: the editorial, catalog and tile faces, the PDP's related card
	 * and gallery-slot image, and the hub's series card and its image.
	 * Deploying them is what puts their utilities into WindPress's compile and
	 * makes the repository file the copy the site renders.
	 */
	public function test_every_face_has_its_file(): void {
		$reflection = new \ReflectionClass( Templates::class );
		foreach ( array( 'FILES', 'PAGES', 'PARTIALS' ) as $constant ) {
			$table = $reflection->getConstant( $constant );
			$files = array();
			foreach ( $table as $entry ) {
				$files[] = is_array( $entry ) ? $entry[0] : $entry;
			}
			if ( 'PARTIALS' === $constant ) {
				$files = array_keys( $table );
			}
			foreach ( $files as $file ) {
				$path = Templates::path( (string) $file, 'PARTIALS' === $constant );
				$this->assertFileExists( $path, "{$constant} names {$file}" );
				$this->assertNotSame( '', trim( (string) file_get_contents( $path ) ), "{$file} is not empty" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			}
		}
		$this->assertCount( 7, $reflection->getConstant( 'FILES' ) );
		$this->assertCount( 18, $reflection->getConstant( 'PAGES' ) );
		$this->assertCount( 17, $reflection->getConstant( 'PARTIALS' ) );
	}

	/**
	 * The face count is the spec's 25: seven page-type faces plus
	 * eighteen per-page faces.
	 */
	public function test_the_face_count_is_the_specs_25(): void {
		$reflection = new \ReflectionClass( Templates::class );
		$this->assertSame(
			25,
			count( $reflection->getConstant( 'FILES' ) ) + count( $reflection->getConstant( 'PAGES' ) ),
			'spec 19 §3: 25 LiveCanvas dynamic templates'
		);
	}

	/**
	 * The read() method refuses an empty source — an empty export would blank a
	 * live face, which is a refusal, never a write.
	 */
	public function test_read_refuses_an_empty_source(): void {
		$path = (string) tempnam( sys_get_temp_dir(), 'md-tpl-' );
		file_put_contents( $path, "   \n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file.
		try {
			Templates::read( $path );
			$this->fail( 'an empty source must refuse' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'missing or empty', $error->getMessage() );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * On a bare site — no template row, no partial, no page — drift
	 * reports every face absent: the read-only twin names what present
	 * would install.
	 */
	public function test_drift_on_a_bare_site_reports_every_face(): void {
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturn( array() );

		$rows = ( new Templates() )->drift();

		$reflection = new \ReflectionClass( Templates::class );
		$expected   = count( $reflection->getConstant( 'FILES' ) )
			+ 2 * count( $reflection->getConstant( 'PAGES' ) )  // Template absent + page absent each.
			+ count( $reflection->getConstant( 'PARTIALS' ) );
		$this->assertCount( $expected, $rows );
		$this->assertContainsOnly( 'array', $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( array( 'surface', 'target', 'detail' ), array_keys( $row ) );
		}
	}

	/**
	 * The series archive's heading is the current term, never a hardcoded
	 * series name: the one shared template renders all nine archives, so a
	 * literal title would print the same collection name on every URL
	 * (visual census VC-037).
	 */
	public function test_the_series_archive_heading_is_the_current_term(): void {
		$source = (string) file_get_contents( Templates::path( 'series-archive' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertMatchesRegularExpression( '/<h1[^>]*>\s*\[lc_the_archive_title\b[^\]]*\]/', $source, 'the heading is LiveCanvas\'s native archive title' );
		$this->assertDoesNotMatchRegularExpression( '/<h1[^>]*>\s*Organic Patterns\s*<\/h1>/i', $source, 'the heading is not the representative collection literal' );
	}

	/**
	 * The series archive's own position — the breadcrumb item and the
	 * catalog sidebar — names no series: one shared template renders all
	 * nine archives, so a literal series name there would print the same
	 * collection on every URL. The shipped template hardcoded Organic in
	 * both, so every archive told the visitor they were on Organic while
	 * the H1 (already term-driven) said otherwise. The editorial guide
	 * sections below the catalog legitimately name series as content;
	 * this test scopes to the two position-bearing blocks.
	 */
	public function test_the_series_archive_position_names_no_series(): void {
		$source = (string) file_get_contents( Templates::path( 'series-archive' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$breadcrumb_start = strpos( $source, "class='breadcrumb'" );
		$this->assertNotFalse( $breadcrumb_start, 'the breadcrumb is in the template' );
		$breadcrumb = substr( $source, $breadcrumb_start, strlen( "<nav class='breadcrumb' role='navigation' aria-label='breadcrumb'>" ) + 1200 );
		$this->assertStringContainsString( '[md_series_current_url]', $breadcrumb, 'the breadcrumb item links the current term' );
		$this->assertStringContainsString( '[md_series_current_label]', $breadcrumb, 'the breadcrumb item is labelled by the current term' );

		$nav_start = strpos( $source, 'class="pattern-catalog__nav"' );
		$this->assertNotFalse( $nav_start, 'the sidebar nav is in the template' );
		$nav_end = strpos( $source, '</nav>', $nav_start );
		$this->assertNotFalse( $nav_end, 'the sidebar nav closes' );
		$nav = substr( $source, $nav_start, $nav_end - $nav_start );

		$this->assertStringContainsString( '[md_series_nav]', $nav, 'the series links are rendered from the terms' );
		$this->assertDoesNotMatchRegularExpression( '/Organic|Geometric|Gradient|Perforated|Landscape|Abstract|Moroccan|Asian|Vintage/i', $nav, 'the sidebar names no series' );
		$this->assertStringNotContainsString( 'is-active', $nav, 'the active state is not a literal class in the template' );
		$this->assertStringContainsString( 'href="/patterns/"', $nav, 'the all-patterns link is the catalogue, not the old /shop/ or /collections/all' );
	}

	/**
	 * Each catalog face declares the face it is, because the sidebar
	 * arm cannot infer it without a WooCommerce conditional no unit test
	 * can reach: the archive marks the queried term, the shop lists the
	 * series unmarked under its own "All Patterns" link. Dropping the
	 * attribute from the shop template would leave the shop's sidebar
	 * empty instead of failing loudly.
	 */
	public function test_the_catalog_faces_declare_which_face_renders_the_nav(): void {
		$archive = (string) file_get_contents( Templates::path( 'series-archive' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$shop    = (string) file_get_contents( Templates::path( 'shop' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$this->assertStringContainsString( '[md_series_nav]', $archive, 'the archive marks its queried term' );
		$this->assertStringNotContainsString( '[md_series_nav face=', $archive, 'the archive must not claim the shop face' );
		$this->assertStringContainsString( '[md_series_nav face="shop"]', $shop, 'the shop declares its own face' );
	}

	/**
	 * The two catalog tools submit to the archive's base address, never to
	 * `action=""`.
	 *
	 * An empty action is resolved by the browser to the address of the page
	 * the visitor is on, page number included. A search submitted from
	 * `/shop/page/2/` then asks for `/shop/page/2/?pattern_search=…`, and
	 * WordPress resolves that page number against the narrowed result set: a
	 * search matching one pattern has no page 2, so the visitor gets a 404
	 * instead of their result. Both faces carried the empty action on both
	 * forms, so the scan is per form and per face — one template fixed and
	 * the other forgotten is exactly the state this test fails on.
	 *
	 * The state the two forms carry each other is asserted here too: the
	 * action is the base, so every parameter the submission keeps has to
	 * come from a field, not from the URL it was standing on.
	 */
	public function test_the_catalog_tools_submit_to_the_archive_base_not_the_current_page(): void {
		foreach ( array( 'shop', 'series-archive' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

			preg_match_all( '/<form[^>]*class="pattern-catalog__(search|sort)"[^>]*>/', $source, $forms );
			$this->assertCount( 2, $forms[0], "{$face} carries both tools" );
			foreach ( $forms[0] as $form ) {
				$this->assertStringContainsString( 'action="[md_archive_form_action]"', $form, "{$face}: the tool submits to the archive base" );
				$this->assertStringNotContainsString( 'action=""', $form, "{$face}: an empty action submits to the current page, page number included" );
				$this->assertStringContainsString( 'method="get"', $form, "{$face}: both tools are GET forms" );
			}

			// Each tool carries the other's state as a field, because the
			// address no longer does.
			$this->assertMatchesRegularExpression(
				'/<form[^>]*pattern-catalog__search[^>]*>.*?\[md_preserve_param name="sort_by" except="pattern_search"\]/s',
				$source,
				"{$face}: the search form carries the order"
			);
			$this->assertMatchesRegularExpression(
				'/<form[^>]*pattern-catalog__sort[^>]*>.*?\[md_preserve_param name="pattern_search" except="sort_by"\]/s',
				$source,
				"{$face}: the sort form carries the search"
			);
			$this->assertSame( 3, substr_count( $source, '[md_sort_selected value=' ), "{$face}: each option asks the data arm whether it is the one in force" );
			$this->assertStringContainsString( '[md_search_value]', $source, "{$face}: the search box keeps its term" );
		}
	}

	/**
	 * The header's search is the site-wide one; the contextual search is each
	 * catalog face's own tool. The split is the old site's and is deliberate.
	 *
	 * Both catalog faces render a search box, and both submit to their own base
	 * (`[md_archive_form_action]`): searching inside Organic stays in Organic.
	 * The header's box is a different control with a different job — it is
	 * rendered on every page, so it cannot know which series the visitor was
	 * browsing, and the old site's header posted to Shopify's global `/search`
	 * carrying no collection context at all (its theme settings carry no
	 * scoped-search option either). "Search from a series" was therefore never
	 * an affordance this UX offered, and making the header contextual would be
	 * a new feature needing new state, not a fix.
	 *
	 * This test exists so that decision is visible to whoever next wonders why
	 * the header does not preserve the series: it fails if the header acquires
	 * the catalog's term-aware action, which would mean someone changed the
	 * behaviour without deciding to.
	 */
	public function test_the_header_search_stays_site_wide_while_the_catalog_tools_are_contextual(): void {
		$header = (string) file_get_contents( Templates::path( 'partials/header.html', true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$forms = array();
		preg_match_all( "/<form action='([^']*)' method='get' role='search' class='search'>/", $header, $forms );
		$this->assertCount( 1, $forms[0], 'the header carries exactly one site-wide search form' );
		$this->assertSame(
			'/patterns/',
			$forms[1][0],
			'the header search submits to the catalogue: it is rendered on every page and has no series to preserve'
		);

		// Comments are prose about the code, not attributes a browser parses —
		// the same rule the placeholder guard follows. The comment beside the
		// form names the catalog action while explaining why the header does
		// not use it, and matching that would make this guard refuse its own
		// documentation.
		$markup = (string) preg_replace( '/<!--.*?-->/s', '', $header );
		$this->assertStringNotContainsString(
			'md_archive_form_action',
			$markup,
			'the header must not borrow the catalog faces\' term-aware action'
		);

		// The contextual search is the catalog tools', and both faces use it.
		foreach ( array( 'shop', 'series-archive' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$this->assertStringContainsString(
				'class="pattern-catalog__search" action="[md_archive_form_action]"',
				$source,
				"{$face}: the face's own search is the contextual one"
			);
		}
	}

	/**
	 * No LiveCanvas template links a retired or non-canonical path.
	 *
	 * Three families, one rule each:
	 *
	 * - `/pages/…` was the old site's shell prefix (every page moved to the
	 *   root level, `contact` retired to /inquiry/) and would send a visitor
	 *   through a redirect, or — for `/pages/contact` — to a 404.
	 * - `/blogs/` was the old site's post handle; the blog index is `/blog/`.
	 * - **Shopify's two URL words are not the site's** (owner ruling
	 *   2026-10-30, GOAL.md 深夜 2): the catalogue is `/patterns/`, a series is
	 *   `/pattern-category/<slug>/`, and `/shop/…`, `/collections/…` are the
	 *   legacy aliases the 301 layer in `mu-plugins/md-legacy-urls.php`
	 *   answers. A template that emits one of them would make every visitor
	 *   from that template take a redirect on the site's own link, which is
	 *   the exact defect this guard exists for. The scan covers attributes as
	 *   well as text, because an `href` is what a visitor follows.
	 *
	 * Comments are prose about the code, not paths a browser resolves, so they
	 * are stripped first — the same rule the header's search guard follows.
	 * This guard's own file names the legacy paths in this docblock, and a
	 * scan that refused its own documentation would be unmaintainable.
	 */
	public function test_no_template_links_a_retired_or_non_canonical_path(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$raw      = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$source   = (string) preg_replace( '/<!--.*?-->/s', '', $raw );
			$relative = basename( (string) $file );
			$this->assertStringNotContainsString( '/pages/', $source, "{$relative} links the retired /pages/ prefix" );
			$this->assertStringNotContainsString( '/blogs/', $source, "{$relative} links the old blog handle; the blog index is /blog/" );
			$this->assertDoesNotMatchRegularExpression(
				'#/shop(?:/|["\'])#',
				$source,
				"{$relative} links the legacy /shop/ catalogue; the ruling makes it /patterns/ (owner, 2026-10-30)"
			);
			$this->assertDoesNotMatchRegularExpression(
				'#/collections(?:/|["\'])#',
				$source,
				"{$relative} links the legacy /collections/ series base; the ruling makes it /pattern-category/ (owner, 2026-10-30)"
			);
		}
	}

	/**
	 * No customer-visible material word outside the owner's single ruling
	 * (2026-10-24 revision 6: Stainless Steel 304 / 316 is the only
	 * material the site names; the old five-material lists are bad data,
	 * not migrated).
	 *
	 * The scan reads the templates' *text* — tag contents with every
	 * attribute removed — because the ruling is about what a visitor
	 * reads. Asset paths legitimately carry source filenames
	 * (`our-paris-lounge-brass-screen-hero-2048w.webp`) and the media
	 * pipeline owns those names; masking them here would either fail on
	 * every rename or push the copy fix out of view. `alt` text is kept
	 * inside the scan: it is read aloud and indexed.
	 *
	 * "Bronze", "gold" and "champagne" are finish names (PVD colours in
	 * the approved copy), not materials, so they are not in the set.
	 */
	public function test_no_template_names_a_material_outside_the_single_ruling(): void {
		$forbidden = 'brass|copper|aluminium|aluminum|corten|mild\s+steel';
		$dir       = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$scanned   = 0;
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			// Keep alt text (it is customer-visible), drop every other attribute.
			preg_match_all( '/\balt=(["\'])(.*?)\1/s', $source, $alts );
			$visible = implode( "\n", array_map( 'html_entity_decode', $alts[2] ) )
				. "\n" . preg_replace( '/<[^>]*>/s', "\n", $source );
			preg_match_all( '/\b(' . $forbidden . ')\b/i', $visible, $hits );
			$relative = str_replace( $dir . '/', '', (string) $file );
			$this->assertSame(
				array(),
				$hits[0],
				"{$relative} names a material outside the Stainless Steel (304 / 316) ruling: " . implode( ', ', array_unique( $hits[0] ) )
			);
			++$scanned;
		}
		$this->assertGreaterThan( 20, $scanned, 'the scan walks the template inventory' );
	}

	/**
	 * The ruling's positive half: the two faces that discuss materials
	 * at length name the sanctioned wording, so removing a forbidden word
	 * cannot quietly leave the page saying nothing about material.
	 */
	public function test_the_material_faces_name_the_sanctioned_material(): void {
		foreach ( array( 'family-stainless-steel', 'faqs' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$this->assertMatchesRegularExpression( '/\b304\b/', $source, "{$face} names 304" );
			$this->assertMatchesRegularExpression( '/\b316\b/', $source, "{$face} names 316" );
		}
	}

	/**
	 * No family or FAQ face quotes a price, a discount tier, or a cart
	 * transaction. The business model is inquiry-only and WooCommerce
	 * carries every product fact (owner ruling 2026-10-26), so a figure
	 * printed on a marketing face is a second, unstamped source for the
	 * same commercial fact — and the sourcing pages contradicted each
	 * other: "MOQ from 1 sqm" on two faces, "10 pieces" and "5 pieces"
	 * on a third, "50–100 sqm" in the FAQs, none of them backed by an
	 * owner decision or a Woo field.
	 *
	 * The scan reads visible text, as the material guard above does,
	 * because the defect is what a visitor reads. It is deliberately
	 * *not* run across the whole tree: `privacy.html` names "no
	 * checkout, no payment" as the site's own disclosure, and
	 * `custom-screens.html` says "PVD Inventory" about stocked sheet.
	 * Both are true statements the pattern would misread, so the scope
	 * is the commercial faces where a price claim would be a defect.
	 */
	public function test_no_commercial_face_quotes_a_price_or_a_cart_transaction(): void {
		$faces     = array(
			'family-laser-cut-geometric',
			'family-glass-hybrid',
			'family-outdoor-privacy',
			'family-chinese-style',
			'family-stainless-steel',
			'family-laser-cut-panels',
			'family-perforated-panels',
			'family-expanded-metal',
			'manufacturing',
			'custom-screens',
			'faqs',
		);
		$forbidden = array(
			'currency'         => '/\$\s?\d|\bUSD\b|\bUS\s?\$/i',
			'discount'         => '/\d+\s*%\s*off\b|\boff list\b|\blist price\b|\bvolume discount\b/i',
			'cart'             => '/\bcart\b|\bcheckout\b|\badd to cart\b|\bin stock\b|\bout of stock\b/i',
			// An MOQ followed within a sentence by a figure is the
			// commercial term the faces contradicted each other on
			// ("1 sqm" vs "10 pieces" vs "50–100 sqm"). The bare
			// category heading "Pricing & MOQ" carries no figure and
			// is not one of these.
			'minimum-quantity' => '/\bMOQ\b[^.\n]{0,60}?\b\d|\bminimum\s+order\b/i',
		);
		foreach ( $faces as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			// Keep alt text (customer-visible), drop every other attribute.
			preg_match_all( '/\balt=(["\'])(.*?)\1/s', $source, $alts );
			$visible = implode( "\n", array_map( 'html_entity_decode', $alts[2] ) )
				. "\n" . preg_replace( '/<[^>]*>/s', "\n", $source );
			foreach ( $forbidden as $kind => $pattern ) {
				preg_match_all( $pattern, html_entity_decode( $visible ), $hits );
				$this->assertSame(
					array(),
					array_values( array_unique( $hits[0] ) ),
					"{$face} carries a {$kind} claim the inquiry-only model contradicts: " . implode( ', ', array_unique( $hits[0] ) )
				);
			}
		}
	}

	/**
	 * The inquiry-only model's positive half, on the same reasoning as
	 * the material test above: a face that used to answer a cost
	 * question still answers it, by explaining what shapes the quote.
	 * Deleting the price tables without this would leave the FAQ silent
	 * on the question a buyer asks first.
	 */
	public function test_the_pricing_faces_explain_how_the_quote_is_built(): void {
		foreach ( array( 'family-expanded-metal', 'family-perforated-panels', 'family-laser-cut-geometric' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$this->assertMatchesRegularExpression( '/how .{0,80}quoted|what shapes the quote|line-item quote/i', $source, "{$face} still explains how a quote is built" );
		}
	}

	/**
	 * No customer-visible template calls a pattern a "product" (owner
	 * ruling 2026-10-24 revision 5: the customer-facing vocabulary is
	 * "pattern" on every surface — copy, spec tables, breadcrumbs,
	 * calls to action, alt text — while WooCommerce's internal
	 * mechanisms and the admin keep their own names).
	 *
	 * The scan is built so it needs no allow-list. It reads the
	 * *rendered* text — `alt` text kept, because it is announced and
	 * indexed, every other attribute dropped, HTML comments dropped —
	 * and that structurally excludes the three places `product` is
	 * legitimate here:
	 *
	 * - `#product-catalog` anchors and `data-psd-url` values, which are
	 *   attribute values and in-page link targets, not text;
	 * - `post_type`/`product_cat`/schema `@type: Product`, which live in
	 *   PHP and JSON-LD, not in these templates;
	 * - the header's inert `data-locales` JSON map, likewise an
	 *   attribute value.
	 *
	 * A second, deliberate exclusion is the word "products" in the
	 * sense of *goods*, not patterns: the FAQ's cleaning advice tells a
	 * reader to avoid abrasive pads and scouring cleaners. That
	 * sentence was rewritten away from the ambiguity when this guard
	 * landed; the pattern below flags it again if it returns, which is
	 * intended — the face can say "cleaners" and be both correct and
	 * unambiguous.
	 */
	public function test_no_customer_template_calls_a_pattern_a_product(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

			// The visible text: comments out, attributes out, tags out,
			// entities decoded — plus the alt text, which lives inside an
			// attribute but is read aloud and indexed. Group 2 is the alt
			// value; group 1 is only the quote character.
			preg_match_all( '/\balt=(["\'])(.*?)\1/s', $source, $alts );
			$text = preg_replace( '/<!--.*?-->/s', "\n", $source );
			$text = preg_replace( '/<[^>]*>/s', "\n", (string) $text );
			$text = html_entity_decode( implode( "\n", $alts[2] ) . "\n" . html_entity_decode( (string) $text ) );

			preg_match_all( '/\b(products?)\b/i', $text, $hits );
			$relative = str_replace( $dir . '/', '', (string) $file );
			$this->assertSame(
				array(),
				array_values( array_unique( array_map( 'strtolower', $hits[0] ) ) ),
				"{$relative} calls a pattern a product in customer-visible text: " . implode( ', ', array_unique( $hits[0] ) )
			);
		}
	}

	/**
	 * The vocabulary ruling's positive half, on the same reasoning as
	 * the material and pricing guards: the faces a visitor reaches by
	 * browsing must still *say* pattern, so a future edit cannot pass by
	 * deleting the word instead of using it. The shop and its archives
	 * are where the catalogue vocabulary is set.
	 */
	public function test_the_catalogue_faces_speak_of_patterns(): void {
		foreach ( array( 'shop', 'series-archive' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$this->assertMatchesRegularExpression( '/\bPatterns\b/', $source, "{$face} names the catalogue in pattern words" );
			$this->assertMatchesRegularExpression( '/pattern detail page|pattern pages|pattern link/i', $source, "{$face} links to pattern pages, not product pages" );
		}
	}

	/**
	 * A numeric claim on a customer face must be traceable or contradicted.
	 *
	 * This guard used to ban this whole family outright, on the reasoning that the
	 * site carried the same fact in several contradictory versions (countries as
	 * 15+/20+/30+/40+, PVD finishes as 10+/18/20+, floor area as 12,000m2 and
	 * 15,000+ sqm) so none of them could be trusted.
	 *
	 * That reasoning no longer holds up. Every one of those figures is verbatim in
	 * data/shopify-public/pages-content.md — the frozen old site published all of
	 * them, including the contradictions. A ban therefore does not protect a
	 * reader from an unsourced number; it deletes wording this business has been
	 * publishing, which the owner ruled against ("metal-divider.com 首先内容要保持一致,
	 * 其次才是考虑升级"). Checked one by one: 30+ countries, 15,000+ sqm, 10+ PVD,
	 * 10+ finishes, 40+ countries, 20+ PVD, 15+ countries, mockups in 24 hours,
	 * ISO 9001, 12,000m2, 500 tons, 20+ Countries — all 12 are in the frozen text.
	 *
	 * So the test asks the two questions that can actually be answered:
	 *
	 * 1. traceable — the matched text appears in the frozen old site, whitespace
	 *    and case folded. A number nobody ever published fails here.
	 * 2. not contradicted — for the one figure a measurement overrides, the
	 *    published pattern count. The catalogue holds 146 patterns, counted in the
	 *    database and in the collection memberships; the old site's "200+ Ready
	 *    Patterns" is therefore false about our own inventory no matter who said
	 *    it, and having a source does not make a number about us true.
	 *
	 * The contradictions themselves are real and remain open — 15+/20+/30+/40+
	 * countries cannot all be right. They are inherited, not created here, and
	 * settling them needs the owner's real figure, not a test that quietly deletes
	 * the evidence. Recorded in docs/acceptance/2026-10-29-status.md.
	 *
	 * Salt-spray hours and service-life years use the same traceability rule, in
	 * test_salt_spray_and_life_figures_match_the_frozen_old_site().
	 */
	public function test_no_unverified_numeric_claims_on_customer_faces(): void {
		$dir    = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$frozen = $this->folded_old_site_text();
		$rules  = array(
			'ISO certification'    => "/\\bISO\\b|\\bISO[\\s-]?9001\\b|\\bwe(?:'re| are)\\s+certified\\b|\\bcertified facility\\b/i",
			'invented sheet stock' => '/\b\d{3}\s*tons\b/i',
			'catalogue count'      => '/\b(?!146\b)\d{2,3}\+\s*(?:Ready\s+)?(?:Patterns?|designs?)\b/i',
			'PVD finish count'     => '/\b\d{1,2}\+\s*PVD|\bPVD\s*\(\s*\d{1,2}\+|\b\d{1,2}\s+PVD\s+finishes/i',
			'finish count'         => '/\b\d{1,2}\+\s*finishes\b/i',
			'country count'        => '/\b\d{2}\+\s*(?:countries|Countries)\b/i',
			'floor area'           => '/\b\d{1,2},\d{3}\s*m2\b|\b\d{1,2},\d{3}\+\s*sqm\b/i',
			'mockups in 24 hours'  => '/mockups?\s+in\s+24\s*hours?/i',
		);
		$found  = array();
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = str_replace( $dir . '/', '', (string) $file );
			$source   = (string) preg_replace( '/<!--.*?-->/s', "\n", $source );
			$source   = (string) preg_replace( '/<[^>]*>/s', "\n", $source );
			foreach ( $rules as $label => $pattern ) {
				if ( ! preg_match( $pattern, $source, $hit ) ) {
					continue;
				}
				// The catalogue count is the one figure a measurement overrides:
				// the inventory is 146, so any other published count is false
				// about us and no old-site wording can make it true.
				if ( 'catalogue count' === $label ) {
					$found[] = "{$relative}: {$label} ({$hit[0]}) contradicts the measured inventory of 146";
					continue;
				}
				if ( false === strpos( $frozen, $this->fold_claim( $hit[0] ) ) ) {
					$found[] = "{$relative}: {$label} ({$hit[0]}) is not in the frozen old site";
				}
			}
		}
		$this->assertSame( array(), $found, 'numeric claims that are invented or contradicted: ' . implode( '; ', $found ) );
	}

	/**
	 * The frozen old site's text, folded for substring comparison.
	 *
	 * Whitespace collapses to single spaces because the two sites wrap lines
	 * differently: the old about page prints "20+ PVD Colors" as a number on one
	 * line and its label on the next, and a byte comparison would call that
	 * "missing" while a reader sees one phrase.
	 */
	private function folded_old_site_text(): string {
		return $this->fold_claim( $this->frozen_old_site_text() );
	}

	/**
	 * Lowercase, collapse whitespace, unify the dashes the two sites disagree on.
	 *
	 * @param string $text Text to fold.
	 */
	private function fold_claim( string $text ): string {
		$text = strtolower( str_replace( array( "\u{2013}", "\u{2014}", "\u{00a0}" ), array( '-', '-', ' ' ), $text ) );
		return (string) preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * Salt-spray hours and service-life years must be the frozen old site's words.
	 *
	 * An outright ban was the wrong shape: the frozen old site published these
	 * figures, so a template may repeat that exact text and must not invent one.
	 * The source of record is data/shopify-public/pages-content.md.
	 */
	public function test_salt_spray_and_life_figures_match_the_frozen_old_site(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$found = array();
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = str_replace( $dir . '/', '', (string) $file );
			foreach ( $this->untraced_life_figures( $source ) as $figure ) {
				$found[] = "{$relative} ({$figure})";
			}
		}
		$this->assertSame(
			array(),
			$found,
			'salt-spray or life figure not verbatim in data/shopify-public/pages-content.md: ' . implode( '; ', $found )
		);
	}

	/**
	 * An hour figure the frozen old site never published still fails.
	 */
	public function test_an_invented_salt_spray_hour_figure_fails_the_parity_lock(): void {
		$this->assertSame(
			array( '12,345 hr' ),
			$this->untraced_life_figures( '<p>12,345 hr salt spray</p>' )
		);
	}

	/**
	 * A published salt-spray figure passes, and the detector has to see it.
	 *
	 * A pattern that matches nothing would otherwise call every figure legal.
	 */
	public function test_a_published_old_site_salt_spray_figure_passes_the_parity_lock(): void {
		$html = '<p>4,000+ hr ASTM B117</p>';
		$this->assertSame( array( '4,000+ hr' ), $this->life_figures( $html ) );
		$this->assertSame( array(), $this->untraced_life_figures( $html ) );
	}

	/**
	 * An en-dash service-life range is matched, and only the published dash passes.
	 *
	 * Without the `/u` flag the en dash is invisible, so the invented range
	 * would not be reported and this test could not fail.
	 */
	public function test_an_en_dash_service_life_is_a_parity_lock_not_a_blind_spot(): void {
		$invented = '12' . "\u{2013}" . '34 years';
		$this->assertSame(
			array( $invented ),
			$this->untraced_life_figures( '<p>Rated ' . $invented . ' outdoors.</p>' )
		);

		$published = '10' . "\u{2013}" . '20 year';
		$this->assertSame( array( $published ), $this->life_figures( '<p>a ' . $published . ' warranty</p>' ) );
		$this->assertSame( array(), $this->untraced_life_figures( '<p>a ' . $published . ' warranty</p>' ) );

		$hyphen = '10-20 year';
		$this->assertSame(
			array( $hyphen ),
			$this->untraced_life_figures( '<p>a ' . $hyphen . ' warranty</p>' )
		);
	}

	/**
	 * Reply windows are not salt-spray claims.
	 *
	 * `24 hours`, `48 hours`, `72 Hours` and `4 hours` stay legal, so the hour
	 * branch needs four digits or an explicit plus.
	 */
	public function test_support_hours_are_not_salt_spray_figures(): void {
		$html = '<p>Reply within 24 hours. Drawing in 48 hours. Sample in 72 Hours. Call in 4 hours.</p>';
		$this->assertSame( array(), $this->life_figures( $html ) );
	}

	/**
	 * The inquiry reply promise reads 24 hours everywhere it is made.
	 *
	 * Three of the eight competitor sites state a reply time; converted to one
	 * clock the average and the median are both 24 hours, so that is the
	 * promise the owner's 2026-10-30 ruling adopts
	 * (.scratch/competitor-2026-10-30/inquiry-response-time.md).
	 *
	 * The clock is pinned by banning the two other wordings outright rather
	 * than by scanning around the word "reply": every case that shipped wrong
	 * here dressed the promise as something else — a button label ("Get a
	 * same-day quote"), a confirmation ("confirms the configuration within
	 * one business day"), a flow table's value cell ("Response Window" on one
	 * line, "Same day" on the next). A frame-word scan caught three of those
	 * four and missed the button, which is how this test failed its own
	 * self-check the first time.
	 *
	 * Two occurrences are legitimately "same day" and are named here, not
	 * matched loosely: the QC and packing stages' own duration (`Lead — Same
	 * day`), and one project's production note ("same-day template fit").
	 * Neither is a reply promise, and both must still exist, so the ban
	 * cannot be satisfied by deleting the distinction.
	 */
	public function test_the_inquiry_reply_promise_is_always_24_hours(): void {
		$dir       = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$offenders = array();
		$allowed   = 0;
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = str_replace( $dir . '/', '', (string) $file );

			// The sanctioned "same day" uses, counted before they are removed.
			$allowed += (int) preg_match_all( '/Lead — Same day|same-day template fit/', $source );
			$scrubbed = (string) preg_replace( '/Lead — Same day|same-day template fit/', "\0", $source );

			if ( ! preg_match_all( '/\b(?:one|1|a)\s+business\s+day|\bsame\s+business\s+day|\bsame[-\s]day\b/i', $scrubbed, $hits, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $hits[0] as list( $hit, $offset ) ) {
				// A bare "Same day" is the value under a process card's
				// `Lead Time` label — the QC or packing stage's own duration.
				// The same markup shape carries the reply window, so the label
				// above it is what decides; "Response Window" is not exempt.
				$before = substr( $scrubbed, max( 0, $offset - 300 ), min( 300, $offset ) );
				if ( preg_match( '/Lead Time/i', $before ) && ! preg_match( '/Response/i', $before ) ) {
					++$allowed;
					continue;
				}
				$offenders[] = "{$relative}: {$hit}";
			}
		}
		$this->assertSame( array(), $offenders, 'wordings that promise a reply clock other than 24 hours: ' . implode( '; ', $offenders ) );
		$this->assertGreaterThan( 0, $allowed, 'the QC/packing lead labels this ban deliberately excludes still exist' );
	}

	/**
	 * The privacy page states no retention period in numbers.
	 *
	 * Seven of the eight competitor policies give no numeric retention period
	 * for inquiry data; the one that does states 60 days after service
	 * termination, a single sample that cannot be averaged into a figure
	 * (.scratch/competitor-2026-10-30/privacy-inquiry-retention.md). The
	 * owner's 2026-10-30 ruling takes the competitor position as ours, so the
	 * page says how long in words and never in a count of years or days.
	 */
	public function test_the_privacy_page_states_no_numeric_retention_period(): void {
		$source = (string) file_get_contents( Templates::path( 'privacy' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$text   = (string) preg_replace( '/<!--.*?-->/s', "\n", $source );
		$text   = html_entity_decode( (string) preg_replace( '/<[^>]*>/s', "\n", $text ) );

		$pattern = '/\d+\s*(?:years?|months?|days?)\b/i';

		// The detector fires on the wording this page used to carry. Without
		// this half the test would pass on any page that merely omits the
		// sentence, which is not the contract.
		$this->assertMatchesRegularExpression(
			$pattern,
			'Inquiry records are kept for 5 years — the tracking cycle of a B2B project — then deleted or anonymized.',
			'the detector recognises a numeric retention period'
		);

		preg_match_all( '/inquiry records[^.]*\./i', $text, $sentences );
		$this->assertNotEmpty( $sentences[0], 'the retention section names the inquiry records' );
		foreach ( $sentences[0] as $sentence ) {
			$this->assertDoesNotMatchRegularExpression(
				$pattern,
				$sentence,
				'the inquiry-record sentence states a number of years, months or days: ' . trim( $sentence )
			);
		}
	}

	/**
	 * A page that shows both accuracy figures explains what each one measures.
	 *
	 * ±0.03 mm is panel-to-panel repeat accuracy and ±0.1 mm is single-part
	 * cutting tolerance. Two unexplained numbers on one page read as a
	 * contradiction; the note names both so a specifier can tell which applies.
	 */
	public function test_pages_showing_both_accuracy_figures_explain_them(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			if ( false === strpos( $source, '±0.03' ) || false === strpos( $source, '±0.1' ) ) {
				continue;
			}
			$relative = str_replace( $dir . '/', '', (string) $file );
			$this->assertStringContainsString(
				'panel-to-panel consistency',
				$source,
				"{$relative} shows both ±0.03mm and ±0.1mm without saying what each measures"
			);
		}
	}

	/**
	 * The gallery says which scenes are renderings.
	 *
	 * 18 of 23 cards carried "visualization" in their alt while the page showed
	 * no such word: a visitor read the whole gallery as completed projects. The
	 * alt text keeps the disclosure for screen readers, and the visible note
	 * makes it available to everyone. Every non-project image must carry it.
	 */
	public function test_the_gallery_discloses_which_scenes_are_renderings(): void {
		$source = (string) file_get_contents( Templates::path( 'project-gallery' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.

		$text = (string) preg_replace( '/<!--.*?-->/s', "\n", $source );
		$text = (string) preg_replace( '/<[^>]*>/s', "\n", $text );
		$this->assertMatchesRegularExpression(
			'/visualization/i',
			html_entity_decode( $text ),
			'the page explains what a visualization label means'
		);

		preg_match_all( '/\balt="([^"]*)"/i', $source, $alts );
		$unlabelled = array();
		foreach ( $alts[1] as $alt ) {
			if ( '' === trim( $alt ) ) {
				continue;
			}
			if ( false === stripos( $alt, 'visualization' ) && false === stripos( $alt, 'project image' ) ) {
				$unlabelled[] = $alt;
			}
		}
		$this->assertSame( array(), $unlabelled, 'gallery scenes with neither a visualization nor a project-image label: ' . implode( ' | ', $unlabelled ) );
	}

	/**
	 * The four blog faces render their design source, not the source text:
	 * every Tangible loop/field sits inside the <tangible> processing
	 * boundary LiveCanvas renders, no de-linker plugin shortcode survives,
	 * and the fork's tv- id family is mapped to this site's md- one.
	 */
	public function test_the_blog_faces_carry_no_unrendered_source(): void {
		foreach ( array( 'blog-index', 'blog-single', 'blog-category', 'blog-search' ) as $face ) {
			$source = (string) file_get_contents( Templates::path( $face ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$this->assertStringContainsString( '<tangible', $source, "{$face} carries the Tangible processing boundary" );
			$this->assertStringContainsString( '</tangible>', $source, "{$face} closes the Tangible processing boundary" );
			$this->assertStringNotContainsString( '[linker_', $source, "{$face} carries no de-linker plugin shortcode" );
			$this->assertStringNotContainsString( 'tv-blog-', $source, "{$face} carries no source-site id family" );
		}
	}

	/**
	 * Zero inline styles in the templates (AGENTS.md §5, spec 19 §4.2 T2,
	 * ticket #47): no style= attribute, no <style> block — composition in
	 * the template, styles in the WindPress volume. The scan is the whole
	 * tree, so a future face cannot reintroduce the debt unseen; the one
	 * exception is the marquee's Alpine :style binding, a runtime
	 * expression (interaction, not chrome), which carries no static value.
	 */
	public function test_the_templates_carry_no_inline_styles(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$this->assertGreaterThan( 20, iterator_count( $files ), 'the tree the scan walks is the template inventory' );
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = basename( (string) $file );
			// The attribute form — whitespace, then style= — in either quote
			// style; the Alpine :style binding (colon-prefixed) is not one.
			preg_match_all( '/(?<=\s)style=/', $source, $attributes );
			$this->assertSame( array(), $attributes[0], "{$relative} carries style= attributes" );
			$this->assertStringNotContainsString( '<style', $source, "{$relative} carries a <style> block" );
		}
	}

	/**
	 * The eight download cards on /faqs/ are Metal Divider files on this
	 * site. A third-party host, a missing path, or an empty card fails.
	 * Format chips name the file that is actually linked. The old "5
	 * formats" count does not.
	 */
	public function test_faq_downloads_are_own_site_files_without_empty_cards(): void {
		$source = (string) file_get_contents( Templates::path( 'faqs' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$paths  = array(
			'/wp-content/uploads/2026/10/pattern-catalog.pdf',
			'/wp-content/uploads/2026/10/perforated-data-sheet.pdf',
			'/wp-content/uploads/2026/10/metal-divider-cad-details.zip',
			'/wp-content/uploads/2026/10/installation-guide.pdf',
			'/wp-content/uploads/2026/10/finish-reference.pdf',
			'/wp-content/uploads/2026/10/project-specification.pdf',
			'/wp-content/uploads/2026/10/metal-divider-revit-pack.zip',
			'/wp-content/uploads/2026/10/stainless-finish-care.pdf',
		);
		foreach ( $paths as $path ) {
			$this->assertSame( 1, substr_count( $source, 'href="' . $path . '"' ), $path );
		}

		preg_match_all( '/\bhref=(["\'])([^"\']*)\1/i', $source, $hrefs );
		$external = array();
		foreach ( $hrefs[2] as $href ) {
			if ( preg_match( '#^(?:https?:)?//#i', $href ) && ! str_starts_with( $href, 'https://wa.me/85264731687' ) ) {
				$external[] = $href;
			}
		}
		$this->assertSame( array(), $external, 'faqs.html has a third-party href' );
		$this->assertSame( 0, preg_match( '/brilliant|zahner/i', $source ) );
		$this->assertStringNotContainsString( 'Source: Brilliant', $source );
		$this->assertStringNotContainsString( 'Source: Zahner', $source );
		$this->assertDoesNotMatchRegularExpression(
			'/Formats<\/p>\s*<p[^>]*>\s*5\s*<\/p>/',
			$source
		);
		$this->assertStringContainsString( '>304/316<', $source );
		$this->assertStringContainsString( 'Stainless Steel Finish &amp; Care', $source );
		$this->assertStringNotContainsString( 'QC &amp; Certificate Pack', $source );
		$this->assertStringNotContainsString( 'EPD', $source );

		$start = strpos( $source, '<div id="faq-download-files"' );
		$end   = strpos( $source, '<div id="faq-download-questions"' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$this->assertLessThan( $end, $start );
		$section = substr( $source, (int) $start, (int) $end - (int) $start );
		$this->assertStringContainsString(
			'These eight files are Metal Divider documents for Stainless Steel (304 / 316).',
			$section
		);
		$this->assertDoesNotMatchRegularExpression(
			'/id="faq-download-sources"[^>]*(hidden|aria-hidden|sr-only)/',
			$section
		);

		preg_match_all( '/<a\b[^>]*data-source-button="download_file"[^>]*>.*?<\/a>/s', $section, $cards );
		$this->assertCount( 8, $cards[0] );
		foreach ( $cards[0] as $card ) {
			$this->assertMatchesRegularExpression( '/href="\/wp-content\/uploads\/2026\/10\/[^"]+"/', $card );
			$text = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $card ), ENT_QUOTES | ENT_HTML5 ) ) );
			$this->assertNotSame( '', $text, $card );
			$this->assertStringNotContainsString( 'Source:', $text );
		}
		foreach ( array(
			'Download Catalog',
			'Download Data Sheet',
			'Download CAD Pack',
			'Download Guide',
			'Download Spec Card',
			'Download Spec',
			'Download Revit Pack',
			'Download Care Note',
		) as $button ) {
			$this->assertSame( 1, substr_count( $section, '>' . $button . '<' ), $button );
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $section );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$this->assertTrue( $loaded );
		$empty        = array();
		$text_content = 'textContent';
		$tag_name     = 'tagName';
		foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
			if ( false === strpos( $node->getAttribute( 'class' ), 'rounded-2xl' ) ) {
				continue;
			}
			$text = trim( (string) preg_replace( '/\s+/', ' ', $node->{$text_content} ) );
			if ( '' !== $text ) {
				continue;
			}
			if ( $node->getElementsByTagName( 'img' )->length > 0 || $node->getElementsByTagName( 'svg' )->length > 0 ) {
				continue;
			}
			$empty[] = $node->{$tag_name} . ' ' . substr( $node->getAttribute( 'class' ), 0, 80 );
		}
		$this->assertSame( array(), $empty, 'empty download cards' );
	}

	/**
	 * Salt-spray hours and service-life years, including en-dash ranges.
	 *
	 * The `/u` flag is load-bearing. Every year range on these pages uses an
	 * en dash (U+2013). Without it PCRE walks the dash byte by byte, no range
	 * matches, and the lock is blind to the claims it exists to check.
	 */
	private function life_figure_pattern(): string {
		$dash = "\u{2013}\u{2014}-";
		return '/\b\d[\d,]*\s*\+\s*(?:hrs?|hours?)\b'
			. '|\b\d[\d,]{2,}\s*(?:hrs?|hours?)\b'
			. '|\b\d{1,2}\s*[' . $dash . ']\s*\d{1,2}\s*years?\b'
			. '|\b\d{1,2}\+\s*years?\b'
			. '|\b(?:holds?|lasts?)\s+\d{1,2}\s*[' . $dash . ']\s*\d{1,2}\b/iu';
	}

	/**
	 * Visible template text: comments and tags removed, as the ban scan does.
	 *
	 * @param string $source Template source, comments included.
	 */
	private function visible_template_text( string $source ): string {
		$text = (string) preg_replace( '/<!--.*?-->/s', "\n", $source );
		return (string) preg_replace( '/<[^>]*>/s', "\n", $text );
	}

	/**
	 * The frozen old-site extract, the parity source of record.
	 */
	private function frozen_old_site_text(): string {
		$root = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName(), 5 );
		$path = $root . '/data/shopify-public/pages-content.md';
		$this->assertFileExists( $path, 'frozen old-site extract is the parity source of record' );
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
		$this->assertNotSame( '', $text, 'frozen old-site extract is empty' );
		$this->assertStringContainsString( '# PAGE: faqs', $text, 'frozen old-site extract is not the page corpus' );
		return $text;
	}

	/**
	 * Life figures the detector sees in customer-visible text.
	 *
	 * @param string $html Template source or a fixture fragment.
	 * @return array<int, string>
	 */
	private function life_figures( string $html ): array {
		if ( false === preg_match_all( $this->life_figure_pattern(), $this->visible_template_text( $html ), $hits ) ) {
			return array();
		}
		return array_values( array_unique( $hits[0] ) );
	}

	/**
	 * Life figures whose exact text is absent from the frozen old site.
	 *
	 * @param string $html Template source or a fixture fragment.
	 * @return array<int, string>
	 */
	private function untraced_life_figures( string $html ): array {
		$corpus  = $this->frozen_old_site_text();
		$missing = array();
		foreach ( $this->life_figures( $html ) as $figure ) {
			if ( false === strpos( $corpus, $figure ) ) {
				$missing[] = $figure;
			}
		}
		return $missing;
	}
}
