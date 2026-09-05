<?php
/**
 * Builds the $args array Woocommerce\ProductImporter::import() expects
 * from the plugin's 'uws_settings' option, so the REST /import flow and
 * the queue Dispatcher build it identically instead of duplicating the
 * same list of keys.
 *
 * @package Uws\Support
 */

namespace Uws\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImporterArgs {

	/**
	 * @param array<string,mixed> $settings   The 'uws_settings' option.
	 * @param int                 $product_id 0 for a new product, an existing ID to update.
	 * @return array<string,mixed>
	 */
	public static function from_settings( array $settings, $product_id = 0 ) {
		return array(
			'product_id'            => $product_id,
			'status'                => $settings['default_import_status'] ?? 'draft',
			'image_policy'          => $settings['image_policy'] ?? 'download',
			'normalization_mode'    => $settings['normalization_mode'] ?? 'smart',
			'protect_manual_edits'  => $settings['protect_manual_edits'] ?? true,
			'update_title'          => $settings['update_title'] ?? true,
			'update_description'    => $settings['update_description'] ?? true,
			'update_price'          => $settings['update_price'] ?? true,
			'update_stock'          => $settings['update_stock'] ?? true,
			'update_categories'     => $settings['update_categories'] ?? true,
			'update_attributes'     => $settings['update_attributes'] ?? true,
			'update_images'         => $settings['update_images'] ?? true,
		);
	}
}
