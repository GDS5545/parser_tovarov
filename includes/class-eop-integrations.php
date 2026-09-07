<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the collected order to Bitrix24 CRM and/or Telegram.
 */
class EOP_Integrations {

	/**
	 * @param array $order {
	 *     @type string $name
	 *     @type string $phone
	 *     @type string $email
	 *     @type string $comment
	 *     @type string $page_url
	 *     @type array  $items  List of ['title'=>, 'price'=>, 'qty'=>, 'sku'=>, 'url'=>]
	 * }
	 * @return array Results per channel, e.g. ['bitrix' => true|WP_Error, 'telegram' => true|WP_Error]
	 */
	public function send( $order ) {
		$options = EOP_Settings::get_options();
		$results = array();

		if ( '1' === $options['bitrix_enabled'] && ! empty( $options['bitrix_webhook'] ) ) {
			$results['bitrix'] = $this->send_to_bitrix24( $order, $options );
		}

		if ( '1' === $options['telegram_enabled'] && ! empty( $options['telegram_token'] ) && ! empty( $options['telegram_chat_id'] ) ) {
			$results['telegram'] = $this->send_to_telegram( $order, $options );
		}

		return $results;
	}

	private function build_items_summary( $order, $options, $plain = true ) {
		$lines = array();
		$total = 0;

		foreach ( $order['items'] as $item ) {
			$qty       = max( 1, (int) $item['qty'] );
			$price     = is_numeric( $item['price'] ) ? (float) $item['price'] : 0;
			$line_total = $price * $qty;
			$total     += $line_total;

			if ( $plain ) {
				$line = sprintf( '- %s x%d', $item['title'], $qty );
				if ( $price > 0 ) {
					$line .= sprintf( ' — %s %s', number_format_i18n( $line_total ), $options['currency'] );
				}
			} else {
				$line = sprintf( '• <b>%s</b> x%d', esc_html( $item['title'] ), $qty );
				if ( $price > 0 ) {
					$line .= sprintf( ' — %s %s', number_format_i18n( $line_total ), esc_html( $options['currency'] ) );
				}
			}
			$lines[] = $line;
		}

		return array(
			'text'  => implode( "\n", $lines ),
			'total' => $total,
		);
	}

	private function send_to_bitrix24( $order, $options ) {
		$entity  = ( 'deal' === $options['bitrix_entity'] ) ? 'deal' : 'lead';
		$method  = 'lead' === $entity ? 'crm.lead.add.json' : 'crm.deal.add.json';
		$webhook = trailingslashit( $options['bitrix_webhook'] ) . $method;

		$summary = $this->build_items_summary( $order, $options, true );

		$titles = wp_list_pluck( $order['items'], 'title' );
		$title  = 'Заказ с сайта: ' . implode( ', ', array_slice( $titles, 0, 3 ) );
		if ( count( $titles ) > 3 ) {
			$title .= sprintf( ' и ещё %d', count( $titles ) - 3 );
		}

		$comments  = $summary['text'];
		if ( $summary['total'] > 0 ) {
			$comments .= "\n\nИтого: " . number_format_i18n( $summary['total'] ) . ' ' . $options['currency'];
		}
		if ( ! empty( $order['comment'] ) ) {
			$comments .= "\n\nКомментарий клиента: " . $order['comment'];
		}
		$comments .= "\n\nСтраница: " . $order['page_url'];

		$fields = array(
			'TITLE'        => $title,
			'NAME'         => $order['name'],
			'COMMENTS'     => $comments,
			'SOURCE_ID'    => 'WEB',
			'PHONE'        => array( array( 'VALUE' => $order['phone'], 'VALUE_TYPE' => 'WORK' ) ),
		);

		if ( ! empty( $order['email'] ) ) {
			$fields['EMAIL'] = array( array( 'VALUE' => $order['email'], 'VALUE_TYPE' => 'WORK' ) );
		}

		if ( 'deal' === $entity ) {
			$fields['TITLE'] = $title;
		}

		$body = array( 'FIELDS' => $fields );

		$response = wp_remote_post(
			$webhook,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['result'] ) ) {
			$message = isset( $data['error_description'] ) ? $data['error_description'] : 'Bitrix24 request failed';
			return new WP_Error( 'eop_bitrix_error', $message, $data );
		}

		return true;
	}

	private function send_to_telegram( $order, $options ) {
		$summary = $this->build_items_summary( $order, $options, false );

		$lines   = array();
		$lines[] = '🛒 <b>Новая заявка с сайта</b>';
		$lines[] = '';
		$lines[] = $summary['text'];
		if ( $summary['total'] > 0 ) {
			$lines[] = '';
			$lines[] = 'Итого: <b>' . number_format_i18n( $summary['total'] ) . ' ' . esc_html( $options['currency'] ) . '</b>';
		}
		$lines[] = '';
		$lines[] = '👤 Имя: ' . esc_html( $order['name'] );
		$lines[] = '📞 Телефон: ' . esc_html( $order['phone'] );
		if ( ! empty( $order['email'] ) ) {
			$lines[] = '✉️ Email: ' . esc_html( $order['email'] );
		}
		if ( ! empty( $order['comment'] ) ) {
			$lines[] = '💬 Комментарий: ' . esc_html( $order['comment'] );
		}
		$lines[] = '🔗 Страница: ' . esc_html( $order['page_url'] );

		$text = implode( "\n", $lines );
		$url  = 'https://api.telegram.org/bot' . $options['telegram_token'] . '/sendMessage';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'body'    => array(
					'chat_id'    => $options['telegram_chat_id'],
					'text'       => $text,
					'parse_mode' => 'HTML',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $data['ok'] ) ) {
			$message = isset( $data['description'] ) ? $data['description'] : 'Telegram request failed';
			return new WP_Error( 'eop_telegram_error', $message, $data );
		}

		return true;
	}
}
