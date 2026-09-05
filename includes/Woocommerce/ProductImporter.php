<?php
/**
 * Creates or updates a WooCommerce simple product from a ProductData DTO,
 * using WooCommerce's own CRUD (`WC_Product_Simple`, `WC_Product_Attribute`)
 * rather than writing to wp_posts/wp_postmeta directly (spec §50).
 *
 * Variable products (spec §9) are Stage 9 work: if $data->product_type is
 * "variable" this importer still creates a simple product from the shared
 * fields and reports that fact as a warning rather than silently dropping
 * the variations or crashing.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

use Uws\Dto\ProductData;
use Uws\Normalizer\AttributeNormalizer;
use Uws\Normalizer\PriceParser;
use WC_Product_Attribute;
use WC_Product_Simple;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductImporter {

	/** @var CategoryResolver */
	private $categories;

	/** @var AttributeResolver */
	private $attributes;

	/** @var ImageImporter */
	private $images;

	public function __construct( CategoryResolver $categories = null, AttributeResolver $attributes = null, ImageImporter $images = null ) {
		$this->categories = $categories ?: new CategoryResolver();
		$this->attributes = $attributes ?: new AttributeResolver();
		$this->images     = $images ?: new ImageImporter();
	}

	/**
	 * @param ProductData $data
	 * @param array{product_id?:int, status?:string, image_policy?:string, normalization_mode?:string} $args
	 * @return array{product_id:int, warnings:string[]}|WP_Error
	 */
	public function import( ProductData $data, array $args = array() ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'uws_woocommerce_missing', __( 'WooCommerce is not active.', 'universal-woo-scraper' ) );
		}

		$warnings   = array();
		$product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;

		$product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		if ( 'variable' === $data->product_type && ! empty( $data->variations ) ) {
			$warnings[] = __( 'Source page has variations, but variable-product import is Stage 9 (not built yet); imported as a simple product using the base fields only.', 'universal-woo-scraper' );
		}

		$this->apply_basic_fields( $product, $data, $args );
		$this->apply_price( $product, $data, $warnings );
		$this->apply_categories( $product, $data );
		$this->apply_attributes( $product, $data, $args, $warnings );

		$product_id = $product->save();

		$this->apply_images( $product, $data, $args, $warnings );

		return array( 'product_id' => $product_id, 'warnings' => $warnings );
	}

	private function apply_basic_fields( \WC_Product $product, ProductData $data, array $args ) {
		if ( $data->name ) {
			$product->set_name( wp_strip_all_tags( $data->name ) );
		}
		if ( $data->description ) {
			$product->set_description( wp_kses_post( $data->description ) );
		}
		if ( $data->short_description ) {
			$product->set_short_description( wp_kses_post( $data->short_description ) );
		}
		if ( $data->sku && ! $this->sku_taken_by_other_product( $data->sku, $product->get_id() ) ) {
			$product->set_sku( $data->sku );
		}

		$status = isset( $args['status'] ) ? $args['status'] : 'draft';
		$product->set_status( in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft' );
		$product->set_catalog_visibility( 'visible' );

		if ( $data->stock_status ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( $data->stock_status );
		}
		if ( null !== $data->stock_quantity ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $data->stock_quantity );
		}

		if ( $data->source_url ) {
			$product->update_meta_data( '_uws_source_url', $data->source_url );
		}
	}

	private function apply_price( \WC_Product $product, ProductData $data, array &$warnings ) {
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

		if ( $data->regular_price ) {
			$parsed = PriceParser::parse( (string) $data->regular_price );
			if ( null !== $parsed['amount'] ) {
				$product->set_regular_price( (string) $parsed['amount'] );
			}
			if ( $parsed['currency'] && $store_currency && $parsed['currency'] !== $store_currency ) {
				$warnings[] = sprintf(
					/* translators: 1: scraped currency, 2: store currency */
					__( 'Source price is in %1$s but the store currency is %2$s; the numeric amount was imported as-is, with no conversion applied.', 'universal-woo-scraper' ),
					$parsed['currency'],
					$store_currency
				);
			}
		}

		if ( $data->sale_price ) {
			$parsed = PriceParser::parse( (string) $data->sale_price );
			if ( null !== $parsed['amount'] ) {
				$product->set_sale_price( (string) $parsed['amount'] );
			}
		}
	}

	private function apply_categories( \WC_Product $product, ProductData $data ) {
		if ( empty( $data->categories ) ) {
			return;
		}
		$term_ids = $this->categories->resolve( $data->categories );
		if ( ! empty( $term_ids ) ) {
			$product->set_category_ids( $term_ids );
		}
	}

	private function apply_attributes( \WC_Product $product, ProductData $data, array $args, array &$warnings ) {
		if ( empty( $data->attributes ) ) {
			return;
		}

		$mode       = isset( $args['normalization_mode'] ) ? $args['normalization_mode'] : 'smart';
		$normalizer = new AttributeNormalizer( $mode );

		$grouped = array();
		foreach ( $data->attributes as $attribute ) {
			$normalizer->normalize( $attribute );
			$resolved = $this->attributes->resolve( $attribute );
			if ( ! $resolved ) {
				$warnings[] = sprintf(
					/* translators: %s: attribute label */
					__( 'Could not create or match a WooCommerce attribute for "%s".', 'universal-woo-scraper' ),
					$attribute->attribute_key
				);
				continue;
			}
			$grouped[ $resolved['taxonomy'] ]['attribute_id'] = $resolved['attribute_id'];
			$grouped[ $resolved['taxonomy'] ]['term_ids'][]    = $resolved['term_id'];
		}

		$wc_attributes = array();
		foreach ( $grouped as $taxonomy => $entry ) {
			$wc_attribute = new WC_Product_Attribute();
			$wc_attribute->set_id( $entry['attribute_id'] );
			$wc_attribute->set_name( $taxonomy );
			$wc_attribute->set_options( array_unique( $entry['term_ids'] ) );
			$wc_attribute->set_visible( true );
			$wc_attribute->set_variation( false );
			$wc_attributes[] = $wc_attribute;
		}

		if ( $wc_attributes ) {
			$product->set_attributes( $wc_attributes );
		}
	}

	private function apply_images( \WC_Product $product, ProductData $data, array $args, array &$warnings ) {
		if ( empty( $data->images ) ) {
			return;
		}

		$policy = isset( $args['image_policy'] ) ? $args['image_policy'] : 'download';
		if ( 'remote' === $policy ) {
			$warnings[] = __( 'Image policy is set to "remote"; images were not downloaded (WooCommerce products need a local attachment, so no image was set).', 'universal-woo-scraper' );
			return;
		}

		$images = 'main_only' === $policy ? array_slice( $data->images, 0, 1 ) : $data->images;

		$attachment_ids = array();
		foreach ( $images as $image ) {
			$attachment_id = $this->images->import( $image['url'], $product->get_id() );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			} else {
				$warnings[] = sprintf(
					/* translators: %s: image URL */
					__( 'Could not download image: %s', 'universal-woo-scraper' ),
					$image['url']
				);
			}
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$product->set_image_id( array_shift( $attachment_ids ) );
		if ( $attachment_ids ) {
			$product->set_gallery_image_ids( $attachment_ids );
		}
		$product->save();
	}

	/**
	 * @param string $sku
	 * @param int    $ignore_product_id
	 * @return bool
	 */
	private function sku_taken_by_other_product( $sku, $ignore_product_id ) {
		$existing_id = wc_get_product_id_by_sku( $sku );
		return $existing_id && (int) $existing_id !== (int) $ignore_product_id;
	}
}
