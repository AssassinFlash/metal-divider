<?php
/**
 * The WordPress side of tools/migrate/18-migrate.py: the seven migration
 * stages applied inside WordPress, per spec 18 (branch research/spec-migrator)
 * and the spec 06 §9 mapping table.
 *
 * 18-migrate.py hands this file to `wp eval-file -` with its request (JSON,
 * base64) in place of the placeholder argument at the end. WP-CLI evaluates
 * the file, and evaluated code cannot start with a strict_types declaration,
 * so this file has none. The answer is one JSON line after the request's
 * marker on stdout; a failure is an exception, which WP-CLI reports with a
 * non-zero exit status.
 *
 * Write channels only (spec 18 §6): Woo CRUD, WP core, ACF and SEOPress
 * APIs — no native SQL writes. Reads may query the tables directly where
 * the API has no batch form (the reconcile scans and the _md_source_url
 * lookup).
 *
 * @package Md\Migrate
 */

defined( 'ABSPATH' ) || exit;

( static function ( string $payload ): void {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64 lets any request JSON sit in the single-quoted literal 18-migrate.py writes into this file.
	$request = json_decode( base64_decode( $payload ), true, 512, JSON_THROW_ON_ERROR );

	$fail = static function ( string $message ): void {
		throw new RuntimeException( esc_html( $message ) );
	};

	/** The product's ACF value as the plan compares it (missing -> null, empty -> ''). */
	$acf_value = static function ( $id, string $name ) {
		$value = get_field( $name, $id );
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( is_bool( $value ) || null === $value ) {
			return null;
		}
		if ( is_numeric( $value ) && ! is_string( $value ) ) {
			return (float) $value;
		}
		return (string) $value;
	};

	$product_read = static function ( WC_Product $product ) use ( $acf_value ): array {
		$acf = array();
		foreach (
			array(
				'open_area',
				'visual_density',
				'repeat_behavior',
				'motifs',
				'best_fit',
				'cad_status',
				'summary',
				'inspiration',
				'source_site',
				'source_slug',
				'source_manifest',
				'openness_inferred_from',
				'openness_confidence',
			) as $name
		) {
			$acf[ $name ] = $acf_value( $product->get_id(), $name );
		}
		$terms = wp_get_object_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );

		return array(
			'id'                 => $product->get_id(),
			'title'              => $product->get_name(),
			'slug'               => $product->get_slug(),
			'status'             => $product->get_status(),
			'content'            => $product->get_description(),
			'excerpt'            => $product->get_short_description(),
			'series'             => is_array( $terms ) && $terms ? (string) $terms[0] : null,
			'virtual'            => $product->get_virtual(),
			'stock_status'       => $product->get_stock_status(),
			'manage_stock'       => $product->get_manage_stock(),
			'regular_price'      => $product->get_regular_price(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'tax_status'         => $product->get_tax_status(),
			'reviews_allowed'    => $product->get_reviews_allowed(),
			'acf'                => $acf,
			'seo'                => array(
				'title'       => (string) get_post_meta( $product->get_id(), '_seopress_titles_title', true ),
				'description' => (string) get_post_meta( $product->get_id(), '_seopress_titles_desc', true ),
			),
		);
	};

	/** One attachment row by _md_source_url, the plan's natural key. */
	$attachment_row = static function ( int $id ): array {
		$variants = json_decode( (string) get_post_meta( $id, '_md_variants', true ), true );

		return array(
			'id'       => $id,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'role'     => (string) get_post_meta( $id, '_md_role', true ),
			'sha256'   => (string) get_post_meta( $id, '_md_sha256', true ),
			'variants' => is_array( $variants ) ? $variants : array(),
		);
	};

	/** The _md_source_url -> attachment map for the sources asked about. */
	$attachments_by_source = static function ( array $sources ) use ( $attachment_row ): array {
		global $wpdb;
		$map = array();
		if ( ! $sources ) {
			return $map;
		}
		// Every row of this key is one of ours: read them all once, then
		// keep the asked-about ones (the API has no batch form).
		$rows    = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_md_source_url'",
			ARRAY_A
		);
		$sources = array_fill_keys( $sources, true );
		foreach ( (array) $rows as $row ) {
			$source = (string) ( $row['meta_value'] ?? '' );
			if ( isset( $sources[ $source ] ) ) {
				$map[ $source ] = $attachment_row( (int) $row['post_id'] );
			}
		}

		return $map;
	};

	/** One media entry placed into the media library: the top tier is the
	 * attachment file, the other tiers ride along as sibling files (spec 15
	 * §3.2/§7: no subsize generation, no -scaled copy, one upload per
	 * source).
	 *
	 * Two binding modes: with attach_uploads (remote mode — the plan was
	 * built with --wp) the file already stands in this instance's uploads,
	 * placed there by the deployer that mirrors the dev library: no copy,
	 * the entry binds that file, and the variants bind by attach_uploads_
	 * variants — the names the dev import recorded, not the sidecar's
	 * (a later media rebuild renamed the sidecar tiers after the dev
	 * import) — each checked for presence, a missing one named and refused.
	 * Without attach_uploads (local mode) the entry's attach is a local
	 * media-final path that gets copied in under the upload dir of this
	 * request, its siblings named as the sidecar says. */
	$import_media = static function ( array $entry, string $source_url ) use ( $fail ): int {
		$upload   = wp_upload_dir();
		$remote   = ! empty( $entry['attach_uploads'] );
		$variants = array();
		if ( $remote ) {
			$relative = (string) $entry['attach_uploads'];
			$file     = $upload['basedir'] . '/' . ltrim( $relative, '/' );
			if ( ! is_file( $file ) ) {
				$fail( "missing on target: {$relative} ({$source_url})" );
			}
			// wp_insert_attachment records _wp_attached_file as the
			// uploads-relative path of $file itself — exactly the dev value
			// the map was exported from.
			$dir = dirname( $file );
			$top = wp_basename( $file );
			foreach ( (array) ( $entry['attach_uploads_variants'] ?? array() ) as $tier ) {
				$tier_name = (string) $tier['f'];
				if ( $tier_name === $top ) {
					$variants[] = array(
						'w' => (int) $tier['w'],
						'f' => $top,
					);
					continue;
				}
				$sibling = $dir . '/' . $tier_name;
				if ( ! is_file( $sibling ) ) {
					$fail( "missing variant on target: {$tier_name} (w=" . (int) $tier['w'] . ") of {$source_url}" );
				}
				$variants[] = array(
					'w' => (int) $tier['w'],
					'f' => $tier_name,
				);
			}
		} else {
			$path = (string) $entry['attach'];
			if ( ! is_file( $path ) ) {
				$fail( "missing final: {$path}" );
			}
			$dir = $upload['path'];
			if ( ! wp_mkdir_p( $dir ) ) {
				$fail( "cannot create {$dir}" );
			}
			$name     = sanitize_file_name( wp_basename( $path ) );
			$filename = wp_unique_filename( $dir, $name );
			$file     = $dir . '/' . $filename;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- the finals are this pipeline's own local files, not uploads from a request.
			if ( ! copy( $path, $file ) ) {
				$fail( "cannot copy {$path} to {$file}" );
			}
			// The sibling tiers: plain files beside the attachment, never
			// attachments themselves, named as the sidecar says (uniquified
			// only on a real collision).
			$top = wp_basename( $path );
			foreach ( (array) ( $entry['variants'] ?? array() ) as $tier ) {
				if ( (string) $tier['file'] === $top ) {
					$variants[] = array(
						'w' => (int) $tier['w'],
						'f' => $filename,
					);
					continue;
				}
				$tier_name = sanitize_file_name( wp_basename( (string) $tier['path'] ) );
				$sibling   = $dir . '/' . wp_unique_filename( $dir, $tier_name );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- same as above.
				if ( ! copy( (string) $tier['path'], $sibling ) ) {
					$fail( "cannot copy tier {$tier['path']}" );
				}
				$variants[] = array(
					'w' => (int) $tier['w'],
					'f' => wp_basename( $sibling ),
				);
			}
		}
		$filename = wp_basename( $file );
		$check    = wp_check_filetype( $filename );
		$post_id  = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'] ? $check['type'] : 'application/octet-stream',
				'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file,
			0,
			true
		);
		if ( is_wp_error( $post_id ) ) {
			$fail( 'wp_insert_attachment: ' . $post_id->get_error_message() );
		}
		$metadata = wp_generate_attachment_metadata( $post_id, $file );
		wp_update_attachment_metadata( $post_id, $metadata );
		update_post_meta( $post_id, '_md_source_url', wp_slash( $source_url ) );
		update_post_meta( $post_id, '_md_role', wp_slash( (string) ( $entry['role'] ?? 'unknown' ) ) );
		update_post_meta( $post_id, '_md_sha256', wp_slash( (string) $entry['sha256'] ) );
		update_post_meta( $post_id, '_md_variants', wp_slash( (string) wp_json_encode( $variants ) ) );
		if ( ! empty( $entry['alt'] ) ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', wp_slash( (string) $entry['alt'] ) );
		}

		return (int) $post_id;
	};

	// Zero subsize generation and no -scaled copies: the finals are final
	// (spec 15 §7, the two filters the pipeline's spec pins).
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
	add_filter( 'big_image_size_threshold', '__return_false' );

	$term_id_of = static function ( string $slug ) use ( $fail ): int {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $term ) {
			$fail( "no product_cat term {$slug} (run the terms stage first)" );
		}

		return (int) $term->term_id;
	};

	$preflight = static function () use ( $request ): array {
		$acf = array();
		foreach ( (array) ( $request['product_fields'] ?? array() ) as $name ) {
			$acf[ $name ] = (bool) acf_get_field( $name );
		}
		foreach ( (array) ( $request['term_fields'] ?? array() ) as $name ) {
			$acf[ $name ] = (bool) acf_get_field( $name );
		}

		return array(
			'plugins'      => (object) array(
				'woocommerce'            => is_plugin_active( 'woocommerce/woocommerce.php' ),
				'advanced-custom-fields' => is_plugin_active( 'advanced-custom-fields/acf.php' ),
				'wp-seopress'            => is_plugin_active( 'wp-seopress/seopress.php' ),
				'wp-seopress-pro'        => is_plugin_active( 'wp-seopress-pro/seopress-pro.php' ),
				'md-site'                => is_plugin_active( 'md-site/md-site.php' ),
			),
			'acf_fields'   => (object) $acf,
			'seopress_404' => post_type_exists( 'seopress_404' ),
		);
	};

	$terms_read = static function () use ( $request ): array {
		$terms = array();
		foreach ( (array) $request['slugs'] as $slug ) {
			$term = get_term_by( 'slug', (string) $slug, 'product_cat' );
			if ( ! $term ) {
				$terms[ $slug ] = null;
				continue;
			}
			$meta = array();
			foreach ( array( 'accent_color', 'difficulty_level', 'typical_applications', 'bottom_content' ) as $name ) {
				// Raw storage, not get_field: the wysiwyg field formats its
				// value through acf_the_content outside the admin, which
				// would make every read differ from the plan's verbatim
				// export. The stored value is exactly what update_field
				// wrote, so this is the honest comparison.
				$value         = get_term_meta( $term->term_id, $name, true );
				$meta[ $name ] = is_string( $value ) ? $value : (string) $value;
			}
			$terms[ $slug ] = array(
				'name' => $term->name,
				'meta' => $meta,
			);
		}
		$default = get_term_by( 'slug', 'uncategorized', 'product_cat' );
		$member  = array();
		$all     = array();
		foreach ( (array) get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		) as $term ) {
			$all[]                 = $term->slug;
			$member[ $term->slug ] = (int) $term->count;
		}

		return array(
			'terms'         => (object) $terms,
			'all_slugs'     => $all,
			'member_counts' => (object) $member,
			'default_term'  => $default ? array( 'count' => (int) $default->count ) : null,
		);
	};

	$terms_write = static function () use ( $request, $fail ): array {
		$results = array();
		$errors  = array();
		foreach ( (array) $request['items'] as $item ) {
			$slug = (string) $item['slug'];
			try {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( ! empty( $item['create'] ) ) {
					if ( $term ) {
						$fail( "term {$slug} already exists" );
					}
					$made = wp_insert_term( (string) $item['name'], 'product_cat', array( 'slug' => $slug ) );
					if ( is_wp_error( $made ) ) {
						$fail( "wp_insert_term {$slug}: " . $made->get_error_message() );
					}
					$term             = get_term( (int) $made['term_id'], 'product_cat' );
					$results[ $slug ] = 'created';
				} elseif ( ! empty( $item['name_changed'] ) ) {
					if ( ! $term ) {
						$fail( "no term {$slug} to update" );
					}
					$done = wp_update_term( $term->term_id, 'product_cat', array( 'name' => (string) $item['name'] ) );
					if ( is_wp_error( $done ) ) {
						$fail( "wp_update_term {$slug}: " . $done->get_error_message() );
					}
				}
				if ( ! $term instanceof WP_Term ) {
					$fail( "no term {$slug} to write meta to" );
				}
				foreach ( (array) ( $item['meta_set'] ?? array() ) as $name => $value ) {
					update_field( $name, $value, 'product_cat_' . $term->term_id );
				}
				if ( 'created' !== ( $results[ $slug ] ?? '' ) ) {
					$results[ $slug ] = 'updated';
				}
			} catch ( Throwable $e ) {
				$results[ $slug ] = 'error';
				$errors[ $slug ]  = $e->getMessage();
			}
		}
		$removed = false;
		if ( ! empty( $request['remove_default_term'] ) ) {
			$default = get_term_by( 'slug', 'uncategorized', 'product_cat' );
			if ( $default && 0 === (int) $default->count ) {
				wp_delete_term( $default->term_id, 'product_cat' );
				$removed = true;
			} elseif ( $default ) {
				$fail( 'uncategorized is in use (' . $default->count . ' products); refusing to delete' );
			}
		}

		return array(
			'results'              => (object) $results,
			'errors'               => (object) $errors,
			'default_term_removed' => $removed,
		);
	};

	$products_read = static function () use ( $request, $product_read ): array {
		$products = array();
		foreach ( (array) $request['skus'] as $sku ) {
			$id               = (int) wc_get_product_id_by_sku( (string) $sku );
			$product          = $id ? wc_get_product( $id ) : null;
			$products[ $sku ] = $product ? $product_read( $product ) : null;
		}
		// Products are not hierarchical: the path is the slug.
		$by_slug = array();
		foreach ( (array) $request['slugs'] as $slug ) {
			$post = get_page_by_path( (string) $slug, OBJECT, 'product' );
			if ( $post ) {
				$by_slug[ $slug ] = (int) $post->ID;
			}
		}

		return array(
			'siteurl'  => (string) get_option( 'siteurl' ),
			'products' => (object) $products,
			'by_slug'  => (object) $by_slug,
		);
	};

	$settings_read = static function () use ( $request, $fail ): array {
		$settings   = $request['settings'];
		$permalinks = get_option( 'woocommerce_permalinks', array() );
		$remove     = array();
		// Only Woo's own untouched shells go. The store options name the
		// pages Woo itself installed, and their install content carries no
		// prose of the site's own — block markup, shortcodes and at most a
		// short default heading ("Shopping cart"): with the tags, block
		// comments and shortcodes gone, nothing but whitespace and that
		// heading remains. Anything longer is a page for a person to look
		// at, not this migration.
		$woo_pages = array(
			'cart'       => 'woocommerce_cart_page_id',
			'checkout'   => 'woocommerce_checkout_page_id',
			'my-account' => 'woocommerce_myaccount_page_id',
		);
		foreach ( (array) $settings['remove_page_slugs'] as $slug ) {
			$page  = get_page_by_path( (string) $slug, OBJECT, 'page' );
			$shell = false;
			if ( $page && (int) get_option( (string) $woo_pages[ $slug ] ) === (int) $page->ID ) {
				$text  = preg_replace(
					'#<!--.*?-->|\[woocommerce_[a-z_]+\]#s',
					'',
					(string) $page->post_content
				);
				$text  = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
				$shell = '' === $text || strlen( $text ) <= 40;
			}
			if ( $page && $shell ) {
				$remove[] = array(
					'id'    => (int) $page->ID,
					'slug'  => $slug,
					'title' => $page->post_title,
				);
			} elseif ( $page ) {
				$fail( "page {$slug} holds content of its own; refusing the checkout-surface removal" );
			}
		}

		return array(
			'permalinks' => array(
				'product_base'  => (string) ( $permalinks['product_base'] ?? '' ),
				'category_base' => (string) ( $permalinks['category_base'] ?? '' ),
			),
			'remove'     => $remove,
			'detail'     => 'product_base=' . ( $permalinks['product_base'] ?? '' )
				. ' category_base=' . ( $permalinks['category_base'] ?? '' )
				. ( $remove ? '; checkout pages to remove: ' . implode( ', ', wp_list_pluck( $remove, 'slug' ) ) : '' ),
		);
	};

	$settings_write = static function () use ( $request ): array {
		$settings                    = $request['settings'];
		$permalinks                  = get_option( 'woocommerce_permalinks', array() );
		$permalinks                  = is_array( $permalinks ) ? $permalinks : array();
		$permalinks['product_base']  = (string) $settings['product_base'];
		$permalinks['category_base'] = (string) $settings['category_base'];
		update_option( 'woocommerce_permalinks', $permalinks );
		// The shop page stays (it is the /shop/ of L18); the checkout
		// surface goes: cart, checkout and my-account, the three pages Woo
		// installs with no content of their own.
		foreach ( array( 'cart_page_id', 'checkout_page_id', 'myaccount_page_id' ) as $option ) {
			delete_option( 'woocommerce_' . $option );
		}
		foreach ( (array) $request['remove'] as $page ) {
			wp_delete_post( (int) $page['id'], true );
		}

		return array( 'done' => true );
	};

	$flush = static function (): array {
		// A flush in the same request that updated woocommerce_permalinks
		// regenerates the rules from the taxonomies registered with the OLD
		// bases, so the new base never lands: this runs in its own request,
		// after WooCommerce has registered product_cat with the new option.
		flush_rewrite_rules();

		return array( 'done' => true );
	};

	$products_write = static function () use ( $request, $fail, $term_id_of ): array {
		$results = array();
		$errors  = array();
		foreach ( (array) $request['items'] as $change ) {
			$sku = (string) $change['sku'];
			$set = (array) $change['set'];
			try {
				$id      = (int) wc_get_product_id_by_sku( $sku );
				$product = $id ? wc_get_product( $id ) : null;
				if ( ! empty( $set['create'] ) ) {
					if ( $product ) {
						$fail( "product {$sku} exists although the plan said create" );
					}
					$product = new WC_Product_Simple();
					$product->set_sku( $sku );
				}
				if ( ! $product instanceof WC_Product ) {
					$fail( "no product with SKU {$sku}" );
				}
				if ( isset( $set['title'] ) ) {
					$product->set_name( (string) $set['title'] );
				}
				if ( isset( $set['slug'] ) ) {
					$product->set_slug( (string) $set['slug'] );
				}
				if ( isset( $set['status'] ) ) {
					$product->set_status( (string) $set['status'] );
				}
				if ( isset( $set['content'] ) ) {
					$product->set_description( (string) $set['content'] );
				}
				if ( isset( $set['excerpt'] ) ) {
					$product->set_short_description( (string) $set['excerpt'] );
				}
				if ( isset( $set['series'] ) ) {
					$product->set_category_ids( array( $term_id_of( (string) $set['series'] ) ) );
				}
				if ( isset( $set['virtual'] ) ) {
					$product->set_virtual( (bool) $set['virtual'] );
				}
				if ( isset( $set['stock_status'] ) ) {
					$product->set_stock_status( (string) $set['stock_status'] );
				}
				if ( isset( $set['manage_stock'] ) ) {
					$product->set_manage_stock( (bool) $set['manage_stock'] );
				}
				if ( isset( $set['regular_price'] ) ) {
					// The empty price is the point (spec 06 §7): an empty
					// string, never 0, never anything else.
					$product->set_regular_price( '' );
					$product->set_sale_price( '' );
					$product->set_price( '' );
				}
				if ( isset( $set['catalog_visibility'] ) ) {
					$product->set_catalog_visibility( (string) $set['catalog_visibility'] );
				}
				if ( isset( $set['tax_status'] ) ) {
					$product->set_tax_status( (string) $set['tax_status'] );
				}
				if ( isset( $set['reviews_allowed'] ) ) {
					$product->set_reviews_allowed( (bool) $set['reviews_allowed'] );
				}
				$product->save();
				$post_id = $product->get_id();
				foreach ( (array) ( $set['acf_set'] ?? array() ) as $name => $value ) {
					if ( 'motifs' === $name ) {
						$value = array_values( (array) $value );
					}
					update_field( $name, $value, $post_id );
				}
				foreach ( (array) ( $set['acf_delete'] ?? array() ) as $name ) {
					delete_field( $name, $post_id );
				}
				if ( isset( $set['seo_title'] ) ) {
					update_post_meta( $post_id, '_seopress_titles_title', wp_slash( (string) $set['seo_title'] ) );
				}
				if ( isset( $set['seo_description'] ) ) {
					update_post_meta( $post_id, '_seopress_titles_desc', wp_slash( (string) $set['seo_description'] ) );
				}
				$results[ $sku ] = empty( $set['create'] ) ? 'updated' : 'created';
			} catch ( Throwable $e ) {
				$results[ $sku ] = 'error';
				$errors[ $sku ]  = $e->getMessage();
			}
		}

		return array(
			'results' => (object) $results,
			'errors'  => (object) $errors,
		);
	};

	$media_read = static function () use ( $request, $attachments_by_source ): array {
		$sources = array();
		foreach ( (array) $request['products'] as $asked ) {
			foreach ( (array) $asked['master_srcs'] as $source ) {
				$sources[] = (string) $source;
			}
		}
		$known     = $attachments_by_source( $sources );
		$src_of_id = array();
		foreach ( $known as $source => $row ) {
			$src_of_id[ (int) $row['id'] ] = (string) $source;
		}
		$out = array();
		foreach ( (array) $request['products'] as $asked ) {
			$id = (int) wc_get_product_id_by_sku( (string) $asked['sku'] );
			if ( ! $id ) {
				// A dry run against a fresh instance reads the honest
				// answer: everything is still to import.
				$out[ $asked['handle'] ] = null;
				continue;
			}
			$thumbnail               = (int) get_post_meta( $id, '_thumbnail_id', true );
			$gallery                 = array_map( 'intval', array_filter( explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) );
			$src_of                  = static function ( int $attachment ) use ( $src_of_id ): ?string {
				return $src_of_id[ $attachment ] ?? null;
			};
			$out[ $asked['handle'] ] = array(
				'attachments' => (object) $known,
				'thumbnail'   => $thumbnail ? ( $src_of_id[ $thumbnail ] ?? null ) : null,
				'gallery'     => array_values( array_filter( array_map( $src_of, $gallery ) ) ),
			);
		}

		return array( 'products' => (object) $out );
	};

	$media_write = static function () use ( $request, $import_media, $attachments_by_source, $fail ): array {
		$change = $request['product'];
		try {
			$id = (int) wc_get_product_id_by_sku( (string) $change['sku'] );
			if ( ! $id ) {
				$fail( "no product with SKU {$change['sku']}" );
			}
			$imported = 0;
			foreach ( (array) $change['import'] as $entry ) {
				$import_media( $entry, (string) $entry['master_src'] );
				$imported++;
			}
			$sources = array();
			foreach ( (array) $change['media'] as $entry ) {
				$sources[] = (string) $entry['master_src'];
			}
			$known = $attachments_by_source( $sources );
			foreach ( (array) $change['fill'] as $fill ) {
				$row = $known[ (string) $fill['master_src'] ] ?? null;
				if ( ! $row ) {
					$fail( "fill for an absent attachment {$fill['master_src']}" );
				}
				if ( isset( $fill['role'] ) && '' === $row['role'] ) {
					update_post_meta( $row['id'], '_md_role', wp_slash( (string) $fill['role'] ) );
				}
				if ( isset( $fill['sha256'] ) && '' === $row['sha256'] ) {
					update_post_meta( $row['id'], '_md_sha256', wp_slash( (string) $fill['sha256'] ) );
				}
				if ( isset( $fill['variants'] ) && ! $row['variants'] ) {
					update_post_meta( $row['id'], '_md_variants', wp_slash( (string) wp_json_encode( $fill['variants'] ) ) );
				}
			}
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product ) {
				$fail( "no product with SKU {$change['sku']}" );
			}
			$bind    = (array) ( $change['bind'] ?? array() );
			$touched = false;
			if ( isset( $bind['thumbnail'] ) ) {
				$product->set_image_id( (int) $known[ $bind['thumbnail'] ]['id'] );
				$touched = true;
			}
			if ( isset( $bind['gallery'] ) ) {
				$ids = array();
				foreach ( (array) $bind['gallery'] as $source ) {
					$ids[] = (int) $known[ $source ]['id'];
				}
				$product->set_gallery_image_ids( $ids );
				$touched = true;
			}
			if ( $touched ) {
				$product->save();
			}
			$status = $imported ? 'created' : ( ( $change['fill'] || $bind ) ? 'updated' : 'identical' );

			return array(
				'status'   => $status,
				'imported' => $imported,
			);
		} catch ( Throwable $e ) {
			return array(
				'status'   => 'error',
				'error'    => $e->getMessage(),
				'imported' => 0,
			);
		}
	};

	$page_media_write = static function () use ( $request, $import_media ): array {
		$results = array();
		$errors  = array();
		global $wpdb;
		foreach ( (array) $request['items'] as $entry ) {
			$source_url = (string) $entry['source_url'];
			try {
				$found = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_md_source_url' AND meta_value = %s LIMIT 1",
						$source_url
					)
				);
				if ( $found ) {
					$results[ $source_url ] = 'present';
					continue;
				}
				// The bytes may already be in the library under a product's
				// own source URL (the shared files of spec 15 §3.2): reuse
				// that attachment, the page's URL joins it as an extra
				// source instead of a second copy.
				$shared = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_md_sha256' AND meta_value = %s LIMIT 1",
						(string) $entry['sha256']
					)
				);
				if ( $shared ) {
					$extras   = json_decode( (string) get_post_meta( (int) $shared->post_id, '_md_extra_sources', true ), true );
					$extras   = is_array( $extras ) ? $extras : array();
					$extras[] = $source_url;
					update_post_meta( (int) $shared->post_id, '_md_extra_sources', wp_slash( (string) wp_json_encode( array_values( array_unique( $extras ) ) ) ) );
					$results[ $source_url ] = 'reused';
					continue;
				}
				$import_media( $entry, $source_url );
				$results[ $source_url ] = 'created';
			} catch ( Throwable $e ) {
				$results[ $source_url ] = 'error';
				$errors[ $source_url ]  = $e->getMessage();
			}
		}

		return array(
			'results' => (object) $results,
			'errors'  => (object) $errors,
		);
	};

	$pages_read = static function () use ( $request, $attachment_row ): array {
		$pages = array();
		foreach ( (array) $request['slugs'] as $slug ) {
			// The content pages are children of the 'pages' shell, so the
			// path is pages/<slug> (that parent is what keeps the old URLs).
			$post = get_page_by_path( 'pages/' . $slug, OBJECT, 'page' );
			if ( ! $post ) {
				$pages[ $slug ] = null;
				continue;
			}
			$pages[ $slug ] = array(
				'id'          => (int) $post->ID,
				'title'       => $post->post_title,
				'status'      => $post->post_status,
				'content'     => (string) $post->post_content,
				// null when the row is absent, '' when it is present and
				// empty: the difference keeps a second apply at zero writes.
				'source_body' => metadata_exists( 'post', $post->ID, '_md_source_body' )
					? (string) get_post_meta( $post->ID, '_md_source_body', true )
					: null,
			);
		}
		$parent      = get_page_by_path( (string) $request['parent'], OBJECT, 'page' );
		$attachments = array();
		$by_sha      = array();
		global $wpdb;
		foreach ( (array) ( $request['media'] ?? array() ) as $asked ) {
			$url = (string) $asked['source_url'];
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_md_source_url' AND meta_value = %s LIMIT 1",
					$url
				)
			);
			if ( $row ) {
				$attachments[ $url ] = $attachment_row( (int) $row->post_id );
			}
			$sha = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_md_sha256' AND meta_value = %s LIMIT 1",
					(string) $asked['sha256']
				)
			);
			if ( $sha ) {
				$by_sha[ (string) $asked['sha256'] ] = (int) $sha->post_id;
			}
		}

		return array(
			'pages'       => (object) $pages,
			'parent'      => $parent ? array( 'id' => (int) $parent->ID ) : null,
			'attachments' => (object) $attachments,
			'by_sha256'   => (object) $by_sha,
		);
	};

	$pages_write = static function () use ( $request, $fail ): array {
		$results = array();
		$errors  = array();
		$parent  = null;
		if ( ! empty( $request['parent'] ) ) {
			$made = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_name'    => (string) $request['parent']['slug'],
					'post_title'   => (string) $request['parent']['title'],
					'post_status'  => (string) $request['parent']['status'],
					'post_content' => (string) $request['parent']['content'],
				),
				true
			);
			if ( is_wp_error( $made ) ) {
				$fail( 'parent page: ' . $made->get_error_message() );
			}
			$parent = (int) $made;
		} else {
			$shell  = get_page_by_path( 'pages', OBJECT, 'page' );
			$parent = $shell ? (int) $shell->ID : $fail( 'no parent pages shell' );
		}
		foreach ( (array) $request['items'] as $change ) {
			$slug = (string) $change['item']['slug'];
			$set  = (array) $change['set'];
			try {
				$post = get_page_by_path( 'pages/' . $slug, OBJECT, 'page' );
				$args = array();
				if ( ! empty( $set['create'] ) ) {
					if ( $post ) {
						$fail( "page {$slug} exists although the plan said create" );
					}
					$args['post_type']   = 'page';
					$args['post_name']   = $slug;
					$args['post_parent'] = $parent;
					$args['post_status'] = (string) ( $set['status'] ?? 'publish' );
				} elseif ( $post ) {
					$args['ID'] = (int) $post->ID;
				} else {
					$fail( "no page {$slug} to update" );
				}
				if ( isset( $set['title'] ) ) {
					$args['post_title'] = (string) $set['title'];
				}
				if ( isset( $set['status'] ) ) {
					$args['post_status'] = (string) $set['status'];
				}
				if ( isset( $set['content'] ) ) {
					$args['post_content'] = (string) $set['content'];
				}
				$made = wp_insert_post( $args, true );
				if ( is_wp_error( $made ) ) {
					$fail( "page {$slug}: " . $made->get_error_message() );
				}
				if ( isset( $set['source_body'] ) ) {
					update_post_meta( (int) $made, '_md_source_body', wp_slash( (string) $set['source_body'] ) );
				}
				$results[ $slug ] = empty( $set['create'] ) ? 'updated' : 'created';
			} catch ( Throwable $e ) {
				$results[ $slug ] = 'error';
				$errors[ $slug ]  = $e->getMessage();
			}
		}

		return array(
			'results' => (object) $results,
			'errors'  => (object) $errors,
		);
	};

	$redirects_read = static function () use ( $request ): array {
		$wanted = array_fill_keys( (array) $request['sources'], true );
		$posts  = get_posts(
			array(
				'post_type'      => 'seopress_404',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		$map    = array();
		foreach ( $posts as $post ) {
			if ( ! isset( $wanted[ $post->post_title ] ) ) {
				continue;
			}
			$map[ $post->post_title ] = array(
				'enabled'       => (string) get_post_meta( $post->ID, '_seopress_redirections_enabled', true ),
				'type'          => (string) get_post_meta( $post->ID, '_seopress_redirections_type', true ),
				'value'         => (string) get_post_meta( $post->ID, '_seopress_redirections_value', true ),
				'logged_status' => (string) get_post_meta( $post->ID, '_seopress_redirections_logged_status', true ),
				'regex'         => (string) get_post_meta( $post->ID, '_seopress_redirections_enabled_regex', true ),
			);
		}

		return array( 'redirects' => (object) $map );
	};

	$redirects_write = static function () use ( $request, $fail ): array {
		$meta_of = array(
			'enabled'       => '_seopress_redirections_enabled',
			'type'          => '_seopress_redirections_type',
			'value'         => '_seopress_redirections_value',
			'logged_status' => '_seopress_redirections_logged_status',
			'regex'         => '_seopress_redirections_enabled_regex',
		);
		$results = array();
		$errors  = array();
		foreach ( (array) $request['items'] as $item ) {
			$source = (string) $item['source'];
			try {
				$post = get_posts(
					array(
						'post_type'      => 'seopress_404',
						'post_status'    => 'any',
						'title'          => $source,
						'posts_per_page' => 1,
					)
				);
				$post = $post[0] ?? null;
				if ( ! empty( $item['create'] ) ) {
					if ( $post ) {
						$fail( "redirect {$source} exists although the plan said create" );
					}
					$made = wp_insert_post(
						array(
							'post_type'   => 'seopress_404',
							'post_status' => 'publish',
							'post_title'  => $source,
						),
						true
					);
					if ( is_wp_error( $made ) ) {
						$fail( "redirect {$source}: " . $made->get_error_message() );
					}
					$post_id            = (int) $made;
					$results[ $source ] = 'created';
				} elseif ( $post ) {
					$post_id            = (int) $post->ID;
					$results[ $source ] = 'updated';
				} else {
					$fail( "no redirect {$source} to update" );
				}
				foreach ( (array) $item['set'] as $key => $value ) {
					update_post_meta( $post_id, $meta_of[ $key ], wp_slash( (string) $value ) );
				}
			} catch ( Throwable $e ) {
				$results[ $source ] = 'error';
				$errors[ $source ]  = $e->getMessage();
			}
		}

		return array(
			'results' => (object) $results,
			'errors'  => (object) $errors,
		);
	};

	$reconcile_dropped = static function () use ( $request ): array {
		global $wpdb;
		$rows = array();
		foreach ( (array) $request['keys'] as $key ) {
			$key_rows = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", (string) $key )
			);
			if ( $key_rows ) {
				$rows[] = "meta_key {$key}: {$key_rows}";
			}
			// The value scan exempts the provenance field whose job is to
			// carry the old import manifest verbatim: its JSON names
			// reference_pdf (146/146, measured), which is the record of the
			// dropped key, not a residue of it.
			$value_rows = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key <> 'source_manifest'",
					'%' . $wpdb->esc_like( (string) $key ) . '%'
				)
			);
			if ( $value_rows ) {
				$rows[] = "meta_value ~ {$key}: {$value_rows}";
			}
		}

		return array(
			'hits' => count( $rows ),
			'rows' => $rows,
		);
	};

	$reconcile_purchasable = static function () use ( $request ): array {
		$results = array();
		$prices  = array();
		foreach ( (array) $request['skus'] as $sku ) {
			$id      = (int) wc_get_product_id_by_sku( (string) $sku );
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				$results[ $sku ] = null;
				continue;
			}
			$results[ $sku ] = (bool) $product->is_purchasable();
			$prices[ $sku ]  = (string) get_post_meta( $id, '_regular_price', true );
		}

		return array(
			'results' => (object) $results,
			'prices'  => (object) $prices,
		);
	};

	$reconcile_cdn = static function () use ( $request ): array {
		global $wpdb;
		$needle = (string) $request['needle'];
		$like   = '%' . $wpdb->esc_like( $needle ) . '%';
		$posts  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE (post_content LIKE %s OR post_excerpt LIKE %s) AND post_type IN ('product', 'page', 'post', 'attachment')",
				$like,
				$like
			)
		);
		// The provenance keys hold the old CDN URLs by design: the natural
		// keys of the attachments (spec 18 §3), not an unwritten rewrite.
		$postmeta = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key NOT IN ('_md_source_url', '_md_extra_sources')",
				$like
			)
		);
		$examples = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key NOT IN ('_md_source_url', '_md_extra_sources') LIMIT 10",
				$like
			)
		);

		return array(
			'posts'    => $posts,
			'postmeta' => $postmeta,
			'examples' => $examples,
		);
	};

	$answer = match ( $request['stage'] . '/' . $request['action'] ) {
		'preflight/read' => $preflight(),
		'terms/read' => $terms_read(),
		'terms/write' => $terms_write(),
		'products/read' => $products_read(),
		'products/read-settings' => $settings_read(),
		'products/write-settings' => $settings_write(),
		'products/flush' => $flush(),
		'products/write' => $products_write(),
		'media/read' => $media_read(),
		'media/write' => $media_write(),
		'media/write-page-media' => $page_media_write(),
		'pages/read' => $pages_read(),
		'pages/write' => $pages_write(),
		'redirects/read' => $redirects_read(),
		'redirects/write' => $redirects_write(),
		'reconcile/dropped-residue' => $reconcile_dropped(),
		'reconcile/purchasable' => $reconcile_purchasable(),
		'reconcile/cdn-residue' => $reconcile_cdn(),
		default => $fail( 'unknown stage/action: ' . $request['stage'] . '/' . $request['action'] ),
	};
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for 18-migrate.py on the command line, not HTML.
	echo "\n" . $request['marker'] . wp_json_encode( $answer ) . "\n";
} )( 'md-migrate-payload' );
