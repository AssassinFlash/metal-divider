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

// phpcs:enable

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- the stand-ins mimic WordPress's own shapes, unprefixed on purpose.

/**
 * The error object wp_remote_post returns on failure. The real one carries
 * codes and messages; the endpoint only asks whether the response is an
 * error, and this stand-in is enough for that question.
 */
final class WP_Error {
}

// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

require_once dirname( __DIR__, 2 ) . '/md-deploy-reset.php';
require_once dirname( __DIR__, 2 ) . '/md-error-recorder.php';
require_once dirname( __DIR__, 2 ) . '/md-site-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-staging-guards.php';
require_once dirname( __DIR__, 2 ) . '/md-kb-endpoint.php';
