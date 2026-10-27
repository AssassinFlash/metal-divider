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
	$GLOBALS['md_mu_test_http'][] = array( $url, $args );
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

	/**
	 * @param array<string, mixed> $fields The planted product.
	 */
	public function __construct( array $fields ) {
		$this->ID          = (int) ( $fields['ID'] ?? 0 );
		$this->post_title  = (string) ( $fields['post_title'] ?? '' );
		$this->post_status = (string) ( $fields['post_status'] ?? 'publish' );
		$this->post_name   = (string) ( $fields['post_name'] ?? '' );
		$this->sku         = (string) ( $fields['sku'] ?? '' );
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
}

$GLOBALS['wpdb'] = new wpdb();

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
}

// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound, PEAR.NamingConventions.ValidClassName.StartWithCapital, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

require_once dirname( __DIR__, 2 ) . '/md-deploy-reset.php';
require_once dirname( __DIR__, 2 ) . '/md-error-recorder.php';
require_once dirname( __DIR__, 2 ) . '/md-site-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-staging-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-kb-endpoint.php';
