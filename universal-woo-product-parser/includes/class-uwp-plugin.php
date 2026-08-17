<?php
/**
 * Точка сборки: регистрация хуков, обработчик фонового тика, витрина «цена по запросу».
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Plugin {

    /** @var UWP_Plugin|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(UWP_Runner::HOOK_TICK, array($this, 'cron_tick'));

        // Фоновый тик вызывается сам у себя без авторизации, поэтому и nopriv.
        add_action('wp_ajax_uwp_tick', array($this, 'ajax_tick'));
        add_action('wp_ajax_nopriv_uwp_tick', array($this, 'ajax_tick'));

        add_action('admin_init', array('UWP_DB', 'maybe_install'));

        if (is_admin()) {
            $admin = new UWP_Admin();
            $admin->hooks();
        }

        add_filter('woocommerce_is_purchasable', array($this, 'quote_purchasable'), 20, 2);
        add_filter('woocommerce_get_price_html', array($this, 'quote_price_html'), 20, 2);
    }

    public function cron_schedules($schedules) {
        if (!isset($schedules['uwp_minute'])) {
            $schedules['uwp_minute'] = array('interval' => 60, 'display' => 'Каждую минуту (парсер товаров)');
        }
        return $schedules;
    }

    /**
     * Страховочный запуск по расписанию: если цепочка фоновых запросов
     * оборвалась (упал воркер, перезагрузился сервер), крон ее поднимет.
     */
    public function cron_tick() {
        if (!UWP_Runner::is_running()) { return; }
        UWP_Runner::tick();
    }

    /**
     * Фоновый тик. Ответ отдается сразу, работа идет уже после разрыва соединения,
     * поэтому браузер и веб-сервер никогда не ждут парсер.
     */
    public function ajax_tick() {
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (!hash_equals(UWP_Runner::token(), $token)) {
            wp_send_json_error(array('message' => 'bad token'), 403);
        }

        // Клиенту отвечать нечем: он и так не ждет.
        if (function_exists('fastcgi_finish_request')) {
            echo 'ok';
            fastcgi_finish_request();
        }

        UWP_Runner::tick();

        if (!function_exists('fastcgi_finish_request')) { echo 'ok'; }
        wp_die('', '', array('response' => 200));
    }

    // ------------------------------------------------------------------
    // Режим «Цена по запросу»
    // ------------------------------------------------------------------

    public function quote_purchasable($purchasable, $product) {
        if ($product && $product->get_meta(UWP_Importer::META_QUOTE) === 'yes') { return true; }
        return $purchasable;
    }

    public function quote_price_html($html, $product) {
        if ($product && $product->get_meta(UWP_Importer::META_QUOTE) === 'yes') {
            return '<span class="price uwp-quote-price">Цена по запросу</span>';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Жизненный цикл
    // ------------------------------------------------------------------

    public static function on_activate() {
        UWP_DB::install();

        $settings = get_option(UWP_Settings::OPTION, array());
        if (!is_array($settings) || !$settings) {
            update_option(UWP_Settings::OPTION, UWP_Settings::defaults(), false);
        }
    }

    public static function on_deactivate() {
        UWP_Runner::stop();
        wp_clear_scheduled_hook(UWP_Runner::HOOK_TICK);
    }
}
