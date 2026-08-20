<?php
/**
 * Top-level markup for one [ptv_catalog] instance: title, filters, table
 * (first page rendered server-side), pagination and the JSON config block
 * that ptv-catalog.js reads to drive AJAX filtering/paging.
 *
 * Expects in scope: array $config, array $result (from PTV_Query::query()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$products   = $result['products'];
$columns    = $config['columns'];
$total      = $result['total'];
$max_pages  = max( 1, $result['max_pages'] );
?>
<div class="ptv-catalog" id="<?php echo esc_attr( $config['instance_id'] ); ?>">
	<?php if ( $config['title'] ) : ?>
		<h3 class="ptv-catalog-title"><?php echo esc_html( $config['title'] ); ?></h3>
	<?php endif; ?>

	<?php if ( ! empty( $config['attributes'] ) || $config['quick_filter'] ) : ?>
		<?php include PTV_PATH . 'templates/filters.php'; ?>
	<?php endif; ?>

	<div class="ptv-table-wrap">
		<div class="ptv-loading" hidden>
			<span class="ptv-spinner" aria-hidden="true"></span>
		</div>

		<table class="ptv-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $column ) : ?>
						<th class="ptv-th ptv-th-<?php echo esc_attr( sanitize_html_class( $column ) ); ?>" data-sort="<?php echo esc_attr( $column ); ?>">
							<?php echo esc_html( PTV_Helpers::column_label( $column ) ); ?>
							<span class="ptv-sort-arrow" aria-hidden="true"></span>
						</th>
					<?php endforeach; ?>
					<th class="ptv-th ptv-th-action"><?php esc_html_e( 'Действие', 'parser-tovarov' ); ?></th>
				</tr>
			</thead>
			<tbody class="ptv-tbody">
				<?php include PTV_PATH . 'templates/table-rows.php'; ?>
			</tbody>
		</table>
	</div>

	<div class="ptv-footer">
		<div class="ptv-total-count">
			<?php
			printf(
				/* translators: %s: number of products found */
				esc_html__( 'Найдено: %s', 'parser-tovarov' ),
				'<span class="ptv-total-number">' . esc_html( $total ) . '</span>'
			);
			?>
		</div>
		<div class="ptv-pagination" data-page="1" data-max-pages="<?php echo esc_attr( $max_pages ); ?>"></div>
	</div>

	<script type="application/json" class="ptv-config">
		<?php
		echo wp_json_encode(
			array(
				'instanceId' => $config['instance_id'],
				'categories' => $config['categories'],
				'columns'    => $config['columns'],
				'orderby'    => $config['orderby'],
				'order'      => $config['order'],
				'perPage'    => $config['per_page'],
				'page'       => 1,
				'maxPages'   => $max_pages,
			)
		);
		?>
	</script>
</div>
