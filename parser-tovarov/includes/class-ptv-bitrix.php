<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Bitrix24 REST client: pushes a quick-order request (from the cart
 * checkout modal) into Bitrix24 as a Lead or a Deal via an incoming
 * webhook, no OAuth app required.
 */
class PTV_Bitrix {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @param array $data { name, phone, comment, attachment, items (array), total (float) }
	 * @return array { success: bool, entity_type?: string, entity_id?: int, message?: string }
	 */
	public function send_lead( array $data ) {
		$settings    = PTV_Settings::instance();
		$webhook_url = trim( $settings->get( 'bitrix_webhook_url' ) );

		if ( ! $webhook_url ) {
			return array(
				'success' => false,
				'message' => __( 'Webhook Bitrix24 не настроен в настройках плагина.', 'parser-tovarov' ),
			);
		}

		$entity   = $settings->get( 'bitrix_entity' );
		$method   = 'deal' === $entity ? 'crm.deal.add' : 'crm.lead.add';
		$endpoint = trailingslashit( $webhook_url ) . $method . '.json';

		$comments = $this->build_comments( $data );

		$fields = array(
			'TITLE'      => sprintf( /* translators: %s: customer name */ __( 'Заявка с сайта — %s', 'parser-tovarov' ), $data['name'] ),
			'NAME'       => $data['name'],
			'COMMENTS'   => $comments,
			'OPPORTUNITY' => $data['total'],
			'CURRENCY_ID' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'RUB',
			'SOURCE_ID'  => 'WEB',
		);

		if ( 'lead' === $entity ) {
			$fields['PHONE'] = array(
				array(
					'VALUE'      => $data['phone'],
					'VALUE_TYPE' => 'WORK',
				),
			);
		} else {
			// Deals have no native phone field without a linked contact;
			// keep it simple and reliable by folding the phone into the
			// title/comments instead of creating a Contact entity.
			$fields['TITLE'] .= ' — ' . $data['phone'];
		}

		$responsible = absint( $settings->get( 'bitrix_responsible' ) );
		if ( $responsible ) {
			$fields['ASSIGNED_BY_ID'] = $responsible;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'body'    => array(
					'fields' => $fields,
					'params' => array( 'REGISTER_SONET_EVENT' => 'Y' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && isset( $body['result'] ) ) {
			return array(
				'success'     => true,
				'entity_type' => $entity,
				'entity_id'   => (int) $body['result'],
			);
		}

		$error_message = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : __( 'Bitrix24 вернул ошибку.', 'parser-tovarov' ) );

		return array(
			'success' => false,
			'message' => $error_message,
		);
	}

	private function build_comments( array $data ) {
		$lines = array();

		$lines[] = __( 'Заявка на быстрый заказ с сайта', 'parser-tovarov' );
		$lines[] = __( 'Имя:', 'parser-tovarov' ) . ' ' . $data['name'];
		$lines[] = __( 'Телефон:', 'parser-tovarov' ) . ' ' . $data['phone'];

		if ( ! empty( $data['comment'] ) ) {
			$lines[] = __( 'Комментарий:', 'parser-tovarov' ) . ' ' . $data['comment'];
		}

		if ( ! empty( $data['attachment'] ) ) {
			$lines[] = __( 'Вложение:', 'parser-tovarov' ) . ' ' . $data['attachment'];
		}

		$lines[] = '';
		$lines[] = __( 'Состав заказа:', 'parser-tovarov' );

		foreach ( $data['items'] as $item ) {
			$lines[] = sprintf(
				'— %1$s%2$s x%3$s = %4$s',
				$item['name'],
				$item['sku'] ? ' (' . $item['sku'] . ')' : '',
				$item['quantity'],
				number_format( $item['line_total'], 2, '.', ' ' )
			);
		}

		$lines[] = '';
		$lines[] = __( 'Итого:', 'parser-tovarov' ) . ' ' . number_format( $data['total'], 2, '.', ' ' );

		return implode( "\n", $lines );
	}
}
