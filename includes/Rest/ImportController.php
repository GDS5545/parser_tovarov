<?php
/**
 * POST /wp-json/uws/v1/import — creates (or updates) a WooCommerce product
 * from a confirmed ProductData payload (the admin Preview screen, possibly
 * edited by the user, posts back exactly the shape ProductData::to_array()
 * returned from /analyze).
 *
 * Runs the duplicate check from spec §26 first: if the source URL, SKU,
 * GTIN, or MPN already maps to a product, the caller must say what to do
 * (`action`: skip | update | duplicate) — nothing is overwritten silently.
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Database\LogRepository;
use Uws\Database\ProductLinkRepository;
use Uws\Dto\ProductData;
use Uws\Security\UrlValidator;
use Uws\Woocommerce\ProductImporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImportController {

	/** @var ProductLinkRepository */
	private $links;

	/** @var LogRepository */
	private $logs;

	/** @var ProductImporter */
	private $importer;

	public function __construct( ProductLinkRepository $links = null, LogRepository $logs = null, ProductImporter $importer = null ) {
		$this->links    = $links ?: new ProductLinkRepository();
		$this->logs     = $logs ?: new LogRepository();
		$this->importer = $importer ?: new ProductImporter();
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$payload = $request->get_param( 'data' );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'uws_invalid_payload', __( 'Missing product data.', 'universal-woo-scraper' ), array( 'status' => 400 ) );
		}

		$data = ProductData::from_array( $payload );

		if ( empty( $data->source_url ) ) {
			return new WP_Error( 'uws_missing_source_url', __( 'source_url is required for duplicate detection and sync.', 'universal-woo-scraper' ), array( 'status' => 400 ) );
		}
		$valid = UrlValidator::validate( $data->source_url );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}
		if ( empty( $data->name ) ) {
			return new WP_Error( 'uws_missing_name', __( 'Product name is required.', 'universal-woo-scraper' ), array( 'status' => 400 ) );
		}

		$action   = $request->get_param( 'action' ) ?: 'create';
		$existing = $this->links->find_existing( $data->source_url, $data->sku, $data->gtin, $data->mpn );

		if ( $existing && 'create' === $action ) {
			return new WP_REST_Response(
				array(
					'status'        => 'duplicate',
					'message'       => __( 'A product from this source (matched by URL, SKU, GTIN, or MPN) has already been imported.', 'universal-woo-scraper' ),
					'existing'      => array(
						'product_id'  => (int) $existing->product_id,
						'edit_link'   => get_edit_post_link( $existing->product_id, 'raw' ),
						'last_scraped_at' => $existing->last_scraped_at,
					),
					'available_actions' => array( 'update', 'duplicate', 'skip' ),
				),
				409
			);
		}

		if ( 'skip' === $action ) {
			return new WP_REST_Response( array( 'status' => 'skipped' ), 200 );
		}

		$settings   = get_option( 'uws_settings', array() );
		$product_id = ( $existing && 'update' === $action ) ? (int) $existing->product_id : 0;

		$result = $this->importer->import(
			$data,
			array(
				'product_id'         => $product_id,
				'status'             => $settings['default_import_status'] ?? 'draft',
				'image_policy'       => $settings['image_policy'] ?? 'download',
				'normalization_mode' => $settings['normalization_mode'] ?? 'smart',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->logs->record(
				array(
					'url'           => $data->source_url,
					'status'        => 'failed',
					'error_message' => $result->get_error_message(),
				)
			);
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		$this->links->upsert(
			array(
				'source_url'    => $data->source_url,
				'source_domain' => wp_parse_url( $data->source_url, PHP_URL_HOST ),
				'source_id'     => $data->source_id,
				'gtin'          => $data->gtin,
				'mpn'           => $data->mpn,
				'sku'           => $data->sku,
				'product_id'    => $result['product_id'],
				'content_hash'  => md5( wp_json_encode( $data->to_array() ) ),
			)
		);

		$this->logs->record(
			array(
				'url'               => $data->source_url,
				'status'            => 'completed',
				'product_id'        => $result['product_id'],
				'images_count'      => count( $data->images ),
				'attributes_count'  => count( $data->attributes ),
				'variations_count'  => count( $data->variations ),
				'error_message'     => implode( ' | ', $result['warnings'] ),
			)
		);

		return new WP_REST_Response(
			array(
				'status'     => 'imported',
				'product_id' => $result['product_id'],
				'edit_link'  => get_edit_post_link( $result['product_id'], 'raw' ),
				'warnings'   => $result['warnings'],
			),
			200
		);
	}
}
