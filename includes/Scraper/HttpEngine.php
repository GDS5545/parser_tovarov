<?php
/**
 * Browser-free fallback engine: fetches a page with `wp_remote_get()`
 * instead of a real browser. No separate service to deploy — this is
 * what the plugin uses automatically when no worker URL is configured
 * under Browser Settings.
 *
 * Trade-off, stated plainly rather than glossed over: this engine cannot
 * execute JavaScript. It works well for classic server-rendered pages
 * (PHP/CMS-generated HTML that already contains the product data, JSON-LD
 * included, before any script runs — which is most stores, including
 * ones built on 1C-Bitrix, OpenCart, classic WooCommerce themes, etc.).
 * It will NOT see content a React/Vue/Next/Nuxt frontend injects only
 * after the browser runs its JS, cannot click a "Load more" button or
 * dismiss a cookie popup, and cannot take a debug screenshot. For sites
 * like that, deploy the Playwright worker (`worker/`) and set its URL —
 * ScraperEngineInterface is exactly what makes that swap a one-line
 * config change rather than a code change (spec §62).
 *
 * @package Uws\Scraper
 */

namespace Uws\Scraper;

use Uws\Security\UrlValidator;
use Uws\Support\HtmlDocument;
use Uws\Support\UrlResolver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HttpEngine implements ScraperEngineInterface {

	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 UniversalWooScraper/0.1 (+no-JS fallback fetch)';

	/** Path/query keywords that are almost never a product detail page — mirrors worker/src/pageFetcher.js's isLikelyProductLink(). */
	const EXCLUDED_PATH_KEYWORDS = array( '/cart', '/checkout', '/login', '/account', '/wishlist', '/compare', '/search', '/contact', '/about', '/blog', '/wp-content', '/wp-admin' );

	public function fetch_page( $url, array $options = array() ) {
		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'user-agent'  => self::USER_AGENT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'uws_http_fetch_failed', $response->get_error_message() );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$html   = wp_remote_retrieve_body( $response );

		if ( $status >= 400 ) {
			return new WP_Error(
				'uws_http_status',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The page returned HTTP %d. If this site actively blocks non-browser requests (some do), the Playwright worker may still succeed where this fallback cannot.', 'universal-woo-scraper' ),
					$status
				)
			);
		}

		return array(
			'html'              => $html,
			'final_url'         => $url, // wp_remote_get() follows redirects internally but doesn't expose the final URL.
			'status_code'       => $status,
			'json_ld_blocks'    => $this->extract_json_ld( $html ),
			'console_errors'    => array(),
			'network_errors'    => array(),
			'screenshot_base64' => null,
		);
	}

	public function discover_product_urls( $url, array $options = array() ) {
		$valid = UrlValidator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$strategy = isset( $options['pagination_strategy'] ) ? $options['pagination_strategy'] : 'none';
		if ( in_array( $strategy, array( 'load_more', 'infinite_scroll' ), true ) ) {
			return new WP_Error(
				'uws_js_pagination_unsupported',
				__( 'This listing loads more products via a JavaScript button/infinite scroll, which the no-browser fallback cannot operate. Deploy the Playwright worker and set its URL under Browser Settings to import this category.', 'universal-woo-scraper' )
			);
		}

		$max_pages = max( 1, min( 50, (int) ( $options['max_pages'] ?? 1 ) ) );
		$found     = array();

		for ( $page_number = 1; $page_number <= $max_pages; $page_number++ ) {
			$page_url = 1 === $page_number ? $url : $this->paginate_url( $url, $strategy, $page_number );

			$page = $this->fetch_page( $page_url );
			if ( is_wp_error( $page ) ) {
				break; // Later pages may simply not exist (404) — keep whatever was already found.
			}

			foreach ( $this->extract_links( $page['html'], $page_url ) as $link ) {
				$found[ $link ] = true;
			}
		}

		return array_keys( $found );
	}

	public function health_check() {
		return true; // No external service — this engine only depends on WordPress's own HTTP API.
	}

	/**
	 * @param string $html
	 * @return string[] Raw textContent of every JSON-LD <script> block, matching
	 *                   the shape PlaywrightHttpEngine's worker returns.
	 */
	private function extract_json_ld( $html ) {
		$xpath  = HtmlDocument::xpath( $html );
		$blocks = array();

		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$blocks[] = $node->textContent;
		}

		return $blocks;
	}

	/**
	 * @param string $html
	 * @param string $base_url
	 * @return string[] Same-domain links that look like product detail pages.
	 */
	private function extract_links( $html, $base_url ) {
		$xpath = HtmlDocument::xpath( $html );
		$base  = wp_parse_url( $base_url );
		$links = array();

		foreach ( $xpath->query( '//a[@href]' ) as $node ) {
			/** @var \DOMElement $node */
			$href = UrlResolver::resolve( $base_url, $node->getAttribute( 'href' ) );
			if ( $this->is_likely_product_link( $href, $base ) ) {
				$links[] = $href;
			}
		}

		return array_values( array_unique( $links ) );
	}

	/**
	 * @param string $link
	 * @param array<string,mixed> $base wp_parse_url() of the listing page.
	 * @return bool
	 */
	private function is_likely_product_link( $link, array $base ) {
		$parts = wp_parse_url( $link );
		if ( empty( $parts['host'] ) || empty( $base['host'] ) || $parts['host'] !== $base['host'] ) {
			return false;
		}

		$path = strtolower( $parts['path'] ?? '' );
		foreach ( self::EXCLUDED_PATH_KEYWORDS as $keyword ) {
			if ( false !== strpos( $path, $keyword ) ) {
				return false;
			}
		}

		return '' !== trim( $path, '/' ) && ( $parts['path'] ?? '' ) !== ( $base['path'] ?? '' );
	}

	/**
	 * @param string $url
	 * @param string $strategy 'query' | 'path'.
	 * @param int    $page_number
	 * @return string
	 */
	private function paginate_url( $url, $strategy, $page_number ) {
		$parts = wp_parse_url( $url );

		if ( 'path' === $strategy ) {
			$path = rtrim( $parts['path'] ?? '', '/' ) . '/page/' . $page_number . '/';
			return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $path;
		}

		// Default: 'query'.
		$separator = isset( $parts['query'] ) && '' !== $parts['query'] ? '&' : '?';
		return $url . $separator . 'page=' . $page_number;
	}
}
