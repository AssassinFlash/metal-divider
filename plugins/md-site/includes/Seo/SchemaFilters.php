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
		add_filter( 'seopress_titles_title', array( $this, 'title' ) );
		add_filter( 'seopress_pro_breadcrumbs_json_remove_home', '__return_false' );

		// The catalogue is a page listing 146 patterns, and no SEOPress builder
		// emits an ItemList for a page: its Product schema is per-product and
		// its CollectionPage is a WooCommerce archive, which /patterns/ is not (it
		// is page #6 with the default template). Without this block a search
		// engine sees a WebPage and no list at all.
		add_action( 'wp_head', array( $this, 'catalog_item_list' ), 99 );
	}

	/**
	 * The catalogue's ItemList: the patterns the page actually renders.
	 *
	 * Reads the same query the catalogue template uses so the list and the
	 * grid cannot disagree, and emits nothing on any other page. Item urls
	 * are the patterns' own permalinks; the order is the page's order.
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
			'name'            => 'Architectural metal screen patterns',
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
	 * The Organization's contact point (spec 22 §3.3): one sales point
	 * carrying the email, read from the Knowledge Graph field this plugin's
	 * config wrote, never a literal of its own.
	 *
	 * The WhatsApp point carrying the phone went with the channel itself
	 * (owner ruling 2026-10-30), so the phone is not published in the
	 * structured data either.
	 *
	 * @param array<string, mixed> $schema The Organization schema.
	 * @return array<string, mixed> The schema with the two points.
	 */
	public function organization( array $schema ): array {
		$email = '';
		if ( function_exists( 'seopress_get_service' ) ) {
			$social = seopress_get_service( 'SocialOption' );
			if ( is_object( $social ) && method_exists( $social, 'getSocialKnowledgeEmail' ) ) {
				$email = (string) $social->getSocialKnowledgeEmail();
			}
		}
		$points = array();
		if ( '' !== $email ) {
			$points[] = array(
				'@type'             => 'ContactPoint',
				'contactType'       => 'sales',
				'email'             => $email,
				'availableLanguage' => array( 'English' ),
			);
		}
		if ( array() !== $points ) {
			$schema['contactPoint'] = $points;
		}
		// The builder copies the phone into a top-level telephone as well;
		// the spec's skeleton carries it on a contact point only, and that
		// point is gone, so no telephone is published at all.
		unset( $schema['telephone'] );
		return $schema;
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
		$json['image']    = $this->images( $post_id, is_array( $json['image'] ?? null ) ? $json['image'] : array() );
		$json['material'] = self::MATERIAL;
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
			'name'   => 'Request a quote',
			'target' => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/inquiry/' ),
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
	 * The breadcrumb trails (spec 22 S4): the pixel-gate replica's
	 * own markup is the visible truth, and its trails are two-level —
	 * Home, then the page — so the JSON-LD carries the same shape.
	 * SEOPress's defaults would walk the shop base and the series
	 * term on pattern pages and the /pages shell on content pages.
	 *
	 * @param array<int, array<int, mixed>> $crumbs The crumbs.
	 * @return array<int, array<int, mixed>> The shaped crumbs.
	 */
	public function crumbs( array $crumbs ): array {
		if ( array() === $crumbs ) {
			return $crumbs;
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
	 * The final title's whitespace: a template carrying the pagination
	 * variable (spec 22 §2.2 T13) renders an empty page number on page
	 * one, leaving a doubled or trailing space; collapse and trim.
	 *
	 * @param string $title The assembled title.
	 * @return string The title, whitespace-normalized.
	 */
	public function title( string $title ): string {
		return trim( (string) preg_replace( '/ {2,}/u', ' ', $title ) );
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
		$entries = array();
		foreach ( self::PROPERTIES as $name => $label ) {
			$value = (string) get_post_meta( $post_id, $name, true );
			if ( '' === $value ) {
				continue;
			}
			$entries[] = array(
				'@type' => 'PropertyValue',
				'name'  => $label,
				'value' => $value,
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
		if ( class_exists( '\SEOPress\Helpers\SchemaEntityId' ) ) {
			return (string) \SEOPress\Helpers\SchemaEntityId::for_home( $entity );
		}
		return home_url( '/#' . $entity );
	}
}
