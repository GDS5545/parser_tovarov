<?php
/**
 * Creates/upgrades the plugin's custom tables via dbDelta (spec §41).
 *
 * @package Uws\Database
 */

namespace Uws\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	/**
	 * Activation hook: create tables and seed default options.
	 */
	public static function activate() {
		self::install();

		if ( false === get_option( 'uws_settings' ) ) {
			add_option( 'uws_settings', self::default_settings() );
		}

		update_option( 'uws_db_version', UWS_DB_VERSION );

		if ( ! wp_next_scheduled( 'uws_process_queue' ) ) {
			wp_schedule_event( time(), 'uws_every_minute', 'uws_process_queue' );
		}
	}

	/**
	 * Deactivation hook: unschedule cron, keep data (uninstall.php handles wipe).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'uws_process_queue' );
	}

	/**
	 * Runs dbDelta for every custom table. Safe to call repeatedly (idempotent),
	 * e.g. after a plugin update that bumps UWS_DB_VERSION.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::schema( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * @param string $charset_collate Result of $wpdb->get_charset_collate().
	 * @return string[] One CREATE TABLE statement per custom table.
	 */
	private static function schema( $charset_collate ) {
		$sources       = Tables::sources();
		$jobs          = Tables::jobs();
		$logs          = Tables::logs();
		$mappings      = Tables::mappings();
		$product_links = Tables::product_links();

		return array(
			"CREATE TABLE {$sources} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				domain VARCHAR(255) NOT NULL,
				profile LONGTEXT NULL,
				has_json_ld TINYINT(1) NOT NULL DEFAULT 0,
				has_product_schema TINYINT(1) NOT NULL DEFAULT 0,
				product_url_pattern VARCHAR(255) NULL,
				last_analyzed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY domain (domain)
			) {$charset_collate};",

			"CREATE TABLE {$jobs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL DEFAULT 'single',
				url TEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				priority SMALLINT NOT NULL DEFAULT 10,
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
				next_retry_at DATETIME NULL,
				payload LONGTEXT NULL,
				result LONGTEXT NULL,
				error_message TEXT NULL,
				parent_job_id BIGINT UNSIGNED NULL,
				created_by BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY parent_job_id (parent_job_id),
				KEY next_retry_at (next_retry_at)
			) {$charset_collate};",

			"CREATE TABLE {$logs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id BIGINT UNSIGNED NULL,
				url TEXT NOT NULL,
				status VARCHAR(20) NOT NULL,
				product_id BIGINT UNSIGNED NULL,
				duration_ms INT UNSIGNED NULL,
				images_count SMALLINT UNSIGNED NULL,
				attributes_count SMALLINT UNSIGNED NULL,
				variations_count SMALLINT UNSIGNED NULL,
				error_message TEXT NULL,
				debug_ref VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};",

			"CREATE TABLE {$mappings} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL,
				scope VARCHAR(255) NOT NULL DEFAULT 'global',
				source_key VARCHAR(255) NOT NULL,
				source_label VARCHAR(255) NULL,
				target_key VARCHAR(255) NULL,
				target_label VARCHAR(255) NULL,
				action VARCHAR(20) NOT NULL DEFAULT 'map',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY type_scope_source (type, scope(100), source_key(191))
			) {$charset_collate};",

			"CREATE TABLE {$product_links} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_url VARCHAR(767) NOT NULL,
				source_domain VARCHAR(255) NULL,
				source_id VARCHAR(255) NULL,
				gtin VARCHAR(64) NULL,
				mpn VARCHAR(64) NULL,
				sku VARCHAR(191) NULL,
				product_id BIGINT UNSIGNED NOT NULL,
				content_hash VARCHAR(64) NULL,
				last_scraped_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_url (source_url),
				KEY product_id (product_id),
				KEY sku (sku),
				KEY gtin (gtin)
			) {$charset_collate};",
		);
	}

	/**
	 * @return array<string,mixed> Defaults for the single 'uws_settings' option
	 *                             (site-wide config only; never used for scraped data).
	 */
	private static function default_settings() {
		return array(
			'worker_url'              => '',
			'worker_api_key'          => '',
			'default_import_status'   => 'draft',
			'normalization_mode'      => 'smart', // 'smart' | 'strict'.
			'delay_between_requests'  => 1.0,
			'max_parallel_workers'    => 1,
			'ai_provider'             => 'none', // 'none' | 'openai' | 'anthropic'.
			'ai_api_key'              => '',
			'image_policy'            => 'download', // 'download' | 'remote' | 'main_only'.
			'protect_manual_edits'    => true,
			'update_title'            => true,
			'update_description'      => true,
			'update_price'            => true,
			'update_stock'            => true,
			'update_categories'       => true,
			'update_attributes'       => true,
			'update_images'           => true,
			'debug_mode'              => false,
		);
	}
}
