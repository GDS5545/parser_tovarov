<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings screen: Settings -> Order Popup.
 */
class EOP_Settings {

	const OPTION_KEY = 'eop_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function get_defaults() {
		return array(
			'trigger_keywords'   => 'Заказать, Купить, Order, Buy Now, В корзину, Оформить заказ',
			'trigger_selector'   => '.eop-order-btn',
			'currency'           => '₸',
			'popup_title'        => 'Оформление заказа',
			'submit_button_text' => 'Отправить заявку',
			'success_message'    => 'Спасибо! Ваша заявка принята, мы скоро свяжемся с вами.',
			'error_message'      => 'Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.',
			'require_email'      => '0',
			'bitrix_enabled'     => '0',
			'bitrix_webhook'     => '',
			'bitrix_entity'      => 'lead',
			'telegram_enabled'   => '0',
			'telegram_token'     => '',
			'telegram_chat_id'   => '',
		);
	}

	public static function get_options() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::get_defaults() );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Order Popup', 'elementor-order-popup' ),
			__( 'Order Popup', 'elementor-order-popup' ),
			'manage_options',
			'eop-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'eop_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$defaults = self::get_defaults();
		$output   = array();

		$output['trigger_keywords']   = isset( $input['trigger_keywords'] ) ? sanitize_text_field( $input['trigger_keywords'] ) : $defaults['trigger_keywords'];
		$output['trigger_selector']   = isset( $input['trigger_selector'] ) ? sanitize_text_field( $input['trigger_selector'] ) : $defaults['trigger_selector'];
		$output['currency']           = isset( $input['currency'] ) ? sanitize_text_field( $input['currency'] ) : $defaults['currency'];
		$output['popup_title']        = isset( $input['popup_title'] ) ? sanitize_text_field( $input['popup_title'] ) : $defaults['popup_title'];
		$output['submit_button_text'] = isset( $input['submit_button_text'] ) ? sanitize_text_field( $input['submit_button_text'] ) : $defaults['submit_button_text'];
		$output['success_message']    = isset( $input['success_message'] ) ? sanitize_textarea_field( $input['success_message'] ) : $defaults['success_message'];
		$output['error_message']      = isset( $input['error_message'] ) ? sanitize_textarea_field( $input['error_message'] ) : $defaults['error_message'];
		$output['require_email']      = ! empty( $input['require_email'] ) ? '1' : '0';

		$output['bitrix_enabled'] = ! empty( $input['bitrix_enabled'] ) ? '1' : '0';
		$output['bitrix_webhook'] = isset( $input['bitrix_webhook'] ) ? esc_url_raw( trim( $input['bitrix_webhook'] ) ) : '';
		$output['bitrix_entity']  = ( isset( $input['bitrix_entity'] ) && 'deal' === $input['bitrix_entity'] ) ? 'deal' : 'lead';

		$output['telegram_enabled'] = ! empty( $input['telegram_enabled'] ) ? '1' : '0';
		$output['telegram_token']   = isset( $input['telegram_token'] ) ? sanitize_text_field( trim( $input['telegram_token'] ) ) : '';
		$output['telegram_chat_id'] = isset( $input['telegram_chat_id'] ) ? sanitize_text_field( trim( $input['telegram_chat_id'] ) ) : '';

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$o = self::get_options();
		?>
		<div class="wrap eop-settings-wrap">
			<h1><?php esc_html_e( 'Elementor Order Popup', 'elementor-order-popup' ); ?></h1>
			<p>
				<?php esc_html_e( 'Добавьте CSS-класс "eop-order-btn" любой кнопке Elementor (вкладка Advanced -> CSS Classes), чтобы клик по ней открывал попап заказа с этим товаром. Дополнительно кнопки распознаются по тексту из списка ключевых слов ниже.', 'elementor-order-popup' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'eop_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Триггеры кнопок', 'elementor-order-popup' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eop_trigger_keywords"><?php esc_html_e( 'Ключевые слова', 'elementor-order-popup' ); ?></label></th>
						<td>
							<input type="text" id="eop_trigger_keywords" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[trigger_keywords]" value="<?php echo esc_attr( $o['trigger_keywords'] ); ?>">
							<p class="description"><?php esc_html_e( 'Текст кнопки (через запятую), при совпадении с которым откроется попап заказа. Регистр не важен.', 'elementor-order-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_trigger_selector"><?php esc_html_e( 'CSS-селектор', 'elementor-order-popup' ); ?></label></th>
						<td>
							<input type="text" id="eop_trigger_selector" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[trigger_selector]" value="<?php echo esc_attr( $o['trigger_selector'] ); ?>">
							<p class="description"><?php esc_html_e( 'Дополнительный CSS-класс/селектор для кнопок-триггеров (например, .eop-order-btn).', 'elementor-order-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_currency"><?php esc_html_e( 'Валюта', 'elementor-order-popup' ); ?></label></th>
						<td><input type="text" id="eop_currency" class="small-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[currency]" value="<?php echo esc_attr( $o['currency'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Текст попапа', 'elementor-order-popup' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eop_popup_title"><?php esc_html_e( 'Заголовок попапа', 'elementor-order-popup' ); ?></label></th>
						<td><input type="text" id="eop_popup_title" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[popup_title]" value="<?php echo esc_attr( $o['popup_title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_submit_button_text"><?php esc_html_e( 'Текст кнопки отправки', 'elementor-order-popup' ); ?></label></th>
						<td><input type="text" id="eop_submit_button_text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[submit_button_text]" value="<?php echo esc_attr( $o['submit_button_text'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_success_message"><?php esc_html_e( 'Сообщение об успехе', 'elementor-order-popup' ); ?></label></th>
						<td><textarea id="eop_success_message" class="large-text" rows="2" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[success_message]"><?php echo esc_textarea( $o['success_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_error_message"><?php esc_html_e( 'Сообщение об ошибке', 'elementor-order-popup' ); ?></label></th>
						<td><textarea id="eop_error_message" class="large-text" rows="2" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[error_message]"><?php echo esc_textarea( $o['error_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email в форме', 'elementor-order-popup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_email]" value="1" <?php checked( $o['require_email'], '1' ); ?>>
								<?php esc_html_e( 'Сделать поле Email обязательным', 'elementor-order-popup' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Bitrix24 CRM', 'elementor-order-popup' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Включить', 'elementor-order-popup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bitrix_enabled]" value="1" <?php checked( $o['bitrix_enabled'], '1' ); ?>>
								<?php esc_html_e( 'Отправлять заявки в Bitrix24', 'elementor-order-popup' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_bitrix_webhook"><?php esc_html_e( 'Webhook URL', 'elementor-order-popup' ); ?></label></th>
						<td>
							<input type="url" id="eop_bitrix_webhook" class="regular-text" placeholder="https://your-domain.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bitrix_webhook]" value="<?php echo esc_attr( $o['bitrix_webhook'] ); ?>">
							<p class="description"><?php esc_html_e( 'Входящий вебхук Bitrix24 с правами crm (создаётся в Bitrix24: Разработчикам -> Другое -> Входящий вебхук).', 'elementor-order-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_bitrix_entity"><?php esc_html_e( 'Создавать', 'elementor-order-popup' ); ?></label></th>
						<td>
							<select id="eop_bitrix_entity" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bitrix_entity]">
								<option value="lead" <?php selected( $o['bitrix_entity'], 'lead' ); ?>><?php esc_html_e( 'Лид (Lead)', 'elementor-order-popup' ); ?></option>
								<option value="deal" <?php selected( $o['bitrix_entity'], 'deal' ); ?>><?php esc_html_e( 'Сделку (Deal)', 'elementor-order-popup' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Telegram', 'elementor-order-popup' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Включить', 'elementor-order-popup' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[telegram_enabled]" value="1" <?php checked( $o['telegram_enabled'], '1' ); ?>>
								<?php esc_html_e( 'Отправлять заявки в Telegram', 'elementor-order-popup' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_telegram_token"><?php esc_html_e( 'Bot Token', 'elementor-order-popup' ); ?></label></th>
						<td><input type="text" id="eop_telegram_token" class="regular-text" placeholder="123456789:AAExampleTokenFromBotFather" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[telegram_token]" value="<?php echo esc_attr( $o['telegram_token'] ); ?>">
							<p class="description"><?php esc_html_e( 'Токен бота, полученный у @BotFather.', 'elementor-order-popup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eop_telegram_chat_id"><?php esc_html_e( 'Chat ID', 'elementor-order-popup' ); ?></label></th>
						<td><input type="text" id="eop_telegram_chat_id" class="regular-text" placeholder="-1001234567890" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[telegram_chat_id]" value="<?php echo esc_attr( $o['telegram_chat_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'ID чата или канала, куда бот отправит заявку (бот должен быть добавлен туда).', 'elementor-order-popup' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
