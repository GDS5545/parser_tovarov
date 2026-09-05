<?php
/**
 * POST /wp-json/uws/v1/analyze — fetches the URL through whichever
 * ScraperEngineInterface Plugin::boot() registered (PlaywrightHttpEngine,
 * once Browser Settings has a worker URL) and runs it through
 * ExtractionPipeline (JSON-LD → meta → specification tables → images →
 * breadcrumbs → DOM heuristics, then AI fallback only if AI Settings has
 * a provider configured AND important fields are still missing). Returns
 * the merged ProductData for the admin Preview screen — nothing is
 * written to WooCommerce here; that only happens when the user confirms
 * via POST /import.
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

use Uws\Pipeline\ExtractionPipeline;
use Uws\Security\UrlValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnalyzeController {

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );

		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}

		/**
		 * @param \Uws\Scraper\ScraperEngineInterface|null $engine
		 */
		$engine = apply_filters( 'uws_scraper_engine', null );

		if ( ! $engine instanceof \Uws\Scraper\ScraperEngineInterface ) {
			return new WP_Error(
				'uws_worker_not_configured',
				__( 'No scraper worker is configured yet. Set the worker URL under Universal Scraper → Browser Settings.', 'universal-woo-scraper' ),
				array( 'status' => 501 )
			);
		}

		$settings = get_option( 'uws_settings', array() );
		$options  = array( 'screenshot' => ! empty( $settings['debug_mode'] ) );

		$pipeline = new ExtractionPipeline( $engine );
		$result   = $pipeline->analyze( $url, $options );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		$response = $result['data']->to_array();

		$response['ai_used']  = ! empty( $result['ai_used'] );
		$response['ai_error'] = $result['ai_error'] ?? null;

		if ( ! empty( $settings['debug_mode'] ) ) {
			$response['debug'] = array(
				'status_code'     => $result['page']['status_code'] ?? null,
				'console_errors'  => $result['page']['console_errors'] ?? array(),
				'network_errors'  => $result['page']['network_errors'] ?? array(),
				'has_screenshot'  => ! empty( $result['page']['screenshot_base64'] ),
			);
		}

		return new WP_REST_Response( $response, 200 );
	}
}
