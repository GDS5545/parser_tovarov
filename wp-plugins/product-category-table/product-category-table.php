<?php
/**
 * Plugin Name: Product Category Table
 * Plugin URI:  https://example.local/
 * Description: Табличный вывод товаров WooCommerce в категориях: быстрые фильтры по атрибутам (автоматически по каждой категории), сортируемые колонки характеристик, кнопки «Заказать» / «Узнать цену», плавающая корзина.
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
 * Характеристики товара читаются в двух вариантах:
 * - глобальные атрибуты WooCommerce (pa_*), назначенные прямо на товар — через
 *   tax_query/get_terms, как и раньше;
 * - локальные (не-taxonomy) атрибуты товара — то, что выводится на вкладке «Дополнительная
 *   информация»/«Детали» карточки, но не привязано к глобальной таксономии. Такие значения
 *   зеркалируются в отдельный postmeta классом PCT_Attributes (см. class-pct-attributes.php)
 *   и после этого тоже фильтруются/сортируются обычным SQL (meta_query/orderby=meta_value),
 *   а не перебором товаров.
 * Значения, спрятанные только в вариациях (а не на родительском товаре), этот плагин не
 * читает — см. README.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PCT_PLUGIN_FILE', __FILE__);
define('PCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PCT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PCT_PLUGIN_DIR . 'includes/class-pct-attributes.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-query.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-render.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-ajax.php';
require_once PCT_PLUGIN_DIR . 'includes/class-pct-cart.php';

final class PCT_Plugin {
    const OPTION_KEY = 'pct_options';
    const VERSION = '1.2.1';

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

        PCT_Attributes::init();
        PCT_Query::init();
        PCT_Ajax::init($this);
        PCT_Cart::init();

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
            'max_filters'           => 0,
            'max_columns'           => 0,
            'chips_attribute'       => '',
            'chips_limit'           => 8,
            'price_prefix'          => 'от ',
            'price_unit'            => 'руб./кг',
            'button_buy_label'      => 'Заказать',
            'button_request_label'  => 'Узнать цену',
            'show_quantity'         => 'yes',
            'floating_cart'         => 'yes',
            'request_email'         => get_option('admin_email'),
            'phone_required'        => 'yes',
            'catalog_updated_date'  => '',
            'priority_attributes'   => 'marka, marka-stali, diametr-mm, diametr, gost-tu, gost, pokrytie, tekhnologiya-izgotovleniya, tehnologiya-izgotovleniya, tolshchina-mm, tolshchina, dlina-mm, dlina, shirina-mm, shirina, razmer',
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

    /**
     * sanitize_key() вырезает двоеточие/другие небезопасные символы, но пропускает дефис —
     * поэтому идентификаторы локальных атрибутов используют префикс "local-" (а не "local:"),
     * чтобы пережить sanitize_key() и без проблем ходить в GET-параметрах (?pct[local-marka]=...).
     */
    private function sanitize_attribute_id($value) {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'local-') === 0) {
            $tail = sanitize_key(substr($value, 6));
            return $tail !== '' ? 'local-' . $tail : '';
        }
        return sanitize_key($value);
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
            $key = $this->sanitize_attribute_id((string) $key);
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
        // 0 = без ограничения (показывать все реально найденные у товаров категории характеристики).
        $out['max_filters'] = min(20, absint($input['max_filters'] ?? $defaults['max_filters']));
        $out['max_columns'] = min(20, absint($input['max_columns'] ?? $defaults['max_columns']));
        $out['chips_attribute'] = $this->sanitize_attribute_id($input['chips_attribute'] ?? '');
        $out['chips_limit'] = max(0, min(30, absint($input['chips_limit'] ?? $defaults['chips_limit'])));
        $out['price_prefix'] = sanitize_text_field($input['price_prefix'] ?? $defaults['price_prefix']);
        $out['price_unit'] = sanitize_text_field($input['price_unit'] ?? $defaults['price_unit']);
        $out['button_buy_label'] = sanitize_text_field($input['button_buy_label'] ?? $defaults['button_buy_label']);
        $out['button_request_label'] = sanitize_text_field($input['button_request_label'] ?? $defaults['button_request_label']);
        $out['show_quantity'] = isset($input['show_quantity']) && $input['show_quantity'] === 'yes' ? 'yes' : 'no';
        $out['floating_cart'] = isset($input['floating_cart']) && $input['floating_cart'] === 'yes' ? 'yes' : 'no';
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

    /**
     * Каждая запись приоритета даёт два кандидата — taxonomy-вариант (pa_marka) и
     * локальный (local-marka), — потому что заранее не известно, как именно на конкретном
     * сайте заведён этот атрибут. order_by_priority() использует тот кандидат, который
     * реально обнаружен у товаров категории.
     */
    public function get_priority_slugs() {
        $raw = (string) $this->get_option('priority_attributes', self::defaults()['priority_attributes']);
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $slugs = array();
        foreach ($parts as $part) {
            $part = sanitize_title($part);
            if (!$part) {
                continue;
            }
            $base = strpos($part, 'pa_') === 0 ? substr($part, 3) : $part;
            $slugs[] = 'pa_' . $base;
            $slugs[] = 'local-' . $base;
        }
        return array_values(array_unique($slugs));
    }

    /**
     * Только зарегистрированные глобальные атрибуты WooCommerce (pa_*) — используется
     * при автоопределении (PCT_Query::detect_used_attributes()), где локальные атрибуты
     * проверяются отдельно через реестр PCT_Attributes.
     */
    public function get_available_taxonomy_attributes() {
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
     * Глобальные атрибуты WooCommerce (pa_*) + известные локальные атрибуты товара
     * (id вида "local-<ключ>", см. PCT_Attributes) — полный список для выбора в настройках
     * и для проверки допустимости явно заданных id фильтров/колонок/chips.
     */
    public function get_available_attribute_taxonomies() {
        $options = $this->get_available_taxonomy_attributes();
        foreach (PCT_Attributes::local_registry() as $key => $label) {
            $options['local-' . $key] = $label;
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Упорядочивает обнаруженные у товаров категории атрибуты: сначала по списку
     * приоритета из настроек, затем остальные — в их естественном (алфавитном) порядке.
     */
    private function order_by_priority($detected) {
        $ordered = array();
        foreach ($this->get_priority_slugs() as $tax) {
            if (isset($detected[$tax]) && !isset($ordered[$tax])) {
                $ordered[$tax] = $detected[$tax];
            }
        }
        foreach ($detected as $tax => $label) {
            if (!isset($ordered[$tax])) {
                $ordered[$tax] = $label;
            }
        }
        return $ordered;
    }

    /**
     * Список атрибутов-фильтров для категории: по умолчанию — ВСЕ атрибуты WooCommerce
     * (pa_*), которые реально назначены хотя бы одному товару в этой категории (включая
     * подкатегории). У разных категорий набор характеристик разный (Марка/Диаметр/ГОСТ у
     * круга, Марка/Толщина/Покрытие у листа и т.д.) — фильтры подстраиваются сами.
     * Явный список в настройках/шорткоде принудительно переопределяет автоопределение.
     */
    public function resolve_filter_taxonomies($term_id, $product_ids, $explicit = array()) {
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

        $detected = PCT_Query::detect_used_attributes($term_id, $product_ids);
        $ordered = $this->order_by_priority($detected);

        $max = (int) $this->get_option('max_filters', 0);
        return $max > 0 ? array_slice($ordered, 0, $max, true) : $ordered;
    }

    public function resolve_column_taxonomies($explicit = array(), $filters = array()) {
        $explicit = $this->normalize_attribute_keys($explicit);
        $max = (int) $this->get_option('max_columns', 0);

        if ($explicit) {
            $available = $this->get_available_attribute_taxonomies();
            $out = array();
            foreach ($explicit as $tax) {
                if (isset($available[$tax])) {
                    $out[$tax] = $available[$tax];
                }
            }
            return $max > 0 ? array_slice($out, 0, $max, true) : $out;
        }

        // По умолчанию колонки таблицы = те же атрибуты, что обнаружены как фильтры
        // (т.е. реально есть у товаров этой категории), при желании — с урезанием сверху.
        return $max > 0 ? array_slice($filters, 0, $max, true) : $filters;
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
        $floating_cart = $this->get_option('floating_cart', 'yes') === 'yes';
        // Плавающая корзина видна на всех страницах сайта (чтобы добавленные из категории
        // товары не терялись при переходах), поэтому в этом режиме грузим ассеты везде.
        if (!$floating_cart && !is_product_category() && !$this->page_has_shortcode()) {
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
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('pct_request_price'),
            'cart_nonce' => wp_create_nonce('pct_cart'),
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
