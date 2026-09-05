<?php
/**
 * Reads specification/characteristic tables: HTML <table>s and <dl>/<dt>/<dd>
 * blocks (spec §4). Populates $data->attributes only — the merger appends
 * every extractor's attributes rather than letting one overwrite another,
 * since a page legitimately has many independent specs.
 *
 * Real-site testing found this scanning the *whole* page picks up more
 * than the product's own specs: a "why choose us" marketing section (e.g.
 * "3D modeling of designed objects", "own production of reagents") is
 * often marked up as exactly the same two-column table/dl shape a real
 * specification table uses, just elsewhere on the page. So, like
 * ImageExtractor, this first looks for a container matching common
 * specification/characteristics naming conventions and, if one exists and
 * yields at least one attribute, uses ONLY that container; otherwise it
 * falls back to the previous whole-page scan.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductAttribute;
use Uws\Dto\ProductData;
use Uws\Support\ContainerFinder;
use Uws\Support\HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SpecificationExtractor implements ProductExtractorInterface {

	/** Skip rows whose value looks like another table caption/heading, not real prose. */
	const MAX_KEY_LENGTH   = 80;
	const MAX_VALUE_LENGTH = 300;

	/**
	 * class/id substrings (case-insensitive) identifying a specification/
	 * characteristics block across common platforms — WooCommerce's own
	 * "Additional information" tab, 1C-Bitrix's "sku_props", generic
	 * "specs"/"characteristics" theme naming.
	 */
	const SPEC_CONTAINER_HINTS = array(
		'additional_information', 'woocommerce-product-attributes', 'product-attributes',
		'specifications', 'specification', 'characteristics', 'sku_props', 'tech-specs',
		'product-specs', 'spec-table', 'params-table',
	);

	public function get_name() {
		return 'specification';
	}

	public function get_priority() {
		return 60;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$xpath = HtmlDocument::xpath( (string) $page['html'] );

		$container = ContainerFinder::find( $xpath, self::SPEC_CONTAINER_HINTS );
		if ( $container ) {
			$scoped = $this->collect( $xpath, $container );
			if ( ! empty( $scoped->attributes ) ) {
				return $scoped;
			}
		}

		return $this->collect( $xpath, null );
	}

	/**
	 * @param \DOMXPath        $xpath
	 * @param \DOMElement|null $scope Restrict the search to this element's
	 *                                descendants, or null for the whole document.
	 * @return ProductData
	 */
	private function collect( \DOMXPath $xpath, $scope ) {
		$data = new ProductData();
		$this->extract_tables( $data, $xpath, $scope );
		$this->extract_definition_lists( $data, $xpath, $scope );
		return $data;
	}

	private function extract_tables( ProductData $data, \DOMXPath $xpath, $scope ) {
		foreach ( $xpath->query( $scope ? './/table' : '//table', $scope ) as $table ) {
			foreach ( $xpath->query( './/tr', $table ) as $row ) {
				$cells = $xpath->query( './th|./td', $row );
				if ( $cells->length < 2 ) {
					continue;
				}

				$key   = HtmlDocument::text( $cells->item( 0 ) );
				$value = HtmlDocument::text( $cells->item( $cells->length - 1 ) );

				$this->maybe_add_attribute( $data, $key, $value );
			}
		}
	}

	private function extract_definition_lists( ProductData $data, \DOMXPath $xpath, $scope ) {
		foreach ( $xpath->query( $scope ? './/dl' : '//dl', $scope ) as $list ) {
			$terms       = $xpath->query( './dt', $list );
			$definitions = $xpath->query( './dd', $list );

			$count = min( $terms->length, $definitions->length );
			for ( $i = 0; $i < $count; $i++ ) {
				$key   = HtmlDocument::text( $terms->item( $i ) );
				$value = HtmlDocument::text( $definitions->item( $i ) );
				$this->maybe_add_attribute( $data, $key, $value );
			}
		}
	}

	private function maybe_add_attribute( ProductData $data, $key, $value ) {
		if ( '' === $key || '' === $value ) {
			return;
		}
		if ( mb_strlen( $key ) > self::MAX_KEY_LENGTH || mb_strlen( $value ) > self::MAX_VALUE_LENGTH ) {
			return;
		}

		$attribute             = new ProductAttribute( $key, $value, $this->get_name() );
		$attribute->confidence = 0.75;
		$data->attributes[]    = $attribute;
	}
}
