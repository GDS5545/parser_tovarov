<?php
/**
 * Main bootstrap: wires admin UI, REST API, and cron once WooCommerce is
 * confirmed active. Deliberately thin — all real logic lives in the
 * Admin\*, Rest\*, Queue\*, Database\* classes it instantiates.
 *
 * @package Uws
 */

namespace Uws;

use Uws\Admin\Menu;
use Uws\Database\Installer;
use Uws\Queue\Dispatcher;
use Uws\Queue\QueueRunner;
use Uws\Rest\RestApi;
use Uws\Scraper\PlaywrightHttpEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot() {
		load_plugin_textdomain( 'universal-woo-scraper', false, dirname( plugin_basename( UWS_PLUGIN_FILE ) ) . '/languages' );

		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( 'uws_process_queue', array( new QueueRunner(), 'run_due_jobs' ) );
		add_action( 'uws_queue_tick', array( new Dispatcher(), 'handle_tick' ), 10, 2 );
		add_filter( 'uws_scraper_engine', array( $this, 'register_scraper_engine' ) );

		if ( is_admin() ) {
			( new Menu() )->register();
		}

		add_action( 'rest_api_init', array( new RestApi(), 'register_routes' ) );

		$this->maybe_upgrade_database();
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public function register_cron_schedule( $schedules ) {
		$schedules['uws_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Universal Woo Scraper queue)', 'universal-woo-scraper' ),
		);
		return $schedules;
	}

	/**
	 * Default implementation of the 'uws_scraper_engine' filter: returns a
	 * PlaywrightHttpEngine bound to the configured worker URL, or null if
	 * none is set yet — callers (AnalyzeController, Dispatcher) treat null
	 * as "no worker configured" rather than crashing.
	 *
	 * @param \Uws\Scraper\ScraperEngineInterface|null $engine Passed through
	 *        unchanged if another filter callback already supplied one.
	 * @return \Uws\Scraper\ScraperEngineInterface|null
	 */
	public function register_scraper_engine( $engine ) {
		if ( null !== $engine ) {
			return $engine;
		}

		$settings = get_option( 'uws_settings', array() );
		if ( empty( $settings['worker_url'] ) ) {
			return null;
		}

		return new PlaywrightHttpEngine( $settings['worker_url'], $settings['worker_api_key'] ?? '' );
	}

	/**
	 * Re-runs dbDelta when the plugin's DB schema version changes, e.g.
	 * after an update from a ZIP replace that skips the activation hook.
	 */
	private function maybe_upgrade_database() {
		if ( get_option( 'uws_db_version' ) !== UWS_DB_VERSION ) {
			Installer::install();
			update_option( 'uws_db_version', UWS_DB_VERSION );
		}
	}
}
