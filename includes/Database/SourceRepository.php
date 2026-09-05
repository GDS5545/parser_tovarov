<?php
/**
 * Read/write access to wp_uws_sources — the "Site Profile" (spec §47–48).
 * Right now this only stores the manual per-domain XPath overrides a
 * merchant configures on the Site Templates admin page; auto-detected
 * profile fields (has_json_ld, product_url_pattern, etc.) are columns
 * already reserved on the table for when Stage-48 auto-detection is
 * built, but nothing populates them yet — this class does not pretend
 * otherwise.
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SourceRepository {

	/** Recognized selector keys a Site Template can override. */
	const SELECTOR_FIELDS = array( 'name', 'sku', 'brand', 'price', 'sale_price', 'description', 'short_description', 'specifications', 'images', 'categories' );

	/**
	 * @param string $domain
	 * @return object|null Row with ->profile already json_decode()d into ->selectors (array), or null if no template exists for this domain.
	 */
	public function find( $domain ) {
		global $wpdb;
		$table = Tables::sources();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE domain = %s", $domain ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! $row ) {
			return null;
		}

		$profile        = json_decode( (string) $row->profile, true );
		$row->selectors = is_array( $profile ) && ! empty( $profile['selectors'] ) ? $profile['selectors'] : array();

		return $row;
	}

	/**
	 * @return array<int,object> Every configured Site Template, most recently updated first.
	 */
	public function all() {
		global $wpdb;
		$table = Tables::sources();

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as $row ) {
			$profile        = json_decode( (string) $row->profile, true );
			$row->selectors = is_array( $profile ) && ! empty( $profile['selectors'] ) ? $profile['selectors'] : array();
		}
		return $rows;
	}

	/**
	 * @param string               $domain
	 * @param array<string,string> $selectors Keys limited to self::SELECTOR_FIELDS; empty values are dropped.
	 * @return int Row ID.
	 */
	public function save( $domain, array $selectors ) {
		global $wpdb;
		$table = Tables::sources();
		$now   = current_time( 'mysql', true );

		$clean = array();
		foreach ( self::SELECTOR_FIELDS as $field ) {
			if ( ! empty( $selectors[ $field ] ) ) {
				$clean[ $field ] = trim( $selectors[ $field ] );
			}
		}
		$profile = wp_json_encode( array( 'selectors' => $clean ) );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE domain = %s", $domain ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$wpdb->update( $table, array( 'profile' => $profile, 'updated_at' => $now ), array( 'id' => $existing ) );
			return (int) $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'domain'     => $domain,
				'profile'    => $profile,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $id
	 */
	public function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::sources(), array( 'id' => (int) $id ) );
	}
}
