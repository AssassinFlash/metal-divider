<?php
/**
 * The schema filters: what SEOPress's builders cannot express.
 *
 * @package MetalDivider\Site\Seo
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Seo;

/**
 * The data-assembly hooks of the SEO plane (spec 22 §3, ticket #34). The
 * builders own every field they can map — these hooks only add the parts
 * the structured data builder has no field for: the WebSite's
 * SearchAction, the Organization's two contact points, the Product's
 * material / ACF properties / QuoteAction and gallery, the article's
 * publisher reference, the breadcrumb trails' shape (the pixel-gate
 * replica's two-level trails, not SEOPress's four-level defaults), and
 * the title whitespace the empty pagination variable leaves behind.
 * Values still come from the site's data — nothing here invents a fact.
 */
final class SchemaFilters {

	/**
	 * The site-wide material wording (owner ruling 2026-10-24
	 * revision 6 — the same constant the spec bar renders).
	 */
	private const MATERIAL = 'Stainless Steel (304 / 316)';

	/**
	 * The ACF fields of the Product schema's additionalProperty list
	 * (spec 22 §3.3): the spec bar's facts, as the data model stores
	 * them, under the names the spec's skeleton gives them. Open area
	 * is absent for the sixteen "On request" patterns — a property
	 * without a sourced value is omitted, never guessed.
	 */
	private const PROPERTIES = array(
		'open_area'       => 'Open area',
		'visual_density'  => 'Visual density',
		'repeat_behavior' => 'Repeat behavior',
		'best_fit'        => 'Best fit',
	);

	/**
	 * Adds the filters. Every callback guards on the SEOPress service
	 * it reads, so a site without SEOPress simply never assembles a
	 * schema — no error, no output.
	 */
	public function register(): void {
		add_filter( 'seopress_schemas_website', array( $this, 'website' ) );
		add_filter( 'seopress_get_json_data_organization', array( $this, 'organization' ) );
		add_filter( 'seopress_schemas_auto_product_json', array( $this, 'product' ) );
		add_filter( 'seopress_schemas_auto_article_json', array( $this, 'article' ) );
		add_filter( 'seopress_pro_breadcrumbs_crumbs', array( $this, 'crumbs' ) );
		add_filter( 'seopress_titles_title', array( $this, 'title' ) );
		add_filter( 'seopress_titles_desc', array( $this, 'description' ) );
		add_filter( 'seopress_social_og_title', array( $this, 'og_title' ) );
		add_filter( 'seopress_social_og_desc', array( $this, 'og_desc' ) );
		add_filter( 'pre_get_document_title', array( $this, 'title' ), 20 );
		add_filter( 'seopress_pro_breadcrumbs_json_remove_home', '__return_false' );

		// The catalogue is a page listing 146 patterns, and no SEOPress builder
		// emits an ItemList for a page: its Product schema is per-product and
		// its CollectionPage is a WooCommerce archive, which /patterns/ is not (it
		// is page #6 with the default template). Without this block a search
		// engine sees a WebPage and no list at all.
		add_action( 'wp_head', array( $this, 'catalog_item_list' ), 99 );
		add_action( 'wp_head', array( $this, 'localized_faq_schema' ), 99 );
	}

	/**
	 * The catalogue's ItemList: the patterns the page actually renders.
	 *
	 * Reads the main query — the one `Catalog::filter_query()` shapes with the
	 * 24-per-page pager, the search term and the sort — so the list and the
	 * grid cannot disagree. A fixed "first 24 by title" query (the first
	 * version) described page one on every page: page 2+ carried the same
	 * items, and a search or a sort carried the unfiltered set, which is a
	 * false statement about the visible list on exactly the pages the robots
	 * rules mark noindex. When the main query has no posts this prints nothing:
	 * an empty result set must not be padded from elsewhere.
	 *
	 * The fixed query remains as the fallback for a runtime with no main query
	 * (a test, a headless render before `wp()`), so the block is still
	 * assembled where no loop exists. Item urls are the patterns' own
	 * permalinks; the order is the page's order.
	 */
	public function catalog_item_list(): void {
		// `/patterns/` is WooCommerce's product archive, not a page: its body class
		// is `post-type-archive-product woocommerce-shop`. `is_page('shop')` is
		// therefore always false here, which is why the first version emitted
		// nothing and the page still had a single JSON-LD block.
		$is_catalog = \function_exists( 'is_shop' ) ? \is_shop() : \is_post_type_archive( 'product' );
		if ( ! $is_catalog ) {
			return;
		}

		$query    = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ? $GLOBALS['wp_query'] : null;
		$products = array();
		if ( null !== $query ) {
			$posts = is_array( $query->posts ) ? $query->posts : array();
			foreach ( $posts as $post ) {
				$product_id = is_object( $post ) ? (int) $post->ID : (int) $post;
				if ( $product_id > 0 ) {
					$products[] = $product_id;
				}
			}
			if ( array() === $products ) {
				return;
			}
		} else {
			$products = \get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 24,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'fields'         => 'ids',
				)
			);
			if ( array() === $products ) {
				return;
			}
		}
		$items    = array();
		$position = 0;
		foreach ( $products as $product_id ) {
			++$position;
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'url'      => (string) \get_permalink( (int) $product_id ),
				'name'     => (string) \get_the_title( (int) $product_id ),
			);
		}
		// `numberOfItems` is the size of the list this page renders, not the
		// catalogue total: schema.org defines it as the number of items in the
		// list, and claiming 146 while listing 24 would be a false statement
		// about the markup. The total is already visible on the page.
		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => \MetalDivider\Site\I18n\Dictionary::text( 'item_list_name' ),
			'numberOfItems'   => \count( $items ),
			'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
			'itemListElement' => $items,
		);
		echo "\n<script type=\"application/ld+json\">" . \wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a script tag.
	}

	/**
	 * The WebSite block's SearchAction (spec 22 S3): the blog's search
	 * face is /?s=, and SEOPress's native block carries no action.
	 *
	 * @param array<string, mixed> $schema The WebSite schema.
	 * @return array<string, mixed> The schema with the SearchAction.
	 */
	public function website( array $schema ): array {
		$schema['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/' ) . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		);
		return $schema;
	}

	/**
	 * The Organization's contact points (spec 22 §3.3): one sales
	 * point carrying the email, one WhatsApp point carrying the phone
	 * — both from the Knowledge Graph fields this plugin's config
	 * wrote, never literals of their own.
	 *
	 * @param array<string, mixed> $schema The Organization schema.
	 * @return array<string, mixed> The schema with the two points.
	 */
	public function organization( array $schema ): array {
		$email = '';
		$phone = '';
		if ( function_exists( 'seopress_get_service' ) ) {
			$social = seopress_get_service( 'SocialOption' );
			if ( is_object( $social ) && method_exists( $social, 'getSocialKnowledgeEmail' ) ) {
				$email = (string) $social->getSocialKnowledgeEmail();
			}
			if ( is_object( $social ) && method_exists( $social, 'getSocialKnowledgePhone' ) ) {
				$phone = (string) $social->getSocialKnowledgePhone();
			}
		}
		$languages = array( 'English', 'Chinese', 'Spanish', 'Portuguese', 'German', 'French', 'Arabic', 'Indonesian', 'Vietnamese' );
		$points    = array();
		if ( '' !== $email ) {
			$points[] = array(
				'@type'             => 'ContactPoint',
				'contactType'       => 'sales',
				'email'             => $email,
				'availableLanguage' => $languages,
			);
		}
		if ( '' !== $phone ) {
			$points[] = array(
				'@type'             => 'ContactPoint',
				'contactType'       => 'WhatsApp',
				'telephone'         => $phone,
				'availableLanguage' => $languages,
			);
		}
		if ( array() !== $points ) {
			$schema['contactPoint'] = $points;
		}
		// The Knowledge Graph field is copied verbatim into the schema, and a
		// site URL saved before the TLS cutover (or resolved through a proxy
		// that reports http) would publish an http logo. The site is https-only,
		// so the schema asserts the scheme every visitor is actually served:
		// a mixed-content image and a second entity URL otherwise.
		if ( isset( $schema['logo'] ) ) {
			$schema['logo'] = $this->https( $schema['logo'] );
		}
		// The builder copies the phone into a top-level telephone as
		// well; the spec's skeleton carries it on the contact point
		// only.
		unset( $schema['telephone'] );
		return $schema;
	}

	/**
	 * An image value forced onto https.
	 *
	 * SEOPress emits the logo either as a URL string or as an ImageObject
	 * (url / contentUrl / @id); both shapes are normalized, anything else is
	 * returned untouched.
	 *
	 * @param mixed $value The logo value.
	 * @return mixed The same shape, with an https scheme on its URL fields.
	 */
	private function https( $value ) {
		if ( is_string( $value ) ) {
			return (string) \preg_replace( '#^http://#i', 'https://', $value );
		}
		if ( is_array( $value ) ) {
			foreach ( array( 'url', 'contentUrl', '@id' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					$value[ $key ] = (string) \preg_replace( '#^http://#i', 'https://', $value[ $key ] );
				}
			}
		}
		return $value;
	}

	/**
	 * The Product schema SEOPress's builder cannot complete (spec 22
	 * §3.1/§3.2): the material constant, the series name, the ACF
	 * facts as additionalProperty, the gallery behind the featured
	 * image, the absolute @id — and the conversion path as a
	 * QuoteAction, because the price is empty by ruling and offers is
	 * therefore never asserted.
	 *
	 * @param array<string, mixed> $json The builder's Product schema.
	 * @return array<string, mixed> The completed schema.
	 */
	public function product( array $json ): array {
		$post_id = (int) get_the_ID();
		if ( 0 === $post_id ) {
			return $json;
		}
		$json['@id'] = (string) get_permalink( $post_id ) . '#product';
		$description = (string) get_post_meta( $post_id, '_seopress_titles_desc', true );
		if ( '' !== $description ) {
			// The migrated per-pattern description, untrimmed — the
			// builder's own resolution would cut it to 50 words — with
			// its entities decoded, the way the schema encoder resolves
			// every other field it carries.
			$json['description'] = html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5 );
		}
		$current_lang     = \MetalDivider\Site\I18n\Languages::current();
		$json['image']    = $this->images( $post_id, is_array( $json['image'] ?? null ) ? $json['image'] : array() );
		$json['material'] = 'en' === $current_lang
			? self::MATERIAL
			: \MetalDivider\Site\I18n\Dictionary::text( 'material_wording', $current_lang );
		$series           = $this->series_name( $post_id );
		if ( '' !== $series ) {
			$json['category'] = $series;
		}
		$properties = $this->properties( $post_id );
		if ( array() !== $properties ) {
			$json['additionalProperty'] = $properties;
		}
		$json['potentialAction'] = array(
			'@type'  => 'QuoteAction',
			'name'   => \MetalDivider\Site\I18n\Dictionary::text( 'request_a_quote', $current_lang ),
			'target' => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => \MetalDivider\Site\I18n\Languages::home_url( '/inquiry/', $current_lang ),
			),
		);
		return $json;
	}

	/**
	 * The article's author and publisher (spec 22 S6): both the one
	 * Organization entity, by reference.
	 *
	 * @param array<string, mixed> $json The builder's article schema.
	 * @return array<string, mixed> The schema with the entity reference.
	 */
	public function article( array $json ): array {
		$entity            = array( '@id' => $this->entity_id( 'organization' ) );
		$json['author']    = $entity;
		$json['publisher'] = $entity;
		return $json;
	}

	/**
	 * WooCommerce default shop/product archive breadcrumb labels across locales
	 * that must be normalized to the pattern catalog label.
	 */
	private const SHOP_CRUMB_LABELS = array(
		'shop',
		'products',
		'商店',
		'商品',
		'产品',
		'tienda',
		'productos',
		'loja',
		'produtos',
		'boutique',
		'produits',
		'produkte',
		'المتجر',
		'المنتجات',
		'toko',
		'produk',
		'cửa hàng',
		'sản phẩm',
	);

	/**
	 * The breadcrumb trails (spec 22 S4): the pixel-gate replica's
	 * own markup is the visible truth, and its trails are two-level —
	 * Home, then the page — so the JSON-LD carries the same shape.
	 * SEOPress's defaults would walk the shop base and the series
	 * term on pattern pages and the /pages shell on content pages.
	 *
	 * Localizes the root Home crumb and replaces any WooCommerce Shop
	 * archive crumb ("Shop" / "商店" / "Tienda" / etc.) with the
	 * localized pattern library label.
	 *
	 * @param array<int, array<int, mixed>> $crumbs The crumbs.
	 * @return array<int, array<int, mixed>> The shaped crumbs.
	 */
	public function crumbs( array $crumbs ): array {
		if ( array() === $crumbs ) {
			return $crumbs;
		}

		$lang = \MetalDivider\Site\I18n\Languages::current();
		if ( isset( $crumbs[0] ) ) {
			$crumbs[0][0] = \MetalDivider\Site\I18n\Dictionary::text( 'home_breadcrumb', $lang );
			if ( 'en' !== $lang && isset( $crumbs[0][1] ) && is_string( $crumbs[0][1] ) && '' !== $crumbs[0][1] ) {
				$crumbs[0][1] = \MetalDivider\Site\I18n\UrlLocalizer::rewrite_url( $crumbs[0][1], $lang );
			}
		}

		foreach ( $crumbs as $index => $crumb ) {
			if ( 0 === $index ) {
				continue;
			}
			if ( $this->is_patterns_archive_crumb( $crumb ) ) {
				$crumbs[ $index ][0] = \MetalDivider\Site\I18n\Dictionary::text( 'patterns_breadcrumb', $lang );
				if ( 'en' !== $lang && isset( $crumbs[ $index ][1] ) && is_string( $crumbs[ $index ][1] ) && '' !== $crumbs[ $index ][1] ) {
					$crumbs[ $index ][1] = \MetalDivider\Site\I18n\UrlLocalizer::rewrite_url( $crumbs[ $index ][1], $lang );
				}
			} elseif ( isset( $crumbs[ $index ][0] ) && is_string( $crumbs[ $index ][0] ) ) {
				$crumbs[ $index ][0] = self::decode_title_entities( $crumbs[ $index ][0] );
			}
		}

		if ( is_singular( 'product' ) ) {
			// Home > Pattern (the replica's PDP trail).
			return array( $crumbs[0], $crumbs[ count( $crumbs ) - 1 ] );
		}
		if ( is_page() ) {
			// The /pages shell is a URL prefix, not a trail level: drop
			// every crumb between Home and the page itself.
			return array( $crumbs[0], $crumbs[ count( $crumbs ) - 1 ] );
		}
		return $crumbs;
	}

	/**
	 * The final title: localizes front-page and pattern-catalog titles on
	 * non-default languages, decodes residual numeric/named HTML entities
	 * (such as `&#038;` / `&amp;#038;` / `&#8217;`), and collapses doubled
	 * or trailing pagination whitespace.
	 *
	 * @param string $title The assembled title.
	 * @return string The localized, entity-clean, whitespace-normalized title.
	 */
	public function title( string $title ): string {
		$localized = $this->localized_archive_or_home_title();
		if ( null !== $localized ) {
			$title = $localized;
		}
		$title = self::decode_title_entities( $title );
		return trim( (string) preg_replace( '/ {2,}/u', ' ', $title ) );
	}

	/**
	 * Meta description filter (`seopress_titles_desc`): supplies localized
	 * descriptions for the front page and pattern catalog archive on
	 * non-default frontend requests.
	 *
	 * @param string $desc The assembled meta description.
	 * @return string The localized meta description.
	 */
	public function description( string $desc ): string {
		$localized = $this->localized_archive_or_home_desc();
		if ( null !== $localized ) {
			return $localized;
		}
		return self::decode_title_entities( $desc );
	}

	/**
	 * Open Graph title filter (`seopress_social_og_title`): handles both
	 * SEOPress's `<meta property="og:title" content="...">` tag string and
	 * plain string specifications.
	 *
	 * @param string $value Current OG title tag or string.
	 * @return string Localized OG title tag or string.
	 */
	public function og_title( string $value ): string {
		$localized = $this->localized_archive_or_home_title();
		if ( null !== $localized ) {
			return self::replace_meta_tag_or_plain( $value, $localized );
		}
		if ( '' === $value ) {
			return $value;
		}
		if ( str_starts_with( ltrim( $value ), '<meta ' ) ) {
			return (string) preg_replace_callback(
				'/(content\s*=\s*")([^"]*)(")/i',
				static function ( array $m ): string {
					$clean = self::decode_title_entities( $m[2] );
					return $m[1] . self::escape_meta_attr( $clean ) . $m[3];
				},
				$value
			);
		}
		return self::decode_title_entities( $value );
	}

	/**
	 * Open Graph description filter (`seopress_social_og_desc`): handles both
	 * SEOPress's `<meta property="og:description" content="...">` tag string and
	 * plain string specifications.
	 *
	 * @param string $value Current OG description tag or string.
	 * @return string Localized OG description tag or string.
	 */
	public function og_desc( string $value ): string {
		$localized = $this->localized_archive_or_home_desc();
		if ( null !== $localized ) {
			return self::replace_meta_tag_or_plain( $value, $localized );
		}
		return $value;
	}

	/**
	 * Resolves localized SEO title for front page or pattern catalog archive on
	 * non-default frontend languages, or null when current route uses per-post/term meta.
	 */
	private function localized_archive_or_home_title(): ?string {
		$lang = \MetalDivider\Site\I18n\Languages::current();
		if ( \MetalDivider\Site\I18n\Languages::SOURCE === $lang || ! \MetalDivider\Site\I18n\Languages::is_frontend_non_default() ) {
			return null;
		}

		$paged = $this->current_page_number();

		if ( \function_exists( 'is_front_page' ) && \is_front_page() ) {
			$base = \MetalDivider\Site\I18n\Dictionary::text( 'seo_home_title', $lang );
			return $paged > 1 ? $base . ' — ' . sprintf( \MetalDivider\Site\I18n\Dictionary::text( 'seo_page_num', $lang ), $paged ) : $base;
		}

		if ( $this->is_pattern_catalog_archive() ) {
			$base = \MetalDivider\Site\I18n\Dictionary::text( 'seo_shop_title', $lang );
			return $paged > 1 ? $base . ' — ' . sprintf( \MetalDivider\Site\I18n\Dictionary::text( 'seo_page_num', $lang ), $paged ) : $base;
		}

		return null;
	}

	/**
	 * Resolves localized SEO description for front page or pattern catalog archive on
	 * non-default frontend languages, or null when current route uses per-post/term meta.
	 */
	private function localized_archive_or_home_desc(): ?string {
		$lang = \MetalDivider\Site\I18n\Languages::current();
		if ( \MetalDivider\Site\I18n\Languages::SOURCE === $lang || ! \MetalDivider\Site\I18n\Languages::is_frontend_non_default() ) {
			return null;
		}

		if ( \function_exists( 'is_front_page' ) && \is_front_page() ) {
			return \MetalDivider\Site\I18n\Dictionary::text( 'seo_home_desc', $lang );
		}

		if ( $this->is_pattern_catalog_archive() ) {
			return \MetalDivider\Site\I18n\Dictionary::text( 'seo_shop_desc', $lang );
		}

		return null;
	}

	/**
	 * Determines whether the current request is the pattern catalog archive (and not a search query).
	 */
	private function is_pattern_catalog_archive(): bool {
		if ( \function_exists( 'is_search' ) && \is_search() ) {
			return false;
		}
		if ( \function_exists( 'is_shop' ) && \is_shop() ) {
			return true;
		}
		return \function_exists( 'is_post_type_archive' ) && \is_post_type_archive( 'product' );
	}

	/**
	 * Reads current pagination page number (`paged` or `page`), >= 1.
	 */
	private function current_page_number(): int {
		if ( ! \function_exists( 'get_query_var' ) ) {
			return 1;
		}
		return max( 1, (int) \get_query_var( 'paged' ), (int) \get_query_var( 'page' ) );
	}

	/**
	 * Checks whether a breadcrumb item represents the WooCommerce shop / patterns archive.
	 *
	 * @param array<int, mixed> $crumb Breadcrumb tuple `[label, url]`.
	 */
	private function is_patterns_archive_crumb( array $crumb ): bool {
		$url = isset( $crumb[1] ) && is_string( $crumb[1] ) ? trim( $crumb[1] ) : '';
		if ( '' !== $url ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			$path = \function_exists( 'wp_parse_url' ) ? \wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
			if ( is_string( $path ) && 1 === preg_match( '#^(?:/(?:en|zh|es|pt-br|de|fr|ar|id|vi))?/(?:patterns|shop)(?:/page/\d+)?/?$#i', $path ) ) {
				return true;
			}
		}

		$label = isset( $crumb[0] ) && is_string( $crumb[0] ) ? mb_strtolower( trim( $crumb[0] ) ) : '';
		return '' !== $label && in_array( $label, self::SHOP_CRUMB_LABELS, true );
	}

	/**
	 * Decodes residual single- or double-encoded HTML entities (e.g. `&#038;`, `&amp;#038;`, `&#8217;`)
	 * while keeping angle brackets safe.
	 *
	 * @param string $text Input title or label text.
	 */
	private static function decode_title_entities( string $text ): string {
		if ( '' === $text || ! str_contains( $text, '&' ) ) {
			return $text;
		}

		// Unwrap double-escaped entities produced when esc_attr() ran after wptexturize().
		$unwrapped = (string) preg_replace( '/&amp;(#\d+|#x[0-9a-fA-F]+|[a-zA-Z0-9]+);/', '&$1;', $text );
		$decoded   = html_entity_decode( $unwrapped, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		return str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $decoded );
	}

	/**
	 * Replaces `content="..."` when `$current` is an HTML `<meta>` tag, or returns plain `$replacement` otherwise.
	 *
	 * @param string $current     Filter input (HTML `<meta ...>` tag or plain string).
	 * @param string $replacement Unescaped localized text.
	 */
	private static function replace_meta_tag_or_plain( string $current, string $replacement ): string {
		if ( str_starts_with( ltrim( $current ), '<meta ' ) ) {
			$escaped = self::escape_meta_attr( $replacement );
			return (string) preg_replace( '/(content\s*=\s*")[^"]*(")/i', '$1' . addcslashes( $escaped, '\\$' ) . '$2', $current, 1 );
		}
		return $replacement;
	}

	/**
	 * Escapes a value for placement inside a double-quoted HTML `<meta content="...">` attribute.
	 *
	 * @param string $value Plain text value.
	 */
	private static function escape_meta_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The featured image followed by the gallery, in order, capped at
	 * six (spec 22 §3.3's image array).
	 *
	 * @param int                $post_id The product's ID.
	 * @param array<int, string> $current The builder's resolved image(s).
	 * @return array<int, string> The image URLs.
	 */
	private function images( int $post_id, array $current ): array {
		$images   = array();
		$featured = (string) get_the_post_thumbnail_url( $post_id, 'full' );
		if ( '' !== $featured ) {
			$images[] = $featured;
		} elseif ( isset( $current[0] ) && '' !== $current[0] ) {
			$images[] = $current[0];
		}
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( is_object( $product ) && method_exists( $product, 'get_gallery_image_ids' ) ) {
				foreach ( array_slice( $product->get_gallery_image_ids(), 0, 6 ) as $attachment_id ) {
					$url = (string) wp_get_attachment_url( (int) $attachment_id );
					if ( '' !== $url ) {
						$images[] = $url;
					}
				}
			}
		}
		return array_values( array_unique( $images ) );
	}

	/**
	 * The pattern's series name (the first product_cat term's display
	 * name, the same term the visible series archive link carries).
	 *
	 * @param int $post_id The product's ID.
	 * @return string The name, '' without a term.
	 */
	private function series_name( int $post_id ): string {
		$terms = wp_get_post_terms( $post_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || array() === $terms ) {
			return '';
		}
		return (string) $terms[0];
	}

	/**
	 * The ACF facts as PropertyValue entries; a field without a value
	 * (the sixteen "On request" open areas) contributes nothing.
	 *
	 * @param int $post_id The product's ID.
	 * @return array<int, array<string, string>> The entries.
	 */
	private function properties( int $post_id ): array {
		$current_lang = \MetalDivider\Site\I18n\Languages::current();
		$entries      = array();
		foreach ( self::PROPERTIES as $name => $label ) {
			$value = (string) get_post_meta( $post_id, $name, true );
			if ( '' === $value ) {
				continue;
			}
			$prop_name  = 'en' === $current_lang ? $label : \MetalDivider\Site\I18n\Dictionary::text( 'spec_' . $name, $current_lang );
			$prop_value = $value;
			if ( 'en' !== $current_lang ) {
				if ( 'best_fit' === $name ) {
					$prop_value = \MetalDivider\Site\I18n\Dictionary::text( 'best_fit.' . $value, $current_lang );
				} elseif ( 'visual_density' === $name ) {
					$prop_value = \MetalDivider\Site\I18n\Dictionary::text( 'visual_density.' . $value, $current_lang );
				} elseif ( 'repeat_behavior' === $name ) {
					$prop_value = \MetalDivider\Site\I18n\Dictionary::text( 'repeat_behavior.' . $value, $current_lang );
				}
			}
			$entries[] = array(
				'@type' => 'PropertyValue',
				'name'  => $prop_name,
				'value' => $prop_value,
			);
		}
		return $entries;
	}

	/**
	 * A site entity's @id, SEOPress's own helper when it is loaded
	 * (so a filter that moves the entity moves with it).
	 *
	 * @param string $entity The entity name (organization, website).
	 * @return string The absolute @id.
	 */
	private function entity_id( string $entity ): string {
		if ( \function_exists( 'has_filter' ) && \has_filter( 'wpml_current_language' ) ) {
			return \MetalDivider\Site\I18n\Languages::unfiltered_home_url( '/#' . $entity );
		}
		if ( class_exists( '\SEOPress\Helpers\SchemaEntityId' ) ) {
			return (string) \SEOPress\Helpers\SchemaEntityId::for_home( $entity );
		}
		return home_url( '/#' . $entity );
	}

	/**
	 * Emits localized FAQPage schema on non-default language translated /faqs/ pages.
	 */
	public function localized_faq_schema(): void {
		if ( ! \MetalDivider\Site\I18n\Languages::is_frontend_non_default() ) {
			return;
		}
		if ( ! \function_exists( 'is_page' ) || ! \is_page() ) {
			return;
		}
		$post_id = (int) \get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$en_id       = \MetalDivider\Site\I18n\Languages::post_translation( $post_id, 'en' );
		$source_id   = $en_id > 0 ? $en_id : $post_id;
		$source_post = \get_post( $source_id );
		if ( ! $source_post instanceof \WP_Post || 'faqs' !== $source_post->post_name ) {
			return;
		}

		$fallback_html = \MetalDivider\Site\Presentation\Templates::read(
			\MetalDivider\Site\Presentation\Templates::path( 'faqs' )
		);
		$current_lang  = \MetalDivider\Site\I18n\Languages::current();
		$html          = \MetalDivider\Site\I18n\TemplateStore::get_template( 'faqs', $fallback_html, $current_lang );
		$permalink     = (string) \get_permalink( $post_id );
		$json_ld       = FaqSchema::json_ld( $html, $permalink );
		if ( '' !== $json_ld ) {
			echo "\n" . $json_ld . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD output.
		}
	}
}
