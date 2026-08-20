<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'ptv_quick_orders';

$per_page = 20;
$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$offset   = ( $paged - 1 ) * $per_page;

$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$status_labels = array(
	'new'   => __( 'Новая', 'parser-tovarov' ),
	'sent'  => __( 'Отправлена в Bitrix24', 'parser-tovarov' ),
	'error' => __( 'Ошибка отправки', 'parser-tovarov' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Заявки на быстрый заказ', 'parser-tovarov' ); ?></h1>

	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'Заявок пока нет.', 'parser-tovarov' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Дата', 'parser-tovarov' ); ?></th>
					<th><?php esc_html_e( 'Имя', 'parser-tovarov' ); ?></th>
					<th><?php esc_html_e( 'Телефон', 'parser-tovarov' ); ?></th>
					<th><?php esc_html_e( 'Сумма', 'parser-tovarov' ); ?></th>
					<th><?php esc_html_e( 'Товары', 'parser-tovarov' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'parser-tovarov' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $items = json_decode( $row->cart_json, true ); ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $row->created_at ) ); ?></td>
						<td><?php echo esc_html( $row->customer_name ); ?></td>
						<td><?php echo esc_html( $row->customer_phone ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $row->total_amount ) ); ?></td>
						<td>
							<?php if ( is_array( $items ) ) : ?>
								<ul style="margin:0;">
									<?php foreach ( $items as $item ) : ?>
										<li><?php echo esc_html( $item['name'] . ' × ' . $item['quantity'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( $row->comment ) : ?>
								<p style="color:#646970;margin:4px 0 0;"><?php echo esc_html( $row->comment ); ?></p>
							<?php endif; ?>
							<?php if ( $row->attachment_url ) : ?>
								<p style="margin:4px 0 0;"><a href="<?php echo esc_url( $row->attachment_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Вложение', 'parser-tovarov' ); ?></a></p>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$label = isset( $status_labels[ $row->status ] ) ? $status_labels[ $row->status ] : $row->status;
							echo esc_html( $label );
							if ( 'error' === $row->status && $row->error_message ) {
								echo '<br><span style="color:#b71c1c;font-size:12px;">' . esc_html( $row->error_message ) . '</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages > 1 ) :
			?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
