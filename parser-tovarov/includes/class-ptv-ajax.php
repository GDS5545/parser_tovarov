<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All frontend AJAX endpoints for the catalog table and cart drawer.
 * Every handler starts by checking the shared 'ptv_ajax' nonce.
 */
class PTV_Ajax {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$actions = array(
			'ptv_get_table'        => 'get_table',
			'ptv_add_to_cart'      => 'add_to_cart',
			'ptv_get_cart'         => 'get_cart',
			'ptv_update_cart_item' => 'update_cart_item',
			'ptv_remove_cart_item' => 'remove_cart_item',
			'ptv_clear_cart'       => 'clear_cart',
			'ptv_submit_order'     => 'submit_order',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	private function verify_nonce() {
		if ( ! check_ajax_referer( 'ptv_ajax', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Сессия устарела, обновите страницу.', 'parser-tovarov' ) ), 403 );
		}
	}

	private function decode( $key, $default = array() ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $default;
		}
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( $raw, true );
		return ( null === $decoded && 'null' !== trim( $raw ) ) ? array( $raw ) : (array) $decoded;
	}

	/* -------------------------------------------------------------- *
	 * Table
	 * -------------------------------------------------------------- */

	public function get_table() {
		$this->verify_nonce();

		$categories = array_map( 'sanitize_title', $this->decode( 'categories' ) );
		$columns    = array_map( 'sanitize_text_field', $this->decode( 'columns' ) );
		$attributes = array();
		foreach ( $this->decode( 'attributes' ) as $taxonomy => $terms ) {
			$attributes[ sanitize_key( $taxonomy ) ] = array_map( 'sanitize_title', (array) $terms );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$orderby  = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'title'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order    = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'ASC'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $columns ) ) {
			$columns = array( 'name', 'price' );
		}

		$result = PTV_Query::query(
			array(
				'categories' => $categories,
				'attributes' => $attributes,
				'search'     => $search,
				'orderby'    => $orderby,
				'order'      => $order,
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);

		$products = $result['products'];

		ob_start();
		include PTV_PATH . 'templates/table-rows.php';
		$rows_html = ob_get_clean();

		wp_send_json_success(
			array(
				'rows_html' => $rows_html,
				'total'     => $result['total'],
				'max_pages' => $result['max_pages'],
				'page'      => $result['page'],
			)
		);
	}

	/* -------------------------------------------------------------- *
	 * Cart
	 * -------------------------------------------------------------- */

	public function add_to_cart() {
		$this->verify_nonce();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Корзина недоступна.', 'parser-tovarov' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'Товар недоступен для заказа.', 'parser-tovarov' ) ) );
		}

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );

		if ( ! $cart_item_key ) {
			wp_send_json_error( array( 'message' => __( 'Не удалось добавить товар в корзину.', 'parser-tovarov' ) ) );
		}

		wp_send_json_success(
			array(
				'cart_count' => WC()->cart->get_cart_contents_count(),
			)
		);
	}

	public function get_cart() {
		$this->verify_nonce();
		$this->send_cart_response();
	}

	public function update_cart_item() {
		$this->verify_nonce();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error();
		}

		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $key && $quantity > 0 ) {
			WC()->cart->set_quantity( $key, $quantity, true );
		} elseif ( $key ) {
			WC()->cart->remove_cart_item( $key );
		}

		$this->send_cart_response();
	}

	public function remove_cart_item() {
		$this->verify_nonce();

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error();
		}

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $key ) {
			WC()->cart->remove_cart_item( $key );
		}

		$this->send_cart_response();
	}

	public function clear_cart() {
		$this->verify_nonce();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$this->send_cart_response();
	}

	private function send_cart_response() {
		$items = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : array();

		ob_start();
		include PTV_PATH . 'templates/cart-items.php';
		$items_html = ob_get_clean();

		wp_send_json_success(
			array(
				'items_html' => $items_html,
				'cart_count' => ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0,
				'cart_total' => ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_total( 'edit' ) : 0,
			)
		);
	}

	/* -------------------------------------------------------------- *
	 * Quick order -> Bitrix24
	 * -------------------------------------------------------------- */

	public function submit_order() {
		$this->verify_nonce();

		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'Корзина пуста.', 'parser-tovarov' ) ) );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $name || ! $phone ) {
			wp_send_json_error( array( 'message' => __( 'Заполните имя и телефон.', 'parser-tovarov' ) ) );
		}

		$cart_snapshot = array();
		$total         = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$cart_snapshot[] = array(
				'id'       => $product->get_id(),
				'name'     => $product->get_name(),
				'sku'      => $product->get_sku(),
				'quantity' => $item['quantity'],
				'price'    => (float) $product->get_price(),
				'line_total' => (float) $item['line_total'],
			);
			$total += (float) $item['line_total'];
		}

		$min_amount = (float) PTV_Settings::instance()->get( 'min_order_amount' );
		if ( $min_amount > 0 && $total < $min_amount ) {
			wp_send_json_error(
				array(
					/* translators: %s: formatted minimum order amount */
					'message' => sprintf( __( 'Минимальная сумма заказа %s.', 'parser-tovarov' ), wp_strip_all_tags( wc_price( $min_amount ) ) ),
				)
			);
		}

		$attachment_url = '';
		if ( ! empty( $_FILES['attachment']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$upload = wp_handle_upload( $_FILES['attachment'], array( 'test_form' => false ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $upload['url'] ) ) {
				$attachment_url = $upload['url'];
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ptv_quick_orders';

		$wpdb->insert(
			$table,
			array(
				'created_at'     => current_time( 'mysql' ),
				'customer_name'  => $name,
				'customer_phone' => $phone,
				'comment'        => $comment,
				'attachment_url' => $attachment_url,
				'cart_json'      => wp_json_encode( $cart_snapshot ),
				'total_amount'   => $total,
				'status'         => 'new',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s' )
		);
		$order_row_id = $wpdb->insert_id;

		$bitrix_result = PTV_Bitrix::instance()->send_lead(
			array(
				'name'       => $name,
				'phone'      => $phone,
				'comment'    => $comment,
				'attachment' => $attachment_url,
				'items'      => $cart_snapshot,
				'total'      => $total,
			)
		);

		$update = array( 'status' => $bitrix_result['success'] ? 'sent' : 'error' );
		if ( $bitrix_result['success'] ) {
			$update['bitrix_entity_type'] = $bitrix_result['entity_type'];
			$update['bitrix_entity_id']   = $bitrix_result['entity_id'];
		} else {
			$update['error_message'] = $bitrix_result['message'];
		}
		$wpdb->update( $table, $update, array( 'id' => $order_row_id ) );

		// The lead/order is logged regardless of the Bitrix outcome, so we
		// still clear the cart and tell the customer their request was
		// received — a Bitrix hiccup shouldn't strand their submission.
		WC()->cart->empty_cart();

		wp_send_json_success(
			array(
				'message'        => __( 'Заявка отправлена! Мы свяжемся с вами в ближайшее время.', 'parser-tovarov' ),
				'bitrix_success' => $bitrix_result['success'],
			)
		);
	}
}
