<?php
/**
 * Ties fetching a page (ScraperEngineInterface) to running every
 * extractor, merging the result into one ProductData DTO, and — only if
 * important fields are still missing/low-confidence afterward — falling
 * back to AI extraction (Stage 12, spec §21-23). Shared by
 * AnalyzeController (analyze-only) and the queue Dispatcher (analyze +
 * import), so both go through the exact same extraction logic.
 *
 * @package Uws\Pipeline
 */

namespace Uws\Pipeline;

use Uws\Ai\AiExtractor;
use Uws\Dto\ProductData;
use Uws\Extractors\BreadcrumbExtractor;
use Uws\Extractors\DomExtractor;
use Uws\Extractors\ImageExtractor;
use Uws\Extractors\JsonLdExtractor;
use Uws\Extractors\MetaExtractor;
use Uws\Extractors\ProductDataMerger;
use Uws\Extractors\ProductExtractorInterface;
use Uws\Extractors\SpecificationExtractor;
use Uws\Extractors\VariationExtractor;
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

	/** @var AiExtractor|null */
	private $ai_extractor;

	/**
	 * @param ScraperEngineInterface            $engine
	 * @param ProductExtractorInterface[]|null  $extractors  Defaults to the built-in
	 *                                                        non-AI extractors (Stages 4-6, 9).
	 * @param AiExtractor|null                  $ai_extractor Defaults to AiExtractor::from_settings()
	 *                                                        (null if AI is disabled/unconfigured).
	 */
	public function __construct( ScraperEngineInterface $engine, array $extractors = null, AiExtractor $ai_extractor = null ) {
		$this->engine       = $engine;
		$this->extractors   = null !== $extractors ? $extractors : $this->default_extractors();
		$this->merger       = new ProductDataMerger();
		$this->ai_extractor = null !== $ai_extractor ? $ai_extractor : AiExtractor::from_settings( get_option( 'uws_settings', array() ) );
	}

	/**
	 * @return ProductExtractorInterface[]
	 */
	private function default_extractors() {
		$extractors = array(
			new JsonLdExtractor(),
			new MetaExtractor(),
			new SpecificationExtractor(),
			new VariationExtractor(),
			new ImageExtractor(),
			new BreadcrumbExtractor(),
			new DomExtractor(),
		);

		/**
		 * Lets a custom extractor join the uniform (non-AI) pipeline
		 * without this class needing to know about it directly. AI
		 * extraction is not part of this list — see the class docblock.
		 *
		 * @param ProductExtractorInterface[] $extractors
		 */
		return apply_filters( 'uws_extractors', $extractors );
	}

	/**
	 * @param string $url
	 * @param array<string,mixed> $options Passed through to the scraper engine.
	 * @return array{data: ProductData, page: array<string,mixed>, ai_used?: bool, ai_error?: string}|\WP_Error
	 */
	public function analyze( $url, array $options = array() ) {
		$page = $this->engine->fetch_page( $url, $options );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$data             = $this->merger->merge( $page, $this->extractors );
		$data->source_url = ! empty( $page['final_url'] ) ? $page['final_url'] : $url;

		$result = array( 'data' => $data, 'page' => $page );

		if ( $this->ai_extractor ) {
			$fallback = $this->ai_extractor->fill_gaps( $page, $data );
			$result['data']     = $fallback['data'];
			$result['ai_used']  = $fallback['used_ai'];
			$result['ai_error'] = $fallback['error'];
		}

		return $result;
	}
}
