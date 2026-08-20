<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Все запросы к товарам категории построены на обычных WP_Query/tax_query/meta_query и на
 * прямом SQL JOIN для сортировки по taxonomy-атрибуту. Ни одна функция здесь не вызывает
 * wc_get_product() для всей ветки категории — только ID (через 'fields' => 'ids'), что для
 * WordPress дешево даже при десятках тысяч товаров.
 *
 * Атрибут может быть:
 * - taxonomy-атрибутом WooCommerce (pa_*) — идентификатор совпадает с именем таксономии;
 * - локальным (не-taxonomy) атрибутом товара — идентификатор имеет вид "local-<ключ>" и
 *   отражает плоский postmeta-ключ "_pct_attr_<ключ>", который поддерживает в актуальном
 *   состоянии PCT_Attributes (см. class-pct-attributes.php). Локальные атрибуты фильтруются
 *   и сортируются через meta_query/orderby=meta_value — тоже SQL, не перебор товаров в PHP.
 */
class PCT_Query {
    const SORT_TAX_ORDERBY = 'pct_tax_name';

    public static function init() {
        add_filter('posts_clauses', array(__CLASS__, 'sort_by_taxonomy_clauses'), 10, 2);
    }

    public static function is_local($id) {
        return strpos((string) $id, 'local-') === 0;
    }

    public static function local_key($id) {
        return substr((string) $id, 6);
    }

    /**
     * Все ID товаров категории и её подкатегорий. WP_Query сам разворачивает дерево
     * термов (include_children включен по умолчанию), поэтому отдельный обход подкатегорий
     * не нужен. Результат — только числа, кешируется транзиентом на 6 часов.
     */
    public static function branch_product_ids($term_id) {
        $term_id = absint($term_id);
        $cache_key = 'pct_ids_' . $term_id . '_' . PCT_Plugin::instance()->cache_version();
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
            $args['tax_query'] = array(
                array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array($term_id)),
            );
        }

        $ids = array_values(array_unique(array_map('absint', (array) (new WP_Query($args))->posts)));
        set_transient($cache_key, $ids, 6 * HOUR_IN_SECONDS);
        return $ids;
    }

    /**
     * Разбирает $selected (id атрибута => значение) на tax_query- и meta_query-условия
     * и на категорию (product_cat, с учётом подкатегорий).
     */
    private static function build_selection_clauses($term_id, $selected, $exclude_id = '') {
        $tax_query = array('relation' => 'AND');
        $meta_query = array('relation' => 'AND');
        $term_id = absint($term_id);
        if ($term_id) {
            $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array($term_id));
        }

        foreach ((array) $selected as $id => $value) {
            if ((string) $id === (string) $exclude_id || $value === '' || $value === null) {
                continue;
            }
            if (self::is_local($id)) {
                $meta_query[] = array(
                    'key'     => PCT_Attributes::meta_key(self::local_key($id)),
                    'value'   => $value,
                    'compare' => '=',
                );
            } else {
                $tax_query[] = array('taxonomy' => $id, 'field' => 'slug', 'terms' => array($value));
            }
        }

        return array($tax_query, $meta_query);
    }

    /**
     * ID товаров категории, подходящих под уже выбранные фильтры (кроме $exclude_id —
     * это нужно, чтобы список значений самого фильтра не схлопывался до одного выбранного
     * пункта). Только ID, без загрузки товаров.
     */
    public static function ids_matching_filters($term_id, $selected, $exclude_id = '') {
        list($tax_query, $meta_query) = self::build_selection_clauses($term_id, $selected, $exclude_id);

        $args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }
        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        return array_values(array_unique(array_map('absint', (array) (new WP_Query($args))->posts)));
    }

    /**
     * Какие атрибуты — глобальные WooCommerce (pa_*) и локальные (см. PCT_Attributes) —
     * реально назначены хотя бы одному товару в данном наборе ID. Для каждого атрибута —
     * один лёгкий запрос "есть ли хоть одно значение у этих object_ids" (LIMIT 1), а не
     * перебор товаров. Количество проверяемых атрибутов = сколько их вообще заведено в
     * магазине (обычно единицы-десятки), поэтому стоимость не зависит от размера категории.
     * Результат кешируется по категории и сбрасывается при изменении товаров/категорий
     * (см. PCT_Plugin::bump_cache_version()).
     */
    public static function detect_used_attributes($term_id, $product_ids) {
        $cache_key = 'pct_used_attrs_v2_' . absint($term_id) . '_' . PCT_Plugin::instance()->cache_version();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $out = array();
        if ($product_ids) {
            $available_tax = PCT_Plugin::instance()->get_available_taxonomy_attributes();
            foreach ($available_tax as $taxonomy => $label) {
                $terms = get_terms(array(
                    'taxonomy'   => $taxonomy,
                    'object_ids' => $product_ids,
                    'hide_empty' => true,
                    'fields'     => 'ids',
                    'number'     => 1,
                ));
                if (!is_wp_error($terms) && !empty($terms)) {
                    $out[$taxonomy] = $label;
                }
            }

            global $wpdb;
            $ids_sql = implode(',', array_map('absint', $product_ids));
            foreach (PCT_Attributes::local_registry() as $key => $label) {
                $meta_key = PCT_Attributes::meta_key($key);
                $found = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$ids_sql}) LIMIT 1",
                    $meta_key
                ));
                if ($found) {
                    $out['local-' . $key] = $label;
                }
            }
        }

        set_transient($cache_key, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Значения атрибута, реально встречающиеся среди данного набора ID товаров — термины
     * таксономии (get_terms(object_ids=>...), JOIN по term_relationships) либо, для
     * локального атрибута, DISTINCT значения его postmeta. Ни то, ни другое не перебирает
     * товары в PHP. Возвращает объекты с ->slug/->name (для локальных slug === name —
     * значение используется как есть, без транслитерации).
     */
    public static function terms_used_by_products($id, $product_ids) {
        if (!$product_ids) {
            return array();
        }

        if (self::is_local($id)) {
            global $wpdb;
            $meta_key = PCT_Attributes::meta_key(self::local_key($id));
            $ids_sql = implode(',', array_map('absint', $product_ids));
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$ids_sql}) AND meta_value <> ''",
                $meta_key
            ));
            $terms = array();
            foreach ($values as $value) {
                $terms[] = (object) array('slug' => $value, 'name' => $value);
            }
        } else {
            if (!taxonomy_exists($id)) {
                return array();
            }
            $terms = get_terms(array(
                'taxonomy'   => $id,
                'object_ids' => $product_ids,
                'hide_empty' => true,
            ));
            $terms = is_wp_error($terms) ? array() : $terms;
        }

        // Естественная сортировка: у значений вида "2", "10", "3.5" (диаметр/толщина)
        // обычная сортировка строк даёт "10" раньше "2", что для каталога товаров неверно.
        usort($terms, function ($a, $b) {
            return strnatcasecmp($a->name, $b->name);
        });
        return $terms;
    }

    /**
     * Основной постраничный запрос товаров для таблицы. $orderby — 'title', 'price',
     * slug taxonomy-атрибута (сортировка JOIN'ом в SQL) или id локального атрибута вида
     * "local-<ключ>" (сортировка через 'orderby' => 'meta_value'). Ни один из вариантов
     * не выгружает товары в PHP для самой сортировки.
     */
    public static function products_query($term_id, $selected, $orderby, $order, $paged, $per_page) {
        list($tax_query, $meta_query) = self::build_selection_clauses($term_id, $selected);

        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => max(1, min(200, absint($per_page))),
            'paged'                  => max(1, absint($paged)),
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        );
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }
        if (count($meta_query) > 1) {
            $args['meta_query'] = $meta_query;
        }

        if ($orderby === 'price') {
            $args['orderby']  = 'meta_value_num';
            $args['meta_key'] = '_price';
            $args['order']    = $order;
        } elseif (self::is_local($orderby)) {
            $args['orderby']  = 'meta_value';
            $args['meta_key'] = PCT_Attributes::meta_key(self::local_key($orderby));
            $args['order']    = $order;
        } elseif (taxonomy_exists($orderby)) {
            $args['orderby']            = self::SORT_TAX_ORDERBY;
            $args['order']              = $order;
            $args['pct_sort_taxonomy']  = $orderby;
        } else {
            $args['orderby'] = 'title';
            $args['order']   = $order;
        }

        return new WP_Query($args);
    }

    /**
     * JOIN term_relationships/term_taxonomy/terms для нужной таксономии и сортировка
     * по имени термина — обычная SQL-сортировка, без обхода товаров в PHP.
     */
    public static function sort_by_taxonomy_clauses($clauses, $query) {
        if ($query->get('orderby') !== self::SORT_TAX_ORDERBY) {
            return $clauses;
        }
        $taxonomy = $query->get('pct_sort_taxonomy');
        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            return $clauses;
        }

        global $wpdb;
        $order = strtoupper($query->get('order')) === 'DESC' ? 'DESC' : 'ASC';

        $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS pct_tr ON ({$wpdb->posts}.ID = pct_tr.object_id)"
            . " LEFT JOIN {$wpdb->term_taxonomy} AS pct_tt ON (pct_tr.term_taxonomy_id = pct_tt.term_taxonomy_id AND pct_tt.taxonomy = '" . esc_sql($taxonomy) . "')"
            . " LEFT JOIN {$wpdb->terms} AS pct_t ON (pct_tt.term_id = pct_t.term_id)";
        $clauses['orderby'] = "pct_t.name {$order}, {$wpdb->posts}.post_title ASC";
        $clauses['groupby'] = "{$wpdb->posts}.ID";

        return $clauses;
    }

    /**
     * Значение атрибута для одной строки таблицы.
     * - taxonomy: get_the_terms() читает уже прогретый (update_post_term_cache=true в
     *   products_query) кеш терминов текущей страницы — отдельного запроса не делает.
     * - локальный: get_post_meta() читает уже прогретый (update_post_meta_cache=true)
     *   кеш postmeta текущей страницы — тоже без отдельного запроса на строку.
     */
    public static function row_attribute_value($post_id, $id) {
        if (self::is_local($id)) {
            $values = (array) get_post_meta($post_id, PCT_Attributes::meta_key(self::local_key($id)));
            $values = array_values(array_unique(array_filter(array_map('trim', $values), 'strlen')));
            return implode(', ', $values);
        }

        $terms = get_the_terms($post_id, $id);
        if (!$terms || is_wp_error($terms)) {
            return '';
        }
        return implode(', ', wp_list_pluck($terms, 'name'));
    }
}
