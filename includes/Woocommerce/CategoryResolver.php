<?php
/**
 * Turns a breadcrumb path (e.g. ["Equipment", "Pumps", "Water Pumps"]) into
 * WooCommerce `product_cat` term IDs, creating any missing level in the
 * hierarchy (spec §9). Category *mapping* (source label → a differently
 * named WooCommerce category, merge/skip decisions, spec §10) is a
 * separate, persisted step layered on top in Stage 10 via
 * wp_uws_mappings; without a mapping row this resolver just mirrors the
 * source path 1:1; so a source category is never silently dropped.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryResolver {

	/**
	 * @param string[] $path Root-first breadcrumb, e.g. ['Equipment', 'Pumps'].
	 * @return int[] Term IDs for every level (ancestors included), leaf last.
	 */
	public function resolve( array $path ) {
		$term_ids = array();
		$parent   = 0;

		foreach ( $path as $label ) {
			$label = trim( $label );
			if ( '' === $label ) {
				continue;
			}

			$term = $this->find_or_create( $label, $parent );
			if ( ! $term ) {
				continue;
			}

			$term_ids[] = $term['term_id'];
			$parent     = $term['term_id'];
		}

		return $term_ids;
	}

	/**
	 * @param string $label
	 * @param int    $parent
	 * @return array{term_id:int,term_taxonomy_id:int}|null
	 */
	private function find_or_create( $label, $parent ) {
		$existing = get_term_by( 'name', $label, 'product_cat' );
		if ( $existing && (int) $existing->parent === (int) $parent ) {
			return array( 'term_id' => (int) $existing->term_id, 'term_taxonomy_id' => (int) $existing->term_taxonomy_id );
		}

		// A term with this name exists but under a different parent (e.g.
		// two different "Pumps" branches) — WordPress allows same-name
		// terms at different hierarchy levels, so check parent-scoped too.
		$siblings = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name'       => $label,
				'parent'     => $parent,
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $siblings ) && ! empty( $siblings ) ) {
			$term = $siblings[0];
			return array( 'term_id' => (int) $term->term_id, 'term_taxonomy_id' => (int) $term->term_taxonomy_id );
		}

		$inserted = wp_insert_term( $label, 'product_cat', array( 'parent' => $parent ) );
		if ( is_wp_error( $inserted ) ) {
			return null;
		}

		return array( 'term_id' => (int) $inserted['term_id'], 'term_taxonomy_id' => (int) $inserted['term_taxonomy_id'] );
	}
}
