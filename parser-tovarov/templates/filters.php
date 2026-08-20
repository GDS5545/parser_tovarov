<?php
/**
 * Filter bar: one dropdown per configured attribute (search + checkbox list,
 * like the reference site), a quick-filter chip row, and a free-text search
 * box. Everything needed to filter is rendered here (terms are pre-fetched
 * and cached server-side) so opening a dropdown needs no extra request.
 *
 * Expects in scope: array $config.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ptv-filters" data-instance="<?php echo esc_attr( $config['instance_id'] ); ?>">
	<div class="ptv-filters-head">
		<span class="ptv-filters-icon" aria-hidden="true">&#9660;</span>
		<span><?php esc_html_e( 'Фильтр продукции', 'parser-tovarov' ); ?></span>
	</div>

	<div class="ptv-filters-row">
		<?php foreach ( $config['attributes'] as $taxonomy ) : ?>
			<?php $terms = PTV_Query::get_attribute_terms( $config['categories'], $taxonomy ); ?>
			<div class="ptv-filter-dropdown" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
				<button type="button" class="ptv-filter-toggle">
					<span class="ptv-filter-label"><?php echo esc_html( PTV_Helpers::attribute_label( $taxonomy ) ); ?></span>
					<span class="ptv-filter-caret" aria-hidden="true">&#9662;</span>
				</button>
				<div class="ptv-filter-panel">
					<input type="text" class="ptv-filter-search" placeholder="<?php esc_attr_e( 'Поиск…', 'parser-tovarov' ); ?>">
					<div class="ptv-filter-options">
						<?php foreach ( $terms as $term ) : ?>
							<label class="ptv-filter-option">
								<input type="checkbox" value="<?php echo esc_attr( $term['slug'] ); ?>">
								<span><?php echo esc_html( $term['name'] ); ?></span>
								<em><?php echo esc_html( $term['count'] ); ?></em>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="ptv-filter-search-global">
			<input type="text" class="ptv-search-input" placeholder="<?php esc_attr_e( 'Поиск по наименованию…', 'parser-tovarov' ); ?>">
		</div>
	</div>

	<div class="ptv-active-filters" hidden></div>

	<?php if ( $config['quick_filter'] && $config['quick_filter_limit'] > 0 ) : ?>
		<?php $quick_terms = PTV_Query::get_quick_filter_terms( $config['categories'], $config['quick_filter'], $config['quick_filter_limit'] ); ?>
		<?php if ( ! empty( $quick_terms ) ) : ?>
			<div class="ptv-quick-filters" data-taxonomy="<?php echo esc_attr( $config['quick_filter'] ); ?>">
				<?php foreach ( $quick_terms as $term ) : ?>
					<button type="button" class="ptv-chip" data-value="<?php echo esc_attr( $term['slug'] ); ?>">
						<?php echo esc_html( $term['name'] ); ?>
					</button>
				<?php endforeach; ?>
				<button type="button" class="ptv-chip ptv-chip-reset"><?php esc_html_e( 'Сбросить фильтр', 'parser-tovarov' ); ?></button>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
