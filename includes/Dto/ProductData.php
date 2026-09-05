<?php
/**
 * Normalized product data model produced by the extraction pipeline and
 * consumed by the WooCommerce importer (spec §65).
 *
 * Every scalar field has a matching entry in $confidence (0.0–1.0) so the
 * admin Preview screen can color-code low-confidence fields (spec §23).
 *
 * @package Uws\Dto
 */

namespace Uws\Dto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductData {

	public $name              = '';
	public $sku               = '';
	public $mpn               = '';
	public $gtin              = '';
	public $brand             = '';
	public $manufacturer      = '';
	public $model             = '';
	public $description       = '';
	public $short_description = '';
	public $regular_price     = null;
	public $sale_price        = null;
	public $currency          = '';
	public $stock_quantity    = null;
	public $stock_status      = ''; // 'instock' | 'outofstock' | 'onbackorder'.
	public $product_type      = 'simple'; // 'simple' | 'variable'.

	/** @var string[] Breadcrumb path, root first, e.g. ['Equipment', 'Pumps', 'Water Pumps']. */
	public $categories = array();

	/**
	 * @var ProductAttribute[] Flat list of extracted specifications/options.
	 */
	public $attributes = array();

	/**
	 * @var array<int,array<string,mixed>> One entry per variation, each with
	 *      its own sku/price/stock/attributes/image keys. Populated only
	 *      when $product_type === 'variable'.
	 */
	public $variations = array();

	/**
	 * @var array<int,array<string,mixed>> Each entry: ['url' => ..., 'is_main' => bool, 'variation_key' => string|null].
	 */
	public $images = array();

	/**
	 * @var array<string,string> meta_title, meta_description, og_title, og_description, og_image, canonical.
	 */
	public $seo = array();

	public $source_url = '';
	public $source_id  = '';

	/**
	 * @var array<string,float> Field name => confidence score (0.0–1.0).
	 */
	public $confidence = array();

	/**
	 * Records the winning value for a field along with which extractor
	 * supplied it and how confident that extractor was — used by
	 * ProductDataMerger to resolve conflicts by priority.
	 *
	 * @param string $field
	 * @param mixed  $value
	 * @param float  $confidence
	 */
	public function set_field( $field, $value, $confidence = 1.0 ) {
		$this->$field            = $value;
		$this->confidence[ $field ] = max( 0.0, min( 1.0, $confidence ) );
	}

	/**
	 * @return array<string,mixed> JSON-serializable representation (used for
	 *                              REST responses and uws_jobs.result storage).
	 */
	public function to_array() {
		$vars                = get_object_vars( $this );
		$vars['attributes']  = array_map(
			function ( ProductAttribute $attribute ) {
				return get_object_vars( $attribute );
			},
			$this->attributes
		);
		return $vars;
	}

	/**
	 * Rebuilds a ProductData from the array shape to_array() produces —
	 * used by ImportController to reconstruct the DTO a user edited in the
	 * admin Preview screen and posted back.
	 *
	 * @param array<string,mixed> $input
	 * @return self
	 */
	public static function from_array( array $input ) {
		$data = new self();

		foreach ( get_object_vars( $data ) as $field => $default ) {
			if ( 'attributes' === $field || ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$data->$field = $input[ $field ];
		}

		if ( ! empty( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
			foreach ( $input['attributes'] as $row ) {
				if ( empty( $row['attribute_key'] ) ) {
					continue;
				}
				$attribute               = new ProductAttribute( (string) $row['attribute_key'], (string) ( $row['value_raw'] ?? '' ), (string) ( $row['source'] ?? 'user' ) );
				$attribute->confidence   = isset( $row['confidence'] ) ? (float) $row['confidence'] : 1.0;
				$attribute->is_variation = ! empty( $row['is_variation'] );
				$data->attributes[]      = $attribute;
			}
		}

		return $data;
	}
}
