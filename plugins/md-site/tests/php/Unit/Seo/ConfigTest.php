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
	 * titles, the blog index's title, the thank-you noindex, and the
	 * seventeen page descriptions. Eighteen keys; the thank-you page stays noindex only.
	 */
	public function test_page_metas_table(): void {
		$metas = ( new Config() )->page_metas( '' );
		$this->assertCount( 18, $metas );
		$this->assertSame(
			array(
				'collections',
				'inquiry',
				'faqs',
				'about-us',
				'manufacturing-process',
				'project-gallery',
				'laser-cut-geometric-metal-screens',
				'blog',
				'custom-metal-divider-screens',
				'privacy',
				'chinese-style-artistic-screens',
				'glass-hybrid-screens',
				'outdoor-privacy-landscape-screens',
				'expanded-metal-panels',
				'laser-cut-panels',
				'perforated-panels',
				'stainless-steel-screens',
				'inquiry/thank-you',
			),
			array_keys( $metas )
		);
		$this->assertSame( 'Request a Quote %%sep%% %%sitetitle%%', $metas['inquiry']['_seopress_titles_title'] );
		$this->assertSame( 'Privacy Policy %%sep%% %%sitetitle%%', $metas['privacy']['_seopress_titles_title'] );
		$this->assertSame( 'Blog %%sep%% %%sitetitle%% %%current_pagination%%', $metas['blog']['_seopress_titles_title'] );
		$this->assertSame(
			array( '_seopress_robots_index' => 'yes' ),
			$metas['inquiry/thank-you']
		);
		$this->assertSame(
			array(
				'collections'                       => 'Decorative metal screen pattern series. Choose a family by privacy, airflow, light, and project use, then send a reference.',
				'inquiry'                           => 'Request your quote. Write what the project needs. A person replies with questions, or with a quote.',
				'faqs'                              => 'Answers on 304 and 316 stainless steel, panel sizes, lead times, and installation for architectural screens.',
				'about-us'                          => 'Laser-cut screens finished in your chosen PVD color, checked before they ship, and delivered exactly as approved.',
				'manufacturing-process'             => 'From drawing review to shipment: stainless screens matched to laser, perforation, or expanded metal, with route-specific tolerances and quality gates.',
				'project-gallery'                   => 'Interior partition screen gallery for hotels, commercial spaces and homes. Filter by category and open a case to view its photo set.',
				'laser-cut-geometric-metal-screens' => 'Laser-cut stainless steel (304 / 316) screens in three interior pattern directions. Compare the directions, then request a quote.',
				'blog'                              => 'Metal Divider journal: technical guidance for architects, contractors and dealers. Browse patterns, read the FAQs, or send an inquiry.',
				'custom-metal-divider-screens'      => 'Custom stainless steel (304 / 316) divider screens from your drawing or an existing pattern. Send dimensions and the application to request a quote.',
				'privacy'                           => 'How metal-divider.com handles inquiry data: what we collect, why, how long we keep it, and how to ask about access or deletion.',
				'chinese-style-artistic-screens'    => 'Chinese-style and artistic stainless steel screens for hotels, restaurants and luxury homes. Send the pattern, dimensions and finish for a project spec.',
				'glass-hybrid-screens'              => 'Glass and hybrid screens pairing stainless steel frames with glass for zoning and privacy in premium interiors. Send the opening and glass direction for a spec.',
				'outdoor-privacy-landscape-screens' => 'Outdoor privacy and landscape screens in 304 and 316 stainless steel, specified for coastal, alpine and courtyard conditions. Send the site and dimensions.',
				'expanded-metal-panels'             => 'Expanded stainless steel mesh panels for facades, partitions and ceilings. Compare diamond, hexagonal and fine patterns, then send the panel schedule.',
				'laser-cut-panels'                  => 'Laser-cut stainless steel panels cut to your drawing in 304 or 316, brushed or PVD finish. Send a DXF for a manufacturability review and quote.',
				'perforated-panels'                 => 'Perforated stainless steel panels in 304 or 316, punched to your pattern and open area. Send the hole pattern, open area and panel size for a quote.',
				'stainless-steel-screens'           => 'Stainless steel screen systems for facades, partitions and outdoor privacy. Compare laser-cut, perforated, expanded and hybrid directions, then send the brief.',
			),
			array_map(
				static fn( array $row ): string => $row['_seopress_titles_desc'],
				array_diff_key( $metas, array( 'inquiry/thank-you' => true ) )
			)
		);
		$this->assertCount( 17, array_filter( $metas, static fn( array $row ): bool => isset( $row['_seopress_titles_desc'] ) ) );
	}

	/**
	 * The install's sample page is not a meta target: it is not part of the
	 * site's information architecture and production does not publish it, so
	 * a row for it made `seo --check` report it missing on a correct site
	 * and asked config to create a page the site does not want. Its noindex
	 * belongs to the render-time rule (`Seo\PageNoindex`), which costs
	 * nothing when the page is absent.
	 */
	public function test_the_sample_page_is_not_a_meta_target(): void {
		$metas = ( new Config() )->page_metas( '' );
		$this->assertArrayNotHasKey( 'sample-page', $metas, 'config must not demand a page the site does not publish' );
	}

	/**
	 * Every noindex row stores SEOPress's checkbox value, never the string
	 * `noindex`: `seopress_titles_noindex_post_option()` reads only `yes`,
	 * so storing `noindex` in the meta silently drops the noindex instead of
	 * moving it (measured on dev, where the page became `index, follow`).
	 *
	 * The canonical is kept by normalizing the bypass return value at render
	 * time (`Seo\PageNoindex`), not by changing this meta. This test exists
	 * so a later "tidy-up" of the string cannot undo that.
	 */
	public function test_noindex_rows_use_the_checkbox_value_not_the_bypass_string(): void {
		$metas = ( new Config() )->page_metas( '' );
		$rows  = array_filter( $metas, static fn( array $m ): bool => isset( $m['_seopress_robots_index'] ) );
		$this->assertNotEmpty( $rows, 'the site has noindex pages to guard' );
		foreach ( $rows as $slug => $m ) {
			$this->assertSame(
				'yes',
				$m['_seopress_robots_index'],
				"{$slug} must store the checkbox value; 'noindex' there removes the noindex"
			);
		}
	}

	/**
	 * The /pages shell carries no meta target, because it is not a page:
	 * issue #4 makes it a URL prefix that 301s home, and the migration
	 * writes the content pages at the site root. A row for it asks the
	 * site for a page that must never exist — `apply()` can only report
	 * it pending and `check()` can only report it missing, forever, on a
	 * correct site.
	 *
	 * The shell's non-indexability is the redirect's job, so this test
	 * holds both halves: no meta row for it, and the redirect that makes
	 * the row unnecessary.
	 */
	public function test_the_pages_shell_is_a_redirect_not_a_meta_target(): void {
		$metas = ( new Config() )->page_metas( '' );
		$this->assertArrayNotHasKey( 'pages', $metas, 'the shell must not be demanded as a page' );

		$reflection = new \ReflectionClass( Config::class );
		$this->assertSame(
			array( 'pages' => '/' ),
			$reflection->getConstant( 'REDIRECT_TARGETS' ),
			'the shell is expressed as the 301 that keeps it out of the index'
		);
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
		$this->assertStringNotContainsString( 'redirect /collections/pattern-gradient', $joined, 'the gradient series is live (issue #25): no 301 may take its visitors away' );
		$this->assertStringContainsString( 'page inquiry: not installed', $joined );
		$this->assertStringContainsString( 'page inquiry/thank-you: not installed', $joined );
		$this->assertStringNotContainsString( 'page pages:', $joined, 'the shell is a redirect, never a page the apply waits on' );
		$this->assertStringContainsString( 'no logo attachment yet', $joined );
	}

	/**
	 * The surviving redirect ignores the query string. SEOPress reads an
	 * empty `_seopress_redirections_param` as `exact_match` and only fires
	 * when the mode is *not* `exact_match`, so without this field
	 * `/pages?utm_source=…` is a 404 while `/pages` redirects.
	 */
	public function test_apply_redirect_carries_the_without_param_mode(): void {
		$this->store = array();
		WP_Mock::userFunction( 'home_url' )->andReturnUsing( static fn( string $path = '/' ) => 'http://site.test' . $path );
		WP_Mock::userFunction( 'get_option' )->andReturn( false );
		WP_Mock::userFunction( 'update_option' )->andReturn( true );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'get_page_by_path' )->andReturn( null );
		WP_Mock::userFunction( 'wp_get_attachment_url' )->never();
		$made = 0;
		WP_Mock::userFunction( 'wp_insert_post' )->andReturnUsing(
			static function () use ( &$made ) {
				return ++$made;
			}
		);
		$metas = array();
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			static function ( int $id, string $key, $value ) use ( &$metas ): bool {
				$metas[ $id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'get_posts' )->andReturn( array() );

		( new Config() )->apply();

		$redirect = null;
		foreach ( $metas as $id => $row ) {
			if ( '/' === ( $row['_seopress_redirections_value'] ?? null ) ) {
				$redirect = $row;
				break;
			}
		}
		$this->assertNotNull( $redirect, 'the /pages redirect was written' );
		$this->assertSame( 'without_param', $redirect['_seopress_redirections_param'] );
	}

	/**
	 * The drift check reads the query-string mode too: a site whose
	 * redirect predates the field is stale and repaired by apply.
	 */
	public function test_check_reports_a_redirect_without_the_param_mode(): void {
		WP_Mock::userFunction( 'home_url' )->andReturn( 'http://site.test/' );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			fn( string $name ) => match ( $name ) {
				Config::TITLES  => Config::titles(),
				Config::SITEMAP => Config::sitemap(),
				Config::SOCIAL  => Config::social( '' ),
				Config::PRO     => Config::pro( 'http://site.test/' ),
				default         => false,
			}
		);
		WP_Mock::userFunction( 'get_page_by_path' )->andReturn( null );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		$post     = new \WP_Post( new \stdClass() );
		$post->ID = 42;
		WP_Mock::userFunction( 'get_posts' )->andReturn( array( $post ) );
		// The redirect post exists with every field but the mode.
		$stored = array(
			'_seopress_redirections_enabled'       => 'yes',
			'_seopress_redirections_type'          => '301',
			'_seopress_redirections_value'         => '/',
			'_seopress_redirections_logged_status' => 'both',
			'_seopress_redirections_enabled_regex' => '',
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static fn( int $id, string $key ) => $stored[ $key ] ?? ''
		);

		$rows = ( new Config() )->check();

		$this->assertContains( 'redirect /pages → /: missing or stale', $rows );
	}
}
