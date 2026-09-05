<?php
/**
 * /wp-json/uws/v1/jobs — real queue CRUD backed by wp_uws_jobs (spec §30).
 * This works today even though nothing consumes the queue yet (Stage 11).
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Queue\JobRepository;
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

		$id = $this->jobs->enqueue( $url, $request->get_param( 'type' ) ?: 'single' );

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
}
