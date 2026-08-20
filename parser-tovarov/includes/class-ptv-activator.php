<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation / deactivation: creates the quick-order log table
 * and seeds default settings.
 */
class PTV_Activator {

	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ptv_quick_orders';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			customer_phone VARCHAR(64) NOT NULL DEFAULT '',
			comment TEXT NULL,
			attachment_url VARCHAR(500) NULL,
			cart_json LONGTEXT NULL,
			total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			bitrix_entity_type VARCHAR(20) NULL,
			bitrix_entity_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			error_message TEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		$defaults = PTV_Settings::get_defaults();
		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'ptv_' . $key, false ) ) {
				add_option( 'ptv_' . $key, $value );
			}
		}

		if ( ! get_option( 'ptv_db_version' ) ) {
			add_option( 'ptv_db_version', PTV_VERSION );
		}
	}

	public static function deactivate() {
		// Intentionally left blank: we keep data (settings, quick-order log)
		// on deactivation so users don't lose history when the plugin is
		// briefly disabled. Uninstall.php (if added) would handle full removal.
	}
}
