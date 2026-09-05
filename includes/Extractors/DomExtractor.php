<?php
/**
 * Last-resort semantic/heuristic DOM reading: page <h1> for the name,
 * text near a "price"-classed element for price, and stock keywords
 * (spec §21 step 6). Lowest priority of the non-AI extractors — it only
 * fills what JSON-LD/meta/specifications left blank.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Normalizer\PriceParser;
use Uws\Support\HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DomExtractor implements ProductExtractorInterface {

	public function get_name() {
		return 'dom';
	}

	public function get_priority() {
		return 40;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$data  = new ProductData();
		$xpath = HtmlDocument::xpath( (string) $page['html'] );

		$this->extract_name( $data, $xpath );
		$this->extract_prices( $data, $xpath );
		$this->extract_stock_status( $data, $xpath );

		return $data;
	}

	private function extract_name( ProductData $data, \DOMXPath $xpath ) {
		$nodes = $xpath->query( '//h1' );
		if ( $nodes->length > 0 ) {
			$text = HtmlDocument::text( $nodes->item( 0 ) );
			if ( '' !== $text ) {
				$data->set_field( 'name', $text, 0.5 );
			}
		}
	}

	private function extract_prices( ProductData $data, \DOMXPath $xpath ) {
		$nodes = $xpath->query( "//*[contains(translate(@class,'PRICE','price'),'price')]" );

		$amounts = array();
		$currency = null;

		foreach ( $nodes as $node ) {
			$text = HtmlDocument::text( $node );
			if ( '' === $text || mb_strlen( $text ) > 40 ) {
				continue; // Skip nodes that are containers, not the price text itself.
			}
			$parsed = PriceParser::parse( $text );
			if ( null !== $parsed['amount'] ) {
				$amounts[] = $parsed['amount'];
			}
			if ( null !== $parsed['currency'] && null === $currency ) {
				$currency = $parsed['currency'];
			}
		}

		if ( empty( $amounts ) ) {
			return;
		}

		$amounts = array_values( array_unique( $amounts ) );
		sort( $amounts );

		if ( count( $amounts ) >= 2 ) {
			// Two differing prices on a product page are almost always
			// (regular, sale); the lower one is the current sale price.
			$data->set_field( 'regular_price', (string) end( $amounts ), 0.4 );
			$data->set_field( 'sale_price', (string) $amounts[0], 0.4 );
		} else {
			$data->set_field( 'regular_price', (string) $amounts[0], 0.4 );
		}

		if ( $currency ) {
			$data->set_field( 'currency', $currency, 0.4 );
		}
	}

	private function extract_stock_status( ProductData $data, \DOMXPath $xpath ) {
		$body_nodes = $xpath->query( '//body' );
		if ( 0 === $body_nodes->length ) {
			return;
		}

		$text = mb_strtolower( HtmlDocument::text( $body_nodes->item( 0 ) ) );

		$out_of_stock_phrases = array( 'out of stock', 'sold out', 'нет в наличии', 'нет в наличие', 'товар закончился' );
		foreach ( $out_of_stock_phrases as $phrase ) {
			if ( false !== mb_strpos( $text, $phrase ) ) {
				$data->set_field( 'stock_status', 'outofstock', 0.3 );
				return;
			}
		}

		$in_stock_phrases = array( 'in stock', 'в наличии', 'есть в наличии' );
		foreach ( $in_stock_phrases as $phrase ) {
			if ( false !== mb_strpos( $text, $phrase ) ) {
				$data->set_field( 'stock_status', 'instock', 0.3 );
				return;
			}
		}
	}
}
