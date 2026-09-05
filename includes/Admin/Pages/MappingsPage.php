<?php
/**
 * Universal Scraper → Mappings: source category/attribute → WooCommerce
 * mapping table (spec §10, §46). Full editing UI (create/merge/skip,
 * "apply to all future products") is built in Stage 10 alongside the
 * category importer that needs it; this page already lists whatever rows
 * exist in wp_uws_mappings so the data model can be exercised by tests.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MappingsPage {

	public function render() {
		global $wpdb;
		$table    = Tables::mappings();
		$mappings = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Category & Attribute Mappings', 'universal-woo-scraper' ); ?></h1>
			<p><?php esc_html_e( 'Mappings are created automatically the first time an unmapped source category or attribute is seen during import (Stage 10), and can be edited here.', 'universal-woo-scraper' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Scope', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Source', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'WooCommerce target', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Action', 'universal-woo-scraper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $mappings ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No mappings recorded yet.', 'universal-woo-scraper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $mappings as $mapping ) : ?>
						<tr>
							<td><?php echo esc_html( $mapping->type ); ?></td>
							<td><?php echo esc_html( $mapping->scope ); ?></td>
							<td><?php echo esc_html( $mapping->source_label ?: $mapping->source_key ); ?></td>
							<td><?php echo esc_html( $mapping->target_label ?: $mapping->target_key ); ?></td>
							<td><?php echo esc_html( $mapping->action ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
