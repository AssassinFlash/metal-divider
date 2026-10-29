<?php
/**
 * Every old page URL still lands somewhere, with a 301.
 *
 * @package MetalDivider\Site\Seo
 */

namespace MetalDivider\Site\Tests\Unit\Seo;

use MetalDivider\Site\Seo\Config;
use MetalDivider\Site\Tests\TestCase;

/**
 * Guards the `/pages/*` redirect table.
 *
 * The old Shopify site served its content pages under `/pages/<handle>`. The
 * migration rewrote them at the site root and installed a single redirect for
 * the `pages` prefix — but SEOPress matches a redirect's post title as an
 * exact path, so that entry only covered `/pages` itself. Measured on
 * production 2026-10-29: of the 24 old `/pages/<handle>` URLs that answered
 * 200, 11 redirected and **13 returned 404**, including every family page,
 * `faqs`, `about-us`, `manufacturing-process` and `project-gallery`.
 *
 * The comment beside the table claimed the prefix "sends the prefix home",
 * which is why the gap survived: the prose described an intent the mechanism
 * never implemented.
 */
final class PageRedirectTest extends TestCase {

	/**
	 * The old handles that must redirect, and where each one lands.
	 *
	 * Taken from the old site's own page list (`data/shopify-public/pages.json`,
	 * 25 handles) crossed with what production answered: every handle below
	 * returned 200 on metalpartition.com and 404 on metal-divider.com before
	 * this table existed.
	 *
	 * @return array<string, string> Old path without a leading slash => target.
	 */
	private function expected(): array {
		return array(
			'pages/about-us'                          => '/about-us/',
			'pages/chinese-style-artistic-screens'    => '/chinese-style-artistic-screens/',
			'pages/custom-metal-divider'              => '/custom-metal-divider-screens/',
			'pages/custom-metal-divider-screens'      => '/custom-metal-divider-screens/',
			'pages/expanded-metal-panels'             => '/expanded-metal-panels/',
			'pages/faqs'                              => '/faqs/',
			'pages/glass-hybrid-screens'              => '/glass-hybrid-screens/',
			'pages/laser-cut-geometric-metal-screens' => '/laser-cut-geometric-metal-screens/',
			'pages/laser-cut-panels'                  => '/laser-cut-panels/',
			'pages/manufacturing-process'             => '/manufacturing-process/',
			'pages/outdoor-privacy-landscape-screens' => '/outdoor-privacy-landscape-screens/',
			'pages/perforated-panels'                 => '/perforated-panels/',
			'pages/project-gallery'                   => '/project-gallery/',
			'pages/stainless-steel-screens'           => '/stainless-steel-screens/',
		);
	}

	/**
	 * Each of the thirteen formerly-404 URLs now has a redirect to its page.
	 */
	public function test_every_old_content_page_has_a_redirect(): void {
		$table = $this->redirect_table();

		foreach ( $this->expected() as $from => $to ) {
			$this->assertArrayHasKey(
				$from,
				$table,
				'/' . $from . ' answered 200 on the old site; without a redirect it is a 404 on this one'
			);
			$this->assertSame( $to, $table[ $from ], '/' . $from . ' must land on the page that carries its content' );
		}
	}

	/**
	 * The set is exactly the old handles, so nothing was invented.
	 *
	 * A redirect to a path this site does not serve is a second 404 with extra
	 * steps, and it hides the first.
	 */
	public function test_every_target_is_a_page_this_site_serves(): void {
		// The old site redirected `pages/custom-metal-divider` to
		// `pages/custom-metal-divider-screens`; the two handles are the same
		// page under two names, so only one of them lands on a matching path.
		$renamed = array( 'pages/custom-metal-divider' => '/custom-metal-divider-screens/' );

		foreach ( $this->redirect_table() as $from => $to ) {
			if ( 'pages' === $from ) {
				continue;
			}
			$expected = $renamed[ $from ]
				?? '/' . substr( $from, strlen( 'pages/' ) ) . '/';
			$this->assertSame(
				$expected,
				$to,
				'/' . $from . ' should land on the page that carries its content'
			);
		}
	}

	/**
	 * The prefix entry survives, so `/pages` itself still redirects.
	 */
	public function test_the_bare_prefix_still_redirects_home(): void {
		$table = $this->redirect_table();

		$this->assertArrayHasKey( 'pages', $table );
		$this->assertSame( '/', $table['pages'] );
	}

	/**
	 * Reads the private table out of the class, so the test cannot drift from it.
	 *
	 * @return array<string, string> Redirect title => target.
	 */
	private function redirect_table(): array {
		$class    = new \ReflectionClass( Config::class );
		$constant = $class->getReflectionConstant( 'REDIRECT_TARGETS' );
		$this->assertNotFalse( $constant, 'the redirect table must exist' );
		$value = $constant->getValue();
		$this->assertIsArray( $value, 'the redirect table must be an array' );

		return $value;
	}
}
