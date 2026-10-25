<?php
/**
 * The multi-product fixture's live read counters.
 *
 * @package MetalDivider\Site
 */

declare( strict_types = 1 );

namespace MetalDivider\Site\Tests;

/**
 * Counts the reads a shortcode fixture's doubles answer, for the tests that
 * prove a slot resolves once rather than once per asking.
 *
 * A typed object rather than a bare stdClass: the memoization tests assert on
 * these members, so naming them here makes a renamed or dropped counter a
 * static-analysis error instead of a test that quietly reads 0. The instance
 * is shared with the closures the fixtures install, so it stays live for as
 * long as the test keeps the fixture.
 */
final class GalleryReadCounter {

	/**
	 * How many times a product double answered get_gallery_image_ids().
	 *
	 * @var int
	 */
	public int $gallery = 0;

	/**
	 * How many times an attachment's `_md_role` was read.
	 *
	 * @var int
	 */
	public int $class_reads = 0;

	/**
	 * How many times an attachment's alt text was read.
	 *
	 * @var int
	 */
	public int $alt_reads = 0;
}
