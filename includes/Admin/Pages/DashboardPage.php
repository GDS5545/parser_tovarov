<?php
/**
 * Universal Scraper → Dashboard: at-a-glance counts from the real queue,
 * log, and product-link tables (no fabricated numbers).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\LogRepository;
use Uws\Database\ProductLinkRepository;
use Uws\Queue\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPage {

	public function render() {
		global $wpdb;

		$jobs_table = \Uws\Database\Tables::jobs();

		$pending    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE status IN ('pending','retry')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$processing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'processing'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$failed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$completed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'completed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$logs_total     = ( new LogRepository() )->count_all();
		$linked_total   = count( ( new ProductLinkRepository() )->paginate( 1, 100000 ) );
		$worker_url     = get_option( 'uws_settings', array() )['worker_url'] ?? '';
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Universal Scraper — Dashboard', 'universal-woo-scraper' ); ?></h1>

			<?php if ( empty( $worker_url ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: link to Browser Settings page */
							esc_html__( 'No scraper worker URL is configured yet. %s to connect the Playwright worker once it is deployed.', 'universal-woo-scraper' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=uws-browser-settings' ) ) . '">' . esc_html__( 'Open Browser Settings', 'universal-woo-scraper' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="uws-stat-grid">
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $pending ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Pending jobs', 'universal-woo-scraper' ); ?></span></div>
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $processing ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Processing', 'universal-woo-scraper' ); ?></span></div>
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $failed ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Failed', 'universal-woo-scraper' ); ?></span></div>
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $completed ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Completed', 'universal-woo-scraper' ); ?></span></div>
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $linked_total ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Imported products', 'universal-woo-scraper' ); ?></span></div>
				<div class="uws-stat-card"><span class="uws-stat-number"><?php echo esc_html( $logs_total ); ?></span><span class="uws-stat-label"><?php esc_html_e( 'Log entries', 'universal-woo-scraper' ); ?></span></div>
			</div>

			<h2><?php esc_html_e( 'Get started', 'universal-woo-scraper' ); ?></h2>
			<ol>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-browser-settings' ) ); ?>"><?php esc_html_e( 'Configure the scraper worker connection', 'universal-woo-scraper' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-import' ) ); ?>"><?php esc_html_e( 'Analyze a single product URL', 'universal-woo-scraper' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=uws-bulk-import' ) ); ?>"><?php esc_html_e( 'Or queue a category/bulk import', 'universal-woo-scraper' ); ?></a></li>
			</ol>
		</div>
		<?php
	}
}
