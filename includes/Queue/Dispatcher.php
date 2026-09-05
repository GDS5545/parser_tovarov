<?php
/**
 * Consumes the jobs QueueRunner::run_due_jobs() hands to 'uws_queue_tick'.
 * A "single" job is analyzed and imported through the same
 * ExtractionPipeline + ProductImporter used by the synchronous
 * /analyze + /import REST flow; a "category" job discovers product URLs
 * and enqueues one "single" job per URL rather than importing anything
 * itself (spec §19).
 *
 * @package Uws\Queue
 */

namespace Uws\Queue;

use Uws\Database\LogRepository;
use Uws\Database\ProductLinkRepository;
use Uws\Pipeline\ExtractionPipeline;
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
		$settings = get_option( 'uws_settings', array() );
		$pipeline = new ExtractionPipeline( $engine );

		$result = $pipeline->analyze( $job->url );
		if ( is_wp_error( $result ) ) {
			$this->fail( $repository, $job, $result->get_error_message() );
			return;
		}

		$data     = $result['data'];
		$existing = $this->links->find_existing( $data->source_url, $data->sku, $data->gtin, $data->mpn );

		$imported = $this->importer->import(
			$data,
			array(
				'product_id'         => $existing ? (int) $existing->product_id : 0,
				'status'             => $settings['default_import_status'] ?? 'draft',
				'image_policy'       => $settings['image_policy'] ?? 'download',
				'normalization_mode' => $settings['normalization_mode'] ?? 'smart',
			)
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

	private function process_category_job( JobRepository $repository, $job, $engine ) {
		$payload = json_decode( (string) $job->payload, true );
		$options = is_array( $payload ) ? ( $payload['discover_options'] ?? array() ) : array();

		$urls = $engine->discover_product_urls( $job->url, $options );
		if ( is_wp_error( $urls ) ) {
			$this->fail( $repository, $job, $urls->get_error_message() );
			return;
		}

		foreach ( $urls as $url ) {
			$repository->enqueue( $url, 'single' );
		}

		$repository->update_status(
			(int) $job->id,
			'completed',
			array( 'result' => wp_json_encode( array( 'discovered' => count( $urls ) ) ) )
		);

		$this->logs->record(
			array(
				'job_id'      => $job->id,
				'url'         => $job->url,
				'status'      => 'completed',
				'error_message' => sprintf(
					/* translators: %d: number of product URLs found */
					__( 'Discovered and queued %d product URLs.', 'universal-woo-scraper' ),
					count( $urls )
				),
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
