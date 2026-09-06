<?php
/**
 * Cron-driven queue worker (spec §30–32). Runs on 'uws_process_queue'
 * (registered as a 1-minute WP-Cron event in Plugin::register_cron_schedule).
 * Before each tick, recovers any job left stuck in 'processing' by an
 * earlier crash (see reset_stale_processing()), then hands the batch of
 * due jobs to whatever listens on 'uws_queue_tick' — Dispatcher, wired up
 * in Plugin.php.
 *
 * @package Uws\Queue
 */

namespace Uws\Queue;

use Uws\Database\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueueRunner {

	/** @var JobRepository */
	private $jobs;

	/** @var LogRepository */
	private $logs;

	public function __construct( JobRepository $jobs = null, LogRepository $logs = null ) {
		$this->jobs = $jobs ?: new JobRepository();
		$this->logs = $logs ?: new LogRepository();
	}

	/**
	 * Entry point for the 'uws_process_queue' cron hook.
	 */
	public function run_due_jobs() {
		foreach ( $this->jobs->reset_stale_processing() as $job ) {
			$this->logs->record(
				array(
					'job_id'        => $job->id,
					'url'           => $job->url,
					'status'        => 'retry',
					'error_message' => __( 'Recovered a job stuck in "processing" (likely an unexpected crash mid-run) and reset it to pending.', 'universal-woo-scraper' ),
				)
			);
		}

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
