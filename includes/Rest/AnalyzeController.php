<?php
/**
 * POST /wp-json/uws/v1/analyze — validates the submitted URL and hands off
 * to whichever ScraperEngineInterface implementation Stage 3 registers via
 * the 'uws_scraper_engine' filter. No engine is registered yet, so this
 * honestly reports 501 rather than faking a result (spec §82: no
 * TODO-stub functions pretending to work).
 *
 * @package Uws\Rest
 */

namespace Uws\Rest;

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
				'uws_not_implemented',
				__( 'No scraper worker is configured yet. Set the worker URL under Universal Scraper → Browser Settings once the Playwright worker (Stage 3) is deployed.', 'universal-woo-scraper' ),
				array( 'status' => 501 )
			);
		}

		$page = $engine->fetch_page( $url );
		if ( is_wp_error( $page ) ) {
			$page->add_data( array( 'status' => 502 ) );
			return $page;
		}

		return new WP_REST_Response(
			array(
				'message' => __( 'Page fetched. Extraction pipeline is not wired in yet (Stages 4–8).', 'universal-woo-scraper' ),
				'page'    => $page,
			),
			200
		);
	}
}
