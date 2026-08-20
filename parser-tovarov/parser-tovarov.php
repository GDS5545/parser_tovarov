<?php
/**
 * Plugin Name: Parser Tovarov — табличный каталог WooCommerce
 * Plugin URI: https://github.com/gds5545/parser_tovarov
 * Description: Табличный каталог товаров WooCommerce с фильтрами, быстрыми кнопками, корзиной и отправкой заявки в Bitrix24. Товары нескольких категорий подгружаются постранично через AJAX, поэтому не нагружают сайт.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * WC requires at least: 5.0
 * Author: Parser Tovarov
 * Text Domain: parser-tovarov
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PTV_VERSION', '1.0.0' );
define( 'PTV_FILE', __FILE__ );
define( 'PTV_PATH', plugin_dir_path( __FILE__ ) );
define( 'PTV_URL', plugin_dir_url( __FILE__ ) );
define( 'PTV_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Composer-free PSR-4-ish autoloader for the includes/ directory.
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'PTV_' ) !== 0 ) {
			return;
		}
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		$path      = PTV_PATH . 'includes/' . $file_name;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( PTV_FILE, array( 'PTV_Activator', 'activate' ) );
register_deactivation_hook( PTV_FILE, array( 'PTV_Activator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded, so WooCommerce is guaranteed
 * to be available for the check below.
 */
function ptv_init_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ptv_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'parser-tovarov', false, dirname( PTV_BASENAME ) . '/languages' );

	PTV_Settings::instance();
	PTV_Assets::instance();
	PTV_Shortcode::instance();
	PTV_Ajax::instance();
	PTV_Cache::instance();
}
add_action( 'plugins_loaded', 'ptv_init_plugin' );

function ptv_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Плагин «Parser Tovarov» требует активный плагин WooCommerce.', 'parser-tovarov' );
	echo '</p></div>';
}
