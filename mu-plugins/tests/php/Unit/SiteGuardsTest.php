<?php
/**
 * Tests for the site guards.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The guards' header strings and interception logic, outside WordPress: the
 * five header lines with their whole values (the CSP's whitelist pinned
 * exactly), the predicates the two interception guards decide by, the pingback
 * removal, and the hooks the file attaches itself with (the bootstrap's
 * stand-ins recorded them at its load).
 *
 * @covers ::md_site_guards_header_lines
 * @covers ::md_site_guards_is_author_enumeration
 * @covers ::md_site_guards_is_legacy_author_path
 * @covers ::md_site_guards_is_users_route
 * @covers ::md_site_guards_remove_pingback
 */
final class SiteGuardsTest extends TestCase {

	/**
	 * The five header lines, exactly: every header value is pinned here, and
	 * the CSP with it — Turnstile's scripts, frames and connections, the
	 * same-origin frame the chat card needs, no CookieYes host, the inline
	 * styles, no unsafe-eval, the closed
	 * object/base/form/frame-ancestors — so a whitelist change is a test change.
	 */
	public function test_the_header_lines_are_the_sites_policy(): void {
		self::assertSame(
			array(
				'Strict-Transport-Security: max-age=31536000; includeSubDomains',
				'X-Frame-Options: SAMEORIGIN',
				'X-Content-Type-Options: nosniff',
				'Referrer-Policy: strict-origin-when-cross-origin',
				"Content-Security-Policy: default-src 'self'; script-src 'self' 'inline-speculation-rules' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self' https://challenges.cloudflare.com; frame-src 'self' https://challenges.cloudflare.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
			),
			md_site_guards_header_lines()
		);
	}

	/**
	 * Turnstile keeps its three sources, and that is the only external
	 * loosening: no CookieYes host, scripts stay free of unsafe-inline and
	 * unsafe-eval, and unsafe-inline remains the style-src exception.
	 */
	public function test_turnstile_connect_does_not_loosen_scripts(): void {
		$csp        = '';
		$directives = array();
		foreach ( md_site_guards_header_lines() as $line ) {
			if ( str_starts_with( $line, 'Content-Security-Policy: ' ) ) {
				$csp = substr( $line, strlen( 'Content-Security-Policy: ' ) );
			}
		}
		self::assertNotSame( '', $csp );

		foreach ( explode( ';', $csp ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', $part );
			self::assertIsArray( $tokens );
			$name                = array_shift( $tokens );
			$directives[ $name ] = $tokens;
		}

		self::assertStringNotContainsString( 'cookieyes', strtolower( $csp ) );
		foreach ( array( 'script-src', 'frame-src', 'connect-src' ) as $directive ) {
			self::assertContains( 'https://challenges.cloudflare.com', $directives[ $directive ], $directive );
		}
		self::assertContains( "'unsafe-inline'", $directives['style-src'] );
		foreach ( $directives as $name => $tokens ) {
			self::assertNotContains( "'unsafe-eval'", $tokens, $name );
			if ( 'style-src' === $name ) {
				continue;
			}
			self::assertNotContains( "'unsafe-inline'", $tokens, $name );
		}
	}

	/**
	 * The headers go out on `send_headers`, before anything else on that hook
	 * can produce output; the author block likewise runs first on
	 * `template_redirect`, ahead of WordPress's own canonical redirect that
	 * would turn `?author=1` into the author archive's URL.
	 */
	public function test_the_file_attaches_itself_to_the_early_hooks(): void {
		$hooks = $this->recorded( 'md_mu_test_hooks' );

		self::assertContains( array( 'md_site_guards_headers', 1, 1 ), $hooks['send_headers'] ?? array() );
		self::assertContains( array( 'md_site_guards_block_author_enumeration', 1, 1 ), $hooks['template_redirect'] ?? array() );
	}

	/**
	 * XML-RPC is off through the filter WordPress documents for it, and the
	 * RSD link (the endpoint's address in every page's head) is gone with it.
	 */
	public function test_xmlrpc_is_off_and_the_rsd_link_removed(): void {
		$hooks    = $this->recorded( 'md_mu_test_hooks' );
		$removed  = $this->recorded( 'md_mu_test_removed' );
		$expected = array( '__return_false', 10, 1 );

		self::assertContains( $expected, $hooks['xmlrpc_enabled'] ?? array() );
		self::assertContains( array( 'rsd_link', 10 ), $removed['wp_head'] ?? array() );
	}

	/**
	 * The pingback header is filtered out of the response, and the rest of
	 * the headers survive the filter untouched.
	 */
	public function test_the_pingback_header_is_removed_and_the_rest_kept(): void {
		$headers = array(
			'X-Pingback'   => 'https://example.org/xmlrpc.php',
			'Content-Type' => 'text/html; charset=UTF-8',
		);

		$kept = md_site_guards_remove_pingback( $headers );

		self::assertArrayNotHasKey( 'X-Pingback', $kept );
		self::assertSame( 'text/html; charset=UTF-8', $kept['Content-Type'] );
	}

	/**
	 * A number in the `author` parameter is an enumeration attempt; a name,
	 * an empty value and a missing one are not. The names are not this door's
	 * form: WordPress answers them with a 404, not with an author archive.
	 */
	public function test_only_a_numeric_author_parameter_is_enumeration(): void {
		self::assertTrue( md_site_guards_is_author_enumeration( '1' ) );
		self::assertTrue( md_site_guards_is_author_enumeration( '42' ) );
		self::assertTrue( md_site_guards_is_author_enumeration( '0' ) );

		self::assertFalse( md_site_guards_is_author_enumeration( 'admin' ) );
		self::assertFalse( md_site_guards_is_author_enumeration( '' ) );
	}

	/**
	 * The legacy archive is the author base at the site root, followed by a
	 * name. `$request` is WP::$request, which carries no home path and no
	 * query string, so `/blog/author/<name>/` (the site's *current* archive)
	 * is `blog/author/<name>` here and is not this predicate's business —
	 * WordPress's own author query variables catch that one.
	 */
	public function test_the_legacy_author_path_is_the_base_at_the_root(): void {
		self::assertTrue( md_site_guards_is_legacy_author_path( 'author/local-admin' ) );
		self::assertTrue( md_site_guards_is_legacy_author_path( 'author/nobody-here' ) );
		// Trailing slashes are not part of the path WordPress parses.
		self::assertTrue( md_site_guards_is_legacy_author_path( 'author/local-admin/' ) );
		// A nested path under the base is still the archive: the base is a
		// reserved permastruct and no page can live there beside it.
		self::assertTrue( md_site_guards_is_legacy_author_path( 'author/team/alice' ) );
	}

	/**
	 * Everything else is left to WordPress: other roots, names that merely
	 * start with the base, the bare base, the empty path, and the current
	 * archive under the site's front.
	 */
	public function test_unrelated_paths_are_not_the_legacy_author_path(): void {
		self::assertFalse( md_site_guards_is_legacy_author_path( 'authors/alice' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'authorizer/alice' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'author' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( '' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( '/' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'blog/author/local-admin' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'pattern/author' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'blog' ) );
	}

	/**
	 * The base is read from the rewrite rules, not assumed: a site that renamed
	 * it closes the archives at its own base, and the default stays the
	 * legacy `author` where WordPress's own default is.
	 */
	public function test_the_legacy_author_path_follows_the_author_base(): void {
		self::assertTrue( md_site_guards_is_legacy_author_path( 'writer/alice', 'writer' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'author/alice', 'writer' ) );
		// Bare and slashed spellings of the base mean the same thing.
		self::assertTrue( md_site_guards_is_legacy_author_path( 'author/alice', '/author/' ) );
		// An empty base is not a door: everything would look like an archive.
		self::assertFalse( md_site_guards_is_legacy_author_path( 'author/alice', '' ) );
		self::assertFalse( md_site_guards_is_legacy_author_path( 'anything/at/all', '/' ) );
	}

	/**
	 * The users resource — the collection, one user, any casing — is the REST
	 * door that closes; everything else, including routes that merely start
	 * like it, is left to WordPress.
	 */
	public function test_the_users_route_is_recognized_and_only_it(): void {
		self::assertTrue( md_site_guards_is_users_route( '/wp/v2/users' ) );
		self::assertTrue( md_site_guards_is_users_route( '/wp/v2/users/1' ) );
		self::assertTrue( md_site_guards_is_users_route( '/WP/V2/Users' ) );

		self::assertFalse( md_site_guards_is_users_route( '/wp/v2/userz' ) );
		self::assertFalse( md_site_guards_is_users_route( '/wp/v2/posts' ) );
		self::assertFalse( md_site_guards_is_users_route( '/md/v1/users' ) );
	}

	/**
	 * What the bootstrap's hook stand-ins recorded when the plugins loaded.
	 *
	 * @param string $name The global's name.
	 *
	 * @return array<string, array<int, array<int, int|string>>>
	 */
	private function recorded( string $name ): array {
		$recorded = $GLOBALS[ $name ] ?? array();

		return is_array( $recorded ) ? $recorded : array();
	}
}
