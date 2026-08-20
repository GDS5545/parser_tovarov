<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = PTV_Settings::instance();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Каталог товаров — настройки', 'parser-tovarov' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'ptv_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'Bitrix24', 'parser-tovarov' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ptv_bitrix_webhook_url"><?php esc_html_e( 'Webhook URL', 'parser-tovarov' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="ptv_bitrix_webhook_url" name="ptv_bitrix_webhook_url"
						value="<?php echo esc_attr( $settings->get( 'bitrix_webhook_url' ) ); ?>"
						placeholder="https://your-domain.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/">
					<p class="description">
						<?php esc_html_e( 'Bitrix24 → Разработчикам → Другое → Входящий вебхук (права: crm).', 'parser-tovarov' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ptv_bitrix_entity"><?php esc_html_e( 'Создавать в CRM', 'parser-tovarov' ); ?></label></th>
				<td>
					<select id="ptv_bitrix_entity" name="ptv_bitrix_entity">
						<option value="lead" <?php selected( $settings->get( 'bitrix_entity' ), 'lead' ); ?>><?php esc_html_e( 'Лид', 'parser-tovarov' ); ?></option>
						<option value="deal" <?php selected( $settings->get( 'bitrix_entity' ), 'deal' ); ?>><?php esc_html_e( 'Сделку', 'parser-tovarov' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ptv_bitrix_responsible"><?php esc_html_e( 'ID ответственного', 'parser-tovarov' ); ?></label></th>
				<td>
					<input type="number" min="0" id="ptv_bitrix_responsible" name="ptv_bitrix_responsible"
						value="<?php echo esc_attr( $settings->get( 'bitrix_responsible' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Необязательно. ID сотрудника Bitrix24, на которого назначать заявки.', 'parser-tovarov' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Каталог и корзина', 'parser-tovarov' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ptv_per_page"><?php esc_html_e( 'Товаров на странице', 'parser-tovarov' ); ?></label></th>
				<td><input type="number" min="1" max="100" id="ptv_per_page" name="ptv_per_page" value="<?php echo esc_attr( $settings->get( 'per_page' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ptv_min_order_amount"><?php esc_html_e( 'Минимальная сумма заказа', 'parser-tovarov' ); ?></label></th>
				<td>
					<input type="number" min="0" step="0.01" id="ptv_min_order_amount" name="ptv_min_order_amount" value="<?php echo esc_attr( $settings->get( 'min_order_amount' ) ); ?>">
					<p class="description"><?php esc_html_e( '0 — без ограничения. Ниже этой суммы кнопка «Оформить заказ» заблокирована.', 'parser-tovarov' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ptv_cache_minutes"><?php esc_html_e( 'Кэш фильтров, минут', 'parser-tovarov' ); ?></label></th>
				<td>
					<input type="number" min="1" id="ptv_cache_minutes" name="ptv_cache_minutes" value="<?php echo esc_attr( $settings->get( 'cache_minutes' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Списки значений фильтров кэшируются, чтобы таблица не нагружала сайт. Кэш сбрасывается автоматически при изменении товаров.', 'parser-tovarov' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ptv_accent_color"><?php esc_html_e( 'Акцентный цвет', 'parser-tovarov' ); ?></label></th>
				<td><input type="text" class="ptv-color-field" id="ptv_accent_color" name="ptv_accent_color" value="<?php echo esc_attr( $settings->get( 'accent_color' ) ); ?>"></td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr>

	<h2><?php esc_html_e( 'Как вывести каталог', 'parser-tovarov' ); ?></h2>
	<p><?php esc_html_e( 'Вставьте шорткод на любую страницу или в виджет:', 'parser-tovarov' ); ?></p>
	<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto;">[ptv_catalog categories="truby-nerzhaveyuschie,krug-nerzhaveyuschij" attributes="marka,diametr,tolschina-stenki,gost" quick_filter="marka" title="Труба нержавеющая"]</pre>
	<p><?php echo wp_kses_post( __( 'Подробное описание всех параметров смотрите на вкладке «Как пользоваться».', 'parser-tovarov' ) ); ?></p>
</div>
