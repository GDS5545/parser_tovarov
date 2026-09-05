<?php
/**
 * Read/write access to wp_uws_product_links — the source↔WooCommerce
 * bridge used for duplicate detection (spec §26) and change-aware sync
 * (spec §27).
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductLinkRepository {

	/**
	 * Looks up an existing import by any of the identifiers a duplicate
	 * check should consider: source URL, SKU, GTIN, or MPN (spec §26).
	 *
	 * @param string      $source_url
	 * @param string|null $sku
	 * @param string|null $gtin
	 * @param string|null $mpn
	 * @return object|null
	 */
	public function find_existing( $source_url, $sku = null, $gtin = null, $mpn = null ) {
		global $wpdb;
		$table = Tables::product_links();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source_url = %s", $source_url ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $row ) {
			return $row;
		}

		foreach ( array( 'sku' => $sku, 'gtin' => $gtin, 'mpn' => $mpn ) as $column => $value ) {
			if ( empty( $value ) ) {
				continue;
			}
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s LIMIT 1", $value ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $data source_url, source_domain, source_id, gtin, mpn, sku, product_id, content_hash.
	 * @return int Row ID.
	 */
	public function upsert( array $data ) {
		global $wpdb;
		$table = Tables::product_links();
		$now   = current_time( 'mysql', true );

		$existing = $this->find_existing( $data['source_url'] ?? '' );

		if ( $existing ) {
			$data['updated_at']      = $now;
			$data['last_scraped_at'] = $now;
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
			return (int) $existing->id;
		}

		$data['created_at']      = $now;
		$data['updated_at']      = $now;
		$data['last_scraped_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $page 1-based.
	 * @param int $per_page
	 * @return array<int,object>
	 */
	public function paginate( $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table  = Tables::product_links();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
