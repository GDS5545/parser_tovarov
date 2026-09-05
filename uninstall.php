<?php
/**
 * Uninstall handler: drops the plugin's custom tables and options.
 * WordPress only executes this file when the plugin is deleted from the
 * Plugins screen (not on deactivate), so it is safe to be destructive here.
 *
 * @package Uws
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix . 'uws_';
$tables = array( 'sources', 'jobs', 'logs', 'mappings', 'product_links' );

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'uws_settings' );
delete_option( 'uws_db_version' );
wp_clear_scheduled_hook( 'uws_process_queue' );
