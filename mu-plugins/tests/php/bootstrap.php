<?php
/**
 * PHPUnit bootstrap: what wp-settings.php defines before a must-use plugin
 * loads, then the plugins themselves.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- a test bootstrap runs outside WordPress: it builds its temporary instance directory with PHP itself, and stands in for WordPress by defining WordPress's own constants and hook functions, whose signatures carry arguments the stand-ins do not use.

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

// The options, transients and HTTP the endpoint-shaped plugins read, served
// from the arrays and callables the tests set (the md-kb-endpoint precedent,
// mu-plugins/tests/php/bootstrap.php of impl/118-kb-endpoint).
$GLOBALS['md_mu_test_options']    = array();
$GLOBALS['md_mu_test_transients'] = array();
$GLOBALS['md_mu_test_http']       = array();
$GLOBALS['md_mu_test_routes']     = array();

/**
 * What the REST infrastructure provides, recorded the same way: the routes a
 * plugin registers, against the namespace and path the tests assert.
 *
 * @param string                                  $route_namespace The route's namespace.
 * @param string                                  $route           The route's path.
 * @param array<string, string|string[]|callable> $args            The route's arguments.
 */
function register_rest_route( string $route_namespace, string $route, array $args = array() ): void {
	$GLOBALS['md_mu_test_routes'][] = array( $route_namespace, $route, $args );
}

/**
 * The options the plugins read, served from the array the test sets
 * ($GLOBALS['md_mu_test_options'], an option name to its value).
 *
 * @param string $name          The option's name.
 * @param mixed  $default_value What to return when the option is not set.
 * @return mixed The option's value, or the default.
 */
function get_option( string $name, mixed $default_value = false ): mixed {
	return $GLOBALS['md_mu_test_options'][ $name ] ?? $default_value;
}

/**
 * Writes an option into the same store.
 *
 * @param string $name  The option's name.
 * @param mixed  $value The option's value.
 * @return bool Always true, as WordPress's own when a value changes.
 */
function update_option( string $name, mixed $value ): bool {
	$GLOBALS['md_mu_test_options'][ $name ] = $value;
	return true;
}

/**
 * The transients a plugin's token cache lives in, served from the array the
 * test sets ($GLOBALS['md_mu_test_transients'], a transient name to its
 * value); expiry is recorded but not enforced — the cache's own expiry
 * timestamp is what the code prunes by.
 *
 * @param string $name The transient's name.
 * @return mixed The transient's value, false when it holds none.
 */
function get_transient( string $name ): mixed {
	return $GLOBALS['md_mu_test_transients'][ $name ] ?? false;
}

/**
 * Writes a transient into the same store.
 *
 * @param string $name   The transient's name.
 * @param mixed  $value  The value.
 * @param int    $expiry Seconds the value would live for.
 * @return bool Always true, as WordPress's own.
 */
function set_transient( string $name, mixed $value, int $expiry = 0 ): bool {
	$GLOBALS['md_mu_test_transients'][ $name ] = $value;
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
 * An outbound POST, recorded the same way with 'POST' as the method.
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
		'body'     => '',
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
 * WordPress's JSON encoding, one argument is all the connector asks of it.
 *
 * @param mixed $value What to encode.
 * @return string|false The JSON, or false when it cannot be encoded.
 */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

// phpcs:enable

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- the stand-in mimics WordPress's own WP_Error shape, unprefixed on purpose: the code under test checks for it with is_wp_error().

/**
 * The error object WordPress returns for a failed transport, standing in so
 * the connector's network-failure paths run outside WordPress.
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

// phpcs:enable

require_once dirname( __DIR__, 2 ) . '/md-deploy-reset.php';
require_once dirname( __DIR__, 2 ) . '/md-error-recorder.php';
require_once dirname( __DIR__, 2 ) . '/md-wecom-notify.php';
require_once dirname( __DIR__, 2 ) . '/md-site-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-staging-guards.php';
