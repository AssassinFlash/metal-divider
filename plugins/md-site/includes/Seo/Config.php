<?php
/**
 * The SEOPress configuration plane (spec 22, branch research/spec-seo).
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

use MetalDivider\Site\Presentation\Templates;

/**
 * Installs the site's SEOPress state as one declarative target: the
 * title/description templates (the fallback layer under the 146
 * migrated per-pattern metas), the noindex faces, the sitemap, the
 * robots.txt virtual file, the Knowledge Graph fields, the structured
 * data builder posts (Product without offers, FAQPage on /faqs/
 * only, BlogPosting), the /pages shell's 301, and the per-page metas
 * of the SEO-owned pages. Every write is idempotent; `check` reports
 * the drift between the site and this target.
 *
 * The values are the spec's, with one filed deviation: the Sitemap
 * directive points at SEOPress's native endpoint /sitemaps.xml (the
 * spec's /sitemap.xml is a permanent 301 to it — a directive should
 * name the canonical).
 */
final class Config {

	/**
	 * SEOPress's option rows this plane owns, as option names.
	 */
	public const TITLES  = 'seopress_titles_option_name';
	public const SOCIAL  = 'seopress_social_option_name';
	public const SITEMAP = 'seopress_xml_sitemap_option_name';
	public const PRO     = 'seopress_pro_option_name';

	/**
	 * The post type of SEOPress PRO's structured data builder and
	 * redirections (the migrator writes the same two).
	 */
	private const SCHEMAS   = 'seopress_schemas';
	private const REDIRECTS = 'seopress_404';

	/**
	 * The PRO keys this plane removes, each with its reason for the
	 * drift report: the WooCommerce noindex switches for pages that do
	 * not exist on this site (§2.1 — the migrator removed them), and
	 * the og:price tags that would assert the empty price as $0 on
	 * every pattern page (the same honesty rule that keeps offers out
	 * of the Product schema, §3.2, applied to the social face).
	 */
	private const PRO_KEYS_OFF = array(
		'seopress_woocommerce_cart_page_no_index'     => 'the cart page does not exist',
		'seopress_woocommerce_checkout_page_no_index' => 'the checkout page does not exist',
		'seopress_woocommerce_customer_account_page_no_index' => 'the account page does not exist',
		'seopress_woocommerce_product_og_price'       => 'the empty price must not be asserted as $0',
		'seopress_woocommerce_product_og_currency'    => 'the empty price must not be asserted as $0',
	);

	/**
	 * The separator (spec 22 §2.1: em dash, site-wide).
	 */
	private const SEP = '—';

	/**
	 * The one entity name the site carries (spec 22 §2.1: sitetitle and
	 * Organization.name are the same single name).
	 */
	private const ENTITY = 'MetalDivider';

	/**
	 * The contact facts of the Organization schema (AGENTS.md: public
	 * site information, never secrets).
	 */
	private const EMAIL = 'info@metal-divider.com';
	private const PHONE = '+85264731687';

	/**
	 * The title/description templates (spec 22 §2.2). The per-pattern
	 * metas the migrator wrote win over every one of these (SEOPress:
	 * metabox value > template); the templates are the fallback for
	 * pages that have no meta of their own.
	 *
	 * @return array<string, mixed> The full titles option value.
	 */
	public static function titles(): array {
		return array(
			'seopress_titles_sep'                     => self::SEP,
			// T1: the home page's meta is hand-written copy, not a template.
			'seopress_titles_home_site_title'         => 'Laser-Cut Stainless Steel Metal Screen Patterns — MetalDivider',
			'seopress_titles_home_site_desc'          => '146 laser-cut stainless steel (304 / 316) screen patterns for privacy screens, facades, and partitions. Compare open area and visual density, then request a quote.',
			'seopress_titles_single_titles'           => array(
				// T2/T3/T8: the minimal fallback (O3) — title and excerpt.
				'post'    => array(
					'title'       => '%%post_title%% %%sep%% %%sitetitle%%',
					'description' => '%%post_excerpt%%',
				),
				'page'    => array(
					'title'       => '%%post_title%% %%sep%% %%sitetitle%%',
					'description' => '%%post_excerpt%%',
				),
				'product' => array(
					'title'       => '%%post_title%% %%sep%% %%sitetitle%%',
					'description' => '%%post_excerpt%%',
				),
			),
			'seopress_titles_tax_titles'              => array(
				// T4: series archives — descriptions are per-term hand copy
				// (O4, pending), so no description template here.
				'product_cat' => array(
					'title' => '%%term_title%% %%current_pagination%% %%sep%% %%sitetitle%%',
				),
				// T7: the blog's cluster hubs.
				'category'    => array(
					'title' => '%%term_title%% %%current_pagination%% %%sep%% Blog %%sep%% %%sitetitle%%',
				),
				// Tags are thin duplicates of their posts (SEOPress's own
				// advice, spec 22 §4.1): keep them out of the index.
				'post_tag'    => array(
					'noindex' => '1',
				),
			),
			'seopress_titles_archive_titles'          => array(
				// T6: the blog index.
				'post'    => array(
					'title' => 'Blog %%sep%% %%sitetitle%%',
				),
				// T5: the shop archive is hand-written (it carries the
				// /collections/all equity, spec 22 §2.2), with the
				// pagination suffix the 146-pattern catalog needs (T13;
				// the empty page number leaves only a trailing space,
				// which the title filter trims).
				'product' => array(
					'title'       => 'Pattern Library — Laser Cut Metal Screen Patterns %%current_pagination%%',
					'description' => 'Browse all 146 laser-cut stainless steel screen patterns by series — Asian inspired, geometric, organic, Moroccan and Arabic, and more. Compare visual density and request a quote.',
				),
			),
			// T12: the thin faces stay out of the index.
			'seopress_titles_archives_search_title_noindex' => '1',
			'seopress_titles_archives_date_noindex'   => '1',
			'seopress_titles_archives_author_noindex' => '1',
			'seopress_titles_attachments_noindex'     => '1',
		);
	}

	/**
	 * The Knowledge Graph and social card fields (spec 22 §2.1 and
	 * §3.3: Organization with name, email and WhatsApp — no address,
	 * so no LocalBusiness; OG on with the page's own meta and image).
	 *
	 * @param string|null $logo_url The logo attachment's URL, null when no attachment exists yet.
	 * @return array<string, mixed> The full social option value.
	 */
	public static function social( ?string $logo_url ): array {
		$social = array(
			'seopress_social_knowledge_type'         => 'Organization',
			'seopress_social_knowledge_name'         => self::ENTITY,
			'seopress_social_knowledge_email'        => self::EMAIL,
			'seopress_social_knowledge_phone'        => self::PHONE,
			'seopress_social_knowledge_contact_type' => 'sales',
			'seopress_social_facebook_og'            => '1',
			'seopress_social_twitter_card'           => '1',
			'seopress_social_twitter_card_og'        => '1',
		);
		if ( null !== $logo_url && '' !== $logo_url ) {
			$social['seopress_social_knowledge_img']        = $logo_url;
			$social['seopress_social_facebook_img_default'] = $logo_url;
		}
		return $social;
	}

	/**
	 * The XML sitemap (spec 22 §4.1): page/post/product in, attachment
	 * and tags out, the image extension on (the 1063 product finals'
	 * image-search entry), no author archives.
	 *
	 * @return array<string, mixed> The full sitemap option value.
	 */
	public static function sitemap(): array {
		return array(
			'seopress_xml_sitemap_general_enable'  => '1',
			'seopress_xml_sitemap_img_enable'      => '1',
			'seopress_xml_sitemap_post_types_list' => array(
				'post'    => array( 'include' => '1' ),
				'page'    => array( 'include' => '1' ),
				'product' => array( 'include' => '1' ),
			),
			'seopress_xml_sitemap_taxonomies_list' => array(
				'category'    => array( 'include' => '1' ),
				'product_cat' => array( 'include' => '1' ),
			),
		);
	}

	/**
	 * The PRO features this plane owns (spec 22 §3.1 S4, §4.2, D6):
	 * breadcrumbs and their JSON-LD, the robots.txt virtual file, the
	 * structured data builder toggle. The WooCommerce cart/checkout
	 * noindex switches stay off (spec 22 §2.1: those pages do not
	 * exist on this site).
	 *
	 * @param string $home The site's home URL (the Sitemap directive's base).
	 * @return array<string, mixed> The full PRO option value.
	 */
	public static function pro( string $home ): array {
		return array(
			'seopress_breadcrumbs_enable'      => '1',
			'seopress_breadcrumbs_json_enable' => '1',
			'seopress_rich_snippets_enable'    => '1',
			'seopress_robots_enable'           => '1',
			'seopress_robots_file'             => self::robots( $home ),
		);
	}

	/**
	 * The robots.txt (spec 22 §4.2): the seven lines, no AI-crawler
	 * blocks (the default is full allow), and the Sitemap directive at
	 * SEOPress's native endpoint.
	 *
	 * @param string $home The site's home URL.
	 * @return string The virtual robots.txt file.
	 */
	public static function robots( string $home ): string {
		return "User-agent: *\n"
			. "Allow: /\n"
			. "Disallow: /wp-admin/\n"
			. "Allow: /wp-admin/admin-ajax.php\n"
			. "Disallow: /?s=\n"
			. "Disallow: /search/\n"
			. "\n"
			. 'Sitemap: ' . untrailingslashit( $home ) . '/sitemaps.xml';
	}

	/**
	 * The per-page metas of the SEO-owned pages (spec 22 §2.2 T9/T10/
	 * T11/T6): the inquiry and privacy titles, the blog index's title,
	 * the thank-you page's noindex — plus the two URL-scaffolding pages
	 * that must stay out of the index and the sitemap: the /pages/ shell
	 * (a URL prefix that 301s home, #4's ruling) and the install's
	 * sample page (deleted by the content plane whenever it lands; until
	 * then it is at least invisible).
	 *
	 * @param string $home The site's home URL (unused here, kept for the table's symmetry).
	 * @return array<string, array<string, string>> Page path → meta key → value.
	 */
	public function page_metas( string $home ): array {
		unset( $home );
		return array(
			'inquiry'           => array(
				'_seopress_titles_title' => 'Request a Quote %%sep%% %%sitetitle%%',
			),
			'privacy'           => array(
				'_seopress_titles_title' => 'Privacy Policy %%sep%% %%sitetitle%%',
			),
			'blog'              => array(
				'_seopress_titles_title' => 'Blog %%sep%% %%sitetitle%% %%current_pagination%%',
			),
			'inquiry/thank-you' => array(
				'_seopress_robots_index' => 'yes',
			),
			'pages'             => array(
				'_seopress_robots_index' => 'yes',
			),
			'sample-page'       => array(
				'_seopress_robots_index' => 'yes',
			),
		);
	}

	/**
	 * Applies the whole target: the four options, the builder posts,
	 * the /pages redirect, the per-page metas. Idempotent — a second
	 * run writes nothing — and it reports what it did and what it
	 * could not (pages that do not exist yet).
	 *
	 * The titles, social and sitemap options are written whole: they
	 * are this plane's own surfaces and nothing else writes them. The
	 * PRO option is an overlay, because it also carries the features
	 * this plane does not own (404 monitoring, AI, white label): only
	 * this plane's keys move, and the three WooCommerce noindex
	 * switches the spec rules out (§2.1) go.
	 *
	 * @return array<int, string> One report line per surface.
	 */
	public function apply(): array {
		$lines = array();
		foreach ( array(
			array( self::TITLES, self::titles(), 'titles', false ),
			array( self::SITEMAP, self::sitemap(), 'sitemap', false ),
			array( self::PRO, self::pro( (string) home_url( '/' ) ), 'pro (breadcrumbs, robots.txt, builder)', true ),
			array( self::SOCIAL, $this->social_with_logo(), 'social (knowledge, cards)', false ),
		) as list( $name, $value, $label, $overlay ) ) {
			if ( $overlay ) {
				$existing = get_option( $name );
				$existing = is_array( $existing ) ? $existing : array();
				$value    = array_merge( $existing, $value );
				foreach ( array_keys( self::PRO_KEYS_OFF ) as $off ) {
					unset( $value[ $off ] );
				}
			}
			$changed = get_option( $name ) !== $value;
			update_option( $name, $value );
			$lines[] = $label . ': ' . ( $changed ? 'written' : 'unchanged' );
		}
		if ( '' === $this->logo_url() ) {
			$lines[] = 'social: no logo attachment yet — Organization logo and the default og:image stay unset until the chrome logo lands';
		}
		$lines   = array_merge( $lines, $this->apply_builder_posts() );
		$lines   = array_merge( $lines, $this->apply_redirects() );
		$lines   = array_merge( $lines, $this->apply_page_metas() );
		return $lines;
	}

	/**
	 * The drift report: every surface whose live value differs from
	 * this target, one line each. Zero lines is the pass. The PRO
	 * option reports only this plane's keys (the overlay's own
	 * contract) plus the switched-off WooCommerce trio.
	 *
	 * @return array<int, string> The drift rows.
	 */
	public function check(): array {
		$rows = array();
		foreach ( array(
			array( self::TITLES, self::titles(), 'titles', false ),
			array( self::SITEMAP, self::sitemap(), 'sitemap', false ),
			array( self::PRO, self::pro( (string) home_url( '/' ) ), 'pro', true ),
			array( self::SOCIAL, $this->social_with_logo(), 'social', false ),
		) as list( $name, $value, $label, $overlay ) ) {
			$live    = get_option( $name );
			$differs = false;
			if ( $overlay ) {
				$live = is_array( $live ) ? $live : array();
				foreach ( array_keys( self::PRO_KEYS_OFF ) as $off ) {
					if ( array_key_exists( $off, $live ) ) {
						$rows[] = sprintf( 'pro %s: set although the page does not exist (spec 22 §2.1)', $off );
					}
				}
				foreach ( $value as $key => $target_value ) {
					if ( ( $live[ $key ] ?? null ) !== $target_value ) {
						$differs = true;
						break;
					}
				}
			} else {
				$differs = $live !== $value;
			}
			if ( $differs ) {
				$rows[] = sprintf( '%s option: differs from the target (run wp md-site seo to repair)', $label );
			}
		}
		$rows = array_merge( $rows, $this->check_builder_posts() );
		$rows = array_merge( $rows, $this->check_redirects() );
		foreach ( $this->page_metas( '' ) as $slug => $metas ) {
			$page = get_page_by_path( $slug );
			if ( null === $page ) {
				$rows[] = sprintf( 'page %s: not installed (the migrator creates it) — its meta is pending', $slug );
				continue;
			}
			foreach ( $metas as $key => $value ) {
				if ( (string) get_post_meta( $page->ID, $key, true ) !== $value ) {
					$rows[] = sprintf( 'page %s: %s differs from the target', $slug, $key );
				}
			}
		}
		return $rows;
	}

	/**
	 * The three structured data builder posts (spec 22 §3.1): Product
	 * on every pattern (no offers — the filter layer carries material,
	 * the ACF properties and the QuoteAction), the FAQPage on
	 * /faqs/ only, and BlogPosting on journal posts.
	 *
	 * @return array<int, array{slug: string, type: string, title: string, rules: array<int, array<int, array<string, string>>>, metas: array<string, string>}> The builder targets.
	 */
	private function builder_posts(): array {
		$posts   = array();
		$posts[] = array(
			'slug'  => 'md-schema-product',
			'type'  => 'products',
			'title' => 'Product — pattern pages',
			'rules' => array(
				array(
					array(
						'filter' => 'post_type',
						'cpt'    => 'product',
						'cond'   => 'equal',
					),
				),
			),
			'metas' => array(
				'_seopress_pro_rich_snippets_product_name' => 'post_title',
				'_seopress_pro_rich_snippets_product_description' => 'post_excerpt',
				'_seopress_pro_rich_snippets_product_img'  => 'post_thumbnail',
				'_seopress_pro_rich_snippets_product_sku'  => 'product_sku',
				'_seopress_pro_rich_snippets_product_brand' => 'manual_global',
				'_seopress_pro_rich_snippets_product_brand_manual_global' => self::ENTITY,
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
		);
		$faqs    = get_page_by_path( 'faqs' );
		$faq_ld  = null === $faqs ? '' : FaqSchema::json_ld( Templates::read( Templates::path( 'faqs' ) ), (string) get_permalink( $faqs ) );
		if ( '' !== $faq_ld ) {
			$posts[] = array(
				'slug'  => 'md-schema-faq',
				'type'  => 'custom',
				'title' => 'FAQPage — /faqs/',
				'rules' => array(
					array(
						array(
							'filter' => 'postId',
							'postId' => (string) $faqs->ID,
							'cond'   => 'equal',
						),
					),
				),
				'metas' => array(
					'_seopress_pro_rich_snippets_custom' => $faq_ld,
				),
			);
		}
		$posts[] = array(
			'slug'  => 'md-schema-article',
			'type'  => 'articles',
			'title' => 'BlogPosting — journal posts',
			'rules' => array(
				array(
					array(
						'filter' => 'post_type',
						'cpt'    => 'post',
						'cond'   => 'equal',
					),
				),
			),
			'metas' => array(
				'_seopress_pro_rich_snippets_article_type' => 'BlogPosting',
				'_seopress_pro_rich_snippets_article_title' => 'post_title',
				'_seopress_pro_rich_snippets_article_desc' => 'post_excerpt',
				'_seopress_pro_rich_snippets_article_img'  => 'post_thumbnail',
				'_seopress_pro_rich_snippets_article_author' => 'none',
			),
		);
		return $posts;
	}

	/**
	 * Writes the builder posts (idempotent by post name).
	 *
	 * @return array<int, string> One line per post.
	 */
	private function apply_builder_posts(): array {
		$lines = array();
		foreach ( $this->builder_posts() as $target ) {
			$post = get_page_by_path( $target['slug'], OBJECT, self::SCHEMAS );
			$args = array(
				'post_type'   => self::SCHEMAS,
				'post_status' => 'publish',
				'post_title'  => $target['title'],
				'post_name'   => $target['slug'],
			);
			if ( null !== $post ) {
				$args['ID'] = $post->ID;
			}
			$made = wp_insert_post( $args, true );
			if ( is_wp_error( $made ) ) {
				$lines[] = sprintf( 'schema %s: refused (%s)', $target['slug'], $made->get_error_message() );
				continue;
			}
			update_post_meta( (int) $made, '_seopress_pro_rich_snippets_type', $target['type'] );
			update_post_meta( (int) $made, '_seopress_pro_rich_snippets_rules', $target['rules'] );
			foreach ( $target['metas'] as $key => $value ) {
				update_post_meta( (int) $made, $key, wp_slash( $value ) );
			}
			$lines[] = sprintf( 'schema %s (%s): %s', $target['slug'], $target['type'], null === $post ? 'created' : 'synced' );
		}
		$faqs = get_page_by_path( 'faqs' );
		if ( null === $faqs ) {
			$lines[] = 'schema faq: /faqs/ is not installed — the FAQPage schema is pending the page';
		}
		return $lines;
	}

	/**
	 * The builder posts' drift rows.
	 *
	 * @return array<int, string> The rows.
	 */
	private function check_builder_posts(): array {
		$rows   = array();
		$faqs   = get_page_by_path( 'faqs' );
		$expect = $this->builder_posts();
		if ( null === $faqs ) {
			$expect = array_values(
				array_filter(
					$expect,
					static function ( array $target ): bool {
						return 'md-schema-faq' !== $target['slug'];
					}
				)
			);
			$rows[] = 'schema faq: /faqs/ is not installed — the FAQPage schema is pending the page';
		}
		foreach ( $expect as $target ) {
			$post = get_page_by_path( $target['slug'], OBJECT, self::SCHEMAS );
			if ( null === $post ) {
				$rows[] = sprintf( 'schema %s: not installed', $target['slug'] );
				continue;
			}
			if ( (string) get_post_meta( $post->ID, '_seopress_pro_rich_snippets_type', true ) !== $target['type']
				|| get_post_meta( $post->ID, '_seopress_pro_rich_snippets_rules', true ) !== $target['rules'] ) {
				$rows[] = sprintf( 'schema %s: type or rules differ from the target', $target['slug'] );
				continue;
			}
			foreach ( $target['metas'] as $key => $value ) {
				if ( (string) get_post_meta( $post->ID, $key, true ) !== $value ) {
					$rows[] = sprintf( 'schema %s: %s differs from the target', $target['slug'], $key );
				}
			}
		}
		return $rows;
	}

	/**
	 * The redirects this plane owns: SEOPress redirection post title (the path
	 * it matches) => 301 target.
	 *
	 * `pages` is the shell prefix (spec 22 F1 via #4). The retired
	 * `pattern-gradient` series (#25: the old collection is empty on the new
	 * catalog and the pixel url-map retires it) keeps its URL alive as a 301
	 * to the shop instead of resolving to an empty archive.
	 *
	 * @var array<string, string>
	 */
	private const REDIRECT_TARGETS = array(
		'pages'                        => '/',
		'collections/pattern-gradient' => '/shop/',
	);

	/**
	 * The redirects' install: one line per redirect.
	 *
	 * @return array<int, string> The report lines.
	 */
	private function apply_redirects(): array {
		$lines = array();
		foreach ( self::REDIRECT_TARGETS as $title => $target ) {
			$lines[] = sprintf( 'redirect /%s → %s: %s', $title, $target, $this->apply_redirect( $title, $target ) );
		}
		return $lines;
	}

	/**
	 * The drift rows of the redirects.
	 *
	 * @return array<int, string> The rows.
	 */
	private function check_redirects(): array {
		$rows = array();
		foreach ( self::REDIRECT_TARGETS as $title => $target ) {
			$post = $this->post_by_title( self::REDIRECTS, $title );
			if ( null === $post || ! $this->redirect_is_current( (int) $post->ID, $target ) ) {
				$rows[] = sprintf( 'redirect /%s → %s: missing or stale', $title, $target );
			}
		}
		return $rows;
	}

	/**
	 * One SEOPress redirection post, created or synced to the target.
	 *
	 * @param string $title  The redirection's post title (the path it matches).
	 * @param string $target The 301 target.
	 * @return string 'created', 'synced', or 'refused (…)'. The 'pages' shell
	 *                keeps its dedicated report line above.
	 */
	private function apply_redirect( string $title, string $target ): string {
		$post = $this->post_by_title( self::REDIRECTS, $title );
		$args = array(
			'post_type'   => self::REDIRECTS,
			'post_status' => 'publish',
			'post_title'  => $title,
		);
		if ( null !== $post ) {
			$args['ID'] = $post->ID;
		}
		$made = wp_insert_post( $args, true );
		if ( is_wp_error( $made ) ) {
			return 'refused (' . $made->get_error_message() . ')';
		}
		update_post_meta( (int) $made, '_seopress_redirections_enabled', 'yes' );
		update_post_meta( (int) $made, '_seopress_redirections_type', '301' );
		update_post_meta( (int) $made, '_seopress_redirections_value', $target );
		update_post_meta( (int) $made, '_seopress_redirections_logged_status', 'both' );
		update_post_meta( (int) $made, '_seopress_redirections_enabled_regex', '' );
		return null === $post ? 'created' : 'synced';
	}

	/**
	 * The per-page metas of the pages that exist (a missing page is a
	 * report line, not a write).
	 *
	 * @return array<int, string> One line per page.
	 */
	private function apply_page_metas(): array {
		$lines = array();
		foreach ( $this->page_metas( '' ) as $slug => $metas ) {
			$page = get_page_by_path( $slug );
			if ( null === $page ) {
				$lines[] = sprintf( 'page %s: not installed — its meta is pending the page', $slug );
				continue;
			}
			foreach ( $metas as $key => $value ) {
				update_post_meta( (int) $page->ID, $key, wp_slash( $value ) );
			}
			$lines[] = sprintf( 'page %s: meta synced', $slug );
		}
		return $lines;
	}

	/**
	 * The chrome logo's URL, the one image the Knowledge Graph and the
	 * default og:image share. The template layer already pins its
	 * uploads path; the schema may only reference an attachment that
	 * exists.
	 *
	 * @return string The URL, '' when the attachment is not in the library yet.
	 */
	private function logo_url(): string {
		$attachment = get_page_by_path( 'metal-divider-primary-logo--120w', OBJECT, 'attachment' );
		if ( null === $attachment ) {
			return '';
		}
		$url = (string) wp_get_attachment_url( (int) $attachment->ID );
		return $url;
	}

	/**
	 * The social target with the logo resolved.
	 *
	 * @return array<string, mixed> The full social option value.
	 */
	private function social_with_logo(): array {
		return self::social( $this->logo_url() );
	}

	/**
	 * Whether the /pages redirect post already carries this target.
	 *
	 * @param int $id The redirect post's ID.
	 * @return bool True when every redirect field matches.
	 */
	private function redirect_is_current( int $id, string $target ): bool {
		return 'yes' === (string) get_post_meta( $id, '_seopress_redirections_enabled', true )
			&& '301' === (string) get_post_meta( $id, '_seopress_redirections_type', true )
			&& $target === (string) get_post_meta( $id, '_seopress_redirections_value', true )
			&& 'both' === (string) get_post_meta( $id, '_seopress_redirections_logged_status', true )
			&& '' === (string) get_post_meta( $id, '_seopress_redirections_enabled_regex', true );
	}

	/**
	 * A post of a type by its exact title (the redirections CPT is
	 * matched by title, its slug is not the lookup key).
	 *
	 * @param string $type  The post type.
	 * @param string $title The exact title.
	 * @return \WP_Post|null The post, null when absent.
	 */
	private function post_by_title( string $type, string $title ) {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}
}
