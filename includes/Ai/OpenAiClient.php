<?php
/**
 * OpenAI Chat Completions API client (api.openai.com/v1/chat/completions).
 *
 * @package Uws\Ai
 */

namespace Uws\Ai;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenAiClient implements AiClientInterface {

	const DEFAULT_MODEL     = 'gpt-4o-mini';
	const API_URL           = 'https://api.openai.com/v1/chat/completions';
	const MAX_OUTPUT_TOKENS = 2000;

	/** @var string */
	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	public function complete( $system_prompt, $user_content ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'uws_ai_no_key', __( 'No OpenAI API key configured.', 'universal-woo-scraper' ) );
		}

		/** @param string $model */
		$model = apply_filters( 'uws_ai_openai_model', self::DEFAULT_MODEL );

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'max_tokens'  => self::MAX_OUTPUT_TOKENS,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system_prompt ),
							array( 'role' => 'user', 'content' => $user_content ),
						),
						'response_format' => array( 'type' => 'json_object' ),
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
			$message = is_array( $body ) && ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Unknown OpenAI API error.', 'universal-woo-scraper' );
			return new WP_Error( 'uws_ai_error', $message, array( 'status' => $status ) );
		}

		return is_array( $body ) ? (string) ( $body['choices'][0]['message']['content'] ?? '' ) : '';
	}
}
