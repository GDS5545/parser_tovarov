<?php
/**
 * Printed once per page (via wp_footer) regardless of how many [ptv_catalog]
 * tables are present. A floating button shows the current WooCommerce cart
 * count and opens the drawer; the drawer body is filled by ptv-catalog.js
 * via the ptv_get_cart AJAX action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings    = PTV_Settings::instance();
$min_amount  = (float) $settings->get( 'min_order_amount' );
$cart_count  = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
?>
<button type="button" id="ptv-cart-toggle" class="ptv-cart-toggle" aria-label="<?php esc_attr_e( 'Корзина', 'parser-tovarov' ); ?>">
	<span class="ptv-cart-icon" aria-hidden="true">&#128722;</span>
	<span class="ptv-cart-count" id="ptv-cart-count"><?php echo esc_html( $cart_count ); ?></span>
</button>

<div id="ptv-cart-overlay" class="ptv-overlay" hidden></div>

<aside id="ptv-cart-drawer" class="ptv-drawer" aria-hidden="true">
	<div class="ptv-drawer-header">
		<h3><?php esc_html_e( 'Корзина', 'parser-tovarov' ); ?></h3>
		<button type="button" class="ptv-drawer-close" id="ptv-cart-close" aria-label="<?php esc_attr_e( 'Закрыть', 'parser-tovarov' ); ?>">&times;</button>
	</div>

	<div class="ptv-drawer-meta">
		<span id="ptv-cart-items-label"></span>
		<button type="button" id="ptv-cart-clear" class="ptv-link-btn"><?php esc_html_e( 'Очистить список', 'parser-tovarov' ); ?></button>
	</div>

	<div class="ptv-drawer-body" id="ptv-cart-body">
		<p class="ptv-cart-empty"><?php esc_html_e( 'Корзина пуста', 'parser-tovarov' ); ?></p>
	</div>

	<div class="ptv-drawer-footer">
		<?php if ( $min_amount > 0 ) : ?>
			<p class="ptv-min-order">
				<?php
				printf(
					/* translators: %s: formatted minimum order amount */
					esc_html__( 'Минимальная сумма заказа %s', 'parser-tovarov' ),
					wp_kses_post( wc_price( $min_amount ) )
				);
				?>
			</p>
		<?php endif; ?>
		<div class="ptv-drawer-actions">
			<button type="button" id="ptv-cart-checkout" class="ptv-btn ptv-btn-primary" data-min-amount="<?php echo esc_attr( $min_amount ); ?>">
				<?php esc_html_e( 'Оформить заказ', 'parser-tovarov' ); ?>
			</button>
			<button type="button" id="ptv-cart-continue" class="ptv-btn ptv-btn-outline">
				<?php esc_html_e( 'Продолжить выбор на сайте', 'parser-tovarov' ); ?>
			</button>
		</div>
	</div>
</aside>
