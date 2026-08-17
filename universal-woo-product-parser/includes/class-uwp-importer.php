<?php
/**
 * Импорт разобранных товаров в каталог WooCommerce.
 *
 * Ключевые гарантии:
 *  - товар попадает ровно в ту ветку product_cat, что соответствует
 *    хлебным крошкам источника, с созданием недостающих уровней;
 *  - повторный запуск не плодит дубли — привязка по _uwp_source_url;
 *  - импортируются только товары с домена, указанного в настройках.
 */

if (!defined('ABSPATH')) { exit; }

class UWP_Importer {

    const META_SOURCE  = '_uwp_source_url';
    const META_HASH    = '_uwp_content_hash';
    const META_QUOTE   = '_uwp_quote_product';

    /** @var array кеш дочерних категорий: parent_id => array(ключ => term_id) */
    private static $children_cache = array();

    public static function woocommerce_ready() {
        return class_exists('WooCommerce') && class_exists('WC_Product_Simple');
    }

    /**
     * Импорт одной записи очереди типа product.
     *
     * @return array array('status' => created|updated|skipped|error, 'message' => string, 'product_id' => int)
     */
    public static function import_row($row) {
        if (!self::woocommerce_ready()) {
            return array('status' => 'error', 'message' => 'WooCommerce не активен', 'product_id' => 0);
        }

        $data = json_decode((string) $row->data_json, true);
        if (!is_array($data) || empty($data['title'])) {
            UWP_DB::finish($row->id, UWP_DB::STATUS_SKIPPED, 'Нет данных товара');
            return array('status' => 'skipped', 'message' => $row->url . ' → нет данных товара', 'product_id' => 0);
        }

        // Домен из настроек — жесткое условие, а не пожелание.
        $source_url = !empty($data['url']) ? $data['url'] : $row->url;
        if (!UWP_Url::same_site($source_url, UWP_Crawler::source_url())) {
            UWP_DB::finish($row->id, UWP_DB::STATUS_SKIPPED, 'Чужой домен');
            return array('status' => 'skipped', 'message' => $source_url . ' → не с домена источника, пропущен', 'product_id' => 0);
        }

        $category_ids = self::resolve_categories(isset($data['category_path']) ? $data['category_path'] : array());

        if (!$category_ids && UWP_Settings::flag('require_category')) {
            UWP_DB::finish($row->id, UWP_DB::STATUS_SKIPPED, 'Категория не определена');
            return array(
                'status'     => 'skipped',
                'message'    => $data['title'] . ' → пропущен: категория не определена, а в настройках включен строгий режим',
                'product_id' => 0,
            );
        }

        try {
            $result = self::save_product($data, $category_ids, $source_url);
        } catch (Throwable $e) {
            UWP_DB::retry_or_fail($row, $e->getMessage());
            return array('status' => 'error', 'message' => $data['title'] . ' → ошибка импорта: ' . $e->getMessage(), 'product_id' => 0);
        }

        UWP_DB::finish($row->id, UWP_DB::STATUS_DONE, $result['status'], $result['product_id']);

        $category_note = $category_ids ? self::category_names($category_ids) : 'без категории';
        $verb = array('created' => 'создан', 'updated' => 'обновлен', 'skipped' => 'уже был');

        return array(
            'status'     => $result['status'],
            'message'    => $data['title'] . ' → ' . $verb[$result['status']] . ' (ID ' . $result['product_id'] . '), категория: ' . $category_note,
            'product_id' => $result['product_id'],
        );
    }

    /**
     * Создает или обновляет товар.
     */
    private static function save_product(array $data, array $category_ids, $source_url) {
        $existing_id = self::find_existing($source_url, isset($data['sku']) ? $data['sku'] : '');

        if ($existing_id && !UWP_Settings::flag('update_existing')) {
            // Категории все равно приводим в порядок: товар мог быть создан без них.
            if ($category_ids) {
                $product = wc_get_product($existing_id);
                if ($product) {
                    $product->set_category_ids($category_ids);
                    $product->save();
                }
            }
            return array('status' => 'skipped', 'product_id' => $existing_id);
        }

        $product = $existing_id ? wc_get_product($existing_id) : null;
        if (!$product instanceof WC_Product) {
            $product = new WC_Product_Simple();
        }

        $product->set_name(mb_substr($data['title'], 0, 200));

        if (!$existing_id) {
            $product->set_status(UWP_Settings::get('post_status'));
        }

        if (!empty($data['description'])) {
            $product->set_description($data['description']);
        }

        $short = self::build_short_description($data);
        if ($short !== '') { $product->set_short_description($short); }

        self::apply_sku($product, isset($data['sku']) ? $data['sku'] : '');
        self::apply_price($product, $data);

        $product->set_catalog_visibility('visible');
        $product->set_stock_status(!empty($data['in_stock']) ? 'instock' : 'outofstock');

        if ($category_ids) {
            $product->set_category_ids($category_ids);
        }

        if (!empty($data['attributes']) && UWP_Settings::flag('import_attributes')) {
            $product->set_attributes(self::build_attributes($data['attributes'], isset($data['brand']) ? $data['brand'] : ''));
        }

        $product->update_meta_data(self::META_SOURCE, $source_url);
        $product->update_meta_data(self::META_HASH, self::content_hash($data));

        $product_id = $product->save();
        if (!$product_id) {
            throw new Exception('WooCommerce не сохранил товар');
        }

        if (UWP_Settings::flag('import_images') && !empty($data['images'])) {
            self::attach_images($product_id, $data['images']);
        }

        return array('status' => $existing_id ? 'updated' : 'created', 'product_id' => $product_id);
    }

    private static function apply_sku($product, $sku) {
        $sku = trim((string) $sku);
        if ($sku === '') { return; }

        $sku = mb_substr($sku, 0, 100);
        $owner = wc_get_product_id_by_sku($sku);
        if ($owner && intval($owner) !== intval($product->get_id())) { return; }

        try {
            $product->set_sku($sku);
        } catch (Exception $e) {
            // Дубликат артикула не повод терять товар.
        }
    }

    private static function apply_price($product, array $data) {
        $price = UWP_Settings::flag('import_prices') ? (string) $data['price'] : '';

        if ($price !== '') {
            $product->set_regular_price($price);
            $product->set_price($price);
            $product->delete_meta_data(self::META_QUOTE);
            return;
        }

        if (UWP_Settings::flag('quote_mode')) {
            // Товар «по запросу»: цена 0, но кнопка заказа работает.
            $product->set_regular_price('0');
            $product->set_price('0');
            $product->update_meta_data(self::META_QUOTE, 'yes');
            return;
        }

        $product->set_regular_price('');
        $product->set_sale_price('');
        $product->set_price('');
        $product->delete_meta_data(self::META_QUOTE);
    }

    private static function build_short_description(array $data) {
        $parts = array();
        if (!empty($data['brand'])) { $parts[] = 'Производитель: ' . esc_html($data['brand']); }
        if (!empty($data['sku'])) { $parts[] = 'Артикул: ' . esc_html($data['sku']); }

        return $parts ? '<p>' . implode('<br>', $parts) . '</p>' : '';
    }

    /**
     * Локальные (не глобальные) атрибуты товара — не засоряют таксономии сайта.
     */
    private static function build_attributes(array $attributes, $brand = '') {
        if ($brand !== '' && !isset($attributes['Бренд']) && !isset($attributes['Производитель'])) {
            $attributes = array_merge(array('Производитель' => $brand), $attributes);
        }

        $out      = array();
        $position = 0;

        foreach ($attributes as $name => $value) {
            $name  = UWP_Dom::clean($name);
            $value = UWP_Dom::clean($value);
            if ($name === '' || $value === '') { continue; }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name(wc_clean($name));
            $attribute->set_options(array($value));
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $out[sanitize_title($name)] = $attribute;

            if ($position >= 30) { break; }
        }

        return $out;
    }

    private static function content_hash(array $data) {
        return md5(wp_json_encode(array(
            isset($data['title']) ? $data['title'] : '',
            isset($data['price']) ? $data['price'] : '',
            isset($data['description']) ? $data['description'] : '',
            isset($data['attributes']) ? $data['attributes'] : array(),
        ), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Поиск ранее импортированного товара. Прямой запрос к postmeta —
     * на порядок быстрее WP_Query с meta_query на больших каталогах.
     */
    private static function find_existing($source_url, $sku = '') {
        global $wpdb;

        $id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = 'product' AND p.post_status != 'trash'
             ORDER BY pm.post_id ASC LIMIT 1",
            self::META_SOURCE,
            $source_url
        )));
        if ($id) { return $id; }

        $sku = trim((string) $sku);
        if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $by_sku = intval(wc_get_product_id_by_sku($sku));
            // Берем по артикулу только те товары, что создал этот же парсер.
            if ($by_sku && get_post_meta($by_sku, self::META_SOURCE, true)) { return $by_sku; }
        }

        return 0;
    }

    // ---------------------------------------------------------------------
    // Категории
    // ---------------------------------------------------------------------

    /**
     * Превращает путь из хлебных крошек в идентификаторы product_cat,
     * достраивая недостающие уровни под нужным родителем.
     *
     * Возвращается только конечная категория: WordPress в архивах категорий
     * по умолчанию учитывает вложенность, поэтому товар виден и в родителях,
     * а каталог при этом не засоряется.
     *
     * @return int[]
     */
    public static function resolve_categories($path) {
        $path = array_values(array_filter(array_map(array('UWP_Dom', 'clean'), (array) $path)));

        $parent = self::root_category_id();
        $chain  = array();

        foreach ($path as $name) {
            if ($name === '' || mb_strlen($name) > 120) { continue; }

            $term_id = self::find_child_term($parent, $name);

            if (!$term_id) {
                if (!UWP_Settings::flag('create_categories')) { break; }
                $term_id = self::create_term($name, $parent);
            }
            if (!$term_id) { break; }

            $chain[] = $term_id;
            $parent  = $term_id;

            if (count($chain) >= 6) { break; }
        }

        if (!$chain) {
            $root = self::root_category_id();
            return $root ? array($root) : array();
        }

        return array(end($chain));
    }

    /**
     * Корневая категория каталога из настроек (если задана).
     */
    private static function root_category_id() {
        static $cached = null;
        if ($cached !== null) { return $cached; }

        $name = trim((string) UWP_Settings::get('root_category'));
        if ($name === '') { return $cached = 0; }

        $term_id = self::find_child_term(0, $name);
        if (!$term_id && UWP_Settings::flag('create_categories')) {
            $term_id = self::create_term($name, 0);
        }

        return $cached = intval($term_id);
    }

    /**
     * Ищет категорию с таким именем или slug среди детей заданного родителя.
     * Дети родителя кешируются — это один запрос на уровень вместо
     * выборки всех терминов сайта на каждый товар.
     */
    private static function find_child_term($parent, $name) {
        $parent = intval($parent);
        $key    = self::term_key($name);
        if ($key === '') { return 0; }

        if (!isset(self::$children_cache[$parent])) {
            self::$children_cache[$parent] = self::load_children($parent);
        }

        return isset(self::$children_cache[$parent][$key]) ? self::$children_cache[$parent][$key] : 0;
    }

    private static function load_children($parent) {
        $map   = array();
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => intval($parent),
            'hide_empty' => false,
        ));

        if (is_wp_error($terms)) { return $map; }

        foreach ($terms as $term) {
            $map[self::term_key($term->name)] = intval($term->term_id);
            $slug_key = self::term_key($term->slug);
            if ($slug_key !== '' && !isset($map[$slug_key])) { $map[$slug_key] = intval($term->term_id); }
        }

        return $map;
    }

    /**
     * Ключ сравнения имен категорий: регистр, пробелы, дефисы и
     * транслитерация slug не должны создавать дубли.
     */
    private static function term_key($name) {
        $name = mb_strtolower(UWP_Dom::clean($name));
        $name = preg_replace('~[^\p{L}\p{N}]+~u', '', $name);
        if ($name === '') { return ''; }

        // Приводим slug-вариант к тому же виду: «poroshki-metalla» и «Порошки металла»
        // остаются разными ключами, но каждый из них стабилен.
        return $name;
    }

    private static function create_term($name, $parent) {
        $name = UWP_Dom::clean($name);
        if ($name === '') { return 0; }

        $args = array('parent' => intval($parent));

        // Уникальный slug в пределах родителя, чтобы WP не ругался на дубли имен.
        $slug = sanitize_title($name);
        if ($slug !== '' && term_exists($slug, 'product_cat')) {
            $slug .= '-' . substr(md5($name . '|' . $parent), 0, 5);
        }
        if ($slug !== '') { $args['slug'] = $slug; }

        $result = wp_insert_term($name, 'product_cat', $args);

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $term_id = 0;
            if (is_array($data) && !empty($data['term_id'])) { $term_id = intval($data['term_id']); }
            elseif (is_numeric($data)) { $term_id = intval($data); }

            if (!$term_id) { return 0; }
        } else {
            $term_id = intval($result['term_id']);
            add_term_meta($term_id, '_uwp_created', '1', true);
        }

        // Обновляем кеш уровня, чтобы следующий товар нашел категорию сразу.
        if (!isset(self::$children_cache[intval($parent)])) { self::$children_cache[intval($parent)] = array(); }
        self::$children_cache[intval($parent)][self::term_key($name)] = $term_id;

        return $term_id;
    }

    public static function category_names($ids) {
        $names = array();
        foreach ((array) $ids as $id) {
            $term = get_term(intval($id), 'product_cat');
            if (!$term || is_wp_error($term)) { continue; }

            $chain = array($term->name);
            $guard = 0;
            while ($term->parent && $guard++ < 6) {
                $term = get_term(intval($term->parent), 'product_cat');
                if (!$term || is_wp_error($term)) { break; }
                array_unshift($chain, $term->name);
            }
            $names[] = implode(' / ', $chain);
        }
        return $names ? implode('; ', $names) : 'без категории';
    }

    // ---------------------------------------------------------------------
    // Изображения
    // ---------------------------------------------------------------------

    private static function attach_images($product_id, array $images) {
        $limit = UWP_Settings::int('max_images');
        if ($limit <= 0) { return; }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing_thumb = get_post_thumbnail_id($product_id);
        $gallery        = array();
        $count          = 0;

        foreach ($images as $url) {
            if ($count >= $limit) { break; }

            $known = self::attachment_by_source($url);
            if (!$known) {
                if ($existing_thumb && $count === 0) { break; } // главное фото уже стоит — не перезаливаем
                $known = self::sideload($url, $product_id);
                if (!$known) { continue; }
            }

            if ($count === 0 && !$existing_thumb) { set_post_thumbnail($product_id, $known); }
            elseif ($known != $existing_thumb) { $gallery[] = $known; }

            $count++;
        }

        if ($gallery) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_gallery_image_ids(array_slice(array_unique($gallery), 0, max(0, $limit - 1)));
                $product->save();
            }
        }
    }

    private static function attachment_by_source($url) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_uwp_image_source' AND meta_value = %s LIMIT 1",
            $url
        )));
    }

    private static function sideload($url, $product_id) {
        $id = media_sideload_image($url, $product_id, null, 'id');
        if (is_wp_error($id)) {
            UWP_DB::log('warning', 'Не удалось скачать изображение ' . $url . ': ' . $id->get_error_message());
            return 0;
        }
        update_post_meta($id, '_uwp_image_source', $url);
        return intval($id);
    }
}
