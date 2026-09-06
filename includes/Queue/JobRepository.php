<?php
/**
 * CRUD access to wp_uws_jobs. All queries are parameterized via
 * $wpdb->prepare(); no raw string interpolation of caller input.
 *
 * @package Uws\Queue
 */

namespace Uws\Queue;

use Uws\Database\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JobRepository {

	const STATUSES = array( 'pending', 'processing', 'completed', 'failed', 'retry', 'cancelled' );

	/**
	 * @param string $url
	 * @param string $type single|category|bulk
	 * @param array<string,mixed> $payload
	 * @param int    $priority Lower runs first.
	 * @return int Inserted job ID.
	 */
	public function enqueue( $url, $type = 'single', array $payload = array(), $priority = 10 ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			Tables::jobs(),
			array(
				'type'       => $type,
				'url'        => $url,
				'url_hash'   => md5( $url ),
				'status'     => 'pending',
				'priority'   => $priority,
				'payload'    => wp_json_encode( $payload ),
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $url
	 * @return bool True if any job (any type/status) already exists for this
	 *              exact URL. Backs enqueue_if_new() so the category-tree
	 *              crawler (Dispatcher::process_category_job(), spec §19)
	 *              can discover the same link from multiple listing pages
	 *              without spawning a duplicate job for it every time.
	 */
	public function url_already_queued( $url ) {
		global $wpdb;
		$table = Tables::jobs();

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s LIMIT 1", md5( $url ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Like enqueue(), but a no-op (returns 0) if a job for this URL already
	 * exists. Used only for URLs the crawler discovers on its own rather
	 * than ones a merchant explicitly submitted — an explicit "Add to
	 * Queue"/"Queue Category" action always queues, even for a URL already
	 * queued, so a merchant can deliberately retry one.
	 *
	 * @param string               $url
	 * @param string               $type
	 * @param array<string,mixed>  $payload
	 * @param int                  $priority
	 * @return int Inserted job ID, or 0 if this URL was already queued.
	 */
	public function enqueue_if_new( $url, $type = 'single', array $payload = array(), $priority = 10 ) {
		if ( $this->url_already_queued( $url ) ) {
			return 0;
		}
		return $this->enqueue( $url, $type, $payload, $priority );
	}

	/**
	 * @param int $limit
	 * @return array<int,object> Jobs whose status is pending/retry and due now, oldest+highest priority first.
	 */
	public function fetch_due( $limit = 5 ) {
		global $wpdb;
		$table = Tables::jobs();
		$now   = current_time( 'mysql', true );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status IN ('pending','retry')
				 AND (next_retry_at IS NULL OR next_retry_at <= %s)
				 ORDER BY priority ASC, id ASC
				 LIMIT %d",
				$now,
				$limit
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a controlled identifier from Tables::jobs().
	}

	/**
	 * Recovers jobs stuck in 'processing': a crash mid-tick (PHP fatal
	 * error, hosting timeout killing the request) leaves a job's status set
	 * to 'processing' forever otherwise, since fetch_due() only ever looks
	 * at 'pending'/'retry' — with no code path left that will ever touch
	 * that row again, the whole queue can appear to just stop, with no log
	 * entry explaining why (Dispatcher::handle_tick() now also wraps each
	 * job in try/catch so this should be rare, but a fatal error can still
	 * happen below the catch, e.g. a hosting timeout that kills the
	 * request outright). Called once per cron tick, before fetch_due().
	 *
	 * @param int $older_than_minutes A job younger than this is left alone —
	 *        it may simply still be running.
	 * @return array<int,object> The jobs that were reset, so the caller can log it.
	 */
	public function reset_stale_processing( $older_than_minutes = 10 ) {
		global $wpdb;
		$table  = Tables::jobs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $older_than_minutes ) * 60 );

		$stuck = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'processing' AND updated_at <= %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $stuck ) {
			$wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET status = 'pending', updated_at = %s WHERE status = 'processing' AND updated_at <= %s", current_time( 'mysql', true ), $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return $stuck ?: array();
	}

	/**
	 * @param int    $id
	 * @param string $status One of self::STATUSES.
	 * @param array<string,mixed> $extra Extra columns to set (result, error_message, next_retry_at, attempts).
	 */
	public function update_status( $id, $status, array $extra = array() ) {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}

		$data   = array_merge( array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), $extra );
		$format = array_fill( 0, count( $data ), '%s' );

		$wpdb->update( Tables::jobs(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;
		$table = Tables::jobs();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param string|null $status Filter, or null for all.
	 * @param int         $page   1-based.
	 * @param int         $per_page
	 * @return array<int,object>
	 */
	public function paginate( $status = null, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table  = Tables::jobs();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		if ( $status ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$status,
					$per_page,
					$offset
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param int $id
	 */
	public function cancel( $id ) {
		$this->update_status( $id, 'cancelled' );
	}

	/**
	 * Resets a failed job back to pending so it is picked up on the next tick.
	 *
	 * @param int $id
	 */
	public function retry( $id ) {
		global $wpdb;
		$wpdb->update(
			Tables::jobs(),
			array(
				'status'        => 'pending',
				'next_retry_at' => null,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param string $status_not_in Comma list is not accepted; call once per status if needed.
	 */
	public function clear_completed() {
		global $wpdb;
		$table = Tables::jobs();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('completed','cancelled')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}
}
