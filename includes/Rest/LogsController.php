<?php
/**
 * GET /wp-json/uws/v1/logs — lists wp_uws_logs entries (spec §29).
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Database\LogRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogsController {

	/** @var LogRepository */
	private $logs;

	public function __construct( LogRepository $logs = null ) {
		$this->logs = $logs ?: new LogRepository();
	}

	public function index( WP_REST_Request $request ) {
		$page = max( 1, (int) $request->get_param( 'page' ) );

		return new WP_REST_Response(
			array(
				'total' => $this->logs->count_all(),
				'items' => $this->logs->paginate( $page ),
			),
			200
		);
	}
}
