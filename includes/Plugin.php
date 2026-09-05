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
use Uws\Queue\QueueRunner;
use Uws\Rest\RestApi;

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
