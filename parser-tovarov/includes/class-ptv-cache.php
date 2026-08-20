<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight transient cache with a version-based bust: instead of deleting
 * every cached key on product changes (expensive to enumerate), we bump a
 * version number and mix it into cache keys, which instantly "forgets" all
 * previous entries without touching the database rows themselves (they just
 * expire naturally).
 */
class PTV_Cache {

	const VERSION_OPTION = 'ptv_cache_version';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post_product', array( $this, 'bump_version' ) );
		add_action( 'woocommerce_update_product', array( $this, 'bump_version' ) );
		add_action( 'delete_post', array( $this, 'bump_version' ) );
		add_action( 'edited_product_cat', array( $this, 'bump_version' ) );
	}

	public function bump_version() {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		update_option( self::VERSION_OPTION, $version + 1, false );
	}

	private function version() {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	public function get( $key ) {
		return get_transient( $this->build_key( $key ) );
	}

	public function set( $key, $value, $minutes = 15 ) {
		set_transient( $this->build_key( $key ), $value, max( 1, (int) $minutes ) * MINUTE_IN_SECONDS );
	}

	private function build_key( $key ) {
		$hash = substr( md5( $key ), 0, 40 );
		return 'ptv_' . $this->version() . '_' . $hash;
	}
}
