<?php
/**
 * The recording state of the WordPress doubles: every double in
 * tests/php/Doubles/wordpress-fakes.php records into MD_Migrate_Fakes and
 * the postmeta pair is a real store, so a test can both assert the calls and
 * read back what the driver wrote. Unlike the function doubles (excluded
 * from analysis, they shadow WordPress's own names), this class is this
 * project's own code and is analysed.
 *
 * @package md-migrate
 */

declare( strict_types = 1 );

/**
 * The recording state shared by the doubles and reset by every test.
 */
final class MD_Migrate_Fakes {

	/**
	 * The uploads basedir wp_upload_dir answers with.
	 *
	 * @var string
	 */
	public static string $upload_basedir = '';

	/**
	 * SKU -> product post id (wc_get_product_id_by_sku).
	 *
	 * @var array<string, int>
	 */
	public static array $product_ids = array();

	/**
	 * The id wp_insert_attachment hands out.
	 *
	 * @var int
	 */
	public static int $attachment_id = 77;

	/**
	 * The postmeta store: post id -> key -> value.
	 *
	 * @var array<int, array<string, string>>
	 */
	public static array $meta = array();

	/**
	 * Recorded calls: double name -> list of argument lists.
	 *
	 * @var array<string, array<int, array<int, mixed>>>
	 */
	public static array $calls = array();

	/**
	 * Empties every recording and points the doubles at a fresh uploads dir.
	 *
	 * @param string $basedir The uploads basedir of this test.
	 * @return void
	 */
	public static function reset( string $basedir ): void {
		self::$upload_basedir = $basedir;
		self::$product_ids    = array();
		self::$attachment_id  = 77;
		self::$meta           = array();
		self::$calls          = array();
		$GLOBALS['wpdb']      = new MD_Migrate_Fake_Wpdb();
	}

	/**
	 * Records a call.
	 *
	 * @param string $name  The double's name.
	 * @param mixed  ...$args The arguments as they arrived.
	 * @return void
	 */
	public static function called( string $name, mixed ...$args ): void {
		self::$calls[ $name ][] = array_values( $args );
	}

	/**
	 * How often a double was called.
	 *
	 * @param string $name The double's name.
	 * @return int The call count.
	 */
	public static function count( string $name ): int {
		return count( self::$calls[ $name ] ?? array() );
	}

	/**
	 * One recorded postmeta value of a post.
	 *
	 * @param int    $id  The post id.
	 * @param string $key The meta key.
	 * @return string The stored value, '' when absent.
	 */
	public static function meta( int $id, string $key ): string {
		return self::$meta[ $id ][ $key ] ?? '';
	}
}
