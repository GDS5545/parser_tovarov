<?php
/**
 * A single normalized specification/characteristic (spec §55: attribute
 * data model). Kept as raw + normalized so both a human-readable
 * "Additional Information" tab and numeric range filters (Power > 1000 W)
 * are possible without re-parsing later.
 *
 * @package Uws\Dto
 */

namespace Uws\Dto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductAttribute {

	/** @var string Raw label as found on the source page, e.g. "Напряжение питания". */
	public $attribute_key;

	/** @var string Normalized key used to match/create a WooCommerce global attribute, e.g. "voltage". */
	public $attribute_name;

	/** @var string Raw value as found on the page, e.g. "220 V". */
	public $value_raw;

	/** @var string Value after normalization (case/unit formatting), e.g. "220 V". */
	public $value_normalized;

	/** @var float|null Numeric portion of the value, when the attribute is numeric, e.g. 220.0. */
	public $numeric_value;

	/** @var string|null Unit of measure, e.g. "V". */
	public $unit;

	/** @var string Which extractor produced this row, e.g. "SpecificationExtractor". */
	public $source;

	/** @var bool Whether this attribute drives variations (true) or is informational (false). */
	public $is_variation = false;

	/** @var float 0.0–1.0. */
	public $confidence = 1.0;

	public function __construct( $attribute_key, $value_raw, $source = '' ) {
		$this->attribute_key = $attribute_key;
		$this->value_raw     = $value_raw;
		$this->source        = $source;
	}
}
