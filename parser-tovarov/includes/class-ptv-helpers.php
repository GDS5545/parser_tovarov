<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for turning a "column key" (name, price, sku, pa_marka...)
 * into a human label and a rendered table cell. Used by both the
 * server-rendered first page and the AJAX table partial, so the two never
 * drift apart.
 */
class PTV_Helpers {

	public static function column_label( $column ) {
		switch ( $column ) {
			case 'name':
				return __( 'Наименование', 'parser-tovarov' );
			case 'price':
				return __( 'Цена', 'parser-tovarov' );
			case 'sku':
				return __( 'Артикул', 'parser-tovarov' );
			default:
				if ( 0 === strpos( $column, 'pa_' ) ) {
					$taxonomy = get_taxonomy( $column );
					if ( $taxonomy ) {
						return $taxonomy->labels->singular_name;
					}
					$name = str_replace( 'pa_', '', $column );
					return ucfirst( str_replace( array( '-', '_' ), ' ', $name ) );
				}
				return ucfirst( $column );
		}
	}

	public static function column_cell( WC_Product $product, $column ) {
		switch ( $column ) {
			case 'name':
				return sprintf(
					'<a class="ptv-product-link" href="%1$s">%2$s</a>',
					esc_url( get_permalink( $product->get_id() ) ),
					esc_html( $product->get_name() )
				);
			case 'price':
				return '<span class="ptv-price">' . $product->get_price_html() . '</span>';
			case 'sku':
				return esc_html( $product->get_sku() );
			default:
				if ( 0 === strpos( $column, 'pa_' ) ) {
					$value = $product->get_attribute( $column );
					return $value ? esc_html( $value ) : '&mdash;';
				}
				return '';
		}
	}

	public static function cell_sort_value( WC_Product $product, $column ) {
		switch ( $column ) {
			case 'name':
				return $product->get_name();
			case 'price':
				return (float) $product->get_price();
			default:
				return $product->get_attribute( $column );
		}
	}

	public static function attribute_label( $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax ) {
			return $tax->labels->singular_name;
		}
		return str_replace( 'pa_', '', $taxonomy );
	}
}
