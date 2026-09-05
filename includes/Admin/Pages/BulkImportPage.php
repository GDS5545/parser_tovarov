<?php
/**
 * Universal Scraper → Bulk Import: one URL per line, or a single category
 * URL, queued via POST /wp-json/uws/v1/jobs (spec §45, §19).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BulkImportPage {

	public function render() {
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Bulk Import', 'universal-woo-scraper' ); ?></h1>

			<div class="uws-card">
				<h2><?php esc_html_e( 'Paste product URLs', 'universal-woo-scraper' ); ?></h2>
				<p><?php esc_html_e( 'One URL per line. Each is added to the queue as a "single" job.', 'universal-woo-scraper' ); ?></p>
				<textarea id="uws-bulk-urls" rows="10" style="width:100%;max-width:640px;" placeholder="https://example.com/product/1&#10;https://example.com/product/2"></textarea>
				<p><button type="button" class="button button-primary" id="uws-bulk-submit"><?php esc_html_e( 'Add to Queue', 'universal-woo-scraper' ); ?></button></p>
			</div>

			<div class="uws-card">
				<h2><?php esc_html_e( 'Import from a category / listing URL', 'universal-woo-scraper' ); ?></h2>
				<p><?php esc_html_e( 'The worker will discover product links (including paginated and "Load more" listings, spec-permitting) and queue them one by one, respecting the configured request delay.', 'universal-woo-scraper' ); ?></p>
				<input type="url" id="uws-category-url" class="regular-text" style="width:100%;max-width:640px;" placeholder="https://example.com/category/pumps/" />
				<p><button type="button" class="button button-primary" id="uws-category-submit"><?php esc_html_e( 'Queue Category', 'universal-woo-scraper' ); ?></button></p>
			</div>

			<div id="uws-bulk-result" class="notice notice-info" hidden><p id="uws-bulk-result-message"></p></div>
		</div>
		<?php
	}
}
