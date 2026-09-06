<?php
/**
 * Consumes the jobs QueueRunner::run_due_jobs() hands to 'uws_queue_tick'.
 * A "single" job is analyzed and imported through the same
 * ExtractionPipeline + ProductImporter used by the synchronous
 * /analyze + /import REST flow. A "category" job (spec §19) fetches its
 * URL and classifies it: if it turns out to already be a single product's
 * page, it is analyzed and imported right there (no different from a
 * "single" job, just discovered rather than pasted directly); otherwise
 * it is treated as a listing/category page and every link found on it
 * becomes its own new "category" job one level deeper, so a whole nested
 * catalog (category → sub-category → … → product) can be queued from one
 * root URL rather than requiring one "category" job per leaf category.
 *
 * @package Uws\Queue
 */

namespace Uws\Queue;

use Uws\Database\LogRepository;
use Uws\Database\ProductLinkRepository;
use Uws\Dto\ProductData;
use Uws\Pipeline\ExtractionPipeline;
use Uws\Support\ImporterArgs;
use Uws\Woocommerce\ProductImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dispatcher {

	/** @var LogRepository */
	private $logs;

	/** @var ProductLinkRepository */
	private $links;

	/** @var ProductImporter */
	private $importer;

	public function __construct( LogRepository $logs = null, ProductLinkRepository $links = null, ProductImporter $importer = null ) {
		$this->logs     = $logs ?: new LogRepository();
		$this->links    = $links ?: new ProductLinkRepository();
		$this->importer = $importer ?: new ProductImporter();
	}

	/**
	 * @param array<int,object> $due_jobs
	 * @param JobRepository      $repository
	 */
	public function handle_tick( array $due_jobs, JobRepository $repository ) {
		if ( empty( $due_jobs ) ) {
			return;
		}

		/** @var \Uws\Scraper\ScraperEngineInterface|null $engine */
		$engine = apply_filters( 'uws_scraper_engine', null );

		foreach ( $due_jobs as $job ) {
			$repository->update_status( (int) $job->id, 'processing' );

			if ( ! $engine ) {
				$this->fail(
					$repository,
					$job,
					__( 'No scraper worker is configured (Universal Scraper → Browser Settings).', 'universal-woo-scraper' )
				);
				continue;
			}

			if ( 'category' === $job->type ) {
				$this->process_category_job( $repository, $job, $engine );
			} else {
				$this->process_single_job( $repository, $job, $engine );
			}
		}
	}

	private function process_single_job( JobRepository $repository, $job, $engine ) {
		$pipeline = new ExtractionPipeline( $engine );

		$result = $pipeline->analyze( $job->url );
		if ( is_wp_error( $result ) ) {
			$this->fail( $repository, $job, $result->get_error_message() );
			return;
		}

		$this->import_and_record( $repository, $job, $result['data'] );
	}

	/**
	 * Discovers and imports an entire nested catalog starting from one
	 * listing URL (spec §19) — the case a merchant hits pasting a catalog
	 * root like example.com/catalog/ rather than one category's product
	 * grid. A listing page's own links can be either a further
	 * sub-category (needs another pass to find its products) or an actual
	 * product page (ready to import); there is no way to tell which
	 * without fetching it, so each discovered link becomes its own
	 * "category" job at depth+1 rather than being assumed to be a product.
	 * When that job is later dequeued, this same method fetches its URL,
	 * classifies it with ExtractionPipeline::looks_like_product_page(),
	 * and either imports it directly (reusing the HTML already fetched for
	 * classification — no second fetch) or expands it into its own child
	 * links. JobRepository::enqueue_if_new() dedupes by URL so a link
	 * reachable from more than one listing page (breadcrumbs, related-
	 * category widgets) is only ever queued once, which is also what keeps
	 * this bounded rather than looping.
	 */
	private function process_category_job( JobRepository $repository, $job, $engine ) {
		$payload   = json_decode( (string) $job->payload, true );
		$payload   = is_array( $payload ) ? $payload : array();
		$options   = isset( $payload['discover_options'] ) && is_array( $payload['discover_options'] ) ? $payload['discover_options'] : array();
		$depth     = isset( $payload['depth'] ) ? (int) $payload['depth'] : 0;
		$max_depth = isset( $payload['max_depth'] ) ? max( 1, min( 10, (int) $payload['max_depth'] ) ) : 5;

		$pipeline = new ExtractionPipeline( $engine );
		$page     = $engine->fetch_page( $job->url, $options );
		if ( is_wp_error( $page ) ) {
			$this->fail( $repository, $job, $page->get_error_message() );
			return;
		}

		if ( $pipeline->looks_like_product_page( $page, $job->url ) ) {
			$result = $pipeline->analyze( $job->url, $options, $page );
			if ( is_wp_error( $result ) ) {
				$this->fail( $repository, $job, $result->get_error_message() );
				return;
			}
			$this->import_and_record( $repository, $job, $result['data'] );
			return;
		}

		if ( $depth >= $max_depth ) {
			$repository->update_status(
				(int) $job->id,
				'completed',
				array( 'result' => wp_json_encode( array( 'reason' => 'max_depth_reached' ) ) )
			);
			return;
		}

		$urls = $engine->discover_product_urls( $job->url, $options );
		if ( is_wp_error( $urls ) ) {
			$this->fail( $repository, $job, $urls->get_error_message() );
			return;
		}

		$queued = 0;
		foreach ( $urls as $url ) {
			$child_payload = array(
				'depth'            => $depth + 1,
				'max_depth'        => $max_depth,
				'discover_options' => $options,
			);
			if ( $repository->enqueue_if_new( $url, 'category', $child_payload ) ) {
				$queued++;
			}
		}

		$repository->update_status(
			(int) $job->id,
			'completed',
			array( 'result' => wp_json_encode( array( 'discovered' => count( $urls ), 'queued' => $queued ) ) )
		);

		$this->logs->record(
			array(
				'job_id'      => $job->id,
				'url'         => $job->url,
				'status'      => 'completed',
				'error_message' => sprintf(
					/* translators: %1$d: links found on this listing page, %2$d: how many of those were new and got queued for crawling/import */
					__( 'Discovered %1$d links, queued %2$d for further crawling/import.', 'universal-woo-scraper' ),
					count( $urls ),
					$queued
				),
			)
		);
	}

	private function import_and_record( JobRepository $repository, $job, ProductData $data ) {
		$settings = get_option( 'uws_settings', array() );
		$existing = $this->links->find_existing( $data->source_url, $data->sku, $data->gtin, $data->mpn );

		$imported = $this->importer->import(
			$data,
			ImporterArgs::from_settings( $settings, $existing ? (int) $existing->product_id : 0 )
		);

		if ( is_wp_error( $imported ) ) {
			$this->fail( $repository, $job, $imported->get_error_message() );
			return;
		}

		$this->links->upsert(
			array(
				'source_url'    => $data->source_url,
				'source_domain' => wp_parse_url( $data->source_url, PHP_URL_HOST ),
				'source_id'     => $data->source_id,
				'gtin'          => $data->gtin,
				'mpn'           => $data->mpn,
				'sku'           => $data->sku,
				'product_id'    => $imported['product_id'],
				'content_hash'  => md5( wp_json_encode( $data->to_array() ) ),
			)
		);

		$repository->update_status(
			(int) $job->id,
			'completed',
			array( 'result' => wp_json_encode( array( 'product_id' => $imported['product_id'], 'warnings' => $imported['warnings'] ) ) )
		);

		$this->logs->record(
			array(
				'job_id'           => $job->id,
				'url'              => $data->source_url,
				'status'           => 'completed',
				'product_id'       => $imported['product_id'],
				'images_count'     => count( $data->images ),
				'attributes_count' => count( $data->attributes ),
				'variations_count' => count( $data->variations ),
				'error_message'    => implode( ' | ', $imported['warnings'] ),
			)
		);
	}

	private function fail( JobRepository $repository, $job, $message ) {
		$attempts     = (int) $job->attempts + 1;
		$max_attempts = (int) $job->max_attempts;

		if ( $attempts >= $max_attempts ) {
			$repository->update_status( (int) $job->id, 'failed', array( 'attempts' => $attempts, 'error_message' => $message ) );
		} else {
			$backoff_seconds = min( 3600, 30 * ( 2 ** $attempts ) );
			$repository->update_status(
				(int) $job->id,
				'retry',
				array(
					'attempts'      => $attempts,
					'error_message' => $message,
					'next_retry_at' => gmdate( 'Y-m-d H:i:s', time() + $backoff_seconds ),
				)
			);
		}

		$this->logs->record(
			array(
				'job_id'        => $job->id,
				'url'           => $job->url,
				'status'        => 'failed',
				'error_message' => $message,
			)
		);
	}
}
