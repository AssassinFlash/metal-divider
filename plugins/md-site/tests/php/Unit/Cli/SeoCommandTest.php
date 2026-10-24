<?php
/**
 * Tests for the SEO configuration command.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests\Unit\Cli;

use MetalDivider\Site\Cli\SeoCommand;
use MetalDivider\Site\Seo\Config;
use MetalDivider\Site\Seo\FaqSchema;
use MetalDivider\Site\Tests\TestCase;
use WP_Mock;
use WP_Post;

/**
 * Tests for SeoCommand: the administrator gate, the check mode's
 * drift report, and the apply it drives.
 *
 * @covers \MetalDivider\Site\Cli\SeoCommand
 */
final class SeoCommandTest extends TestCase {

	/**
	 * The option values update_option writes (the fixture's store).
	 *
	 * @var array<string, mixed>
	 */
	private array $state = array();

	/**
	 * The command refuses anyone but an administrator.
	 */
	public function test_gate_refuses_non_administrators(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		$this->expectException( 'WP_CLI\ExitException' );
		$this->expectExceptionMessage( 'Run as an administrator' );
		( new SeoCommand() )->seo( array(), array() );
	}

	/**
	 * On a fully provisioned site — every option at the target, every
	 * page and builder post installed — check reports zero drift.
	 */
	public function test_check_reports_zero_drift_on_a_provisioned_site(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->given_provisioned_site();

		ob_start();
		( new SeoCommand() )->seo( array(), array( 'check' => true ) );
		$output = (string) ob_get_clean();

		$this->assertSame( "wp-cli success: seo drift: 0 rows — the site matches the target.\n", $output );
	}

	/**
	 * The check mode exits 1 with one line per drift row.
	 */
	public function test_check_exits_with_one_line_per_drift_row(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->given_provisioned_site( array( Config::TITLES => array( 'seopress_titles_sep' => '-' ) ) );
		$this->expectOutputString( "wp-cli log: titles option: differs from the target (run wp md-site seo to repair)\n" );
		$this->expectException( 'WP_CLI\ExitException' );
		$this->expectExceptionMessage( 'seo drift: 1 rows (run wp md-site seo to repair)' );
		( new SeoCommand() )->seo( array(), array( 'check' => true ) );
	}

	/**
	 * The apply mode logs every surface's report line and succeeds.
	 */
	public function test_apply_logs_the_report_and_succeeds(): void {
		WP_Mock::userFunction( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->given_provisioned_site(
			array(
				Config::TITLES  => false,
				Config::SITEMAP => false,
				Config::PRO     => false,
				Config::SOCIAL  => false,
			),
			false
		);

		ob_start();
		( new SeoCommand() )->seo( array(), array() );
		$output = (string) ob_get_clean();

		$this->assertStringStartsWith( "wp-cli log: titles: written\nwp-cli log: sitemap: written\n", $output );
		$this->assertStringEndsWith( "wp-cli success: SEO configuration installed.\n", $output );
	}

	/**
	 * The site both modes run against: the options at their target
	 * (or overridden by $options), the four SEO-owned pages and the
	 * three builder posts installed with their target metas, the
	 * /pages redirect in place. With $installed false every post
	 * lookup misses, so apply has everything to create.
	 *
	 * @param array<string, mixed> $options  Option overrides against the target.
	 * @param bool                 $installed Whether the pages and posts exist.
	 */
	private function given_provisioned_site( array $options = array(), bool $installed = true ): void {
		$this->state = array();
		WP_Mock::userFunction( 'home_url' )->zeroOrMoreTimes()->andReturnUsing( static fn( string $path = '/' ) => 'http://site.test' . $path );
		WP_Mock::userFunction( 'get_permalink' )->zeroOrMoreTimes()->andReturn( 'http://site.test/pages/faqs/' );
		WP_Mock::passthruFunction( 'wp_strip_all_tags', true );
		WP_Mock::passthruFunction( 'untrailingslashit', true );
		WP_Mock::userFunction( 'wp_json_encode' )->zeroOrMoreTimes()->andReturnUsing(
			static fn( $data, $flags = 0 ) => json_encode( $data, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
		WP_Mock::userFunction( 'get_option' )->zeroOrMoreTimes()->andReturnUsing(
			function ( string $name ) use ( $options ) {
				if ( array_key_exists( $name, $options ) ) {
					return $options[ $name ];
				}
				if ( array_key_exists( $name, $this->state ) ) {
					return $this->state[ $name ];
				}
				return match ( $name ) {
					Config::TITLES  => Config::titles(),
					Config::SITEMAP => Config::sitemap(),
					Config::SOCIAL  => Config::social( '' ),
					Config::PRO     => Config::pro( 'http://site.test/' ),
					default         => false,
				};
			}
		);
		WP_Mock::userFunction( 'update_option' )->zeroOrMoreTimes()->andReturnUsing(
			function ( string $name, $value ): bool {
				$this->state[ $name ] = $value;
				return true;
			}
		);

		$faqs_ld = FaqSchema::json_ld(
			\MetalDivider\Site\Presentation\Templates::read( \MetalDivider\Site\Presentation\Templates::path( 'faqs' ) ),
			'http://site.test/pages/faqs/'
		);
		$metas   = $this->target_metas( $faqs_ld );
		$next    = 10;
		WP_Mock::userFunction( 'get_page_by_path' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $path ) use ( $installed, $metas ) {
				if ( ! $installed || ! array_key_exists( $path, $metas['posts'] ) ) {
					return null;
				}
				$post             = new WP_Post( new \stdClass() );
				$post->ID         = $metas['posts'][ $path ]['id'];
				$post->post_type  = $metas['posts'][ $path ]['type'];
				$post->post_title = $metas['posts'][ $path ]['title'];
				return $post;
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( int $id, string $key ) use ( $installed, $metas ) {
				if ( ! $installed ) {
					return '';
				}
				return $metas['meta'][ $id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'get_posts' )->zeroOrMoreTimes()->andReturnUsing(
			static function () use ( $installed, $metas ) {
				if ( ! $installed ) {
					return array();
				}
				$post     = new WP_Post( new \stdClass() );
				$post->ID = $metas['redirect']['id'];
				return array( $post );
			}
		);
		WP_Mock::userFunction( 'wp_insert_post' )->zeroOrMoreTimes()->andReturnUsing(
			static function () use ( &$next ) {
				return ++$next;
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->zeroOrMoreTimes()->andReturn( true );
		WP_Mock::userFunction( 'wp_slash' )->zeroOrMoreTimes()->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'is_wp_error' )->zeroOrMoreTimes()->andReturn( false );
		WP_Mock::userFunction( 'untrailingslashit' )->zeroOrMoreTimes()->andReturnUsing( static fn( string $value ) => rtrim( $value, '/' ) );
	}

	/**
	 * The fixture's post table: every page and builder post the check
	 * walks (keys are the paths Config looks up), with the exact metas
	 * of the target (so the fixture itself exercises Config's own
	 * builders against the real faqs template).
	 *
	 * @param string $faqs_ld The FAQPage payload built from the real template.
	 * @return array{posts: array<string, array{id: int<1, max>, type: non-empty-string, title: string}>, meta: array<int, array<string, mixed>>, redirect: array{id: int<1, max>}}
	 */
	private function target_metas( string $faqs_ld ): array {
		$inquiry     = 101;
		$privacy     = 102;
		$blog        = 103;
		$thank_you   = 104;
		$faqs        = 105;
		$shell       = 106;
		$sample_page = 107;
		$s_product   = 201;
		$s_faq       = 202;
		$s_article   = 203;
		$redirect    = 301;
		$config      = new Config();
		$page_metas  = $config->page_metas( '' );

		$posts = array(
			'inquiry'           => array(
				'id'    => $inquiry,
				'type'  => 'page',
				'title' => 'Inquiry',
			),
			'privacy'           => array(
				'id'    => $privacy,
				'type'  => 'page',
				'title' => 'Privacy policy',
			),
			'blog'              => array(
				'id'    => $blog,
				'type'  => 'page',
				'title' => 'Blog',
			),
			'inquiry/thank-you' => array(
				'id'    => $thank_you,
				'type'  => 'page',
				'title' => 'Inquiry thank you',
			),
			'pages'             => array(
				'id'    => $shell,
				'type'  => 'page',
				'title' => 'Pages',
			),
			'sample-page'       => array(
				'id'    => $sample_page,
				'type'  => 'page',
				'title' => 'Sample Page',
			),
			'pages/faqs'        => array(
				'id'    => $faqs,
				'type'  => 'page',
				'title' => 'FAQs & Downloads',
			),
			'md-schema-product' => array(
				'id'    => $s_product,
				'type'  => 'seopress_schemas',
				'title' => 'Product — pattern pages',
			),
			'md-schema-faq'     => array(
				'id'    => $s_faq,
				'type'  => 'seopress_schemas',
				'title' => 'FAQPage — /pages/faqs',
			),
			'md-schema-article' => array(
				'id'    => $s_article,
				'type'  => 'seopress_schemas',
				'title' => 'BlogPosting — journal posts',
			),
		);

		$meta = array(
			$inquiry     => $page_metas['inquiry'],
			$privacy     => $page_metas['privacy'],
			$blog        => $page_metas['blog'],
			$thank_you   => $page_metas['inquiry/thank-you'],
			$shell       => $page_metas['pages'],
			$sample_page => $page_metas['sample-page'],
			$s_product   => array(
				'_seopress_pro_rich_snippets_type'         => 'products',
				'_seopress_pro_rich_snippets_rules'        => array(
					array(
						array(
							'filter' => 'post_type',
							'cpt'    => 'product',
							'cond'   => 'equal',
						),
					),
				),
				'_seopress_pro_rich_snippets_product_name' => 'post_title',
				'_seopress_pro_rich_snippets_product_description' => 'post_excerpt',
				'_seopress_pro_rich_snippets_product_img'  => 'post_thumbnail',
				'_seopress_pro_rich_snippets_product_sku'  => 'product_sku',
				'_seopress_pro_rich_snippets_product_brand' => 'manual_global',
				'_seopress_pro_rich_snippets_product_brand_manual_global' => 'MetalDivider',
				'_seopress_pro_rich_snippets_product_price' => 'none',
				'_seopress_pro_rich_snippets_product_price_valid_date' => 'none',
				'_seopress_pro_rich_snippets_product_price_currency' => 'none',
				'_seopress_pro_rich_snippets_product_condition' => 'none',
				'_seopress_pro_rich_snippets_product_availability' => 'none',
				'_seopress_pro_rich_snippets_product_global_ids' => 'none',
				'_seopress_pro_rich_snippets_product_global_ids_value' => 'none',
				'_seopress_pro_rich_snippets_product_positive_notes' => 'none',
				'_seopress_pro_rich_snippets_product_negative_notes' => 'none',
			),
			$s_faq       => array(
				'_seopress_pro_rich_snippets_type'   => 'custom',
				'_seopress_pro_rich_snippets_rules'  => array(
					array(
						array(
							'filter' => 'postId',
							'postId' => (string) $faqs,
							'cond'   => 'equal',
						),
					),
				),
				'_seopress_pro_rich_snippets_custom' => $faqs_ld,
			),
			$s_article   => array(
				'_seopress_pro_rich_snippets_type'         => 'articles',
				'_seopress_pro_rich_snippets_rules'        => array(
					array(
						array(
							'filter' => 'post_type',
							'cpt'    => 'post',
							'cond'   => 'equal',
						),
					),
				),
				'_seopress_pro_rich_snippets_article_type' => 'BlogPosting',
				'_seopress_pro_rich_snippets_article_title' => 'post_title',
				'_seopress_pro_rich_snippets_article_desc' => 'post_excerpt',
				'_seopress_pro_rich_snippets_article_img'  => 'post_thumbnail',
				'_seopress_pro_rich_snippets_article_author' => 'none',
			),
			$redirect    => array(
				'_seopress_redirections_enabled'       => 'yes',
				'_seopress_redirections_type'          => '301',
				'_seopress_redirections_value'         => '/',
				'_seopress_redirections_logged_status' => 'both',
				'_seopress_redirections_enabled_regex' => '',
			),
		);

		return array(
			'posts'    => $posts,
			'meta'     => $meta,
			'redirect' => array( 'id' => $redirect ),
		);
	}
}
