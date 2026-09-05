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
 * third-party domain, analytics tracking pixels (Mail.ru/Yandex counters
 * rendered as a 1x1 <img>), and — the hardest case — a same-domain,
 * normally-sized photo gallery elsewhere on the page (a homepage-style
 * "our work"/certificates carousel) that no filename or size heuristic
 * distinguishes from a real product photo. This extractor therefore:
 *  - first looks for a container whose class/id matches common
 *    product-gallery conventions across several CMS/theme families
 *    (WooCommerce, 1C-Bitrix, generic "product-image"/"detail_picture"
 *    naming) and, if one exists and contains at least one image that
 *    survives the filters below, uses ONLY images inside it — this is
 *    what actually fixes "picks up everything around the product but
 *    not the product" on sites with unrelated same-domain image blocks
 *    elsewhere on the page;
 *  - otherwise falls back to scanning the whole page, so a site without
 *    a recognizable gallery container still gets whatever the filters
 *    below consider plausible, rather than nothing;
 *  - only keeps images on the same registrable domain as the page itself
 *    (a CDN subdomain is fine; a different site like web.telegram.org or
 *    mail.ru is not);
 *  - drops filenames matching a list of common site-chrome keywords;
 *  - drops images whose declared width/height attributes mark them as
 *    icon-sized (<=32px).
 *
 * None of this is a substitute for actually seeing the page: if a site's
 * real product photo still doesn't come through, the fix is to look at
 * that page's markup (or add a manual image URL in the Preview screen)
 * rather than keep stacking heuristics blind.
 *
 * @package Uws\Extractors
 */

namespace Uws\Extractors;

use Uws\Dto\ProductData;
use Uws\Support\ContainerFinder;
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

	/**
	 * class/id substrings (case-insensitive) that identify a product image
	 * gallery/container across common platforms — WooCommerce's own
	 * markup, 1C-Bitrix catalog templates ("detail_picture", "sku_props"),
	 * and generic "product-image"/"product-gallery" theme conventions.
	 * Checked in order; the first one present on the page wins.
	 */
	const GALLERY_CONTAINER_HINTS = array(
		'woocommerce-product-gallery', 'product-gallery', 'product-images', 'product-image',
		'product-photo', 'product-photos', 'detail_picture', 'element-image', 'item-photo',
		'item-image', 'gallery-product', 'fotorama',
	);

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
		$xpath     = HtmlDocument::xpath( (string) $page['html'] );
		$base      = ! empty( $page['final_url'] ) ? $page['final_url'] : '';
		$page_host = $base ? (string) ( wp_parse_url( $base, PHP_URL_HOST ) ?: '' ) : '';

		$container = ContainerFinder::find( $xpath, self::GALLERY_CONTAINER_HINTS );
		if ( $container ) {
			$scoped = $this->collect_images( $xpath->query( './/img', $container ), $base, $page_host );
			if ( ! empty( $scoped->images ) ) {
				return $scoped;
			}
		}

		return $this->collect_images( $xpath->query( '//img' ), $base, $page_host );
	}

	/**
	 * @param \DOMNodeList $img_nodes
	 * @param string       $base
	 * @param string       $page_host
	 * @return ProductData
	 */
	private function collect_images( \DOMNodeList $img_nodes, $base, $page_host ) {
		$data = new ProductData();
		$seen = array();

		foreach ( $img_nodes as $img ) {
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
