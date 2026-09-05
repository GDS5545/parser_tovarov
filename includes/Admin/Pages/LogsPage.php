<?php
/**
 * Universal Scraper → Logs: real listing of wp_uws_logs with a "Clear
 * logs" action (spec §29, §58).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogsPage {

	const CLEAR_ACTION = 'uws_clear_logs';

	public function render() {
		$this->maybe_handle_clear();

		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$logs = ( new LogRepository() )->paginate( $page, 50 );
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Logs', 'universal-woo-scraper' ); ?></h1>

			<form method="post" style="margin-bottom:1em;">
				<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Clear all logs? This cannot be undone.', 'universal-woo-scraper' ) ); ?>');">
					<?php esc_html_e( 'Clear logs', 'universal-woo-scraper' ); ?>
				</button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'URL', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Status', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Product', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Images', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Attributes', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Variations', 'universal-woo-scraper' ); ?></th>
						<th><?php esc_html_e( 'Error', 'universal-woo-scraper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No log entries yet.', 'universal-woo-scraper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><?php echo esc_html( $log->url ); ?></td>
							<td><span class="uws-badge uws-badge-<?php echo esc_attr( $log->status ); ?>"><?php echo esc_html( $log->status ); ?></span></td>
							<td><?php echo $log->product_id ? esc_html( get_the_title( $log->product_id ) ?: ( '#' . $log->product_id ) ) : '—'; ?></td>
							<td><?php echo esc_html( $log->duration_ms ? round( $log->duration_ms / 1000, 1 ) . 's' : '—' ); ?></td>
							<td><?php echo esc_html( $log->images_count ?? '—' ); ?></td>
							<td><?php echo esc_html( $log->attributes_count ?? '—' ); ?></td>
							<td><?php echo esc_html( $log->variations_count ?? '—' ); ?></td>
							<td><?php echo esc_html( $log->error_message ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function maybe_handle_clear() {
		if ( ! isset( $_POST['action'] ) || self::CLEAR_ACTION !== $_POST['action'] ) {
			return;
		}
		check_admin_referer( self::CLEAR_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		( new LogRepository() )->clear();
	}
}
