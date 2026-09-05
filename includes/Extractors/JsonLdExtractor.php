<?php
/**
 * Reads schema.org Product/Offer JSON-LD blocks. This is the
 * highest-priority extractor (spec §21: JSON-LD first) because a site that
 * publishes structured data is asserting it directly, rather than us
 * guessing from rendered markup.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductAttribute;
use Uws\Dto\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsonLdExtractor implements ProductExtractorInterface {

	public function get_name() {
		return 'json_ld';
	}

	public function get_priority() {
		return 100;
	}

	public function supports( array $page ) {
		return ! empty( $page['json_ld_blocks'] ) && is_array( $page['json_ld_blocks'] );
	}

	public function extract( array $page ) {
		$data = new ProductData();

		foreach ( $page['json_ld_blocks'] as $raw ) {
			$decoded = json_decode( trim( (string) $raw ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $this->flatten_products( $decoded ) as $product ) {
				$this->apply_product( $data, $product );
			}
		}

		return $data;
	}

	/**
	 * Walks @graph arrays and top-level arrays to find every node whose
	 * @type is (or includes) "Product".
	 *
	 * @param array<string,mixed> $decoded
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten_products( array $decoded ) {
		$nodes = array();

		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			foreach ( $decoded['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
		} elseif ( $this->is_list( $decoded ) ) {
			foreach ( $decoded as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
		} else {
			$nodes[] = $decoded;
		}

		return array_values(
			array_filter(
				$nodes,
				function ( $node ) {
					return $this->type_includes( $node, 'Product' );
				}
			)
		);
	}

	/**
	 * @param array<string,mixed> $node
	 * @param string              $type
	 * @return bool
	 */
	private function type_includes( array $node, $type ) {
		if ( empty( $node['@type'] ) ) {
			return false;
		}
		$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
		return in_array( $type, $types, true );
	}

	/**
	 * @param array<int|string,mixed> $array
	 * @return bool
	 */
	private function is_list( array $array ) {
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * @param ProductData          $data
	 * @param array<string,mixed>  $product
	 */
	private function apply_product( ProductData $data, array $product ) {
		if ( ! empty( $product['name'] ) ) {
			$data->set_field( 'name', wp_strip_all_tags( (string) $product['name'] ), 0.98 );
		}
		if ( ! empty( $product['description'] ) ) {
			$data->set_field( 'description', (string) $product['description'], 0.9 );
		}
		if ( ! empty( $product['sku'] ) ) {
			$data->set_field( 'sku', (string) $product['sku'], 0.95 );
		}
		if ( ! empty( $product['mpn'] ) ) {
			$data->set_field( 'mpn', (string) $product['mpn'], 0.9 );
		}

		foreach ( array( 'gtin13', 'gtin12', 'gtin8', 'gtin14', 'gtin' ) as $gtin_field ) {
			if ( ! empty( $product[ $gtin_field ] ) ) {
				$data->set_field( 'gtin', (string) $product[ $gtin_field ], 0.9 );
				break;
			}
		}

		if ( ! empty( $product['brand'] ) ) {
			$brand = is_array( $product['brand'] ) ? ( $product['brand']['name'] ?? '' ) : $product['brand'];
			if ( $brand ) {
				$data->set_field( 'brand', (string) $brand, 0.9 );
			}
		}

		$this->apply_offers( $data, $product );
		$this->apply_images( $data, $product );

		if ( ! empty( $product['category'] ) ) {
			$categories = is_array( $product['category'] ) ? $product['category'] : explode( '>', (string) $product['category'] );
			$data->categories = array_values( array_filter( array_map( 'trim', array_map( 'strval', $categories ) ) ) );
			$data->confidence['categories'] = 0.7;
		}

		if ( ! empty( $product['additionalProperty'] ) && is_array( $product['additionalProperty'] ) ) {
			foreach ( $product['additionalProperty'] as $property ) {
				if ( empty( $property['name'] ) || ! isset( $property['value'] ) ) {
					continue;
				}
				$attribute            = new ProductAttribute( (string) $property['name'], (string) $property['value'], $this->get_name() );
				$attribute->confidence = 0.85;
				$data->attributes[]    = $attribute;
			}
		}
	}

	/**
	 * @param ProductData         $data
	 * @param array<string,mixed> $product
	 */
	private function apply_offers( ProductData $data, array $product ) {
		if ( empty( $product['offers'] ) ) {
			return;
		}

		$offers = $product['offers'];
		$offer  = $this->is_list( $offers ) ? ( $offers[0] ?? array() ) : $offers;

		if ( ! is_array( $offer ) ) {
			return;
		}

		if ( isset( $offer['price'] ) && '' !== $offer['price'] ) {
			$data->set_field( 'regular_price', (string) $offer['price'], 0.95 );
		}
		if ( ! empty( $offer['priceCurrency'] ) ) {
			$data->set_field( 'currency', strtoupper( (string) $offer['priceCurrency'] ), 0.95 );
		}
		if ( ! empty( $offer['availability'] ) ) {
			$availability = strtolower( (string) $offer['availability'] );
			if ( false !== strpos( $availability, 'instock' ) ) {
				$data->set_field( 'stock_status', 'instock', 0.9 );
			} elseif ( false !== strpos( $availability, 'outofstock' ) ) {
				$data->set_field( 'stock_status', 'outofstock', 0.9 );
			} elseif ( false !== strpos( $availability, 'backorder' ) ) {
				$data->set_field( 'stock_status', 'onbackorder', 0.9 );
			}
		}
		if ( ! empty( $offer['sku'] ) && empty( $data->sku ) ) {
			$data->set_field( 'sku', (string) $offer['sku'], 0.9 );
		}
	}

	/**
	 * @param ProductData         $data
	 * @param array<string,mixed> $product
	 */
	private function apply_images( ProductData $data, array $product ) {
		if ( empty( $product['image'] ) ) {
			return;
		}

		$images = $product['image'];
		$images = is_array( $images ) && ! isset( $images['url'] ) ? $images : array( $images );

		foreach ( $images as $image ) {
			$url = is_array( $image ) ? ( $image['url'] ?? '' ) : $image;
			if ( $url ) {
				$data->images[] = array( 'url' => (string) $url, 'is_main' => empty( $data->images ), 'variation_key' => null );
			}
		}
	}
}
