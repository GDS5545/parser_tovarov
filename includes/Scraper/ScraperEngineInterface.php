<?php
/**
 * Abstraction over "fetch a page with a real browser and return its raw
 * structured contents" so the engine can be swapped (spec §62) without
 * touching any extractor or the importer. The current (Stage 3)
 * implementation, PlaywrightHttpEngine, talks to the Node.js worker over
 * HTTP; a lighter HttpEngine for static pages can implement the same
 * contract later.
 *
 * @package Uws\Scraper
 */

namespace Uws\Scraper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ScraperEngineInterface {

	/**
	 * Fetches a single page and returns its raw rendered contents plus
	 * whatever structured data the browser could observe directly
	 * (JSON-LD blocks it found, resolved image URLs, etc.). Extractors
	 * then work over this raw payload — the engine itself does not decide
	 * what a "product" looks like.
	 *
	 * @param string               $url     Already SSRF-validated URL.
	 * @param array<string,mixed>  $options wait_until, extra_delay_ms, viewport,
	 *                                      locale, timezone, user_agent, cookies,
	 *                                      close_popups, screenshot (bool).
	 * @return array<string,mixed>|\WP_Error {
	 *     @type string $html            Fully rendered DOM HTML.
	 *     @type string $final_url       URL after redirects.
	 *     @type int    $status_code
	 *     @type array  $json_ld_blocks  Raw JSON-LD script contents found by the browser.
	 *     @type array  $console_errors
	 *     @type array  $network_errors
	 *     @type string|null $screenshot_path Set only when debug mode is on.
	 * }
	 */
	public function fetch_page( $url, array $options = array() );

	/**
	 * Fetches a category/listing page, following pagination or clicking a
	 * "Load more" control up to $max_pages, and returns the discovered
	 * product URLs (spec §19–20).
	 *
	 * @param string                    $url
	 * @param array<string,mixed>       $options max_pages, pagination_strategy ('query'|'path'|'load_more'|'infinite_scroll').
	 * @param array<string,mixed>|null  $prefetched_page A page already
	 *        fetched for this exact $url (see fetch_page()) — skips
	 *        fetching page 1 again. The category-tree crawler
	 *        (Dispatcher::process_category_job(), spec §19) always has one
	 *        on hand already, since it must fetch the URL anyway to decide
	 *        whether it's a product page before calling this; without this,
	 *        a large listing page would be fetched and held in memory
	 *        twice at once for no reason.
	 * @return string[]|\WP_Error
	 */
	public function discover_product_urls( $url, array $options = array(), array $prefetched_page = null );

	/**
	 * @return bool Whether the worker is reachable and reports itself healthy.
	 */
	public function health_check();
}
