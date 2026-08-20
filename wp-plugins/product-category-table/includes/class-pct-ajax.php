<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Единственный кастомный AJAX-обработчик плагина: заявка «Узнать цену» для товаров без
 * цены/остатка. Кнопка «Купить» использует стандартный ajax add-to-cart WooCommerce
 * (wc-add-to-cart.js), отдельный обработчик для этого не нужен.
 */
class PCT_Ajax {
    public static function init($plugin) {
        add_action('wp_ajax_pct_request_price', array(__CLASS__, 'handle_request_price'));
        add_action('wp_ajax_nopriv_pct_request_price', array(__CLASS__, 'handle_request_price'));
    }

    public static function handle_request_price() {
        check_ajax_referer('pct_request_price', 'nonce');

        // Простая honey-pot защита от ботов: скрытое поле, которое человек не заполнит.
        if (!empty($_POST['pct_hp'])) {
            wp_send_json_success(array('message' => 'Заявка отправлена. Мы свяжемся с вами.'));
        }

        $plugin = PCT_Plugin::instance();
        $product_id = absint($_POST['product_id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

        if (!$product_id || get_post_type($product_id) !== 'product') {
            wp_send_json_error(array('message' => 'Товар не найден.'));
        }

        if ($plugin->get_option('phone_required', 'yes') === 'yes' && $phone === '') {
            wp_send_json_error(array('message' => 'Укажите телефон.'));
        }

        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : ('Товар #' . $product_id);
        $to = $plugin->get_option('request_email', get_option('admin_email'));

        $subject = sprintf('Заявка «Узнать цену»: %s', $product_name);
        $body = "Товар: {$product_name}\n"
            . 'Ссылка: ' . get_permalink($product_id) . "\n"
            . 'Имя: ' . ($name !== '' ? $name : '—') . "\n"
            . 'Телефон: ' . ($phone !== '' ? $phone : '—') . "\n";

        wp_mail($to, $subject, $body);

        wp_send_json_success(array('message' => 'Заявка отправлена. Мы свяжемся с вами.'));
    }
}
