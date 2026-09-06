<?php
/**
 * Plugin Name:       Universal WooCommerce Product Scraper & Importer
 * Plugin URI:        https://github.com/gds5545/parser_tovarov
 * Description:       Analyzes any product/category page with a real browser worker, extracts structured product data, and imports it into WooCommerce with attribute normalization, category mapping, and sync.
 * Version:           0.9.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            gds5545
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       universal-woo-scraper
 * Domain Path:       /languages
 *
 * @package Uws
 */

namespace Uws;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Do not load directly.
}

define( 'UWS_VERSION', '0.9.6' );
define( 'UWS_PLUGIN_FILE', __FILE__ );
define( 'UWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UWS_DB_VERSION', '1.1.0' );

$uws_autoload = UWS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $uws_autoload ) ) {
	require_once $uws_autoload;
} else {
	require_once UWS_PLUGIN_DIR . 'includes/autoload.php';
}

register_activation_hook( __FILE__, array( Database\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Database\Installer::class, 'deactivate' ) );

/**
 * Bootstraps the plugin once WooCommerce (if present) has finished loading.
 */
function uws_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'Universal WooCommerce Product Scraper requires WooCommerce to be installed and active.', 'universal-woo-scraper' ) .
					'</p></div>';
			}
		);
		return;
	}

	Plugin::instance()->boot();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\uws_bootstrap' );
