<?php
/**
 * Universal Scraper → Import Product: single-URL "Analyze" form (spec §44).
 * Posts to /wp-json/uws/v1/analyze via assets/js/admin.js; the REST
 * response (or its honest 501 until Stage 3's worker is connected) is
 * rendered into #uws-preview by that same script.
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
			<p><?php esc_html_e( 'Paste the URL of a single product page. The scraper worker will open it in a real browser, extract structured data, and show you a preview before anything is imported.', 'universal-woo-scraper' ); ?></p>

			<form id="uws-analyze-form" class="uws-card">
				<label for="uws-product-url"><strong><?php esc_html_e( 'Product URL', 'universal-woo-scraper' ); ?></strong></label>
				<input type="url" id="uws-product-url" class="regular-text" style="width:100%;max-width:640px;" placeholder="https://example.com/product/example-item" required />
				<p>
					<button type="submit" class="button button-primary" id="uws-analyze-btn"><?php esc_html_e( 'Analyze Product', 'universal-woo-scraper' ); ?></button>
				</p>
			</form>

			<div id="uws-preview" class="uws-card" hidden>
				<h2><?php esc_html_e( 'Preview', 'universal-woo-scraper' ); ?></h2>
				<pre id="uws-preview-json"></pre>
			</div>

			<div id="uws-error" class="notice notice-error" hidden><p id="uws-error-message"></p></div>
		</div>
		<?php
	}
}
