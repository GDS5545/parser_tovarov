<?php
/**
 * Universal Scraper → Browser Settings: worker URL/API key, request delay,
 * parallelism, and image import policy. Persists to the real
 * 'uws_settings' option; the worker URL is what AnalyzeController checks
 * before it can do anything.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Admin\SettingsRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BrowserSettingsPage {

	public function render() {
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'Browser Settings', 'universal-woo-scraper' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( SettingsRegistrar::OPTION_GROUP );
				do_settings_sections( 'uws-browser-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
