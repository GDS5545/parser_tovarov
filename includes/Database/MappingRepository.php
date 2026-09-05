<?php
/**
 * Read/write access to wp_uws_mappings — source→WooCommerce mapping rows
 * for both categories and attributes (spec §10, §46).
 *
 * The importer never creates a WooCommerce category/attribute term
 * straight from a scraped label without checking here first: the first
 * time a given source label is seen, an identity mapping row is
 * auto-created (source label == target label, action = 'map') so it
 * shows up for review on the Mappings admin page; every subsequent import
 * of that label reads the row instead, so once a merchant edits the
 * target label or sets action to 'skip'/'merge', it applies to all future
 * products without touching already-imported ones.
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MappingRepository {

	const TYPE_CATEGORY  = 'category';
	const TYPE_ATTRIBUTE = 'attribute';

	const ACTION_MAP  = 'map';
	const ACTION_SKIP = 'skip';

	/**
	 * Returns the mapping for this source key, creating an identity row
	 * (target == source, action = map) the first time it's seen.
	 *
	 * @param string $type         self::TYPE_CATEGORY | self::TYPE_ATTRIBUTE.
	 * @param string $scope        Domain name for per-site category mappings, or 'global'.
	 * @param string $source_key   Normalized/slugged source identifier.
	 * @param string $source_label Human-readable original label.
	 * @return object {id, type, scope, source_key, source_label, target_key, target_label, action}
	 */
	public function get_or_create( $type, $scope, $source_key, $source_label ) {
		$existing = $this->find( $type, $scope, $source_key );
		if ( $existing ) {
			return $existing;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->insert(
			Tables::mappings(),
			array(
				'type'         => $type,
				'scope'        => $scope,
				'source_key'   => $source_key,
				'source_label' => $source_label,
				'target_key'   => $source_key,
				'target_label' => $source_label,
				'action'       => self::ACTION_MAP,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		return $this->find( $type, $scope, $source_key );
	}

	/**
	 * @param string $type
	 * @param string $scope
	 * @param string $source_key
	 * @return object|null
	 */
	public function find( $type, $scope, $source_key ) {
		global $wpdb;
		$table = Tables::mappings();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND scope = %s AND source_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				$scope,
				$source_key
			)
		);
	}

	/**
	 * @param string|null $type Filter, or null for all.
	 * @return array<int,object>
	 */
	public function all( $type = null ) {
		global $wpdb;
		$table = Tables::mappings();

		if ( $type ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC", $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Admin edit: change the target label/key and/or action for one row.
	 *
	 * @param int    $id
	 * @param string $target_label
	 * @param string $action self::ACTION_MAP | self::ACTION_SKIP.
	 */
	public function update( $id, $target_label, $action ) {
		global $wpdb;

		$action = in_array( $action, array( self::ACTION_MAP, self::ACTION_SKIP ), true ) ? $action : self::ACTION_MAP;

		$wpdb->update(
			Tables::mappings(),
			array(
				'target_label' => $target_label,
				'target_key'   => sanitize_title( $target_label ),
				'action'       => $action,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * @param int $id
	 */
	public function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::mappings(), array( 'id' => (int) $id ) );
	}
}
