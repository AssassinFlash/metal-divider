<?php
/**
 * Tests for the site launcher script tag.
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

namespace Md\Mu\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The gate decides which responses carry the launcher: staging front-end
 * pages yes, chat and admin surfaces no, production no until its cutover
 * ticket opts in. The URL pins the versioned asset path the browser loads.
 *
 * @covers ::md_chat_launcher_should_print
 * @covers ::md_chat_launcher_script_url
 * @covers ::md_chat_launcher_print
 */
final class ChatLauncherTest extends TestCase {

	/**
	 * Prints on staging front-end pages.
	 */
	public function test_prints_on_staging_front_end(): void {
		self::assertTrue( md_chat_launcher_should_print( 'staging.metal-divider.com', '/pattern/agave/', false, false ) );
		self::assertTrue( md_chat_launcher_should_print( 'STAGING.metal-divider.com', '/', false, false ) );
		self::assertTrue( md_chat_launcher_should_print( 'staging.metal-divider.com', '/collections/laser-cut/', false, false ) );
	}

	/**
	 * Stays off the chat surface, admin screens, feeds and login.
	 */
	public function test_skips_chat_admin_and_feeds(): void {
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/chat/index.php/chat/chatwidget', false, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/chat/', false, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/', true, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/', false, true ) );
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/wp-login.php', false, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'staging.metal-divider.com', '/wp-admin/admin-ajax.php', false, false ) );
	}

	/**
	 * Production stays out until its cutover ticket opts in.
	 */
	public function test_skips_hosts_not_yet_opted_in(): void {
		self::assertFalse( md_chat_launcher_should_print( 'metal-divider.com', '/pattern/agave/', false, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'www.metal-divider.com', '/', false, false ) );
		self::assertFalse( md_chat_launcher_should_print( 'example.com', '/', false, false ) );
		self::assertTrue( md_chat_launcher_should_print( 'localhost', '/', false, false ) );
	}

	/**
	 * A host may be added by filter for a future production cutover.
	 */
	public function test_host_list_is_filterable(): void {
		$adder = static fn ( array $hosts ): array => array_merge( $hosts, array( 'metal-divider.com' ) );
		add_filter( 'md_chat_launcher_hosts', $adder );
		self::assertTrue( md_chat_launcher_should_print( 'metal-divider.com', '/', false, false ) );
		remove_filter( 'md_chat_launcher_hosts', $adder );
		self::assertFalse( md_chat_launcher_should_print( 'metal-divider.com', '/', false, false ) );
	}

	/**
	 * The script is the extension asset itself; the version travels in the
	 * enqueue call, not a hand-built query string.
	 */
	public function test_script_url_is_the_extension_asset(): void {
		self::assertSame( '/chat/extension/mdvisitor/design/mdvisitortheme/js/mdvisitor-launcher.js', md_chat_launcher_script_url() );
	}

	/**
	 * The script is enqueued on wp_enqueue_scripts.
	 */
	public function test_enqueue_hook_is_registered(): void {
		$hooks = $GLOBALS['md_mu_test_hooks']['wp_enqueue_scripts'] ?? array();
		$found = false;
		foreach ( $hooks as $entry ) {
			if ( 'md_chat_launcher_enqueue' === $entry[0] ) {
				$found = true;
			}
		}
		self::assertTrue( $found, 'wp_enqueue_scripts carries md_chat_launcher_enqueue' );
	}

	/**
	 * On a staging page request the enqueue fires once, deferred, in the
	 * footer, pointing at the extension asset with the pinned version.
	 */
	public function test_enqueue_fires_on_a_staging_page_request(): void {
		$_SERVER['HTTP_HOST']  = 'staging.metal-divider.com';
		$_SERVER['REQUEST_URI'] = '/pattern/agave/';
		$GLOBALS['md_mu_test_enqueues'] = array();
		$GLOBALS['md_mu_test_is_admin'] = false;
		$GLOBALS['md_mu_test_is_feed']  = false;

		md_chat_launcher_enqueue();

		self::assertCount( 1, $GLOBALS['md_mu_test_enqueues'] );
		$enqueue = $GLOBALS['md_mu_test_enqueues'][0];
		self::assertSame( 'md-chat-launcher', $enqueue['handle'] );
		self::assertSame( md_chat_launcher_script_url(), $enqueue['src'] );
		self::assertSame( MD_CHAT_LAUNCHER_VERSION, $enqueue['ver'] );
		self::assertTrue( $enqueue['args']['in_footer'] );
		self::assertSame( 'defer', $enqueue['args']['strategy'] );
	}

	/**
	 * A non-staging host produces no enqueue at all.
	 */
	public function test_enqueue_stays_silent_off_staging(): void {
		$_SERVER['HTTP_HOST']  = 'metal-divider.com';
		$_SERVER['REQUEST_URI'] = '/pattern/agave/';
		$GLOBALS['md_mu_test_enqueues'] = array();

		md_chat_launcher_enqueue();

		self::assertSame( array(), $GLOBALS['md_mu_test_enqueues'] );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $GLOBALS['md_mu_test_enqueues'], $GLOBALS['md_mu_test_is_admin'], $GLOBALS['md_mu_test_is_feed'] );
	}
}
