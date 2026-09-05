<?php
/**
 * Records what ProductImporter last wrote into a small set of fields, so a
 * later re-scrape (spec §27, sync) can tell "the merchant edited this by
 * hand in WooCommerce since we last touched it" apart from "this still
 * matches what we imported, safe to refresh" (spec §73: protect manual
 * edits). Stored as one postmeta row per product — deliberately not a
 * full revision history, just the last-known-good snapshot needed for
 * that one comparison.
 *
 * @package Uws\Woocommerce
 */

namespace Uws\Woocommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImportSnapshot {

	const META_KEY = '_uws_import_snapshot';

	/** Fields this snapshot tracks for manual-edit protection. */
	const TRACKED_FIELDS = array( 'name', 'description', 'short_description', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity' );

	/**
	 * @param int $product_id
	 * @return array<string,mixed> Empty array if this product has never been imported before.
	 */
	public function read( $product_id ) {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param \WC_Product $product Freshly saved product; values are read back from it
	 *                              so the snapshot reflects what's actually stored.
	 */
	public function write( \WC_Product $product ) {
		$snapshot = array();
		foreach ( self::TRACKED_FIELDS as $field ) {
			$getter = 'get_' . $field;
			$snapshot[ $field ] = method_exists( $product, $getter ) ? $product->$getter() : null;
		}

		update_post_meta( $product->get_id(), self::META_KEY, wp_json_encode( $snapshot ) );
	}

	/**
	 * @param array<string,mixed> $snapshot From read().
	 * @param \WC_Product         $product  The product as it currently stands in the DB.
	 * @param string              $field
	 * @return bool True if $field differs from the snapshot, i.e. someone
	 *              changed it since the plugin last wrote it (or there is
	 *              no snapshot yet, in which case nothing counts as
	 *              "manually edited").
	 */
	public function was_manually_edited( array $snapshot, \WC_Product $product, $field ) {
		if ( ! array_key_exists( $field, $snapshot ) ) {
			return false;
		}
		$getter = 'get_' . $field;
		if ( ! method_exists( $product, $getter ) ) {
			return false;
		}
		return (string) $snapshot[ $field ] !== (string) $product->$getter();
	}
}
