<?php
/**
 * Universal Scraper → Import Product: single-URL "Analyze" form (spec §44)
 * followed by an editable Preview (spec §24–25) with an Import button.
 * All the interactive behavior lives in assets/js/admin.js, which calls
 * /wp-json/uws/v1/analyze then /wp-json/uws/v1/import.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImportProductPage {

	public function render() {
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Import Product', 'universal-woo-scraper' ); ?></h1>
			<p><?php esc_html_e( 'Paste the URL of a single product page and extract structured data from it; nothing is created in WooCommerce until you review the preview below and click Import.', 'universal-woo-scraper' ); ?></p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to Browser Settings page */
					esc_html__( 'No separate worker needed for most sites — plain HTTP fetch is used by default. It cannot render JavaScript-only content or click "Load more" buttons; %s and set a worker URL there only if a specific site needs a real browser.', 'universal-woo-scraper' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=uws-browser-settings' ) ) . '">' . esc_html__( 'deploy the optional Playwright worker', 'universal-woo-scraper' ) . '</a>'
				);
				?>
			</p>

			<form id="uws-analyze-form" class="uws-card">
				<label for="uws-product-url"><strong><?php esc_html_e( 'Product URL', 'universal-woo-scraper' ); ?></strong></label>
				<input type="url" id="uws-product-url" class="regular-text" style="width:100%;max-width:640px;" placeholder="https://example.com/product/example-item" required />
				<p>
					<button type="submit" class="button button-primary" id="uws-analyze-btn"><?php esc_html_e( 'Analyze Product', 'universal-woo-scraper' ); ?></button>
				</p>
			</form>

			<div id="uws-error" class="notice notice-error" hidden><p id="uws-error-message"></p></div>

			<div id="uws-preview" class="uws-card" hidden>
				<h2><?php esc_html_e( 'Preview', 'universal-woo-scraper' ); ?></h2>
				<p class="description" id="uws-engine-note"></p>

				<table class="form-table">
					<tr>
						<th><label for="uws-f-name"><?php esc_html_e( 'Name', 'universal-woo-scraper' ); ?></label></th>
						<td><input type="text" id="uws-f-name" class="regular-text uws-confidence-field" data-field="name" /></td>
					</tr>
					<tr>
						<th><label for="uws-f-sku"><?php esc_html_e( 'SKU', 'universal-woo-scraper' ); ?></label></th>
						<td><input type="text" id="uws-f-sku" class="regular-text uws-confidence-field" data-field="sku" /></td>
					</tr>
					<tr>
						<th><label for="uws-f-brand"><?php esc_html_e( 'Brand', 'universal-woo-scraper' ); ?></label></th>
						<td><input type="text" id="uws-f-brand" class="regular-text uws-confidence-field" data-field="brand" /></td>
					</tr>
					<tr>
						<th><label for="uws-f-regular-price"><?php esc_html_e( 'Regular price', 'universal-woo-scraper' ); ?></label></th>
						<td>
							<input type="text" id="uws-f-regular-price" class="regular-text uws-confidence-field" data-field="regular_price" />
							<input type="text" id="uws-f-currency" placeholder="<?php esc_attr_e( 'Currency', 'universal-woo-scraper' ); ?>" style="width:6em;" data-field="currency" />
						</td>
					</tr>
					<tr>
						<th><label for="uws-f-sale-price"><?php esc_html_e( 'Sale price', 'universal-woo-scraper' ); ?></label></th>
						<td><input type="text" id="uws-f-sale-price" data-field="sale_price" /></td>
					</tr>
					<tr>
						<th><label for="uws-f-categories"><?php esc_html_e( 'Categories', 'universal-woo-scraper' ); ?></label></th>
						<td><input type="text" id="uws-f-categories" class="regular-text uws-confidence-field" data-field="categories" placeholder="<?php esc_attr_e( '(none detected — type comma-separated category names here if needed)', 'universal-woo-scraper' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="uws-f-short-description"><?php esc_html_e( 'Short description', 'universal-woo-scraper' ); ?></label></th>
						<td><textarea id="uws-f-short-description" rows="3" style="width:100%;" data-field="short_description"></textarea></td>
					</tr>
					<tr>
						<th><label for="uws-f-description"><?php esc_html_e( 'Description', 'universal-woo-scraper' ); ?></label></th>
						<td><textarea id="uws-f-description" rows="8" style="width:100%;" data-field="description"></textarea></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Attributes', 'universal-woo-scraper' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:0.5em;">
								<input type="checkbox" id="uws-f-is-variable" />
								<?php esc_html_e( 'This is a variable product (create a WooCommerce variable product using the attributes checked below as "Used for variations")', 'universal-woo-scraper' ); ?>
							</label>
							<div id="uws-attributes-list"></div>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Images', 'universal-woo-scraper' ); ?></th>
						<td><div id="uws-images-list"></div></td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-primary" id="uws-import-btn"><?php esc_html_e( 'Import', 'universal-woo-scraper' ); ?></button>
					<span id="uws-import-status"></span>
				</p>
			</div>
		</div>
		<?php
	}
}
