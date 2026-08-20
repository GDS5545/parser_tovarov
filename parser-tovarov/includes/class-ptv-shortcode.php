<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [ptv_catalog] shortcode: renders the filter bar, quick-filter chips and
 * the first page of the product table server-side (so the page has real
 * content without waiting on JS), then hands control over to ptv-catalog.js
 * for filtering / paging / add-to-cart via AJAX.
 */
class PTV_Shortcode {

	private static $instance = null;
	private static $rendered_on_page = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'ptv_catalog', array( $this, 'render' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_globals' ) );
	}

	/**
	 * Normalizes shortcode attributes into the config array used by both
	 * the server-side render and PTV_Ajax::get_table().
	 */
	public static function parse_config( $atts ) {
		$settings = PTV_Settings::instance();

		$atts = shortcode_atts(
			array(
				'categories'         => '',
				'attributes'         => '',
				'columns'            => '',
				'quick_filter'       => '',
				'quick_filter_limit' => 14,
				'per_page'           => $settings->get( 'per_page' ),
				'title'              => '',
				'orderby'            => 'title',
				'order'              => 'ASC',
			),
			$atts,
			'ptv_catalog'
		);

		$categories = array_filter( array_map( 'trim', explode( ',', $atts['categories'] ) ) );

		$attributes = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $atts['attributes'] ) ) ) as $attr ) {
			$taxonomy = 0 === strpos( $attr, 'pa_' ) ? $attr : 'pa_' . $attr;
			if ( taxonomy_exists( $taxonomy ) ) {
				$attributes[] = $taxonomy;
			}
		}

		$quick_filter = trim( $atts['quick_filter'] );
		if ( $quick_filter ) {
			$quick_filter = 0 === strpos( $quick_filter, 'pa_' ) ? $quick_filter : 'pa_' . $quick_filter;
			if ( ! taxonomy_exists( $quick_filter ) ) {
				$quick_filter = '';
			}
		}
		if ( ! $quick_filter && ! empty( $attributes ) ) {
			$quick_filter = $attributes[0];
		}

		if ( ! empty( $atts['columns'] ) ) {
			$columns = array_filter( array_map( 'trim', explode( ',', $atts['columns'] ) ) );
		} else {
			$columns = array_merge( array( 'name' ), $attributes, array( 'price' ) );
		}

		return array(
			'instance_id'        => 'ptv-' . substr( md5( wp_json_encode( $atts ) . mt_rand() ), 0, 8 ),
			'categories'         => $categories,
			'attributes'         => $attributes,
			'columns'            => $columns,
			'quick_filter'       => $quick_filter,
			'quick_filter_limit' => max( 0, (int) $atts['quick_filter_limit'] ),
			'per_page'           => max( 1, min( 100, (int) $atts['per_page'] ) ),
			'title'              => sanitize_text_field( $atts['title'] ),
			'orderby'            => sanitize_key( $atts['orderby'] ),
			'order'              => ( 'DESC' === strtoupper( $atts['order'] ) ) ? 'DESC' : 'ASC',
		);
	}

	public function render( $atts ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		self::$rendered_on_page = true;
		PTV_Assets::instance()->mark_needed();

		$config = self::parse_config( $atts );

		if ( empty( $config['categories'] ) ) {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				return '<p style="color:#c0392b;">' . esc_html__( 'ptv_catalog: укажите атрибут categories со слагами категорий товаров.', 'parser-tovarov' ) . '</p>';
			}
			return '';
		}

		$result = PTV_Query::query(
			array(
				'categories' => $config['categories'],
				'per_page'   => $config['per_page'],
				'page'       => 1,
				'orderby'    => $config['orderby'],
				'order'      => $config['order'],
			)
		);

		ob_start();
		include PTV_PATH . 'templates/catalog.php';
		return ob_get_clean();
	}

	/**
	 * The cart drawer and quick-order modal markup only needs to exist once
	 * per page, regardless of how many [ptv_catalog] tables are on it.
	 */
	public function maybe_render_globals() {
		if ( ! self::$rendered_on_page ) {
			return;
		}
		include PTV_PATH . 'templates/cart-drawer.php';
		include PTV_PATH . 'templates/order-modal.php';
	}
}
