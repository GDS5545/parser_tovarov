<?php
/**
 * Finds product images: <img src>, common lazy-load attributes
 * (data-src, data-lazy-src), and the first entry of a srcset (spec §13).
 * Background-image and JS-object images are left to the AiExtractor
 * fallback (Stage 12) — parsing arbitrary inline <script> state for image
 * URLs reliably needs the same JSON-blob heuristics as EmbeddedJsonExtractor,
 * which is a separate, not-yet-built extractor.
 *
 * Real e-commerce pages are full of <img> tags that are not the product:
 * header/footer chrome (WhatsApp/Telegram click-to-chat badges, language
 * flags, partner/payment logos), social widget icons pulled from a
 * third-party domain, and analytics tracking pixels (Mail.ru/Yandex
 * counters rendered as a 1x1 <img>). Filtering those out matters — without
 * it, "Import" would pull two dozen unrelated images into the product's
 * gallery and could even end up choosing one of them as the *main* image
 * (whichever happens to appear first in the raw HTML, which is often a
 * header badge above the actual product photo). This extractor therefore:
 *  - only keeps images on the same registrable domain as the page itself
 *    (a CDN subdomain is fine; a different site like web.telegram.org or
 *    mail.ru is not — that alone removes tracking pixels and social badges
 *    embedded from a different domain);
 *  - drops filenames matching a list of common site-chrome keywords
 *    (whatsapp, telegram, partner, payment logos, etc.);
 *  - drops images whose declared width/height attributes mark them as
 *    icon-sized (<=32px) even when same-domain and not keyword-matched.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Support\DomainMatcher;
use Uws\Support\HtmlDocument;
use Uws\Support\UrlResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageExtractor implements ProductExtractorInterface {

	const LAZY_ATTRIBUTES = array( 'data-src', 'data-lazy-src', 'data-original' );

	/** Filenames matching these are almost always site chrome, not product photos. */
	const IGNORE_PATTERNS = array(
		'icon', 'logo', 'sprite', 'placeholder', 'spinner', 'loading',
		'whatsapp', 'telegram', 'viber', 'instagram', 'facebook', 'vkontakte', 'odnoklassniki',
		'youtube', 'partner', 'sponsor', 'payment', 'visa', 'mastercard', 'sber', '/flag',
	);

	/**
	 * Exact (not substring) basenames-without-extension that are almost
	 * always a language-switcher flag icon — matched exactly, not as a
	 * substring, because codes this short ("en", "ru", "gb") would
	 * otherwise false-positive on ordinary filenames ("green.jpg",
	 * "screen.png").
	 */
	const IGNORE_EXACT_BASENAMES = array( 'rus', 'gb', 'en', 'ru', 'eng', 'kz', 'ua', 'by', 'de', 'fr', 'es', 'it', 'cn', 'tr', 'pl' );

	/** An <img> with both dimensions at or below this (in its own width/height attributes) is icon-sized, not a product photo. */
	const ICON_MAX_DIMENSION = 32;

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
		$page_host = $base ? (string) ( wp_parse_url( $base, PHP_URL_HOST ) ?: '' ) : '';

		$seen = array();

		foreach ( $xpath->query( '//img' ) as $img ) {
			/** @var \DOMElement $img */
			if ( $this->is_icon_sized( $img ) ) {
				continue;
			}

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

				if ( $page_host && ! $this->is_same_site( $absolute, $page_host ) ) {
					continue;
				}
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
	 * @param \DOMElement $img
	 * @return bool
	 */
	private function is_icon_sized( \DOMElement $img ) {
		$width  = $img->getAttribute( 'width' );
		$height = $img->getAttribute( 'height' );

		if ( '' === $width || '' === $height || ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return false; // No reliable size info — don't guess.
		}

		return (float) $width <= self::ICON_MAX_DIMENSION && (float) $height <= self::ICON_MAX_DIMENSION;
	}

	/**
	 * @param string $absolute_url
	 * @param string $page_host
	 * @return bool
	 */
	private function is_same_site( $absolute_url, $page_host ) {
		$image_host = wp_parse_url( $absolute_url, PHP_URL_HOST );
		if ( ! $image_host ) {
			return true; // Relative/unparseable — UrlResolver should have made it absolute already, but don't discard on doubt.
		}
		return DomainMatcher::same_site( $image_host, $page_host );
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

		$path     = (string) wp_parse_url( $lower, PHP_URL_PATH );
		$basename = pathinfo( $path, PATHINFO_FILENAME );
		if ( in_array( $basename, self::IGNORE_EXACT_BASENAMES, true ) ) {
			return true;
		}

		return false;
	}
}
