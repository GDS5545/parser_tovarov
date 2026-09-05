<?php
/**
 * Read/write access to wp_uws_logs (spec §29).
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogRepository {

	/**
	 * @param array<string,mixed> $data job_id, url, status, product_id, duration_ms,
	 *                                  images_count, attributes_count, variations_count, error_message, debug_ref.
	 * @return int Inserted log ID.
	 */
	public function record( array $data ) {
		global $wpdb;

		$data['created_at'] = current_time( 'mysql', true );

		$wpdb->insert( Tables::logs(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $page 1-based.
	 * @param int $per_page
	 * @return array<int,object>
	 */
	public function paginate( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table  = Tables::logs();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @return int Total row count, for pagination UI.
	 */
	public function count_all() {
		global $wpdb;
		$table = Tables::logs();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Truncates the log table (Debug Mode "clear logs" button, spec §58).
	 */
	public function clear() {
		global $wpdb;
		$table = Tables::logs();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
