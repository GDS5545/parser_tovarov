<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings screen: Bitrix24 webhook, default categories/attributes,
 * caching and pagination behaviour.
 */
class PTV_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	public function admin_assets( $hook ) {
		if ( false === strpos( $hook, 'parser-tovarov' ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){$(".ptv-color-field").wpColorPicker();});'
		);
	}

	public static function get_defaults() {
		return array(
			'bitrix_webhook_url'  => '',
			'bitrix_entity'       => 'lead', // lead | deal
			'bitrix_responsible'  => '',
			'min_order_amount'    => 0,
			'per_page'            => 20,
			'cache_minutes'       => 15,
			'accent_color'        => '#c0392b',
			'currency_suffix'     => ' ₽',
		);
	}

	public function get( $key ) {
		$defaults = self::get_defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
		return get_option( 'ptv_' . $key, $default );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Каталог товаров', 'parser-tovarov' ),
			__( 'Каталог товаров', 'parser-tovarov' ),
			'manage_woocommerce',
			'parser-tovarov',
			array( $this, 'render_settings_page' ),
			'dashicons-list-view',
			56
		);

		add_submenu_page(
			'parser-tovarov',
			__( 'Настройки', 'parser-tovarov' ),
			__( 'Настройки', 'parser-tovarov' ),
			'manage_woocommerce',
			'parser-tovarov',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'parser-tovarov',
			__( 'Заявки', 'parser-tovarov' ),
			__( 'Заявки', 'parser-tovarov' ),
			'manage_woocommerce',
			'parser-tovarov-orders',
			array( $this, 'render_orders_page' )
		);

		add_submenu_page(
			'parser-tovarov',
			__( 'Как пользоваться', 'parser-tovarov' ),
			__( 'Как пользоваться', 'parser-tovarov' ),
			'manage_woocommerce',
			'parser-tovarov-help',
			array( $this, 'render_help_page' )
		);
	}

	public function register_settings() {
		$fields = array(
			'bitrix_webhook_url' => 'esc_url_raw',
			'bitrix_entity'      => array( $this, 'sanitize_entity' ),
			'bitrix_responsible' => 'absint',
			'min_order_amount'   => 'floatval',
			'per_page'           => 'absint',
			'cache_minutes'      => 'absint',
			'accent_color'       => 'sanitize_hex_color',
			'currency_suffix'    => 'sanitize_text_field',
		);

		foreach ( $fields as $key => $sanitize ) {
			register_setting( 'ptv_settings', 'ptv_' . $key, array( 'sanitize_callback' => $sanitize ) );
		}
	}

	public function sanitize_entity( $value ) {
		return in_array( $value, array( 'lead', 'deal' ), true ) ? $value : 'lead';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		include PTV_PATH . 'admin/views/settings-page.php';
	}

	public function render_orders_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		include PTV_PATH . 'admin/views/orders-page.php';
	}

	public function render_help_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		include PTV_PATH . 'admin/views/help-page.php';
	}
}
