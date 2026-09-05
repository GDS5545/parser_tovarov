<?php
/**
 * Universal Scraper → Mappings: review/edit the source category/attribute
 * → WooCommerce mapping rows (spec §10, §46). A row is auto-created
 * (target == source, action = map) the first time CategoryResolver or
 * AttributeResolver sees a given source label — this page is where a
 * merchant renames the WooCommerce-facing label or sets a row to "skip"
 * (dropped from every future import of that label; already-imported
 * products are untouched since the mapping is only consulted going
 * forward, spec §10's "apply to all future products").
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\MappingRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MappingsPage {

	const SAVE_ACTION = 'uws_save_mappings';

	public function render() {
		$repository = new MappingRepository();

		$this->maybe_handle_save( $repository );

		$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mappings = $repository->all( $type ?: null );
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Category & Attribute Mappings', 'universal-woo-scraper' ); ?></h1>
			<p><?php esc_html_e( 'A row is created automatically the first time an unmapped source category or attribute is seen during import. Rename the WooCommerce-facing label or set a row to "Skip" to drop it from every future import of that domain/attribute — already-imported products are not affected.', 'universal-woo-scraper' ); ?></p>

			<p class="uws-filter-links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-mappings' ) ); ?>" class="button <?php echo $type ? '' : 'button-primary'; ?>"><?php esc_html_e( 'All', 'universal-woo-scraper' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-mappings&type=category' ) ); ?>" class="button <?php echo 'category' === $type ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Categories', 'universal-woo-scraper' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-mappings&type=attribute' ) ); ?>" class="button <?php echo 'attribute' === $type ? 'button-primary' : ''; ?>"><?php esc_html_e( 'Attributes', 'universal-woo-scraper' ); ?></a>
			</p>

			<?php if ( empty( $mappings ) ) : ?>
				<p><?php esc_html_e( 'No mappings recorded yet — they appear here after the first Analyze/Import that finds a category or attribute.', 'universal-woo-scraper' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::SAVE_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'universal-woo-scraper' ); ?></th>
								<th><?php esc_html_e( 'Scope', 'universal-woo-scraper' ); ?></th>
								<th><?php esc_html_e( 'Source label', 'universal-woo-scraper' ); ?></th>
								<th><?php esc_html_e( 'WooCommerce label', 'universal-woo-scraper' ); ?></th>
								<th><?php esc_html_e( 'Action', 'universal-woo-scraper' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $mappings as $mapping ) : ?>
								<tr>
									<td><?php echo esc_html( $mapping->type ); ?></td>
									<td><?php echo esc_html( $mapping->scope ); ?></td>
									<td><?php echo esc_html( $mapping->source_label ); ?></td>
									<td>
										<input type="text" class="regular-text" name="mappings[<?php echo esc_attr( $mapping->id ); ?>][target_label]" value="<?php echo esc_attr( $mapping->target_label ); ?>" />
									</td>
									<td>
										<select name="mappings[<?php echo esc_attr( $mapping->id ); ?>][action]">
											<option value="map" <?php selected( $mapping->action, 'map' ); ?>><?php esc_html_e( 'Map', 'universal-woo-scraper' ); ?></option>
											<option value="skip" <?php selected( $mapping->action, 'skip' ); ?>><?php esc_html_e( 'Skip', 'universal-woo-scraper' ); ?></option>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php submit_button( __( 'Save mappings', 'universal-woo-scraper' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function maybe_handle_save( MappingRepository $repository ) {
		if ( ! isset( $_POST['action'] ) || self::SAVE_ACTION !== $_POST['action'] ) {
			return;
		}
		check_admin_referer( self::SAVE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$rows = isset( $_POST['mappings'] ) && is_array( $_POST['mappings'] ) ? wp_unslash( $_POST['mappings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $rows as $id => $row ) {
			$repository->update(
				(int) $id,
				isset( $row['target_label'] ) ? sanitize_text_field( $row['target_label'] ) : '',
				isset( $row['action'] ) ? sanitize_key( $row['action'] ) : 'map'
			);
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mappings saved.', 'universal-woo-scraper' ) . '</p></div>';
	}
}
