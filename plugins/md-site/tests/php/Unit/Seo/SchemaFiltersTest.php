<?php
/**
 * Tests for the SEO schema filters.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\SchemaFilters;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

// The runtime doubles of seopress_get_service and wc_get_product live
// in tests/php/Doubles/seopress-woocommerce.php (the global namespace,
// where the filters' function_exists() probes look).

/**
 * Tests for SchemaFilters: the five data-assembly hooks of the schema
 * plane — the SearchAction on the WebSite block, the Organization's
 * two contact points, the Product's completed facts (never offers),
 * the article's entity reference, and the two-level breadcrumb trails.
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
			 * The knowledge phone (WhatsApp).
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
	 * No leaked product double between tests.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['md_wc_product_double'] );
		parent::tearDown();
	}

	/**
	 * The register() hook: seven filters (five schema assemblies, the
	 * Home crumb the JSON-LD keeps — the spec's trails begin at Home —
	 * and the title whitespace trim).
	 */
	public function test_register_adds_the_hooks(): void {
		$filters = new SchemaFilters();
		WP_Mock::expectFilterAdded( 'seopress_schemas_website', array( $filters, 'website' ) );
		WP_Mock::expectFilterAdded( 'seopress_get_json_data_organization', array( $filters, 'organization' ) );
		WP_Mock::expectFilterAdded( 'seopress_schemas_auto_product_json', array( $filters, 'product' ) );
		WP_Mock::expectFilterAdded( 'seopress_schemas_auto_article_json', array( $filters, 'article' ) );
		WP_Mock::expectFilterAdded( 'seopress_pro_breadcrumbs_crumbs', array( $filters, 'crumbs' ) );
		WP_Mock::expectFilterAdded( 'seopress_titles_title', array( $filters, 'title' ) );
		WP_Mock::expectFilterAdded( 'seopress_pro_breadcrumbs_json_remove_home', '__return_false' );

		$filters->register();

		$this->assertConditionsMet();
	}

	/**
	 * The WebSite block gains the SearchAction at /?s= (spec 22 S3).
	 */
	public function test_website_gains_the_search_action(): void {
		$schema = ( new SchemaFilters() )->website( array( '@type' => 'WebSite' ) );
		$this->assertSame( 'SearchAction', $schema['potentialAction']['@type'] );
		$this->assertSame( 'https://metal-divider.com/?s={search_term_string}', $schema['potentialAction']['target']['urlTemplate'] );
		$this->assertSame( 'required name=search_term_string', $schema['potentialAction']['query-input'] );
	}

	/**
	 * The Organization carries exactly the spec's two contact points —
	 * sales with the email, WhatsApp with the phone — both read from
	 * the Knowledge Graph fields, and no top-level telephone.
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
		$this->assertSame(
			array(
				array(
					'@type'             => 'ContactPoint',
					'contactType'       => 'sales',
					'email'             => 'info@metal-divider.com',
					'availableLanguage' => array( 'English' ),
				),
				array(
					'@type'             => 'ContactPoint',
					'contactType'       => 'WhatsApp',
					'telephone'         => '+85264731687',
					'availableLanguage' => array( 'English' ),
				),
			),
			$schema['contactPoint']
		);
		$this->assertArrayNotHasKey( 'telephone', $schema );
	}

	/**
	 * Without SEOPress's service (a site where SEOPress is absent),
	 * the Organization keeps its builder shape minus the telephone —
	 * no invented points.
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
		$GLOBALS['md_wc_product_double'] = new class() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a test double's handoff, not a plugin global.
			/**
			 * The gallery ids.
			 *
			 * @return int[]
			 */
			public function get_gallery_image_ids(): array {
				return array( 2, 3, 4, 5, 6, 7, 8, 9 );
			}
		};

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
				array( 'FAQs & Downloads', 'https://metal-divider.com/pages/faqs/' ),
			),
			( new SchemaFilters() )->crumbs(
				array(
					array( 'Home', 'https://metal-divider.com/' ),
					array( 'Pages', 'https://metal-divider.com/pages/' ),
					array( 'FAQs & Downloads', 'https://metal-divider.com/pages/faqs/' ),
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
}
