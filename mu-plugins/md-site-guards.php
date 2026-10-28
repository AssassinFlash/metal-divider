<?php
/**
 * Plugin Name: MD site guards
 * Description: The public site's security baseline: the response headers (the Content-Security-Policy included), XML-RPC fully closed, the author archives closed, and the closed REST user list.
 *
 * A must-use plugin: the headers and the guards are properties of the site
 * itself, and they must hold while a regular plugin is being activated,
 * updated or broken.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

/*
 * XML-RPC is off for the whole site: no client exists for it (no Jetpack, no
 * mobile app, no pingbacks), and on a shared host behind Cloudflare
 * `system.multicall` is a brute-force amplifier. xmlrpc.php defines
 * XMLRPC_REQUEST — as true, its only definition — before it loads WordPress,
 * so refusing at this file's load kills every XML-RPC request at the earliest
 * point a mu-plugin owns: method calls and the ?rsd endpoint alike.
 *
 * The `xmlrpc_enabled` filter below is the second lock, kept for any code
 * path that reaches the server class without going through xmlrpc.php; on its
 * own it would not close the site (WordPress 7's own docblock: the filter
 * "does not control whether XML-RPC is *fully* enabled" — system methods and
 * pingbacks would stay open, class-wp-xmlrpc-server.php, 2026-10-24).
 */
if ( defined( 'XMLRPC_REQUEST' ) ) {
	http_response_code( 405 );
	exit( 'XML-RPC services are disabled on this site.' );
}

add_filter( 'xmlrpc_enabled', '__return_false' );
remove_action( 'wp_head', 'rsd_link' );

/**
 * The security headers every response carries, as header lines.
 *
 * HSTS asks for one year and includes subdomains, and is sent by this site
 * only: Cloudflare, in front, is left with its own HSTS off, so the policy
 * has a single source (docs/specs/23-mu-plugins.md §2.1). The next two
 * headers are the site's own policy: nothing frames the site (and the CSP's
 * frame-ancestors says the same to browsers that understand it), and browsers
 * keep the declared content types and the referrer's origin — an inquiry URL
 * carries three source parameters, and wa.me is a plain link, so only the
 * origin ever reaches it. The CSP closes the site around its two external
 * faces: Turnstile (scripts and frames from challenges.cloudflare.com) and
 * the CookieYes banner (scripts, images and connections from cdn-cookieyes.com
 * and *.cookieyes.com, inline styles); wa.me needs no entry, being a
 * navigation, and scripts carry no 'unsafe-inline' — the value is pinned by
 * test, and the one allowed fallback needs a recorded violation first
 * (docs/specs/23-mu-plugins.md §2.2). frame-src also carries 'self': the
 * site pages frame the same-origin visitor chat widget (the Ask Metal
 * Divider launcher, mu-plugins/md-chat-launcher.php), so self-framing is a
 * first-party need, not an opening to third parties.
 *
 * @return array<int, string> The header lines, name and value, ready for header().
 */
function md_site_guards_header_lines(): array {
	return array(
		'Strict-Transport-Security: max-age=31536000; includeSubDomains',
		'X-Frame-Options: SAMEORIGIN',
		'X-Content-Type-Options: nosniff',
		'Referrer-Policy: strict-origin-when-cross-origin',
		"Content-Security-Policy: default-src 'self'; script-src 'self' 'inline-speculation-rules' https://challenges.cloudflare.com https://cdn-cookieyes.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://cdn-cookieyes.com; font-src 'self'; connect-src 'self' https://*.cookieyes.com https://cdn-cookieyes.com; frame-src 'self' https://challenges.cloudflare.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
	);
}

/**
 * Sends the security headers, before any output.
 */
function md_site_guards_headers(): void {
	if ( headers_sent() ) {
		return;
	}
	foreach ( md_site_guards_header_lines() as $line ) {
		header( $line );
	}
}
add_action( 'send_headers', 'md_site_guards_headers', 1 );

/**
 * Removes the X-Pingback header: pingback is a use of XML-RPC, and XML-RPC
 * is off.
 *
 * @param array<string, string> $headers The headers WordPress is about to send.
 * @return array<string, string> The headers without the pingback one.
 */
function md_site_guards_remove_pingback( array $headers ): array {
	unset( $headers['X-Pingback'] );
	return $headers;
}
add_filter( 'wp_headers', 'md_site_guards_remove_pingback' );

/**
 * Whether an `author` query parameter is a number, the form the login-name
 * enumeration `?author=1` takes: WordPress answers it with a redirect to the
 * author archive, whose URL carries the login name.
 *
 * @param string $author The raw `author` parameter.
 * @return bool Whether it is a number.
 */
function md_site_guards_is_author_enumeration( string $author ): bool {
	return '' !== $author && is_numeric( $author );
}

/**
 * Whether a request path is the legacy author archive: the author base at the
 * site root, followed by a name.
 *
 * `$request` is `WP::$request` — the path WordPress already resolved, with the
 * home path and the query string taken off, so this predicate needs no
 * superglobal and no `home_url()` of its own.
 *
 * The shape exists because the author permastruct follows the site's front
 * (`$wp_rewrite->front . $author_base . '/%author%'`). With the journal's
 * `/blog/%postname%/` the archive lives at `/blog/author/<name>/`, and the
 * older `/author/<name>/` — the address every WordPress site had, and the one
 * written into whatever linked to it — matches no rewrite rule at all and
 * falls through to a 404 that is WordPress's to shape, not this guard's.
 *
 * Only a root-level segment counts. `/authors/alice` and `/pattern/author/`
 * are not this door, and a nested path under the base is: the base is a
 * reserved permastruct and a page cannot live there beside the archive. The
 * predicate therefore cannot mistake an unrelated path for an author.
 *
 * @param string $request     The request path relative to the site root, no slashes at either end.
 * @param string $author_base The author base in use (`$wp_rewrite->author_base`).
 * @return bool Whether it is an author archive under the legacy base.
 */
function md_site_guards_is_legacy_author_path( string $request, string $author_base = 'author' ): bool {
	$path = trim( $request, '/' );
	$base = trim( $author_base, '/' );
	if ( '' === $path || '' === $base ) {
		return false;
	}
	if ( ! str_starts_with( $path, $base . '/' ) ) {
		return false;
	}
	return '' !== trim( substr( $path, strlen( $base ) + 1 ), '/' );
}

/**
 * Closes the front end's doors to login-name enumeration besides the REST
 * list: the numeric `?author` parameter, and the author archives — by name as
 * well as resolved. A site with no public author pages has no use for any of
 * them, and the login names they reveal are the first half of a password
 * attack.
 *
 * `is_author()` alone is not enough for the archives: WordPress answers a
 * non-existent author with a 404 whose flag reset clears is_author() before
 * this hook runs (WP::handle_404 → WP_Query::set_404() → init_query_flags(),
 * measured on the local instance, 2026-10-24), so the `author_name` query
 * variable — which the 404 leaves in place — is what catches that shape.
 *
 * Neither of those sees the *legacy* `/author/<name>/` address once the site's
 * front moved the archive to `/blog/author/<name>/`: WordPress matches no rule
 * there, so no author query variable is ever set and the request dies in
 * WP::handle_404. Such a request is recognised by its path instead
 * (`md_site_guards_is_legacy_author_path`) and answered with the same 301 the
 * other doors get — the response must not depend on whether the name exists,
 * which is the one thing an enumeration reads.
 */
function md_site_guards_block_author_enumeration(): void {
	// filter_input reads the request without touching the superglobals; the
	// value only decides whether the request is redirected to the home page —
	// there is nothing to forge and no state to protect.
	$author  = filter_input( INPUT_GET, 'author' );
	$archive = is_author() || '' !== (string) get_query_var( 'author_name' );
	if ( ! $archive ) {
		// WP::$request is the path after WordPress stripped the home path and
		// the query string; on this hook it is always set (WP::main parsed the
		// request before template_redirect fired).
		global $wp, $wp_rewrite;
		$request = $wp instanceof WP ? (string) $wp->request : '';
		$base    = $wp_rewrite instanceof WP_Rewrite ? (string) $wp_rewrite->author_base : 'author';
		$archive = md_site_guards_is_legacy_author_path( $request, $base );
	}
	if ( ( is_string( $author ) && md_site_guards_is_author_enumeration( $author ) ) || $archive ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'md_site_guards_block_author_enumeration', 1 );

/**
 * Whether a REST route is the users resource: the collection or one user.
 *
 * @param string $route The route the request names.
 * @return bool Whether it is under /wp/v2/users.
 */
function md_site_guards_is_users_route( string $route ): bool {
	return 1 === preg_match( '#^/wp/v2/users(?:/|$)#i', $route );
}

/**
 * Keeps the REST user list closed to everyone but the people who may list
 * users: anonymous `/wp/v2/users` enumerates login names, which is the first
 * half of a password attack. The site has no customer accounts, so the names
 * behind this door are the staff's.
 *
 * @param mixed          $result  The pre-dispatch result, untouched unless this guard refuses.
 * @param WP_REST_Server $server  The server handling the request.
 * @param mixed          $request The request being dispatched (checked below: the filter's shape is wp-includes' to keep, not this guard's to trust).
 * @return mixed
 */
function md_site_guards_rest_users( $result, $server, $request ) {
	unset( $server );
	if ( ! $request instanceof WP_REST_Request ) {
		return $result;
	}
	if ( md_site_guards_is_users_route( $request->get_route() ) && ! current_user_can( 'list_users' ) ) {
		return new WP_Error( 'rest_forbidden', 'Access denied.', array( 'status' => 403 ) );
	}
	return $result;
}
add_filter( 'rest_pre_dispatch', 'md_site_guards_rest_users', 10, 3 );
