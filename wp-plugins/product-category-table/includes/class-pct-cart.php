<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Плавающая корзина поверх обычной корзины WooCommerce (WC()->cart, обычная сессия).
 * Кнопка «Заказать» в таблице добавляет товар стандартным WooCommerce ajax add-to-cart
 * (см. class-pct-render.php + assets/frontend.js) — этот класс только показывает
 * содержимое той же корзины в выезжающей панели и оформляет заказ по имени/телефону
 * без полной формы оплаты/доставки WooCommerce. Заказ создаётся как обычный WC_Order,
 * поэтому попадает в WooCommerce → Заказы и уходит штатным письмом «Новый заказ».
 */
class PCT_Cart {
    public static function init() {
        add_action('wp_footer', array(__CLASS__, 'render_widget'));

        add_action('wp_ajax_pct_get_cart', array(__CLASS__, 'ajax_get_cart'));
        add_action('wp_ajax_nopriv_pct_get_cart', array(__CLASS__, 'ajax_get_cart'));
        add_action('wp_ajax_pct_update_cart_item', array(__CLASS__, 'ajax_update_item'));
        add_action('wp_ajax_nopriv_pct_update_cart_item', array(__CLASS__, 'ajax_update_item'));
        add_action('wp_ajax_pct_remove_cart_item', array(__CLASS__, 'ajax_remove_item'));
        add_action('wp_ajax_nopriv_pct_remove_cart_item', array(__CLASS__, 'ajax_remove_item'));
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
            <button type="button" class="pct-cart-toggle" id="pct-cart-toggle" aria-label="Корзина">
                <?php echo self::cart_icon(); ?>
                <span class="pct-cart-count" id="pct-cart-count" hidden>0</span>
            </button>
            <div class="pct-cart-drawer" id="pct-cart-drawer" hidden>
                <div class="pct-cart-drawer-overlay" data-pct-cart-close></div>
                <div class="pct-cart-drawer-box" role="dialog" aria-modal="true" aria-labelledby="pct-cart-title">
                    <div class="pct-cart-drawer-head">
                        <h3 id="pct-cart-title">Корзина</h3>
                        <button type="button" class="pct-cart-drawer-close" data-pct-cart-close aria-label="Закрыть">&times;</button>
                    </div>
                    <div class="pct-cart-drawer-body" id="pct-cart-items">
                        <p class="pct-cart-empty">Корзина пуста.</p>
                    </div>
                    <div class="pct-cart-drawer-foot">
                        <div class="pct-cart-total">Итого: <strong id="pct-cart-total">—</strong></div>
                        <form id="pct-cart-order-form">
                            <input type="hidden" name="action" value="pct_submit_order">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('pct_cart')); ?>">
                            <input type="text" name="pct_hp" class="pct-hp" tabindex="-1" autocomplete="off">
                            <label class="pct-field">Имя
                                <input type="text" name="name" required>
                            </label>
                            <label class="pct-field">Телефон *
                                <input type="tel" name="phone" required>
                            </label>
                            <button type="submit" class="pct-btn pct-btn-buy pct-cart-submit">Оформить заказ</button>
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

    private static function cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return null;
        }
        return WC()->cart;
    }

    private static function summary($message = '') {
        $cart = self::cart();
        return array(
            'count'      => $cart ? $cart->get_cart_contents_count() : 0,
            'total'      => $cart ? wp_strip_all_tags($cart->get_cart_total()) : '',
            'items_html' => self::render_items_html(),
            'message'    => $message,
        );
    }

    private static function render_items_html() {
        $cart = self::cart();
        if (!$cart || $cart->is_empty()) {
            return '<p class="pct-cart-empty">Корзина пуста.</p>';
        }

        ob_start();
        foreach ($cart->get_cart() as $key => $item) {
            $product = isset($item['data']) ? $item['data'] : null;
            if (!$product) {
                continue;
            }
            $qty = (int) $item['quantity'];
            $line_total = wc_price($cart->get_product_subtotal($product, $qty));
            ?>
            <div class="pct-cart-item" data-key="<?php echo esc_attr($key); ?>">
                <div class="pct-cart-item-name"><?php echo esc_html($product->get_name()); ?></div>
                <div class="pct-cart-item-row">
                    <input type="number" class="pct-cart-item-qty" min="1" step="1" value="<?php echo esc_attr($qty); ?>" data-key="<?php echo esc_attr($key); ?>" aria-label="Количество">
                    <span class="pct-cart-item-price"><?php echo wp_kses_post($line_total); ?></span>
                    <button type="button" class="pct-cart-item-remove" data-key="<?php echo esc_attr($key); ?>" aria-label="Удалить">&times;</button>
                </div>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    public static function ajax_get_cart() {
        check_ajax_referer('pct_cart', 'nonce');
        wp_send_json_success(self::summary());
    }

    public static function ajax_update_item() {
        check_ajax_referer('pct_cart', 'nonce');
        $cart = self::cart();
        if (!$cart) {
            wp_send_json_error(array('message' => 'Корзина недоступна.'));
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $qty = isset($_POST['quantity']) ? absint($_POST['quantity']) : 0;
        if ($key === '' || !isset($cart->get_cart()[$key])) {
            wp_send_json_error(array('message' => 'Товар не найден в корзине.'));
        }

        if ($qty < 1) {
            $cart->remove_cart_item($key);
        } else {
            $cart->set_quantity($key, $qty);
        }
        $cart->calculate_totals();

        wp_send_json_success(self::summary());
    }

    public static function ajax_remove_item() {
        check_ajax_referer('pct_cart', 'nonce');
        $cart = self::cart();
        if (!$cart) {
            wp_send_json_error(array('message' => 'Корзина недоступна.'));
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        if ($key !== '' && isset($cart->get_cart()[$key])) {
            $cart->remove_cart_item($key);
            $cart->calculate_totals();
        }

        wp_send_json_success(self::summary());
    }

    public static function ajax_submit_order() {
        check_ajax_referer('pct_cart', 'nonce');

        // Honeypot: боту отвечаем "успехом", чтобы он не пытался снова, но заказ не создаём.
        if (!empty($_POST['pct_hp'])) {
            wp_send_json_success(array('message' => 'Заказ принят.', 'count' => 0));
        }

        $cart = self::cart();
        if (!$cart || $cart->is_empty()) {
            wp_send_json_error(array('message' => 'Корзина пуста.'));
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        if ($phone === '') {
            wp_send_json_error(array('message' => 'Укажите телефон.'));
        }

        $order = wc_create_order();
        if (is_wp_error($order)) {
            wp_send_json_error(array('message' => 'Не удалось создать заказ, попробуйте ещё раз.'));
        }

        foreach ($cart->get_cart() as $item) {
            $product = isset($item['data']) ? $item['data'] : null;
            if (!$product) {
                continue;
            }
            $order->add_product($product, $item['quantity']);
        }

        $name = $name !== '' ? $name : 'Клиент';
        $name_parts = explode(' ', $name, 2);
        $order->set_billing_first_name($name_parts[0]);
        if (!empty($name_parts[1])) {
            $order->set_billing_last_name($name_parts[1]);
        }
        $order->set_billing_phone($phone);
        $order->set_payment_method('pct_quick_order');
        $order->set_payment_method_title('Быстрый заказ (имя и телефон)');
        $order->set_created_via('product_category_table');
        $order->calculate_totals();
        $order->set_status('pending');
        $order->save();
        // Переход pending → on-hold — штатный триггер письма WooCommerce «Новый заказ» админу.
        $order->update_status('on-hold', sprintf('Быстрый заказ через плавающую корзину: %s, %s.', $name, $phone));

        $cart->empty_cart();

        wp_send_json_success(array(
            'message' => sprintf('Спасибо! Заказ №%s принят, мы свяжемся с вами по телефону.', $order->get_order_number()),
            'count'   => 0,
        ));
    }
}
