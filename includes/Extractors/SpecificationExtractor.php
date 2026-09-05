<?php
/**
 * Reads specification/characteristic tables: HTML <table>s and <dl>/<dt>/<dd>
 * blocks (spec §4). Populates $data->attributes only — the merger appends
 * every extractor's attributes rather than letting one overwrite another,
 * since a page legitimately has many independent specs.
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

class SpecificationExtractor implements ProductExtractorInterface {

	/** Skip rows whose value looks like another table caption/heading, not real prose. */
	const MAX_KEY_LENGTH   = 80;
	const MAX_VALUE_LENGTH = 300;

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
		$data  = new ProductData();
		$xpath = HtmlDocument::xpath( (string) $page['html'] );

		$this->extract_tables( $data, $xpath );
		$this->extract_definition_lists( $data, $xpath );

		return $data;
	}

	private function extract_tables( ProductData $data, \DOMXPath $xpath ) {
		foreach ( $xpath->query( '//table' ) as $table ) {
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

	private function extract_definition_lists( ProductData $data, \DOMXPath $xpath ) {
		foreach ( $xpath->query( '//dl' ) as $list ) {
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
