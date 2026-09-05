<?php
/**
 * Ties fetching a page (ScraperEngineInterface) to running every
 * extractor and merging the result into one ProductData DTO. Shared by
 * AnalyzeController (analyze-only) and the queue Dispatcher (analyze +
 * import), so both go through the exact same extraction logic.
 *
 * @package Uws\Pipeline
 */

namespace Uws\Pipeline;

use Uws\Dto\ProductData;
use Uws\Extractors\BreadcrumbExtractor;
use Uws\Extractors\DomExtractor;
use Uws\Extractors\ImageExtractor;
use Uws\Extractors\JsonLdExtractor;
use Uws\Extractors\MetaExtractor;
use Uws\Extractors\ProductDataMerger;
use Uws\Extractors\ProductExtractorInterface;
use Uws\Extractors\SpecificationExtractor;
use Uws\Scraper\ScraperEngineInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtractionPipeline {

	/** @var ScraperEngineInterface */
	private $engine;

	/** @var ProductExtractorInterface[] */
	private $extractors;

	/** @var ProductDataMerger */
	private $merger;

	/**
	 * @param ScraperEngineInterface            $engine
	 * @param ProductExtractorInterface[]|null  $extractors Defaults to the built-in
	 *                                                       non-AI extractors (Stages 4-6);
	 *                                                       Stage 12 appends AiExtractor
	 *                                                       via the 'uws_extractors' filter below.
	 */
	public function __construct( ScraperEngineInterface $engine, array $extractors = null ) {
		$this->engine     = $engine;
		$this->extractors = null !== $extractors ? $extractors : $this->default_extractors();
		$this->merger     = new ProductDataMerger();
	}

	/**
	 * @return ProductExtractorInterface[]
	 */
	private function default_extractors() {
		$extractors = array(
			new JsonLdExtractor(),
			new MetaExtractor(),
			new SpecificationExtractor(),
			new ImageExtractor(),
			new BreadcrumbExtractor(),
			new DomExtractor(),
		);

		/**
		 * Lets Stage 12's AiExtractor (and any custom extractor) join the
		 * pipeline without this class needing to know about it directly.
		 *
		 * @param ProductExtractorInterface[] $extractors
		 */
		return apply_filters( 'uws_extractors', $extractors );
	}

	/**
	 * @param string $url
	 * @param array<string,mixed> $options Passed through to the scraper engine.
	 * @return array{data: ProductData, page: array<string,mixed>}|\WP_Error
	 */
	public function analyze( $url, array $options = array() ) {
		$page = $this->engine->fetch_page( $url, $options );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$data             = $this->merger->merge( $page, $this->extractors );
		$data->source_url = ! empty( $page['final_url'] ) ? $page['final_url'] : $url;

		return array( 'data' => $data, 'page' => $page );
	}
}
