<?php
/**
 * Хранилище настроек. Один источник правды для значений по умолчанию,
 * типов полей и санитайза.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Settings {

    const OPTION = 'uwp_parser_settings';

    /** @var array|null */
    private static $cache = null;

    /**
     * Значения по умолчанию. Ключ => значение.
     */
    public static function defaults() {
        return array(
            // Источник
            'source_url'        => '',
            'include_paths'     => '',
            'exclude_paths'     => "/blog\n/news\n/novosti\n/about\n/o-kompanii\n/contact\n/kontakty\n/dostavka\n/oplata\n/policy\n/politika\n/vakansii\n/otzyvy",

            // Куда кладем в WooCommerce
            'root_category'     => '',
            'create_categories' => 1,
            'require_category'  => 0,
            'post_status'       => 'publish',

            // Что импортируем
            'import_prices'     => 1,
            'quote_mode'        => 0,
            'import_images'     => 1,
            'max_images'        => 3,
            'import_attributes' => 1,
            'update_existing'   => 1,

            // Пределы обхода
            'max_depth'         => 6,
            'max_pages'         => 3000,
            'max_products'      => 0,
            'use_sitemap'       => 1,

            // Скорость и защита от зависаний
            'requests_per_tick' => 4,
            'tick_budget'       => 15,
            'request_timeout'   => 20,
            'request_delay_ms'  => 500,
            'max_attempts'      => 3,

            // Сеть.
            // User-Agent обычного браузера: на служебный UA парсера Cloudflare
            // и модули безопасности хостингов отвечают 403, и обход встает.
            'user_agent'        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'proxy'             => '',

            // Ручные селекторы (нужны редко, только если авто-разбор промахнулся)
            'sel_product_link'  => '',
            'sel_title'         => '',
            'sel_price'         => '',
            'sel_description'   => '',
            'sel_image'         => '',
            'sel_attributes'    => '',
            'sel_breadcrumbs'   => '',
        );
    }

    /**
     * Целочисленные поля и их безопасные границы: ключ => array(min, max).
     */
    public static function int_fields() {
        return array(
            'max_depth'         => array(0, 20),
            'max_pages'         => array(0, 200000),
            'max_products'      => array(0, 200000),
            'max_images'        => array(0, 10),
            'requests_per_tick' => array(1, 20),
            'tick_budget'       => array(5, 60),
            'request_timeout'   => array(5, 60),
            'request_delay_ms'  => array(0, 10000),
            'max_attempts'      => array(1, 10),
        );
    }

    public static function bool_fields() {
        return array(
            'create_categories', 'require_category', 'import_prices', 'quote_mode',
            'import_images', 'import_attributes', 'update_existing', 'use_sitemap',
        );
    }

    public static function all() {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, array());
            if (!is_array($stored)) { $stored = array(); }
            self::$cache = array_merge(self::defaults(), $stored);
        }
        return self::$cache;
    }

    public static function get($key, $fallback = null) {
        $all = self::all();
        if (array_key_exists($key, $all)) { return $all[$key]; }
        return $fallback;
    }

    public static function int($key) {
        $limits = self::int_fields();
        $value = intval(self::get($key, 0));
        if (isset($limits[$key])) {
            $value = max($limits[$key][0], min($limits[$key][1], $value));
        }
        return $value;
    }

    public static function flag($key) {
        return !empty(self::get($key));
    }

    public static function save(array $values) {
        $current = self::all();
        $clean   = self::sanitize($values, $current);
        update_option(self::OPTION, $clean, false);
        self::$cache = $clean;
        return $clean;
    }

    public static function update($key, $value) {
        $all = self::all();
        $all[$key] = $value;
        update_option(self::OPTION, $all, false);
        self::$cache = $all;
    }

    /**
     * Приводит присланную из формы структуру к безопасному виду.
     * Отсутствующие ключи берутся из текущих настроек, чтобы частичное
     * сохранение не обнуляло остальное.
     */
    public static function sanitize(array $input, array $current) {
        $out    = array();
        $bools  = self::bool_fields();
        $ints   = self::int_fields();
        $submitted = !empty($input['__uwp_form']);

        foreach (self::defaults() as $key => $default) {
            $has = array_key_exists($key, $input);

            if (in_array($key, $bools, true)) {
                // Чекбоксы не приходят в POST, когда сняты, поэтому смотрим на факт отправки формы.
                if ($submitted) { $out[$key] = $has && !empty($input[$key]) ? 1 : 0; }
                else { $out[$key] = isset($current[$key]) ? intval((bool) $current[$key]) : intval((bool) $default); }
                continue;
            }

            if (!$has) {
                $out[$key] = isset($current[$key]) ? $current[$key] : $default;
                continue;
            }

            $value = $input[$key];
            if (is_array($value)) { $value = ''; }
            $value = wp_unslash((string) $value);

            if (isset($ints[$key])) {
                $out[$key] = max($ints[$key][0], min($ints[$key][1], intval($value)));
                continue;
            }

            switch ($key) {
                case 'source_url':
                    $out[$key] = esc_url_raw(trim($value));
                    break;
                case 'post_status':
                    $out[$key] = in_array($value, array('draft', 'publish', 'pending', 'private'), true) ? $value : 'draft';
                    break;
                case 'include_paths':
                case 'exclude_paths':
                    $out[$key] = sanitize_textarea_field($value);
                    break;
                default:
                    $out[$key] = sanitize_text_field($value);
                    break;
            }
        }

        return $out;
    }

    /**
     * Строки многострочного поля без пустот и дублей.
     */
    public static function lines($key) {
        $raw   = (string) self::get($key, '');
        $lines = preg_split('/\r?\n/u', $raw);
        $out   = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) { continue; }
            $out[] = $line;
        }
        return array_values(array_unique($out));
    }

    public static function flush_cache() {
        self::$cache = null;
    }
}
