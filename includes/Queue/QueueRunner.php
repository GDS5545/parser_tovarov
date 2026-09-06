<?php
/**
 * Cron-driven queue worker (spec §30–32). Runs on 'uws_process_queue'
 * (registered as a 1-minute WP-Cron event in Plugin::register_cron_schedule).
 * Before each tick, recovers any job left stuck in 'processing' by an
 * earlier crash (see reset_stale_processing()), then hands the batch of
 * due jobs directly to Dispatcher::handle_tick().
 *
 * This used to go through a 'uws_queue_tick' action hook instead of a
 * direct call, on the theory that some other listener might one day want
 * to process jobs differently. Nothing in this plugin (or, as far as
 * anyone reported, outside it) ever used that extensibility, and it
 * became a real liability while chasing a live "Argument #1 ($due_jobs)
 * must be of type array, stdClass given" crash: with the call routed
 * through do_action()/WP_Hook, there was no way to be certain, from a
 * debug.log stack trace alone, that a stale bytecode cache (or a second,
 * unexpected listener) wasn't involved. A direct method call removes
 * that entire class of doubt — if this crashes now, it's unambiguously
 * this exact function's own logic.
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

	/** @var Dispatcher */
	private $dispatcher;

	public function __construct( JobRepository $jobs = null, LogRepository $logs = null, Dispatcher $dispatcher = null ) {
		$this->jobs       = $jobs ?: new JobRepository();
		$this->logs       = $logs ?: new LogRepository();
		$this->dispatcher = $dispatcher ?: new Dispatcher();
	}

	/**
	 * Entry point for the 'uws_process_queue' cron hook — also called
	 * directly (not through cron) by JobsController::run_now(), the
	 * "Run queue now" button on the Queue admin page, for a site where
	 * WP-Cron isn't firing on its own (see the class docblock) and a
	 * merchant needs the queue to move without waiting on it.
	 *
	 * @return int Number of due jobs handed to the dispatcher this call.
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

		$due_jobs = $this->jobs->fetch_due( self::batch_size() );
		if ( ! is_array( $due_jobs ) ) {
			// fetch_due() already guarantees an array; this is a second,
			// cheap guard directly at the call site that fatal-crashed on a
			// live install ("Argument #1 ($due_jobs) must be of type array,
			// stdClass given") — whatever produced that, handle_tick() below
			// must never receive anything but an array.
			$due_jobs = is_object( $due_jobs ) ? array( $due_jobs ) : array();
		}

		$this->dispatcher->handle_tick( $due_jobs, $this->jobs );

		return count( $due_jobs );
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
