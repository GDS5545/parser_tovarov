<?php
/**
 * Reads OpenGraph / product / twitter <meta> tags (spec §21 step 4).
 * Runs after JSON-LD, so it only fills fields JSON-LD left empty, and
 * always populates $data->seo regardless (used for SEO import, spec §15).
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MetaExtractor implements ProductExtractorInterface {

	public function get_name() {
		return 'meta';
	}

	public function get_priority() {
		return 70;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$data = new ProductData();
		$meta = $this->read_meta_tags( (string) $page['html'] );

		$seo = array();
		if ( ! empty( $meta['og:title'] ) ) {
			$seo['og_title'] = $meta['og:title'];
			$data->set_field( 'name', $meta['og:title'], 0.6 );
		}
		if ( ! empty( $meta['og:description'] ) ) {
			$seo['og_description'] = $meta['og:description'];
			$data->set_field( 'short_description', $meta['og:description'], 0.5 );
		}
		if ( ! empty( $meta['og:image'] ) ) {
			$seo['og_image']  = $meta['og:image'];
			$data->images[]   = array( 'url' => $meta['og:image'], 'is_main' => true, 'variation_key' => null );
		}
		if ( ! empty( $meta['description'] ) ) {
			$seo['meta_description'] = $meta['description'];
		}
		if ( ! empty( $meta['product:price:amount'] ) ) {
			$data->set_field( 'regular_price', $meta['product:price:amount'], 0.7 );
		}
		if ( ! empty( $meta['product:price:currency'] ) ) {
			$data->set_field( 'currency', strtoupper( $meta['product:price:currency'] ), 0.7 );
		}
		if ( ! empty( $meta['product:brand'] ) ) {
			$data->set_field( 'brand', $meta['product:brand'], 0.6 );
		}
		if ( ! empty( $meta['og:title'] ) ) {
			$seo['meta_title'] = $meta['og:title'];
		}

		$data->seo = $seo;

		return $data;
	}

	/**
	 * @param string $html
	 * @return array<string,string>
	 */
	private function read_meta_tags( $html ) {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$tags = array();

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		foreach ( $dom->getElementsByTagName( 'meta' ) as $meta_node ) {
			/** @var \DOMElement $meta_node */
			$key = $meta_node->getAttribute( 'property' ) ?: $meta_node->getAttribute( 'name' );
			if ( ! $key ) {
				continue;
			}
			$content = $meta_node->getAttribute( 'content' );
			if ( '' === $content ) {
				continue;
			}
			$tags[ strtolower( $key ) ] = $content;
		}

		return $tags;
	}
}
