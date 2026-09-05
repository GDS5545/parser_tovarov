<?php
/**
 * Combines every extractor's partial ProductData into one, honoring each
 * extractor's declared priority (spec §64: "at conflict, JSON-LD price
 * outranks a DOM text scrape of the same price"). Scalar fields are kept
 * only from whichever extractor set them with the highest confidence;
 * attributes and images are additive (every extractor's findings are
 * kept, deduplicated); SEO fields let a later (higher-priority) extractor
 * override an earlier one field-by-field.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductDataMerger {

	/**
	 * @param array<string,mixed>            $page       Raw payload from ScraperEngineInterface::fetch_page().
	 * @param ProductExtractorInterface[]     $extractors
	 * @return ProductData
	 */
	public function merge( array $page, array $extractors ) {
		// Highest priority first: scalar-field conflicts are resolved by
		// confidence regardless of order, but the additive lists (images,
		// seo) are not — processing JSON-LD before a DOM guess means its
		// image is the one that ends up marked "main" and its SEO fields
		// are the ones that survive, matching "JSON-LD outranks DOM" for
		// list-shaped data too, not just scalars.
		usort(
			$extractors,
			function ( ProductExtractorInterface $a, ProductExtractorInterface $b ) {
				return $b->get_priority() <=> $a->get_priority();
			}
		);

		$final = new ProductData();

		foreach ( $extractors as $extractor ) {
			if ( ! $extractor->supports( $page ) ) {
				continue;
			}

			$partial = $extractor->extract( $page );

			$this->merge_scalars( $final, $partial );
			$this->merge_categories( $final, $partial );

			$final->attributes = $this->merge_attributes( $final->attributes, $partial->attributes );
			$final->images     = $this->merge_images( $final->images, $partial->images );
			// Fill only keys not already set by a higher-priority extractor
			// (extractors are processed highest-priority first).
			$final->seo        = array_merge( array_filter( $partial->seo ), $final->seo );
		}

		return $final;
	}

	private function merge_scalars( ProductData $final, ProductData $partial ) {
		foreach ( $partial->confidence as $field => $confidence ) {
			if ( in_array( $field, array( 'categories' ), true ) ) {
				continue; // Handled separately: categories is a list, not a plain scalar.
			}
			$existing_confidence = $final->confidence[ $field ] ?? -1;
			if ( $confidence >= $existing_confidence ) {
				$final->$field               = $partial->$field;
				$final->confidence[ $field ] = $confidence;
			}
		}
	}

	private function merge_categories( ProductData $final, ProductData $partial ) {
		if ( empty( $partial->categories ) ) {
			return;
		}
		$existing_confidence = $final->confidence['categories'] ?? -1;
		$new_confidence       = $partial->confidence['categories'] ?? 0;
		if ( $new_confidence >= $existing_confidence ) {
			$final->categories             = $partial->categories;
			$final->confidence['categories'] = $new_confidence;
		}
	}

	/**
	 * @param \Uws\Dto\ProductAttribute[] $existing
	 * @param \Uws\Dto\ProductAttribute[] $incoming
	 * @return \Uws\Dto\ProductAttribute[]
	 */
	private function merge_attributes( array $existing, array $incoming ) {
		$seen = array();
		foreach ( $existing as $attribute ) {
			$seen[ $this->attribute_dedupe_key( $attribute ) ] = true;
		}

		foreach ( $incoming as $attribute ) {
			$key = $this->attribute_dedupe_key( $attribute );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$existing[]   = $attribute;
		}

		return $existing;
	}

	/**
	 * @param \Uws\Dto\ProductAttribute $attribute
	 * @return string
	 */
	private function attribute_dedupe_key( $attribute ) {
		return mb_strtolower( trim( $attribute->attribute_key ) ) . '|' . mb_strtolower( trim( $attribute->value_raw ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $existing
	 * @param array<int,array<string,mixed>> $incoming
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_images( array $existing, array $incoming ) {
		$seen = array();
		foreach ( $existing as $image ) {
			$seen[ $image['url'] ] = true;
		}

		foreach ( $incoming as $image ) {
			if ( isset( $seen[ $image['url'] ] ) ) {
				continue;
			}
			$seen[ $image['url'] ] = true;
			if ( ! empty( $existing ) ) {
				$image['is_main'] = false;
			}
			$existing[] = $image;
		}

		return $existing;
	}
}
