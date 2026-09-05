<?php
/**
 * Creates or updates a WooCommerce product from a ProductData DTO, using
 * WooCommerce's own CRUD (`WC_Product_Simple`/`WC_Product_Variable`,
 * `WC_Product_Attribute`) rather than writing to wp_posts/wp_postmeta
 * directly (spec §50).
 *
 * Variable products (spec §9): when the extraction pipeline detected
 * variation option groups (VariationExtractor sets $data->product_type =
 * "variable" and flags the relevant ProductAttribute rows
 * is_variation = true), this importer creates a real WC_Product_Variable
 * with those attributes marked for variations. It deliberately does NOT
 * synthesize a full price/SKU/stock matrix per variation combination —
 * that data is essentially never present in a page's initial server-
 * rendered HTML (stores load it via AJAX once a shopper picks options),
 * so fabricating it would mean inventing numbers. Instead the result
 * carries a warning telling the merchant to use WooCommerce's own
 * "Generate variations" button and fill in price/SKU/stock per
 * combination — a real variable product ready for that step, not a fake
 * one pretending to be fully imported.
 *
 * Synchronization (spec §27, §72-73): when re-importing into an existing
 * product, each update-policy toggle (update_title/update_description/
 * update_price/update_stock/update_categories/update_attributes/
 * update_images) independently gates whether that field group is touched
 * at all, and — for the fields ImportSnapshot tracks (title, description,
 * short_description, price, stock) — "protect manual edits" additionally
 * skips a field the merchant changed by hand in WooCommerce since the
 * last import, rather than clobbering it with a fresh scrape.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

use Uws\Dto\ProductData;
use Uws\Normalizer\AttributeNormalizer;
use Uws\Normalizer\PriceParser;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
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

	/** @var ImportSnapshot */
	private $snapshot;

	public function __construct( CategoryResolver $categories = null, AttributeResolver $attributes = null, ImageImporter $images = null, ImportSnapshot $snapshot = null ) {
		$this->categories = $categories ?: new CategoryResolver();
		$this->attributes = $attributes ?: new AttributeResolver();
		$this->images     = $images ?: new ImageImporter();
		$this->snapshot   = $snapshot ?: new ImportSnapshot();
	}

	/**
	 * @param ProductData $data
	 * @param array{product_id?:int, status?:string, image_policy?:string, normalization_mode?:string,
	 *              protect_manual_edits?:bool, update_title?:bool, update_description?:bool,
	 *              update_price?:bool, update_stock?:bool, update_categories?:bool,
	 *              update_attributes?:bool, update_images?:bool} $args
	 * @return array{product_id:int, warnings:string[]}|WP_Error
	 */
	public function import( ProductData $data, array $args = array() ) {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'uws_woocommerce_missing', __( 'WooCommerce is not active.', 'universal-woo-scraper' ) );
		}

		$warnings       = array();
		$product_id     = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
		$is_new         = 0 === $product_id;
		$wants_variable = 'variable' === $data->product_type;

		$product = $is_new ? null : wc_get_product( $product_id );
		if ( ! $product ) {
			$product = $wants_variable ? new WC_Product_Variable() : new WC_Product_Simple();
			$is_new  = true;
		} elseif ( $wants_variable !== ( $product instanceof WC_Product_Variable ) ) {
			$warnings[] = __( 'This product already exists as a different product type (simple vs. variable) than what was just detected; the existing type was kept rather than converting it automatically.', 'universal-woo-scraper' );
		}

		$is_variable = $product instanceof WC_Product_Variable;
		$snapshot    = $is_new ? array() : $this->snapshot->read( $product_id );

		$this->apply_basic_fields( $product, $data, $args, $snapshot, $warnings );
		$this->apply_price( $product, $data, $args, $snapshot, $is_variable, $warnings );

		if ( $this->policy_enabled( $args, 'update_categories' ) ) {
			$domain = wp_parse_url( $data->source_url, PHP_URL_HOST ) ?: 'global';
			$this->apply_categories( $product, $data, $domain );
		}

		$variation_attribute_labels = array();
		if ( $this->policy_enabled( $args, 'update_attributes' ) ) {
			$variation_attribute_labels = $this->apply_attributes( $product, $data, $args, $is_variable );
		}

		$product_id = $product->save();

		if ( $this->policy_enabled( $args, 'update_images' ) ) {
			$this->apply_images( $product, $data, $args, $warnings );
		}

		$this->snapshot->write( wc_get_product( $product_id ) );

		if ( $is_variable && ! empty( $variation_attribute_labels ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated list of attribute labels, e.g. "Color, Size" */
				__( 'Variable product created with variation attribute(s): %s. Per-variation price/SKU/stock could not be reliably extracted from the page (this usually requires simulating each option combination via AJAX). Open this product in WooCommerce, use "Generate variations", then fill in price/SKU/stock for each combination.', 'universal-woo-scraper' ),
				implode( ', ', $variation_attribute_labels )
			);
		} elseif ( $wants_variable && ! $is_variable ) {
			$warnings[] = __( 'Source page looked like a variable product but no usable variation attributes could be extracted; imported the shared fields as-is.', 'universal-woo-scraper' );
		}

		return array( 'product_id' => $product_id, 'warnings' => $warnings );
	}

	/**
	 * @param array<string,mixed> $args
	 * @param string              $key
	 * @return bool Whether this field group's update-policy toggle allows touching it (default true if unset).
	 */
	private function policy_enabled( array $args, $key ) {
		return ! array_key_exists( $key, $args ) || ! empty( $args[ $key ] );
	}

	/**
	 * Gate for one ImportSnapshot-tracked field: false if its update-policy
	 * toggle is off, or if "protect manual edits" is on and this field
	 * differs from the last known-imported snapshot (someone changed it
	 * by hand since).
	 *
	 * @param array<string,mixed> $args
	 * @param string              $policy_key
	 * @param array<string,mixed> $snapshot
	 * @param \WC_Product         $product
	 * @param string              $field
	 * @param string|null         $label For the "skipped" warning; pass null to skip silently.
	 * @param string[]            $warnings
	 * @return bool
	 */
	private function should_apply( array $args, $policy_key, array $snapshot, \WC_Product $product, $field, $label, array &$warnings ) {
		if ( ! $this->policy_enabled( $args, $policy_key ) ) {
			return false;
		}
		if ( empty( $snapshot ) ) {
			return true; // New product, or never tracked before — nothing to protect against yet.
		}
		if ( ! empty( $args['protect_manual_edits'] ) && $this->snapshot->was_manually_edited( $snapshot, $product, $field ) ) {
			if ( $label ) {
				$warnings[] = sprintf(
					/* translators: %s: field label, e.g. "description" */
					__( 'Skipped updating %s: it was manually edited in WooCommerce since the last import.', 'universal-woo-scraper' ),
					$label
				);
			}
			return false;
		}
		return true;
	}

	private function apply_basic_fields( \WC_Product $product, ProductData $data, array $args, array $snapshot, array &$warnings ) {
		if ( $data->name && $this->should_apply( $args, 'update_title', $snapshot, $product, 'name', __( 'title', 'universal-woo-scraper' ), $warnings ) ) {
			$product->set_name( wp_strip_all_tags( $data->name ) );
		}
		if ( $data->description && $this->should_apply( $args, 'update_description', $snapshot, $product, 'description', __( 'description', 'universal-woo-scraper' ), $warnings ) ) {
			$product->set_description( wp_kses_post( $data->description ) );
		}
		if ( $data->short_description && $this->should_apply( $args, 'update_description', $snapshot, $product, 'short_description', null, $warnings ) ) {
			$product->set_short_description( wp_kses_post( $data->short_description ) );
		}
		if ( $data->sku && ! $this->sku_taken_by_other_product( $data->sku, $product->get_id() ) ) {
			$product->set_sku( $data->sku );
		}

		$status = isset( $args['status'] ) ? $args['status'] : 'draft';
		$product->set_status( in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : 'draft' );
		$product->set_catalog_visibility( 'visible' );

		if ( $data->stock_status && $this->should_apply( $args, 'update_stock', $snapshot, $product, 'stock_status', __( 'stock status', 'universal-woo-scraper' ), $warnings ) ) {
			$product->set_manage_stock( false );
			$product->set_stock_status( $data->stock_status );
		}
		if ( null !== $data->stock_quantity && $this->should_apply( $args, 'update_stock', $snapshot, $product, 'stock_quantity', __( 'stock quantity', 'universal-woo-scraper' ), $warnings ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $data->stock_quantity );
		}

		if ( $data->source_url ) {
			$product->update_meta_data( '_uws_source_url', $data->source_url );
		}
	}

	private function apply_price( \WC_Product $product, ProductData $data, array $args, array $snapshot, $is_variable, array &$warnings ) {
		if ( $is_variable ) {
			if ( $data->regular_price ) {
				$warnings[] = __( 'Source page has a price, but WooCommerce prices variable products per-variation, not on the parent; it was not applied here — set it per variation after generating them.', 'universal-woo-scraper' );
			}
			return;
		}

		$store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

		if ( $data->regular_price && $this->should_apply( $args, 'update_price', $snapshot, $product, 'regular_price', __( 'regular price', 'universal-woo-scraper' ), $warnings ) ) {
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

		if ( $data->sale_price && $this->should_apply( $args, 'update_price', $snapshot, $product, 'sale_price', null, $warnings ) ) {
			$parsed = PriceParser::parse( (string) $data->sale_price );
			if ( null !== $parsed['amount'] ) {
				$product->set_sale_price( (string) $parsed['amount'] );
			}
		}
	}

	private function apply_categories( \WC_Product $product, ProductData $data, $domain ) {
		if ( empty( $data->categories ) ) {
			return;
		}
		$term_ids = $this->categories->resolve( $data->categories, $domain );
		if ( ! empty( $term_ids ) ) {
			$product->set_category_ids( $term_ids );
		}
	}

	/**
	 * @param \WC_Product $product
	 * @param ProductData $data
	 * @param array       $args
	 * @param bool        $is_variable
	 * @return string[] Display labels of attributes marked for variation (empty if none/not variable).
	 */
	private function apply_attributes( \WC_Product $product, ProductData $data, array $args, $is_variable ) {
		if ( empty( $data->attributes ) ) {
			return array();
		}

		$mode       = isset( $args['normalization_mode'] ) ? $args['normalization_mode'] : 'smart';
		$normalizer = new AttributeNormalizer( $mode );

		$grouped = array();
		foreach ( $data->attributes as $attribute ) {
			$normalizer->normalize( $attribute );
			$resolved = $this->attributes->resolve( $attribute );
			if ( ! $resolved ) {
				continue; // Mapped to "skip", or taxonomy/term creation failed silently (rare; not worth a warning per attribute).
			}
			$taxonomy = $resolved['taxonomy'];
			if ( ! isset( $grouped[ $taxonomy ] ) ) {
				$grouped[ $taxonomy ] = array( 'attribute_id' => $resolved['attribute_id'], 'term_ids' => array(), 'label' => $attribute->attribute_key, 'is_variation' => false );
			}
			$grouped[ $taxonomy ]['term_ids'][] = $resolved['term_id'];
			if ( $attribute->is_variation ) {
				$grouped[ $taxonomy ]['is_variation'] = true;
			}
		}

		$wc_attributes              = array();
		$variation_attribute_labels = array();

		foreach ( $grouped as $taxonomy => $entry ) {
			$mark_as_variation = $is_variable && $entry['is_variation'];

			$wc_attribute = new WC_Product_Attribute();
			$wc_attribute->set_id( $entry['attribute_id'] );
			$wc_attribute->set_name( $taxonomy );
			$wc_attribute->set_options( array_unique( $entry['term_ids'] ) );
			$wc_attribute->set_visible( true );
			$wc_attribute->set_variation( $mark_as_variation );
			$wc_attributes[] = $wc_attribute;

			if ( $mark_as_variation ) {
				$variation_attribute_labels[] = $entry['label'];
			}
		}

		if ( $wc_attributes ) {
			$product->set_attributes( $wc_attributes );
		}

		return $variation_attribute_labels;
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
