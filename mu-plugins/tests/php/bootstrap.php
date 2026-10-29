<?php
/**
 * PHPUnit bootstrap: what wp-settings.php defines before a must-use plugin
 * loads, then the plugins themselves.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- a test bootstrap runs outside WordPress: it builds its temporary instance directory with PHP itself, and stands in for WordPress by defining WordPress's own constants and hook functions.

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// The constants the recorder reads; a temporary wp-content, so that including
// the plugin (which starts the recorder) never touches a real installation.
define( 'ABSPATH', sys_get_temp_dir() . '/md-mu-test-instance/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
@mkdir( WP_CONTENT_DIR, 0755, true );

/**
 * What wp-settings.php provides before a must-use plugin loads: the hook
 * functions, which record their calls into $GLOBALS so the tests can assert
 * how the plugins attach themselves. PHPStan does not read this file
 * (phpstan.neon.dist): it knows these names from the WordPress stubs.
 *
 * @param string          $hook     The hook's name.
 * @param string|callable $callback What runs on it.
 * @param int             $priority Where in the queue.
 * @param int             $args     How many arguments the callback takes.
 */
function add_action( string $hook, string|callable $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['md_mu_test_hooks'][ $hook ][] = array( $callback, $priority, $args );
}

/**
 * Adds to a filter, recorded the same way.
 *
 * @param string          $hook     The filter's name.
 * @param string|callable $callback What runs on it.
 * @param int             $priority Where in the queue.
 * @param int             $args     How many arguments the callback takes.
 */
function add_filter( string $hook, string|callable $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['md_mu_test_hooks'][ $hook ][] = array( $callback, $priority, $args );
}

/**
 * Removes from a hook, recorded so the tests can assert the removal.
 *
 * @param string          $hook     The hook's name.
 * @param string|callable $callback What is removed.
 * @param int             $priority Where in the queue it sat.
 */
function remove_action( string $hook, string|callable $callback, int $priority = 10 ): void {
	$GLOBALS['md_mu_test_removed'][ $hook ][] = array( $callback, $priority );
}

/**
 * What the REST infrastructure provides, recorded the same way: the routes a
 * plugin registers, against the namespace and path the tests assert.
 *
 * @param string                                  $route_namespace The route's namespace.
 * @param string                                  $route     The route's path.
 * @param array<string, string|string[]|callable> $args      The route's arguments.
 */
function register_rest_route( string $route_namespace, string $route, array $args = array() ): void {
	$GLOBALS['md_mu_test_routes'][] = array( $route_namespace, $route, $args );
}

/**
 * The options the endpoint reads, served from the array the test sets
 * ($GLOBALS['md_mu_test_options'], an option name to its value).
 *
 * @param string $name        The option's name.
 * @param mixed  $default_value What to return when the option is not set.
 * @return mixed The option's value, or the default.
 */
function get_option( string $name, mixed $default_value = false ): mixed {
	return $GLOBALS['md_mu_test_options'][ $name ] ?? $default_value;
}

/**
 * The options the endpoint writes, into the same store get_option reads.
 * $GLOBALS['md_mu_test_update_option_fail'] makes the update fail, the
 * way a database error would, so the endpoint's failure path is testable.
 *
 * @param string      $name     The option's name.
 * @param mixed       $value    The value to store.
 * @param bool|string $autoload Whether the option autoloads; recorded only.
 * @return bool Whether the write happened.
 */
function update_option( string $name, mixed $value, bool|string $autoload = true ): bool {
	$GLOBALS['md_mu_test_option_writes'][] = array( $name, $value, $autoload );
	if ( ! empty( $GLOBALS['md_mu_test_update_option_fail'] ) ) {
		return false;
	}
	$GLOBALS['md_mu_test_options'][ $name ] = $value;
	return true;
}

/**
 * The atomic insert WordPress's own locks are built on: an option whose
 * name is taken is refused, so two callers cannot both win it.
 *
 * @param string           $name       The option's name.
 * @param mixed            $value      The option's value.
 * @param string           $deprecated WordPress's unused argument.
 * @param string|bool|null $autoload  WordPress's autoload flag, 'no' for a lock.
 * @return bool Whether this call created the option.
 */
function add_option( string $name, mixed $value = '', string $deprecated = '', string|bool|null $autoload = null ): bool {
	if ( array_key_exists( $name, $GLOBALS['md_mu_test_options'] ) ) {
		return false;
	}
	$GLOBALS['md_mu_test_options'][ $name ] = $value;
	return true;
}

/**
 * Removes an option from the same store.
 *
 * @param string $name The option's name.
 * @return bool Whether an option was removed.
 */
function delete_option( string $name ): bool {
	if ( ! array_key_exists( $name, $GLOBALS['md_mu_test_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['md_mu_test_options'][ $name ] );
	return true;
}

/**
 * WordPress's filter application, over the callbacks add_filter recorded:
 * priority order, the value threaded through. The bootstrap's plugins
 * register their hooks for assertion, so a filter with no callback returns
 * its value untouched.
 *
 * @since 1.0.0
 *
 * @param string $hook  The filter's name.
 * @param mixed  $value The value to filter.
 * @param mixed  ...$args The filter's extra arguments.
 * @return mixed The filtered value.
 */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$callbacks = $GLOBALS['md_mu_test_hooks'][ $hook ] ?? array();
	usort( $callbacks, static fn ( array $a, array $b ): int => $a[1] <=> $b[1] );
	foreach ( $callbacks as $callback ) {
		if ( is_callable( $callback[0] ) ) {
			$value = $callback[0]( $value, ...$args );
		}
	}
	return $value;
}

/**
 * WordPress's tag stripping, for the unanswered log's shaping.
 *
 * @param string $text           The text.
 * @param bool   $remove_breaks  Whether line breaks go too.
 * @return string The stripped text.
 */
function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
	$text = strip_tags( $text );
	return $remove_breaks ? (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text;
}

/**
 * An outbound GET, recorded into $GLOBALS['md_mu_test_http'] with 'GET' as
 * the method, answered by $GLOBALS['md_mu_test_http_responder'] — a callable
 * taking the URL and the request args — or the successful empty default.
 *
 * @param string               $url  The address asked.
 * @param array<string, mixed> $args The request's arguments.
 * @return array<string, mixed>|\WP_Error The response, as WordPress shapes it.
 */
function wp_remote_get( string $url, array $args = array() ): array|\WP_Error {
	$GLOBALS['md_mu_test_http'][] = array( $url, $args, 'GET' );
	$responder                    = $GLOBALS['md_mu_test_http_responder'] ?? null;
	if ( \is_callable( $responder ) ) {
		$response = $responder( $url, $args );
		if ( \is_array( $response ) || $response instanceof \WP_Error ) {
			return $response;
		}
	}
	return array(
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'body'     => '',
	);
}

/**
 * The HTTP client the endpoint calls DeepSeek through: the request is
 * recorded into $GLOBALS['md_mu_test_http'], and the response comes from
 * $GLOBALS['md_mu_test_http_responder'] — a callable taking the URL and the
 * request args — or the successful empty default.
 *
 * @param string               $url  The address asked.
 * @param array<string, mixed> $args The request's arguments.
 * @return array<string, mixed>|\WP_Error The response, as WordPress shapes it.
 */
function wp_remote_post( string $url, array $args = array() ): array|\WP_Error {
	$GLOBALS['md_mu_test_http'][] = array( $url, $args, 'POST' );
	$responder                    = $GLOBALS['md_mu_test_http_responder'] ?? null;
	if ( \is_callable( $responder ) ) {
		$response = $responder( $url, $args );
		if ( \is_array( $response ) || $response instanceof \WP_Error ) {
			return $response;
		}
	}
	return array(
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'body'     => '{"choices":[{"message":{"content":"{}"}}]}',
	);
}

/**
 * The response's status code, from either shape WordPress returns.
 *
 * @param array<string, mixed>|\WP_Error $response The response.
 * @return int The status code, 0 for an error.
 */
function wp_remote_retrieve_response_code( array|\WP_Error $response ): int {
	if ( $response instanceof \WP_Error ) {
		return 0;
	}
	return (int) ( $response['response']['code'] ?? 0 );
}

/**
 * The response's body, '' for an error.
 *
 * @param array<string, mixed>|\WP_Error $response The response.
 * @return string The body.
 */
function wp_remote_retrieve_body( array|\WP_Error $response ): string {
	if ( $response instanceof \WP_Error ) {
		return '';
	}
	return (string) ( $response['body'] ?? '' );
}

/**
 * What WordPress answers for its error objects.
 *
 * @param mixed $thing What to test.
 * @return bool Whether it is a WP_Error.
 */
function is_wp_error( mixed $thing ): bool {
	return $thing instanceof \WP_Error;
}

/**
 * WordPress's JSON encoding, one argument is all the endpoint asks of it.
 *
 * @param mixed $value What to encode.
 * @return string|false The JSON, or false when it cannot be encoded.
 */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * The transients the endpoint's failure window lives in, served from the
 * array the test sets ($GLOBALS['md_mu_test_transients'], a transient name
 * to its value).
 *
 * @param string $name The transient's name.
 * @return mixed The transient's value, false when it holds none.
 */
function get_transient( string $name ): mixed {
	return $GLOBALS['md_mu_test_transients'][ $name ] ?? false;
}

/**
 * Writes a transient into the same store, expiry recorded but not enforced:
 * the window's own timestamps are what the endpoint prunes by.
 *
 * @param string $name     The transient's name.
 * @param mixed  $value    The value.
 * @param int    $expiry   Seconds the value would live for.
 * @return bool Always true, as WordPress's own.
 */
function set_transient( string $name, mixed $value, int $expiry = 0 ): bool {
	$GLOBALS['md_mu_test_transients'][ $name ]       = $value;
	$GLOBALS['md_mu_test_transient_expiry'][ $name ] = $expiry;
	$GLOBALS['md_mu_test_transient_ttls'][ $name ]   = $expiry;
	return true;
}


/**
 * The product query the pattern lookup runs, served from
 * $GLOBALS['md_mu_test_products']: a list of product arrays the test
 * plants. A query with post_status=publish never sees a non-publish
 * row, which is the WooCommerce rule the lookup depends on. The search
 * string is recorded so a test can assert what was asked.
 *
 * @param array<string, mixed> $args The query arguments.
 */
class WP_Query {

	/**
	 * The posts this query matched.
	 *
	 * @var array<int, \WP_Post>
	 */
	public array $posts = array();

	/**
	 * Builds the query from the planted products.
	 *
	 * @param array<string, mixed> $args The query arguments.
	 */
	public function __construct( array $args = array() ) {
		$GLOBALS['md_mu_test_queries'][] = $args;
		$wanted                          = (string) ( $args['post_status'] ?? '' );
		$search                          = strtolower( trim( (string) ( $args['s'] ?? '' ) ) );
		$posts                           = array();
		foreach ( (array) ( $GLOBALS['md_mu_test_products'] ?? array() ) as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$status = (string) ( $product['post_status'] ?? 'publish' );
			if ( '' !== $wanted && 'any' !== $wanted && $status !== $wanted ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower( (string) ( $product['post_title'] ?? '' ) . ' ' . (string) ( $product['sku'] ?? '' ) . ' ' . (string) ( $product['post_name'] ?? '' ) );
				if ( ! str_contains( $haystack, $search ) ) {
					continue;
				}
			}
			$posts[] = new \WP_Post( $product );
		}
		$this->posts = $posts;
	}
}

/**
 * One product post, the fields the lookup reads off a WP_Post.
 */
class WP_Post {

	/** @var int */
	public int $ID = 0;

	/** @var string */
	public string $post_title = '';

	/** @var string */
	public string $post_status = 'publish';

	/** @var string */
	public string $post_name = '';

	/** @var string */
	public string $sku = '';

	/** @var string */
	public string $post_type = 'product';

	/**
	 * @param array<string, mixed> $fields The planted product.
	 */
	public function __construct( array $fields ) {
		$this->ID          = (int) ( $fields['ID'] ?? 0 );
		$this->post_title  = (string) ( $fields['post_title'] ?? '' );
		$this->post_status = (string) ( $fields['post_status'] ?? 'publish' );
		$this->post_name   = (string) ( $fields['post_name'] ?? '' );
		$this->sku         = (string) ( $fields['sku'] ?? '' );
		$this->post_type   = (string) ( $fields['post_type'] ?? 'product' );
	}
}

/**
 * ACF's field read, from $GLOBALS['md_mu_test_fields'][post_id][name].
 *
 * @param string   $selector The field name.
 * @param int|false $post_id The post, false for the current one.
 * @return mixed The field value, null when unset.
 */
/**
 * Post meta, from the planted product when the key is _sku, otherwise empty.
 *
 * @param int    $post_id The post.
 * @param string $key     The meta key.
 * @param bool   $single  Whether one value is wanted.
 * @return mixed The value.
 */
function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
	unset( $single );
	if ( '_sku' !== $key ) {
		return '';
	}
	foreach ( (array) ( $GLOBALS['md_mu_test_products'] ?? array() ) as $product ) {
		if ( is_array( $product ) && (int) ( $product['ID'] ?? 0 ) === $post_id ) {
			return (string) ( $product['sku'] ?? '' );
		}
	}
	return '';
}

function get_field( string $selector, int|false $post_id = false, bool $format_value = true ): mixed {
	unset( $format_value );
	$id     = false === $post_id ? 0 : $post_id;
	$fields = $GLOBALS['md_mu_test_fields'][ $id ] ?? array();
	return is_array( $fields ) && array_key_exists( $selector, $fields ) ? $fields[ $selector ] : null;
}

/**
 * The product's terms, from $GLOBALS['md_mu_test_terms'][post_id][taxonomy].
 *
 * @param int    $post_id  The post.
 * @param string $taxonomy The taxonomy.
 * @return array<int, \WP_Term>|\WP_Error The terms, or an error the lookup must ignore.
 */
function get_the_terms( int $post_id, string $taxonomy ): array|\WP_Error {
	$terms = $GLOBALS['md_mu_test_terms'][ $post_id ][ $taxonomy ] ?? array();
	return is_array( $terms ) ? $terms : array();
}

/**
 * The permalink the lookup cites, from $GLOBALS['md_mu_test_permalinks'].
 *
 * @param int $post_id The post.
 * @return string The URL, '' when unset.
 */
function get_permalink( int $post_id = 0 ): string {
	return (string) ( $GLOBALS['md_mu_test_permalinks'][ $post_id ] ?? '' );
}

/**
 * The page at a path, from the planted products. WordPress matches the
 * last path segment against post_name for the asked post type; the tests
 * use that to stand in for /pattern/<slug>.
 *
 * @param string               $page_path The path, slashes included.
 * @param string               $output    The return shape. Only OBJECT is served.
 * @param string|array<string> $post_type The post type, or a list of them.
 * @return \WP_Post|null The planted post, null when nothing matches.
 */
/**
 * parse_url() with the keys WordPress returns, enough for the page URL
 * the endpoint accepts. A URL it cannot parse is false, as WordPress.
 *
 * @param string $url The URL.
 * @return array<string, mixed>|false The parts, or false.
 */
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

function wp_parse_url( string $url, int $component = -1 ): array|string|int|false|null {
	$parts = parse_url( $url );
	if ( -1 !== $component ) {
		return is_array( $parts ) ? ( $parts[ $component ] ?? null ) : false;
	}
	return is_array( $parts ) ? $parts : false;
}

/**
 * Whether the request is an admin screen, overridable per test.
 *
 * @return bool
 */
function is_admin(): bool {
	return $GLOBALS['md_mu_test_is_admin'] ?? false;
}

/**
 * Whether the request is a feed, overridable per test.
 *
 * @return bool
 */
function is_feed(): bool {
	return $GLOBALS['md_mu_test_is_feed'] ?? false;
}

/**
 * Removes a filter callback recorded by add_filter.
 *
 * @param string $hook     The filter name.
 * @param mixed  $callback The callback to remove.
 * @return void
 */
function remove_filter( string $hook, mixed $callback ): void {
	$GLOBALS['md_mu_test_hooks'][ $hook ] = array_values(
		array_filter(
			$GLOBALS['md_mu_test_hooks'][ $hook ] ?? array(),
			static fn ( array $entry ): bool => $entry[0] !== $callback
		)
	);
}

/**
 * Records enqueue calls for assertions.
 *
 * @param string           $handle The script handle.
 * @param string           $src    The script source.
 * @param array<int,mixed> $deps   The dependencies.
 * @param string|bool|null $ver    The version.
 * @param array<string,mixed>|bool $args Footer and strategy arguments.
 * @return void
 */
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, array|bool $args = array() ): void {
	$GLOBALS['md_mu_test_enqueues'][] = array(
		'handle' => $handle,
		'src'    => $src,
		'deps'   => $deps,
		'ver'    => $ver,
		'args'   => $args,
	);
}

/**
 * WordPress's text sanitizer, a strip-and-trim for test purposes.
 *
 * @param mixed $value The submitted value.
 * @return string|array<string>|null The sanitized value.
 */
function sanitize_text_field( mixed $value ): string|array|null {
	if ( is_string( $value ) ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $value ) ) ?? '' );
	}
	if ( is_array( $value ) ) {
		return array_map( 'sanitize_text_field', $value );
	}
	return null;
}

/**
 * Path normalizer: lowercases the drive letter and turns backslashes into
 * slashes, matching the WP core behaviour the delete endpoint relies on.
 *
 * @param string $path The path.
 * @return string The normalized path.
 */
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

/**
 * WordPress's unslash, a no-op for test superglobals.
 *
 * @param mixed $value The submitted value.
 * @return mixed The value unchanged.
 */
function wp_unslash( mixed $value ): mixed {
	return $value;
}

function get_page_by_path( string $page_path, string $output = OBJECT, string|array $post_type = 'page' ): ?\WP_Post {
	unset( $output );
	$slug  = strtolower( rawurldecode( basename( trim( $page_path, '/' ) ) ) );
	$types = is_array( $post_type ) ? $post_type : array( $post_type );
	if ( '' === $slug ) {
		return null;
	}
	foreach ( (array) ( $GLOBALS['md_mu_test_products'] ?? array() ) as $product ) {
		if ( ! is_array( $product ) ) {
			continue;
		}
		$type = (string) ( $product['post_type'] ?? 'product' );
		if ( ! in_array( $type, $types, true ) ) {
			continue;
		}
		if ( strtolower( (string) ( $product['post_name'] ?? '' ) ) !== $slug ) {
			continue;
		}
		return new \WP_Post( $product );
	}
	return null;
}

/**
 * The featured image URL, from $GLOBALS['md_mu_test_thumbs'].
 *
 * @param int         $post_id The post.
 * @param string|int[] $size   The size asked for.
 * @return string|false The URL, false when the product has none.
 */
function get_the_post_thumbnail_url( int $post_id, string|array $size = 'post-thumbnail' ): string|false {
	unset( $size );
	if ( ! isset( $GLOBALS['md_mu_test_thumbs'][ $post_id ] ) ) {
		return false;
	}
	return (string) $GLOBALS['md_mu_test_thumbs'][ $post_id ];
}

/**
 * One product_cat term.
 */
class WP_Term {

	/** @var string */
	public string $name = '';

	/** @var string */
	public string $slug = '';

	/**
	 * @param string $name The term name.
	 * @param string $slug The term slug.
	 */
	public function __construct( string $name, string $slug ) {
		$this->name = $name;
		$this->slug = $slug;
	}
}

// phpcs:enable

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the stand-ins mimic WordPress's and Rapls's own shapes, unprefixed on purpose: the lowercase wpdb is WordPress's own name, the two small stand-in classes share this bootstrap, and the global handle is what the code under test reads.

/**
 * The database handle the endpoint counts the knowledge through: the
 * queries land in $queries, and get_var() answers from
 * $GLOBALS['md_mu_test_db_responder'] — a callable taking the SQL — or
 * '0', an empty knowledge base being the safe default.
 */
final class wpdb {

	/**
	 * The table prefix the stand-in validates names with.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * The queries the code under test made.
	 *
	 * @var array<int, string>
	 */
	public array $queries = array();

	/**
	 * Answers one scalar query from the responder the test set.
	 *
	 * @param string|null $query The SQL.
	 * @return string|null The scalar, as WordPress would return it.
	 */
	public function get_var( ?string $query = null ): ?string {
		if ( null !== $query ) {
			$this->queries[] = $query;
		}
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			$value = $responder( (string) $query );
			return \is_string( $value ) ? $value : (string) $value;
		}
		return '0';
	}

	/**
	 * Answers one row query: the responder returns an associative array or
	 * null, and the row ships as the object the callers read ->hash from.
	 *
	 * @param string|null $query The SQL.
	 * @return \stdClass|null The row, null when there is none.
	 */
	public function get_row( ?string $query = null ): ?\stdClass {
		if ( null !== $query ) {
			$this->queries[] = $query;
		}
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			$row = $responder( (string) $query );
			if ( \is_array( $row ) ) {
				return (object) $row;
			}
		}
		return null;
	}

	/**
	 * Answers a rows query: the responder returns a list of associative
	 * arrays, and the rows ship as objects.
	 *
	 * @param string|null $query The SQL.
	 * @return array<int, \stdClass> The rows.
	 */
	public function get_results( ?string $query = null ): array {
		if ( null !== $query ) {
			$this->queries[] = $query;
		}
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			$rows = $responder( (string) $query );
			if ( \is_array( $rows ) ) {
				return \array_map( static fn ( array $row ): \stdClass => (object) $row, \array_filter( $rows, 'is_array' ) );
			}
		}
		return array();
	}

	/**
	 * Answers a column query: the responder returns the list, scalars only.
	 *
	 * @param string|null $query The SQL.
	 * @return array<int, mixed> The column's values.
	 */
	public function get_col( ?string $query = null ): array {
		if ( null !== $query ) {
			$this->queries[] = $query;
		}
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			$col = $responder( (string) $query );
			if ( \is_array( $col ) ) {
				return $col;
			}
		}
		return array();
	}

	/**
	 * Runs one write query: logged, answered by the responder — false means
	 * failure to the callers, anything else is a result they accept.
	 *
	 * @param string $query The SQL.
	 * @return mixed The responder's answer, 0 when there is none.
	 */
	public function query( string $query ): mixed {
		$this->queries[] = $query;
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			return $responder( $query );
		}
		return 0;
	}

	/**
	 * WordPress's placeholder interpolation, the subset the code under test
	 * uses: %d, %s and %f, with the literal %% left alone.
	 *
	 * @param string $query The SQL with placeholders.
	 * @param mixed  ...$args The values.
	 * @return string The SQL with the values in it.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( array() === $args ) {
			return $query;
		}
		$replaced = '';
		$arg_i    = 0;
		$length   = \strlen( $query );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $query[ $i ];
			if ( '%' !== $char || $i + 1 >= $length ) {
				$replaced .= $char;
				continue;
			}
			$next = $query[ $i + 1 ];
			if ( '%' === $next ) {
				$replaced .= '%';
				++$i;
				continue;
			}
			if ( $arg_i >= \count( $args ) ) {
				$replaced .= $char;
				continue;
			}
			$value = $args[ $arg_i++ ];
			if ( 'd' === $next ) {
				$replaced .= (string) (int) $value;
			} elseif ( 'f' === $next ) {
				$replaced .= (string) (float) $value;
			} else {
				$replaced .= "'" . \addslashes( (string) $value ) . "'";
			}
			++$i;
		}
		return $replaced;
	}

	/**
	 * Runs one update: logged, answered by the responder — 1 row touched is
	 * the stand-in's default, false (the failure the callers check) never is.
	 *
	 * @param string              $table The table.
	 * @param array<string,mixed> $data The columns to set.
	 * @param array<string,mixed> $where The columns to match.
	 * @return mixed The responder's answer, 1 when there is none.
	 */
	public function update( string $table, array $data, array $where ): mixed {
		$this->queries[] = 'UPDATE ' . $table . ' SET ' . \implode( ',', \array_keys( $data ) ) . ' WHERE ' . \implode( ',', \array_keys( $where ) );
		$responder = $GLOBALS['md_mu_test_db_responder'] ?? null;
		if ( \is_callable( $responder ) ) {
			return $responder( $this->queries[ \count( $this->queries ) - 1 ] );
		}
		return 1;
	}
}

$GLOBALS['wpdb'] = new wpdb();

/**
 * The site salt stand-in: a fixed string, because the failure windows only
 * need it to be stable and secret-shaped inside one test run.
 *
 * @param string $scheme The salt's scheme.
 * @return string The salt.
 */
function wp_salt( string $scheme = 'auth' ): string {
	return 'md-mu-test-salt-' . $scheme;
}

/**
 * The REST request stand-in: built from the decoded JSON body the route
 * callback reads, plus the headers the bearer gate checks.
 */
final class WP_REST_Request {

	/**
	 * The decoded JSON body.
	 *
	 * @var array<string, mixed>
	 */
	public array $json_params;

	/**
	 * The request's headers, lowercase names.
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	/**
	 * Builds the request.
	 *
	 * @param array<string, mixed>  $json_params The decoded body.
	 * @param array<string, string> $headers The headers.
	 */
	public function __construct( array $json_params = array(), array $headers = array() ) {
		$this->json_params = $json_params;
		$this->headers     = \array_change_key_case( $headers );
	}

	/**
	 * The decoded JSON body.
	 *
	 * @return array<string, mixed>|null The body, null when there is none.
	 */
	public function get_json_params(): ?array {
		return $this->json_params;
	}

	/**
	 * One header, by its lowercase name.
	 *
	 * @param string $name The header's name.
	 * @return string The header, '' when absent.
	 */
	public function get_header( string $name ): string {
		return $this->headers[ \strtolower( $name ) ] ?? '';
	}
}

/**
 * The REST response stand-in: the data and the status the routes return,
 * readable by the tests.
 */
final class WP_REST_Response {

	/**
	 * The response's data.
	 *
	 * @var mixed
	 */
	public mixed $data;

	/**
	 * The response's HTTP status.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Builds the response.
	 *
	 * @param mixed $data The data.
	 * @param int   $status The HTTP status.
	 */
	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

/**
 * Rapls's own whitelist-validated table name (rapls-ai-chatbot.php:316):
 * present at runtime exactly when the knowledge plugin is. The stand-in
 * keeps the whitelist to the two tables the endpoint touches.
 *
 * @param string $suffix The table's suffix, without the prefix.
 * @return string The backtick-quoted table name, '' when not whitelisted.
 */
function raplsaich_validated_table( string $suffix ): string {
	$allowed = array( 'raplsaich_index', 'raplsaich_knowledge' );
	return \in_array( $suffix, $allowed, true ) ? '`' . $GLOBALS['wpdb']->prefix . $suffix . '`' : '';
}

/**
 * The error object wp_remote_post returns on failure. The real one carries
 * codes and messages; the endpoint only asks whether the response is an
 * error, and this stand-in is enough for that question.
 */
final class WP_Error {

	/**
	 * The error's code.
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * The error's message.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * Builds the error.
	 *
	 * @param string $code    The error's code.
	 * @param string $message The error's message.
	 */
	public function __construct( string $code = 'http_request_failed', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
}

// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * The wp-cli runtime double: add_command records what production
	 * registered, the writers echo so tests can capture them with output
	 * buffering, and error() throws the way the real one exits.
	 */
	class WP_CLI {
		/** @var array<string, callable|string> The commands add_command registered. */
		public static array $registered_commands = array();

		/** Records the command for the dispatch tests to read back. */
		public static function add_command( string $name, callable|string $command ): void {
			self::$registered_commands[ $name ] = $command;
		}

		/** Prints one line, like wp-cli's log. */
		public static function log( string $message ): void {
			echo 'wp-cli log: ' . $message . "\n";
		}

		/** Prints one success line. */
		public static function success( string $message ): void {
			echo 'wp-cli success: ' . $message . "\n";
		}

		/** Prints one warning line. */
		public static function warning( string $message ): void {
			echo 'wp-cli warning: ' . $message . "\n";
		}

		/** Exits like wp-cli's error, as a catchable exception instead. */
		public static function error( string $message ): void {
			throw new WP_CLI\ExitException( $message );
		}
	}
}

if ( ! class_exists( 'WP_CLI\ExitException' ) ) {
	class WP_CLI_ExitException extends Exception {
	}
	class_alias( WP_CLI_ExitException::class, 'WP_CLI\\ExitException' );
}

require_once dirname( __DIR__, 2 ) . '/md-deploy-reset.php';
require_once dirname( __DIR__, 2 ) . '/md-error-recorder.php';
require_once dirname( __DIR__, 2 ) . '/md-wecom-notify.php';
require_once dirname( __DIR__, 2 ) . '/md-site-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-staging-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-kb-endpoint.php';
require_once dirname( __DIR__, 2 ) . '/md-chat-email.php';
require_once dirname( __DIR__, 2 ) . '/md-chat-delete.php';
require_once dirname( __DIR__, 2 ) . '/md-chat-launcher.php';
