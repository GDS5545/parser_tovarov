<?php
/**
 * Registers the single 'uws_settings' option with the Settings API and
 * defines every field, split across three admin pages (Settings, AI
 * Settings, Browser Settings) that all write into the same option array.
 *
 * @package Uws\Admin
 */

namespace Uws\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsRegistrar {

	const OPTION       = 'uws_settings';
	const OPTION_GROUP = 'uws_settings_group';

	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		$this->register_general_section();
		$this->register_browser_section();
		$this->register_ai_section();
	}

	private function register_general_section() {
		add_settings_section( 'uws_general', __( 'Import defaults', 'universal-woo-scraper' ), '__return_false', 'uws-settings' );

		add_settings_field(
			'default_import_status',
			__( 'Default import status', 'universal-woo-scraper' ),
			function () {
				$value = $this->get( 'default_import_status', 'draft' );
				?>
				<select name="uws_settings[default_import_status]">
					<option value="draft" <?php selected( $value, 'draft' ); ?>><?php esc_html_e( 'Draft', 'universal-woo-scraper' ); ?></option>
					<option value="pending" <?php selected( $value, 'pending' ); ?>><?php esc_html_e( 'Pending review', 'universal-woo-scraper' ); ?></option>
					<option value="publish" <?php selected( $value, 'publish' ); ?>><?php esc_html_e( 'Published', 'universal-woo-scraper' ); ?></option>
				</select>
				<?php
			},
			'uws-settings',
			'uws_general'
		);

		add_settings_field(
			'normalization_mode',
			__( 'Attribute value normalization', 'universal-woo-scraper' ),
			function () {
				$value = $this->get( 'normalization_mode', 'smart' );
				?>
				<label><input type="radio" name="uws_settings[normalization_mode]" value="smart" <?php checked( $value, 'smart' ); ?> /> <?php esc_html_e( 'Smart normalization', 'universal-woo-scraper' ); ?></label><br />
				<label><input type="radio" name="uws_settings[normalization_mode]" value="strict" <?php checked( $value, 'strict' ); ?> /> <?php esc_html_e( 'Strict mode', 'universal-woo-scraper' ); ?></label>
				<?php
			},
			'uws-settings',
			'uws_general'
		);

		add_settings_field(
			'protect_manual_edits',
			__( 'Protect manual edits on sync', 'universal-woo-scraper' ),
			function () {
				?>
				<label><input type="checkbox" name="uws_settings[protect_manual_edits]" value="1" <?php checked( $this->get( 'protect_manual_edits', true ) ); ?> /> <?php esc_html_e( 'Do not overwrite fields a human edited manually in WooCommerce', 'universal-woo-scraper' ); ?></label>
				<?php
			},
			'uws-settings',
			'uws_general'
		);

		add_settings_field(
			'debug_mode',
			__( 'Debug mode', 'universal-woo-scraper' ),
			function () {
				?>
				<label><input type="checkbox" name="uws_settings[debug_mode]" value="1" <?php checked( $this->get( 'debug_mode', false ) ); ?> /> <?php esc_html_e( 'Store HTML snapshots, screenshots, and console/network errors for each analyzed page', 'universal-woo-scraper' ); ?></label>
				<?php
			},
			'uws-settings',
			'uws_general'
		);
	}

	private function register_browser_section() {
		add_settings_section( 'uws_browser', __( 'Scraper worker connection', 'universal-woo-scraper' ), '__return_false', 'uws-browser-settings' );

		add_settings_field(
			'worker_url',
			__( 'Worker URL', 'universal-woo-scraper' ),
			function () {
				printf(
					'<input type="url" class="regular-text" name="uws_settings[worker_url]" value="%s" placeholder="https://scraper-worker.example.com" />',
					esc_attr( $this->get( 'worker_url', '' ) )
				);
				echo '<p class="description">' . esc_html__( 'Base URL of the Node.js/Playwright worker (Stage 3). Left empty, Analyze/Import report that no worker is configured.', 'universal-woo-scraper' ) . '</p>';
			},
			'uws-browser-settings',
			'uws_browser'
		);

		add_settings_field(
			'worker_api_key',
			__( 'Worker API key', 'universal-woo-scraper' ),
			function () {
				printf(
					'<input type="password" class="regular-text" name="uws_settings[worker_api_key]" value="%s" autocomplete="off" />',
					esc_attr( $this->get( 'worker_api_key', '' ) )
				);
			},
			'uws-browser-settings',
			'uws_browser'
		);

		add_settings_field(
			'delay_between_requests',
			__( 'Delay between requests (seconds)', 'universal-woo-scraper' ),
			function () {
				printf(
					'<input type="number" step="0.1" min="0" name="uws_settings[delay_between_requests]" value="%s" />',
					esc_attr( $this->get( 'delay_between_requests', 1.0 ) )
				);
			},
			'uws-browser-settings',
			'uws_browser'
		);

		add_settings_field(
			'max_parallel_workers',
			__( 'Max parallel jobs', 'universal-woo-scraper' ),
			function () {
				printf(
					'<input type="number" step="1" min="1" max="10" name="uws_settings[max_parallel_workers]" value="%s" />',
					esc_attr( $this->get( 'max_parallel_workers', 1 ) )
				);
			},
			'uws-browser-settings',
			'uws_browser'
		);

		add_settings_field(
			'image_policy',
			__( 'Image import policy', 'universal-woo-scraper' ),
			function () {
				$value = $this->get( 'image_policy', 'download' );
				?>
				<select name="uws_settings[image_policy]">
					<option value="download" <?php selected( $value, 'download' ); ?>><?php esc_html_e( 'Download all images', 'universal-woo-scraper' ); ?></option>
					<option value="main_only" <?php selected( $value, 'main_only' ); ?>><?php esc_html_e( 'Download main image only', 'universal-woo-scraper' ); ?></option>
					<option value="remote" <?php selected( $value, 'remote' ); ?>><?php esc_html_e( 'Use remote image URLs', 'universal-woo-scraper' ); ?></option>
				</select>
				<?php
			},
			'uws-browser-settings',
			'uws_browser'
		);
	}

	private function register_ai_section() {
		add_settings_section( 'uws_ai', __( 'AI fallback extraction', 'universal-woo-scraper' ), '__return_false', 'uws-ai-settings' );

		add_settings_field(
			'ai_provider',
			__( 'Provider', 'universal-woo-scraper' ),
			function () {
				$value = $this->get( 'ai_provider', 'none' );
				?>
				<select name="uws_settings[ai_provider]">
					<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'Disabled', 'universal-woo-scraper' ); ?></option>
					<option value="anthropic" <?php selected( $value, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic', 'universal-woo-scraper' ); ?></option>
					<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'universal-woo-scraper' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Used only for fields the conventional extractors (JSON-LD, DOM, specification tables) could not confidently determine.', 'universal-woo-scraper' ); ?></p>
				<?php
			},
			'uws-ai-settings',
			'uws_ai'
		);

		add_settings_field(
			'ai_api_key',
			__( 'API key', 'universal-woo-scraper' ),
			function () {
				printf(
					'<input type="password" class="regular-text" name="uws_settings[ai_api_key]" value="%s" autocomplete="off" />',
					esc_attr( $this->get( 'ai_api_key', '' ) )
				);
			},
			'uws-ai-settings',
			'uws_ai'
		);
	}

	/**
	 * @param mixed $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION, array() );
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Sanitizes the whole settings array on save. Unknown keys are dropped;
	 * known keys are cast/escaped per field type.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$existing = get_option( self::OPTION, array() );
		$input    = is_array( $input ) ? $input : array();

		$clean = $existing;

		if ( isset( $input['worker_url'] ) ) {
			$clean['worker_url'] = esc_url_raw( $input['worker_url'] );
		}
		if ( isset( $input['worker_api_key'] ) ) {
			$clean['worker_api_key'] = sanitize_text_field( $input['worker_api_key'] );
		}
		if ( isset( $input['default_import_status'] ) ) {
			$clean['default_import_status'] = in_array( $input['default_import_status'], array( 'draft', 'pending', 'publish' ), true )
				? $input['default_import_status']
				: 'draft';
		}
		if ( isset( $input['normalization_mode'] ) ) {
			$clean['normalization_mode'] = 'strict' === $input['normalization_mode'] ? 'strict' : 'smart';
		}
		$clean['protect_manual_edits'] = ! empty( $input['protect_manual_edits'] );
		$clean['debug_mode']           = ! empty( $input['debug_mode'] );

		if ( isset( $input['delay_between_requests'] ) ) {
			$clean['delay_between_requests'] = max( 0, (float) $input['delay_between_requests'] );
		}
		if ( isset( $input['max_parallel_workers'] ) ) {
			$clean['max_parallel_workers'] = max( 1, min( 10, (int) $input['max_parallel_workers'] ) );
		}
		if ( isset( $input['image_policy'] ) ) {
			$clean['image_policy'] = in_array( $input['image_policy'], array( 'download', 'main_only', 'remote' ), true )
				? $input['image_policy']
				: 'download';
		}
		if ( isset( $input['ai_provider'] ) ) {
			$clean['ai_provider'] = in_array( $input['ai_provider'], array( 'none', 'anthropic', 'openai' ), true )
				? $input['ai_provider']
				: 'none';
		}
		if ( isset( $input['ai_api_key'] ) ) {
			$clean['ai_api_key'] = sanitize_text_field( $input['ai_api_key'] );
		}

		return $clean;
	}
}
