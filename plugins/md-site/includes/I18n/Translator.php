<?php
/**
 * Translation orchestration engine for metal-divider.com.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\I18n;

use RuntimeException;
use WP_Term;

/**
 * Orchestrates multi-layer translation of terms, pages, patterns, and LiveCanvas templates
 * with parallel DeepSeek execution, persistent translation memory, and strict verification.
 */
final class Translator {

	/**
	 * Default translation memory cache file name.
	 */
	private const TM_FILE_NAME = 'i18n-tm-cache.json';

	/**
	 * Mandatory Simplified Chinese glossary rules enforced during translation and retry prompts.
	 */
	public const ZH_GLOSSARY_SYSTEM_PROMPT = 'Simplified Chinese (zh) glossary rules: NEVER translate "order/orders" as "订单" — always use "项目", "委托", or "批次"; NEVER translate "product/products" as "产品" — always use "图案", "屏风", or "板材"; NEVER translate "pattern/patterns" as "款式" or "花型" — always use "图案".';

	/**
	 * Path to translation memory cache file.
	 *
	 * @var string
	 */
	private string $tm_cache_path;

	/**
	 * Loaded translation memory cache.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $tm_cache = null;

	/**
	 * DeepSeek API client.
	 *
	 * @var DeepSeekClient
	 */
	private DeepSeekClient $client;

	/**
	 * Translation validator.
	 *
	 * @var TranslationValidator
	 */
	private TranslationValidator $validator;

	/**
	 * HTML/text segmenter.
	 *
	 * @var Segmenter
	 */
	private Segmenter $segmenter;

	/**
	 * Initializer.
	 *
	 * @param DeepSeekClient|null       $client        Client instance.
	 * @param TranslationValidator|null $validator     Validator instance.
	 * @param Segmenter|null            $segmenter     Segmenter instance.
	 * @param string|null               $tm_cache_path Explicit TM cache path.
	 */
	public function __construct(
		?DeepSeekClient $client = null,
		?TranslationValidator $validator = null,
		?Segmenter $segmenter = null,
		?string $tm_cache_path = null
	) {
		$this->client    = $client ?? new DeepSeekClient();
		$this->validator = $validator ?? new TranslationValidator();
		$this->segmenter = $segmenter ?? new Segmenter();

		if ( null !== $tm_cache_path && '' !== trim( $tm_cache_path ) ) {
			$this->tm_cache_path = $tm_cache_path;
		} else {
			$home = getenv( 'HOME' );
			if ( is_string( $home ) && '' !== $home ) {
				$this->tm_cache_path = $home . '/metal-divider-local/' . self::TM_FILE_NAME;
			} else {
				$this->tm_cache_path = sys_get_temp_dir() . '/' . self::TM_FILE_NAME;
			}
		}
	}

	/**
	 * Translates all layers across requested languages.
	 *
	 * @param array{only?: string, lang?: string, force?: bool} $options Execution options.
	 * @return array{terms: int, pages: int, products: int, templates: int}
	 */
	public function translate_all( array $options = array() ): array {
		$only    = $options['only'] ?? 'all';
		$lang    = isset( $options['lang'] ) && 'all' !== $options['lang'] ? $options['lang'] : null;
		$force   = (bool) ( $options['force'] ?? false );
		$targets = null !== $lang ? array( Languages::from_wpml( $lang ) ) : Languages::TARGETS;
		$report  = array(
			'terms'     => 0,
			'pages'     => 0,
			'products'  => 0,
			'templates' => 0,
		);

		$this->prewarm_all( $targets, $only );

		if ( in_array( $only, array( 'all', 'terms' ), true ) ) {
			$report['terms'] = $this->translate_terms( $lang, $force );
		}

		if ( in_array( $only, array( 'all', 'pages' ), true ) ) {
			$report['pages'] = $this->translate_pages( $lang, $force );
		}

		if ( in_array( $only, array( 'all', 'products' ), true ) ) {
			$report['products'] = $this->translate_products( $lang, $force );
		}

		if ( in_array( $only, array( 'all', 'templates' ), true ) ) {
			$report['templates'] = $this->translate_templates( $lang, $force );
		}

		return $report;
	}

	/**
	 * Layer 1: Translates product_cat pattern series terms.
	 *
	 * @param string|null $target_lang Target language code or null for all.
	 * @param bool        $force       Force re-translation.
	 * @return int Total translated terms.
	 */
	public function translate_terms( ?string $target_lang = null, bool $force = false ): int {
		$targets = null !== $target_lang ? array( Languages::from_wpml( $target_lang ) ) : Languages::TARGETS;
		$count   = 0;

		$terms = Languages::source_terms( 'product_cat' );

		// Filter out uncategorized.
		$series_terms = array_filter(
			$terms,
			static function ( WP_Term $term ): bool {
				return 'uncategorized' !== $term->slug;
			}
		);

		$sitepress = $GLOBALS['sitepress'] ?? null;

		foreach ( $targets as $lang ) {
			foreach ( $series_terms as $term ) {
				$term_id = (int) $term->term_id;
				$tr_id   = Languages::term_translation( $term_id, 'product_cat', $lang );

				if ( $tr_id > 0 && ! $force ) {
					continue;
				}

				// Extract texts.
				$name         = (string) $term->name;
				$desc         = (string) $term->description;
				$typical_apps = (string) ( \function_exists( 'get_term_meta' ) ? \get_term_meta( $term_id, 'typical_applications', true ) : '' );
				$bottom_cnt   = (string) ( \function_exists( 'get_term_meta' ) ? \get_term_meta( $term_id, 'bottom_content', true ) : '' );
				$style_blurb  = (string) ( \function_exists( 'get_term_meta' ) ? \get_term_meta( $term_id, 'style_blurb', true ) : '' );
				$seo_title    = (string) ( \function_exists( 'get_term_meta' ) ? \get_term_meta( $term_id, '_seopress_titles_title', true ) : '' );
				$seo_desc     = (string) ( \function_exists( 'get_term_meta' ) ? \get_term_meta( $term_id, '_seopress_titles_desc', true ) : '' );

				$texts_to_translate = array_filter(
					array(
						'name'                 => $name,
						'desc'                 => $desc,
						'typical_applications' => $typical_apps,
						'style_blurb'          => $style_blurb,
						'seo_title'            => $seo_title,
						'seo_desc'             => $seo_desc,
					),
					static fn( string $s ): bool => '' !== trim( $s )
				);

				$translated    = $this->batch_translate_map( $lang, $texts_to_translate );
				$tr_bottom_cnt = '' !== trim( $bottom_cnt ) ? $this->translate_html_document( $bottom_cnt, $lang ) : '';

				$tr_name = $translated['name'] ?? $name;
				$tr_desc = $translated['desc'] ?? $desc;
				$tr_slug = $term->slug . '-' . $lang;

				if ( $tr_id <= 0 ) {
					if ( \function_exists( 'wp_insert_term' ) ) {
						$insert = \wp_insert_term(
							$tr_name,
							'product_cat',
							array(
								'description' => $tr_desc,
								'slug'        => $tr_slug,
							)
						);
						if ( is_array( $insert ) ) {
							$tr_id          = (int) $insert['term_id'];
							$tr_taxonomy_id = (int) $insert['term_taxonomy_id'];

							if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_trid' ) && method_exists( $sitepress, 'set_element_language_details' ) ) {
								$src_tt_id = (int) $term->term_taxonomy_id;
								$trid      = $sitepress->get_element_trid( $src_tt_id, 'tax_product_cat' );
								if ( ! $trid ) {
									$sitepress->set_element_language_details( $src_tt_id, 'tax_product_cat', null, 'en' );
									$trid = $sitepress->get_element_trid( $src_tt_id, 'tax_product_cat' );
								}
								$sitepress->set_element_language_details( $tr_taxonomy_id, 'tax_product_cat', $trid, Languages::to_wpml( $lang ), 'en' );
							}
						}
					}
				} elseif ( \function_exists( 'wp_update_term' ) ) {
						\wp_update_term(
							$tr_id,
							'product_cat',
							array(
								'name'        => $tr_name,
								'description' => $tr_desc,
							)
						);
				}

				if ( $tr_id > 0 && \function_exists( 'update_term_meta' ) ) {
					// Copy copyable fields.
					foreach ( array( 'thumbnail_id', 'accent_color', 'difficulty_level', '_accent_color', '_difficulty_level', '_typical_applications', '_bottom_content', '_style_blurb' ) as $copy_key ) {
						$val = \get_term_meta( $term_id, $copy_key, true );
						if ( '' !== $val ) {
							\update_term_meta( $tr_id, $copy_key, $val );
						}
					}

					// Write translated fields.
					if ( isset( $translated['typical_applications'] ) ) {
						\update_term_meta( $tr_id, 'typical_applications', $translated['typical_applications'] );
					}
					if ( '' !== $tr_bottom_cnt ) {
						\update_term_meta( $tr_id, 'bottom_content', $tr_bottom_cnt );
					}
					if ( isset( $translated['style_blurb'] ) ) {
						\update_term_meta( $tr_id, 'style_blurb', $translated['style_blurb'] );
					}
					if ( isset( $translated['seo_title'] ) ) {
						\update_term_meta( $tr_id, '_seopress_titles_title', $translated['seo_title'] );
					}
					if ( isset( $translated['seo_desc'] ) ) {
						\update_term_meta( $tr_id, '_seopress_titles_desc', $translated['seo_desc'] );
					}
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Layer 2: Translates published pages (inquiry, home, patterns, etc.).
	 *
	 * @param string|null $target_lang Target language code or null for all.
	 * @param bool        $force       Force re-translation.
	 * @return int Total translated pages.
	 */
	public function translate_pages( ?string $target_lang = null, bool $force = false ): int {
		$targets = null !== $target_lang ? array( Languages::from_wpml( $target_lang ) ) : Languages::TARGETS;
		$count   = 0;

		$pages = Languages::source_posts(
			'page',
			array(
				'orderby' => 'parent menu_order',
				'order'   => 'ASC',
			)
		);

		if ( \function_exists( 'kses_remove_filters' ) ) {
			\kses_remove_filters();
		}

		$sitepress = $GLOBALS['sitepress'] ?? null;
		global $wpdb;

		foreach ( $targets as $lang ) {
			foreach ( $pages as $page ) {
				$page_id = (int) $page->ID;
				$tr_id   = Languages::post_translation( $page_id, $lang );

				if ( $tr_id > 0 && ! $force ) {
					continue;
				}

				// Extract texts.
				$title     = (string) $page->post_title;
				$excerpt   = (string) $page->post_excerpt;
				$content   = (string) $page->post_content;
				$seo_title = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $page_id, '_seopress_titles_title', true ) : '' );
				$seo_desc  = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $page_id, '_seopress_titles_desc', true ) : '' );

				$texts_to_translate = array_filter(
					array(
						'title'     => $title,
						'excerpt'   => $excerpt,
						'seo_title' => $seo_title,
						'seo_desc'  => $seo_desc,
					),
					static fn( string $s ): bool => '' !== trim( $s )
				);

				$translated = $this->batch_translate_map( $lang, $texts_to_translate );

				$tr_title   = $translated['title'] ?? $title;
				$tr_excerpt = $translated['excerpt'] ?? $excerpt;
				$tr_content = $content;

				// Translate HTML content if present.
				if ( '' !== trim( $content ) ) {
					$tr_content = $this->translate_html_document( $content, $lang );
				}

				$page_arr  = (array) $page;
				$parent_id = (int) $page_arr['post_parent'];
				$tr_parent = 0;
				if ( $parent_id > 0 ) {
					$tr_parent = Languages::post_translation( $parent_id, $lang );
				}

				if ( $tr_id <= 0 ) {
					if ( \function_exists( 'wp_insert_post' ) ) {
						$insert_id = \wp_insert_post(
							array(
								'post_type'    => 'page',
								'post_status'  => 'publish',
								'post_title'   => $tr_title,
								'post_name'    => $page->post_name,
								'post_content' => $tr_content,
								'post_excerpt' => $tr_excerpt,
								'post_parent'  => $tr_parent,
								'menu_order'   => $page->menu_order,
							)
						);
						if ( (int) $insert_id > 0 ) {
							$tr_id = (int) $insert_id;

							// Enforce exact slug without -2 suffix.
							if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->posts ) {
								$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
									$wpdb->posts,
									array( 'post_name' => $page->post_name ),
									array( 'ID' => $tr_id )
								);
								if ( \function_exists( 'clean_post_cache' ) ) {
									\clean_post_cache( $tr_id );
								}
							}

							if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_trid' ) && method_exists( $sitepress, 'set_element_language_details' ) ) {
								$trid = $sitepress->get_element_trid( $page_id, 'post_page' );
								if ( ! $trid ) {
									$sitepress->set_element_language_details( $page_id, 'post_page', null, 'en' );
									$trid = $sitepress->get_element_trid( $page_id, 'post_page' );
								}
								$sitepress->set_element_language_details( $tr_id, 'post_page', $trid, Languages::to_wpml( $lang ), 'en' );
							}
						}
					}
				} elseif ( \function_exists( 'wp_update_post' ) ) {
						\wp_update_post(
							array(
								'ID'           => $tr_id,
								'post_title'   => $tr_title,
								'post_content' => $tr_content,
								'post_excerpt' => $tr_excerpt,
								'post_parent'  => $tr_parent,
							)
						);
				}

				if ( $tr_id > 0 && \function_exists( 'update_post_meta' ) ) {
					// Copy copyable fields.
					foreach ( array( 'lc_use_template_of_slug', '_seopress_robots_index', '_wp_page_template' ) as $copy_key ) {
						$val = \get_post_meta( $page_id, $copy_key, true );
						if ( '' !== $val ) {
							\update_post_meta( $tr_id, $copy_key, $val );
						}
					}

					// Update SEO.
					if ( isset( $translated['seo_title'] ) ) {
						\update_post_meta( $tr_id, '_seopress_titles_title', $translated['seo_title'] );
					}
					if ( isset( $translated['seo_desc'] ) ) {
						\update_post_meta( $tr_id, '_seopress_titles_desc', $translated['seo_desc'] );
					}
					++$count;
				}
			}
		}

		if ( \function_exists( 'kses_init_filters' ) ) {
			\kses_init_filters();
		}

		return $count;
	}

	/**
	 * Layer 3: Translates 146 pattern products.
	 *
	 * @param string|null $target_lang Target language code or null for all.
	 * @param bool        $force       Force re-translation.
	 * @return int Total translated products.
	 */
	public function translate_products( ?string $target_lang = null, bool $force = false ): int {
		$targets = null !== $target_lang ? array( Languages::from_wpml( $target_lang ) ) : Languages::TARGETS;
		$count   = 0;

		$products = Languages::source_posts(
			'product',
			array(
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		if ( \function_exists( 'kses_remove_filters' ) ) {
			\kses_remove_filters();
		}

		$sitepress = $GLOBALS['sitepress'] ?? null;
		global $wpdb;

		foreach ( $targets as $lang ) {
			foreach ( $products as $product ) {
				$prod_id = (int) $product->ID;
				$tr_id   = Languages::post_translation( $prod_id, $lang );

				if ( $tr_id > 0 && ! $force ) {
					continue;
				}

				// Title handling: keep proper name before |.
				$raw_title = (string) $product->post_title;
				$prefix    = '';
				$suffix    = $raw_title;
				if ( str_contains( $raw_title, ' | ' ) ) {
					list( $prefix, $suffix ) = explode( ' | ', $raw_title, 2 );
				}

				// Extract texts.
				$excerpt   = (string) $product->post_excerpt;
				$content   = (string) $product->post_content;
				$summary   = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $prod_id, 'summary', true ) : '' );
				$inspire   = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $prod_id, 'inspiration', true ) : '' );
				$effect    = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $prod_id, 'notable_effect', true ) : '' );
				$seo_title = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $prod_id, '_seopress_titles_title', true ) : '' );
				$seo_desc  = (string) ( \function_exists( 'get_post_meta' ) ? \get_post_meta( $prod_id, '_seopress_titles_desc', true ) : '' );

				$texts_to_translate = array_filter(
					array(
						'suffix'         => $suffix,
						'excerpt'        => $excerpt,
						'summary'        => $summary,
						'inspiration'    => $inspire,
						'notable_effect' => $effect,
						'seo_title'      => $seo_title,
						'seo_desc'       => $seo_desc,
					),
					static fn( string $s ): bool => '' !== trim( $s )
				);

				$translated = $this->batch_translate_map( $lang, $texts_to_translate );

				$tr_suffix  = $translated['suffix'] ?? $suffix;
				$tr_title   = '' !== $prefix ? $prefix . ' | ' . $tr_suffix : $tr_suffix;
				$tr_excerpt = $translated['excerpt'] ?? $excerpt;
				$tr_content = '' !== trim( $content ) ? $this->translate_html_document( $content, $lang ) : $content;

				if ( $tr_id <= 0 ) {
					if ( \function_exists( 'wp_insert_post' ) ) {
						$insert_id = \wp_insert_post(
							array(
								'post_type'    => 'product',
								'post_status'  => 'publish',
								'post_title'   => $tr_title,
								'post_name'    => $product->post_name,
								'post_content' => $tr_content,
								'post_excerpt' => $tr_excerpt,
								'menu_order'   => $product->menu_order,
							)
						);
						if ( (int) $insert_id > 0 ) {
							$tr_id = (int) $insert_id;

							// Enforce exact slug.
							if ( isset( $wpdb ) && $wpdb instanceof \wpdb && '' !== $wpdb->posts ) {
								$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
									$wpdb->posts,
									array( 'post_name' => $product->post_name ),
									array( 'ID' => $tr_id )
								);
								if ( \function_exists( 'clean_post_cache' ) ) {
									\clean_post_cache( $tr_id );
								}
							}

							if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_element_trid' ) && method_exists( $sitepress, 'set_element_language_details' ) ) {
								$trid = $sitepress->get_element_trid( $prod_id, 'post_product' );
								if ( ! $trid ) {
									$sitepress->set_element_language_details( $prod_id, 'post_product', null, 'en' );
									$trid = $sitepress->get_element_trid( $prod_id, 'post_product' );
								} elseif ( method_exists( $sitepress, 'get_element_language_details' ) ) {
									$src_det = $sitepress->get_element_language_details( $prod_id, 'post_product' );
									if ( ! is_object( $src_det ) || 'en' !== (string) ( $src_det->language_code ?? '' ) ) {
										$sitepress->set_element_language_details( $prod_id, 'post_product', $trid, 'en' );
									}
								}
								$sitepress->set_element_language_details( $tr_id, 'post_product', $trid, Languages::to_wpml( $lang ), 'en' );
							}
						}
					}
				} elseif ( \function_exists( 'wp_update_post' ) ) {
						\wp_update_post(
							array(
								'ID'           => $tr_id,
								'post_title'   => $tr_title,
								'post_content' => $tr_content,
								'post_excerpt' => $tr_excerpt,
							)
						);
				}

				if ( $tr_id > 0 ) {
					// Assign translated product_cat terms.
					if ( \function_exists( 'wp_get_object_terms' ) && \function_exists( 'wp_set_object_terms' ) ) {
						$src_cats = \wp_get_object_terms( $prod_id, 'product_cat', array( 'fields' => 'ids' ) );
						$tr_cats  = array();
						if ( is_array( $src_cats ) ) {
							foreach ( $src_cats as $cat_id ) {
								$tr_cat = Languages::term_translation( (int) $cat_id, 'product_cat', $lang );
								if ( $tr_cat > 0 ) {
									$tr_cats[] = $tr_cat;
								}
							}
						}
						if ( ! empty( $tr_cats ) ) {
							\wp_set_object_terms( $tr_id, $tr_cats, 'product_cat' );
						}

						// Copy product_type and visibility terms.
						foreach ( array( 'product_type', 'product_visibility' ) as $tax ) {
							$terms = \wp_get_object_terms( $prod_id, $tax, array( 'fields' => 'ids' ) );
							if ( is_array( $terms ) && ! empty( $terms ) ) {
								\wp_set_object_terms( $tr_id, $terms, $tax );
							}
						}
					}

					// Copy spec, provenance and commerce fields.
					if ( \function_exists( 'update_post_meta' ) ) {
						$copy_keys = array(
							'open_area',
							'visual_density',
							'repeat_behavior',
							'motifs',
							'best_fit',
							'cad_status',
							'source_site',
							'source_slug',
							'source_manifest',
							'openness_inferred_from',
							'openness_confidence',
							'_sku',
							'_regular_price',
							'_price',
							'_thumbnail_id',
							'_product_image_gallery',
							'_manage_stock',
							'_stock_status',
							'_virtual',
							'_downloadable',
							'_open_area',
							'_visual_density',
							'_repeat_behavior',
							'_motifs',
							'_best_fit',
							'_cad_status',
							'_summary',
							'_inspiration',
							'_notable_effect',
							'_source_site',
							'_source_slug',
							'_source_manifest',
							'_openness_inferred_from',
							'_openness_confidence',
						);

						foreach ( $copy_keys as $ck ) {
							$val = \get_post_meta( $prod_id, $ck, true );
							if ( '' !== $val ) {
								\update_post_meta( $tr_id, $ck, $val );
							}
						}

						// Write translated prose fields.
						if ( isset( $translated['summary'] ) ) {
							\update_post_meta( $tr_id, 'summary', $translated['summary'] );
						}
						if ( isset( $translated['inspiration'] ) ) {
							\update_post_meta( $tr_id, 'inspiration', $translated['inspiration'] );
						}
						if ( isset( $translated['notable_effect'] ) ) {
							\update_post_meta( $tr_id, 'notable_effect', $translated['notable_effect'] );
						}
						if ( isset( $translated['seo_title'] ) ) {
							\update_post_meta( $tr_id, '_seopress_titles_title', $translated['seo_title'] );
						}
						if ( isset( $translated['seo_desc'] ) ) {
							\update_post_meta( $tr_id, '_seopress_titles_desc', $translated['seo_desc'] );
						}
					}

					// WCML sync hook & lookup tables.
					if ( \function_exists( 'do_action' ) ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound,WooCommerce.Commenting.CommentHooks.MissingHookComment
						\do_action( 'wcml_synchronize_product_translations', $product, array( $tr_id ), array( $tr_id => Languages::to_wpml( $lang ) ) );
					}
					if ( \function_exists( 'wc_update_product_lookup_tables' ) ) {
						\wc_update_product_lookup_tables( $tr_id );
					}

					++$count;
				}
			}
		}

		if ( \function_exists( 'kses_init_filters' ) ) {
			\kses_init_filters();
		}

		return $count;
	}

	/**
	 * Layer 4: Translates LiveCanvas templates and partials.
	 *
	 * @param string|null $target_lang Target language code or null for all.
	 * @param bool        $force       Force re-translation.
	 * @return int Total translated template files.
	 */
	public function translate_templates( ?string $target_lang = null, bool $force = false ): int {
		$targets = null !== $target_lang ? array( Languages::from_wpml( $target_lang ) ) : Languages::TARGETS;
		$count   = 0;

		$base_dir = dirname( __DIR__, 2 ) . '/content/livecanvas';
		if ( ! is_dir( $base_dir ) ) {
			return 0;
		}

		// 1. Templates
		$template_files = glob( $base_dir . '/*.html' );
		$template_files = false === $template_files ? array() : $template_files;

		// 2. Partials
		$partial_files = glob( $base_dir . '/partials/*.html' );
		$partial_files = false === $partial_files ? array() : $partial_files;

		foreach ( $targets as $lang ) {
			foreach ( $template_files as $file ) {
				$file_key = basename( $file, '.html' );
				$en_html  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( '' === trim( $en_html ) ) {
					continue;
				}

				$src_hash = hash( 'sha256', rtrim( $en_html, "\n" ) );
				$existing = TemplateStore::load( $lang );
				if ( ! $force && isset( $existing[ 'tpl:' . $file_key ] ) && $existing[ 'tpl:' . $file_key ]['src_hash'] === $src_hash ) {
					continue;
				}

				$tr_html = $this->translate_html_document( $en_html, $lang );
				TemplateStore::put_template( $file_key, $en_html, $tr_html, $lang );
				++$count;
			}

			foreach ( $partial_files as $file ) {
				$slug    = basename( $file, '.html' );
				$en_html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( '' === trim( $en_html ) ) {
					continue;
				}

				$src_hash = hash( 'sha256', rtrim( $en_html, "\n" ) );
				$existing = TemplateStore::load( $lang );
				if ( ! $force && isset( $existing[ 'part:' . $slug ] ) && $existing[ 'part:' . $slug ]['src_hash'] === $src_hash ) {
					continue;
				}

				$tr_html = $this->translate_html_document( $en_html, $lang );
				TemplateStore::put_partial( $slug, $en_html, $tr_html, $lang );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Translates an HTML document using Segmenter and TranslationValidator,
	 * including inner markup of `<template>` elements.
	 *
	 * @param string $html Full HTML string.
	 * @param string $lang Target canonical language code.
	 */
	public function translate_html_document( string $html, string $lang ): string {
		$html = $this->transform_template_blocks(
			$html,
			fn( string $inner ): string => $this->translate_html_document( $inner, $lang )
		);

		$doc = $this->segmenter->segment_html( $html );
		if ( empty( $doc['segments'] ) ) {
			return 'zh' === $lang ? TemplateStore::sanitize_zh_glossary_html( $html ) : $html;
		}

		$segment_texts = array();
		foreach ( $doc['segments'] as $seg ) {
			$segment_texts[] = $seg['text'];
		}

		$translated_map = $this->translate_segments_cached( $lang, $segment_texts );

		$translated_list = array();
		foreach ( $segment_texts as $src_text ) {
			$translated_list[] = $translated_map[ $src_text ] ?? $src_text;
		}

		$assembled = $this->segmenter->reassemble( $doc, $translated_list );
		return 'zh' === $lang ? TemplateStore::sanitize_zh_glossary_html( $assembled ) : $assembled;
	}

	/**
	 * Walks `<template ...>...</template>` elements using HtmlTokenizer and applies
	 * a transformation callback to each top-level template's inner HTML losslessly.
	 *
	 * @param string   $html          Source HTML.
	 * @param callable $inner_handler `fn(string $inner_html): string` callback.
	 */
	private function transform_template_blocks( string $html, callable $inner_handler ): string {
		if ( false === stripos( $html, '<template' ) ) {
			return $html;
		}

		$tokenizer      = new HtmlTokenizer();
		$tokens         = $tokenizer->tokens( $html );
		$out            = '';
		$template_depth = 0;
		$open_tag       = '';
		$inner_buf      = '';

		foreach ( $tokens as $token ) {
			if ( HtmlTokenizer::TYPE_TAG === $token['type'] && 'template' === $token['name'] ) {
				$self_closing = ! $token['closing'] && str_ends_with( rtrim( substr( $token['raw'], 0, -1 ) ), '/' );
				if ( ! $token['closing'] && ! $self_closing ) {
					if ( 0 === $template_depth ) {
						$open_tag       = $token['raw'];
						$inner_buf      = '';
						$template_depth = 1;
					} else {
						++$template_depth;
						$inner_buf .= $token['raw'];
					}
					continue;
				}

				if ( $token['closing'] ) {
					if ( $template_depth > 1 ) {
						--$template_depth;
						$inner_buf .= $token['raw'];
					} elseif ( 1 === $template_depth ) {
						$template_depth = 0;
						$transformed    = (string) $inner_handler( $inner_buf );
						$out           .= $open_tag . $transformed . $token['raw'];
						$open_tag       = '';
						$inner_buf      = '';
					} else {
						$out .= $token['raw'];
					}
					continue;
				}
			}

			if ( $template_depth > 0 ) {
				$inner_buf .= $token['raw'];
			} else {
				$out .= $token['raw'];
			}
		}

		if ( $template_depth > 0 ) {
			$out .= $open_tag . $inner_buf;
		}

		return $out;
	}

	/**
	 * Translates an associative map of texts (e.g. ['title' => '...', 'desc' => '...']).
	 *
	 * @param string                $lang  Canonical language code.
	 * @param array<string, string> $texts Map of key => source text.
	 * @return array<string, string> Map of key => translated text.
	 */
	public function batch_translate_map( string $lang, array $texts ): array {
		if ( empty( $texts ) ) {
			return array();
		}

		$docs            = array();
		$masked_segments = array();

		foreach ( $texts as $key => $src_val ) {
			$doc          = $this->segmenter->segment_text( $src_val );
			$docs[ $key ] = $doc;
			foreach ( $doc['segments'] as $seg ) {
				$masked_segments[] = $seg['text'];
			}
		}

		$translated_map = ! empty( $masked_segments )
			? $this->translate_segments_cached( $lang, $masked_segments )
			: array();

		$results = array();
		foreach ( $texts as $key => $src_val ) {
			$doc = $docs[ $key ];
			if ( empty( $doc['segments'] ) ) {
				$results[ $key ] = $src_val;
				continue;
			}
			$tr_list = array();
			foreach ( $doc['segments'] as $seg ) {
				$src_seg   = $seg['text'];
				$tr_list[] = $translated_map[ $src_seg ] ?? $src_seg;
			}
			$results[ $key ] = $this->segmenter->reassemble( $doc, $tr_list );
		}

		return $results;
	}

	/**
	 * Translates segments using TM cache and parallel DeepSeek requests for misses.
	 *
	 * @param string             $lang     Target language code.
	 * @param array<int, string> $segments Source segments with placeholders.
	 * @return array<string, string> Map of source segment => translated segment.
	 * @throws RuntimeException On translation or validation failure.
	 */
	public function translate_segments_cached( string $lang, array $segments ): array {
		$unique_segments = array_values( array_unique( array_filter( $segments, static fn( $s ): bool => '' !== trim( $s ) ) ) );
		if ( empty( $unique_segments ) ) {
			return array();
		}

		$this->ensure_tm_loaded();

		$results = array();
		$misses  = array();

		foreach ( $unique_segments as $seg ) {
			if ( 1 !== preg_match( '/\p{L}/u', TranslationValidator::strip_placeholders( $seg ) ) ) {
				$results[ $seg ] = $seg;
				continue;
			}
			$key = $lang . ':' . hash( 'sha256', $seg );
			if ( isset( $this->tm_cache[ $key ] ) ) {
				$results[ $seg ] = 'zh' === $lang
					? TemplateStore::sanitize_zh_glossary_text( $this->tm_cache[ $key ] )
					: $this->tm_cache[ $key ];
			} else {
				$misses[] = $seg;
			}
		}

		if ( empty( $misses ) ) {
			return $results;
		}

		// Chunk misses into batches of up to 25 items (~4,000 chars) and execute in waves of 20 batches.
		$chunks  = array_chunk( $misses, 25 );
		$batches = array();
		foreach ( $chunks as $chunk ) {
			$batches[] = array(
				'lang'     => $lang,
				'segments' => $chunk,
			);
		}

		foreach ( array_chunk( $batches, 20 ) as $wave ) {
			$translated_batches = $this->client->translate_batches_parallel( $wave, 20 );

			foreach ( $wave as $b_idx => $b_data ) {
				$src_chunk = $b_data['segments'];
				$tr_chunk  = $translated_batches[ $b_idx ] ?? array();

				foreach ( $src_chunk as $c_idx => $src_item ) {
					$raw_tr                 = $tr_chunk[ $c_idx ] ?? '';
					$tr_item                = $this->validate_or_retry_item( $lang, $src_item, $raw_tr );
					$results[ $src_item ]   = $tr_item;
					$key                    = $lang . ':' . hash( 'sha256', $src_item );
					$this->tm_cache[ $key ] = $tr_item;
				}
			}

			$this->save_tm_cache();
		}

		return $results;
	}

	/**
	 * Prewarms all missing (language, segment) pairs across requested layers in parallel waves.
	 *
	 * @param array<int, string> $targets Canonical target language codes.
	 * @param string             $only    Layer selector ('all', 'terms', 'pages', 'products', 'templates').
	 */
	private function prewarm_all( array $targets, string $only ): void {
		$source_segments = $this->collect_source_segments( $only );
		if ( empty( $source_segments ) ) {
			return;
		}

		$this->ensure_tm_loaded();

		$batches = array();
		foreach ( $targets as $lang ) {
			$lang_misses = array();
			foreach ( $source_segments as $seg ) {
				$key = $lang . ':' . hash( 'sha256', $seg );
				if ( ! isset( $this->tm_cache[ $key ] ) ) {
					$lang_misses[] = $seg;
				}
			}
			foreach ( array_chunk( $lang_misses, 25 ) as $chunk ) {
				$batches[] = array(
					'lang'     => $lang,
					'segments' => $chunk,
				);
			}
		}

		if ( empty( $batches ) ) {
			return;
		}

		$waves       = array_chunk( $batches, 20 );
		$total_waves = count( $waves );

		foreach ( $waves as $w_idx => $wave ) {
			if ( \class_exists( 'WP_CLI' ) ) {
				\WP_CLI::log(
					sprintf(
						'Prewarming TM wave %d/%d (%d batches)...',
						$w_idx + 1,
						$total_waves,
						count( $wave )
					)
				);
			}

			$translated_batches = $this->client->translate_batches_parallel( $wave, 20 );

			try {
				foreach ( $wave as $b_idx => $b_data ) {
					$lang      = $b_data['lang'];
					$src_chunk = $b_data['segments'];
					$tr_chunk  = $translated_batches[ $b_idx ] ?? array();

					foreach ( $src_chunk as $c_idx => $src_item ) {
						$raw_tr = $tr_chunk[ $c_idx ] ?? '';
						try {
							$tr_item                = $this->validate_or_retry_item( $lang, $src_item, $raw_tr );
							$key                    = $lang . ':' . hash( 'sha256', $src_item );
							$this->tm_cache[ $key ] = $tr_item;
						} catch ( \Throwable $e ) {
							if ( \class_exists( 'WP_CLI' ) ) {
								\WP_CLI::warning( 'Prewarm deferred item: ' . $e->getMessage() );
							}
						}
					}
				}
			} finally {
				$this->save_tm_cache();
			}
		}
	}

	/**
	 * Collects unique masked source segments across requested translation layers.
	 *
	 * @param string $only Layer selector.
	 * @return array<int, string>
	 */
	private function collect_source_segments( string $only ): array {
		$seen = array();

		$add_plain = function ( string $text ) use ( &$seen ): void {
			if ( '' === trim( $text ) ) {
				return;
			}
			$doc = $this->segmenter->segment_text( $text );
			foreach ( $doc['segments'] as $seg ) {
				$s = $seg['text'];
				if ( '' !== trim( $s ) && 1 === preg_match( '/\p{L}/u', TranslationValidator::strip_placeholders( $s ) ) ) {
					$seen[ $s ] = true;
				}
			}
		};

		$add_html = null;
		$add_html = function ( string $html ) use ( &$seen, &$add_html ): void {
			if ( '' === trim( $html ) ) {
				return;
			}
			$this->transform_template_blocks(
				$html,
				static function ( string $inner ) use ( &$add_html ): string {
					$add_html( $inner );
					return $inner;
				}
			);
			$doc = $this->segmenter->segment_html( $html );
			foreach ( $doc['segments'] as $seg ) {
				$s = $seg['text'];
				if ( '' !== trim( $s ) && 1 === preg_match( '/\p{L}/u', TranslationValidator::strip_placeholders( $s ) ) ) {
					$seen[ $s ] = true;
				}
			}
		};

		if ( in_array( $only, array( 'all', 'terms' ), true ) ) {
			foreach ( Languages::source_terms( 'product_cat' ) as $term ) {
				if ( 'uncategorized' === $term->slug ) {
					continue;
				}
				$tid = (int) $term->term_id;
				$add_plain( (string) $term->name );
				$add_plain( (string) $term->description );
				if ( \function_exists( 'get_term_meta' ) ) {
					$add_plain( (string) \get_term_meta( $tid, 'typical_applications', true ) );
					$add_plain( (string) \get_term_meta( $tid, 'style_blurb', true ) );
					$add_plain( (string) \get_term_meta( $tid, '_seopress_titles_title', true ) );
					$add_plain( (string) \get_term_meta( $tid, '_seopress_titles_desc', true ) );
					$add_html( (string) \get_term_meta( $tid, 'bottom_content', true ) );
				}
			}
		}

		if ( in_array( $only, array( 'all', 'pages' ), true ) ) {
			foreach ( Languages::source_posts( 'page' ) as $page ) {
				$pid = (int) $page->ID;
				$add_plain( (string) $page->post_title );
				$add_plain( (string) $page->post_excerpt );
				$add_html( (string) $page->post_content );
				if ( \function_exists( 'get_post_meta' ) ) {
					$add_plain( (string) \get_post_meta( $pid, '_seopress_titles_title', true ) );
					$add_plain( (string) \get_post_meta( $pid, '_seopress_titles_desc', true ) );
				}
			}
		}

		if ( in_array( $only, array( 'all', 'products' ), true ) ) {
			foreach ( Languages::source_posts( 'product' ) as $product ) {
				$pid       = (int) $product->ID;
				$raw_title = (string) $product->post_title;
				$suffix    = str_contains( $raw_title, ' | ' ) ? explode( ' | ', $raw_title, 2 )[1] : $raw_title;
				$add_plain( $suffix );
				$add_plain( (string) $product->post_excerpt );
				$add_html( (string) $product->post_content );
				if ( \function_exists( 'get_post_meta' ) ) {
					$add_plain( (string) \get_post_meta( $pid, 'summary', true ) );
					$add_plain( (string) \get_post_meta( $pid, 'inspiration', true ) );
					$add_plain( (string) \get_post_meta( $pid, 'notable_effect', true ) );
					$add_plain( (string) \get_post_meta( $pid, '_seopress_titles_title', true ) );
					$add_plain( (string) \get_post_meta( $pid, '_seopress_titles_desc', true ) );
				}
			}
		}

		if ( in_array( $only, array( 'all', 'templates' ), true ) ) {
			$base_dir = dirname( __DIR__, 2 ) . '/content/livecanvas';
			if ( is_dir( $base_dir ) ) {
				$tpl_glob  = glob( $base_dir . '/*.html' );
				$part_glob = glob( $base_dir . '/partials/*.html' );
				$files     = array_merge(
					false === $tpl_glob ? array() : $tpl_glob,
					false === $part_glob ? array() : $part_glob
				);
				foreach ( $files as $file ) {
					$en_html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$add_html( $en_html );
				}
			}
		}

		return array_keys( $seen );
	}

	/**
	 * Normalizes digits, validates a translated item, and retries up to twice with validation feedback.
	 *
	 * @param string $lang     Canonical target language code.
	 * @param string $src_item Source masked segment.
	 * @param string $raw_tr   Initial raw translation.
	 * @throws RuntimeException When validation still fails after retries.
	 */
	private function validate_or_retry_item( string $lang, string $src_item, string $raw_tr ): string {
		$tr_item  = TranslationValidator::normalize_digits( $raw_tr );
		$problems = $this->validator->validate( $src_item, $tr_item, $lang );

		for ( $attempt = 0; $attempt < 2 && ! empty( $problems ); $attempt++ ) {
			try {
				$feedback = implode( '; ', $problems );
				if ( 'zh' === $lang ) {
					$feedback .= ' | ' . self::ZH_GLOSSARY_SYSTEM_PROMPT;
				}
				$retry_chunk = $this->client->translate_batch( $lang, array( $src_item ), $feedback );
				$tr_item     = TranslationValidator::normalize_digits( $retry_chunk[0] ?? '' );
				$problems    = $this->validator->validate( $src_item, $tr_item, $lang );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException( sprintf( 'Translation validation failed for "%s" (%s): %s', $src_item, $lang, $e->getMessage() ) );
			}
		}

		if ( ! empty( $problems ) && 'zh' === $lang ) {
			$tr_item  = TemplateStore::sanitize_zh_glossary_text( $tr_item );
			$problems = $this->validator->validate( $src_item, $tr_item, $lang );
		}

		if ( ! empty( $problems ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( sprintf( 'Translation validation failed for "%s" (%s): %s', $src_item, $lang, implode( '; ', $problems ) ) );
		}

		return $tr_item;
	}

	/**
	 * Loads translation memory cache.
	 */
	private function ensure_tm_loaded(): void {
		if ( null !== $this->tm_cache ) {
			return;
		}

		if ( is_readable( $this->tm_cache_path ) ) {
			$bytes = file_get_contents( $this->tm_cache_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $bytes ) {
				$data = json_decode( $bytes, true );
				if ( is_array( $data ) ) {
					/* @var array<string, string> $data */
					$this->tm_cache = $data;
					return;
				}
			}
		}

		$this->tm_cache = array();
	}

	/**
	 * Saves translation memory cache atomically.
	 */
	private function save_tm_cache(): void {
		if ( null === $this->tm_cache ) {
			return;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
		$dir = dirname( $this->tm_cache_path );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}

		$temp = $this->tm_cache_path . '.tmp.' . uniqid( '', true );
		try {
			$json = \function_exists( 'wp_json_encode' )
				? (string) \wp_json_encode( $this->tm_cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: (string) json_encode( $this->tm_cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} catch ( \Throwable ) {
			$json = (string) json_encode( $this->tm_cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$res = @file_put_contents( $temp, $json, LOCK_EX );

		if ( false !== $res ) {
			@rename( $temp, $this->tm_cache_path );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
