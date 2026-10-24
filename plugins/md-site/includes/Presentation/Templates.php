<?php
/**
 * LiveCanvas dynamic templates: the repository files installed into the
 * site (spec 19 §3, ticket #32).
 *
 * The presentation source of truth is this plugin's
 * content/livecanvas/*.html — one file per face. `present` writes each
 * file into the post LiveCanvas actually renders from, by the
 * assignment mechanism the face uses, and never creates a second
 * template beside an installed one: page-type keys reclaim the
 * published template row that carries the key (the de-linker
 * production pattern, spec 19 F4), per-page faces create their own
 * post and point the page at it with LiveCanvas's own per-post choice,
 * and the two chrome partials carry LiveCanvas's is_header/is_footer
 * assignment. `drift()` is the read-only twin: the DB post_content
 * against the file, every lookup the same one present writes through.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Presentation;

use WP_Post;
use WP_CLI;

/**
 * The template inventory: files under content/livecanvas, their
 * assignment mechanism, and their editor titles.
 */
final class Templates {

	/**
	 * LiveCanvas's dynamic-template post type.
	 */
	public const POST_TYPE = 'lc_dynamic_template';

	/**
	 * LiveCanvas's per-post template choice, written onto a page.
	 */
	public const PAGE_CHOICE = 'lc_use_template_of_slug';

	/**
	 * The template directory, relative to this plugin.
	 */
	private const RELATIVE_DIR = 'content/livecanvas';

	/**
	 * Page-type assignment key → file name (without .html), the faces
	 * LiveCanvas assigns by request condition (spec 19 §3; the series
	 * archive's key is the taxonomy one the LiveCanvas loader resolves,
	 * `is_archive_for_tax_` + taxonomy — verified against the instance's
	 * livecanvas/modules/templating.php before this table was written).
	 */
	private const FILES = array(
		'is_single_product'              => 'pdp',
		'is_archive_for_tax_product_cat' => 'series-archive',
		'is_shop_page'                   => 'shop',
		'is_blog_posts_index'            => 'blog-index',
		'is_single_post'                 => 'blog-single',
		'is_archive_for_tax_category'    => 'blog-category',
		'is_search'                      => 'blog-search',
	);

	/**
	 * Editor titles of the page-type templates.
	 */
	private const FILE_TITLES = array(
		'is_single_product'              => 'Pattern (single)',
		'is_archive_for_tax_product_cat' => 'Pattern series archive',
		'is_shop_page'                   => 'Shop (all patterns)',
		'is_blog_posts_index'            => 'Blog index',
		'is_single_post'                 => 'Blog single post',
		'is_archive_for_tax_category'    => 'Blog category archive',
		'is_search'                      => 'Blog search results',
	);

	/**
	 * Per-page faces: template slug (the post name LiveCanvas looks the
	 * per-post choice up by) → array( file, title, page slug ). The
	 * pages themselves are the migrator's to create (ticket #31); when a
	 * page is there, `present` points it at its template, and when it is
	 * not, that fact is a drift row, not a write.
	 */
	private const PAGES = array(
		'md-home'                       => array( 'home', 'Home', 'home' ),
		'md-hub'                        => array( 'hub', 'Pattern series hub', 'collections' ),
		'md-family-laser-cut-geometric' => array( 'family-laser-cut-geometric', 'Laser-cut geometric screens', 'laser-cut-geometric-metal-screens' ),
		'md-family-glass-hybrid'        => array( 'family-glass-hybrid', 'Glass hybrid screens', 'glass-hybrid-screens' ),
		'md-family-outdoor-privacy'     => array( 'family-outdoor-privacy', 'Outdoor privacy screens', 'outdoor-privacy-landscape-screens' ),
		'md-family-chinese-style'       => array( 'family-chinese-style', 'Chinese style screens', 'chinese-style-artistic-screens' ),
		'md-family-stainless-steel'     => array( 'family-stainless-steel', 'Stainless steel screens', 'stainless-steel-screens' ),
		'md-family-laser-cut-panels'    => array( 'family-laser-cut-panels', 'Laser-cut panels', 'laser-cut-panels' ),
		'md-family-perforated-panels'   => array( 'family-perforated-panels', 'Perforated panels', 'perforated-panels' ),
		'md-family-expanded-metal'      => array( 'family-expanded-metal', 'Expanded metal panels', 'expanded-metal-panels' ),
		'md-manufacturing'              => array( 'manufacturing', 'Manufacturing process', 'manufacturing-process' ),
		'md-project-gallery'            => array( 'project-gallery', 'Project gallery', 'project-gallery' ),
		'md-about'                      => array( 'about', 'About us', 'about-us' ),
		'md-faqs'                       => array( 'faqs', 'FAQs', 'faqs' ),
		'md-custom-screens'             => array( 'custom-screens', 'Custom metal divider screens', 'custom-metal-divider-screens' ),
		'md-inquiry'                    => array( 'inquiry', 'Inquiry', 'inquiry' ),
		'md-inquiry-thank-you'          => array( 'inquiry-thank-you', 'Inquiry thank you', 'inquiry-thank-you' ),
		'md-privacy'                    => array( 'privacy', 'Privacy policy', 'privacy' ),
	);

	/**
	 * The partials: file → array( slug, title, assignment meta or null ).
	 * The chrome pair carries LiveCanvas's is_header / is_footer keys
	 * (the picostrap header/footer call them through lc_custom_header /
	 * lc_custom_footer); the two content partials are plain lc_partial
	 * posts the PDP template references by slug (spec 19 §5.2).
	 */
	private const PARTIALS = array(
		'partials/header.html'         => array( 'header', 'Site header', 'is_header' ),
		'partials/footer.html'         => array( 'footer', 'Site footer', 'is_footer' ),
		'partials/md-where-works.html' => array( 'md-where-works', 'Where this pattern works', null ),
		'partials/md-pdp-faq.html'     => array( 'md-pdp-faq', 'Pattern technical FAQ', null ),
	);

	/**
	 * Template post ids inserted by the current sync().
	 *
	 * @var list<int>
	 */
	private array $created_ids = array();

	/**
	 * The ids the current sync() created.
	 *
	 * @return list<int>
	 */
	public function created_ids(): array {
		return $this->created_ids;
	}

	/**
	 * Installs or updates every face so the site matches the files.
	 *
	 * @return array{created: int, updated: int, unchanged: int, pages_assigned: int, pages_missing: list<string>, partials_created: int, partials_updated: int}
	 * @throws \RuntimeException When WordPress refuses a template insert.
	 */
	public function sync(): array {
		$created        = 0;
		$updated        = 0;
		$unchanged      = 0;
		$pages_assigned = 0;
		$pages_missing  = array();
		foreach ( self::FILES as $meta => $file ) {
			$html = self::read( self::path( $file ) );
			$id   = $this->existing_id( $meta );
			if ( 0 === $id ) {
				$id = $this->insert( $file, self::FILE_TITLES[ $meta ], $html );
				update_post_meta( $id, $meta, '1' );
				$this->created_ids[] = $id;
				++$created;
				continue;
			}
			$post = get_post( $id );
			if ( $post instanceof WP_Post && $post->post_content === $html ) {
				++$unchanged;
				continue;
			}
			$this->update( $id, $file, self::FILE_TITLES[ $meta ], $html );
			++$updated;
		}
		foreach ( self::PAGES as $slug => $page ) {
			list( $file, $title, $page_slug ) = $page;
			$html                             = self::read( self::path( $file ) );
			$post                             = $this->template_by_slug( $slug );
			if ( null === $post ) {
				$id                  = $this->insert( $slug, $title, $html );
				$this->created_ids[] = $id;
				++$created;
			} elseif ( $post->post_content !== $html || $post->post_title !== $title ) {
				$this->update( $post->ID, $slug, $title, $html );
				++$updated;
			} else {
				++$unchanged;
			}
			$page_id = $this->page_id( $page_slug );
			if ( 0 === $page_id ) {
				$pages_missing[] = $page_slug;
				continue;
			}
			if ( get_post_meta( $page_id, self::PAGE_CHOICE, true ) !== $slug ) {
				update_post_meta( $page_id, self::PAGE_CHOICE, $slug );
				++$pages_assigned;
			}
		}
		$partials = $this->sync_partials();
		return array(
			'created'          => $created,
			'updated'          => $updated,
			'unchanged'        => $unchanged,
			'pages_assigned'   => $pages_assigned,
			'pages_missing'    => $pages_missing,
			'partials_created' => $partials['created'],
			'partials_updated' => $partials['updated'],
		);
	}

	/**
	 * The four partials: created when absent, updated when their file
	 * changed, and the chrome pair registered with LiveCanvas's own
	 * header/footer assignment.
	 *
	 * @return array{created: int, updated: int}
	 * @throws \RuntimeException When a source is missing or WordPress refuses a write.
	 */
	private function sync_partials(): array {
		$created = 0;
		$updated = 0;
		foreach ( self::PARTIALS as $rel => $meta ) {
			list( $slug, $title, $assignment ) = $meta;
			$html                              = self::read( self::path( $rel, true ) );
			$post                              = $this->partial_by_slug( $slug );
			if ( null === $post ) {
				$id = wp_insert_post(
					array(
						'post_type'    => 'lc_partial',
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => $title,
						'post_content' => wp_slash( $html ),
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- operator log, not page output.
					throw new \RuntimeException( sprintf( 'wp_insert_post refused the %s partial: %s', $slug, $id->get_error_message() ) );
				}
				if ( null !== $assignment ) {
					update_post_meta( (int) $id, $assignment, '1' );
				}
				$this->created_ids[] = (int) $id;
				++$created;
				continue;
			}
			if ( $post->post_content === $html ) {
				continue;
			}
			$result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_slash( $html ),
					'post_status'  => 'publish',
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- operator log, not page output.
				throw new \RuntimeException( sprintf( 'wp_update_post refused the %s partial: %s', $slug, $result->get_error_message() ) );
			}
			if ( null !== $assignment ) {
				update_post_meta( $post->ID, $assignment, '1' );
			}
			++$updated;
		}
		return array(
			'created' => $created,
			'updated' => $updated,
		);
	}

	/**
	 * Whether the site's templates are what the files say they are: the
	 * read-only twin of sync(), the drift check of the acceptance gate
	 * (spec 19 §8 G1). Every lookup is sync()'s own; a row present
	 * would not repair is not reported. Nothing here writes.
	 *
	 * @return list<array{surface: string, target: string, detail: string}> Drift rows, empty when in sync.
	 */
	public function drift(): array {
		$rows = array();
		foreach ( self::FILES as $meta => $file ) {
			$this->drift_row( $rows, 'page type ' . $meta, self::path( $file ), $this->existing_id( $meta ), self::FILE_TITLES[ $meta ] );
		}
		foreach ( self::PAGES as $slug => $page ) {
			list( $file, $title, $page_slug ) = $page;
			$this->drift_row( $rows, 'page ' . $slug, self::path( $file ), $this->id_by_slug( $slug ), $title );
			$page_id = $this->page_id( $page_slug );
			if ( 0 === $page_id ) {
				$rows[] = $this->row( 'page assignment ' . $slug, $page_slug, 'the page this template belongs to is not installed (the migrator creates it, ticket #31)' );
				continue;
			}
			if ( get_post_meta( $page_id, self::PAGE_CHOICE, true ) !== $slug ) {
				$rows[] = $this->row( 'page assignment ' . $slug, $page_slug, sprintf( 'post %d points at another template (or none)', $page_id ) );
			}
		}
		foreach ( self::PARTIALS as $rel => $meta ) {
			$slug = $meta[0];
			$post = $this->partial_by_slug( $slug );
			$html = self::read( self::path( $rel, true ) );
			if ( null === $post ) {
				$rows[] = $this->row( 'partial ' . $slug, $slug, 'not installed' );
				continue;
			}
			if ( $post->post_content !== $html ) {
				$rows[] = $this->row( 'partial ' . $slug, sprintf( 'post %d', $post->ID ), 'content differs from ' . basename( $rel ) );
			}
		}
		return $rows;
	}

	/**
	 * One template against its file: absent, unreadable, or content
	 * that differs (the title too, where sync writes one).
	 *
	 * @param list<array{surface: string, target: string, detail: string}> $rows  Rows so far (by reference).
	 * @param string                                                       $surface Row label.
	 * @param string                                                       $path    The exported file.
	 * @param int                                                          $id      The site's template post, 0 when absent.
	 * @param string|null                                                  $title   The repo title, when sync writes one.
	 */
	private function drift_row( array &$rows, string $surface, string $path, int $id, ?string $title = null ): void {
		if ( 0 === $id ) {
			$rows[] = $this->row( $surface, basename( $path ), 'not installed' );
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			$rows[] = $this->row( $surface, sprintf( 'post %d', $id ), 'the template post cannot be read' );
			return;
		}
		if ( self::read( $path ) !== $post->post_content ) {
			$rows[] = $this->row( $surface, sprintf( 'post %d', $id ), 'content differs from ' . basename( $path ) );
		}
		if ( null !== $title && $post->post_title !== $title ) {
			$rows[] = $this->row( $surface, sprintf( 'post %d', $id ), 'title differs from "' . $title . '"' );
		}
	}

	/**
	 * One drift row.
	 *
	 * @param string $surface The kind of thing that drifted.
	 * @param string $target  Which one.
	 * @param string $detail  What is wrong.
	 * @return array{surface: string, target: string, detail: string}
	 */
	private function row( string $surface, string $target, string $detail ): array {
		return array(
			'surface' => $surface,
			'target'  => $target,
			'detail'  => $detail,
		);
	}

	/**
	 * The absolute path of an exported template file.
	 *
	 * @param string $file File name, with .html for a partial's relative path.
	 * @param bool   $is_relative_path Whether $file is already a path under the directory.
	 */
	public static function path( string $file, bool $is_relative_path = false ): string {
		$base = dirname( __DIR__, 2 ) . '/' . self::RELATIVE_DIR . '/';
		return $is_relative_path ? $base . $file : $base . $file . '.html';
	}

	/**
	 * An exported file's contents, refusing the empty (an empty source
	 * would blank the live face — a refusal, not a write).
	 *
	 * @param string $path Absolute path.
	 * @throws \RuntimeException When the file is missing or empty.
	 */
	public static function read( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read at install time.
		$html = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		if ( '' === trim( $html ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- operator log, not page output.
			throw new \RuntimeException( sprintf( 'Template source is missing or empty: %s', $path ) );
		}
		return rtrim( $html, "\n" );
	}

	/**
	 * Inserts one template post.
	 *
	 * @param string $name  The post name (the per-page slug, or the file's for page types).
	 * @param string $title Editor title.
	 * @param string $html  The exported markup.
	 * @return int The new post id.
	 * @throws \RuntimeException When WordPress refuses the insert.
	 */
	private function insert( string $name, string $title, string $html ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_name'    => $name,
				'post_title'   => $title,
				'post_content' => wp_slash( $html ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- operator log, not page output.
			throw new \RuntimeException( sprintf( 'wp_insert_post refused the %s template: %s', $name, $id->get_error_message() ) );
		}
		return (int) $id;
	}

	/**
	 * Updates one template post in place.
	 *
	 * @param int    $id    The post id.
	 * @param string $name  The post name, for the error message.
	 * @param string $title Editor title.
	 * @param string $html  The exported markup.
	 * @throws \RuntimeException When WordPress refuses the update.
	 */
	private function update( int $id, string $name, string $title, string $html ): void {
		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $html ),
				'post_title'   => $title,
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- operator log, not page output.
			throw new \RuntimeException( sprintf( 'wp_update_post refused the %s template: %s', $name, $result->get_error_message() ) );
		}
	}

	/**
	 * The published template assigned to a LiveCanvas page-type key, or 0.
	 *
	 * @param string $meta The assignment key (is_single_product, …).
	 */
	private function existing_id( string $meta ): int {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- LiveCanvas's own lookup.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- LiveCanvas stores the assignment as "1".
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return isset( $posts[0] ) ? (int) $posts[0]->ID : 0;
	}

	/**
	 * The template of a slug, any status, or null.
	 *
	 * @param string $slug The post name.
	 */
	private function template_by_slug( string $slug ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * The template id of a slug, 0 when there is none.
	 *
	 * @param string $slug The post name.
	 */
	private function id_by_slug( string $slug ): int {
		$post = $this->template_by_slug( $slug );
		return null === $post ? 0 : (int) $post->ID;
	}

	/**
	 * The published partial of a slug, or null.
	 *
	 * @param string $slug The partial's post name.
	 */
	private function partial_by_slug( string $slug ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => 'lc_partial',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * The published page of a slug, or 0. The migrator (ticket #31)
	 * creates the pages; present only points them at their templates.
	 *
	 * @param string $slug The page's post name.
	 */
	private function page_id( string $slug ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);
		return isset( $posts[0] ) ? (int) $posts[0]->ID : 0;
	}
}
