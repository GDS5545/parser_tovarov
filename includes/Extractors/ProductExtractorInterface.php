<?php
/**
 * Contract for one extraction strategy (JSON-LD, meta tags, embedded JSON,
 * DOM heuristics, specification tables, variations, images, breadcrumbs,
 * or AI — spec §63). Each extractor reads the same raw page payload
 * returned by ScraperEngineInterface::fetch_page() and fills in only the
 * fields it is confident about; ProductDataMerger combines the results.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ProductExtractorInterface {

	/**
	 * @return string Stable identifier used as the "source" on merged fields,
	 *                e.g. "json_ld", "dom", "ai".
	 */
	public function get_name();

	/**
	 * @return int Merge priority; higher wins when two extractors disagree
	 *             on the same field (JSON-LD price should outrank a DOM
	 *             text-scrape of the same price, per spec §64).
	 */
	public function get_priority();

	/**
	 * @param array<string,mixed> $page Raw payload from ScraperEngineInterface::fetch_page().
	 * @return bool Whether this extractor found anything usable on this page
	 *              (cheap pre-check so the merger can skip empty extractors).
	 */
	public function supports( array $page );

	/**
	 * @param array<string,mixed> $page Raw payload from ScraperEngineInterface::fetch_page().
	 * @return ProductData Partial DTO; unset fields are left at their defaults
	 *                      and MUST NOT be assigned a confidence score.
	 */
	public function extract( array $page );
}
