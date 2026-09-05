<?php
/**
 * Anthropic Messages API client (api.anthropic.com/v1/messages).
 *
 * @package Uws\Ai
 */

namespace Uws\Ai;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AnthropicClient implements AiClientInterface {

	const DEFAULT_MODEL   = 'claude-3-5-haiku-20241022';
	const API_URL         = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2023-06-01';
	const MAX_OUTPUT_TOKENS = 2000;

	/** @var string */
	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	public function complete( $system_prompt, $user_content ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'uws_ai_no_key', __( 'No Anthropic API key configured.', 'universal-woo-scraper' ) );
		}

		/** @param string $model */
		$model = apply_filters( 'uws_ai_anthropic_model', self::DEFAULT_MODEL );

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => self::MAX_OUTPUT_TOKENS,
						'system'     => $system_prompt,
						'messages'   => array(
							array( 'role' => 'user', 'content' => $user_content ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'uws_ai_unreachable', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Unknown Anthropic API error.', 'universal-woo-scraper' );
			return new WP_Error( 'uws_ai_error', $message, array( 'status' => $status ) );
		}

		$text = '';
		if ( is_array( $body ) && ! empty( $body['content'] ) && is_array( $body['content'] ) ) {
			foreach ( $body['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return $text;
	}
}
