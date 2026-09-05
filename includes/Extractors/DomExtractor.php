<?php
/**
 * Last-resort semantic/heuristic DOM reading: page <h1> for the name,
 * text near a "price"-classed element for price, and stock keywords
 * (spec §21 step 6). Lowest priority of the non-AI extractors — it only
 * fills what JSON-LD/meta/specifications left blank.
 *
 * Price detection first tries to scope its search to a recognized
 * product-info container (mirrors ImageExtractor/SpecificationExtractor):
 * real-site testing showed that scanning every "price"-classed element on
 * the *whole* page picks up unrelated prices from related-product widgets,
 * delivery calculators, etc. elsewhere on the page, and — when exactly 2
 * amounts turn up — wrongly assumes they're (regular, sale) even when
 * they're two unrelated numbers.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Normalizer\PriceParser;
use Uws\Support\ContainerFinder;
use Uws\Support\HtmlDocument;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DomExtractor implements ProductExtractorInterface {

	/**
	 * class/id substrings identifying the main product info block, so the
	 * price search can be scoped there before falling back to the whole page.
	 */
	const PRICE_CONTAINER_HINTS = array(
		'product-summary', 'product-info', 'product-main', 'product-detail',
		'product-price', 'item-price', 'price-block', 'summary',
	);

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
		$container = ContainerFinder::find( $xpath, self::PRICE_CONTAINER_HINTS );

		$found = $container ? $this->find_price_amounts( $xpath, $container ) : array();
		if ( empty( $found['amounts'] ) ) {
			$found = $this->find_price_amounts( $xpath, null );
		}

		if ( empty( $found['amounts'] ) ) {
			return;
		}

		$amounts = array_values( array_unique( $found['amounts'] ) );
		sort( $amounts );

		if ( 2 === count( $amounts ) ) {
			// Exactly two differing amounts near the product info is a
			// reliable (regular, sale) signal; the lower one is the current price.
			$data->set_field( 'regular_price', (string) $amounts[1], 0.4 );
			$data->set_field( 'sale_price', (string) $amounts[0], 0.4 );
		} else {
			// Either a single confident amount, or 3+ (ambiguous — more
			// likely several unrelated numbers than one product's regular+
			// sale price). Either way, only commit to the first one found
			// in document order rather than guessing at a pair.
			$data->set_field( 'regular_price', (string) $found['amounts'][0], count( $amounts ) > 2 ? 0.25 : 0.4 );
		}

		if ( $found['currency'] ) {
			$data->set_field( 'currency', $found['currency'], 0.4 );
		}
	}

	/**
	 * @param \DOMXPath        $xpath
	 * @param \DOMElement|null $scope
	 * @return array{amounts: float[], currency: string|null} $amounts is in document order (not deduped/sorted).
	 */
	private function find_price_amounts( \DOMXPath $xpath, $scope ) {
		$expression = "contains(translate(@class,'PRICE','price'),'price')";
		$nodes      = $xpath->query( $scope ? ".//*[{$expression}]" : "//*[{$expression}]", $scope );

		$amounts  = array();
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

		return array( 'amounts' => $amounts, 'currency' => $currency );
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
