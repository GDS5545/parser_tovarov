<?php
/**
 * Universal Scraper → Attributes: lists the store's real WooCommerce
 * global attributes (pa_*), which is exactly what the normalizer
 * (Stage 7) will match extracted specifications against before creating
 * a new one (spec §5–6).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AttributesPage {

	public function render() {
		$attribute_taxonomies = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Global WooCommerce Attributes', 'universal-woo-scraper' ); ?></h1>
			<p>
				<?php esc_html_e( 'The normalizer (Stage 7) checks this list before creating a new global attribute for an extracted specification, so equivalent labels like "Voltage", "Напряжение", and "Напряжение питания" collapse onto a single pa_voltage taxonomy instead of five separate ones.', 'universal-woo-scraper' ); ?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Taxonomy', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Label', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Type', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Terms', 'universal-woo-scraper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $attribute_taxonomies ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No global attributes exist yet in this store.', 'universal-woo-scraper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $attribute_taxonomies as $attribute ) : ?>
						<?php $taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name ); ?>
						<tr>
							<td><code><?php echo esc_html( $taxonomy ); ?></code></td>
							<td><?php echo esc_html( $attribute->attribute_label ); ?></td>
							<td><?php echo esc_html( $attribute->attribute_type ); ?></td>
							<td><?php echo esc_html( wp_count_terms( array( 'taxonomy' => $taxonomy ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
