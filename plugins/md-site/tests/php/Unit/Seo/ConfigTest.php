<?php
/**
 * Tests for the SEOPress configuration plane's target state.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\Config;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;

/**
 * Tests for Config: the declarative target — the templates of spec
 * 22 §2.2, the noindex faces of §2.1, the sitemap of §4.1, the
 * robots.txt of §4.2, the Knowledge Graph of §3.3 — and the
 * idempotent apply that writes it.
 *
 * @covers \MetalDivider\Site\Seo\Config
 */
final class ConfigTest extends TestCase {

	/**
	 * The option values update_option writes (the fixture's store).
	 *
	 * @var array<string, mixed>
	 */
	private array $store = array();

	/**
	 * The separator helper the robots file trims with.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_Mock::userFunction( 'untrailingslashit' )->zeroOrMoreTimes()->andReturnUsing( static fn( string $value ) => rtrim( $value, '/' ) );
	}

	/**
	 * The separator and the hand-written home meta (T1: copy, not a
	 * template).
	 */
	public function test_titles_carry_the_sep_and_the_home_copy(): void {
		$titles = Config::titles();
		$this->assertSame( '—', $titles['seopress_titles_sep'] );
		$this->assertSame( 'Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider', $titles['seopress_titles_home_site_title'] );
		$this->assertStringContainsString( '146 laser-cut stainless steel', $titles['seopress_titles_home_site_desc'] );
	}

	/**
	 * The single templates are the minimal fallback (O3): title and
	 * excerpt, the same three content types the site carries.
	 */
	public function test_titles_single_templates_are_the_minimal_fallback(): void {
		$single = Config::titles()['seopress_titles_single_titles'];
		$this->assertSame( array( 'post', 'page', 'product' ), array_keys( $single ) );
		foreach ( $single as $template ) {
			$this->assertSame( '%%post_title%% %%sep%% %%sitetitle%%', $template['title'] );
			$this->assertSame( '%%post_excerpt%%', $template['description'] );
		}
	}

	/**
	 * The taxonomy templates (T4/T7) carry the pagination variable;
	 * the series and blog-category descriptions stay unset (per-term
	 * hand copy, O4); tags are out of the index.
	 */
	public function test_titles_taxonomy_templates(): void {
		$tax = Config::titles()['seopress_titles_tax_titles'];
		$this->assertSame( '%%term_title%% %%current_pagination%% %%sep%% %%sitetitle%%', $tax['product_cat']['title'] );
		$this->assertArrayNotHasKey( 'description', $tax['product_cat'] );
		$this->assertSame( '%%term_title%% %%current_pagination%% %%sep%% Blog %%sep%% %%sitetitle%%', $tax['category']['title'] );
		$this->assertSame( '1', $tax['post_tag']['noindex'] );
	}

	/**
	 * The shop archive is hand-written (T5) and the blog index carries
	 * its template (T6).
	 */
	public function test_titles_archive_templates(): void {
		$archives = Config::titles()['seopress_titles_archive_titles'];
		$this->assertSame( 'Pattern Library — Laser Cut Metal Screen Patterns %%current_pagination%%', $archives['product']['title'] );
		$this->assertNotSame( '', $archives['product']['description'] );
		$this->assertSame( 'Blog %%sep%% %%sitetitle%%', $archives['post']['title'] );
	}

	/**
	 * The four noindex faces of §2.1 (T12): search, date, author,
	 * attachment. The thank-you page's noindex is a page meta, not a
	 * global switch.
	 */
	public function test_titles_noindex_faces(): void {
		$titles = Config::titles();
		$this->assertSame( '1', $titles['seopress_titles_archives_search_title_noindex'] );
		$this->assertSame( '1', $titles['seopress_titles_archives_date_noindex'] );
		$this->assertSame( '1', $titles['seopress_titles_archives_author_noindex'] );
		$this->assertSame( '1', $titles['seopress_titles_attachments_noindex'] );
	}

	/**
	 * The sitemap (§4.1): page/post/product in, the two taxonomies in,
	 * the image extension on.
	 */
	public function test_sitemap_includes_and_image_extension(): void {
		$sitemap = Config::sitemap();
		$this->assertSame( '1', $sitemap['seopress_xml_sitemap_general_enable'] );
		$this->assertSame( '1', $sitemap['seopress_xml_sitemap_img_enable'] );
		$this->assertSame( array( 'post', 'page', 'product' ), array_keys( $sitemap['seopress_xml_sitemap_post_types_list'] ) );
		$this->assertSame( array( 'category', 'product_cat' ), array_keys( $sitemap['seopress_xml_sitemap_taxonomies_list'] ) );
	}

	/**
	 * The robots.txt (§4.2): the seven lines, no AI-crawler blocks,
	 * and the Sitemap directive at the native endpoint.
	 */
	public function test_robots_is_the_seven_lines_plus_the_native_sitemap(): void {
		$this->assertSame(
			"User-agent: *\n"
			. "Allow: /\n"
			. "Disallow: /wp-admin/\n"
			. "Allow: /wp-admin/admin-ajax.php\n"
			. "Disallow: /?s=\n"
			. "Disallow: /search/\n"
			. "\n"
			. 'Sitemap: https://metal-divider.com/sitemaps.xml',
			Config::robots( 'https://metal-divider.com/' )
		);
	}

	/**
	 * The PRO features: breadcrumbs and their JSON-LD on, the builder
	 * on, the robots file in — and the WooCommerce cart/checkout
	 * noindex switches this target does not carry (§2.1: those pages
	 * do not exist).
	 */
	public function test_pro_carries_breadcrumbs_builder_and_robots(): void {
		$pro = Config::pro( 'https://metal-divider.com/' );
		$this->assertSame( '1', $pro['seopress_breadcrumbs_enable'] );
		$this->assertSame( '1', $pro['seopress_breadcrumbs_json_enable'] );
		$this->assertSame( '1', $pro['seopress_rich_snippets_enable'] );
		$this->assertSame( '1', $pro['seopress_robots_enable'] );
		$this->assertSame( Config::robots( 'https://metal-divider.com/' ), $pro['seopress_robots_file'] );
		$this->assertArrayNotHasKey( 'seopress_woocommerce_cart_page_no_index', $pro );
		$this->assertArrayNotHasKey( 'seopress_woocommerce_checkout_page_no_index', $pro );
	}

	/**
	 * The Knowledge Graph (§3.3): Organization — the entity name, the
	 * email, the phone, the sales contact type — and no logo keys
	 * until an attachment exists.
	 */
	public function test_social_knowledge_without_a_logo(): void {
		$social = Config::social( null );
		$this->assertSame( 'Organization', $social['seopress_social_knowledge_type'] );
		$this->assertSame( 'MetalDivider', $social['seopress_social_knowledge_name'] );
		$this->assertSame( 'info@metal-divider.com', $social['seopress_social_knowledge_email'] );
		$this->assertSame( '+85264731687', $social['seopress_social_knowledge_phone'] );
		$this->assertSame( 'sales', $social['seopress_social_knowledge_contact_type'] );
		$this->assertSame( '1', $social['seopress_social_facebook_og'] );
		$this->assertSame( '1', $social['seopress_social_twitter_card_og'] );
		$this->assertArrayNotHasKey( 'seopress_social_knowledge_img', $social );
		$this->assertArrayNotHasKey( 'seopress_social_facebook_img_default', $social );
	}

	/**
	 * With the chrome logo's attachment, the Knowledge Graph logo and
	 * the default og:image carry its URL.
	 */
	public function test_social_carries_the_logo_when_it_exists(): void {
		$social = Config::social( 'https://metal-divider.com/wp-content/uploads/metal-divider-primary-logo--120w.webp' );
		$this->assertSame( 'https://metal-divider.com/wp-content/uploads/metal-divider-primary-logo--120w.webp', $social['seopress_social_knowledge_img'] );
		$this->assertSame( 'https://metal-divider.com/wp-content/uploads/metal-divider-primary-logo--120w.webp', $social['seopress_social_facebook_img_default'] );
	}

	/**
	 * The SEO-owned pages' metas (T9/T10/T11/T6): inquiry and privacy
	 * titles, the blog index's title, the thank-you noindex.
	 */
	public function test_page_metas_table(): void {
		$metas = ( new Config() )->page_metas( '' );
		$this->assertSame(
			array( 'inquiry', 'privacy', 'blog', 'inquiry/thank-you', 'pages', 'sample-page' ),
			array_keys( $metas )
		);
		$this->assertSame( 'Request a Quote %%sep%% %%sitetitle%%', $metas['inquiry']['_seopress_titles_title'] );
		$this->assertSame( 'Privacy Policy %%sep%% %%sitetitle%%', $metas['privacy']['_seopress_titles_title'] );
		$this->assertSame( 'Blog %%sep%% %%sitetitle%% %%current_pagination%%', $metas['blog']['_seopress_titles_title'] );
		$this->assertSame( 'yes', $metas['inquiry/thank-you']['_seopress_robots_index'] );
	}

	/**
	 * The apply() write: the four options land, every missing page is
	 * reported as pending (the migrator creates pages; a missing page
	 * is a report line, never a write), and the builder posts and the
	 * /pages redirect it made are in the report.
	 */
	public function test_apply_writes_the_target_and_reports_pending_pages(): void {
		$this->store = array();
		WP_Mock::userFunction( 'home_url' )->andReturnUsing( static fn( string $path = '/' ) => 'http://site.test' . $path );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( string $name ) {
				return $this->store[ $name ] ?? false;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( string $name, $value ): bool {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		// No pages and no logo attachment exist on this site.
		WP_Mock::userFunction( 'get_page_by_path' )->andReturn( null );
		WP_Mock::userFunction( 'wp_get_attachment_url' )->never();
		// The two builder posts that do not need a page (the FAQPage
		// needs /faqs/) insert once each; the redirect posts too.
		$made = 0;
		WP_Mock::userFunction( 'wp_insert_post' )->andReturnUsing(
			static function () use ( &$made ) {
				return ++$made;
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );

		$lines = ( new Config() )->apply();

		$this->assertSame( Config::titles(), $this->store[ Config::TITLES ] );
		$this->assertSame( Config::sitemap(), $this->store[ Config::SITEMAP ] );
		$this->assertSame( Config::social( '' ), $this->store[ Config::SOCIAL ], 'no logo attachment: the logo keys stay out' );
		$this->assertStringContainsString( 'Sitemap: http://site.test/sitemaps.xml', $this->store[ Config::PRO ]['seopress_robots_file'] );
		$joined = implode( "\n", $lines );
		$this->assertStringContainsString( 'schema md-schema-product (products): created', $joined );
		$this->assertStringContainsString( 'schema md-schema-article (articles): created', $joined );
		$this->assertStringContainsString( 'schema faq: /faqs/ is not installed', $joined );
		$this->assertStringContainsString( 'redirect /pages → /: created', $joined );
		$this->assertStringContainsString( 'redirect /collections/pattern-gradient → /shop/: created', $joined, 'the retired series keeps its URL as a 301 (VC-040)' );
		$this->assertStringContainsString( 'page inquiry: not installed', $joined );
		$this->assertStringContainsString( 'page inquiry/thank-you: not installed', $joined );
		$this->assertStringContainsString( 'no logo attachment yet', $joined );
	}
}
