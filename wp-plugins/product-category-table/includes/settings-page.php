<?php
if (!defined('ABSPATH')) {
    exit;
}

/** @var PCT_Plugin $this — включён через PCT_Plugin::settings_page() */
if (!current_user_can('manage_woocommerce')) {
    return;
}

$opts = $this->get_options();
$taxonomy_attribute_options = $this->get_available_taxonomy_attributes();
$local_attribute_options = PCT_Attributes::local_registry();
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
    <p>Таблица товаров WooCommerce для категорий. По умолчанию фильтры и колонки характеристик определяются <strong>автоматически для каждой категории отдельно</strong> — плагин смотрит, какие атрибуты товара реально назначены именно в этой категории (у круга это, например, Марка/Диаметр/ГОСТ, у листа — Марка/Толщина/Покрытие), и показывает только их. Поля «Фильтры»/«Колонки таблицы» ниже нужны, только если хотите принудительно задать один и тот же список для всех категорий.</p>

    <div class="notice notice-info">
        <p>
            <strong>Найдено атрибутов:</strong>
            глобальных WooCommerce (<code>pa_*</code>) — <?php echo (int) count($taxonomy_attribute_options); ?>,
            локальных (атрибуты прямо на товаре, вкладка «Детали»/«Дополнительная информация») — <?php echo (int) count($local_attribute_options); ?>.
            <?php if ($local_attribute_options) : ?>
                <br>Локальные: <?php echo esc_html(implode(', ', $local_attribute_options)); ?>.
            <?php endif; ?>
        </p>
        <?php if (empty($taxonomy_attribute_options) && empty($local_attribute_options)) : ?>
            <p><strong>Ничего не найдено.</strong> Если товары точно уже сохранены с характеристиками — нажмите «Пересканировать все товары» ниже: локальные атрибуты подхватываются автоматически только при сохранении товара, а для уже импортированных нужен один разовый проход.</p>
        <?php endif; ?>
        <p>
            <button type="button" class="button" id="pct-reindex-btn">Пересканировать все товары</button>
            <span id="pct-reindex-status"></span>
        </p>
        <p class="description">Нужно один раз после установки/обновления плагина (или после импорта партии товаров парсером в обход обычного сохранения товара). Обрабатывает магазин пакетами по 200 товаров через AJAX — безопасно для больших каталогов, не выполняется одним долгим запросом.</p>
    </div>

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
                <th scope="row">Фильтры (принудительно)</th>
                <td>
                    <?php $render_multiselect('filter_attributes', $opts['filter_attributes'], 'Если не выбрано — фильтры определяются автоматически по товарам каждой категории (см. пояснение вверху страницы). Если выбрать здесь — этот список будет одинаковым для ВСЕХ категорий, авто-определение отключится.'); ?>
                    <p><label>Максимум фильтров (0 — без ограничения, показывать всё найденное): <input type="number" min="0" max="20" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[max_filters]" value="<?php echo esc_attr($opts['max_filters']); ?>"></label></p>
                </td>
            </tr>
            <tr>
                <th scope="row">Колонки таблицы (принудительно)</th>
                <td>
                    <?php $render_multiselect('column_attributes', $opts['column_attributes'], 'Порядок выбора — порядок колонок между «Наименование» и «Цена». Если не выбрано — колонки = те же атрибуты, что определены как фильтры для категории.'); ?>
                    <p><label>Максимум колонок (0 — без ограничения): <input type="number" min="0" max="20" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[max_columns]" value="<?php echo esc_attr($opts['max_columns']); ?>"></label></p>
                    <p class="description">Таблица прокручивается по горизонтали, так что много колонок — не проблема для вёрстки.</p>
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
                    <label>Текст кнопки заказа: <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[button_buy_label]" value="<?php echo esc_attr($opts['button_buy_label']); ?>" class="regular-text"></label><br>
                    <label>Текст кнопки заявки (для товаров без цены/остатка): <input type="text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[button_request_label]" value="<?php echo esc_attr($opts['button_request_label']); ?>" class="regular-text"></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[show_quantity]" value="yes" <?php checked($opts['show_quantity'], 'yes'); ?>> показывать поле количества рядом с кнопкой заказа</label>
                </td>
            </tr>
            <tr>
                <th scope="row">Плавающая корзина</th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[floating_cart]" value="yes" <?php checked($opts['floating_cart'], 'yes'); ?>> показывать плавающую кнопку корзины на всех страницах сайта</label>
                    <p class="description">Кнопка «<?php echo esc_html($opts['button_buy_label']); ?>» добавляет товар в обычную корзину WooCommerce без перезагрузки страницы. Покупатель может продолжать выбирать товары в других категориях — всё останется в корзине. По клику на плавающую кнопку открывается корзина, где можно изменить количество, удалить позицию и оформить заказ по имени и телефону (без полной формы оплаты/доставки WooCommerce — оформленные так заказы появляются в WooCommerce → Заказы).</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Заявки «Узнать цену» и заказы</th>
                <td>
                    <label>Email для заявок «Узнать цену»: <input type="email" class="regular-text" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[request_email]" value="<?php echo esc_attr($opts['request_email']); ?>"></label><br>
                    <label><input type="checkbox" name="<?php echo esc_attr(PCT_Plugin::OPTION_KEY); ?>[phone_required]" value="yes" <?php checked($opts['phone_required'], 'yes'); ?>> телефон обязателен</label>
                    <p class="description">Заказы из корзины уходят стандартным уведомлением WooCommerce «Новый заказ» на email из WooCommerce → Настройки → Email — это поле только для заявок «Узнать цену».</p>
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
                    <p class="description">Через запятую, используется только при авто-подборе фильтров/колонок (когда выше ничего явно не выбрано) — задаёт порядок. Пишите просто хвост slug, без <code>pa_</code>: атрибут будет найден и как глобальный <code>pa_marka</code>, и как локальный.</p>
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
<script>
(function ($) {
    var $btn = $('#pct-reindex-btn');
    var $status = $('#pct-reindex-status');

    $btn.on('click', function () {
        $btn.prop('disabled', true);
        $status.text('Сканируем…');

        function step(offset, processedTotal) {
            $.post(ajaxurl, {
                action: 'pct_reindex_batch',
                nonce: '<?php echo esc_js(wp_create_nonce('pct_reindex')); ?>',
                offset: offset
            }).done(function (response) {
                if (!response || !response.success) {
                    $status.text('Ошибка: ' + ((response && response.data && response.data.message) || 'не удалось выполнить запрос.'));
                    $btn.prop('disabled', false);
                    return;
                }
                var data = response.data;
                var total = processedTotal + data.processed;
                $status.text('Обработано товаров: ' + total + '. Найдено локальных атрибутов: ' + data.attributes_found + '…');

                if (data.done) {
                    $status.text('Готово. Обработано товаров: ' + total + '. Найдено локальных атрибутов: ' + data.attributes_found + '. Обновите страницу категории, чтобы увидеть фильтры.');
                    $btn.prop('disabled', false);
                } else {
                    step(data.next_offset, total);
                }
            }).fail(function () {
                $status.text('Сбой запроса, попробуйте ещё раз.');
                $btn.prop('disabled', false);
            });
        }

        step(0, 0);
    });
})(jQuery);
</script>
