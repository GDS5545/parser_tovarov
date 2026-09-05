<?php
/**
 * Matches a normalized ProductAttribute against the store's existing
 * global WooCommerce attributes before creating a new one (spec §5–6),
 * and ensures the value exists as a term on that attribute's taxonomy.
 *
 * Like CategoryResolver, every normalized attribute slug is checked
 * against wp_uws_mappings first (spec §10, §46): the first time a slug is
 * seen, an identity mapping row is auto-created so a merchant can later
 * rename its display label or set it to "skip" (the attribute is dropped
 * from every future import without touching products already created).
 * Attribute mappings are scoped globally, not per-domain — a WooCommerce
 * global attribute is store-wide by definition.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

use Uws\Database\MappingRepository;
use Uws\Dto\ProductAttribute;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AttributeResolver {

	/** @var MappingRepository */
	private $mappings;

	public function __construct( MappingRepository $mappings = null ) {
		$this->mappings = $mappings ?: new MappingRepository();
	}

	/**
	 * @param ProductAttribute $attribute Already run through AttributeNormalizer.
	 * @return array{taxonomy:string, attribute_id:int, term_id:int}|null Null
	 *         if the attribute's mapping says "skip", or if taxonomy/term
	 *         creation failed.
	 */
	public function resolve( ProductAttribute $attribute ) {
		$mapping = $this->mappings->get_or_create(
			MappingRepository::TYPE_ATTRIBUTE,
			'global',
			$attribute->attribute_name,
			$attribute->attribute_key
		);

		if ( MappingRepository::ACTION_SKIP === $mapping->action ) {
			return null;
		}

		$slug  = $mapping->target_key ?: $attribute->attribute_name;
		$label = $mapping->target_label ?: $attribute->attribute_key;

		$taxonomy = $this->ensure_taxonomy( $slug, $label );
		if ( ! $taxonomy ) {
			return null;
		}

		$term_id = $this->ensure_term( $taxonomy['taxonomy'], $attribute->value_normalized ?: $attribute->value_raw );
		if ( ! $term_id ) {
			return null;
		}

		return array(
			'taxonomy'     => $taxonomy['taxonomy'],
			'attribute_id' => $taxonomy['attribute_id'],
			'term_id'      => $term_id,
		);
	}

	/**
	 * @param string $slug  Normalized attribute_name, e.g. "voltage".
	 * @param string $label Human label to use if a new attribute must be created.
	 * @return array{taxonomy:string, attribute_id:int}|null
	 */
	private function ensure_taxonomy( $slug, $label ) {
		if ( ! function_exists( 'wc_attribute_taxonomy_name' ) ) {
			return null;
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );

		$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
		if ( $attribute_id ) {
			return array( 'taxonomy' => $taxonomy, 'attribute_id' => (int) $attribute_id );
		}

		$created = wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $created ) ) {
			return null;
		}

		// wc_create_attribute() doesn't register the taxonomy for the
		// current request; do it now so wp_insert_term()/wp_set_object_terms()
		// below can use it immediately.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				'product',
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}

		return array( 'taxonomy' => $taxonomy, 'attribute_id' => (int) $created );
	}

	/**
	 * @param string $taxonomy
	 * @param string $value
	 * @return int|null Term ID.
	 */
	private function ensure_term( $taxonomy, $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$existing = get_term_by( 'name', $value, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$inserted = wp_insert_term( $value, $taxonomy );
		if ( is_wp_error( $inserted ) ) {
			return null;
		}

		return (int) $inserted['term_id'];
	}
}
