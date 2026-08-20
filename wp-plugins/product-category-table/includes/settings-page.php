<?php
if (!defined('ABSPATH')) {
    exit;
}

/** @var PCT_Plugin $this — включён через PCT_Plugin::settings_page() */
if (!current_user_can('manage_woocommerce')) {
    return;
}

$opts = $this->get_options();
$attribute_options = $this->get_available_attribute_taxonomies();

$render_multiselect = function ($name, $selected, $placeholder = '') use ($attribute_options) {
    $selected = (array) $selected;
    echo '<select multiple size="8" style="min-width:320px;max-width:100%;" name="' . esc_attr(PCT_Plugin::OPTION_KEY . '[' . $name . '][]') . '">';
    foreach ($attribute_options as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected(in_array($key, $selected, true), true, false) . '>' . esc_html($label . ' (' . $key . ')') . '</option>';
    }
    echo '</select>';
    if ($placeholder) {
        echo '<p class="description">' . esc_html($placeholder) . '</p>';
    }
};
?>
<div class="wrap">
    <h1>Product Category Table</h1>
    <p>Таблица товаров WooCommerce для категорий: фильтры и колонки строятся только из атрибутов WooCommerce (<code>pa_*</code>), назначенных прямо на товар.</p>

    <?php if (empty($attribute_options)) : ?>
        <div class="notice notice-warning"><p>В магазине пока нет ни одного глобального атрибута WooCommerce (Товары → Атрибуты). Без них фильтры и колонки характеристик будут пустыми.</p></div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields('pct_settings'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Автоматически на категориях</th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[auto_category]" value="yes" <?php checked($opts['auto_category'], 'yes'); ?>> включить для всех категорий товаров</label>
                    <p class="description">Если выключить, выводите таблицу шорткодом <code>[product_category_table]</code>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Товаров на странице</th>
                <td><input type="number" min="5" max="200" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[per_page]" value="<?php echo esc_attr($opts['per_page']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">Фильтры</th>
                <td>
                    <?php $render_multiselect('filter_attributes', $opts['filter_attributes'], 'Зажми Ctrl/Cmd для выбора нескольких. Если ничего не выбрано — берутся первые атрибуты из списка приоритета ниже.'); ?>
                    <p><label>Максимум фильтров при авто-подборе: <input type="number" min="1" max="10" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[max_filters]" value="<?php echo esc_attr($opts['max_filters']); ?>"></label></p>
                </td>
            </tr>
            <tr>
                <th scope="row">Колонки таблицы</th>
                <td>
                    <?php $render_multiselect('column_attributes', $opts['column_attributes'], 'Порядок выбора — порядок колонок между «Наименование» и «Цена». Если не выбрано — берутся первые колонки из фильтров выше.'); ?>
                    <p><label>Максимум колонок характеристик: <input type="number" min="1" max="8" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[max_columns]" value="<?php echo esc_attr($opts['max_columns']); ?>"></label></p>
                </td>
            </tr>
            <tr>
                <th scope="row">Быстрые кнопки сверху (chips)</th>
                <td>
                    <select name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[chips_attribute]">
                        <option value="">— первый фильтр —</option>
                        <?php foreach ($attribute_options as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($opts['chips_attribute'], $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p><label>Показывать значений до кнопки «Смотреть все»: <input type="number" min="0" max="30" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[chips_limit]" value="<?php echo esc_attr($opts['chips_limit']); ?>"></label> (0 — показывать все сразу)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Цена</th>
                <td>
                    <label>Префикс: <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[price_prefix]" value="<?php echo esc_attr($opts['price_prefix']); ?>" class="regular-text"></label><br>
                    <label>Единица: <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[price_unit]" value="<?php echo esc_attr($opts['price_unit']); ?>" class="regular-text"></label>
                    <p class="description">Например: «от » + «355» + «руб./кг» → «от 355 руб./кг». Если у товара нет цены — вместо неё показывается «По запросу».</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Кнопки</th>
                <td>
                    <label>Текст кнопки покупки: <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[button_buy_label]" value="<?php echo esc_attr($opts['button_buy_label']); ?>" class="regular-text"></label><br>
                    <label>Текст кнопки заявки (для товаров без цены/остатка): <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[button_request_label]" value="<?php echo esc_attr($opts['button_request_label']); ?>" class="regular-text"></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[show_quantity]" value="yes" <?php checked($opts['show_quantity'], 'yes'); ?>> показывать поле количества рядом с кнопкой покупки</label>
                </td>
            </tr>
            <tr>
                <th scope="row">Заявки «Узнать цену»</th>
                <td>
                    <label>Email для заявок: <input type="email" class="regular-text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[request_email]" value="<?php echo esc_attr($opts['request_email']); ?>"></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[phone_required]" value="yes" <?php checked($opts['phone_required'], 'yes'); ?>> телефон обязателен</label>
                </td>
            </tr>
            <tr>
                <th scope="row">Дата актуализации сортамента</th>
                <td>
                    <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[catalog_updated_date]" value="<?php echo esc_attr($opts['catalog_updated_date']); ?>" class="regular-text" placeholder="12.08.2026">
                    <p class="description">Показывается справа от заголовка категории: «Сортамент товаров актуализирован: …». Оставьте пустым, чтобы не показывать. Обновляйте вручную после каждого запуска парсера.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Приоритет атрибутов</th>
                <td>
                    <textarea name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[priority_attributes]" rows="3" class="large-text"><?php echo esc_textarea($opts['priority_attributes']); ?></textarea>
                    <p class="description">Через запятую, используется только при авто-подборе фильтров/колонок (когда выше ничего явно не выбрано). Можно писать хвост slug без <code>pa_</code>.</p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <h2>Шорткод</h2>
    <p>Для текущей категории: <code>[product_category_table]</code></p>
    <p>Для конкретной категории: <code>[product_category_table category="krug-nerzhaveyushchij"]</code></p>
    <p>Для всех товаров: <code>[product_category_table category="all"]</code></p>
</div>
