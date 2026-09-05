<?php
/**
 * Universal Scraper → Products: real listing of wp_uws_product_links —
 * every WooCommerce product created by this plugin, with a link back to
 * both the source page and the WooCommerce edit screen.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\ProductLinkRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductsPage {

	public function render() {
		$page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$links = ( new ProductLinkRepository() )->paginate( $page, 20 );
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Imported Products', 'universal-woo-scraper' ); ?></h1>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Source', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Last scraped', 'universal-woo-scraper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $links ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No products imported yet.', 'universal-woo-scraper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $links as $link ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $link->product_id ) ); ?>"><?php echo esc_html( get_the_title( $link->product_id ) ?: ( '#' . $link->product_id ) ); ?></a></td>
							<td><?php echo esc_html( $link->sku ); ?></td>
							<td><a href="<?php echo esc_url( $link->source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link->source_domain ); ?></a></td>
							<td><?php echo esc_html( $link->last_scraped_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
