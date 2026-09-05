<?php
/**
 * Cron-driven queue worker (spec §30–32). Runs on 'uws_process_queue'
 * (registered as a 1-minute WP-Cron event in Plugin::register_cron_schedule).
 *
 * Deliberately does not process jobs by itself yet: the scrape/import
 * pipeline (ScraperEngineInterface + extractors + WooCommerce importer)
 * lands in Stages 3–8. Wiring a job dispatcher here before that pipeline
 * exists would mean either faking success or crashing — neither is
 * acceptable, so today this class only enforces "one job at a time,
 * respecting the configured delay" bookkeeping and leaves due jobs queued
 * until Stage 11 adds the real dispatcher.
 *
 * @package Uws\Queue
 */

namespace Uws\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueueRunner {

	/** @var JobRepository */
	private $jobs;

	public function __construct( JobRepository $jobs = null ) {
		$this->jobs = $jobs ?: new JobRepository();
	}

	/**
	 * Entry point for the 'uws_process_queue' cron hook.
	 */
	public function run_due_jobs() {
		/**
		 * Fires once per cron tick with the jobs that are due to run.
		 * Stage 11 attaches the real dispatcher (fetch → extract → import)
		 * here via add_action(); until then no listener is registered and
		 * this method is a correct no-op rather than a fake success.
		 *
		 * @param array<int,object> $due_jobs
		 * @param JobRepository     $repository
		 */
		do_action( 'uws_queue_tick', $this->jobs->fetch_due( self::batch_size() ), $this->jobs );
	}

	/**
	 * @return int Max jobs to dequeue per tick, from settings (max_parallel_workers).
	 */
	private static function batch_size() {
		$settings = get_option( 'uws_settings', array() );
		$parallel = isset( $settings['max_parallel_workers'] ) ? (int) $settings['max_parallel_workers'] : 1;
		return max( 1, min( 10, $parallel ) );
	}
}
