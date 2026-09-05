<?php
/**
 * Turns a breadcrumb path (e.g. ["Equipment", "Pumps", "Water Pumps"]) into
 * WooCommerce `product_cat` term IDs, creating any missing level in the
 * hierarchy (spec §9).
 *
 * Every source label is checked against wp_uws_mappings first (spec §10):
 * the first time a label is seen for a given domain, an identity mapping
 * row is auto-created (target == source, action = map) so it appears on
 * the Mappings admin page for review; a merchant can then rename the
 * target category or set the row to "skip" (drop that level from every
 * future import of that domain) without touching already-imported
 * products, since the mapping is only consulted going forward.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

use Uws\Database\MappingRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CategoryResolver {

	/** @var MappingRepository */
	private $mappings;

	public function __construct( MappingRepository $mappings = null ) {
		$this->mappings = $mappings ?: new MappingRepository();
	}

	/**
	 * @param string[] $path   Root-first breadcrumb, e.g. ['Equipment', 'Pumps'].
	 * @param string   $domain Source domain, used to scope the mapping lookup
	 *                         (the same category name can map differently
	 *                         per site).
	 * @return int[] Term IDs for every level (ancestors included), leaf last.
	 */
	public function resolve( array $path, $domain = 'global' ) {
		$term_ids = array();
		$parent   = 0;

		foreach ( $path as $label ) {
			$label = trim( $label );
			if ( '' === $label ) {
				continue;
			}

			$mapping = $this->mappings->get_or_create(
				MappingRepository::TYPE_CATEGORY,
				$domain,
				sanitize_title( $label ),
				$label
			);

			if ( MappingRepository::ACTION_SKIP === $mapping->action ) {
				continue; // Drop this level; the next label attaches to the current parent instead.
			}

			$target_label = $mapping->target_label ?: $label;

			$term = $this->find_or_create( $target_label, $parent );
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
