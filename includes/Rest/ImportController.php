<?php
/**
 * POST /wp-json/uws/v1/import — will create a WooCommerce product from a
 * confirmed ProductData payload once Woocommerce\ProductImporter lands in
 * Stage 8. Until then it reports 501 rather than a fake success.
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImportController {

	/**
	 * @param WP_REST_Request $request
	 * @return WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		return new WP_Error(
			'uws_not_implemented',
			__( 'WooCommerce import is implemented in Stage 8 of the roadmap and is not available yet.', 'universal-woo-scraper' ),
			array( 'status' => 501 )
		);
	}
}
