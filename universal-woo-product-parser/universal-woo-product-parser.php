<?php
/**
 * Plugin Name: Universal Woo Product Parser
 * Plugin URI:  https://github.com/gds5545/parser_tovarov
 * Description: Универсальный парсер каталогов. Обходит домен, указанный в настройках, распознает карточки товаров на любом движке (JSON-LD, микроразметка, OpenGraph, эвристики) и импортирует их в каталог WooCommerce с правильным деревом категорий. Работает в фоне короткими тиками — без 504 и зависаний.
 * Version:     2.0.0
 * Author:      parser_tovarov
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: uwp-parser
 */

if (!defined('ABSPATH')) { exit; }

define('UWP_VERSION', '2.0.0');
define('UWP_FILE', __FILE__);
define('UWP_DIR', plugin_dir_path(__FILE__));
define('UWP_URL', plugin_dir_url(__FILE__));

require_once UWP_DIR . 'includes/class-uwp-settings.php';
require_once UWP_DIR . 'includes/class-uwp-db.php';
require_once UWP_DIR . 'includes/class-uwp-http.php';
require_once UWP_DIR . 'includes/class-uwp-dom.php';
require_once UWP_DIR . 'includes/class-uwp-url.php';
require_once UWP_DIR . 'includes/class-uwp-extractor.php';
require_once UWP_DIR . 'includes/class-uwp-crawler.php';
require_once UWP_DIR . 'includes/class-uwp-importer.php';
require_once UWP_DIR . 'includes/class-uwp-runner.php';
require_once UWP_DIR . 'includes/class-uwp-admin.php';
require_once UWP_DIR . 'includes/class-uwp-plugin.php';

register_activation_hook(__FILE__, array('UWP_Plugin', 'on_activate'));
register_deactivation_hook(__FILE__, array('UWP_Plugin', 'on_deactivate'));

add_action('plugins_loaded', array('UWP_Plugin', 'instance'));
