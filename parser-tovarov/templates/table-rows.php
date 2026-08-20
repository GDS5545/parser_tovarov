<?php
/**
 * Renders <tr> rows for a set of products. Included both by templates/catalog.php
 * (first page, server-rendered) and by PTV_Ajax::get_table() (subsequent pages).
 *
 * Expects in scope: array $products (WC_Product[]), array $columns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $products ) ) :
	?>
	<tr class="ptv-empty-row">
		<td colspan="<?php echo esc_attr( count( $columns ) + 1 ); ?>">
			<?php esc_html_e( 'По заданным фильтрам ничего не найдено.', 'parser-tovarov' ); ?>
		</td>
	</tr>
	<?php
	return;
endif;

foreach ( $products as $product ) :
	if ( ! $product instanceof WC_Product ) {
		continue;
	}
	$purchasable = $product->is_purchasable() && $product->is_in_stock();
	?>
	<tr class="ptv-row" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
		<?php foreach ( $columns as $column ) : ?>
			<td class="ptv-col ptv-col-<?php echo esc_attr( sanitize_html_class( $column ) ); ?>">
				<?php echo wp_kses_post( PTV_Helpers::column_cell( $product, $column ) ); ?>
			</td>
		<?php endforeach; ?>
		<td class="ptv-col ptv-col-action">
			<?php if ( $purchasable ) : ?>
				<button type="button" class="ptv-btn ptv-btn-buy" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
					<?php esc_html_e( 'Купить', 'parser-tovarov' ); ?>
				</button>
			<?php else : ?>
				<span class="ptv-out-of-stock"><?php esc_html_e( 'Нет в наличии', 'parser-tovarov' ); ?></span>
			<?php endif; ?>
		</td>
	</tr>
	<?php
endforeach;
