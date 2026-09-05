<?php
/**
 * HTTP client for the Node.js/Playwright worker (Stage 3). Implements
 * ScraperEngineInterface so the extraction pipeline never has to know
 * whether the page came from Playwright or a future alternative engine.
 *
 * @package Uws\Scraper
 */

namespace Uws\Scraper;

use Uws\Security\UrlValidator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PlaywrightHttpEngine implements ScraperEngineInterface {

	/** @var string */
	private $worker_url;

	/** @var string */
	private $api_key;

	/**
	 * @param string $worker_url Base URL, e.g. https://scraper-worker.example.com.
	 * @param string $api_key
	 */
	public function __construct( $worker_url, $api_key = '' ) {
		$this->worker_url = untrailingslashit( $worker_url );
		$this->api_key    = $api_key;
	}

	public function fetch_page( $url, array $options = array() ) {
		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $this->post( '/fetch', array( 'url' => $url, 'options' => $options ) );
	}

	public function discover_product_urls( $url, array $options = array() ) {
		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$result = $this->post( '/discover', array( 'url' => $url, 'options' => $options ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['urls'] ) && is_array( $result['urls'] ) ? $result['urls'] : array();
	}

	public function health_check() {
		$response = wp_remote_get(
			$this->worker_url . '/health',
			array( 'timeout' => 5 )
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * @param string $path
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	private function post( $path, array $body ) {
		if ( empty( $this->worker_url ) ) {
			return new WP_Error(
				'uws_worker_not_configured',
				__( 'No scraper worker URL is configured. Set it under Universal Scraper → Browser Settings.', 'universal-woo-scraper' )
			);
		}

		$response = wp_remote_post(
			$this->worker_url . $path,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-UWS-Api-Key'  => $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'uws_worker_unreachable',
				sprintf(
					/* translators: %s: underlying HTTP error message */
					__( 'Could not reach the scraper worker: %s', 'universal-woo-scraper' ),
					$response->get_error_message()
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : __( 'Unknown worker error.', 'universal-woo-scraper' );
			return new WP_Error( 'uws_worker_error', $message, array( 'status' => $status ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
