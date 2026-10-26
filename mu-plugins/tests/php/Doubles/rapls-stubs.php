<?php
/**
 * Static-analysis stubs for Rapls (wordpress.org package rapls-ai-chatbot,
 * the MVP's knowledge layer): it supplies RAPLSAICH_Search_Engine at
 * runtime, is neither in the WordPress stubs nor on Packagist, and the test
 * runtime needs no declaration (the knowledge tests alias their own fake to
 * the class name). phpstan reads this file via scanFiles; the test
 * bootstrap never loads it. Only the surface the endpoint touches is
 * declared, with the signatures of v1.21.4 (includes/crawler/
 * class-search-engine.php:46,122,961, verified against the package source).
 *
 * @package Md\Mu
 */

declare( strict_types = 1 );

// phpcs:ignoreFile -- third-party symbol declaration for static analysis;
// the WordPress prefix and naming sniffs do not apply to Rapls's own names.

namespace {
	class RAPLSAICH_Search_Engine {

		/**
		 * Sets what the search covers.
		 *
		 * @param string[] $categories    Knowledge categories to include (empty = all).
		 * @param bool     $use_crawl     Whether to search the site crawl index.
		 * @param bool     $use_knowledge Whether to search the knowledge base.
		 * @return void
		 */
		public function set_bot_filters( array $categories = array(), bool $use_crawl = true, bool $use_knowledge = true ): void {}

		/**
		 * Searches for the query.
		 *
		 * @param string $query The question.
		 * @param int    $limit How many hits were asked for.
		 * @return array<int, array<string, mixed>> The hits.
		 */
		public function search( string $query, int $limit = 3 ): array {
			return array();
		}

		/**
		 * Builds the context the model answers from.
		 *
		 * @param array<int, array<string, mixed>> $results    The hits.
		 * @param int                              $max_length The context budget.
		 * @param string                           $query      The question.
		 * @return string The context.
		 */
		public function build_context( array $results, int $max_length = 50000, string $query = '' ): string {
			return '';
		}
	}
}
