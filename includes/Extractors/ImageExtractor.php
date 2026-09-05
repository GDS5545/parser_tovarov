<?php
/**
 * Finds product images: <img src>, common lazy-load attributes
 * (data-src, data-lazy-src), and the first entry of a srcset (spec §13).
 * Background-image and JS-object images are left to the AiExtractor
 * fallback (Stage 12) — parsing arbitrary inline <script> state for image
 * URLs reliably needs the same JSON-blob heuristics as EmbeddedJsonExtractor,
 * which is a separate, not-yet-built extractor.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Support\HtmlDocument;
use Uws\Support\UrlResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageExtractor implements ProductExtractorInterface {

	const LAZY_ATTRIBUTES = array( 'data-src', 'data-lazy-src', 'data-original' );

	/** Filenames matching these are almost always UI chrome, not product photos. */
	const IGNORE_PATTERNS = array( 'icon', 'logo', 'sprite', 'placeholder', 'spinner', 'loading' );

	public function get_name() {
		return 'image';
	}

	public function get_priority() {
		return 50;
	}

	public function supports( array $page ) {
		return ! empty( $page['html'] );
	}

	public function extract( array $page ) {
		$data  = new ProductData();
		$xpath = HtmlDocument::xpath( (string) $page['html'] );
		$base  = ! empty( $page['final_url'] ) ? $page['final_url'] : '';

		$seen = array();

		foreach ( $xpath->query( '//img' ) as $img ) {
			/** @var \DOMElement $img */
			$candidates = array( $img->getAttribute( 'src' ) );
			foreach ( self::LAZY_ATTRIBUTES as $attribute ) {
				$candidates[] = $img->getAttribute( $attribute );
			}
			$srcset = $img->getAttribute( 'srcset' );
			if ( $srcset ) {
				$first = trim( explode( ',', $srcset )[0] );
				$candidates[] = trim( explode( ' ', $first )[0] );
			}

			foreach ( array_filter( $candidates ) as $candidate ) {
				if ( $this->should_ignore( $candidate ) ) {
					continue;
				}
				$absolute = $base ? UrlResolver::resolve( $base, $candidate ) : $candidate;
				if ( isset( $seen[ $absolute ] ) ) {
					continue;
				}
				$seen[ $absolute ] = true;
				$data->images[]    = array(
					'url'           => $absolute,
					'is_main'       => empty( $data->images ),
					'variation_key' => null,
				);
			}
		}

		return $data;
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	private function should_ignore( $url ) {
		if ( 0 === strpos( $url, 'data:' ) ) {
			return true;
		}
		$lower = strtolower( $url );
		foreach ( self::IGNORE_PATTERNS as $pattern ) {
			if ( false !== strpos( $lower, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
