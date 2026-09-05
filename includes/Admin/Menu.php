<?php
/**
 * Registers the "Universal Scraper" admin menu under WooCommerce and wires
 * each submenu page to its renderer class (spec §43).
 *
 * @package Uws\Admin
 */

namespace Uws\Admin;

use Uws\Admin\Pages\AiSettingsPage;
use Uws\Admin\Pages\AttributesPage;
use Uws\Admin\Pages\BrowserSettingsPage;
use Uws\Admin\Pages\BulkImportPage;
use Uws\Admin\Pages\DashboardPage;
use Uws\Admin\Pages\ImportProductPage;
use Uws\Admin\Pages\LogsPage;
use Uws\Admin\Pages\MappingsPage;
use Uws\Admin\Pages\ProductsPage;
use Uws\Admin\Pages\QueuePage;
use Uws\Admin\Pages\SettingsPage;
use Uws\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	const SLUG_ROOT = 'uws-dashboard';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		( new SettingsRegistrar() )->register();
	}

	public function register_menu() {
		$cap = Capabilities::MANAGE;

		add_menu_page(
			__( 'Universal Scraper', 'universal-woo-scraper' ),
			__( 'Universal Scraper', 'universal-woo-scraper' ),
			$cap,
			self::SLUG_ROOT,
			array( new DashboardPage(), 'render' ),
			'dashicons-download',
			56
		);

		$pages = array(
			self::SLUG_ROOT       => array( __( 'Dashboard', 'universal-woo-scraper' ), new DashboardPage() ),
			'uws-import'          => array( __( 'Import Product', 'universal-woo-scraper' ), new ImportProductPage() ),
			'uws-bulk-import'     => array( __( 'Bulk Import', 'universal-woo-scraper' ), new BulkImportPage() ),
			'uws-queue'           => array( __( 'Queue', 'universal-woo-scraper' ), new QueuePage() ),
			'uws-products'        => array( __( 'Products', 'universal-woo-scraper' ), new ProductsPage() ),
			'uws-mappings'        => array( __( 'Mappings', 'universal-woo-scraper' ), new MappingsPage() ),
			'uws-attributes'      => array( __( 'Attributes', 'universal-woo-scraper' ), new AttributesPage() ),
			'uws-settings'        => array( __( 'Settings', 'universal-woo-scraper' ), new SettingsPage() ),
			'uws-ai-settings'     => array( __( 'AI Settings', 'universal-woo-scraper' ), new AiSettingsPage() ),
			'uws-browser-settings'=> array( __( 'Browser Settings', 'universal-woo-scraper' ), new BrowserSettingsPage() ),
			'uws-logs'            => array( __( 'Logs', 'universal-woo-scraper' ), new LogsPage() ),
		);

		foreach ( $pages as $slug => $entry ) {
			list( $title, $page ) = $entry;
			add_submenu_page( self::SLUG_ROOT, $title, $title, $cap, $slug, array( $page, 'render' ) );
		}
	}

	/**
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'uws-' ) && false === strpos( (string) $hook, self::SLUG_ROOT ) ) {
			return;
		}

		wp_enqueue_style( 'uws-admin', UWS_PLUGIN_URL . 'assets/css/admin.css', array(), UWS_VERSION );
		wp_enqueue_script( 'uws-admin', UWS_PLUGIN_URL . 'assets/js/admin.js', array(), UWS_VERSION, true );
		wp_localize_script(
			'uws-admin',
			'uwsAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'uws/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'analyzing'      => __( 'Analyzing…', 'universal-woo-scraper' ),
					'analyzeProduct' => __( 'Analyze Product', 'universal-woo-scraper' ),
					'importing'      => __( 'Importing…', 'universal-woo-scraper' ),
					'imported'       => __( 'Imported as product #', 'universal-woo-scraper' ),
					'duplicateFound' => __( 'This product was already imported (product #', 'universal-woo-scraper' ),
					'mainImage'      => __( 'main', 'universal-woo-scraper' ),
					'usedForVariations' => __( 'Used for variations', 'universal-woo-scraper' ),
					'httpEngineHint'    => __( 'Fetched via plain HTTP (no browser needed).', 'universal-woo-scraper' ),
					'httpEngineEmptyHint' => __( 'Fetched via plain HTTP (no browser) and found little usable data — this site may render its content with JavaScript. Try deploying the Playwright worker under Browser Settings for this site.', 'universal-woo-scraper' ),
					'browserEngineHint' => __( 'Fetched via the Playwright browser worker.', 'universal-woo-scraper' ),
					'urlsQueuedTemplate'    => __( '%1$d / %2$d URLs queued.', 'universal-woo-scraper' ),
					'categoryQueuedTemplate' => __( 'Category job #%d queued.', 'universal-woo-scraper' ),
					'duplicatePromptSuffix'  => __( '). Type "update", "duplicate", or "skip":', 'universal-woo-scraper' ),
					'error'          => __( 'Error', 'universal-woo-scraper' ),
				),
			)
		);
	}
}
