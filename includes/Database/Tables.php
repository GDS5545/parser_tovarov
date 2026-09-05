<?php
/**
 * Central registry of the plugin's custom table names.
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tables {

	/**
	 * @return string wp_uws_sources — one row per scraped domain (site profile).
	 */
	public static function sources() {
		global $wpdb;
		return $wpdb->prefix . 'uws_sources';
	}

	/**
	 * @return string wp_uws_jobs — scrape/import queue.
	 */
	public static function jobs() {
		global $wpdb;
		return $wpdb->prefix . 'uws_jobs';
	}

	/**
	 * @return string wp_uws_logs — per-attempt execution log.
	 */
	public static function logs() {
		global $wpdb;
		return $wpdb->prefix . 'uws_logs';
	}

	/**
	 * @return string wp_uws_mappings — source→WooCommerce category/attribute mappings.
	 */
	public static function mappings() {
		global $wpdb;
		return $wpdb->prefix . 'uws_mappings';
	}

	/**
	 * @return string wp_uws_product_links — source URL/ID ↔ WC product bridge, for dedup + sync.
	 */
	public static function product_links() {
		global $wpdb;
		return $wpdb->prefix . 'uws_product_links';
	}
}
