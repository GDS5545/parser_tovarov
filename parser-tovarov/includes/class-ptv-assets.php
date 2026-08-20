<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads CSS/JS only on pages that actually use [ptv_catalog], so the plugin
 * never adds weight to the rest of the site. The common case (shortcode
 * inside post/page content) is detected cheaply via has_shortcode() before
 * wp_head fires. Shortcodes rendered from widgets/page builders (not part
 * of post_content) call mark_needed(), which is caught by a late wp_footer
 * fallback that prints the tags directly if the head-based check missed them.
 */
class PTV_Assets {

	private static $instance = null;
	private $needed = false;
	private $enqueued = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_from_content' ), 20 );
		add_action( 'wp_footer', array( $this, 'footer_fallback' ), 5 );
	}

	public function register() {
		wp_register_style( 'ptv-catalog', PTV_URL . 'public/css/ptv-catalog.css', array(), PTV_VERSION );
		wp_register_script( 'ptv-catalog', PTV_URL . 'public/js/ptv-catalog.js', array(), PTV_VERSION, true );
	}

	public function maybe_enqueue_from_content() {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'ptv_catalog' ) ) {
				$this->enqueue();
			}
		}
	}

	/**
	 * Called from PTV_Shortcode::render() every time the shortcode actually
	 * outputs a table, so we know assets are required even when the
	 * has_shortcode() content scan above could not see it (widgets, PHP
	 * snippets, page builders that store markup elsewhere).
	 */
	public function mark_needed() {
		$this->needed = true;
		if ( did_action( 'wp_enqueue_scripts' ) && ! $this->enqueued ) {
			$this->enqueue();
		}
	}

	public function footer_fallback() {
		if ( ! $this->needed || $this->enqueued ) {
			return;
		}
		// The head-based detection missed it (shortcode came from a widget
		// or builder). Print the tags directly so the page still works.
		$this->enqueue();
		wp_print_styles( array( 'ptv-catalog' ) );
		wp_print_scripts( array( 'ptv-catalog' ) );
	}

	private function enqueue() {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;

		wp_enqueue_style( 'ptv-catalog' );
		wp_enqueue_script( 'ptv-catalog' );

		$settings = PTV_Settings::instance();

		wp_localize_script(
			'ptv-catalog',
			'PTV_DATA',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'ptv_ajax' ),
				'currencySuffix' => $settings->get( 'currency_suffix' ),
				'i18n'          => array(
					'loading'        => __( 'Загрузка…', 'parser-tovarov' ),
					'addedToCart'    => __( 'Добавлено в корзину', 'parser-tovarov' ),
					'error'          => __( 'Ошибка. Попробуйте ещё раз.', 'parser-tovarov' ),
					'emptyCart'      => __( 'Корзина пуста', 'parser-tovarov' ),
					'itemsOne'       => __( 'товар', 'parser-tovarov' ),
					'itemsFew'       => __( 'товара', 'parser-tovarov' ),
					'itemsMany'      => __( 'товаров', 'parser-tovarov' ),
					'minOrderNotice' => __( 'Минимальная сумма заказа не набрана', 'parser-tovarov' ),
					'fillRequired'   => __( 'Заполните обязательные поля и подтвердите согласие', 'parser-tovarov' ),
					'orderSuccess'   => __( 'Заявка отправлена! Мы свяжемся с вами в ближайшее время.', 'parser-tovarov' ),
					'confirmClear'   => __( 'Очистить корзину?', 'parser-tovarov' ),
				),
			)
		);

		wp_style_add_data( 'ptv-catalog', 'rtl', 'replace' );
		$accent = $settings->get( 'accent_color' );
		if ( $accent ) {
			wp_add_inline_style( 'ptv-catalog', ':root{--ptv-accent:' . esc_html( $accent ) . ';}' );
		}
	}
}
