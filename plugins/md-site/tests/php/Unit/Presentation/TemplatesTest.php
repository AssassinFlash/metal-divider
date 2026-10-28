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
		$this->assertStringContainsString( 'href="/shop/"', $nav, 'the all-patterns link is the shop, not /collections/all' );
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
			'/shop/',
			$forms[1][0],
			'the header search submits to the shop: it is rendered on every page and has no series to preserve'
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
	 * No LiveCanvas template links a retired or non-canonical path:
	 * `/pages/…` was the old site's shell prefix (every page moved to the
	 * root level, `contact` retired to /inquiry/), and `/collections/all`
	 * is Shopify's handle for the shop, which is /shop/ here. Both would
	 * send visitors through a redirect — or, for /pages/contact, to a 404.
	 */
	public function test_no_template_links_a_retired_or_non_canonical_path(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = basename( (string) $file );
			$this->assertStringNotContainsString( '/pages/', $source, "{$relative} links the retired /pages/ prefix" );
			$this->assertStringNotContainsString( '/collections/all', $source, "{$relative} links -> /shop/" );
			$this->assertStringNotContainsString( '/blogs/', $source, "{$relative} links the old blog handle; the blog index is /blog/" );
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
	 * No unverified averaged claim reaches a customer-visible face.
	 *
	 * The site once carried the same facts in several contradictory versions
	 * (countries as 15+/20+/30+/40+, PVD finishes as 10+/18/20+, floor area as
	 * 12,000m2 and 15,000+ sqm) and promises no source supported: an ISO
	 * certification, 500 tons of sheet stock, 200+ designs against a catalogue
	 * of 146 patterns, and pattern mockups inside 24 hours. They are averages
	 * and assumptions, not measured facts, so they stay out until the owner
	 * supplies the real figure. The catalogue count is checked against the
	 * published 146 so a new number cannot be invented by typing it.
	 */
	public function test_no_unverified_numeric_claims_on_customer_faces(): void {
		$dir   = dirname( (string) ( new \ReflectionClass( Templates::class ) )->getFileName() ) . '/../../content/livecanvas';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$rules = array(
			// "certified curtain wall teams" is a real third-party credential and
			// stays legal; an ISO claim about this facility is not sourced.
			'ISO certification'          => "/\\bISO\\b|\\bISO[\\s-]?9001\\b|\\bwe(?:'re| are)\\s+certified\\b|\\bcertified facility\\b/i",
			'invented sheet stock'       => '/\b\d{3}\s*tons\b/i',
			'catalogue count'            => '/\b(?!146\b)\d{2,3}\+\s*(?:Ready\s+)?(?:Patterns?|designs?)\b/i',
			'PVD finish count'           => '/\b\d{1,2}\+\s*PVD|\bPVD\s*\(\s*\d{1,2}\+|\b\d{1,2}\s+PVD\s+finishes/i',
			'finish count'               => '/\b\d{1,2}\+\s*finishes\b/i',
			'country count'              => '/\b\d{2}\+\s*(?:countries|Countries)\b/i',
			'floor area'                 => '/\b\d{1,2},\d{3}\s*m2\b|\b\d{1,2},\d{3}\+\s*sqm\b/i',
			'mockups in 24 hours'        => '/mockups?\s+in\s+24\s*hours?/i',
			// Test hours and service life are the two figures the owner struck
			// for want of a source. The first pass matched only `1,000+ hours`
			// and `25–30 years`, so the pages kept `4,000hr+`, `4,000hr`,
			// `500hr`, `1,000 hrs`, `3–5 years`, `10–20 year warranty` and
			// `25+ Year Finish Life` — seven claims the guard was written to
			// stop. Support hours (`24 hours`, `48 hours`, `72 Hours`) and
			// working hours (`4 hours`) stay legal, which is why the hour
			// branch needs either four digits or an explicit `+`.
			//
			// The `/u` flag is load-bearing: every range on these pages uses
			// an en dash (U+2013). Without it PCRE walks the three bytes of
			// the dash individually, no range ever matches, and the guard is
			// silently blind to the exact claims it was written for.
			'salt-spray or life figures' => '/\b\d[\d,]*\s*\+\s*(?:hrs?|hours?)\b'
				. '|\b\d[\d,]{2,}\s*(?:hrs?|hours?)\b'
				. '|\b\d{1,2}\s*[–—-]\s*\d{1,2}\s*years?\b'
				. '|\b\d{1,2}\+\s*years?\b'
				. '|\b(?:holds?|lasts?)\s+\d{1,2}\s*[–—-]\s*\d{1,2}\b/iu',
		);
		$found = array();
		foreach ( $files as $file ) {
			$source   = (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file in a test.
			$relative = str_replace( $dir . '/', '', (string) $file );
			$source   = (string) preg_replace( '/<!--.*?-->/s', "\n", $source );
			$source   = (string) preg_replace( '/<[^>]*>/s', "\n", $source );
			foreach ( $rules as $label => $pattern ) {
				if ( preg_match( $pattern, $source, $hit ) ) {
					$found[] = "{$relative}: {$label} ({$hit[0]})";
				}
			}
		}
		$this->assertSame( array(), $found, 'unverified numeric claims on customer faces: ' . implode( '; ', $found ) );
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
			if ( preg_match( '#^(?:https?:)?//#i', $href ) ) {
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
}
