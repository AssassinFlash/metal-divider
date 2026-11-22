<?php
/**
 * Tests for the SEO schema filters.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\I18n\Dictionary;
use MetalDivider\Site\I18n\Languages;
use MetalDivider\Site\Seo\SchemaFilters;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

// The runtime doubles of seopress_get_service and wc_get_product live
// in tests/php/Doubles/seopress-woocommerce.php (the global namespace,
// where the filters' function_exists() probes look).

/**
 * Tests for SchemaFilters: the data-assembly hooks of the schema
 * plane — the global singleton WebSite block (without SearchAction),
 * the global singleton Organization with 512w logo and two contact
 * points, the Product's completed facts (never offers), the article's
 * entity reference, and the two-level breadcrumb trails.
 *
 * @covers \MetalDivider\Site\Seo\SchemaFilters
 */
final class SchemaFiltersTest extends TestCase {

	/**
	 * The service doubles the locator above hands out (static, because
	 * the locator is a plain function).
	 *
	 * @var array<string, object>
	 */
	public static array $services = array();

	/**
	 * The SocialOption double: the two Knowledge Graph getters the
	 * Organization filter reads (SEOPress's own names, hence the ignores).
	 */
	private function social_service(): object {
		return new class() {
			/**
			 * The knowledge email.
			 *
			 * @return string
			 */
			public function getSocialKnowledgeEmail(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SEOPress's API name.
				return 'info@metal-divider.com';
			}

			/**
			 * The knowledge phone. Still returned by the service, no longer
			 * published: the channel it belonged to is gone.
			 *
			 * @return string
			 */
			public function getSocialKnowledgePhone(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- SEOPress's API name.
				return '+85264731687';
			}
		};
	}

	/**
	 * A clean service registry and the home URL.
	 */
	public function setUp(): void { // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital -- setUp is the PHPUnit API name.
		parent::setUp();
		self::$services = array();
		WP_Mock::userFunction( 'home_url' )->andReturnUsing( static fn( string $path = '/' ) => 'https://metal-divider.com' . $path );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'untrailingslashit' )->zeroOrMoreTimes()->andReturnUsing( static fn( string $value ) => rtrim( $value, '/' ) );
	}

	/**
	 * No leaked product double or forced language between tests.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['md_wc_product_double'] );
		Languages::force_for_tests( null );
		parent::tearDown();
	}

	/**
	 * The register() hook: schema assemblies, breadcrumb trails, localized
	 * titles/descriptions/OG metadata, and wp_head JSON-LD actions.
	 */
	public function test_register_adds_the_hooks(): void {
		$filters = new SchemaFilters();
		WP_Mock::expectFilterAdded( 'seopress_schemas_website', array( $filters, 'website' ) );
		WP_Mock::expectFilterAdded( 'seopress_get_json_data_organization', array( $filters, 'organization' ) );
		WP_Mock::expectFilterAdded( 'seopress_schemas_auto_product_json', array( $filters, 'product' ) );
		WP_Mock::expectFilterAdded( 'seopress_schemas_auto_article_json', array( $filters, 'article' ) );
		WP_Mock::expectFilterAdded( 'seopress_pro_breadcrumbs_crumbs', array( $filters, 'crumbs' ) );
		WP_Mock::expectFilterAdded( 'seopress_titles_title', array( $filters, 'title' ) );
		WP_Mock::expectFilterAdded( 'seopress_titles_desc', array( $filters, 'description' ) );
		WP_Mock::expectFilterAdded( 'seopress_social_og_title', array( $filters, 'og_title' ) );
		WP_Mock::expectFilterAdded( 'seopress_social_og_desc', array( $filters, 'og_desc' ) );
		WP_Mock::expectFilterAdded( 'pre_get_document_title', array( $filters, 'title' ), 20 );
		WP_Mock::expectFilterAdded( 'seopress_pro_breadcrumbs_json_remove_home', '__return_false' );
		// The catalogue's ItemList: an action, not a filter, because it prints
		// its own block rather than amending one of SEOPress's.
		WP_Mock::expectActionAdded( 'wp_head', array( $filters, 'catalog_item_list' ), 99 );
		WP_Mock::expectActionAdded( 'wp_head', array( $filters, 'localized_faq_schema' ), 99 );

		$filters->register();

		$this->assertConditionsMet();
	}

	/**
	 * The catalogue's ItemList lists what the page renders, and only there.
	 *
	 * The defect this covers: /shop/ is WooCommerce's product archive, so
	 * `is_page('shop')` is always false and the first version of this method
	 * emitted nothing at all. A test that only checked "some JSON is printed"
	 * would have passed an empty string.
	 */
	public function test_the_catalog_item_list_lists_the_rendered_products(): void {
		WP_Mock::userFunction( 'is_shop', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_posts',
			array(
				'return' => array( 11, 12, 13 ),
			)
		);
		WP_Mock::userFunction(
			'get_permalink',
			array(
				'return' => function ( int $id ) {
					return 'https://metal-divider.com/pattern/p' . $id . '/';
				},
			)
		);
		WP_Mock::userFunction(
			'get_the_title',
			array(
				'return' => function ( int $id ) {
					return 'Pattern ' . $id;
				},
			)
		);
		WP_Mock::userFunction(
			'wp_json_encode',
			array(
				// Mirror the real call's flags: the production code passes
				// JSON_UNESCAPED_SLASHES, and a stub without them turns every
				// "/" into "\/" so a url assertion fails on the stub, not the code.
				'return' => function ( $data, $flags = 0 ) {
					return (string) json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the test stub for wp_json_encode.
				},
			)
		);

		ob_start();
		( new SchemaFilters() )->catalog_item_list();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'ItemList', $output, 'a list is emitted' );
		$this->assertStringContainsString( '"numberOfItems":3', $output, 'the count matches the items' );
		$this->assertStringContainsString( 'Pattern 11', $output, 'the items are named' );
		$this->assertStringContainsString( '/pattern/p13/', $output, 'the items carry their urls' );
	}

	/**
	 * Every other page stays list-free.
	 *
	 * The block is only meaningful on the catalogue; emitting it site-wide
	 * would tell a search engine that every page is a 24-item list.
	 */
	public function test_the_catalog_item_list_is_silent_elsewhere(): void {
		WP_Mock::userFunction( 'is_shop', array( 'return' => false ) );
		WP_Mock::userFunction( 'is_post_type_archive', array( 'return' => false ) );

		ob_start();
		( new SchemaFilters() )->catalog_item_list();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output, 'no list outside the catalogue' );
	}

	/**
	 * The WebSite block converges onto one global singleton entity at the root
	 * domain across English and non-English WPML locales, fixes `name` and
	 * `alternateName`, links `publisher` to the root Organization, and strips
	 * `potentialAction` (`SearchAction` points at `/?s=` which robots.txt disallows).
	 */
	public function test_website_is_global_singleton_without_search_action(): void {
		$filters = new SchemaFilters();

		// English home with SEOPress's long page title, bare domain alternateName, and SearchAction.
		$en = $filters->website(
			array(
				'@type'           => 'WebSite',
				'@id'             => 'https://metal-divider.com/#website',
				'url'             => 'https://metal-divider.com/',
				'name'            => 'Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider',
				'alternateName'   => 'metal-divider.com',
				'publisher'       => array( '@id' => 'https://metal-divider.com/#organization' ),
				'potentialAction' => array(
					'@type'  => 'SearchAction',
					'target' => 'https://metal-divider.com/?s={search_term_string}',
				),
			)
		);
		$this->assertSame( 'https://metal-divider.com/#website', $en['@id'] );
		$this->assertSame( 'https://metal-divider.com/', $en['url'] );
		$this->assertSame( 'MetalDivider', $en['name'] );
		$this->assertSame( 'Metal Divider', $en['alternateName'] );
		$this->assertSame( array( '@id' => 'https://metal-divider.com/#organization' ), $en['publisher'] );
		$this->assertArrayNotHasKey( 'potentialAction', $en );

		// Empty / missing alternateName normalizes to 'Metal Divider'.
		$minimal = $filters->website( array( '@type' => 'WebSite' ) );
		$this->assertSame( 'Metal Divider', $minimal['alternateName'] );
		$this->assertArrayNotHasKey( 'potentialAction', $minimal );

		// Non-English WPML page (/de/) where SEOPress emitted split /de/ entity IDs and URLs.
		Languages::force_for_tests( 'de' );
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->with( 'home' )->andReturn( 'https://metal-divider.com' );
		WP_Mock::userFunction( 'home_url' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( string $path = '/' ): string => 'https://metal-divider.com/de' . $path
		);

		$de = $filters->website(
			array(
				'@type'           => 'WebSite',
				'@id'             => 'https://metal-divider.com/de/#website',
				'url'             => 'https://metal-divider.com/de/',
				'name'            => 'Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider',
				'alternateName'   => 'https://metal-divider.com/',
				'publisher'       => array( '@id' => 'https://metal-divider.com/de/#organization' ),
				'potentialAction' => array(
					'@type'  => 'SearchAction',
					'target' => 'https://metal-divider.com/de/?s={search_term_string}',
				),
			)
		);
		$this->assertSame( 'https://metal-divider.com/#website', $de['@id'] );
		$this->assertSame( 'https://metal-divider.com/', $de['url'] );
		$this->assertSame( 'MetalDivider', $de['name'] );
		$this->assertSame( 'Metal Divider', $de['alternateName'] );
		$this->assertSame( array( '@id' => 'https://metal-divider.com/#organization' ), $de['publisher'] );
		$this->assertArrayNotHasKey( 'potentialAction', $de );
	}

	/**
	 * The Organization carries the two contact points — sales with the
	 * email, WhatsApp with the phone — both read from the Knowledge Graph
	 * fields, converges onto the root singleton identity, upgrades empty logo
	 * to the 512w PNG over https, and removes top-level telephone.
	 */
	public function test_organization_carries_the_two_contact_points(): void {
		self::$services['SocialOption'] = $this->social_service();
		$schema                         = ( new SchemaFilters() )->organization(
			array(
				'@type'        => 'Organization',
				'telephone'    => '+85264731687',
				'contactPoint' => array( array( '@type' => 'ContactPoint' ) ),
			)
		);
		$this->assertSame( 'https://metal-divider.com/#organization', $schema['@id'] );
		$this->assertSame( 'https://metal-divider.com/', $schema['url'] );
		$this->assertSame(
			'https://metal-divider.com/wp-content/plugins/md-site/assets/brand/metal-divider-icon-512.png',
			$schema['logo']
		);
		$this->assertSame(
			array(
				array(
					'@type'             => 'ContactPoint',
					'contactType'       => 'sales',
					'email'             => 'info@metal-divider.com',
					'availableLanguage' => array( 'English', 'Chinese', 'Spanish', 'Portuguese', 'German', 'French', 'Arabic', 'Indonesian', 'Vietnamese' ),
				),
				array(
					'@type'             => 'ContactPoint',
					'contactType'       => 'WhatsApp',
					'telephone'         => '+85264731687',
					'availableLanguage' => array( 'English', 'Chinese', 'Spanish', 'Portuguese', 'German', 'French', 'Arabic', 'Indonesian', 'Vietnamese' ),
				),
			),
			$schema['contactPoint']
		);
		$this->assertSame(
			array(
				'https://www.facebook.com/stainlesssteeleverywhere',
				'https://www.facebook.com/metaldivider',
				'https://www.linkedin.com/in/chester-john-1800b4312/',
			),
			$schema['sameAs']
		);
		$this->assertArrayNotHasKey( 'telephone', $schema );
	}

	/**
	 * Under non-English WPML locales, Organization converges `@id` and `url`
	 * onto the root domain and upgrades empty or legacy 240w logos (string or
	 * ImageObject) to the 512w PNG over https while enforcing https on custom logos.
	 */
	public function test_organization_converges_singleton_identity_and_upgrades_logo_to_512w(): void {
		Languages::force_for_tests( 'de' );
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->with( 'home' )->andReturn( 'https://metal-divider.com/de/' );
		WP_Mock::userFunction( 'home_url' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( string $path = '/' ): string => 'https://metal-divider.com/de' . $path
		);

		$filters       = new SchemaFilters();
		$expected_logo = 'https://metal-divider.com/wp-content/plugins/md-site/assets/brand/metal-divider-icon-512.png';

		// Legacy 240w http string logo + split /de/ entity ID and URL.
		$from_240w = $filters->organization(
			array(
				'@type' => 'Organization',
				'@id'   => 'https://metal-divider.com/de/#organization',
				'url'   => 'https://metal-divider.com/de/',
				'logo'  => 'http://metal-divider.com/wp-content/plugins/md-site/assets/brand/metal-divider-logo-240w.webp',
			)
		);
		$this->assertSame( 'https://metal-divider.com/#organization', $from_240w['@id'] );
		$this->assertSame( 'https://metal-divider.com/', $from_240w['url'] );
		$this->assertSame( $expected_logo, $from_240w['logo'] );

		// Legacy 240w ImageObject array logo with stale 240w dimensions.
		$from_image_object = $filters->organization(
			array(
				'@type' => 'Organization',
				'logo'  => array(
					'@type'      => 'ImageObject',
					'url'        => 'http://metal-divider.com/wp-content/plugins/md-site/assets/brand/metal-divider-logo-240w.webp',
					'contentUrl' => 'http://metal-divider.com/wp-content/plugins/md-site/assets/brand/metal-divider-logo-240w.webp',
					'width'      => 240,
					'height'     => 106,
				),
			)
		);
		$this->assertSame(
			array(
				'@type'      => 'ImageObject',
				'url'        => $expected_logo,
				'contentUrl' => $expected_logo,
				'width'      => 512,
				'height'     => 512,
			),
			$from_image_object['logo']
		);

		// Custom non-240w http logo keeps its path and is forced onto https.
		$from_custom = $filters->organization(
			array(
				'@type' => 'Organization',
				'logo'  => 'http://metal-divider.com/wp-content/uploads/brand-mark-512.png',
			)
		);
		$this->assertSame( 'https://metal-divider.com/wp-content/uploads/brand-mark-512.png', $from_custom['logo'] );
	}

	/**
	 * Without SEOPress's service (a site where SEOPress is absent),
	 * the Organization keeps its builder shape minus the telephone —
	 * no invented points, while confirmed brand sameAs profiles still attach.
	 */
	public function test_organization_without_seopress_invents_nothing(): void {
		$schema = ( new SchemaFilters() )->organization(
			array(
				'@type'     => 'Organization',
				'telephone' => '+85264731687',
			)
		);
		$this->assertArrayNotHasKey( 'contactPoint', $schema );
		$this->assertArrayNotHasKey( 'telephone', $schema );
		$this->assertSame(
			array(
				'https://www.facebook.com/stainlesssteeleverywhere',
				'https://www.facebook.com/metaldivider',
				'https://www.linkedin.com/in/chester-john-1800b4312/',
			),
			$schema['sameAs']
		);
	}

	/**
	 * The Product gains the material constant, the series, the ACF
	 * facts (open area omitted for the sixteen "On request" patterns),
	 * the gallery behind the featured image, the absolute @id and the
	 * QuoteAction — and never an offers key.
	 */
	public function test_product_completes_the_facts_without_offers(): void {
		WP_Mock::userFunction( 'get_the_ID' )->andReturn( 184 );
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/timothys-vine-architectural-metal-screen-pattern/' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ) {
				$meta = array(
					'_seopress_titles_desc' => 'Review the Timothy&#039;s Vine architectural metal screen pattern.',
					'open_area'             => '',
					'visual_density'        => 'dense',
					'repeat_behavior'       => 'repeating',
					'best_fit'              => 'architectural-screening',
				);
				return $meta[ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( 'https://metal-divider.com/wp-content/uploads/timothys-vine-01-2048w.webp' );
		WP_Mock::userFunction( 'wp_get_post_terms' )->andReturnUsing(
			static function ( int $id, string $tax, array $args ) {
				return 'product_cat' === $tax && array( 'fields' => 'names' ) === $args ? array( 'Organic' ) : array();
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->andReturnUsing(
			static fn( int $id ) => "https://metal-divider.com/wp-content/uploads/timothys-vine-0{$id}-2048w.webp"
		);
		// The gallery product: eight images, so the cap keeps the
		// schema at the spec's "featured + first six".
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a test double's handoff, not a plugin global.
		$GLOBALS['md_wc_product_double'] = new \FakeGalleryProduct( array( 2, 3, 4, 5, 6, 7, 8, 9 ) );

		$json = ( new SchemaFilters() )->product(
			array(
				'@type' => 'Product',
				'name'  => "Timothy's Vine Metal Screen Pattern",
				'sku'   => 'MD-1001',
				'image' => 'https://metal-divider.com/wp-content/uploads/timothys-vine-01-1024w.webp',
			)
		);

		$this->assertSame( 'https://metal-divider.com/pattern/timothys-vine-architectural-metal-screen-pattern/#product', $json['@id'] );
		$this->assertSame( "Review the Timothy's Vine architectural metal screen pattern.", $json['description'], 'the migrated per-pattern description, untrimmed and entity-decoded' );
		$this->assertSame( 'Stainless Steel (304 / 316)', $json['material'] );
		$this->assertSame( 'Organic', $json['category'] );
		$this->assertSame(
			array(
				array(
					'@type' => 'PropertyValue',
					'name'  => 'Visual density',
					'value' => 'dense',
				),
				array(
					'@type' => 'PropertyValue',
					'name'  => 'Repeat behavior',
					'value' => 'repeating',
				),
				array(
					'@type' => 'PropertyValue',
					'name'  => 'Best fit',
					'value' => 'architectural-screening',
				),
			),
			$json['additionalProperty'],
			'open area is absent (the "On request" patterns) and the other three facts carry their raw keys'
		);
		$this->assertCount( 7, $json['image'], 'featured plus the first six gallery images' );
		$this->assertSame( 'https://metal-divider.com/wp-content/uploads/timothys-vine-01-2048w.webp', $json['image'][0] );
		$this->assertSame( 'QuoteAction', $json['potentialAction']['@type'] );
		$this->assertSame( 'https://metal-divider.com/inquiry/', $json['potentialAction']['target']['urlTemplate'] );
		$this->assertArrayNotHasKey( 'offers', $json, 'no price exists, so none is asserted (spec 22 §3.2)' );
		unset( $GLOBALS['wc_product_double'] );
	}

	/**
	 * A pattern with a sourced open area carries all four facts.
	 */
	public function test_product_carries_open_area_when_sourced(): void {
		WP_Mock::userFunction( 'get_the_ID' )->andReturn( 184 );
		WP_Mock::userFunction( 'get_permalink' )->andReturn( 'https://metal-divider.com/pattern/akira-architectural-metal-screen-pattern/' );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( int $id, string $key ) {
				$meta = array(
					'_seopress_titles_desc' => 'The Akira pattern.',
					'open_area'             => '17',
					'visual_density'        => 'dense',
					'repeat_behavior'       => 'repeating',
					'best_fit'              => 'architectural-screening',
				);
				return $meta[ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'get_the_post_thumbnail_url' )->andReturn( 'https://metal-divider.com/wp-content/uploads/akira-01-2048w.webp' );
		WP_Mock::userFunction( 'wp_get_post_terms' )->andReturn( array( 'Geometric' ) );
		WP_Mock::userFunction( 'wp_get_attachment_url' )->never();
		unset( $GLOBALS['wc_product_double'] );

		$json = ( new SchemaFilters() )->product( array( '@type' => 'Product' ) );
		$this->assertCount( 4, $json['additionalProperty'] );
		$this->assertSame(
			array(
				'@type' => 'PropertyValue',
				'name'  => 'Open area',
				'value' => '17',
			),
			$json['additionalProperty'][0]
		);
		$this->assertSame( array( 'https://metal-divider.com/wp-content/uploads/akira-01-2048w.webp' ), $json['image'] );
	}

	/**
	 * The article's author and publisher are the one Organization
	 * entity, by reference (spec 22 S6).
	 */
	public function test_article_references_the_organization(): void {
		$json = ( new SchemaFilters() )->article(
			array(
				'@type'     => 'BlogPosting',
				'publisher' => array(
					'@type' => 'Organization',
					'name'  => 'MetalDivider',
				),
			)
		);
		$this->assertSame( array( '@id' => 'https://metal-divider.com/#organization' ), $json['author'] );
		$this->assertSame( array( '@id' => 'https://metal-divider.com/#organization' ), $json['publisher'] );
	}

	/**
	 * Pattern pages carry the replica's two-level trail — Home, then
	 * the pattern — whatever SEOPress walked in between (the shop
	 * base, the series term).
	 */
	public function test_crumbs_pattern_page_is_two_level(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( true );
		$this->assertSame(
			array(
				array( 'Home', 'https://metal-divider.com/' ),
				array( "Timothy's Vine", 'https://metal-divider.com/pattern/timothys-vine/' ),
			),
			( new SchemaFilters() )->crumbs(
				array(
					array( 'Home', 'https://metal-divider.com/' ),
					array( 'Shop', 'https://metal-divider.com/shop/' ),
					array( 'Organic', 'https://metal-divider.com/collections/pattern-organic/' ),
					array( "Timothy's Vine", 'https://metal-divider.com/pattern/timothys-vine/' ),
				)
			)
		);
	}

	/**
	 * Content pages carry the same two-level shape (the /pages shell
	 * is a URL prefix, not a trail level).
	 */
	public function test_crumbs_content_page_is_two_level(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( false );
		WP_Mock::userFunction( 'is_page' )->andReturn( true );
		$this->assertSame(
			array(
				array( 'Home', 'https://metal-divider.com/' ),
				array( 'FAQs & Downloads', 'https://metal-divider.com/faqs/' ),
			),
			( new SchemaFilters() )->crumbs(
				array(
					array( 'Home', 'https://metal-divider.com/' ),
					array( 'Pages', 'https://metal-divider.com/pages/' ),
					array( 'FAQs & Downloads', 'https://metal-divider.com/faqs/' ),
				)
			)
		);
	}

	/**
	 * The final title collapses the doubled and trailing spaces the
	 * empty pagination variable leaves behind (T13's dedup suffix).
	 */
	public function test_title_collapses_the_pagination_whitespace(): void {
		$filters = new SchemaFilters();
		$this->assertSame( 'Organic — MetalDivider', $filters->title( 'Organic  — MetalDivider' ) );
		$this->assertSame( 'Pattern Library — Laser Cut Metal Screen Patterns', $filters->title( 'Pattern Library — Laser Cut Metal Screen Patterns ' ) );
		$this->assertSame( 'Pattern Library — Laser Cut Metal Screen Patterns 2', $filters->title( 'Pattern Library — Laser Cut Metal Screen Patterns 2' ) );
	}

	/**
	 * Blog posts and series archives keep SEOPress's own shape (Home
	 * > Blog > Article, Home > Series).
	 */
	public function test_crumbs_leave_posts_and_archives_alone(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( false );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		$filters = new SchemaFilters();
		$trail   = array(
			array( 'Home', 'https://metal-divider.com/' ),
			array( 'Blog', 'https://metal-divider.com/blog/' ),
			array( 'Article', 'https://metal-divider.com/blog/article/' ),
		);
		$this->assertSame( $trail, $filters->crumbs( $trail ) );
		$this->assertSame( array(), $filters->crumbs( array() ) );
	}

	/**
	 * Verifies title() and og_title() decode residual numeric and double-escaped entities
	 * such as `&#038;`, `&amp;#038;`, and `&#8217;`.
	 */
	public function test_title_decodes_residual_numeric_and_double_escaped_entities(): void {
		$filters = new SchemaFilters();

		$this->assertSame(
			'Laser-Cut & Geometric Metal Screens — MetalDivider',
			$filters->title( 'Laser-Cut &#038; Geometric Metal Screens — MetalDivider' )
		);
		$this->assertSame(
			'FAQs & Downloads — MetalDivider',
			$filters->title( 'FAQs &amp;#038; Downloads — MetalDivider' )
		);
		$this->assertSame(
			'Timothy’s Vine — MetalDivider',
			$filters->title( 'Timothy&#8217;s Vine — MetalDivider' )
		);
		$this->assertSame(
			'<meta property="og:title" content="FAQs &amp; Downloads — MetalDivider">',
			$filters->og_title( '<meta property="og:title" content="FAQs &amp;#038; Downloads — MetalDivider">' )
		);
	}

	/**
	 * Verifies non-English front page and pattern catalog archive return localized
	 * `<title>`, `<meta description>`, `og:title`, and `og:description` (including pagination).
	 */
	public function test_non_english_home_and_shop_seo_titles_descriptions_and_og_tags(): void {
		WP_Mock::userFunction( 'is_admin' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'wp_doing_ajax' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'wp_doing_cron' )->zeroOrMoreTimes()->andReturn( false );

		$is_front = true;
		$is_shop  = false;
		$paged    = 1;

		WP_Mock::userFunction( 'is_front_page' )->andReturnUsing(
			static function () use ( &$is_front ): bool {
				return $is_front;
			}
		);
		WP_Mock::userFunction( 'is_search' )->andReturn( false );
		WP_Mock::userFunction( 'is_shop' )->andReturnUsing(
			static function () use ( &$is_shop ): bool {
				return $is_shop;
			}
		);
		WP_Mock::userFunction( 'is_post_type_archive' )->andReturn( false );
		WP_Mock::userFunction( 'get_query_var' )->andReturnUsing(
			static function ( string $query_var ) use ( &$paged ): int {
				return 'paged' === $query_var ? $paged : 0;
			}
		);

		$filters = new SchemaFilters();
		Languages::force_for_tests( 'zh' );

		// 1. Chinese front page.
		$this->assertSame(
			Dictionary::text( 'seo_home_title', 'zh' ),
			$filters->title( 'Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider' )
		);
		$this->assertSame(
			Dictionary::text( 'seo_home_desc', 'zh' ),
			$filters->description( '146 laser-cut stainless steel screen patterns...' )
		);
		$this->assertSame(
			'<meta property="og:title" content="' . Dictionary::text( 'seo_home_title', 'zh' ) . '">',
			$filters->og_title( '<meta property="og:title" content="Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider">' )
		);
		$this->assertSame(
			'<meta property="og:description" content="' . Dictionary::text( 'seo_home_desc', 'zh' ) . '">',
			$filters->og_desc( '<meta property="og:description" content="English desc">' )
		);

		// 2. Chinese pattern catalog archive (page 1 & page 2).
		$is_front = false;
		$is_shop  = true;
		$this->assertSame(
			Dictionary::text( 'seo_shop_title', 'zh' ),
			$filters->title( 'Pattern Library — Laser Cut Metal Screen Patterns' )
		);
		$this->assertSame(
			Dictionary::text( 'seo_shop_desc', 'zh' ),
			$filters->description( 'Browse all 146 laser-cut stainless steel screen patterns...' )
		);

		$paged = 2;
		$this->assertSame(
			Dictionary::text( 'seo_shop_title', 'zh' ) . ' — 第 2 页',
			$filters->title( 'Pattern Library — Laser Cut Metal Screen Patterns 2' )
		);
	}

	/**
	 * Verifies crumbs() localizes Home and replaces WooCommerce Shop ("商店" / "Shop" / "Tienda")
	 * with the localized pattern library label across languages.
	 */
	public function test_crumbs_localizes_home_and_replaces_woocommerce_shop_across_languages(): void {
		WP_Mock::userFunction( 'is_singular' )->andReturn( false );
		WP_Mock::userFunction( 'is_page' )->andReturn( false );
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->with( 'home' )->andReturn( 'https://metal-divider.com' );

		$filters = new SchemaFilters();

		// Chinese pattern catalog trail: Home -> 首页, 商店 -> 图案库.
		Languages::force_for_tests( 'zh' );
		$zh_crumbs = $filters->crumbs(
			array(
				array( 'Home', 'https://metal-divider.com/' ),
				array( '商店', 'https://metal-divider.com/zh/patterns/' ),
			)
		);
		$this->assertSame(
			array(
				array( '首页', 'https://metal-divider.com/zh/' ),
				array( '图案库', 'https://metal-divider.com/zh/patterns/' ),
			),
			$zh_crumbs
		);

		// Arabic series archive trail: Home -> الرئيسية, المتجر -> مكتبة الأنماط.
		Languages::force_for_tests( 'ar' );
		$ar_crumbs = $filters->crumbs(
			array(
				array( 'Home', 'https://metal-divider.com/' ),
				array( 'المتجر', 'https://metal-divider.com/patterns/' ),
				array( 'هندسي', 'https://metal-divider.com/ar/pattern-category/pattern-geometric-ar/' ),
			)
		);
		$this->assertSame(
			array(
				array( 'الرئيسية', 'https://metal-divider.com/ar/' ),
				array( 'مكتبة الأنماط', 'https://metal-divider.com/ar/patterns/' ),
				array( 'هندسي', 'https://metal-divider.com/ar/pattern-category/pattern-geometric-ar/' ),
			),
			$ar_crumbs
		);
	}
}
