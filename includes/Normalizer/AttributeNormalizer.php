<?php
/**
 * Fills in ProductAttribute::attribute_name/value_normalized/numeric_value/unit
 * (spec §5, §12, §55) from the raw key/value an extractor found. Two modes:
 * "smart" collapses known synonyms and standardizes unit spelling; "strict"
 * only trims whitespace and leaves everything else exactly as scraped.
 *
 * @package Uws\Normalizer
 */

namespace Uws\Normalizer;

use Uws\Dto\ProductAttribute;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AttributeNormalizer {

	const MODE_SMART  = 'smart';
	const MODE_STRICT = 'strict';

	/** @var string */
	private $mode;

	/** @var array<string,array{0:string,1:string[]}> */
	private $canonical;

	/** @var array<string,string> */
	private $units;

	public function __construct( $mode = self::MODE_SMART ) {
		$this->mode      = in_array( $mode, array( self::MODE_SMART, self::MODE_STRICT ), true ) ? $mode : self::MODE_SMART;
		$this->canonical = AttributeDictionary::canonical_attributes();
		$this->units     = AttributeDictionary::unit_aliases();
	}

	/**
	 * Mutates $attribute in place.
	 *
	 * @param ProductAttribute $attribute
	 */
	public function normalize( ProductAttribute $attribute ) {
		$attribute->attribute_key = trim( $attribute->attribute_key );
		$attribute->value_raw     = trim( $attribute->value_raw );

		list( $slug, $label ) = $this->resolve_name( $attribute->attribute_key );
		$attribute->attribute_name = $slug;

		$this->apply_value( $attribute );
	}

	/**
	 * @param string $raw_key
	 * @return array{0:string,1:string} [slug, display label]
	 */
	private function resolve_name( $raw_key ) {
		$normalized_key = $this->normalize_label( $raw_key );

		if ( self::MODE_STRICT !== $this->mode ) {
			foreach ( $this->canonical as $slug => $entry ) {
				list( $label, $synonyms ) = $entry;
				foreach ( $synonyms as $synonym ) {
					if ( $normalized_key === $this->normalize_label( $synonym ) ) {
						return array( $slug, $label );
					}
				}
			}
		}

		return array( sanitize_title( $raw_key ), $raw_key );
	}

	/**
	 * @param string $label
	 * @return string
	 */
	private function normalize_label( $label ) {
		$label = mb_strtolower( trim( $label ) );
		$label = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $label );
		return trim( preg_replace( '/\s+/u', ' ', $label ) );
	}

	/**
	 * @param ProductAttribute $attribute
	 */
	private function apply_value( ProductAttribute $attribute ) {
		$raw = $attribute->value_raw;

		if ( self::MODE_STRICT === $this->mode ) {
			$attribute->value_normalized = $raw;
			return;
		}

		if ( preg_match( '/^(-?[\d.,\s]+)\s*([^\d\s].{0,10})?$/u', $raw, $matches ) ) {
			$numeric = $this->parse_number( $matches[1] );
			if ( null !== $numeric ) {
				$attribute->numeric_value = $numeric;

				$unit_token = isset( $matches[2] ) ? trim( $matches[2] ) : '';
				if ( '' !== $unit_token ) {
					$canonical_unit    = $this->units[ mb_strtolower( $unit_token ) ] ?? $unit_token;
					$attribute->unit   = $canonical_unit;
					$attribute->value_normalized = rtrim( rtrim( sprintf( '%s', $numeric ), '0' ), '.' ) . ' ' . $canonical_unit;
					return;
				}

				$attribute->value_normalized = (string) $numeric;
				return;
			}
		}

		$attribute->value_normalized = preg_replace( '/\s+/u', ' ', $raw );
	}

	/**
	 * @param string $raw
	 * @return float|null
	 */
	private function parse_number( $raw ) {
		$raw = trim( $raw );
		$raw = preg_replace( '/(\d)\s+(?=\d)/u', '$1', $raw ); // "50 000" -> "50000".

		if ( false !== strpos( $raw, ',' ) && false === strpos( $raw, '.' ) ) {
			$decimals = strlen( $raw ) - strrpos( $raw, ',' ) - 1;
			$raw      = ( 2 === $decimals ) ? str_replace( ',', '.', $raw ) : str_replace( ',', '', $raw );
		}

		return is_numeric( $raw ) ? (float) $raw : null;
	}
}
