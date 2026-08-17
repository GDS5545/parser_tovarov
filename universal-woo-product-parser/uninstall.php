<?php
/**
 * Полная очистка при удалении плагина через админку WordPress.
 * Импортированные товары и категории не трогаются — удаляются только
 * служебные таблицы, настройки и метки самого парсера.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

global $wpdb;

$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'uwp_queue');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'uwp_log');

foreach (array(
    'uwp_parser_settings',
    'uwp_parser_db_version',
    'uwp_parser_running',
    'uwp_parser_heartbeat',
    'uwp_parser_token',
    'uwp_parser_stats',
) as $option) {
    delete_option($option);
}

delete_transient('uwp_parser_lock');
wp_clear_scheduled_hook('uwp_parser_tick');
