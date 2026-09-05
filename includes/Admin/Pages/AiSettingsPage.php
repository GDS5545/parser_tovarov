<?php
/**
 * Universal Scraper → AI Settings: provider + API key, used only as a
 * fallback for fields the conventional extractors leave unresolved
 * (Stage 12). Persists to the real 'uws_settings' option.
 *
 * @package Uws\Admin\Pages
 */

namespace Uws\Admin\Pages;

use Uws\Admin\SettingsRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiSettingsPage {

	public function render() {
		?>
		<div class="wrap uws-wrap">
			<h1><?php esc_html_e( 'AI Settings', 'universal-woo-scraper' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( SettingsRegistrar::OPTION_GROUP );
				do_settings_sections( 'uws-ai-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
