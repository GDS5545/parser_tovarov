<?php
/**
 * Universal Scraper → Queue: real listing of wp_uws_jobs with
 * Cancel/Retry actions wired to the REST API (spec §30).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Queue\JobRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueuePage {

	public function render() {
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$jobs = ( new JobRepository() )->paginate( null, $page, 20 );
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Queue', 'universal-woo-scraper' ); ?></h1>

			<p>
				<button type="button" class="button button-primary" id="uws-queue-run-now"><?php esc_html_e( 'Run queue now', 'universal-woo-scraper' ); ?></button>
				<span class="description"><?php esc_html_e( 'Processes whatever is currently due immediately, instead of waiting for WP-Cron — useful on a low-traffic site where WP-Cron\'s only trigger (a visitor request) may not fire for a while.', 'universal-woo-scraper' ); ?></span>
			</p>
			<div id="uws-queue-run-result" class="notice notice-info" hidden><p id="uws-queue-run-message"></p></div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'URL', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Type', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Status', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Created', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'universal-woo-scraper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $jobs ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No jobs yet. Add one from Import Product or Bulk Import.', 'universal-woo-scraper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $jobs as $job ) : ?>
						<tr data-job-id="<?php echo esc_attr( $job->id ); ?>">
							<td><?php echo esc_html( $job->id ); ?></td>
							<td><?php echo esc_html( $job->url ); ?></td>
							<td><?php echo esc_html( $job->type ); ?></td>
							<td><span class="uws-badge uws-badge-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span></td>
							<td><?php echo esc_html( $job->attempts ); ?>/<?php echo esc_html( $job->max_attempts ); ?></td>
							<td><?php echo esc_html( $job->created_at ); ?></td>
							<td>
								<?php if ( in_array( $job->status, array( 'pending', 'retry', 'processing' ), true ) ) : ?>
									<button class="button uws-job-cancel" data-id="<?php echo esc_attr( $job->id ); ?>"><?php esc_html_e( 'Cancel', 'universal-woo-scraper' ); ?></button>
								<?php endif; ?>
								<?php if ( 'failed' === $job->status ) : ?>
									<button class="button uws-job-retry" data-id="<?php echo esc_attr( $job->id ); ?>"><?php esc_html_e( 'Retry', 'universal-woo-scraper' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
