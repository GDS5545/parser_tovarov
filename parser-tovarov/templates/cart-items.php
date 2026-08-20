<?php
/**
 * Renders the item list inside the cart drawer body. Included by
 * PTV_Ajax::get_cart() with $items (WC cart contents array) in scope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $items ) ) :
	?>
	<p class="ptv-cart-empty"><?php esc_html_e( 'Корзина пуста', 'parser-tovarov' ); ?></p>
	<?php
	return;
endif;
?>
<ul class="ptv-cart-list">
	<?php foreach ( $items as $cart_item_key => $item ) : ?>
		<?php
		$product = $item['data'];
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		?>
		<li class="ptv-cart-item" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
			<div class="ptv-cart-item-thumb"><?php echo wp_kses_post( $product->get_image( 'thumbnail' ) ); ?></div>
			<div class="ptv-cart-item-info">
				<div class="ptv-cart-item-name"><?php echo esc_html( $product->get_name() ); ?></div>
				<div class="ptv-cart-item-meta">
					<span class="ptv-cart-item-qty">
						<button type="button" class="ptv-qty-btn ptv-qty-minus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">&minus;</button>
						<span class="ptv-qty-value"><?php echo esc_html( $item['quantity'] ); ?></span>
						<button type="button" class="ptv-qty-btn ptv-qty-plus" data-key="<?php echo esc_attr( $cart_item_key ); ?>">&plus;</button>
					</span>
					<span class="ptv-cart-item-price"><?php echo wp_kses_post( wc_price( $item['line_total'] ) ); ?></span>
				</div>
			</div>
			<button type="button" class="ptv-cart-item-remove" data-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="<?php esc_attr_e( 'Удалить', 'parser-tovarov' ); ?>">&#128465;</button>
		</li>
	<?php endforeach; ?>
</ul>
