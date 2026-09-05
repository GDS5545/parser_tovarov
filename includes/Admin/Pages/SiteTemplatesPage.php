<?php
/**
 * Universal Scraper → Site Templates (spec §47): per-domain XPath
 * overrides for name/SKU/brand/price/description/specifications/images/
 * categories. Once saved, ManualSelectorExtractor applies these with the
 * highest priority of any extractor for every future Analyze/Import on
 * that domain — the fix for a site whose markup no generic heuristic
 * gets right.
 *
 * Selectors are XPath, not CSS: every browser's DevTools can produce one
 * directly (Chrome/Edge: right-click the element → Copy → Copy XPath;
 * Firefox: Copy → XPath) with no translation layer needed on our side,
 * and an XPath that doesn't match fails obviously (empty field) rather
 * than a loose CSS selector silently grabbing the wrong element.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Database\SourceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteTemplatesPage {

	const SAVE_ACTION   = 'uws_save_site_template';
	const DELETE_ACTION = 'uws_delete_site_template';

	/** Ordered field keys shown on the form; must match Uws\Database\SourceRepository::SELECTOR_FIELDS. */
	const FIELDS = array( 'name', 'sku', 'brand', 'price', 'sale_price', 'description', 'short_description', 'specifications', 'images', 'categories' );

	/**
	 * @param string $key One of self::FIELDS.
	 * @return string Translated field label.
	 */
	private function field_label( $key ) {
		switch ( $key ) {
			case 'name':
				return __( 'Name', 'universal-woo-scraper' );
			case 'sku':
				return __( 'SKU', 'universal-woo-scraper' );
			case 'brand':
				return __( 'Brand', 'universal-woo-scraper' );
			case 'price':
				return __( 'Regular price', 'universal-woo-scraper' );
			case 'sale_price':
				return __( 'Sale price', 'universal-woo-scraper' );
			case 'description':
				return __( 'Description', 'universal-woo-scraper' );
			case 'short_description':
				return __( 'Short description', 'universal-woo-scraper' );
			case 'specifications':
				return __( 'Specifications table/list container', 'universal-woo-scraper' );
			case 'images':
				return __( 'Images (an <img>, or a container — every descendant <img> is used)', 'universal-woo-scraper' );
			case 'categories':
				return __( 'Categories/breadcrumb links', 'universal-woo-scraper' );
			default:
				return $key;
		}
	}

	public function render() {
		$repository = new SourceRepository();

		$this->maybe_handle_save( $repository );
		$this->maybe_handle_delete( $repository );

		$editing_domain = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing        = $editing_domain ? $repository->find( $editing_domain ) : null;
		$templates      = $repository->all();
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Site Templates', 'universal-woo-scraper' ); ?></h1>
			<p>
				<?php esc_html_e( 'For a site whose markup the automatic extractors can\'t read correctly, tell the plugin exactly where each field lives with an XPath expression. Get one from your browser\'s DevTools: right-click the element on the page → Copy → "Copy XPath" (Chrome/Edge) or "XPath" (Firefox). Any field left blank here keeps using automatic detection.', 'universal-woo-scraper' ); ?>
			</p>

			<form method="post" class="uws-card">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />

				<table class="form-table">
					<tr>
						<th><label for="uws-st-domain"><?php esc_html_e( 'Domain', 'universal-woo-scraper' ); ?></label></th>
						<td>
							<input type="text" id="uws-st-domain" name="domain" class="regular-text" placeholder="example.com" required
								value="<?php echo esc_attr( $editing_domain ); ?>" <?php echo $editing ? 'readonly' : ''; ?> />
							<?php if ( $editing ) : ?>
								<p class="description"><?php esc_html_e( 'Editing an existing template. To rename the domain, delete this one and create a new one.', 'universal-woo-scraper' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<?php foreach ( self::FIELDS as $key ) : ?>
						<tr>
							<th><label for="uws-st-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->field_label( $key ) ); ?></label></th>
							<td>
								<input type="text" id="uws-st-<?php echo esc_attr( $key ); ?>" name="selectors[<?php echo esc_attr( $key ); ?>]" class="large-text code"
									value="<?php echo esc_attr( $editing ? ( $editing->selectors[ $key ] ?? '' ) : '' ); ?>" />
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( $editing ? __( 'Save template', 'universal-woo-scraper' ) : __( 'Add template', 'universal-woo-scraper' ) ); ?>
			</form>

			<?php if ( ! empty( $templates ) ) : ?>
				<h2><?php esc_html_e( 'Configured domains', 'universal-woo-scraper' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Domain', 'universal-woo-scraper' ); ?></th>
							<th><?php esc_html_e( 'Fields overridden', 'universal-woo-scraper' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'universal-woo-scraper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $templates as $template ) : ?>
							<tr>
								<td><?php echo esc_html( $template->domain ); ?></td>
								<td><?php echo esc_html( implode( ', ', array_keys( $template->selectors ) ) ); ?></td>
								<td>
									<a class="button" href="<?php echo esc_url( add_query_arg( 'edit', rawurlencode( $template->domain ) ) ); ?>"><?php esc_html_e( 'Edit', 'universal-woo-scraper' ); ?></a>
									<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this site template?', 'universal-woo-scraper' ) ); ?>');">
										<?php wp_nonce_field( self::DELETE_ACTION ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
										<input type="hidden" name="id" value="<?php echo esc_attr( $template->id ); ?>" />
										<button type="submit" class="button"><?php esc_html_e( 'Delete', 'universal-woo-scraper' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function maybe_handle_save( SourceRepository $repository ) {
		if ( ! isset( $_POST['action'] ) || self::SAVE_ACTION !== $_POST['action'] ) {
			return;
		}
		check_admin_referer( self::SAVE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$domain = preg_replace( '#^https?://#i', '', $domain );
		$domain = trim( $domain, '/ ' );
		if ( '' === $domain ) {
			return;
		}

		$raw_selectors = isset( $_POST['selectors'] ) && is_array( $_POST['selectors'] ) ? wp_unslash( $_POST['selectors'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$selectors     = array();
		foreach ( SourceRepository::SELECTOR_FIELDS as $field ) {
			if ( ! empty( $raw_selectors[ $field ] ) ) {
				$selectors[ $field ] = sanitize_text_field( $raw_selectors[ $field ] );
			}
		}

		$repository->save( $domain, $selectors );

		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html( sprintf(
				/* translators: %s: domain name */
				__( 'Site template saved for %s.', 'universal-woo-scraper' ),
				$domain
			) ) . '</p></div>';
	}

	private function maybe_handle_delete( SourceRepository $repository ) {
		if ( ! isset( $_POST['action'] ) || self::DELETE_ACTION !== $_POST['action'] ) {
			return;
		}
		check_admin_referer( self::DELETE_ACTION );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$repository->delete( (int) ( $_POST['id'] ?? 0 ) );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Site template deleted.', 'universal-woo-scraper' ) . '</p></div>';
	}
}
