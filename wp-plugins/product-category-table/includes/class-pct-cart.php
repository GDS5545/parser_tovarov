<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Плавающий список товаров ("Заказать") — НЕ WooCommerce-корзина. Кнопка «Заказать» в
 * таблице (см. class-pct-render.php + assets/frontend.js) ничего не отправляет на сервер:
 * она просто добавляет товар в список, хранящийся в localStorage браузера покупателя,
 * поэтому позиции копятся при переходах между категориями без лишних AJAX-запросов и без
 * PHP-сессии/WC()->cart. На сервер уходит только финальная отправка формы
 * (ajax_submit_order) — готовый список товаров вместе с именем и телефоном, которые
 * обязательны. Результат — лид в Bitrix24 (через входящий вебхук), с отправкой на email
 * как запасным вариантом, если Bitrix не настроен или недоступен.
 */
class PCT_Cart {
    const MAX_ITEMS = 100;

    public static function init() {
        add_action('wp_footer', array(__CLASS__, 'render_widget'));

        add_action('wp_ajax_pct_submit_order', array(__CLASS__, 'ajax_submit_order'));
        add_action('wp_ajax_nopriv_pct_submit_order', array(__CLASS__, 'ajax_submit_order'));
    }

    public static function render_widget() {
        if (is_admin() || !function_exists('WC')) {
            return;
        }
        if (PCT_Plugin::instance()->get_option('floating_cart', 'yes') !== 'yes') {
            return;
        }
        ?>
        <div class="pct-cart-widget" id="pct-cart-widget">
            <button type="button" class="pct-cart-toggle" id="pct-cart-toggle" aria-label="Список товаров">
                <?php echo self::cart_icon(); ?>
                <span class="pct-cart-count" id="pct-cart-count" hidden>0</span>
            </button>
            <div class="pct-cart-drawer" id="pct-cart-drawer" hidden>
                <div class="pct-cart-drawer-overlay" data-pct-cart-close></div>
                <div class="pct-cart-drawer-box" role="dialog" aria-modal="true" aria-labelledby="pct-cart-title">
                    <div class="pct-cart-drawer-head">
                        <h3 id="pct-cart-title">Список товаров</h3>
                        <button type="button" class="pct-cart-drawer-close" data-pct-cart-close aria-label="Закрыть">&times;</button>
                    </div>
                    <div class="pct-cart-drawer-body" id="pct-cart-items">
                        <p class="pct-cart-empty">Список пуст.</p>
                    </div>
                    <div class="pct-cart-drawer-foot">
                        <form id="pct-cart-order-form">
                            <input type="hidden" name="action" value="pct_submit_order">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('pct_cart')); ?>">
                            <input type="hidden" name="items" value="[]">
                            <input type="text" name="pct_hp" class="pct-hp" tabindex="-1" autocomplete="off">
                            <label class="pct-field">Имя *
                                <input type="text" name="name" required>
                            </label>
                            <label class="pct-field">Телефон *
                                <input type="tel" name="phone" required>
                            </label>
                            <button type="submit" class="pct-btn pct-btn-buy pct-cart-submit">Отправить заявку</button>
                            <p class="pct-cart-status" aria-live="polite"></p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private static function cart_icon() {
        return '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M1 1h2l1.7 9.9a2 2 0 0 0 2 1.6h6.9a2 2 0 0 0 2-1.6L17 5H4.3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="17" r="1.3" fill="currentColor"/><circle cx="15" cy="17" r="1.3" fill="currentColor"/></svg>';
    }

    /**
     * Список товаров приходит из localStorage клиента как JSON [{id, qty}, ...] — доверять
     * ему нельзя (id/qty легко подделать через devtools), поэтому здесь используется только
     * id и qty из этого JSON, а настоящие название и существование товара заново проверяются
     * через wc_get_product(). Не больше MAX_ITEMS позиций за раз — это список для одной
     * заявки покупателя, а не весь каталог, так что по памяти это безопасно.
     */
    private static function parse_items($raw) {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return array();
        }

        $items = array();
        foreach (array_slice($decoded, 0, self::MAX_ITEMS) as $entry) {
            if (!is_array($entry) || empty($entry['id'])) {
                continue;
            }
            $product_id = absint($entry['id']);
            $qty = isset($entry['qty']) ? absint($entry['qty']) : 1;
            if (!$product_id || $qty < 1) {
                continue;
            }
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $items[] = array(
                'product' => $product,
                'qty'     => $qty,
            );
        }
        return $items;
    }

    public static function ajax_submit_order() {
        check_ajax_referer('pct_cart', 'nonce');

        // Honeypot: боту отвечаем "успехом", чтобы он не пытался снова, но лид не создаём.
        if (!empty($_POST['pct_hp'])) {
            wp_send_json_success(array('message' => 'Заявка принята.'));
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        if ($name === '') {
            wp_send_json_error(array('message' => 'Укажите имя.'));
        }
        if ($phone === '') {
            wp_send_json_error(array('message' => 'Укажите телефон.'));
        }

        $items = self::parse_items(wp_unslash($_POST['items'] ?? '[]'));
        if (!$items) {
            wp_send_json_error(array('message' => 'Список товаров пуст. Добавьте хотя бы один товар кнопкой «Заказать».'));
        }

        $lines = array();
        foreach ($items as $item) {
            $lines[] = sprintf('%s — %d шт.', $item['product']->get_name(), $item['qty']);
        }
        $comment = implode("\n", $lines);

        $sent = self::send_to_bitrix($name, $phone, $comment);
        if (!$sent) {
            $sent = self::send_by_email($name, $phone, $comment);
        }

        if (!$sent) {
            wp_send_json_error(array('message' => 'Не удалось отправить заявку, попробуйте ещё раз или позвоните нам напрямую.'));
        }

        wp_send_json_success(array(
            'message' => 'Спасибо! Заявка отправлена, мы свяжемся с вами по телефону.',
        ));
    }

    /**
     * Лид в Bitrix24 через входящий вебхук (в Bitrix24: Настройки → Разработчикам →
     * Другое → Входящий вебхук, права — как минимум "CRM"). URL вида
     * https://ваш-портал.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxxxxxx/ — метод дописывается ниже.
     * Настраивается в WooCommerce → Category Table. Если поле пустое — эта функция ничего
     * не делает, и заявка уходит письмом (send_by_email).
     */
    private static function send_to_bitrix($name, $phone, $comment) {
        $webhook_url = trim((string) PCT_Plugin::instance()->get_option('bitrix_webhook_url', ''));
        if ($webhook_url === '') {
            return false;
        }

        $endpoint = rtrim($webhook_url, '/') . '/crm.lead.add.json';

        $response = wp_remote_post($endpoint, array(
            'timeout' => 15,
            'body'    => array(
                'fields' => array(
                    'TITLE'     => sprintf('Заявка с сайта: %s', $name),
                    'NAME'      => $name,
                    'PHONE'     => array(
                        array('VALUE' => $phone, 'VALUE_TYPE' => 'WORK'),
                    ),
                    'COMMENTS'  => $comment,
                    'SOURCE_ID' => 'WEB',
                ),
                'params' => array(
                    'REGISTER_SONET_EVENT' => 'Y',
                ),
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) && !empty($body['result']);
    }

    private static function send_by_email($name, $phone, $comment) {
        $to = (string) PCT_Plugin::instance()->get_option('request_email', get_option('admin_email'));
        if (!is_email($to)) {
            $to = get_option('admin_email');
        }

        $subject = sprintf('Заявка с сайта: %s', $name);
        $body = sprintf("Имя: %s\nТелефон: %s\n\nТовары:\n%s", $name, $phone, $comment);

        return (bool) wp_mail($to, $subject, $body);
    }
}
