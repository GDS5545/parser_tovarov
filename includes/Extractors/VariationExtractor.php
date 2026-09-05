<?php
/**
 * Detects variation option groups — native <select> dropdowns and
 * <input type="radio"> groups sharing a `name` (the two semantically
 * well-defined patterns for "pick one of several options"; many
 * div/swatch-based variant pickers render one of these under the hood
 * even when styled to look like colored squares, spec §8) — and marks
 * $data->product_type = "variable" with one is_variation ProductAttribute
 * per (attribute, value) pair.
 *
 * This extractor intentionally does NOT attempt to reconstruct
 * per-combination price/SKU/stock: that data is loaded via AJAX on real
 * stores once a shopper picks a combination, so it is essentially never
 * present in the page's initial HTML. Woocommerce\ProductImporter is
 * responsible for turning "these are the variation attributes" into a
 * real WC_Product_Variable and telling the merchant to use WooCommerce's
 * own "Generate variations" button for the rest.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductAttribute;
use Uws\Dto\ProductData;
use Uws\Support\HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VariationExtractor implements ProductExtractorInterface {

	/** Option/value text matching these (case-insensitive) is a placeholder, not a real choice. */
	const PLACEHOLDER_PATTERN = '/^(choose|select|please select|—?\s*(выберите|выбрать)|размер\?|-{1,3})/iu';

	public function get_name() {
		return 'variation';
	}

	public function get_priority() {
		return 65;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$data  = new ProductData();
		$xpath = HtmlDocument::xpath( (string) $page['html'] );

		$groups = array_merge(
			$this->groups_from_selects( $xpath ),
			$this->groups_from_radios( $xpath )
		);

		$has_variation_group = false;

		foreach ( $groups as $label => $values ) {
			$values = array_values( array_unique( $values ) );
			if ( count( $values ) < 2 ) {
				continue;
			}

			$has_variation_group = true;

			foreach ( $values as $value ) {
				$attribute               = new ProductAttribute( $label, $value, $this->get_name() );
				$attribute->is_variation = true;
				$attribute->confidence   = 0.6;
				$data->attributes[]      = $attribute;
			}
		}

		if ( $has_variation_group ) {
			$data->set_field( 'product_type', 'variable', 0.6 );
		}

		return $data;
	}

	/**
	 * @param \DOMXPath $xpath
	 * @return array<string,string[]> label => option values.
	 */
	private function groups_from_selects( \DOMXPath $xpath ) {
		$groups = array();

		foreach ( $xpath->query( '//select' ) as $select ) {
			/** @var \DOMElement $select */
			$label  = $this->label_for( $xpath, $select );
			$values = array();

			foreach ( $xpath->query( './option', $select ) as $option ) {
				/** @var \DOMElement $option */
				$text = HtmlDocument::text( $option );
				$value = $option->getAttribute( 'value' );
				if ( '' === $text || '' === $value || preg_match( self::PLACEHOLDER_PATTERN, $text ) ) {
					continue;
				}
				$values[] = $text;
			}

			if ( $label && count( $values ) >= 2 ) {
				$groups[ $label ] = array_merge( $groups[ $label ] ?? array(), $values );
			}
		}

		return $groups;
	}

	/**
	 * @param \DOMXPath $xpath
	 * @return array<string,string[]> label => option values.
	 */
	private function groups_from_radios( \DOMXPath $xpath ) {
		$by_name = array();

		foreach ( $xpath->query( '//input[translate(@type,"RADIO","radio")="radio"]' ) as $input ) {
			/** @var \DOMElement $input */
			$name = $input->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}

			$value = $this->label_for_input( $xpath, $input );
			if ( '' === $value || preg_match( self::PLACEHOLDER_PATTERN, $value ) ) {
				continue;
			}

			$by_name[ $name ][] = $value;
		}

		$groups = array();
		foreach ( $by_name as $name => $values ) {
			if ( count( array_unique( $values ) ) < 2 ) {
				continue;
			}
			$groups[ $this->humanize( $name ) ] = $values;
		}

		return $groups;
	}

	/**
	 * @param \DOMXPath   $xpath
	 * @param \DOMElement $field A <select> or <input>.
	 * @return string
	 */
	private function label_for( \DOMXPath $xpath, \DOMElement $field ) {
		$aria_label = $field->getAttribute( 'aria-label' );
		if ( $aria_label ) {
			return trim( $aria_label );
		}

		$id = $field->getAttribute( 'id' );
		if ( $id ) {
			$labels = $xpath->query( '//label[@for="' . $this->xpath_literal_escape( $id ) . '"]' );
			if ( $labels->length > 0 ) {
				$text = HtmlDocument::text( $labels->item( 0 ) );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		$name = $field->getAttribute( 'name' ) ?: $id;
		return $name ? $this->humanize( $name ) : '';
	}

	/**
	 * @param \DOMXPath   $xpath
	 * @param \DOMElement $input
	 * @return string The option's display value: its <label>, else its value attribute.
	 */
	private function label_for_input( \DOMXPath $xpath, \DOMElement $input ) {
		$id = $input->getAttribute( 'id' );
		if ( $id ) {
			$labels = $xpath->query( '//label[@for="' . $this->xpath_literal_escape( $id ) . '"]' );
			if ( $labels->length > 0 ) {
				$text = HtmlDocument::text( $labels->item( 0 ) );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}
		return trim( $input->getAttribute( 'value' ) );
	}

	/**
	 * @param string $slug e.g. "attribute_pa_color", "product-size".
	 * @return string e.g. "Color", "Product size".
	 */
	private function humanize( $slug ) {
		$slug = preg_replace( '/^attribute[_-]?/i', '', $slug );
		$slug = str_replace( array( '_', '-', '[]' ), ' ', $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', $slug ) );
		return $slug ? mb_convert_case( $slug, MB_CASE_TITLE ) : '';
	}

	/**
	 * @param string $value
	 * @return string Value safe to embed in an XPath string literal (handles embedded quotes).
	 */
	private function xpath_literal_escape( $value ) {
		if ( false === strpos( $value, '"' ) ) {
			return $value;
		}
		return str_replace( '"', '', $value );
	}
}
