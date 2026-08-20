<?php
/**
 * Plugin Name: ANEP Woo Category Table
 * Plugin URI:  https://example.local/
 * Description: Табличный вывод товаров WooCommerce в категориях и Elementor с фильтрами по атрибутам, кнопками «Узнать цену» / «Купить».
 * Version:     3.7.1
 * Author:      ChatGPT
 * Text Domain: anep-woo-table
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ANEP_Woo_Category_Table {
    const OPTION_KEY = 'anep_wct_options';
    const VERSION = '3.7.1';

    private static $instance = null;
    private static $assets_enqueued = false;
    private $force_cart_product_ids = array();

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
        add_action('woocommerce_attribute_added', array($this, 'bump_cache_version'));
        add_action('woocommerce_attribute_updated', array($this, 'bump_cache_version'));
        add_action('wp_ajax_anep_wct_request', array($this, 'ajax_request'));
        add_action('wp_ajax_nopriv_anep_wct_request', array($this, 'ajax_request'));
        add_action('wp_ajax_anep_wct_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_anep_wct_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_filter('woocommerce_is_purchasable', array($this, 'force_purchasable_for_no_price'), 30, 2);
        add_filter('woocommerce_variation_is_purchasable', array($this, 'force_purchasable_for_no_price'), 30, 2);
        add_filter('woocommerce_product_is_in_stock', array($this, 'force_in_stock_for_table_cart_items'), 30, 2);
        add_filter('woocommerce_product_get_stock_status', array($this, 'force_stock_status_for_table_cart_items'), 30, 2);
        add_filter('woocommerce_product_variation_get_stock_status', array($this, 'force_stock_status_for_table_cart_items'), 30, 2);
        add_filter('woocommerce_variation_is_active', array($this, 'force_variation_active_for_table_items'), 30, 2);
        add_filter('woocommerce_add_to_cart_validation', array($this, 'force_add_to_cart_validation_for_table_items'), 30, 5);
        add_action('woocommerce_before_calculate_totals', array($this, 'set_zero_price_for_table_cart_items'), 20);
        add_shortcode('anep_category_table', array($this, 'shortcode'));
        add_action('elementor/widgets/register', array($this, 'register_elementor_widget'));
        // В архивах Elementor/WooCommerce рендер виджета часто происходит уже после wp_head.
        // Если подключать CSS только внутри render_table(), стили могут не попасть в страницу.
        // Поэтому подключаем небольшой frontend-набор заранее. Файлы маленькие, зато шаблон архива
        // и обычная страница получают один и тот же CSS/JS стабильно.
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_front_assets'), 20);
    }

    public function load() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        if ($this->get_option('auto_category', 'yes') === 'yes') {
            add_filter('template_include', array($this, 'template_include'), 99);
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>ANEP Woo Category Table</strong>: требуется активный WooCommerce.</p></div>';
    }

    public static function defaults() {
        return array(
            'auto_category'       => 'yes',
            'per_page'            => 50,
            'max_filters'         => 6,
            'max_columns'         => 6,
            'filter_attributes'   => array(),
            'column_attributes'   => array(),
            'hide_empty_columns'   => 'yes',
            'mobile_compact'        => 'yes',
            'show_name_column'    => 'yes',
            'show_sku_column'     => 'no',
            'show_price_column'   => 'no',
            'show_actions_column' => 'yes',
            'button_mode'         => 'smart',
            'style_source'         => 'theme',
            'allow_cart_no_price'  => 'yes',
            'show_quantity'        => 'yes',
            'show_cart_summary'    => 'yes',
            'suppress_cart_popup'  => 'yes',
            'large_category_fast_mode' => 'yes',
            'large_category_threshold' => 300,
            'custom_accent_color'       => '#ff1f2d',
            'custom_text_color'         => '#242933',
            'custom_muted_color'        => '#8d96a3',
            'custom_line_color'         => '#e7ebf0',
            'custom_background_color'   => '#ffffff',
            'custom_filter_background'  => '#f7f8fa',
            'custom_table_background'   => '#ffffff',
            'custom_header_background'  => '#ffffff',
            'custom_header_color'       => '#6b7280',
            'custom_row_hover_color'    => '#fafafa',
            'custom_button_text_color'  => '#ffffff',
            'custom_button_radius'      => 4,
            'custom_cell_padding_y'     => 16,
            'custom_cell_padding_x'     => 14,
            'custom_button_padding_y'   => 12,
            'custom_button_padding_x'   => 16,
            'custom_font_size'          => 15,
            'custom_table_min_width'    => 760,
            'custom_quantity_width'     => 86,
            'request_email'       => get_option('admin_email'),
            'phone_required'      => 'yes',
            'priority_attributes' => 'marka, marka-stali, gost-tu, gost, diametr-mm, diametr, tolshchina-mm, tolshchina, razmer, tekhnologiya-izgotovleniya, tehnologiya-izgotovleniya',
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
            'ANEP Table',
            'ANEP Table',
            'manage_woocommerce',
            'anep-wct',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('anep_wct_settings', self::OPTION_KEY, array($this, 'sanitize_options'));
    }

    public function sanitize_options($input) {
        $defaults = self::defaults();
        $out = array();
        $out['auto_category'] = isset($input['auto_category']) && $input['auto_category'] === 'yes' ? 'yes' : 'no';
        $out['per_page'] = max(5, min(300, absint($input['per_page'] ?? $defaults['per_page'])));
        $out['max_filters'] = max(1, min(20, absint($input['max_filters'] ?? $defaults['max_filters'])));
        $out['max_columns'] = max(1, min(12, absint($input['max_columns'] ?? $defaults['max_columns'])));
        $out['filter_attributes'] = $this->normalize_attribute_keys($input['filter_attributes'] ?? array());
        $out['column_attributes'] = $this->normalize_attribute_keys($input['column_attributes'] ?? array());
        $out['hide_empty_columns'] = isset($input['hide_empty_columns']) && $input['hide_empty_columns'] === 'yes' ? 'yes' : 'no';
        $out['mobile_compact'] = isset($input['mobile_compact']) && $input['mobile_compact'] === 'yes' ? 'yes' : 'no';
        $out['show_name_column'] = isset($input['show_name_column']) && $input['show_name_column'] === 'yes' ? 'yes' : 'no';
        $out['show_sku_column'] = isset($input['show_sku_column']) && $input['show_sku_column'] === 'yes' ? 'yes' : 'no';
        $out['show_price_column'] = isset($input['show_price_column']) && $input['show_price_column'] === 'yes' ? 'yes' : 'no';
        $out['show_actions_column'] = isset($input['show_actions_column']) && $input['show_actions_column'] === 'yes' ? 'yes' : 'no';
        $mode = sanitize_key($input['button_mode'] ?? $defaults['button_mode']);
        $out['button_mode'] = in_array($mode, array('smart', 'request_only', 'cart_only'), true) ? $mode : 'smart';
        $style_source = sanitize_key($input['style_source'] ?? $defaults['style_source']);
        $out['style_source'] = in_array($style_source, array('elementor', 'theme', 'anep', 'custom'), true) ? $style_source : 'theme';
        $out['allow_cart_no_price'] = isset($input['allow_cart_no_price']) && $input['allow_cart_no_price'] === 'yes' ? 'yes' : 'no';
        $out['show_quantity'] = isset($input['show_quantity']) && $input['show_quantity'] === 'yes' ? 'yes' : 'no';
        $out['show_cart_summary'] = isset($input['show_cart_summary']) && $input['show_cart_summary'] === 'yes' ? 'yes' : 'no';
        $out['suppress_cart_popup'] = isset($input['suppress_cart_popup']) && $input['suppress_cart_popup'] === 'yes' ? 'yes' : 'no';
        $out['large_category_fast_mode'] = isset($input['large_category_fast_mode']) && $input['large_category_fast_mode'] === 'yes' ? 'yes' : 'no';
        $out['large_category_threshold'] = max(50, min(5000, absint($input['large_category_threshold'] ?? $defaults['large_category_threshold'])));
        foreach ($this->custom_color_option_keys() as $color_key) {
            $raw_color = isset($input[$color_key]) ? sanitize_hex_color($input[$color_key]) : '';
            $out[$color_key] = $raw_color ?: $defaults[$color_key];
        }
        foreach ($this->custom_number_option_keys() as $number_key => $limits) {
            $raw = isset($input[$number_key]) ? absint($input[$number_key]) : $defaults[$number_key];
            $out[$number_key] = max($limits[0], min($limits[1], $raw));
        }
        $out['request_email'] = sanitize_email($input['request_email'] ?? $defaults['request_email']);
        $out['phone_required'] = isset($input['phone_required']) && $input['phone_required'] === 'yes' ? 'yes' : 'no';
        $out['priority_attributes'] = sanitize_text_field($input['priority_attributes'] ?? $defaults['priority_attributes']);
        $this->bump_cache_version();
        return $out;
    }



    private function custom_color_option_keys() {
        return array(
            'custom_accent_color',
            'custom_text_color',
            'custom_muted_color',
            'custom_line_color',
            'custom_background_color',
            'custom_filter_background',
            'custom_table_background',
            'custom_header_background',
            'custom_header_color',
            'custom_row_hover_color',
            'custom_button_text_color',
        );
    }

    private function custom_number_option_keys() {
        return array(
            'custom_button_radius'    => array(0, 60),
            'custom_cell_padding_y'   => array(4, 60),
            'custom_cell_padding_x'   => array(4, 80),
            'custom_button_padding_y' => array(4, 40),
            'custom_button_padding_x' => array(6, 80),
            'custom_font_size'        => array(11, 24),
            'custom_table_min_width'  => array(420, 1600),
            'custom_quantity_width'   => array(56, 180),
        );
    }

    public function normalize_attribute_keys($value) {
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

    public function get_all_attribute_options() {
        $options = array();

        if (function_exists('wc_get_attribute_taxonomies')) {
            $attrs = wc_get_attribute_taxonomies();
            if ($attrs) {
                foreach ($attrs as $attr) {
                    $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
                    $label = wc_attribute_label($taxonomy);
                    $options[$taxonomy] = $label ?: $taxonomy;
                }
            }
        }

        // Локальные атрибуты товаров тоже добавляем в список выбора.
        // Ограничение сделано, чтобы админка не зависала на очень больших каталогах.
        $ids = get_posts(array(
            'post_type'              => 'product',
            'post_status'            => array('publish', 'draft', 'private'),
            'fields'                 => 'ids',
            'posts_per_page'         => 1200,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            foreach ($product->get_attributes() as $attribute) {
                if (!is_a($attribute, 'WC_Product_Attribute')) {
                    continue;
                }
                $name = $attribute->get_name();
                if (!$name) {
                    continue;
                }
                if ($attribute->is_taxonomy()) {
                    $key = sanitize_key($name);
                    if ($key && !isset($options[$key])) {
                        $options[$key] = wc_attribute_label($key) ?: $key;
                    }
                } else {
                    $key = 'attr_' . sanitize_title($name);
                    $key = sanitize_key($key);
                    if ($key && $key !== 'attr_' && !isset($options[$key])) {
                        $options[$key] = $name;
                    }
                }
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    private function render_attribute_multiselect($name, $selected, $options, $placeholder = '') {
        $selected = $this->normalize_attribute_keys($selected);
        echo '<select multiple size="10" style="min-width:360px;max-width:100%;" name="' . esc_attr(self::OPTION_KEY . '[' . $name . '][]') . '">';
        foreach ($options as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected(in_array($key, $selected, true), true, false) . '>' . esc_html($label . ' (' . $key . ')') . '</option>';
        }
        echo '</select>';
        if ($placeholder) {
            echo '<p class="description">' . wp_kses_post($placeholder) . '</p>';
        }
    }

    public function settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $opts = $this->get_options();
        $attr_options = $this->get_all_attribute_options();
        ?>
        <div class="wrap">
            <h1>ANEP Woo Category Table</h1>
            <p>Плагин выводит товары WooCommerce таблицей как в каталоге ANEP: фильтры по атрибутам, колонки характеристик и кнопки заявки/покупки.</p>
            <form method="post" action="options.php">
                <?php settings_fields('anep_wct_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Автоматически на категориях</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_category]" value="yes" <?php checked($opts['auto_category'], 'yes'); ?>> включить для всех product_cat</label>
                            <p class="description">Если категория собрана через Elementor, можно выключить и поставить виджет вручную.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Товаров на странице</th>
                        <td><input type="number" min="5" max="300" name="<?php echo esc_attr(self::OPTION_KEY); ?>[per_page]" value="<?php echo esc_attr($opts['per_page']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Максимум фильтров</th>
                        <td><input type="number" min="1" max="20" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_filters]" value="<?php echo esc_attr($opts['max_filters']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Максимум колонок таблицы всего</th>
                        <td><input type="number" min="1" max="12" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_columns]" value="<?php echo esc_attr($opts['max_columns']); ?>">
                        <p class="description">Считаются все видимые колонки: Наименование, Артикул, Цена, атрибуты, Кол-во и Кнопки. Например, 6 = не больше 6 колонок на экране.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Фильтры по умолчанию</th>
                        <td>
                            <?php $this->render_attribute_multiselect('filter_attributes', $opts['filter_attributes'], $attr_options, 'Зажми Ctrl / Cmd, чтобы выбрать несколько. Если ничего не выбрано, плагин сам возьмет первые атрибуты по приоритету.'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Колонки таблицы по умолчанию</th>
                        <td>
                            <?php $this->render_attribute_multiselect('column_attributes', $opts['column_attributes'], $attr_options, 'Это колонки характеристик. Наименование, количество и кнопки считаются в общий лимит колонок ниже. Если выбрано больше, чем помещается в лимит, лишние характеристики будут скрыты.'); ?>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[hide_empty_columns]" value="yes" <?php checked($opts['hide_empty_columns'], 'yes'); ?>> Скрывать пустые столбцы характеристик</label></p>
                            <p class="description">Если у товаров в текущей категории/выборке нет значения, например «Толщина», такая колонка не будет показана. Когда в другой категории значение есть — колонка появится автоматически.</p>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mobile_compact]" value="yes" <?php checked($opts['mobile_compact'], 'yes'); ?>> На мобильной версии показывать только наименование и кнопки</label></p>
                            <p class="description">Остальные столбцы остаются на десктопе, но на телефоне скрываются, чтобы список товаров был удобным как карточки/строки.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Системные колонки</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_name_column]" value="yes" <?php checked($opts['show_name_column'], 'yes'); ?>> Наименование</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_sku_column]" value="yes" <?php checked($opts['show_sku_column'], 'yes'); ?>> Артикул</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_price_column]" value="yes" <?php checked($opts['show_price_column'], 'yes'); ?>> Цена</label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_actions_column]" value="yes" <?php checked($opts['show_actions_column'], 'yes'); ?>> Кнопки «Узнать цену» и «Купить»</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Режим кнопки «Купить»</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[button_mode]">
                                <option value="smart" <?php selected($opts['button_mode'], 'smart'); ?>>Умно: корзина, если товар покупаемый; иначе заявка</option>
                                <option value="request_only" <?php selected($opts['button_mode'], 'request_only'); ?>>Всегда заявка</option>
                                <option value="cart_only" <?php selected($opts['button_mode'], 'cart_only'); ?>>Всегда WooCommerce корзина/страница товара</option>
                            </select>
                            <p class="description">Если у товаров нет цены, включи опцию ниже — тогда плагин будет добавлять их в корзину как товары с ценой 0, чтобы заявка/заказ собирались через корзину.</p>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_cart_no_price]" value="yes" <?php checked($opts['allow_cart_no_price'], 'yes'); ?>> Разрешить добавление в корзину товаров без цены</label></p>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_quantity]" value="yes" <?php checked($opts['show_quantity'], 'yes'); ?>> Показывать поле количества в строке товара</label></p>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_cart_summary]" value="yes" <?php checked($opts['show_cart_summary'], 'yes'); ?>> Показывать блок «В корзине / Оформить заказ» над таблицей</label></p>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[suppress_cart_popup]" value="yes" <?php checked($opts['suppress_cart_popup'], 'yes'); ?>> Добавлять тихо, без вызова всплывающей мини-корзины темы</label></p>
                            <p class="description">Покупатель сможет зайти в одну категорию, добавить позиции с количеством, перейти в другую категорию, добавить ещё и потом оформить общий заказ из корзины WooCommerce.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Оптимизация больших категорий</th>
                        <td>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[large_category_fast_mode]" value="yes" <?php checked($opts['large_category_fast_mode'], 'yes'); ?>> Быстрый режим для больших/родительских категорий</label></p>
                            <p>Порог: <input type="number" min="50" max="5000" name="<?php echo esc_attr(self::OPTION_KEY); ?>[large_category_threshold]" value="<?php echo esc_attr($opts['large_category_threshold']); ?>"> товаров в категории вместе с дочерними.</p>
                            <p class="description">Когда в родительской категории много товаров, плагин не строит полную матрицу всех вариаций при первом открытии. Страница открывается быстрее, а полный точный индекс строится только после выбора фильтра/сортировки и затем кешируется.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Стили по умолчанию</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[style_source]">
                                <option value="elementor" <?php selected($opts['style_source'], 'elementor'); ?>>Минимальный стиль / для Elementor-шаблонов</option>
                                <option value="theme" <?php selected($opts['style_source'], 'theme'); ?>>Брать из темы / Elementor Global Styles</option>
                                <option value="anep" <?php selected($opts['style_source'], 'anep'); ?>>Встроенный красный стиль ANEP</option>
                                <option value="custom" <?php selected($opts['style_source'], 'custom'); ?>>Свои стили из админки</option>
                            </select>
                            <p class="description">Для шаблона Elementor лучше выбрать в самом виджете режим «Стили Elementor». Тогда настройки вкладки «Стиль» будут иметь приоритет и не будут перебиваться стилями из админки. Режим «Свои стили из админки» нужен для страниц без Elementor.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Стили из админки</th>
                        <td>
                            <p class="description">Эти параметры работают, когда выше выбран режим <strong>«Свои стили из админки»</strong>. Они нужны, если страница без Elementor или тема почти не задаёт оформление.</p>
                            <table class="widefat striped" style="max-width:980px;margin-top:10px">
                                <tbody>
                                    <tr><td>Акцентный цвет</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_accent_color]" value="<?php echo esc_attr($opts['custom_accent_color']); ?>" class="regular-text" placeholder="#ff1f2d"></td><td>Кнопка «Купить», активные элементы</td></tr>
                                    <tr><td>Основной текст</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_text_color]" value="<?php echo esc_attr($opts['custom_text_color']); ?>" class="regular-text"></td><td>Текст таблицы и блока</td></tr>
                                    <tr><td>Приглушенный текст</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_muted_color]" value="<?php echo esc_attr($opts['custom_muted_color']); ?>" class="regular-text"></td><td>Подписи, пустые значения</td></tr>
                                    <tr><td>Линии / рамки</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_line_color]" value="<?php echo esc_attr($opts['custom_line_color']); ?>" class="regular-text"></td><td>Границы фильтров и строк</td></tr>
                                    <tr><td>Фон блока</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_background_color]" value="<?php echo esc_attr($opts['custom_background_color']); ?>" class="regular-text"></td><td>Общий фон виджета</td></tr>
                                    <tr><td>Фон фильтров</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_filter_background]" value="<?php echo esc_attr($opts['custom_filter_background']); ?>" class="regular-text"></td><td>Секция фильтров</td></tr>
                                    <tr><td>Фон таблицы</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_table_background]" value="<?php echo esc_attr($opts['custom_table_background']); ?>" class="regular-text"></td><td>Белый фон таблицы</td></tr>
                                    <tr><td>Фон шапки таблицы</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_header_background]" value="<?php echo esc_attr($opts['custom_header_background']); ?>" class="regular-text"></td><td>Заголовки колонок</td></tr>
                                    <tr><td>Цвет шапки таблицы</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_header_color]" value="<?php echo esc_attr($opts['custom_header_color']); ?>" class="regular-text"></td><td>Текст заголовков</td></tr>
                                    <tr><td>Фон строки при наведении</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_row_hover_color]" value="<?php echo esc_attr($opts['custom_row_hover_color']); ?>" class="regular-text"></td><td>Hover строки</td></tr>
                                    <tr><td>Цвет текста кнопки «Купить»</td><td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_button_text_color]" value="<?php echo esc_attr($opts['custom_button_text_color']); ?>" class="regular-text"></td><td>Обычно #ffffff</td></tr>
                                    <tr><td>Скругление кнопок/полей, px</td><td><input type="number" min="0" max="60" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_button_radius]" value="<?php echo esc_attr($opts['custom_button_radius']); ?>"></td><td>Радиус кнопок и select</td></tr>
                                    <tr><td>Отступ ячеек Y/X, px</td><td><input type="number" min="4" max="60" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_cell_padding_y]" value="<?php echo esc_attr($opts['custom_cell_padding_y']); ?>"> <input type="number" min="4" max="80" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_cell_padding_x]" value="<?php echo esc_attr($opts['custom_cell_padding_x']); ?>"></td><td>Высота строк таблицы</td></tr>
                                    <tr><td>Отступ кнопок Y/X, px</td><td><input type="number" min="4" max="40" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_button_padding_y]" value="<?php echo esc_attr($opts['custom_button_padding_y']); ?>"> <input type="number" min="6" max="80" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_button_padding_x]" value="<?php echo esc_attr($opts['custom_button_padding_x']); ?>"></td><td>Размер кнопок</td></tr>
                                    <tr><td>Размер шрифта, px</td><td><input type="number" min="11" max="24" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_font_size]" value="<?php echo esc_attr($opts['custom_font_size']); ?>"></td><td>Таблица, фильтры, кнопки</td></tr>
                                    <tr><td>Минимальная ширина таблицы, px</td><td><input type="number" min="420" max="1600" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_table_min_width]" value="<?php echo esc_attr($opts['custom_table_min_width']); ?>"></td><td>Для горизонтального скролла на мобильном</td></tr>
                                    <tr><td>Ширина поля количества, px</td><td><input type="number" min="56" max="180" name="<?php echo esc_attr(self::OPTION_KEY); ?>[custom_quantity_width]" value="<?php echo esc_attr($opts['custom_quantity_width']); ?>"></td><td>Поле количества возле кнопки</td></tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Email для заявок</th>
                        <td><input type="email" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[request_email]" value="<?php echo esc_attr($opts['request_email']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Телефон обязателен</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[phone_required]" value="yes" <?php checked($opts['phone_required'], 'yes'); ?>> да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Приоритет атрибутов</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPTION_KEY); ?>[priority_attributes]" rows="3" class="large-text"><?php echo esc_textarea($opts['priority_attributes']); ?></textarea>
                            <p class="description">Через запятую. Можно писать хвост slug без <code>pa_</code>: <code>marka, gost-tu, diametr-mm</code>. Эти атрибуты первыми попадут в фильтры и колонки таблицы.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Elementor</h2>
            <p>В Elementor добавлен виджет <strong>ANEP таблица товаров</strong>. Его можно найти в группе <strong>WooCommerce</strong> или поиском по слову <strong>ANEP</strong>.</p>
            <h2>Шорткод</h2>
            <p>Для текущей категории:</p>
            <code>[anep_category_table]</code>
            <p>Для конкретной категории:</p>
            <code>[anep_category_table category="krug-otsinkovannyj"]</code>
            <p>Для всех товаров:</p>
            <code>[anep_category_table category="all"]</code>
        </div>
        <?php
    }

    public function register_elementor_widget($widgets_manager) {
        if (!class_exists('WooCommerce') || !class_exists('\\Elementor\\Widget_Base')) {
            return;
        }
        $file = plugin_dir_path(__FILE__) . 'includes/class-anep-wct-elementor-widget.php';
        if (file_exists($file)) {
            require_once $file;
        }
        if (class_exists('ANEP_WCT_Elementor_Widget')) {
            $widgets_manager->register(new ANEP_WCT_Elementor_Widget());
        }
    }

    public function get_product_cat_options($include_current = true, $include_all = true) {
        $options = array();
        if ($include_current) {
            $options['current'] = 'Текущая категория';
        }
        if ($include_all) {
            $options['all'] = 'Все товары';
        }
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $prefix = '';
                if (!empty($term->parent)) {
                    $ancestors = get_ancestors($term->term_id, 'product_cat');
                    $prefix = str_repeat('— ', count($ancestors));
                }
                $options[$term->slug] = $prefix . $term->name;
            }
        }
        return $options;
    }

    public function template_include($template) {
        if (is_admin() || !function_exists('is_product_category') || !is_product_category()) {
            return $template;
        }
        $custom = plugin_dir_path(__FILE__) . 'templates/archive-product-table.php';
        return file_exists($custom) ? $custom : $template;
    }

    public function shortcode($atts) {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $atts = shortcode_atts(array(
            'category'        => '',
            'per_page'        => '',
            'max_filters'     => '',
            'max_columns'     => '',
            'show_chips'      => 'yes',
            'show_filters'    => 'yes',
            'show_pagination' => 'yes',
            'filter_attributes' => '',
            'column_attributes' => '',
            'show_name'       => '',
            'show_sku'        => '',
            'show_price'      => '',
            'show_actions'    => '',
            'show_quantity'   => '',
            'show_cart_summary' => '',
            'hide_empty_columns' => '',
            'mobile_compact' => '',
            'style_source'    => '',
        ), $atts, 'anep_category_table');

        $term = null;
        $category = sanitize_title($atts['category']);
        if ($category === 'all') {
            $term = null;
        } elseif (!empty($category)) {
            $term = get_term_by('slug', $category, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return '<div class="anep-wct-empty">Категория не найдена.</div>';
            }
        } elseif (is_product_category()) {
            $term = get_queried_object();
        } else {
            return '<div class="anep-wct-empty">Укажите категорию в шорткоде: <code>[anep_category_table category="slug-kategorii"]</code> или используйте <code>category="all"</code>.</div>';
        }

        $args = array(
            'per_page'        => $atts['per_page'] !== '' ? absint($atts['per_page']) : null,
            'max_filters'     => $atts['max_filters'] !== '' ? absint($atts['max_filters']) : null,
            'max_columns'     => $atts['max_columns'] !== '' ? absint($atts['max_columns']) : null,
            'show_chips'      => $atts['show_chips'] !== 'no',
            'show_filters'    => $atts['show_filters'] !== 'no',
            'show_pagination' => $atts['show_pagination'] !== 'no',
            'filter_attributes' => $this->normalize_attribute_keys($atts['filter_attributes']),
            'column_attributes' => $this->normalize_attribute_keys($atts['column_attributes']),
            'show_name_column' => $atts['show_name'] === '' ? null : ($atts['show_name'] !== 'no'),
            'show_sku_column' => $atts['show_sku'] === '' ? null : ($atts['show_sku'] === 'yes'),
            'show_price_column' => $atts['show_price'] === '' ? null : ($atts['show_price'] === 'yes'),
            'show_actions_column' => $atts['show_actions'] === '' ? null : ($atts['show_actions'] !== 'no'),
            'show_quantity' => $atts['show_quantity'] === '' ? null : ($atts['show_quantity'] !== 'no'),
            'show_cart_summary' => $atts['show_cart_summary'] === '' ? null : ($atts['show_cart_summary'] !== 'no'),
            'hide_empty_columns' => $atts['hide_empty_columns'] === '' ? null : ($atts['hide_empty_columns'] !== 'no'),
            'mobile_compact' => $atts['mobile_compact'] === '' ? null : ($atts['mobile_compact'] !== 'no'),
            'style_source'    => in_array(sanitize_key($atts['style_source']), array('elementor', 'theme', 'anep', 'custom'), true) ? sanitize_key($atts['style_source']) : '',
            'base_url'        => $this->current_base_url($term),
        );

        ob_start();
        $this->render_table($term, $args);
        return ob_get_clean();
    }

    public function maybe_enqueue_front_assets() {
        if (is_admin()) {
            return;
        }
        $this->enqueue_assets();
    }

    public function enqueue_assets() {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'anep-wct-frontend',
            plugins_url('assets/frontend.css', __FILE__),
            array(),
            self::VERSION
        );
        wp_add_inline_style('anep-wct-frontend', $this->critical_frontend_css());
        if (wp_script_is('wc-add-to-cart', 'registered')) {
            wp_enqueue_script('wc-add-to-cart');
        }
        if (wp_script_is('wc-cart-fragments', 'registered')) {
            wp_enqueue_script('wc-cart-fragments');
        }

        wp_enqueue_script(
            'anep-wct-frontend',
            plugins_url('assets/frontend.js', __FILE__),
            array('jquery'),
            self::VERSION,
            true
        );
        wp_localize_script('anep-wct-frontend', 'ANEP_WCT', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('anep_wct_request'),
            'cart_url' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
            'suppress_cart_popup' => $this->get_option('suppress_cart_popup', 'yes') === 'yes' ? 'yes' : 'no',
        ));
    }

    private function critical_frontend_css() {
        return <<<CSS
/* v3.6 critical fallback: нужен, когда архив Elementor/WooCommerce не подхватывает внешний CSS или растягивает виджет на всю ширину. */
.elementor-widget-anep_wct_table,
.elementor-widget-anep_wct_table .elementor-widget-container,
.anep-wct-elementor-shell{box-sizing:border-box!important;max-width:100%;width:100%;float:none!important;clear:both!important;}
.anep-wct-elementor-shell.awt-shell-boxed{width:min(100%,var(--awt-shell-max-width,1200px))!important;max-width:var(--awt-shell-max-width,1200px)!important;margin-left:auto!important;margin-right:auto!important;padding-left:var(--awt-shell-pad-x,0);padding-right:var(--awt-shell-pad-x,0);}
.anep-wct-elementor-shell.awt-shell-full{max-width:none;width:100%;}
.anep-wct{box-sizing:border-box!important;width:100%!important;max-width:100%!important;}
.anep-wct-table-wrap{width:100%!important;max-width:100%!important;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.anep-wct-table{border-collapse:collapse;border-spacing:0;}
.anep-wct.anep-wct-style-elementor{--awt-red:var(--e-global-color-primary,#ff1f2d);--awt-e-color-fallback:#242933;--awt-e-line-fallback:#dfe5ec;--awt-e-soft-fallback:#f7f8fa;--awt-e-field-fallback:#fff;color:var(--awt-e-color,var(--awt-e-color-fallback));background-color:var(--awt-e-bg,transparent);margin:var(--awt-e-general-margin,24px 0 50px);padding:var(--awt-e-general-padding,0);}
.anep-wct-style-elementor *,.anep-wct-style-elementor *:before,.anep-wct-style-elementor *:after{box-sizing:border-box;}
.anep-wct-style-elementor .anep-wct-filter-wrap{margin:0 0 28px!important;padding:var(--awt-e-filter-padding,24px)!important;background-color:var(--awt-e-filter-bg,var(--awt-e-soft-fallback))!important;border:1px solid var(--awt-e-row-border-color,var(--awt-e-line-fallback))!important;border-radius:8px!important;}
.anep-wct-style-elementor .anep-wct-filter-title{color:var(--awt-e-filter-title-color,var(--awt-e-color,var(--awt-e-color-fallback)))!important;font-size:24px;line-height:1.2;margin:0 0 18px!important;font-weight:700;}
.anep-wct-style-elementor .anep-wct-filter-form{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(210px,1fr))!important;gap:var(--awt-e-filter-gap,16px)!important;align-items:start!important;}
.anep-wct-style-elementor .anep-wct-filter-toggle,.anep-wct-style-elementor .anep-wct-filter-select,.anep-wct-style-elementor .anep-wct-filter-search,.anep-wct-style-elementor .anep-wct-filter-panel{color:var(--awt-e-filter-color,var(--awt-e-color,var(--awt-e-color-fallback)))!important;background-color:var(--awt-e-filter-field-bg,var(--awt-e-field-fallback))!important;border:var(--awt-e-filter-border,1px solid var(--awt-e-line-fallback))!important;border-radius:var(--awt-e-filter-radius,4px)!important;}
.anep-wct-style-elementor .anep-wct-filter-toggle,.anep-wct-style-elementor .anep-wct-filter-select,.anep-wct-style-elementor .anep-wct-filter-search{min-height:48px!important;padding:var(--awt-e-filter-field-padding,0 16px)!important;box-shadow:none!important;outline:0!important;width:100%!important;}
.anep-wct-style-elementor .anep-wct-filter-panel{box-shadow:0 14px 35px rgba(16,24,40,.14)!important;padding:12px!important;z-index:30;}
.anep-wct-style-elementor .anep-wct-table-wrap{width:100%;overflow-x:auto;background-color:var(--awt-e-table-bg,var(--awt-e-field-fallback))!important;}
.anep-wct-style-elementor .anep-wct-table,.anep-wct-style-elementor table.anep-wct-table.shop_table{width:100%!important;min-width:760px;border-collapse:collapse!important;border-spacing:0!important;border:var(--awt-e-table-border,1px solid var(--awt-e-row-border-color,var(--awt-e-line-fallback)))!important;background-color:var(--awt-e-table-bg,var(--awt-e-field-fallback))!important;margin:0!important;}
.anep-wct-style-elementor .anep-wct-table th,.anep-wct-style-elementor .anep-wct-table td,.anep-wct-style-elementor table.anep-wct-table.shop_table th,.anep-wct-style-elementor table.anep-wct-table.shop_table td{padding:var(--awt-e-cell-padding,16px 18px)!important;border:0!important;border-bottom:1px solid var(--awt-e-row-border-color,var(--awt-e-line-fallback))!important;border-right:1px solid var(--awt-e-row-border-color,var(--awt-e-line-fallback))!important;text-align:left!important;vertical-align:middle!important;color:var(--awt-e-body-color,var(--awt-e-color,var(--awt-e-color-fallback)))!important;line-height:1.35;}
.anep-wct-style-elementor .anep-wct-table thead th{background-color:var(--awt-e-header-bg,var(--awt-e-field-fallback))!important;color:var(--awt-e-header-color,var(--awt-e-color,var(--awt-e-color-fallback)))!important;font-weight:700!important;white-space:nowrap!important;}
.anep-wct-style-elementor .anep-wct-actions{display:flex!important;justify-content:flex-end!important;gap:var(--awt-e-btn-gap,10px)!important;white-space:nowrap!important;min-width:210px;}
.anep-wct-style-elementor .anep-wct-btn,.anep-wct-style-elementor .button.anep-wct-btn,.anep-wct-style-elementor button.anep-wct-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:42px!important;padding:var(--awt-e-btn-padding,10px 16px)!important;border-radius:var(--awt-e-btn-radius,4px)!important;border:1px solid transparent!important;font-weight:700!important;line-height:1.2!important;cursor:pointer!important;text-decoration:none!important;box-shadow:none!important;}
.anep-wct-style-elementor .anep-wct-btn-outline{color:var(--awt-e-price-btn-color,var(--awt-red))!important;background-color:var(--awt-e-price-btn-bg,#fff)!important;border:1px solid var(--awt-e-price-btn-border,var(--awt-red))!important;}
.anep-wct-style-elementor .anep-wct-btn-red{color:var(--awt-e-buy-btn-color,#fff)!important;background-color:var(--awt-e-buy-btn-bg,var(--awt-red))!important;border:1px solid var(--awt-e-buy-btn-bg,var(--awt-red))!important;}
@media(max-width:760px){.anep-wct-elementor-shell.awt-shell-boxed{padding-left:var(--awt-shell-pad-x-mobile,12px);padding-right:var(--awt-shell-pad-x-mobile,12px);} .anep-wct-style-elementor .anep-wct-filter-wrap{padding:16px!important;} .anep-wct-style-elementor .anep-wct-filter-form{grid-template-columns:1fr!important;} .anep-wct-style-elementor.anep-wct-mobile-compact .anep-wct-table{min-width:0!important;} .anep-wct-style-elementor.anep-wct-mobile-compact .anep-wct-actions{justify-content:flex-start!important;min-width:0!important;}}
CSS;
    }

    public function render_category_archive() {
        $term = get_queried_object();
        if (!$term || is_wp_error($term)) {
            return;
        }
        $this->render_table($term, array('base_url' => get_term_link($term)));
    }

    public function render_table($term = null, $args = array()) {
        $this->enqueue_assets();

        $term_id = ($term && !is_wp_error($term)) ? absint($term->term_id) : 0;
        $args = wp_parse_args($args, array(
            'per_page'        => null,
            'max_filters'     => null,
            'max_columns'     => null,
            'show_chips'      => true,
            'show_filters'    => true,
            'show_pagination' => true,
            'filter_attributes' => array(),
            'column_attributes' => array(),
            'show_name_column' => null,
            'show_sku_column' => null,
            'show_price_column' => null,
            'show_actions_column' => null,
            'show_quantity' => null,
            'show_cart_summary' => null,
            'hide_empty_columns' => null,
            'mobile_compact' => null,
            'base_url'        => $this->current_base_url($term),
            'style_source'    => '',
            'inline_style'    => '',
        ));

        $args['filter_attributes'] = $this->normalize_attribute_keys($args['filter_attributes']);
        $args['column_attributes'] = $this->normalize_attribute_keys($args['column_attributes']);
        $args['show_name_column'] = $args['show_name_column'] === null ? ($this->get_option('show_name_column', 'yes') === 'yes') : (bool) $args['show_name_column'];
        $args['show_sku_column'] = $args['show_sku_column'] === null ? ($this->get_option('show_sku_column', 'no') === 'yes') : (bool) $args['show_sku_column'];
        $args['show_price_column'] = $args['show_price_column'] === null ? ($this->get_option('show_price_column', 'no') === 'yes') : (bool) $args['show_price_column'];
        $args['show_actions_column'] = $args['show_actions_column'] === null ? ($this->get_option('show_actions_column', 'yes') === 'yes') : (bool) $args['show_actions_column'];
        $args['show_quantity'] = $args['show_quantity'] === null ? ($this->get_option('show_quantity', 'yes') === 'yes') : (bool) $args['show_quantity'];
        $args['show_cart_summary'] = $args['show_cart_summary'] === null ? ($this->get_option('show_cart_summary', 'yes') === 'yes') : (bool) $args['show_cart_summary'];
        $args['hide_empty_columns'] = $args['hide_empty_columns'] === null ? ($this->get_option('hide_empty_columns', 'yes') === 'yes') : (bool) $args['hide_empty_columns'];
        $args['mobile_compact'] = $args['mobile_compact'] === null ? ($this->get_option('mobile_compact', 'yes') === 'yes') : (bool) $args['mobile_compact'];

        $attr_data = $this->get_category_attributes($term_id);
        $filters = $this->select_filter_attributes($attr_data, $args);
        $columns = $this->select_column_attributes($attr_data, $filters, $args);
        $args['sort_columns'] = $columns;
        $selected = $this->get_selected_filters($filters);
        $args['selected_filters'] = $selected;
        $args['active_filters'] = $filters;
        $query = $this->product_query($term, $selected, $filters, $args);
        $columns = $this->remove_empty_attribute_columns($columns, $query, $args);
        $args['sort_columns'] = $columns;

        $style_source = !empty($args['style_source']) ? sanitize_key($args['style_source']) : $this->get_option('style_source', 'theme');
        if (!in_array($style_source, array('elementor', 'theme', 'anep', 'custom'), true)) {
            $style_source = 'theme';
        }
        $style_class = 'anep-wct anep-wct-style-' . $style_source;
        if (!empty($args['mobile_compact'])) {
            $style_class .= ' anep-wct-mobile-compact';
        }
        $inline_style = '';
        if ($style_source === 'custom') {
            $inline_style .= $this->custom_style_string();
        }
        if (!empty($args['inline_style'])) {
            $inline_style .= ';' . (string) $args['inline_style'];
        }
        $style_attr = $inline_style !== '' ? ' style="' . esc_attr($inline_style) . '"' : '';
        echo '<div class="' . esc_attr($style_class) . '" data-category="' . esc_attr($term_id) . '"' . $style_attr . '>';
        if ($args['show_chips']) {
            $this->render_quick_chips($term, $filters, $args);
        }
        if ($args['show_filters']) {
            $this->render_filters($term, $filters, $selected, $args);
        } elseif (!$filters) {
            echo '<div class="anep-wct-note">Фильтры не найдены. Проверьте, что у товаров заполнены атрибуты WooCommerce.</div>';
        }
        if ($args['show_cart_summary']) {
            $this->render_cart_summary();
        }
        $this->render_products_table($query, $columns, $args);
        if ($args['show_pagination']) {
            $this->render_pagination($query, $args);
        }
        $this->render_modal();
        echo '</div>';

        wp_reset_postdata();
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
            $slugs[] = $part;
            if (strpos($part, 'pa_') !== 0) {
                $slugs[] = 'pa_' . $part;
                $slugs[] = 'attr_' . $part;
            }
        }
        return array_values(array_unique($slugs));
    }

    public function bump_cache_version() {
        update_option('anep_wct_cache_version', time(), false);
    }

    private function cache_version() {
        return (string) get_option('anep_wct_cache_version', '1');
    }

    private function large_category_threshold() {
        return max(50, min(5000, absint($this->get_option('large_category_threshold', 300))));
    }

    private function is_large_category_fast_mode($term_id = 0) {
        if ($this->get_option('large_category_fast_mode', 'yes') !== 'yes') {
            return false;
        }
        $ids = $this->get_category_product_ids($term_id);
        return count($ids) > $this->large_category_threshold();
    }

    /**
     * wc_get_product()/get_post() fill WP's object cache and never release it within a
     * single request. Looping over thousands of products+variations (a parent category
     * with a big subcategory tree) can exhaust the PHP memory limit before the loop even
     * finishes. Periodically dropping the runtime cache keeps memory bounded without
     * touching a persistent cache backend (Redis/Memcached) shared by other visitors.
     */
    private function release_bulk_scan_memory($count) {
        if ($count <= 0 || $count % 200 !== 0) {
            return;
        }
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        } elseif (!wp_using_ext_object_cache()) {
            wp_cache_flush();
        }
    }

    private function get_large_category_attribute_data($term_id = 0) {
        $data = array();
        $needed = array_merge(
            $this->normalize_attribute_keys($this->get_option('filter_attributes', array())),
            $this->normalize_attribute_keys($this->get_option('column_attributes', array()))
        );

        if (!$needed) {
            $max = (int) $this->get_option('max_filters', 6) + (int) $this->get_option('max_columns', 6) + 4;
            $priority = $this->get_priority_slugs();
            foreach ($priority as $key) {
                $key = sanitize_key($key);
                if (strpos($key, 'pa_') !== 0) {
                    $key = 'pa_' . $key;
                }
                if (taxonomy_exists($key) && !in_array($key, $needed, true)) {
                    $needed[] = $key;
                }
                if (count($needed) >= $max) {
                    break;
                }
            }
        }

        foreach ($needed as $taxonomy) {
            $taxonomy = sanitize_key($taxonomy);
            if (!$taxonomy || strpos($taxonomy, 'pa_') !== 0 || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $label = wc_attribute_label($taxonomy) ?: $taxonomy;
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => 500,
            ));
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            foreach ($terms as $term_item) {
                $this->add_attr_term($data, $taxonomy, $label, $term_item->slug, $term_item->name, 'taxonomy', $taxonomy, (int) $term_item->term_id);
            }
        }

        uasort($data, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return strnatcasecmp($a['label'], $b['label']);
            }
            return $a['priority'] <=> $b['priority'];
        });
        return $data;
    }

    /**
     * Возвращает товары для текущей категории-ветки.
     *
     * В родительских категориях WooCommerce иногда не подтягивает товары из глубоко вложенных
     * категорий через include_children, особенно после импорта/пересборки иерархии. Поэтому здесь
     * мы явно собираем все дочерние term_id и ищем товары по всей ветке. Это нужно, чтобы фильтры
     * на родительской категории строились по товарам из подкатегорий, а не показывали
     * «Фильтры не найдены».
     */
    public function get_category_product_ids($term_id = 0) {
        $term_id = absint($term_id);
        $cache_key = 'anep_wct_ids_branch_v2_' . $term_id . '_' . $this->cache_version();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return array_map('absint', $cached);
        }

        $args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ($term_id) {
            $branch_terms = $this->get_product_cat_branch_ids($term_id);
            $args['tax_query'] = array(
                array(
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $branch_terms ? $branch_terms : array($term_id),
                    'include_children' => false,
                    'operator'         => 'IN',
                ),
            );
        }

        $q = new WP_Query($args);
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $q->posts))));

        // Подстраховка для импортированных каталогов: если общий запрос по ветке пустой,
        // пробуем отдельно пройтись по дочерним категориям. Это медленнее, поэтому используется
        // только как fallback и кешируется.
        if (!$ids && $term_id) {
            $ids = $this->get_category_product_ids_from_children_fallback($term_id);
        }

        set_transient($cache_key, $ids, 6 * HOUR_IN_SECONDS);
        return $ids;
    }

    private function get_product_cat_branch_ids($term_id) {
        $term_id = absint($term_id);
        if (!$term_id) {
            return array();
        }
        $ids = array($term_id);
        $children = get_term_children($term_id, 'product_cat');
        if (!is_wp_error($children) && !empty($children)) {
            foreach ($children as $child_id) {
                $child_id = absint($child_id);
                if ($child_id) {
                    $ids[] = $child_id;
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private function get_category_product_ids_from_children_fallback($term_id) {
        $ids = array();
        $branch_terms = $this->get_product_cat_branch_ids($term_id);
        foreach ($branch_terms as $branch_term_id) {
            if (!$branch_term_id || $branch_term_id === absint($term_id)) {
                continue;
            }
            $q = new WP_Query(array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'fields'                 => 'ids',
                'posts_per_page'         => -1,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query'              => array(
                    array(
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => array($branch_term_id),
                        'include_children' => false,
                    ),
                ),
            ));
            if (!empty($q->posts)) {
                $ids = array_merge($ids, (array) $q->posts);
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    public function get_category_attributes($term_id = 0) {
        $term_id = absint($term_id);
        $cache_key = 'anep_wct_attrs_v10_branch_' . $term_id . '_' . $this->cache_version();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $product_ids = $this->get_category_product_ids($term_id);
        if (!$product_ids) {
            set_transient($cache_key, array(), 6 * HOUR_IN_SECONDS);
            return array();
        }

        if ($this->is_large_category_fast_mode($term_id)) {
            $large_data = $this->get_large_category_attribute_data($term_id);
            if ($large_data) {
                set_transient($cache_key, $large_data, 6 * HOUR_IN_SECONDS);
                return $large_data;
            }
            // Ни одного pa_-атрибута не нашлось (каталог держит характеристики как локальные
            // атрибуты товара, не WooCommerce-таксономии). Раньше это приводило к полному обходу
            // wc_get_product() по всем товарам и вариациям всей ветки без ограничения — именно
            // так родительская категория с большим деревом подкатегорий валила PHP по памяти.
            // В "быстром" режиме ограничиваем разведку локальных атрибутов первыми N товарами.
            $product_ids = array_slice($product_ids, 0, $this->large_category_threshold());
        }

        $data = array();

        // 1) Глобальные атрибуты WooCommerce: pa_*. Это самый правильный вариант для быстрых фильтров.
        $attrs = wc_get_attribute_taxonomies();
        if ($attrs) {
            foreach ($attrs as $attr) {
                $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }

                $terms = wp_get_object_terms($product_ids, $taxonomy, array(
                    'fields'  => 'all',
                    'orderby' => 'name',
                    'order'   => 'ASC',
                ));

                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    if (!$term || is_wp_error($term)) {
                        continue;
                    }
                    $this->add_attr_term($data, $taxonomy, wc_attribute_label($taxonomy), $term->slug, $term->name, 'taxonomy', $taxonomy, (int) $term->term_id);
                }
            }
        }

        // 2) Подстраховка: сканируем атрибуты прямо из карточек товара.
        // Это помогает, если импорт создал локальные атрибуты или термины есть в карточке, но не попали в фильтр WooCommerce.
        foreach ($product_ids as $scan_index => $product_id) {
            $this->release_bulk_scan_memory($scan_index);
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $attributes = $product->get_attributes();
            foreach ($attributes as $attr_key => $attribute) {
                if (!is_a($attribute, 'WC_Product_Attribute')) {
                    continue;
                }
                $source_name = $attribute->get_name();
                if (!$source_name) {
                    continue;
                }

                if ($attribute->is_taxonomy()) {
                    $taxonomy = $source_name;
                    if (!taxonomy_exists($taxonomy)) {
                        continue;
                    }
                    $terms = wc_get_product_terms($product_id, $taxonomy, array('fields' => 'all'));
                    if (is_wp_error($terms) || empty($terms)) {
                        continue;
                    }
                    foreach ($terms as $term) {
                        $this->add_attr_term($data, $taxonomy, wc_attribute_label($taxonomy), $term->slug, $term->name, 'taxonomy', $taxonomy, (int) $term->term_id);
                    }
                } else {
                    $label = $source_name;
                    $key = 'attr_' . sanitize_title($source_name);
                    if (!$key || $key === 'attr_') {
                        continue;
                    }
                    $values = $this->attribute_options_to_values($attribute->get_options());
                    foreach ($values as $value) {
                        $slug = sanitize_title($value);
                        if ($slug === '') {
                            continue;
                        }
                        $this->add_attr_term($data, $key, $label, $slug, $value, 'custom', $source_name, 0);
                    }
                }
            }

            // 3) Для вариативных товаров добавляем значения, которые хранятся только в вариациях.
            // Без этого фильтр может показывать/искать не все реальные комбинации каталога.
            if ($product->is_type('variable')) {
                foreach ((array) $product->get_children() as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation || !$variation->is_type('variation')) {
                        continue;
                    }
                    $variation_attrs = method_exists($variation, 'get_variation_attributes') ? $variation->get_variation_attributes() : array();
                    if (!$variation_attrs) {
                        $variation_attrs = $variation->get_attributes();
                    }
                    foreach ($variation_attrs as $v_key => $v_value) {
                        $clean_key = preg_replace('/^attribute_/u', '', (string) $v_key);
                        $v_value = trim(wp_strip_all_tags((string) $v_value));
                        if ($clean_key === '' || $v_value === '') {
                            continue;
                        }

                        if (taxonomy_exists($clean_key)) {
                            $label = wc_attribute_label($clean_key);
                            $term = get_term_by('slug', $v_value, $clean_key);
                            if (!$term) {
                                $term = get_term_by('name', $v_value, $clean_key);
                            }
                            if ($term && !is_wp_error($term)) {
                                $this->add_attr_term($data, $clean_key, $label, $term->slug, $term->name, 'taxonomy', $clean_key, (int) $term->term_id);
                            } else {
                                $this->add_attr_term($data, $clean_key, $label, sanitize_title($v_value), $v_value, 'taxonomy', $clean_key, 0);
                            }
                        } else {
                            $key = 'attr_' . sanitize_title($clean_key);
                            $label = $clean_key;
                            if ($key && $key !== 'attr_') {
                                $this->add_attr_term($data, $key, $label, sanitize_title($v_value), $v_value, 'custom', $clean_key, 0);
                            }
                        }
                    }
                }
            }
        }

        foreach ($data as $key => $item) {
            if (empty($item['terms'])) {
                unset($data[$key]);
                continue;
            }
            $terms = array_values($item['terms']);
            usort($terms, function ($a, $b) {
                return strnatcasecmp($a['name'], $b['name']);
            });
            $data[$key]['terms'] = $terms;
        }

        uasort($data, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return strnatcasecmp($a['label'], $b['label']);
            }
            return $a['priority'] <=> $b['priority'];
        });

        set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    private function add_attr_term(&$data, $key, $label, $slug, $name, $type, $source_name, $term_id = 0) {
        $key = sanitize_key($key);
        $name = trim(wp_strip_all_tags((string) $name));
        $slug = $this->filter_value_key($name !== '' ? $name : $slug);
        $label = trim(wp_strip_all_tags((string) $label));
        if (!$key || !$slug || $name === '') {
            return;
        }
        if (!isset($data[$key])) {
            $data[$key] = array(
                'taxonomy'    => $key,
                'label'       => $label ?: $key,
                'terms'       => array(),
                'filter_type' => $type,
                'source_name' => $source_name,
                'priority'    => $this->attribute_priority($key, $label ?: $key),
            );
        }
        if (!isset($data[$key]['terms'][$slug])) {
            $data[$key]['terms'][$slug] = array(
                'term_id' => (int) $term_id,
                'name'    => $name,
                'slug'    => $slug,
            );
        }
    }

    private function attribute_options_to_values($options) {
        $values = array();
        if (!is_array($options)) {
            $options = array($options);
        }
        foreach ($options as $option) {
            if (is_array($option)) {
                $option = implode('|', $option);
            }
            $option = wc_clean(wp_unslash((string) $option));
            if ($option === '') {
                continue;
            }
            if (function_exists('wc_get_text_attributes')) {
                $parts = wc_get_text_attributes($option);
            } else {
                $parts = array_map('trim', explode('|', $option));
            }
            foreach ($parts as $part) {
                $part = trim(wp_strip_all_tags((string) $part));
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }
        return array_values(array_unique($values));
    }

    private function filter_value_key($value) {
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '') {
            return '';
        }

        // Внутренний ключ значения фильтра. Не используем sanitize_title(), потому что
        // в WordPress он может превращать «Д-43» в «43» и «1/9» в «19».
        // Здесь сохраняем смысл марки/размера: Д-43 -> d-43, 1/9 -> 1-9, Р43/P43 -> p43.
        $key = $this->normalize_filter_compare_value($value, false);
        if ($key === '') {
            $key = $this->normalize_filter_compare_value($value, true);
        }
        return (string) $key;
    }

    private function attribute_priority($taxonomy, $label) {
        $priority_slugs = $this->get_priority_slugs();
        $base = str_replace(array('pa_', 'attr_'), '', $taxonomy);
        foreach ($priority_slugs as $i => $slug) {
            if ($slug === $taxonomy || $slug === $base) {
                return $i;
            }
        }

        $l = mb_strtolower((string) $label);
        $t = mb_strtolower((string) $taxonomy);
        if (strpos($l, 'марк') !== false || strpos($t, 'mark') !== false) return 100;
        if (strpos($l, 'гост') !== false || strpos($t, 'gost') !== false || strpos($t, 'tu') !== false) return 110;
        if (strpos($l, 'диаметр') !== false || strpos($t, 'diam') !== false) return 120;
        if (strpos($l, 'толщ') !== false || strpos($t, 'tol') !== false) return 130;
        if (strpos($l, 'размер') !== false || strpos($t, 'razmer') !== false) return 140;
        if (strpos($l, 'технолог') !== false || strpos($t, 'tehn') !== false || strpos($t, 'tekhn') !== false) return 150;
        return 1000;
    }

    private function pick_attributes_by_keys($attr_data, $keys) {
        $picked = array();
        foreach ($this->normalize_attribute_keys($keys) as $key) {
            if (isset($attr_data[$key])) {
                $picked[$key] = $attr_data[$key];
            }
        }
        return $picked;
    }

    public function select_filter_attributes($attr_data, $args = array()) {
        $explicit = !empty($args['filter_attributes']) ? $args['filter_attributes'] : $this->get_option('filter_attributes', array());
        $explicit = $this->normalize_attribute_keys($explicit);
        if ($explicit) {
            return $this->pick_attributes_by_keys($attr_data, $explicit);
        }
        $max = isset($args['max_filters']) && $args['max_filters'] ? absint($args['max_filters']) : (int) $this->get_option('max_filters', 6);
        return array_slice($attr_data, 0, $max, true);
    }

    private function system_table_columns_count($args = array()) {
        $count = 0;
        if (!empty($args['show_name_column'])) {
            $count++;
        }
        if (!empty($args['show_sku_column'])) {
            $count++;
        }
        if (!empty($args['show_price_column'])) {
            $count++;
        }
        if (!empty($args['show_actions_column']) && !empty($args['show_quantity'])) {
            $count++;
        }
        if (!empty($args['show_actions_column'])) {
            $count++;
        }
        return $count;
    }

    private function attribute_columns_limit($args = array()) {
        $max_total = isset($args['max_columns']) && $args['max_columns'] ? absint($args['max_columns']) : (int) $this->get_option('max_columns', 6);
        $max_total = max(1, min(12, $max_total));
        $system_columns = $this->system_table_columns_count($args);
        return max(0, $max_total - $system_columns);
    }

    public function select_column_attributes($attr_data, $filters, $args = array()) {
        $attr_limit = $this->attribute_columns_limit($args);
        $hide_empty = $this->hide_empty_attribute_columns_enabled($args);
        $explicit = !empty($args['column_attributes']) ? $args['column_attributes'] : $this->get_option('column_attributes', array());
        $explicit = $this->normalize_attribute_keys($explicit);

        if ($explicit) {
            $columns = $this->pick_attributes_by_keys($attr_data, $explicit);
            // Если включено скрытие пустых колонок, сначала берем все выбранные кандидаты,
            // потом после запроса товаров убираем пустые и только затем применяем общий лимит.
            if (!$hide_empty) {
                $columns = $attr_limit > 0 ? array_slice($columns, 0, $attr_limit, true) : array();
            }
        } else {
            $columns = $hide_empty ? $attr_data : ($attr_limit > 0 ? array_slice($attr_data, 0, $attr_limit, true) : array());

            foreach ($filters as $tax => $item) {
                if (!$hide_empty && $attr_limit <= 0) {
                    break;
                }
                if (isset($_GET['awt'][$tax]) && !isset($columns[$tax]) && ($hide_empty || count($columns) < $attr_limit)) {
                    $columns[$tax] = $item;
                }
            }
        }

        return $columns;
    }

    private function hide_empty_attribute_columns_enabled($args = array()) {
        if (array_key_exists('hide_empty_columns', (array) $args) && $args['hide_empty_columns'] !== null) {
            return (bool) $args['hide_empty_columns'];
        }
        return $this->get_option('hide_empty_columns', 'yes') === 'yes';
    }

    private function remove_empty_attribute_columns($columns, $query, $args = array()) {
        $columns = is_array($columns) ? $columns : array();
        $attr_limit = $this->attribute_columns_limit($args);

        if ($attr_limit <= 0) {
            return array();
        }

        if (!$this->hide_empty_attribute_columns_enabled($args)) {
            return array_slice($columns, 0, $attr_limit, true);
        }

        if (!$columns || !($query instanceof WP_Query) || empty($query->posts)) {
            return array_slice($columns, 0, $attr_limit, true);
        }

        $visible = array();
        foreach ($columns as $key => $col) {
            if ($this->attribute_column_has_data($query, $col)) {
                $visible[$key] = $col;
                if (count($visible) >= $attr_limit) {
                    break;
                }
            }
        }

        return $visible;
    }

    private function attribute_column_has_data($query, $col) {
        if (!($query instanceof WP_Query) || empty($query->posts) || !$col) {
            return false;
        }

        foreach ((array) $query->posts as $post_item) {
            $product_id = is_object($post_item) && isset($post_item->ID) ? absint($post_item->ID) : absint($post_item);
            if (!$product_id) {
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $values = $this->get_product_attribute_values($product, $col);
            foreach ((array) $values as $value) {
                $value = trim(wp_strip_all_tags((string) $value));
                if ($value !== '' && $value !== '—' && $value !== '-') {
                    return true;
                }
            }
        }

        return false;
    }

    public function get_selected_filters($filters) {
        $selected = array();
        $raw = isset($_GET['awt']) && is_array($_GET['awt']) ? wp_unslash($_GET['awt']) : array();
        foreach ($filters as $taxonomy => $item) {
            if (empty($raw[$taxonomy])) {
                continue;
            }
            $value = $this->filter_value_key(sanitize_text_field($raw[$taxonomy]));
            if ($value !== '') {
                $selected[$taxonomy] = $value;
            }
        }
        return $selected;
    }

    public function product_query($term, $selected, $filters, $args = array()) {
        $tax_query = array('relation' => 'AND');
        $term_id = ($term && !is_wp_error($term)) ? absint($term->term_id) : 0;
        $sort = $this->get_current_sort($filters, $args);

        if ($term_id) {
            $branch_terms = $this->get_product_cat_branch_ids($term_id);
            $tax_query[] = array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $branch_terms ? $branch_terms : array($term_id),
                'include_children' => false,
                'operator'         => 'IN',
            );
        }

        $paged = max(1, absint(get_query_var('paged')), absint($_GET['product-page'] ?? 0));
        $per_page = isset($args['per_page']) && $args['per_page'] ? absint($args['per_page']) : (int) $this->get_option('per_page', 50);
        $per_page = max(5, min(300, $per_page));

        $query_args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $paged,
            'orderby'                => 'title',
            'order'                  => $sort['order'],
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        );

        $selected = is_array($selected) ? array_filter($selected, 'strlen') : array();
        $manual_ids = false;

        // Важно: часть импортированных товаров хранит атрибуты только в вариациях.
        // Обычный tax_query по post_type=product тогда не находит родительский товар, хотя строка
        // с Д-43/Р43/ГОСТ реально есть в таблице. Поэтому при выбранных фильтрах используем быстрый
        // индекс комбинаций "товар/вариация => атрибуты". Он кешируется и не смешивает значения
        // из разных вариаций между собой.
        $tax_selected = $this->split_selected_filters_by_query_type($selected, $filters);
        if (!empty($selected) || $sort['type'] === 'attribute') {
            $manual_base_ids = $this->quick_query_product_ids_for_tax_context($term_id, array(), $filters);

            if (!empty($selected)) {
                $manual_base_ids = $this->fast_filter_product_ids_by_selected($term_id, $manual_base_ids, $selected, $filters);
            }
            if ($sort['type'] === 'attribute') {
                $manual_base_ids = $this->sort_product_ids_by_attribute($manual_base_ids, $sort['item'], $sort['order']);
            }
            if (!$manual_base_ids) {
                $manual_base_ids = array(0);
            }
            $query_args['post__in'] = array_map('absint', $manual_base_ids);
        }

        if (count($tax_query) > 1) {
            $query_args['tax_query'] = $tax_query;
        }

        if ($sort['type'] === 'attribute') {
            $query_args['orderby'] = 'post__in';
            $query_args['order'] = 'ASC';
        } elseif ($sort['key'] === 'price') {
            $query_args['orderby'] = 'meta_value_num';
            $query_args['meta_key'] = '_price';
            $query_args['order'] = $sort['order'];
        } elseif ($sort['key'] === 'sku') {
            $query_args['orderby'] = 'meta_value';
            $query_args['meta_key'] = '_sku';
            $query_args['order'] = $sort['order'];
        } else {
            $query_args['orderby'] = 'title';
            $query_args['order'] = $sort['order'];
        }

        return new WP_Query($query_args);
    }

    private function split_selected_filters_by_query_type($selected, $filters) {
        $out = array('tax' => array(), 'manual' => array());
        if (!$selected || !is_array($selected)) {
            return $out;
        }
        foreach ($selected as $filter_key => $selected_slug) {
            $selected_slug = sanitize_text_field((string) $selected_slug);
            if ($selected_slug === '') {
                continue;
            }
            $item = isset($filters[$filter_key]) ? $filters[$filter_key] : null;
            $taxonomy = ($item && !empty($item['source_name'])) ? (string) $item['source_name'] : '';
            if ($item && isset($item['filter_type']) && $item['filter_type'] === 'taxonomy' && $taxonomy && taxonomy_exists($taxonomy)) {
                $out['tax'][$filter_key] = $selected_slug;
            } else {
                $out['manual'][$filter_key] = $selected_slug;
            }
        }
        return $out;
    }

    private function selected_tax_query_slugs($item, $selected_slug) {
        $slugs = array();
        if (!$item || $selected_slug === '') {
            return $slugs;
        }
        $selected_slug = $this->filter_value_key($selected_slug);
        if (!empty($item['terms']) && is_array($item['terms'])) {
            foreach ($item['terms'] as $term_item) {
                if (empty($term_item['slug'])) {
                    continue;
                }
                $term_slug = $this->filter_value_key($term_item['slug']);
                if ($term_slug === $selected_slug || $this->tokens_are_close_enough($term_slug, $selected_slug)) {
                    $slugs[] = $term_slug;
                    continue;
                }
                if (!empty($term_item['name'])) {
                    $name_slug = $this->filter_value_key($term_item['name']);
                    if ($name_slug === $selected_slug || $this->tokens_are_close_enough($name_slug, $selected_slug)) {
                        $slugs[] = $term_slug;
                    }
                }
            }
        }
        if (!$slugs) {
            $slugs[] = $selected_slug;
        }
        return array_values(array_unique(array_filter($slugs, 'strlen')));
    }

    private function quick_query_product_ids_for_tax_context($term_id, $tax_selected, $filters, $exclude_filter_key = '') {
        $tax_query = array('relation' => 'AND');
        $term_id = absint($term_id);
        if ($term_id) {
            $branch_terms = $this->get_product_cat_branch_ids($term_id);
            $tax_query[] = array(
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $branch_terms ? $branch_terms : array($term_id),
                'include_children' => false,
                'operator'         => 'IN',
            );
        }

        if (is_array($tax_selected)) {
            foreach ($tax_selected as $filter_key => $selected_slug) {
                if ($exclude_filter_key !== '' && $filter_key === $exclude_filter_key) {
                    continue;
                }
                $item = isset($filters[$filter_key]) ? $filters[$filter_key] : null;
                $taxonomy = ($item && !empty($item['source_name'])) ? (string) $item['source_name'] : '';
                if (!$item || !$taxonomy || !taxonomy_exists($taxonomy)) {
                    continue;
                }
                $term_slugs = $this->selected_tax_query_slugs($item, $selected_slug);
                if (!$term_slugs) {
                    continue;
                }
                $tax_query[] = array(
                    'taxonomy'         => $taxonomy,
                    'field'            => 'slug',
                    'terms'            => $term_slugs,
                    'operator'         => 'IN',
                    'include_children' => false,
                );
            }
        }

        $query_args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        if (count($tax_query) > 1) {
            $query_args['tax_query'] = $tax_query;
        }
        $q = new WP_Query($query_args);
        return array_values(array_filter(array_map('absint', (array) $q->posts)));
    }

    private function get_current_sort($filters = array(), $args = array()) {
        $sort_key = isset($_GET['awt_sort']) ? sanitize_key(wp_unslash($_GET['awt_sort'])) : 'name';
        $order_raw = isset($_GET['awt_order']) ? strtolower(sanitize_key(wp_unslash($_GET['awt_order']))) : 'asc';
        $order = $order_raw === 'desc' ? 'DESC' : 'ASC';

        if (in_array($sort_key, array('name', 'sku', 'price'), true)) {
            return array(
                'key'   => $sort_key,
                'type'  => 'builtin',
                'order' => $order,
                'item'  => null,
            );
        }

        $available = array();
        if (is_array($filters)) {
            $available = $filters;
        }
        if (!empty($args['sort_columns']) && is_array($args['sort_columns'])) {
            $available = array_merge($available, $args['sort_columns']);
        }

        if (isset($available[$sort_key])) {
            return array(
                'key'   => $sort_key,
                'type'  => 'attribute',
                'order' => $order,
                'item'  => $available[$sort_key],
            );
        }

        return array(
            'key'   => 'name',
            'type'  => 'builtin',
            'order' => 'ASC',
            'item'  => null,
        );
    }

    private function sort_product_ids_by_attribute($ids, $item, $order = 'ASC') {
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        if (!$ids || !$item) {
            return $ids;
        }

        $cache = array();
        foreach ($ids as $scan_index => $product_id) {
            $this->release_bulk_scan_memory($scan_index);
            $product = wc_get_product($product_id);
            $values = $product ? $this->get_product_attribute_values($product, $item) : array();
            $cache[$product_id] = $this->sort_value_from_attribute_values($values);
        }

        usort($ids, function ($a, $b) use ($cache, $order) {
            $va = isset($cache[$a]) ? $cache[$a] : '';
            $vb = isset($cache[$b]) ? $cache[$b] : '';

            $a_empty = ($va === '');
            $b_empty = ($vb === '');
            if ($a_empty && !$b_empty) {
                return 1;
            }
            if ($b_empty && !$a_empty) {
                return -1;
            }

            $na = $this->extract_numeric_sort_value($va);
            $nb = $this->extract_numeric_sort_value($vb);
            if ($na !== null && $nb !== null) {
                $cmp = $na <=> $nb;
            } else {
                $cmp = strnatcasecmp((string) $va, (string) $vb);
            }

            return $order === 'DESC' ? -$cmp : $cmp;
        });

        return $ids;
    }

    private function sort_value_from_attribute_values($values) {
        if (!is_array($values)) {
            $values = array($values);
        }
        $values = array_values(array_filter(array_map(function ($value) {
            return trim(wp_strip_all_tags((string) $value));
        }, $values)));

        return $values ? $values[0] : '';
    }

    private function extract_numeric_sort_value($value) {
        $value = str_replace(',', '.', (string) $value);
        if (preg_match('/-?\d+(?:\.\d+)?/u', $value, $m)) {
            return (float) $m[0];
        }
        return null;
    }

    private function filter_product_ids_by_selected($ids, $selected, $filters) {
        $matched = array();
        foreach ($ids as $scan_index => $product_id) {
            $this->release_bulk_scan_memory($scan_index);
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Важно: для вариативного товара все выбранные фильтры должны совпасть
            // в одной и той же вариации, а не частями в разных вариациях.
            if ($this->product_matches_all_selected_filters($product, $selected, $filters, true)) {
                $matched[] = absint($product_id);
            }
        }
        return $matched;
    }

    private function product_matches_all_selected_filters($product, $selected, $filters, $check_children = true) {
        if (!$product || empty($selected) || !is_array($selected)) {
            return true;
        }

        // Сначала проверяем конкретные вариации: одна строка результата должна соответствовать
        // одной реальной вариации, если товар вариативный.
        if ($check_children && $product->is_type('variable')) {
            foreach ((array) $product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) {
                    continue;
                }
                if ($this->product_matches_all_selected_filters($variation, $selected, $filters, false)) {
                    return true;
                }
            }
        }

        foreach ($selected as $key => $slug) {
            $item = isset($filters[$key]) ? $filters[$key] : null;
            if (!$item) {
                continue;
            }
            if (!$this->product_matches_selected_value($product, $item, $slug, false)) {
                return false;
            }
        }
        return true;
    }

    private function product_matches_selected_value($product, $item, $selected_slug, $check_children = false) {
        if (!$product || !$item || $selected_slug === '') {
            return true;
        }

        $selected_slug = $this->filter_value_key($selected_slug);
        $selected_values = $this->selected_filter_values($item, $selected_slug);
        if (!$selected_values) {
            $selected_values = array($selected_slug);
        }

        $selected_tokens = array();
        foreach ($selected_values as $value) {
            foreach ($this->filter_compare_tokens($value) as $token) {
                $selected_tokens[$token] = true;
            }
        }

        // 1) Основной путь: читаем атрибут именно по выбранной колонке/фильтру.
        $product_values = $this->get_product_attribute_values($product, $item);
        $product_slugs = $this->get_product_attribute_value_slugs($product, $item);
        $product_values = array_merge($product_values, $product_slugs);

        if ($this->values_match_selected_tokens($product_values, $selected_tokens)) {
            return true;
        }

        // 2) Подстраховка для импорта: иногда ключ атрибута отличается от ключа фильтра
        // (например label «ГОСТ/ТУ», slug «gost-tu», meta attribute_gost_tu, локальный атрибут «Гост ТУ»).
        // Тогда ищем среди всех атрибутов товара, но сначала с проверкой похожести ключей.
        if ($this->product_deep_attribute_match($product, $item, $selected_tokens, true)) {
            return true;
        }

        // 3) Для вариаций проверяем родителя как fallback: часть характеристик может быть на родителе,
        // а часть — в variation meta.
        if ($product->is_type('variation')) {
            $parent_id = method_exists($product, 'get_parent_id') ? absint($product->get_parent_id()) : 0;
            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent && $this->product_matches_selected_value($parent, $item, $selected_slug, false)) {
                    return true;
                }
            }
        }

        // 4) Для простых/импортированных карточек последняя мягкая подстраховка: если значение явно есть
        // в названии или SKU, считаем его совпадением. Это помогает, когда характеристики видны на сайте,
        // но не были записаны как нормальные WC-атрибуты.
        $name_sku_values = array($product->get_name(), $product->get_sku());
        if ($this->values_match_selected_tokens($name_sku_values, $selected_tokens)) {
            return true;
        }

        // Проверка детей используется только там, где допустимо искать значение хоть в одной вариации.
        // Для комбинации фильтров используется product_matches_all_selected_filters(), чтобы не смешивать
        // разные вариации между собой.
        if ($check_children && $product->is_type('variable')) {
            foreach ((array) $product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if ($variation && $this->product_matches_selected_value($variation, $item, $selected_slug, false)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function values_match_selected_tokens($values, $selected_tokens) {
        if (!is_array($values)) {
            $values = array($values);
        }
        foreach ($values as $value) {
            foreach ($this->filter_compare_tokens($value) as $token) {
                if (isset($selected_tokens[$token])) {
                    return true;
                }
                foreach ($selected_tokens as $selected_token => $_) {
                    if ($this->tokens_are_close_enough($token, $selected_token)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function product_deep_attribute_match($product, $item, $selected_tokens, $prefer_key_match = true) {
        $pairs = $this->get_product_attribute_pairs_deep($product);
        if (!$pairs) {
            return false;
        }

        $matched_any_key = false;
        foreach ($pairs as $pair) {
            $key = isset($pair['key']) ? $pair['key'] : '';
            $label = isset($pair['label']) ? $pair['label'] : '';
            $values = isset($pair['values']) ? $pair['values'] : array();
            $key_matches = $this->attribute_key_matches_item($key, $label, $item);
            if ($key_matches) {
                $matched_any_key = true;
                if ($this->values_match_selected_tokens($values, $selected_tokens)) {
                    return true;
                }
            }
        }

        // Если похожий ключ вообще не нашли, делаем общий fallback по всем значениям.
        // Если похожий ключ был, но значение в нем не совпало, общий fallback не используем,
        // чтобы не получить ложное совпадение по другому столбцу.
        if (!$prefer_key_match || !$matched_any_key) {
            foreach ($pairs as $pair) {
                $values = isset($pair['values']) ? $pair['values'] : array();
                if ($this->values_match_selected_tokens($values, $selected_tokens)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_product_attribute_pairs_deep($product) {
        if (!$product) {
            return array();
        }

        $pairs = array();
        $attributes = $product->get_attributes();
        foreach ($attributes as $attr_key => $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute')) {
                continue;
            }

            $name = $attribute->get_name();
            $label = $attribute->is_taxonomy() ? wc_attribute_label($name) : $name;
            $values = array();

            if ($attribute->is_taxonomy()) {
                $taxonomy = $name;
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $terms = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'all'));
                    if (!is_wp_error($terms) && !empty($terms)) {
                        foreach ($terms as $term) {
                            $values[] = (string) $term->name;
                            $values[] = (string) $term->slug;
                        }
                    }
                }
                $values = array_merge($values, $this->attribute_options_to_values($product->get_attribute($taxonomy)));
            } else {
                $values = $this->attribute_options_to_values($attribute->get_options());
            }

            $pairs[] = array(
                'key'    => (string) $name,
                'label'  => (string) $label,
                'values' => array_values(array_unique(array_filter($values, 'strlen'))),
            );
        }

        if ($product->is_type('variation')) {
            $variation_attrs = method_exists($product, 'get_variation_attributes') ? $product->get_variation_attributes() : array();
            if (!$variation_attrs) {
                $variation_attrs = $product->get_attributes();
            }
            foreach ($variation_attrs as $key => $raw_value) {
                $clean_key = preg_replace('/^attribute_/u', '', (string) $key);
                $values = array();
                $raw_value = (string) $raw_value;
                if ($raw_value !== '') {
                    $values[] = $raw_value;
                    if (taxonomy_exists($clean_key)) {
                        $term = get_term_by('slug', $raw_value, $clean_key);
                        if (!$term) {
                            $term = get_term_by('name', $raw_value, $clean_key);
                        }
                        if ($term && !is_wp_error($term)) {
                            $values[] = (string) $term->name;
                            $values[] = (string) $term->slug;
                        }
                    }
                }
                $pairs[] = array(
                    'key'    => (string) $clean_key,
                    'label'  => taxonomy_exists($clean_key) ? wc_attribute_label($clean_key) : (string) $clean_key,
                    'values' => array_values(array_unique(array_filter($values, 'strlen'))),
                );
            }
        }

        return $pairs;
    }

    private function attribute_key_matches_item($key, $label, $item) {
        if (!$item) {
            return false;
        }
        $source_name = isset($item['source_name']) ? (string) $item['source_name'] : '';
        $taxonomy = isset($item['taxonomy']) ? (string) $item['taxonomy'] : '';
        $item_label = isset($item['label']) ? (string) $item['label'] : '';

        $needles = array($source_name, $taxonomy, $item_label);
        $haystack = array($key, $label);

        foreach ($needles as $needle) {
            foreach ($haystack as $hay) {
                if ($this->attribute_key_compare_token($needle) !== '' && $this->attribute_key_compare_token($needle) === $this->attribute_key_compare_token($hay)) {
                    return true;
                }
                $n = $this->attribute_key_compare_token($needle, true);
                $h = $this->attribute_key_compare_token($hay, true);
                if ($n !== '' && $h !== '' && ($n === $h || strpos($n, $h) !== false || strpos($h, $n) !== false)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function attribute_key_compare_token($value, $compact = false) {
        $value = (string) $value;
        $value = preg_replace('/^attribute_/u', '', $value);
        $value = preg_replace('/^(pa_|attr_)/u', '', $value);
        $value = str_replace(array('_', '/', '\\'), '-', $value);
        return $this->normalize_filter_compare_value($value, $compact);
    }

    private function selected_filter_values($item, $selected_slug) {
        $values = array($selected_slug);
        if (!empty($item['terms']) && is_array($item['terms'])) {
            foreach ($item['terms'] as $term) {
                if (empty($term['slug'])) {
                    continue;
                }
                $term_slug = $this->filter_value_key($term['slug']);
                if ($term_slug === $selected_slug || $this->tokens_are_close_enough($term_slug, $selected_slug)) {
                    $values[] = $term_slug;
                    if (!empty($term['name'])) {
                        $values[] = (string) $term['name'];
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($values, 'strlen')));
    }

    private function filter_compare_tokens($value) {
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '') {
            return array();
        }

        $variants = array();
        $variants[] = $value;
        $variants[] = rawurldecode($value);
        $variants[] = str_replace(array('/', '\\'), '-', $value);
        $variants[] = str_replace(array('/', '\\'), ' ', $value);
        $variants[] = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value);
        $variants[] = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        foreach ($this->make_filter_slug_aliases($value) as $alias) {
            $variants[] = $alias;
        }

        $tokens = array();
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant === '') {
                continue;
            }
            $tokens[] = $this->normalize_filter_compare_value($variant, false);
            $tokens[] = $this->normalize_filter_compare_value($variant, true);
            $tokens[] = $this->filter_value_key($variant);
        }

        return array_values(array_unique(array_filter($tokens, 'strlen')));
    }

    private function normalize_filter_compare_value($value, $compact = false) {
        $value = trim(wp_strip_all_tags((string) $value));
        $value = html_entity_decode($value, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $value = mb_strtolower($value, 'UTF-8');

        // Нормализуем похожие латинские/кириллические буквы: Р43 и P43,
        // С245 и C245, Х18 и X18 должны считаться одинаковыми.
        $map = array(
            // Визуально похожие кириллица/латиница: Р43 и P43, С245 и C245, Х18 и X18.
            'а' => 'a', 'в' => 'b', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'н' => 'h',
            'о' => 'o', 'р' => 'p', 'с' => 'c', 'т' => 't', 'у' => 'y', 'х' => 'x',
            // Дополнительно оставляем значимые русские буквы, чтобы Д-43 не превращалось просто в 43.
            'б' => 'b', 'г' => 'g', 'д' => 'd', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y',
            'л' => 'l', 'п' => 'p', 'ф' => 'f', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ы' => 'y', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ё' => 'e', 'і' => 'i', 'ї' => 'i', 'Ӏ' => 'i',
        );
        $value = strtr($value, $map);
        $value = str_replace(array('×', 'х'), 'x', $value);
        $value = str_replace(',', '.', $value);
        if ($compact) {
            $value = preg_replace('/[^a-z0-9\.]+/u', '', $value);
        } else {
            $value = preg_replace('/[^a-z0-9\.]+/u', '-', $value);
            $value = trim(preg_replace('/-+/u', '-', $value), '-');
        }
        return (string) $value;
    }

    private function tokens_are_close_enough($a, $b) {
        $a1 = $this->normalize_filter_compare_value($a, false);
        $b1 = $this->normalize_filter_compare_value($b, false);
        $a2 = $this->normalize_filter_compare_value($a, true);
        $b2 = $this->normalize_filter_compare_value($b, true);
        if ($a1 !== '' && $a1 === $b1) {
            return true;
        }
        if ($a2 !== '' && $a2 === $b2) {
            return true;
        }
        // Например выбран ГОСТ 2590, а в товаре указано "ГОСТ 2590-2006".
        if (strlen($a2) >= 3 && strlen($b2) >= 3 && (strpos($a2, $b2) !== false || strpos($b2, $a2) !== false)) {
            return true;
        }
        return false;
    }

    private function resolve_cart_product_for_row($product, $args = array()) {
        if (!$product || !$product->is_type('variable')) {
            return $product;
        }

        $selected = !empty($args['selected_filters']) && is_array($args['selected_filters']) ? $args['selected_filters'] : array();
        $filters = !empty($args['active_filters']) && is_array($args['active_filters']) ? $args['active_filters'] : array();
        if (!$selected || !$filters) {
            return $product;
        }

        foreach ((array) $product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation) {
                continue;
            }
            $ok = true;
            foreach ($selected as $key => $slug) {
                $item = isset($filters[$key]) ? $filters[$key] : null;
                if (!$item || !$this->product_matches_selected_value($variation, $item, $slug)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $variation;
            }
        }
        return $product;
    }

    private function get_product_attribute_value_slugs($product, $item) {
        if (!$product || !$item) {
            return array();
        }

        $slugs = array();

        if (isset($item['filter_type']) && $item['filter_type'] === 'taxonomy') {
            $taxonomy = isset($item['source_name']) ? $item['source_name'] : '';
            if ($taxonomy && taxonomy_exists($taxonomy)) {
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'all'));
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }
                        $slugs[] = (string) $term->slug;
                        $slugs[] = $this->filter_value_key($term->name);
                    }
                }
            }
        }

        foreach ($this->get_product_attribute_values($product, $item) as $value) {
            foreach ($this->make_filter_slug_aliases($value) as $alias) {
                $slugs[] = $alias;
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    private function make_filter_slug_aliases($value) {
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '') {
            return array();
        }

        $variants = array($value);
        $variants[] = str_replace(array('/', '\\'), '-', $value);
        $variants[] = str_replace(array('/', '\\'), ' ', $value);
        $variants[] = preg_replace('/\s+/u', ' ', $value);
        $variants[] = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value);

        $aliases = array();
        foreach ($variants as $variant) {
            $slug = $this->filter_value_key($variant);
            if ($slug !== '') {
                $aliases[] = $slug;
            }
        }

        return array_values(array_unique($aliases));
    }

    private function get_product_attribute_values($product, $item) {
        if (!$product || !$item) {
            return array();
        }

        $values = array();

        if ($item['filter_type'] === 'taxonomy') {
            $taxonomy = $item['source_name'];
            if ($taxonomy && taxonomy_exists($taxonomy)) {
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'all'));
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }
                        $values[] = (string) $term->name;
                        $values[] = (string) $term->slug;
                    }
                }
            }
            $value = $taxonomy ? $product->get_attribute($taxonomy) : '';
            $values = array_merge($values, $this->attribute_options_to_values($value));
        } else {
            $source_name = $item['source_name'];
            $source_slug = sanitize_title($source_name);
            $attributes = $product->get_attributes();
            foreach ($attributes as $attr_key => $attribute) {
                if (!is_a($attribute, 'WC_Product_Attribute') || $attribute->is_taxonomy()) {
                    continue;
                }
                $name = $attribute->get_name();
                if ($name === $source_name || sanitize_title($name) === $source_slug || sanitize_title($attr_key) === $source_slug) {
                    $values = array_merge($values, $this->attribute_options_to_values($attribute->get_options()));
                }
            }
        }

        // Вариации часто хранят выбранные значения в meta attribute_*, а не в списке атрибутов родителя.
        if ($product->is_type('variation')) {
            $values = array_merge($values, $this->get_variation_attribute_values($product, $item));
        }

        if ($product->is_type('variable') && !$values) {
            foreach ((array) $product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if ($variation) {
                    $values = array_merge($values, $this->get_variation_attribute_values($variation, $item));
                }
            }
        }

        $values = array_map(function ($value) {
            return trim(wp_strip_all_tags((string) $value));
        }, $values);
        return array_values(array_unique(array_filter($values, 'strlen')));
    }

    private function get_variation_attribute_values($variation, $item) {
        if (!$variation || !$variation->is_type('variation') || !$item) {
            return array();
        }
        $values = array();
        $variation_attrs = method_exists($variation, 'get_variation_attributes') ? $variation->get_variation_attributes() : array();
        if (!$variation_attrs) {
            $variation_attrs = $variation->get_attributes();
        }

        $source_tax = (isset($item['filter_type']) && $item['filter_type'] === 'taxonomy' && !empty($item['source_name'])) ? (string) $item['source_name'] : '';

        foreach ($variation_attrs as $key => $raw_value) {
            $clean_key = preg_replace('/^attribute_/u', '', (string) $key);
            $clean_label = taxonomy_exists($clean_key) ? wc_attribute_label($clean_key) : $clean_key;
            if (!$this->attribute_key_matches_item($clean_key, $clean_label, $item)) {
                continue;
            }

            $raw_value = (string) $raw_value;
            if ($raw_value === '') {
                continue;
            }
            $values[] = $raw_value;
            if ($source_tax && taxonomy_exists($source_tax)) {
                $term = get_term_by('slug', $raw_value, $source_tax);
                if (!$term) {
                    $term = get_term_by('name', $raw_value, $source_tax);
                }
                if ($term && !is_wp_error($term)) {
                    $values[] = (string) $term->name;
                    $values[] = (string) $term->slug;
                }
            }
            if (taxonomy_exists($clean_key)) {
                $term = get_term_by('slug', $raw_value, $clean_key);
                if (!$term) {
                    $term = get_term_by('name', $raw_value, $clean_key);
                }
                if ($term && !is_wp_error($term)) {
                    $values[] = (string) $term->name;
                    $values[] = (string) $term->slug;
                }
            }
        }
        return $values;
    }

    private function custom_style_string() {
        $opts = $this->get_options();
        $pairs = array(
            '--awt-red' => $opts['custom_accent_color'],
            '--awt-dark' => $opts['custom_text_color'],
            '--awt-muted' => $opts['custom_muted_color'],
            '--awt-line' => $opts['custom_line_color'],
            '--awt-bg-main' => $opts['custom_background_color'],
            '--awt-bg' => $opts['custom_filter_background'],
            '--awt-table-bg' => $opts['custom_table_background'],
            '--awt-header-bg' => $opts['custom_header_background'],
            '--awt-header-color' => $opts['custom_header_color'],
            '--awt-row-hover' => $opts['custom_row_hover_color'],
            '--awt-button-text' => $opts['custom_button_text_color'],
            '--awt-radius' => absint($opts['custom_button_radius']) . 'px',
            '--awt-cell-y' => absint($opts['custom_cell_padding_y']) . 'px',
            '--awt-cell-x' => absint($opts['custom_cell_padding_x']) . 'px',
            '--awt-btn-y' => absint($opts['custom_button_padding_y']) . 'px',
            '--awt-btn-x' => absint($opts['custom_button_padding_x']) . 'px',
            '--awt-font-size' => absint($opts['custom_font_size']) . 'px',
            '--awt-table-min-width' => absint($opts['custom_table_min_width']) . 'px',
            '--awt-qty-width' => absint($opts['custom_quantity_width']) . 'px',
        );

        $style = '';
        foreach ($pairs as $key => $value) {
            $style .= $key . ':' . esc_attr($value) . ';';
        }
        return $style;
    }

    private function custom_style_attribute() {
        $style = $this->custom_style_string();
        return $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
    }

    public function render_cart_summary() {
        $count = 0;
        if (function_exists('WC') && WC()->cart) {
            $count = absint(WC()->cart->get_cart_contents_count());
        }
        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#';
        $checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : $cart_url;
        echo '<div class="anep-wct-cart-summary" data-cart-summary>';
        echo '<span class="anep-wct-cart-count">В корзине: <strong data-cart-count="1">' . esc_html($count) . '</strong> поз.</span>';
        echo '<a class="button anep-wct-cart-link" href="' . esc_url($cart_url) . '">Открыть корзину</a>';
        echo '<a class="button alt anep-wct-checkout-link" href="' . esc_url($checkout_url) . '">Оформить заказ</a>';
        echo '</div>';
    }

    public function render_quick_chips($term, $filters, $args = array()) {
        if (!$filters) {
            return;
        }

        $chip_attr = null;
        foreach ($filters as $tax => $item) {
            $label = mb_strtolower($item['label']);
            if (strpos($label, 'диаметр') !== false || strpos($label, 'толщ') !== false || strpos($label, 'размер') !== false) {
                $chip_attr = $item;
                break;
            }
        }
        if (!$chip_attr) {
            $chip_attr = reset($filters);
        }
        if (!$chip_attr || empty($chip_attr['terms'])) {
            return;
        }

        $base_url = isset($args['base_url']) ? $args['base_url'] : $this->current_base_url($term);
        $terms = array_slice($chip_attr['terms'], 0, 7);
        echo '<div class="anep-wct-chips">';
        foreach ($terms as $term_item) {
            $url = add_query_arg(array('awt' => array($chip_attr['taxonomy'] => $term_item['slug'])), $base_url);
            echo '<a class="button anep-wct-chip" href="' . esc_url($url) . '">' . esc_html($term_item['name']) . '</a>';
        }
        echo '<a class="button anep-wct-chip anep-wct-chip-all" href="' . esc_url($base_url) . '">Смотреть все</a>';
        echo '</div>';
    }


    /**
     * Быстрый индекс фильтрации для категории.
     *
     * Старый вариант пересчитывал фильтры через wc_get_product() по каждому товару несколько раз:
     * отдельно для каждого select, отдельно для списка товаров и отдельно для вариаций. На каталогах
     * 1000+ товаров это давало долгую загрузку после выбора любого критерия.
     *
     * Новый вариант один раз строит матрицу "строка товара/вариации => значения фильтров" и кладет
     * ее в transient. После этого зависимые фильтры и выборка товаров считаются простыми массивами.
     */
    private function get_fast_filter_index($term_id, $filters) {
        $term_id = absint($term_id);
        if (!$filters || !is_array($filters)) {
            return array('rows' => array());
        }

        $signature = array();
        foreach ($filters as $key => $item) {
            $terms = array();
            if (!empty($item['terms']) && is_array($item['terms'])) {
                foreach ($item['terms'] as $term) {
                    if (!empty($term['slug'])) {
                        $terms[] = $this->filter_value_key($term['slug']);
                    }
                }
            }
            $signature[$key] = array(
                'label' => isset($item['label']) ? (string) $item['label'] : '',
                'source' => isset($item['source_name']) ? (string) $item['source_name'] : '',
                'type' => isset($item['filter_type']) ? (string) $item['filter_type'] : '',
                'terms' => $terms,
            );
        }

        $cache_key = 'anep_wct_fidx_v4_' . $term_id . '_' . md5(wp_json_encode($signature)) . '_' . $this->cache_version();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached) && isset($cached['rows'])) {
            return $cached;
        }

        $index = $this->build_fast_filter_index($term_id, $filters);
        set_transient($cache_key, $index, 6 * HOUR_IN_SECONDS);
        return $index;
    }

    private function build_fast_filter_index($term_id, $filters) {
        $product_ids = $this->get_category_product_ids($term_id);
        $rows = array();

        if (!$product_ids || !$filters) {
            return array('rows' => array());
        }

        foreach ($product_ids as $scan_index => $product_id) {
            $this->release_bulk_scan_memory($scan_index);
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            if ($product->is_type('variable')) {
                $children = (array) $product->get_children();
                if ($children) {
                    foreach ($children as $child_id) {
                        $variation = wc_get_product($child_id);
                        if (!$variation) {
                            continue;
                        }
                        $attrs = $this->fast_filter_row_attrs($variation, $filters, $product);
                        if ($attrs) {
                            $rows[] = array(
                                'product_id'   => absint($product_id),
                                'variation_id' => absint($child_id),
                                'attrs'        => $attrs,
                            );
                        }
                    }
                    continue;
                }
            }

            $attrs = $this->fast_filter_row_attrs($product, $filters, null);
            if ($attrs) {
                $rows[] = array(
                    'product_id'   => absint($product_id),
                    'variation_id' => 0,
                    'attrs'        => $attrs,
                );
            }
        }

        return array('rows' => $rows);
    }

    private function fast_filter_row_attrs($product, $filters, $parent_product = null) {
        $attrs = array();

        foreach ($filters as $key => $item) {
            $values = $this->get_product_attribute_values_direct($product, $item);

            // Для вариации: если конкретное значение не хранится в variation meta, берем значение родителя.
            // Важно: берем именно direct-значения родителя, без подмешивания всех дочерних вариаций.
            if (!$values && $parent_product) {
                $values = $this->get_product_attribute_values_direct($parent_product, $item);
            }

            // Подстраховка для кривого импорта: если значение есть в названии/артикуле, тоже учитываем,
            // но только когда нормального атрибута нет. Это не мешает точным variation-связкам.
            if (!$values) {
                $values = array($product->get_name(), $product->get_sku());
                if ($parent_product) {
                    $values[] = $parent_product->get_name();
                    $values[] = $parent_product->get_sku();
                }
            }

            $slugs = $this->term_slugs_matching_values($item, $values);
            if (!$slugs && $values) {
                $slugs = array();
                foreach ($values as $value) {
                    $slug = $this->filter_value_key($value);
                    if ($slug !== '') {
                        $slugs[] = $slug;
                    }
                }
            }
            $slugs = array_values(array_unique(array_filter(array_map(array($this, 'filter_value_key'), (array) $slugs), 'strlen')));
            if ($slugs) {
                $attrs[$key] = $slugs;
            }
        }

        return $attrs;
    }

    /**
     * То же, что get_product_attribute_values(), но без обхода всех детей вариативного товара.
     * Это нужно для корректной Excel-логики: одна вариация = одна реальная комбинация.
     */
    private function get_product_attribute_values_direct($product, $item) {
        if (!$product || !$item) {
            return array();
        }

        $values = array();

        if (isset($item['filter_type']) && $item['filter_type'] === 'taxonomy') {
            $taxonomy = isset($item['source_name']) ? (string) $item['source_name'] : '';
            if ($taxonomy && taxonomy_exists($taxonomy)) {
                $terms = wc_get_product_terms($product->get_id(), $taxonomy, array('fields' => 'all'));
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        if (!$term || is_wp_error($term)) {
                            continue;
                        }
                        $values[] = (string) $term->name;
                        $values[] = (string) $term->slug;
                    }
                }
            }
            $value = $taxonomy ? $product->get_attribute($taxonomy) : '';
            $values = array_merge($values, $this->attribute_options_to_values($value));
        } else {
            $source_name = isset($item['source_name']) ? (string) $item['source_name'] : '';
            $source_slug = sanitize_title($source_name);
            $attributes = $product->get_attributes();
            foreach ($attributes as $attr_key => $attribute) {
                if (!is_a($attribute, 'WC_Product_Attribute') || $attribute->is_taxonomy()) {
                    continue;
                }
                $name = $attribute->get_name();
                if ($name === $source_name || sanitize_title($name) === $source_slug || sanitize_title($attr_key) === $source_slug) {
                    $values = array_merge($values, $this->attribute_options_to_values($attribute->get_options()));
                }
            }
        }

        if ($product->is_type('variation')) {
            $values = array_merge($values, $this->get_variation_attribute_values($product, $item));
        }

        $values = array_map(function ($value) {
            return trim(wp_strip_all_tags((string) $value));
        }, $values);

        return array_values(array_unique(array_filter($values, 'strlen')));
    }

    private function fast_row_matches_selected($attrs, $selected, $exclude_key = '') {
        if (!$selected || !is_array($selected)) {
            return true;
        }

        foreach ($selected as $key => $slug) {
            if ($exclude_key !== '' && $key === $exclude_key) {
                continue;
            }
            $slug = $this->filter_value_key($slug);
            if ($slug === '') {
                continue;
            }

            $row_slugs = isset($attrs[$key]) ? (array) $attrs[$key] : array();
            if (!$row_slugs) {
                return false;
            }

            $matched = false;
            foreach ($row_slugs as $row_slug) {
                $row_slug = $this->filter_value_key($row_slug);
                if ($row_slug === $slug || $this->tokens_are_close_enough($row_slug, $slug)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function fast_filter_product_ids_by_selected($term_id, $ids, $selected, $filters) {
        $ids = array_values(array_filter(array_map('absint', (array) $ids)));
        if (!$selected || !is_array($selected)) {
            return $ids;
        }

        $allowed = array_fill_keys($ids, true);
        $index = $this->get_fast_filter_index($term_id, $filters);
        if (empty($index['rows']) || !is_array($index['rows'])) {
            // На случай экзотичного импорта оставляем старый медленный, но надежный путь как fallback.
            return $this->filter_product_ids_by_selected($ids, $selected, $filters);
        }

        $matched = array();
        foreach ($index['rows'] as $row) {
            $product_id = isset($row['product_id']) ? absint($row['product_id']) : 0;
            if (!$product_id || !isset($allowed[$product_id])) {
                continue;
            }
            $attrs = isset($row['attrs']) && is_array($row['attrs']) ? $row['attrs'] : array();
            if ($this->fast_row_matches_selected($attrs, $selected)) {
                $matched[$product_id] = true;
            }
        }

        return array_map('absint', array_keys($matched));
    }


    /**
     * Возвращает доступные значения каждого фильтра с учетом уже выбранных других фильтров.
     * Логика как в Excel: выбрали Марку — ГОСТ/Диаметр/Тип показывают только реальные комбинации.
     * Для текущего фильтра его собственное выбранное значение исключается из контекста, чтобы
     * выбранный select не превращался в один-единственный пункт и позволял сменить значение.
     */
    private function get_contextual_filter_terms($term, $filters, $selected) {
        if (!$filters || !is_array($filters)) {
            return array();
        }

        $term_id = ($term && !is_wp_error($term)) ? absint($term->term_id) : 0;
        $selected = is_array($selected) ? array_filter($selected, 'strlen') : array();
        $result = array();

        if (!$selected) {
            foreach ($filters as $filter_key => $item) {
                $result[$filter_key] = $this->all_filter_term_counts($item);
            }
            return $result;
        }

        // Используем тот же быстрый индекс, что и для таблицы. Это важно для вариативных товаров:
        // если значение Д-43 хранится только в variation meta, tax_query по родительскому product
        // вернет пусто, хотя товар реально есть.
        $split = $this->split_selected_filters_by_query_type($selected, $filters);
        $index = $this->get_fast_filter_index($term_id, $filters);
        if (!empty($index['rows']) && is_array($index['rows'])) {
            foreach ($filters as $filter_key => $item) {
                $counts = array();
                foreach ($index['rows'] as $row) {
                    $attrs = isset($row['attrs']) && is_array($row['attrs']) ? $row['attrs'] : array();
                    if (!$this->fast_row_matches_selected($attrs, $selected, $filter_key)) {
                        continue;
                    }
                    if (empty($attrs[$filter_key])) {
                        continue;
                    }
                    foreach ((array) $attrs[$filter_key] as $slug) {
                        $slug = $this->filter_value_key($slug);
                        if ($slug === '') {
                            continue;
                        }
                        $counts[$slug] = isset($counts[$slug]) ? ($counts[$slug] + 1) : 1;
                    }
                }

                if (!empty($selected[$filter_key])) {
                    $selected_slug = $this->filter_value_key($selected[$filter_key]);
                    if ($selected_slug && !isset($counts[$selected_slug])) {
                        $counts[$selected_slug] = 0;
                    }
                }

                $result[$filter_key] = $counts;
            }
            return $result;
        }

        // Без индекса — просто показываем все варианты, чтобы не зависать.
        foreach ($filters as $filter_key => $item) {
            $result[$filter_key] = $this->all_filter_term_counts($item);
        }
        return $result;
    }

    private function all_filter_term_counts($item) {
        $counts = array();
        if (!empty($item['terms']) && is_array($item['terms'])) {
            foreach ($item['terms'] as $term_item) {
                if (empty($term_item['slug'])) {
                    continue;
                }
                $slug = $this->filter_value_key($term_item['slug']);
                if ($slug !== '') {
                    $counts[$slug] = 1;
                }
            }
        }
        return $counts;
    }

    private function available_term_counts_for_filter($product_ids, $item, $context_selected, $filters) {
        $counts = array();
        $product_ids = array_values(array_filter(array_map('absint', (array) $product_ids)));
        if (!$product_ids || !$item || empty($item['terms'])) {
            return $counts;
        }

        foreach ($product_ids as $scan_index => $product_id) {
            $this->release_bulk_scan_memory($scan_index);
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $slugs = $this->available_term_slugs_from_product_context($product, $item, $context_selected, $filters);
            foreach (array_unique($slugs) as $slug) {
                $slug = $this->filter_value_key($slug);
                if ($slug === '') {
                    continue;
                }
                if (!isset($counts[$slug])) {
                    $counts[$slug] = 0;
                }
                $counts[$slug]++;
            }
        }

        return $counts;
    }

    private function available_term_slugs_from_product_context($product, $item, $context_selected, $filters) {
        if (!$product || !$item) {
            return array();
        }

        $context_selected = is_array($context_selected) ? array_filter($context_selected, 'strlen') : array();
        $slugs = array();

        // Для вариативного товара важно считать только реальные комбинации одной вариации.
        // Иначе фильтр может показать значение из другой вариации, которого нет в выбранной связке.
        if ($product->is_type('variable')) {
            $matched_variation = false;
            foreach ((array) $product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) {
                    continue;
                }
                if (!empty($context_selected) && !$this->product_matches_all_selected_filters($variation, $context_selected, $filters, false)) {
                    continue;
                }

                $matched_variation = true;
                $values = $this->get_product_attribute_values($variation, $item);

                // Если нужная характеристика хранится только на родителе, а не в самой вариации,
                // берем родительское значение как подстраховку.
                if (!$values) {
                    $values = $this->get_product_attribute_values($product, $item);
                }

                $slugs = array_merge($slugs, $this->term_slugs_matching_values($item, $values));
            }

            if ($matched_variation) {
                return array_values(array_unique(array_filter($slugs, 'strlen')));
            }
        }

        if (!empty($context_selected) && !$this->product_matches_all_selected_filters($product, $context_selected, $filters, true)) {
            return array();
        }

        $values = $this->get_product_attribute_values($product, $item);
        $slugs = array_merge($slugs, $this->term_slugs_matching_values($item, $values));

        return array_values(array_unique(array_filter($slugs, 'strlen')));
    }

    private function term_slugs_matching_values($item, $values) {
        if (!$item || empty($item['terms'])) {
            return array();
        }

        $values = is_array($values) ? $values : array($values);
        $values = array_values(array_filter(array_map(function ($value) {
            return trim(wp_strip_all_tags((string) $value));
        }, $values), 'strlen'));

        if (!$values) {
            return array();
        }

        $matched = array();
        foreach ($item['terms'] as $term_item) {
            if (empty($term_item['slug'])) {
                continue;
            }
            $term_slug = $this->filter_value_key($term_item['slug']);
            if ($term_slug === '') {
                continue;
            }

            $tokens = array();
            foreach ($this->selected_filter_values($item, $term_slug) as $candidate) {
                foreach ($this->filter_compare_tokens($candidate) as $token) {
                    $tokens[$token] = true;
                }
            }
            if (!empty($term_item['name'])) {
                foreach ($this->filter_compare_tokens($term_item['name']) as $token) {
                    $tokens[$token] = true;
                }
            }

            if ($tokens && $this->values_match_selected_tokens($values, $tokens)) {
                $matched[] = $term_slug;
            }
        }

        return array_values(array_unique($matched));
    }

    private function filter_term_name_by_slug($item, $slug) {
        $slug = $this->filter_value_key($slug);
        if (!$item || $slug === '' || empty($item['terms']) || !is_array($item['terms'])) {
            return '';
        }
        foreach ($item['terms'] as $term_item) {
            $term_slug = isset($term_item['slug']) ? $this->filter_value_key($term_item['slug']) : '';
            if ($term_slug === '') {
                continue;
            }
            $term_name = isset($term_item['name']) ? (string) $term_item['name'] : '';
            if ($term_slug === $slug || $this->tokens_are_close_enough($term_slug, $slug) || ($term_name !== '' && $this->tokens_are_close_enough($term_name, $slug))) {
                return $term_name !== '' ? $term_name : $term_slug;
            }
        }
        return $slug;
    }

    private function filter_option_is_available($available, $option_slug) {
        $option_slug = $this->filter_value_key($option_slug);
        if ($option_slug === '') {
            return false;
        }
        if (isset($available[$option_slug])) {
            return true;
        }
        foreach (array_keys((array) $available) as $available_slug) {
            $available_slug = $this->filter_value_key($available_slug);
            if ($available_slug === $option_slug || $this->tokens_are_close_enough($available_slug, $option_slug)) {
                return true;
            }
        }
        return false;
    }

    private function filters_url_with_selected($base_url, $selected, $remove_key = '') {
        $args = array();
        foreach ($_GET as $key => $value) {
            if ($key === 'awt' || $key === 'product-page' || $key === 'paged' || is_array($value)) {
                continue;
            }
            $args[$key] = sanitize_text_field(wp_unslash($value));
        }
        foreach ((array) $selected as $key => $value) {
            if ($remove_key !== '' && $key === $remove_key) {
                continue;
            }
            $value = sanitize_text_field((string) $value);
            if ($value === '') {
                continue;
            }
            if (!isset($args['awt']) || !is_array($args['awt'])) {
                $args['awt'] = array();
            }
            $args['awt'][$key] = $value;
        }
        return add_query_arg($args, $base_url);
    }

    private function frontend_filter_matrix($term, $filters, $selected = array()) {
        $term_id = ($term && !is_wp_error($term)) ? absint($term->term_id) : 0;
        $selected = is_array($selected) ? array_filter($selected, 'strlen') : array();
        if (!$selected && $this->is_large_category_fast_mode($term_id)) {
            return array(
                'rows' => array(),
                'lazy' => true,
            );
        }
        $index = $this->get_fast_filter_index($term_id, $filters);
        $rows = array();
        if (!empty($index['rows']) && is_array($index['rows'])) {
            foreach ($index['rows'] as $row) {
                $attrs = isset($row['attrs']) && is_array($row['attrs']) ? $row['attrs'] : array();
                $out = array();
                foreach ($filters as $key => $item) {
                    if (empty($attrs[$key])) {
                        continue;
                    }
                    $vals = array_values(array_unique(array_filter(array_map(array($this, 'filter_value_key'), (array) $attrs[$key]), 'strlen')));
                    if ($vals) {
                        $out[$key] = $vals;
                    }
                }
                if ($out) {
                    $rows[] = $out;
                }
                if (count($rows) >= 12000) {
                    break;
                }
            }
        }
        return array(
            'rows' => $rows,
        );
    }

    public function render_filters($term, $filters, $selected, $args = array()) {
        if (!$filters) {
            echo '<div class="anep-wct-note">Фильтры не найдены. У товаров должны быть заполнены атрибуты WooCommerce. Плагин теперь читает и глобальные <code>pa_*</code>, и локальные атрибуты товара.</div>';
            return;
        }

        $base_url = isset($args['base_url']) ? $args['base_url'] : $this->current_base_url($term);
        $contextual_terms = $this->get_contextual_filter_terms($term, $filters, $selected);
        $matrix = $this->frontend_filter_matrix($term, $filters, $selected);

        echo '<section class="anep-wct-filter-wrap">';
        echo '<h2 class="anep-wct-filter-title"><span class="anep-wct-filter-icon" aria-hidden="true">⌄</span> Фильтр продукции</h2>';
        echo '<form class="anep-wct-filter-form anep-wct-filter-form-custom" method="get" action="' . esc_url($base_url) . '">';

        foreach ($_GET as $key => $value) {
            if ($key === 'awt' || $key === 'product-page' || $key === 'paged' || is_array($value)) {
                continue;
            }
            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '">';
        }

        foreach ($filters as $taxonomy => $item) {
            $current = isset($selected[$taxonomy]) ? $this->filter_value_key($selected[$taxonomy]) : '';
            $current_name = $current !== '' ? $this->filter_term_name_by_slug($item, $current) : '';
            $available = isset($contextual_terms[$taxonomy]) && is_array($contextual_terms[$taxonomy]) ? $contextual_terms[$taxonomy] : array();
            $button_text = $current_name !== '' ? sprintf('%s: %s', $item['label'], $current_name) : $item['label'];

            echo '<div class="anep-wct-filter-field" data-awt-filter-field data-filter-key="' . esc_attr($taxonomy) . '" data-filter-label="' . esc_attr($item['label']) . '">';
            echo '<input type="hidden" class="anep-wct-filter-hidden" name="awt[' . esc_attr($taxonomy) . ']" value="' . esc_attr($current) . '">';
            echo '<button type="button" class="anep-wct-filter-toggle" aria-expanded="false">';
            echo '<span class="anep-wct-filter-toggle-text">' . esc_html($button_text) . '</span>';
            echo '<span class="anep-wct-filter-toggle-icon" aria-hidden="true">⌄</span>';
            echo '</button>';
            echo '<div class="anep-wct-filter-panel" hidden>';
            echo '<input type="search" class="anep-wct-filter-search" placeholder="Поиск..." autocomplete="off">';
            echo '<div class="anep-wct-filter-options">';
            echo '<button type="button" class="anep-wct-filter-option anep-wct-filter-option-clear" data-filter-value="" data-filter-name="' . esc_attr($item['label']) . '"><span class="anep-wct-filter-check"></span><span class="anep-wct-filter-option-name">Все</span></button>';

            foreach ($item['terms'] as $term_item) {
                $option_slug = $this->filter_value_key($term_item['slug']);
                $is_current = ($current !== '' && ($current === $option_slug || $this->tokens_are_close_enough($current, $option_slug)));
                $is_available = $this->filter_option_is_available($available, $option_slug);

                if (!$is_available && !$is_current) {
                    continue;
                }

                $option_classes = 'anep-wct-filter-option';
                if ($is_current) {
                    $option_classes .= ' is-selected';
                }
                if (!$is_available && $is_current) {
                    $option_classes .= ' is-forced-current';
                }

                echo '<button type="button" class="' . esc_attr($option_classes) . '" data-filter-value="' . esc_attr($term_item['slug']) . '" data-filter-name="' . esc_attr($term_item['name']) . '">';
                echo '<span class="anep-wct-filter-check" aria-hidden="true">' . ($is_current ? '✓' : '') . '</span>';
                echo '<span class="anep-wct-filter-option-name">' . esc_html($term_item['name']) . '</span>';
                echo '</button>';
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '<a class="button anep-wct-reset" href="' . esc_url($base_url) . '">Сбросить</a>';
        echo '</form>';

        echo '<div class="anep-wct-active-filters" data-awt-active-filters' . (empty($selected) ? ' hidden' : '') . '>';
        foreach ((array) $selected as $key => $slug) {
            if (empty($filters[$key])) {
                continue;
            }
            $item = $filters[$key];
            $name = $this->filter_term_name_by_slug($item, $slug);
            if ($name === '') {
                continue;
            }
            echo '<span class="anep-wct-active-filter" data-filter-chip="' . esc_attr($key) . '">';
            echo '<span class="anep-wct-active-filter-label">' . esc_html($item['label']) . ':</span> ';
            echo '<a class="anep-wct-active-filter-value" href="' . esc_url($this->filters_url_with_selected($base_url, $selected, $key)) . '" data-awt-remove-filter="' . esc_attr($key) . '"><span>' . esc_html($name) . '</span><span class="anep-wct-active-filter-x" aria-hidden="true">×</span></a>';
            echo '</span>';
        }
        echo '</div>';

        echo '<script type="application/json" class="anep-wct-filter-matrix">' . wp_json_encode($matrix) . '</script>';
        echo '</section>';
    }

    private function sortable_header_html($label, $key, $class = '', $sort = array()) {
        $key = sanitize_key($key);
        $is_active = !empty($sort['key']) && $sort['key'] === $key;
        $current_order = !empty($sort['order']) && $sort['order'] === 'DESC' ? 'desc' : 'asc';
        $next_order = ($is_active && $current_order === 'asc') ? 'desc' : 'asc';
        $icon = $is_active ? ($current_order === 'asc' ? '↑' : '↓') : '↕';
        $url = remove_query_arg(array('product-page', 'paged'));
        $url = add_query_arg(array(
            'awt_sort'  => $key,
            'awt_order' => $next_order,
        ), $url);

        $classes = trim('anep-wct-sortable ' . $class . ($is_active ? ' is-active' : ''));

        return '<th class="' . esc_attr($classes) . '"><a href="' . esc_url($url) . '" class="anep-wct-sort-link"><span>' . esc_html($label) . '</span><span class="anep-wct-sort-icon" aria-hidden="true">' . esc_html($icon) . '</span></a></th>';
    }

    public function render_products_table($query, $columns, $args = array()) {
        $show_name = !empty($args['show_name_column']);
        $show_sku = !empty($args['show_sku_column']);
        $show_price = !empty($args['show_price_column']);
        $show_actions = !empty($args['show_actions_column']);
        $show_quantity = !empty($args['show_quantity']) && $show_actions;
        $sort = $this->get_current_sort($columns, array('sort_columns' => $columns));

        echo '<div class="anep-wct-table-wrap">';
        echo '<table class="shop_table shop_table_responsive anep-wct-table">';
        echo '<thead><tr>';
        if ($show_name) {
            echo $this->sortable_header_html('Наименование', 'name', 'anep-wct-col-name', $sort);
        }
        if ($show_sku) {
            echo $this->sortable_header_html('Артикул', 'sku', 'anep-wct-col-sku', $sort);
        }
        foreach ($columns as $key => $col) {
            echo $this->sortable_header_html($col['label'], $key, '', $sort);
        }
        if ($show_price) {
            echo $this->sortable_header_html('Цена', 'price', 'anep-wct-col-price', $sort);
        }
        if ($show_quantity) {
            echo '<th class="anep-wct-qty-col">Кол-во</th>';
        }
        if ($show_actions) {
            echo '<th class="anep-wct-actions-col"></th>';
        }
        echo '</tr></thead><tbody>';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                if (!$product) {
                    continue;
                }
                echo '<tr>';
                if ($show_name) {
                    echo '<td class="anep-wct-name" data-label="Наименование" data-title="Наименование"><a href="' . esc_url(get_permalink($product->get_id())) . '">' . esc_html($product->get_name()) . '</a></td>';
                }
                if ($show_sku) {
                    $sku = $product->get_sku();
                    echo '<td class="anep-wct-sku" data-label="Артикул" data-title="Артикул">' . esc_html($sku !== '' ? $sku : '—') . '</td>';
                }
                foreach ($columns as $key => $col) {
                    echo '<td data-label="' . esc_attr($col['label']) . '" data-title="' . esc_attr($col['label']) . '">' . wp_kses_post($this->get_product_attribute_value($product, $col)) . '</td>';
                }
                if ($show_price) {
                    $price_html = $product->get_price_html();
                    echo '<td class="anep-wct-price" data-label="Цена" data-title="Цена">' . ($price_html ? wp_kses_post($price_html) : '—') . '</td>';
                }
                if ($show_quantity) {
                    echo '<td class="anep-wct-qty" data-label="Кол-во" data-title="Кол-во"><input type="number" class="anep-wct-qty-input qty" value="1" min="1" step="1" inputmode="numeric" aria-label="Количество"></td>';
                }
                if ($show_actions) {
                    $cart_product = $this->resolve_cart_product_for_row($product, $args);
                    echo '<td class="anep-wct-actions" data-label="Действие" data-title="Действие">';
                    echo $this->price_button($product);
                    echo $this->buy_button($cart_product, $product);
                    echo '</td>';
                }
                echo '</tr>';
            }
        } else {
            $colspan = count($columns) + ($show_name ? 1 : 0) + ($show_sku ? 1 : 0) + ($show_price ? 1 : 0) + ($show_quantity ? 1 : 0) + ($show_actions ? 1 : 0);
            $colspan = max(1, $colspan);
            echo '<tr><td colspan="' . esc_attr($colspan) . '" class="anep-wct-empty" data-label="">По выбранным фильтрам товары не найдены.</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function get_product_attribute_value($product, $item) {
        $values = $this->get_product_attribute_values($product, $item);
        if (!$values && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $values = $this->get_product_attribute_values($parent, $item);
            }
        }
        if (!$values) {
            return '—';
        }
        return esc_html(implode(', ', $values));
    }

    public function price_button($product) {
        return '<button type="button" class="button anep-wct-btn anep-wct-btn-outline anep-wct-open-modal" data-product-id="' . esc_attr($product->get_id()) . '" data-product-name="' . esc_attr($product->get_name()) . '" data-request-type="Узнать цену">Узнать цену</button>';
    }

    public function buy_button($product, $display_product = null) {
        $display_product = $display_product ?: $product;
        $mode = $this->get_option('button_mode', 'smart');

        // Кнопка «Купить» теперь всегда является нашей AJAX-кнопкой, а не ссылкой WooCommerce.
        // Это важно: многие темы открывают боковую корзину/попап, когда видят обычную add-to-cart ссылку.
        if ($mode !== 'request_only') {
            $product_id = $product ? $product->get_id() : $display_product->get_id();
            return '<button type="button" class="button alt anep-wct-btn anep-wct-btn-red anep-wct-add-cart" data-awt-no-popup="1" data-product-id="' . esc_attr($product_id) . '" data-product_id="' . esc_attr($product_id) . '" data-product-name="' . esc_attr($display_product->get_name()) . '" data-quantity="1" aria-label="Добавить в корзину">Купить</button>';
        }

        return '<button type="button" class="button alt anep-wct-btn anep-wct-btn-red anep-wct-open-modal" data-product-id="' . esc_attr($display_product->get_id()) . '" data-product-name="' . esc_attr($display_product->get_name()) . '" data-request-type="Заказать">Купить</button>';
    }

    public function render_pagination($query, $args = array()) {
        if (!$query || $query->max_num_pages <= 1) {
            return;
        }

        $current = max(1, absint(get_query_var('paged')), absint($_GET['product-page'] ?? 0));
        $base_url = isset($args['base_url']) ? $args['base_url'] : remove_query_arg(array('product-page', 'paged'));
        $add_args = array();
        if (isset($_GET['awt']) && is_array($_GET['awt'])) {
            $add_args['awt'] = wp_unslash($_GET['awt']);
        }
        if (isset($_GET['awt_sort'])) {
            $add_args['awt_sort'] = sanitize_key(wp_unslash($_GET['awt_sort']));
        }
        if (isset($_GET['awt_order'])) {
            $add_args['awt_order'] = sanitize_key(wp_unslash($_GET['awt_order']));
        }

        echo '<nav class="anep-wct-pagination">';
        echo paginate_links(array(
            'base'      => esc_url_raw(add_query_arg('product-page', '%#%', $base_url)),
            'format'    => '',
            'current'   => $current,
            'total'     => $query->max_num_pages,
            'prev_text' => '←',
            'next_text' => '→',
            'type'      => 'list',
            'add_args'  => $add_args ? $add_args : false,
        ));
        echo '</nav>';
    }

    private function current_base_url($term = null) {
        if ($term && !is_wp_error($term)) {
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                return remove_query_arg(array('awt', 'product-page', 'paged', 'awt_sort', 'awt_order'), $link);
            }
        }
        if (function_exists('is_shop') && is_shop()) {
            $shop_id = wc_get_page_id('shop');
            if ($shop_id > 0) {
                return get_permalink($shop_id);
            }
        }
        if (is_singular()) {
            return get_permalink();
        }
        return remove_query_arg(array('awt', 'product-page', 'paged', 'awt_sort', 'awt_order'));
    }

    public function render_modal() {
        $phone_required = $this->get_option('phone_required', 'yes') === 'yes' ? 'required' : '';
        ?>
        <div class="anep-wct-modal" aria-hidden="true">
            <div class="anep-wct-modal-overlay"></div>
            <div class="anep-wct-modal-box" role="dialog" aria-modal="true" aria-labelledby="anep-wct-modal-title">
                <button type="button" class="anep-wct-modal-close" aria-label="Закрыть">×</button>
                <h3 id="anep-wct-modal-title">Оставить заявку</h3>
                <p class="anep-wct-modal-product"></p>
                <form class="anep-wct-request-form">
                    <input type="hidden" name="action" value="anep_wct_request">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('anep_wct_request')); ?>">
                    <input type="hidden" name="product_id" value="">
                    <input type="hidden" name="product_name" value="">
                    <input type="hidden" name="request_type" value="">
                    <label>Ваше имя<input type="text" name="client_name" placeholder="Имя"></label>
                    <label>Телефон<input type="tel" name="client_phone" placeholder="+7" <?php echo esc_attr($phone_required); ?>></label>
                    <label>Комментарий<textarea name="client_comment" rows="3" placeholder="Количество, город, вопрос"></textarea></label>
                    <button type="submit" class="button alt anep-wct-btn anep-wct-btn-red anep-wct-submit">Отправить</button>
                    <div class="anep-wct-form-result" aria-live="polite"></div>
                </form>
            </div>
        </div>
        <?php
    }

    public function ajax_request() {
        check_ajax_referer('anep_wct_request', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $product_name = sanitize_text_field(wp_unslash($_POST['product_name'] ?? ''));
        $request_type = sanitize_text_field(wp_unslash($_POST['request_type'] ?? 'Заявка'));
        $client_name = sanitize_text_field(wp_unslash($_POST['client_name'] ?? ''));
        $client_phone = sanitize_text_field(wp_unslash($_POST['client_phone'] ?? ''));
        $client_comment = sanitize_textarea_field(wp_unslash($_POST['client_comment'] ?? ''));

        if ($this->get_option('phone_required', 'yes') === 'yes' && !$client_phone) {
            wp_send_json_error(array('message' => 'Укажите телефон.'));
        }

        if ($product_id && !$product_name) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product_name = $product->get_name();
            }
        }

        $to = sanitize_email($this->get_option('request_email', get_option('admin_email')));
        if (!$to) {
            $to = get_option('admin_email');
        }

        $subject = sprintf('[%s] %s: %s', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES), $request_type, $product_name ?: 'товар');
        $body = "Новая заявка из таблицы товаров\n\n";
        $body .= "Тип: {$request_type}\n";
        $body .= "Товар: {$product_name}\n";
        if ($product_id) {
            $body .= "Ссылка: " . get_permalink($product_id) . "\n";
        }
        $body .= "Имя: {$client_name}\n";
        $body .= "Телефон: {$client_phone}\n";
        $body .= "Комментарий: {$client_comment}\n";
        $body .= "Страница: " . esc_url_raw(wp_get_referer()) . "\n";

        $sent = wp_mail($to, $subject, $body);
        if (!$sent) {
            wp_send_json_error(array('message' => 'Не удалось отправить заявку. Проверьте почту сайта / SMTP.'));
        }

        wp_send_json_success(array('message' => 'Заявка отправлена. Мы свяжемся с вами.'));
    }

    public function force_purchasable_for_no_price($purchasable, $product) {
        if ($purchasable) {
            return true;
        }

        if ($this->get_option('allow_cart_no_price', 'yes') !== 'yes') {
            return $purchasable;
        }

        if (!is_a($product, 'WC_Product')) {
            return $purchasable;
        }

        if ($this->should_force_cart_product($product)) {
            return true;
        }

        if (!$product->is_type('simple') && !$product->is_type('variation')) {
            return $purchasable;
        }

        $status = method_exists($product, 'get_status') ? $product->get_status() : '';
        if ($status && !in_array($status, array('publish', 'private'), true)) {
            return $purchasable;
        }

        if ($product->is_type('variation')) {
            $parent_id = method_exists($product, 'get_parent_id') ? absint($product->get_parent_id()) : 0;
            if ($parent_id) {
                $parent_status = get_post_status($parent_id);
                if ($parent_status && !in_array($parent_status, array('publish', 'private'), true)) {
                    return $purchasable;
                }
            }
        }

        $price = method_exists($product, 'get_price') ? $product->get_price() : null;
        if ($price === '' || $price === null) {
            return true;
        }

        return $purchasable;
    }

    public function set_zero_price_for_table_cart_items($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !is_a($cart, 'WC_Cart')) {
            return;
        }
        if ($this->get_option('allow_cart_no_price', 'yes') !== 'yes') {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['data']) || !is_a($cart_item['data'], 'WC_Product')) {
                continue;
            }
            $product = $cart_item['data'];
            $price = $product->get_price();
            if (!empty($cart_item['anep_wct_added']) && ($price === '' || $price === null)) {
                $product->set_price(0);
            }
        }
    }

    private function should_force_cart_product($product) {
        if ($this->get_option('allow_cart_no_price', 'yes') !== 'yes') {
            return false;
        }
        if (!is_a($product, 'WC_Product')) {
            return false;
        }

        $ids = array(absint($product->get_id()));
        if (method_exists($product, 'get_parent_id')) {
            $parent_id = absint($product->get_parent_id());
            if ($parent_id) {
                $ids[] = $parent_id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        foreach ($ids as $id) {
            if (in_array($id, $this->force_cart_product_ids, true)) {
                return true;
            }
        }

        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (empty($cart_item['anep_wct_added'])) {
                continue;
            }
            $cart_ids = array(
                absint($cart_item['product_id'] ?? 0),
                absint($cart_item['variation_id'] ?? 0),
                absint($cart_item['anep_wct_source_product_id'] ?? 0),
            );
            $cart_ids = array_values(array_unique(array_filter($cart_ids)));
            if (array_intersect($ids, $cart_ids)) {
                return true;
            }
        }

        return false;
    }

    public function force_in_stock_for_table_cart_items($in_stock, $product) {
        if ($this->should_force_cart_product($product)) {
            return true;
        }
        return $in_stock;
    }

    public function force_stock_status_for_table_cart_items($stock_status, $product) {
        if ($this->should_force_cart_product($product)) {
            return 'instock';
        }
        return $stock_status;
    }

    public function force_variation_active_for_table_items($active, $variation) {
        if ($this->should_force_cart_product($variation)) {
            return true;
        }
        return $active;
    }

    public function force_add_to_cart_validation_for_table_items($passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array()) {
        $ids = array(absint($product_id), absint($variation_id));
        $ids = array_values(array_unique(array_filter($ids)));
        foreach ($ids as $id) {
            if (in_array($id, $this->force_cart_product_ids, true)) {
                return true;
            }
        }
        return $passed;
    }

    private function first_variation_for_cart($product) {
        if (!$product || !$product->is_type('variable')) {
            return null;
        }
        foreach ((array) $product->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation || !$variation->is_type('variation')) {
                continue;
            }
            $status = method_exists($variation, 'get_status') ? $variation->get_status() : '';
            if ($status && !in_array($status, array('publish', 'private'), true)) {
                continue;
            }
            return $variation;
        }
        return null;
    }

    public function ajax_add_to_cart() {
        check_ajax_referer('anep_wct_request', 'nonce');

        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => 'Корзина WooCommerce недоступна.'));
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $quantity = max(1, absint($_POST['quantity'] ?? 1));
        $product = $product_id ? wc_get_product($product_id) : null;

        if (!$product) {
            wp_send_json_error(array('message' => 'Товар не найден.'));
        }

        $cart_product_id = $product_id;
        $variation_id = 0;
        $variation = array();

        if ($product->is_type('variation')) {
            $variation_id = $product_id;
            $cart_product_id = $product->get_parent_id();
            $variation = method_exists($product, 'get_variation_attributes') ? $product->get_variation_attributes() : array();
        } elseif ($product->is_type('variable')) {
            $picked_variation = $this->first_variation_for_cart($product);
            if (!$picked_variation) {
                wp_send_json_error(array('message' => 'У этого вариативного товара не найдена доступная вариация.'));
            }
            $variation_id = $picked_variation->get_id();
            $cart_product_id = $product->get_id();
            $variation = method_exists($picked_variation, 'get_variation_attributes') ? $picked_variation->get_variation_attributes() : array();
            $product = $picked_variation;
        } elseif (!$product->is_type('simple')) {
            wp_send_json_error(array('message' => 'Этот тип товара нельзя добавить в корзину из таблицы.'));
        }

        $allow_no_price = $this->get_option('allow_cart_no_price', 'yes') === 'yes';
        if (!$allow_no_price && !$product->is_in_stock()) {
            wp_send_json_error(array('message' => 'Этого товара нет в наличии.'));
        }
        if (!$product->is_purchasable() && !$allow_no_price) {
            wp_send_json_error(array('message' => 'Этот товар нельзя добавить в корзину. Проверьте цену и настройки товара.'));
        }

        $force_product_ids = array(absint($product_id), absint($cart_product_id), absint($variation_id));
        $force_product_ids = array_filter(array_unique($force_product_ids));
        $previous_force_ids = $this->force_cart_product_ids;
        $this->force_cart_product_ids = array_values(array_unique(array_merge($this->force_cart_product_ids, $force_product_ids)));
        $force_purchasable = function ($purchasable, $check_product) use ($allow_no_price, $force_product_ids) {
            if ($allow_no_price && $check_product && in_array(absint($check_product->get_id()), $force_product_ids, true)) {
                return true;
            }
            return $purchasable;
        };
        $force_stock = function ($value, $check_product) use ($allow_no_price, $force_product_ids) {
            if ($allow_no_price && $check_product && in_array(absint($check_product->get_id()), $force_product_ids, true)) {
                return true;
            }
            return $value;
        };
        $force_stock_status = function ($value, $check_product) use ($allow_no_price, $force_product_ids) {
            if ($allow_no_price && $check_product && in_array(absint($check_product->get_id()), $force_product_ids, true)) {
                return 'instock';
            }
            return $value;
        };
        add_filter('woocommerce_is_purchasable', $force_purchasable, 20, 2);
        add_filter('woocommerce_variation_is_purchasable', $force_purchasable, 20, 2);
        add_filter('woocommerce_product_is_in_stock', $force_stock, 20, 2);
        add_filter('woocommerce_product_get_stock_status', $force_stock_status, 20, 2);
        add_filter('woocommerce_product_variation_get_stock_status', $force_stock_status, 20, 2);
        $cart_item_data = array(
            'anep_wct_added' => true,
            'anep_wct_source_product_id' => absint($product_id),
        );
        $cart_item_key = WC()->cart->add_to_cart($cart_product_id, $quantity, $variation_id, $variation, $cart_item_data);
        remove_filter('woocommerce_is_purchasable', $force_purchasable, 20);
        remove_filter('woocommerce_variation_is_purchasable', $force_purchasable, 20);
        remove_filter('woocommerce_product_is_in_stock', $force_stock, 20);
        remove_filter('woocommerce_product_get_stock_status', $force_stock_status, 20);
        remove_filter('woocommerce_product_variation_get_stock_status', $force_stock_status, 20);
        $this->force_cart_product_ids = $previous_force_ids;
        if (!$cart_item_key) {
            wp_send_json_error(array('message' => 'Не удалось добавить товар в корзину.'));
        }

        $suppress_popup = isset($_POST['suppress_popup']) && $_POST['suppress_popup'] === 'yes';
        $fragments = array();
        $cart_hash = '';
        if (!$suppress_popup && function_exists('woocommerce_mini_cart')) {
            ob_start();
            woocommerce_mini_cart();
            $mini_cart = ob_get_clean();
            $fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', $fragments);
        }
        if (WC()->cart && method_exists(WC()->cart, 'get_cart_hash')) {
            $cart_hash = WC()->cart->get_cart_hash();
        }

        wp_send_json_success(array(
            'message'    => 'Товар добавлен в корзину.',
            'cart_url'   => wc_get_cart_url(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'fragments'  => $fragments,
            'cart_hash'  => $cart_hash,
        ));
    }

}

register_activation_hook(__FILE__, function () {
    update_option('anep_wct_cache_version', time(), false);
});

ANEP_Woo_Category_Table::instance();
