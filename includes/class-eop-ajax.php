<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the AJAX submission of the order popup form.
 */
class EOP_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_eop_submit_order', array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_eop_submit_order', array( $this, 'handle_submit' ) );
	}

	public function handle_submit() {
		check_ajax_referer( 'eop_submit_order', 'nonce' );

		// Honeypot anti-spam field: real users never fill it.
		if ( ! empty( $_POST['eop_website'] ) ) {
			wp_send_json_success( array( 'message' => EOP_Settings::get_options()['success_message'] ) );
		}

		$options = EOP_Settings::get_options();

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		$items_raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';

		if ( '' === $name || '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Пожалуйста, заполните имя и телефон.', 'elementor-order-popup' ) ), 400 );
		}

		if ( '1' === $options['require_email'] && '' === $email ) {
			wp_send_json_error( array( 'message' => __( 'Пожалуйста, укажите email.', 'elementor-order-popup' ) ), 400 );
		}

		$items_decoded = json_decode( $items_raw, true );

		if ( empty( $items_decoded ) || ! is_array( $items_decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'В заявке нет ни одного товара.', 'elementor-order-popup' ) ), 400 );
		}

		$items = array();
		foreach ( $items_decoded as $raw_item ) {
			if ( empty( $raw_item['title'] ) ) {
				continue;
			}
			$items[] = array(
				'title' => sanitize_text_field( $raw_item['title'] ),
				'price' => isset( $raw_item['price'] ) ? (float) $raw_item['price'] : 0,
				'qty'   => isset( $raw_item['qty'] ) ? max( 1, (int) $raw_item['qty'] ) : 1,
				'sku'   => isset( $raw_item['sku'] ) ? sanitize_text_field( $raw_item['sku'] ) : '',
				'url'   => isset( $raw_item['url'] ) ? esc_url_raw( $raw_item['url'] ) : '',
			);
		}

		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'В заявке нет ни одного товара.', 'elementor-order-popup' ) ), 400 );
		}

		$order = array(
			'name'     => $name,
			'phone'    => $phone,
			'email'    => $email,
			'comment'  => $comment,
			'page_url' => $page_url,
			'items'    => $items,
		);

		/**
		 * Fires right before the order is dispatched to Bitrix24/Telegram.
		 * Useful for storing the lead locally or adding custom integrations.
		 */
		do_action( 'eop_before_send_order', $order );

		$integrations = new EOP_Integrations();
		$results      = $integrations->send( $order );

		$errors = array();
		foreach ( $results as $channel => $result ) {
			if ( is_wp_error( $result ) ) {
				$errors[ $channel ] = $result->get_error_message();
			}
		}

		if ( empty( $results ) ) {
			// No channel configured — still treat as success so the user isn't blocked,
			// but let admins know via the error log.
			error_log( 'Elementor Order Popup: order received but no delivery channel (Bitrix24/Telegram) is configured.' );
		}

		if ( ! empty( $errors ) && count( $errors ) === count( $results ) ) {
			wp_send_json_error( array( 'message' => $options['error_message'], 'details' => $errors ), 500 );
		}

		do_action( 'eop_after_send_order', $order, $results );

		wp_send_json_success( array( 'message' => $options['success_message'] ) );
	}
}
