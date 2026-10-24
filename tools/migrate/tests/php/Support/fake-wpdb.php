<?php
/**
 * The wpdb double: its one job on the media path is the _md_source_url scan,
 * answered from the postmeta store the update double filled.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

/**
 * The wpdb stand-in of the tests' runtime.
 */
final class MD_Migrate_Fake_Wpdb {

	/**
	 * The table prefix the SQL interpolates.
	 *
	 * @var string
	 */
	public string $postmeta = 'wp_postmeta';

	/**
	 * The _md_source_url rows of the store, as attachments_by_source reads them.
	 *
	 * @param string      $query  The SQL (only the _md_source_url scan arrives here; the rows come from the store, not the query).
	 * @param string|null $output The output-mode constant (ARRAY_A; the rows are shaped for it).
	 * @return array<int, array<string, mixed>> The post_id/meta_value rows.
	 */
	public function get_results( string $query, ?string $output = null ): array {
		$rows = array();
		foreach ( MD_Migrate_Fakes::$meta as $post_id => $meta ) {
			if ( isset( $meta['_md_source_url'] ) ) {
				$rows[] = array(
					'post_id'    => (string) $post_id,
					'meta_value' => $meta['_md_source_url'],
				);
			}
		}
		return $rows;
	}
}
