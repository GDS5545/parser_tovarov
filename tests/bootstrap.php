<?php
/**
 * Lightweight test bootstrap that stubs the handful of WordPress functions
 * Security\UrlValidator depends on, so its SSRF protection can be unit
 * tested without spinning up a full WordPress + database test suite.
 * Full WP-integration tests (wp-env/wp-cli scaffold) are added in Stage 15
 * once there is importer/extractor behavior worth testing against a real
 * WooCommerce install.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url ) {
		return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data = array();

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function add_data( $data ) {
			$this->data = array_merge( $this->data, $data );
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

require_once __DIR__ . '/../includes/Security/UrlValidator.php';
