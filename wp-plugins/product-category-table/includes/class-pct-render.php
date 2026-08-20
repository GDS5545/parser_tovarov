<?php
if (!defined('ABSPATH')) {
    exit;
}

class PCT_Render {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render_table($term, $args = array()) {
        $plugin = PCT_Plugin::instance();
        $term_id = ($term && !is_wp_error($term)) ? absint($term->term_id) : 0;

        $args = wp_parse_args($args, array(
            'per_page'           => null,
            // Форма фильтров, сортировка и пагинация всегда должны вести на страницу,
            // которая СЕЙЧАС открыта, а не на "канонический" URL архива термина
            // (get_term_link()). На сайтах, где категория собрана отдельной
            // страницей/Elementor-страницей с шорткодом (а не через нативный архив
            // WooCommerce), get_term_link() ведёт на другой, часто нерабочий адрес —
            // и выбор фильтра вместо фильтрации уводил на этот чужой URL (в т.ч. на
            // главную, если тот адрес редиректился на неё).
            'base_url'           => $this->current_url_without_pct_args(),
            'filter_attributes'  => array(),
            'column_attributes'  => array(),
            'show_chips'         => true,
            'show_filters'       => true,
        ));

        // Список товаров этой категории (только ID) нужен и для чипов, и для авто-подбора
        // фильтров — считаем один раз, результат уже закеширован в PCT_Query.
        $branch_ids = PCT_Query::branch_product_ids($term_id);

        $filters = $plugin->resolve_filter_taxonomies($term_id, $branch_ids, $args['filter_attributes']);
        $columns = $plugin->resolve_column_taxonomies($args['column_attributes'], $filters);
        $sortable = $filters + $columns;

        $selected = $this->get_selected_filters($filters);
        $orderby = $this->get_current_orderby($sortable);
        $order = $this->get_current_order();
        $page = max(1, isset($_GET['pct_page']) ? absint(wp_unslash($_GET['pct_page'])) : 1);
        $per_page = $args['per_page'] ? absint($args['per_page']) : (int) $plugin->get_option('per_page', 20);

        $context = array(
            'base_url' => $args['base_url'],
            'selected' => $selected,
            'orderby'  => $orderby,
            'order'    => $order,
            'page'     => $page,
        );

        echo '<div class="pct">';

        if ($args['show_chips']) {
            $this->render_chips($branch_ids, $filters, $context);
        }

        if ($args['show_filters']) {
            $this->render_filters($term_id, $filters, $context);
        }

        $query = PCT_Query::products_query($term_id, $selected, $orderby, $order, $page, $per_page);
        $this->render_table_rows($query, $columns, $context, $plugin);
        $this->render_pagination($query, $context);
        $this->render_request_modal($plugin);

        echo '</div>';

        wp_reset_postdata();
    }

    /* ---------------------------------------------------------------------
     * Состояние из URL
     * ------------------------------------------------------------------ */

    private function get_selected_filters($filters) {
        $out = array();
        $raw = (isset($_GET['pct']) && is_array($_GET['pct'])) ? wp_unslash($_GET['pct']) : array();
        foreach ($filters as $id => $label) {
            if (!isset($raw[$id]) || $raw[$id] === '') {
                $out[$id] = '';
                continue;
            }
            // У taxonomy-атрибутов значение — это slug термина. У локальных атрибутов
            // (id вида "local-...") своих slug'ов нет — фильтр matches по точному значению
            // из postmeta, поэтому оно только очищается, а не приводится к slug-формату.
            $out[$id] = PCT_Query::is_local($id)
                ? sanitize_text_field((string) $raw[$id])
                : sanitize_title((string) $raw[$id]);
        }
        return $out;
    }

    private function get_current_orderby($sortable) {
        $raw = isset($_GET['pct_orderby']) ? sanitize_key(wp_unslash($_GET['pct_orderby'])) : 'title';
        if ($raw === 'title' || $raw === 'price') {
            return $raw;
        }
        return isset($sortable[$raw]) ? $raw : 'title';
    }

    private function get_current_order() {
        $raw = isset($_GET['pct_order']) ? strtolower(sanitize_key(wp_unslash($_GET['pct_order']))) : 'asc';
        return $raw === 'desc' ? 'desc' : 'asc';
    }

    private function current_url_without_pct_args() {
        $url = home_url(add_query_arg(array()));
        return remove_query_arg(array('pct', 'pct_orderby', 'pct_order', 'pct_page'), $url);
    }

    /**
     * Строит ссылку на то же состояние таблицы, отражая только известные параметры
     * (фильтры/сортировку/страницу), а не произвольные значения из текущего $_GET —
     * так в ссылках не расползаются посторонние query-параметры.
     */
    private function build_url($base_url, $selected, $orderby, $order, $page) {
        $query = array();
        foreach ((array) $selected as $taxonomy => $slug) {
            if ($slug !== '' && $slug !== null) {
                $query['pct'][$taxonomy] = $slug;
            }
        }
        if ($orderby && $orderby !== 'title') {
            $query['pct_orderby'] = $orderby;
        }
        if ($order && $order !== 'asc') {
            $query['pct_order'] = $order;
        }
        if ($page && (int) $page > 1) {
            $query['pct_page'] = (int) $page;
        }

        if (!$query) {
            return remove_query_arg(array('pct', 'pct_orderby', 'pct_order', 'pct_page'), $base_url);
        }
        return add_query_arg($query, $base_url);
    }

    /* ---------------------------------------------------------------------
     * Быстрые кнопки (chips)
     * ------------------------------------------------------------------ */

    private function render_chips($branch_ids, $filters, $context) {
        $plugin = PCT_Plugin::instance();
        $chip_tax = sanitize_key((string) $plugin->get_option('chips_attribute', ''));
        if (!$chip_tax || !isset($filters[$chip_tax])) {
            $chip_tax = $filters ? array_key_first($filters) : '';
        }
        if (!$chip_tax || !$branch_ids) {
            return;
        }

        $terms = PCT_Query::terms_used_by_products($chip_tax, $branch_ids);
        if (!$terms) {
            return;
        }

        $limit = (int) $plugin->get_option('chips_limit', 8);
        echo '<div class="pct-chips" data-pct-chips>';
        foreach ($terms as $i => $term_obj) {
            $is_selected = isset($context['selected'][$chip_tax]) && $context['selected'][$chip_tax] === $term_obj->slug;
            $next_selected = $context['selected'];
            $next_selected[$chip_tax] = $is_selected ? '' : $term_obj->slug;
            $url = $this->build_url($context['base_url'], $next_selected, $context['orderby'], $context['order'], 1);

            $classes = array('pct-chip');
            if ($is_selected) {
                $classes[] = 'is-active';
            }
            if ($limit > 0 && $i >= $limit) {
                $classes[] = 'pct-chip-hidden';
            }
            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">' . esc_html($term_obj->name) . '</a>';
        }
        if ($limit > 0 && count($terms) > $limit) {
            echo '<button type="button" class="pct-chip pct-chip-more" data-pct-chips-toggle>Смотреть все</button>';
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------------
     * Фильтры
     * ------------------------------------------------------------------ */

    private function render_filters($term_id, $filters, $context) {
        if (!$filters) {
            echo '<div class="pct-note">Фильтры не найдены. Проверьте, что у товаров назначены атрибуты WooCommerce (Марка, ГОСТ/ТУ и т.д.) как таксономии, а не локальные значения.</div>';
            return;
        }

        echo '<div class="pct-filter-wrap">';
        echo '<div class="pct-filter-title">' . $this->funnel_icon() . '<span>Фильтр продукции</span></div>';
        echo '<form class="pct-filter-form" method="get" action="' . esc_url($context['base_url']) . '">';

        if ($context['orderby'] !== 'title') {
            echo '<input type="hidden" name="pct_orderby" value="' . esc_attr($context['orderby']) . '">';
        }
        if ($context['order'] !== 'asc') {
            echo '<input type="hidden" name="pct_order" value="' . esc_attr($context['order']) . '">';
        }

        foreach ($filters as $taxonomy => $label) {
            $option_ids = PCT_Query::ids_matching_filters($term_id, $context['selected'], $taxonomy);
            $terms = PCT_Query::terms_used_by_products($taxonomy, $option_ids);

            echo '<select class="pct-filter-select" name="pct[' . esc_attr($taxonomy) . ']" data-pct-autosubmit>';
            echo '<option value="">' . esc_html($label) . '</option>';
            foreach ($terms as $term_obj) {
                $is_selected = isset($context['selected'][$taxonomy]) && $context['selected'][$taxonomy] === $term_obj->slug;
                echo '<option value="' . esc_attr($term_obj->slug) . '" ' . selected($is_selected, true, false) . '>' . esc_html($term_obj->name) . '</option>';
            }
            echo '</select>';
        }

        echo '<button type="submit" class="pct-filter-submit">Показать</button>';
        if (array_filter($context['selected'])) {
            echo '<a class="pct-filter-reset" href="' . esc_url($context['base_url']) . '">Сбросить</a>';
        }
        echo '</form>';
        echo '</div>';
    }

    /* ---------------------------------------------------------------------
     * Таблица
     * ------------------------------------------------------------------ */

    private function render_table_rows($query, $columns, $context, $plugin) {
        $col_count = count($columns) + 3;

        echo '<div class="pct-table-wrap"><table class="pct-table"><thead><tr>';
        echo '<th class="pct-col-name">' . $this->sort_link('title', 'Наименование', $context) . '</th>';
        foreach ($columns as $taxonomy => $label) {
            echo '<th>' . $this->sort_link($taxonomy, $label, $context) . '</th>';
        }
        echo '<th class="pct-col-price">' . $this->sort_link('price', 'Цена', $context) . '</th>';
        echo '<th class="pct-col-actions"></th>';
        echo '</tr></thead><tbody>';

        if (!$query->have_posts()) {
            echo '<tr><td colspan="' . absint($col_count) . '" class="pct-empty-row">Товары не найдены. Попробуйте изменить фильтры.</td></tr>';
        }

        // wc_get_product() здесь вызывается только для товаров текущей страницы таблицы
        // (обычно 20–50 шт.), а не для всей категории — как это делает и стандартный
        // архив WooCommerce.
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $product = wc_get_product($post_id);

            echo '<tr>';
            echo '<td class="pct-col-name"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></td>';
            foreach ($columns as $taxonomy => $label) {
                $value = PCT_Query::row_attribute_value($post_id, $taxonomy);
                echo '<td>' . ($value !== '' ? esc_html($value) : '<span class="pct-dash">—</span>') . '</td>';
            }
            echo '<td class="pct-col-price">' . $this->render_price($product, $plugin) . '</td>';
            echo '<td class="pct-col-actions">' . $this->render_action($product, $plugin) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function render_price($product, $plugin) {
        if (!$product) {
            return '';
        }
        $price = $product->get_price();
        if ($price === '' || (float) $price <= 0) {
            return '<span class="pct-price-empty">По запросу</span>';
        }
        $prefix = (string) $plugin->get_option('price_prefix', 'от ');
        $unit = (string) $plugin->get_option('price_unit', '');
        $formatted = number_format((float) $price, 0, ',', ' ');
        return '<span class="pct-price">' . esc_html(trim($prefix . $formatted . ' ' . $unit)) . '</span>';
    }

    private function render_action($product, $plugin) {
        if (!$product) {
            return '';
        }
        $buy_label = (string) $plugin->get_option('button_buy_label', 'Заказать');
        $request_label = (string) $plugin->get_option('button_request_label', 'Узнать цену');
        $show_qty = $plugin->get_option('show_quantity', 'yes') === 'yes';

        $purchasable = $product->is_purchasable() && $product->is_in_stock() && $product->get_price() !== '';

        if (!$purchasable) {
            return '<button type="button" class="pct-btn pct-btn-request" data-pct-request'
                . ' data-product-id="' . esc_attr($product->get_id()) . '"'
                . ' data-product-name="' . esc_attr($product->get_name()) . '">'
                . esc_html($request_label) . '</button>';
        }

        $html = '<span class="pct-action">';
        if ($show_qty) {
            $html .= '<input type="number" class="pct-qty" min="1" step="1" value="1" aria-label="Количество">';
        }
        $html .= '<a href="' . esc_url($product->add_to_cart_url()) . '"'
            . ' data-quantity="1"'
            . ' data-product_id="' . esc_attr($product->get_id()) . '"'
            . ' data-product_sku="' . esc_attr($product->get_sku()) . '"'
            . ' class="pct-btn pct-btn-buy button product_type_' . esc_attr($product->get_type()) . ' add_to_cart_button ajax_add_to_cart">'
            . esc_html($buy_label) . '</a>';
        $html .= '</span>';

        return $html;
    }

    private function sort_link($key, $label, $context) {
        $is_active = $context['orderby'] === $key;
        $next_order = ($is_active && $context['order'] === 'asc') ? 'desc' : 'asc';
        $url = $this->build_url($context['base_url'], $context['selected'], $key, $next_order, 1);

        $classes = array('pct-sort');
        if ($is_active) {
            $classes[] = 'is-active';
            $classes[] = 'is-' . $context['order'];
        }

        return '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">'
            . esc_html($label) . '<span class="pct-sort-icon" aria-hidden="true"></span></a>';
    }

    /* ---------------------------------------------------------------------
     * Пагинация
     * ------------------------------------------------------------------ */

    private function render_pagination($query, $context) {
        $total_pages = (int) $query->max_num_pages;
        if ($total_pages <= 1) {
            return;
        }

        $state_url = $this->build_url($context['base_url'], $context['selected'], $context['orderby'], $context['order'], 1);
        $links = paginate_links(array(
            'base'      => add_query_arg('pct_page', '%#%', $state_url),
            'format'    => '',
            'current'   => $context['page'],
            'total'     => $total_pages,
            'type'      => 'array',
            'prev_text' => '‹',
            'next_text' => '›',
            'mid_size'  => 2,
            'end_size'  => 1,
        ));

        if ($links) {
            echo '<nav class="pct-pagination">' . implode(' ', $links) . '</nav>';
        }
    }

    /* ---------------------------------------------------------------------
     * Модалка «Узнать цену»
     * ------------------------------------------------------------------ */

    private function render_request_modal($plugin) {
        $phone_required = $plugin->get_option('phone_required', 'yes') === 'yes';
        ?>
        <div class="pct-modal" id="pct-request-modal" hidden>
            <div class="pct-modal-overlay" data-pct-modal-close></div>
            <div class="pct-modal-box" role="dialog" aria-modal="true" aria-labelledby="pct-modal-title">
                <button type="button" class="pct-modal-close" data-pct-modal-close aria-label="Закрыть">&times;</button>
                <h3 class="pct-modal-title" id="pct-modal-title">Узнать цену</h3>
                <p class="pct-modal-product"></p>
                <form id="pct-request-form">
                    <input type="hidden" name="action" value="pct_request_price">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('pct_request_price')); ?>">
                    <input type="hidden" name="product_id" value="">
                    <input type="text" name="pct_hp" class="pct-hp" tabindex="-1" autocomplete="off">
                    <label class="pct-field">Имя
                        <input type="text" name="name">
                    </label>
                    <label class="pct-field">Телефон<?php echo $phone_required ? ' *' : ''; ?>
                        <input type="tel" name="phone" <?php echo $phone_required ? 'required' : ''; ?>>
                    </label>
                    <button type="submit" class="pct-btn pct-btn-buy pct-modal-submit">Отправить</button>
                    <p class="pct-modal-status" aria-live="polite"></p>
                </form>
            </div>
        </div>
        <?php
    }

    private function funnel_icon() {
        return '<svg class="pct-filter-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M1 2h14l-5.5 6.5V14l-3-1.5V8.5L1 2z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>';
    }
}
