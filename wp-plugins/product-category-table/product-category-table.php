<?php
/**
 * Plugin Name: Product Category Table
 * Plugin URI:  https://example.local/
 * Description: Табличный вывод товаров WooCommerce в категориях: быстрые фильтры по атрибутам, сортируемые колонки характеристик, кнопки «Купить» / «Узнать цену».
 * Version:     1.0.0
 * Author:      Claude
 * Text Domain: product-category-table
 *
 * Отличие от предыдущего варианта такого плагина: список товаров категории (включая все
 * подкатегории) никогда не загружается в PHP как объекты WC_Product. Фильтры, колонки
 * характеристик и сортировка построены на обычных WordPress tax_query/JOIN-запросах —
 * так же, как это делает сам WooCommerce на странице каталога. wc_get_product() вызывается
 * только для товаров текущей страницы таблицы (по умолчанию 20 шт.), а не для всей ветки
 * категории. Поэтому категория с большим деревом подкатегорий и тысячами товаров не может
 * исчерпать лимit памяти PHP.
 *
 * Требование к товарам: характеристики (Марка, Диаметр, ГОСТ/ТУ и т.д.) должны быть
 * назначены как обычные атрибуты WooCommerce (pa_*) прямо на товар. Локальные (не-taxonomy)
 * атрибуты и значения, спрятанные только в вариациях, этот плагин не читает — см. README.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PCT_PLUGIN_FILE', __FILE__);
define('PCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PCT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PCT_PLUGIN_DIR . 'includes/class-pct-query.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-render.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-ajax.php';

final class PCT_Plugin {
    const OPTION_KEY = 'pct_options';
    const VERSION = '1.0.0';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'load'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('save_post_product', array($this, 'bump_cache_version'));
        add_action('created_product_cat', array($this, 'bump_cache_version'));
        add_action('edited_product_cat', array($this, 'bump_cache_version'));
        add_shortcode('product_category_table', array($this, 'shortcode'));
    }

    public function load() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        PCT_Query::init();
        PCT_Ajax::init($this);

        if ($this->get_option('auto_category', 'yes') === 'yes') {
            add_filter('template_include', array($this, 'template_include'), 99);
        }

        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>Product Category Table</strong>: требуется активный WooCommerce.</p></div>';
    }

    /* ---------------------------------------------------------------------
     * Настройки
     * ------------------------------------------------------------------ */

    public static function defaults() {
        return array(
            'auto_category'        => 'yes',
            'per_page'              => 20,
            'filter_attributes'     => array(),
            'column_attributes'     => array(),
            'max_filters'           => 5,
            'max_columns'           => 3,
            'chips_attribute'       => '',
            'chips_limit'           => 8,
            'price_prefix'          => 'от ',
            'price_unit'            => 'руб./кг',
            'button_buy_label'      => 'Купить',
            'button_request_label'  => 'Узнать цену',
            'show_quantity'         => 'yes',
            'request_email'         => get_option('admin_email'),
            'phone_required'        => 'yes',
            'catalog_updated_date'  => '',
            'priority_attributes'   => 'marka, marka-stali, diametr-mm, diametr, gost-tu, gost, pokrytie, tekhnologiya-izgotovleniya, tehnologiya-izgotovleniya, tolshchina-mm, tolshchina, razmer',
        );
    }

    public function get_options() {
        $opts = get_option(self::OPTION_KEY, array());
        if (!is_array($opts)) {
            $opts = array();
        }
        return wp_parse_args($opts, self::defaults());
    }

    public function get_option($key, $default = null) {
        $opts = $this->get_options();
        return isset($opts[$key]) ? $opts[$key] : $default;
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Product Category Table',
            'Category Table',
            'manage_woocommerce',
            'pct-settings',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('pct_settings', self::OPTION_KEY, array($this, 'sanitize_options'));
    }

    private function normalize_attribute_keys($value) {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach ($value as $key) {
            if (is_array($key)) {
                continue;
            }
            $key = sanitize_key((string) $key);
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public function sanitize_options($input) {
        $defaults = self::defaults();
        $out = array();
        $out['auto_category'] = isset($input['auto_category']) && $input['auto_category'] === 'yes' ? 'yes' : 'no';
        $out['per_page'] = max(5, min(200, absint($input['per_page'] ?? $defaults['per_page'])));
        $out['filter_attributes'] = $this->normalize_attribute_keys($input['filter_attributes'] ?? array());
        $out['column_attributes'] = $this->normalize_attribute_keys($input['column_attributes'] ?? array());
        $out['max_filters'] = max(1, min(10, absint($input['max_filters'] ?? $defaults['max_filters'])));
        $out['max_columns'] = max(1, min(8, absint($input['max_columns'] ?? $defaults['max_columns'])));
        $out['chips_attribute'] = sanitize_key($input['chips_attribute'] ?? '');
        $out['chips_limit'] = max(0, min(30, absint($input['chips_limit'] ?? $defaults['chips_limit'])));
        $out['price_prefix'] = sanitize_text_field($input['price_prefix'] ?? $defaults['price_prefix']);
        $out['price_unit'] = sanitize_text_field($input['price_unit'] ?? $defaults['price_unit']);
        $out['button_buy_label'] = sanitize_text_field($input['button_buy_label'] ?? $defaults['button_buy_label']);
        $out['button_request_label'] = sanitize_text_field($input['button_request_label'] ?? $defaults['button_request_label']);
        $out['show_quantity'] = isset($input['show_quantity']) && $input['show_quantity'] === 'yes' ? 'yes' : 'no';
        $out['request_email'] = sanitize_email($input['request_email'] ?? $defaults['request_email']);
        $out['phone_required'] = isset($input['phone_required']) && $input['phone_required'] === 'yes' ? 'yes' : 'no';
        $out['catalog_updated_date'] = sanitize_text_field($input['catalog_updated_date'] ?? '');
        $out['priority_attributes'] = sanitize_text_field($input['priority_attributes'] ?? $defaults['priority_attributes']);
        $this->bump_cache_version();
        return $out;
    }

    public function bump_cache_version() {
        update_option('pct_cache_version', time(), false);
    }

    public function cache_version() {
        return (string) get_option('pct_cache_version', '1');
    }

    public function get_priority_slugs() {
        $raw = (string) $this->get_option('priority_attributes', self::defaults()['priority_attributes']);
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $slugs = array();
        foreach ($parts as $part) {
            $part = sanitize_title($part);
            if (!$part) {
                continue;
            }
            $slugs[] = strpos($part, 'pa_') === 0 ? $part : 'pa_' . $part;
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Все зарегистрированные глобальные атрибуты WooCommerce (pa_*), доступные для выбора
     * в настройках как фильтры/колонки. Локальные (не-taxonomy) атрибуты сюда не попадают —
     * плагин их не поддерживает (см. README.md).
     */
    public function get_available_attribute_taxonomies() {
        $options = array();
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return $options;
        }
        $attrs = wc_get_attribute_taxonomies();
        if ($attrs) {
            foreach ($attrs as $attr) {
                $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
                if (taxonomy_exists($taxonomy)) {
                    $options[$taxonomy] = wc_attribute_label($taxonomy) ?: $taxonomy;
                }
            }
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Итоговый список атрибутов-фильтров: явный выбор в настройках/шорткоде,
     * иначе — первые по приоритету среди реально зарегистрированных pa_* таксономий.
     */
    public function resolve_filter_taxonomies($explicit = array()) {
        $explicit = $this->normalize_attribute_keys($explicit);
        $available = $this->get_available_attribute_taxonomies();

        if ($explicit) {
            $out = array();
            foreach ($explicit as $tax) {
                if (isset($available[$tax])) {
                    $out[$tax] = $available[$tax];
                }
            }
            return $out;
        }

        $max = (int) $this->get_option('max_filters', 5);
        $out = array();
        foreach ($this->get_priority_slugs() as $tax) {
            if (isset($available[$tax]) && !isset($out[$tax])) {
                $out[$tax] = $available[$tax];
            }
            if (count($out) >= $max) {
                break;
            }
        }
        if (!$out) {
            $out = array_slice($available, 0, $max, true);
        }
        return $out;
    }

    public function resolve_column_taxonomies($explicit = array(), $filters = array()) {
        $explicit = $this->normalize_attribute_keys($explicit);
        $available = $this->get_available_attribute_taxonomies();
        $max = (int) $this->get_option('max_columns', 3);

        if ($explicit) {
            $out = array();
            foreach ($explicit as $tax) {
                if (isset($available[$tax])) {
                    $out[$tax] = $available[$tax];
                }
            }
            return array_slice($out, 0, $max, true);
        }

        // По умолчанию колонки = первые max_columns фильтров (как на анепе: Марка,
        // Диаметр, ГОСТ/ТУ повторяют часть фильтров сверху таблицы).
        return array_slice($filters, 0, $max, true);
    }

    public function settings_page() {
        require PCT_PLUGIN_DIR . 'includes/settings-page.php';
    }

    /* ---------------------------------------------------------------------
     * Вывод на фронтенде
     * ------------------------------------------------------------------ */

    public function template_include($template) {
        if (is_admin() || !function_exists('is_product_category') || !is_product_category()) {
            return $template;
        }
        $custom = PCT_PLUGIN_DIR . 'templates/archive-product-table.php';
        return file_exists($custom) ? $custom : $template;
    }

    public function maybe_enqueue_assets() {
        if (is_admin()) {
            return;
        }
        if (!is_product_category() && !$this->page_has_shortcode()) {
            return;
        }
        $this->enqueue_assets();
    }

    private function page_has_shortcode() {
        global $post;
        return $post instanceof WP_Post && has_shortcode($post->post_content, 'product_category_table');
    }

    public function enqueue_assets() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        wp_enqueue_style('pct-frontend', PCT_PLUGIN_URL . 'assets/frontend.css', array(), self::VERSION);

        if (wp_script_is('wc-add-to-cart', 'registered')) {
            wp_enqueue_script('wc-add-to-cart');
        }
        if (wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
        }

        wp_enqueue_script('pct-frontend', PCT_PLUGIN_URL . 'assets/frontend.js', array('jquery'), self::VERSION, true);
        wp_localize_script('pct-frontend', 'PCT', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('pct_request_price'),
        ));
    }

    public function shortcode($atts) {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $atts = shortcode_atts(array(
            'category' => '',
            'per_page' => '',
        ), $atts, 'product_category_table');

        $term = null;
        $category = sanitize_title($atts['category']);
        if ($category === 'all' || $category === '') {
            if ($category === '' && is_product_category()) {
                $term = get_queried_object();
            } else {
                $term = null;
            }
        } else {
            $term = get_term_by('slug', $category, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return '<div class="pct-empty">Категория не найдена.</div>';
            }
        }

        $this->enqueue_assets();

        ob_start();
        PCT_Render::instance()->render_table($term, array(
            'per_page' => $atts['per_page'] !== '' ? absint($atts['per_page']) : null,
        ));
        return ob_get_clean();
    }

    public function render_category_archive() {
        $term = get_queried_object();
        if (!$term || is_wp_error($term)) {
            return;
        }
        $this->enqueue_assets();
        PCT_Render::instance()->render_table($term, array());
    }
}

PCT_Plugin::instance();
