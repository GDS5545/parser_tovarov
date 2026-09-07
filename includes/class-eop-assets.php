<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and enqueues the frontend assets, and exposes settings to JS.
 */
class EOP_Assets {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	public function enqueue_frontend() {
		$options = EOP_Settings::get_options();

		wp_enqueue_style(
			'eop-frontend',
			EOP_URL . 'assets/css/eop-frontend.css',
			array(),
			EOP_VERSION
		);

		wp_enqueue_script(
			'eop-frontend',
			EOP_URL . 'assets/js/eop-frontend.js',
			array(),
			EOP_VERSION,
			true
		);

		$keywords = array_filter( array_map( 'trim', explode( ',', $options['trigger_keywords'] ) ) );

		wp_localize_script(
			'eop-frontend',
			'EOP_SETTINGS',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'eop_submit_order' ),
				'triggerWords' => array_map( 'mb_strtolower', $keywords ),
				'selector'     => $options['trigger_selector'],
				'currency'     => $options['currency'],
				'requireEmail' => '1' === $options['require_email'],
				'i18n'         => array(
					'popupTitle'    => $options['popup_title'],
					'submitButton'  => $options['submit_button_text'],
					'successMsg'    => $options['success_message'],
					'errorMsg'      => $options['error_message'],
					'name'          => __( 'Ваше имя', 'elementor-order-popup' ),
					'phone'         => __( 'Телефон', 'elementor-order-popup' ),
					'email'         => __( 'Email', 'elementor-order-popup' ),
					'comment'       => __( 'Комментарий', 'elementor-order-popup' ),
					'total'         => __( 'Итого', 'elementor-order-popup' ),
					'remove'        => __( 'Удалить', 'elementor-order-popup' ),
					'addMore'       => __( 'Добавить ещё товар', 'elementor-order-popup' ),
					'addMoreHint'   => __( 'Закройте окно и выберите ещё один товар на странице', 'elementor-order-popup' ),
					'emptyCart'     => __( 'Список товаров пуст.', 'elementor-order-popup' ),
					'requiredField' => __( 'Заполните обязательные поля', 'elementor-order-popup' ),
					'sending'       => __( 'Отправка...', 'elementor-order-popup' ),
					'close'         => __( 'Закрыть', 'elementor-order-popup' ),
					'product'       => __( 'Товар', 'elementor-order-popup' ),
				),
			)
		);
	}
}
