<?php
/**
 * /wp-json/uws/v1/jobs — queue CRUD backed by wp_uws_jobs (spec §30), plus
 * run_now() for the "Run queue now" button that drives the queue directly
 * (bypassing WP-Cron) for a site where the cron event isn't firing on its
 * own — a real-world case: a low-traffic site whose only WP-Cron trigger is
 * a visitor request has no visitor to trigger it.
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Queue\JobRepository;
use Uws\Queue\QueueRunner;
use Uws\Security\UrlValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobsController {

	/** @var JobRepository */
	private $jobs;

	public function __construct( JobRepository $jobs = null ) {
		$this->jobs = $jobs ?: new JobRepository();
	}

	public function index( WP_REST_Request $request ) {
		$status = $request->get_param( 'status' );
		$page   = max( 1, (int) $request->get_param( 'page' ) );

		return new WP_REST_Response( $this->jobs->paginate( $status, $page ), 200 );
	}

	public function create( WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );

		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}

		$options = $request->get_param( 'options' );
		$payload = is_array( $options ) ? array( 'discover_options' => $options ) : array();

		$type = $request->get_param( 'type' ) ?: 'single';
		if ( 'category' === $type && null !== $request->get_param( 'max_depth' ) ) {
			$payload['max_depth'] = max( 1, min( 10, (int) $request->get_param( 'max_depth' ) ) );
		}

		$id = $this->jobs->enqueue( $url, $type, $payload );

		return new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	public function show( WP_REST_Request $request ) {
		$job = $this->jobs->find( (int) $request->get_param( 'id' ) );

		if ( ! $job ) {
			return new WP_Error( 'uws_job_not_found', __( 'Job not found.', 'universal-woo-scraper' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $job, 200 );
	}

	public function cancel( WP_REST_Request $request ) {
		$this->jobs->cancel( (int) $request->get_param( 'id' ) );
		return new WP_REST_Response( array( 'status' => 'cancelled' ), 200 );
	}

	public function retry( WP_REST_Request $request ) {
		$this->jobs->retry( (int) $request->get_param( 'id' ) );
		return new WP_REST_Response( array( 'status' => 'pending' ), 200 );
	}

	/**
	 * Runs one queue tick right now, in this request, instead of waiting
	 * for WP-Cron. Safe to click repeatedly: it only ever processes
	 * whatever is currently due (respecting max_parallel_workers), the
	 * same as a real cron tick would.
	 */
	public function run_now( WP_REST_Request $request ) {
		$processed = ( new QueueRunner( $this->jobs ) )->run_due_jobs();
		return new WP_REST_Response( array( 'processed' => $processed ), 200 );
	}
}
