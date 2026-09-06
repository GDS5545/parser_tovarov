<?php
/**
 * Registers every /wp-json/uws/v1/* route. Every route declares an explicit
 * permission_callback (spec §42) — none use __return_true.
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestApi {

	const NAMESPACE_V1 = 'uws/v1';

	public function register_routes() {
		$analyze  = new AnalyzeController();
		$import   = new ImportController();
		$jobs     = new JobsController();
		$products = new ProductsController();
		$logs     = new LogsController();

		register_rest_route(
			self::NAMESPACE_V1,
			'/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $analyze, 'handle' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $import, 'handle' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $jobs, 'index' ),
					'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $jobs, 'create' ),
					'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
					'args'                => array(
						'url'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'type' => array(
							'required' => false,
							'type'     => 'string',
							'enum'     => array( 'single', 'category', 'bulk' ),
							'default'  => 'single',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $jobs, 'show' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $jobs, 'cancel' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $jobs, 'retry' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/run-now',
			array(
				'methods'             => 'POST',
				'callback'            => array( $jobs, 'run_now' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $products, 'index' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $logs, 'index' ),
				'permission_callback' => array( Capabilities::class, 'rest_permission_check' ),
			)
		);
	}
}
