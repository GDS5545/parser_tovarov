<?php
/**
 * Universal Scraper → Settings: import defaults, normalization mode,
 * manual-edit protection, debug mode. Persists to the real 'uws_settings'
 * option via the WordPress Settings API (SettingsRegistrar).
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Admin\SettingsRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	public function render() {
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Settings', 'universal-woo-scraper' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( SettingsRegistrar::OPTION_GROUP );
				// Tells SettingsRegistrar::sanitize() this page's checkboxes are
				// present, so an unrelated page's save can't reset them to unchecked.
				echo '<input type="hidden" name="uws_settings_page" value="uws-settings" />';
				do_settings_sections( 'uws-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
