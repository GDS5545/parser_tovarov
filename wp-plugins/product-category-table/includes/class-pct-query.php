<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Все запросы к товарам категории построены на обычных WP_Query/tax_query и на прямом
 * SQL JOIN для сортировки по атрибуту. Ни одна функция здесь не вызывает wc_get_product()
 * для всей ветки категории — только ID (через 'fields' => 'ids'), что для WordPress
 * дешево даже при десятках тысяч товаров.
 */
class PCT_Query {
    const SORT_TAX_ORDERBY = 'pct_tax_name';

    public static function init() {
        add_filter('posts_clauses', array(__CLASS__, 'sort_by_taxonomy_clauses'), 10, 2);
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
     * ID товаров категории, подходящих под уже выбранные фильтры (кроме $exclude_taxonomy —
     * это нужно, чтобы список значений самого фильтра не схлопывался до одного выбранного
     * пункта). Только ID, без загрузки товаров.
     */
    public static function ids_matching_filters($term_id, $selected, $exclude_taxonomy = '') {
        $tax_query = array('relation' => 'AND');
        $term_id = absint($term_id);
        if ($term_id) {
            $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array($term_id));
        }
        foreach ((array) $selected as $taxonomy => $slug) {
            if ($taxonomy === $exclude_taxonomy || $slug === '' || $slug === null) {
                continue;
            }
            $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array($slug));
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
        if (count($tax_query) > 1) {
            $args['tax_query'] = $tax_query;
        }

        return array_values(array_unique(array_map('absint', (array) (new WP_Query($args))->posts)));
    }

    /**
     * Какие из зарегистрированных глобальных атрибутов WooCommerce (pa_*) реально
     * назначены хотя бы одному товару в данном наборе ID. Для каждой таксономии — один
     * лёгкий запрос "есть ли хоть один термин у этих object_ids" (LIMIT 1), а не перебор
     * товаров. Количество таксономий = сколько атрибутов вообще заведено в магазине
     * (обычно единицы-десятки), поэтому стоимость не зависит от размера категории.
     * Результат кешируется по категории и сбрасывается при изменении товаров/категорий
     * (см. PCT_Plugin::bump_cache_version()).
     */
    public static function detect_used_taxonomies($term_id, $product_ids) {
        $cache_key = 'pct_used_tax_' . absint($term_id) . '_' . PCT_Plugin::instance()->cache_version();
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $out = array();
        if ($product_ids) {
            $available = PCT_Plugin::instance()->get_available_attribute_taxonomies();
            foreach ($available as $taxonomy => $label) {
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
        }

        set_transient($cache_key, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Термины таксономии, реально встречающиеся среди данного набора ID товаров.
     * get_terms(object_ids=>...) — это JOIN по term_relationships, а не перебор товаров.
     */
    public static function terms_used_by_products($taxonomy, $product_ids) {
        if (!$product_ids || !taxonomy_exists($taxonomy)) {
            return array();
        }
        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'object_ids' => $product_ids,
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        return is_wp_error($terms) ? array() : $terms;
    }

    /**
     * Основной постраничный запрос товаров для таблицы. $orderby — 'title', 'price'
     * или slug таксономии атрибута (сортировка по атрибуту делается JOIN'ом в SQL,
     * без выгрузки товаров в PHP).
     */
    public static function products_query($term_id, $selected, $orderby, $order, $paged, $per_page) {
        $tax_query = array('relation' => 'AND');
        $term_id = absint($term_id);
        if ($term_id) {
            $tax_query[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array($term_id));
        }
        foreach ((array) $selected as $taxonomy => $slug) {
            if ($slug === '' || $slug === null) {
                continue;
            }
            $tax_query[] = array('taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array($slug));
        }

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

        if ($orderby === 'price') {
            $args['orderby']  = 'meta_value_num';
            $args['meta_key'] = '_price';
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
     * Значение атрибута для одной строки таблицы. Использует get_the_terms(), который
     * читает уже прогретый (update_post_term_cache=true в products_query) кеш терминов
     * текущей страницы — отдельного запроса на каждую строку не делает.
     */
    public static function row_attribute_value($post_id, $taxonomy) {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            return '';
        }
        $names = wp_list_pluck($terms, 'name');
        return implode(', ', $names);
    }
}
