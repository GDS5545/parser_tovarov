<?php
/**
 * Plugin Name: Elementor Order Popup
 * Description: Превращает кнопки виджетов Elementor («Заказать», «Купить» и т.д.) в кнопки добавления товара в форму-попап. Позволяет добавить несколько товаров в одну заявку и отправляет её в Bitrix24 (CRM) и в Telegram.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:
 * License: GPL v2 or later
 * Text Domain: elementor-order-popup
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EOP_VERSION', '1.0.0' );
define( 'EOP_FILE', __FILE__ );
define( 'EOP_DIR', plugin_dir_path( __FILE__ ) );
define( 'EOP_URL', plugin_dir_url( __FILE__ ) );

require_once EOP_DIR . 'includes/class-eop-settings.php';
require_once EOP_DIR . 'includes/class-eop-assets.php';
require_once EOP_DIR . 'includes/class-eop-integrations.php';
require_once EOP_DIR . 'includes/class-eop-ajax.php';

/**
 * Main plugin bootstrap.
 */
final class Elementor_Order_Popup {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		new EOP_Settings();
		new EOP_Assets();
		new EOP_Ajax();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'elementor-order-popup', false, dirname( plugin_basename( EOP_FILE ) ) . '/languages' );
	}
}

Elementor_Order_Popup::instance();

register_activation_hook( EOP_FILE, function () {
	if ( false === get_option( 'eop_options' ) ) {
		add_option( 'eop_options', EOP_Settings::get_defaults() );
	}
} );
