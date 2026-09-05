<?php
/**
 * GET /wp-json/uws/v1/products — lists the source↔WooCommerce links
 * recorded in wp_uws_product_links (populated once Stage 8's importer runs).
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Database\ProductLinkRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductsController {

	/** @var ProductLinkRepository */
	private $links;

	public function __construct( ProductLinkRepository $links = null ) {
		$this->links = $links ?: new ProductLinkRepository();
	}

	public function index( WP_REST_Request $request ) {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		return new WP_REST_Response( $this->links->paginate( $page ), 200 );
	}
}
