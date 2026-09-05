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

// In-memory postmeta stand-in for ImportSnapshot's read()/write() tests —
// swap-in-able because it's global state, so tests that use it should not
// assume isolation between test *methods* that touch the same post ID.
$GLOBALS['uws_test_postmeta'] = array();

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['uws_test_postmeta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['uws_test_postmeta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	// Minimal stand-in satisfying the \WC_Product type hint ImportSnapshot
	// uses — the real class ships with WooCommerce and isn't available in
	// this pure-PHP unit test run. Declares real methods (not __call) for
	// every field ImportSnapshot::TRACKED_FIELDS reads, since
	// method_exists() does not recognize magic methods.
	class WC_Product {
		private $id;
		private $fields;

		public function __construct( $id, array $fields = array() ) {
			$this->id     = $id;
			$this->fields = $fields;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_name() {
			return $this->fields['name'] ?? '';
		}

		public function get_description() {
			return $this->fields['description'] ?? '';
		}

		public function get_short_description() {
			return $this->fields['short_description'] ?? '';
		}

		public function get_regular_price() {
			return $this->fields['regular_price'] ?? '';
		}

		public function get_sale_price() {
			return $this->fields['sale_price'] ?? '';
		}

		public function get_stock_status() {
			return $this->fields['stock_status'] ?? '';
		}

		public function get_stock_quantity() {
			return $this->fields['stock_quantity'] ?? null;
		}
	}
}

// Composer's PSR-4 autoloader (Uws\ => includes/) covers every plugin
// class from here on, so individual test files don't need to hand-require
// the classes they exercise.
require_once __DIR__ . '/../vendor/autoload.php';
