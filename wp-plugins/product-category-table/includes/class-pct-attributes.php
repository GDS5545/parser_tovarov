<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Поддержка локальных (не-taxonomy) атрибутов товара — то, что WooCommerce показывает на
 * вкладке «Дополнительная информация»/«Детали» карточки товара, когда атрибут добавлен на
 * сам товар и НЕ привязан к глобальной таксономии (Товары → Атрибуты). Большинство парсеров
 * заполняют характеристики именно так — это быстрее при импорте, чем заранее заводить
 * глобальные pa_* атрибуты и термины.
 *
 * Такие значения нельзя отфильтровать через tax_query/get_terms (нет таблицы связи термов),
 * поэтому при каждом сохранении товара они зеркалируются в обычный postmeta —
 * "_pct_attr_<ключ>" (по одной строке на значение, ключ = sanitize_title() от названия
 * атрибута). После этого фильтрация/сортировка/список значений работают через meta_query и
 * 'orderby' => 'meta_value' — обычный индексированный SQL, как и для всего остального в
 * этом плагине, без перебора товаров в PHP.
 *
 * Для товаров, импортированных ДО установки/обновления плагина, нужен один разовый прогон —
 * кнопка «Пересканировать все товары» в настройках (пакетами по 200 через AJAX, каждый вызов
 * — отдельный PHP-процесс с ограниченной памятью, а не один долгий цикл по всему каталогу).
 */
class PCT_Attributes {
    const META_PREFIX = '_pct_attr_';
    const REGISTRY_OPTION = 'pct_local_attributes';
    const BATCH_SIZE = 200;

    public static function init() {
        add_action('save_post_product', array(__CLASS__, 'sync_on_save'), 20, 1);
        add_action('wp_ajax_pct_reindex_batch', array(__CLASS__, 'ajax_reindex_batch'));
    }

    public static function meta_key($local_key) {
        return self::META_PREFIX . $local_key;
    }

    public static function local_registry() {
        $registry = get_option(self::REGISTRY_OPTION, array());
        return is_array($registry) ? $registry : array();
    }

    public static function sync_on_save($product_id) {
        if (wp_is_post_autosave($product_id) || wp_is_post_revision($product_id)) {
            return;
        }
        self::sync_product($product_id);
    }

    /**
     * Зеркалирует локальные (не-taxonomy) атрибуты одного товара в плоский postmeta.
     * Вызывается на каждое сохранение товара и, пакетами, из ajax_reindex_batch().
     */
    public static function sync_product($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== 'product' || !function_exists('wc_get_product')) {
            return;
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $registry = self::local_registry();
        $registry_changed = false;
        $seen_meta_keys = array();

        foreach ($product->get_attributes() as $attribute) {
            if (!is_a($attribute, 'WC_Product_Attribute') || $attribute->is_taxonomy()) {
                continue;
            }
            $name = $attribute->get_name();
            $key = $name !== '' ? sanitize_title($name) : '';
            if ($key === '') {
                continue;
            }

            $meta_key = self::meta_key($key);
            $seen_meta_keys[] = $meta_key;

            $values = array();
            foreach ((array) $attribute->get_options() as $value) {
                $value = trim(wp_strip_all_tags((string) $value));
                if ($value !== '') {
                    $values[] = $value;
                }
            }
            $values = array_values(array_unique($values));

            delete_post_meta($product_id, $meta_key);
            foreach ($values as $value) {
                add_post_meta($product_id, $meta_key, $value, false);
            }

            if (!isset($registry[$key])) {
                $registry[$key] = $name;
                $registry_changed = true;
            }
        }

        // Убираем устаревшую _pct_attr_* мету для атрибутов, которых у товара больше нет.
        foreach (array_keys(get_post_meta($product_id)) as $existing_key) {
            if (strpos($existing_key, self::META_PREFIX) === 0 && !in_array($existing_key, $seen_meta_keys, true)) {
                delete_post_meta($product_id, $existing_key);
            }
        }

        if ($registry_changed) {
            update_option(self::REGISTRY_OPTION, $registry, false);
            PCT_Plugin::instance()->bump_cache_version();
        }
    }

    /**
     * Пакетная переиндексация уже существующих товаров (для тех, что были импортированы
     * до установки/обновления плагина и ещё не проходили sync_on_save()). Каждый вызов
     * обрабатывает не больше BATCH_SIZE товаров — вызывается из JS в цикле, пока
     * не придёт done:true, поэтому память ограничена одним пакетом, а не всем каталогом.
     */
    public static function ajax_reindex_batch() {
        check_ajax_referer('pct_reindex', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Недостаточно прав.'));
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        $ids = get_posts(array(
            'post_type'              => 'product',
            'post_status'            => array('publish', 'draft', 'private'),
            'fields'                 => 'ids',
            'posts_per_page'         => self::BATCH_SIZE,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        foreach ($ids as $product_id) {
            self::sync_product($product_id);
        }

        wp_send_json_success(array(
            'processed' => count($ids),
            'next_offset' => $offset + self::BATCH_SIZE,
            'done' => count($ids) < self::BATCH_SIZE,
            'attributes_found' => count(self::local_registry()),
        ));
    }
}
