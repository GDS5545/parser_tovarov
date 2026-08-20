<?php
/**
 * Quick-order modal opened from the cart drawer's "Оформить заказ" button.
 * Submitting posts name/phone/comment/attachment to PTV_Ajax::submit_order(),
 * which logs the request and forwards it to Bitrix24.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$privacy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
?>
<div id="ptv-order-overlay" class="ptv-overlay" hidden></div>

<div id="ptv-order-modal" class="ptv-modal" aria-hidden="true">
	<div class="ptv-modal-inner">
		<button type="button" class="ptv-modal-close" id="ptv-order-close" aria-label="<?php esc_attr_e( 'Закрыть', 'parser-tovarov' ); ?>">&times;</button>

		<h3><?php esc_html_e( 'Оформите быстрый заказ', 'parser-tovarov' ); ?></h3>
		<p class="ptv-modal-subtitle"><?php esc_html_e( 'Наш менеджер подготовит коммерческое предложение и свяжется с вами.', 'parser-tovarov' ); ?></p>

		<form id="ptv-order-form" novalidate>
			<div class="ptv-form-row">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Имя', 'parser-tovarov' ); ?></span>
					<input type="text" name="name" id="ptv-order-name" placeholder="<?php esc_attr_e( 'Имя *', 'parser-tovarov' ); ?>" required>
				</label>
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Телефон', 'parser-tovarov' ); ?></span>
					<input type="tel" name="phone" id="ptv-order-phone" placeholder="<?php esc_attr_e( 'Телефон *', 'parser-tovarov' ); ?>" required>
				</label>
			</div>

			<label class="ptv-form-full">
				<span class="screen-reader-text"><?php esc_html_e( 'Комментарий', 'parser-tovarov' ); ?></span>
				<textarea name="comment" id="ptv-order-comment" placeholder="<?php esc_attr_e( 'Комментарий', 'parser-tovarov' ); ?>" rows="3"></textarea>
			</label>

			<label class="ptv-file-label">
				<span class="ptv-file-icon" aria-hidden="true">&#128206;</span>
				<span id="ptv-file-name"><?php esc_html_e( 'Прикрепить документ', 'parser-tovarov' ); ?></span>
				<input type="file" name="attachment" id="ptv-order-file">
			</label>

			<label class="ptv-consent">
				<input type="checkbox" name="consent" id="ptv-order-consent" required>
				<span>
					<?php
					if ( $privacy_url ) {
						printf(
							/* translators: %s: link to the site privacy policy */
							wp_kses_post( __( 'Я даю согласие на обработку моих персональных данных в соответствии с %s', 'parser-tovarov' ) ),
							'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'политикой обработки персональных данных', 'parser-tovarov' ) . '</a>'
						);
					} else {
						esc_html_e( 'Я даю согласие на обработку моих персональных данных', 'parser-tovarov' );
					}
					?>
				</span>
			</label>

			<div class="ptv-form-message" id="ptv-order-message" hidden></div>

			<button type="submit" class="ptv-btn ptv-btn-primary ptv-btn-submit" id="ptv-order-submit">
				<?php esc_html_e( 'Оформить', 'parser-tovarov' ); ?>
			</button>
		</form>
	</div>
</div>
