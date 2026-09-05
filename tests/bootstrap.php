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

if ( ! function_exists( 'sanitize_title' ) ) {
	// Approximates WordPress's sanitize_title() closely enough for tests:
	// lowercases, keeps unicode letters/digits, turns everything else into
	// single hyphens. Real sanitize_title() also transliterates accented
	// Latin characters, which is irrelevant to the Cyrillic/plain-ASCII
	// cases exercised here.
	function sanitize_title( $title ) {
		$title = mb_strtolower( trim( $title ) );
		$title = preg_replace( '/[^\p{L}\p{N}]+/u', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

// Composer's PSR-4 autoloader (Uws\ => includes/) covers every plugin
// class from here on, so individual test files don't need to hand-require
// the classes they exercise.
require_once __DIR__ . '/../vendor/autoload.php';
